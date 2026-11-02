#!/usr/bin/env python3
"""Selected-revision, existing-target deployment performer. No automatic retries."""
import json
import hashlib
import importlib.util
import tempfile
import os
import re
import shlex
import subprocess
import sys
import time
from pathlib import Path
from urllib.parse import urlsplit

HERE = Path(__file__).resolve().parent
SLUG = 'products-manager'


class Refused(RuntimeError):
    pass


def command(argv, *, data=None, cwd=None, timeout=180):
    result = subprocess.run(argv, input=data, cwd=cwd, text=True, capture_output=True, timeout=timeout)
    if result.returncode:
        detail = ''
        try:
            diagnostic = json.loads(result.stderr)
            if isinstance(diagnostic, dict) and isinstance(diagnostic.get('error'), str):
                detail = '; ' + diagnostic['error'][:1000]
        except (ValueError, TypeError):
            pass
        raise Refused(Path(argv[0]).name + ' returned ' + str(result.returncode) + detail + '; diagnostics-sha256=' + hashlib.sha256(result.stderr.encode()).hexdigest())
    return result.stdout.strip()


def valid_sha(value):
    if not re.fullmatch(r'[a-f0-9]{40}', value or ''):
        raise Refused('An exact lowercase forty-hex SHA/tree is required')
    return value


def request(environment, env):
    if environment not in ('production', 'staging'):
        raise Refused('Unknown deployment environment')
    event, selected_ref = env.get('GITHUB_EVENT_NAME'), env.get('GITHUB_REF', '')
    if environment == 'production':
        if event != 'workflow_dispatch' or selected_ref != 'refs/heads/main':
            raise Refused('Production requires an explicit main dispatch')
        if not env.get('PM_EXPECTED_CURRENT_VERSION'):
            raise Refused('Production requires its reviewed existing-version baseline')
    elif event not in ('push', 'workflow_dispatch') or (event == 'push' and selected_ref != 'refs/heads/dev'):
        raise Refused('Only dev pushes or explicit branch dispatches deploy staging')
    if not selected_ref.startswith('refs/heads/'):
        raise Refused('An exact selected branch ref is required')
    expected_sha = valid_sha(env.get('PM_EXPECTED_SHA'))
    if expected_sha != env.get('GITHUB_SHA'):
        raise Refused('Selected SHA differs from the dispatched/pushed revision')
    expected_tree = valid_sha(env.get('PM_EXPECTED_TREE'))
    parsed = urlsplit(env.get('PM_EXPECTED_SITE', ''))
    if parsed.scheme != 'https' or not parsed.hostname or parsed.username or parsed.password or parsed.query or parsed.fragment or parsed.path not in ('', '/'):
        raise Refused('Exact HTTPS target site required')
    host, user = env.get('PM_HOST', ''), env.get('PM_USER', '')
    if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9.:-]*', host) or not re.fullmatch(r'[A-Za-z0-9_][A-Za-z0-9_.-]*', user):
        raise Refused('Invalid configured SSH target identity')
    port = int(env.get('PM_PORT') or '22')
    if not 1 <= port <= 65535:
        raise Refused('Invalid configured SSH port')
    base = env.get('PM_PLUGINS_BASE', '').rstrip('/')
    if not re.fullmatch(r'/(?:[A-Za-z0-9_-]+/)*public/wp-content/plugins', base):
        raise Refused('Unsupported configured plugin base')
    return {'environment': environment, 'sha': expected_sha, 'tree': expected_tree, 'ref': selected_ref, 'site': env['PM_EXPECTED_SITE'].rstrip('/'), 'priorVersion': env.get('PM_EXPECTED_CURRENT_VERSION', ''), 'host': host, 'user': user, 'port': port, 'base': base}


def verify_checkout(spec, repo):
    if command(['git', 'rev-parse', 'HEAD'], cwd=repo) != spec['sha'] or command(['git', 'rev-parse', 'HEAD^{tree}'], cwd=repo) != spec['tree']:
        raise Refused('Pinned checkout SHA/full tree mismatch')
    command(['git', 'check-ref-format', spec['ref']], cwd=repo)


def entry_version(payload):
    text = (payload / 'products-manager.php').read_text()
    header = re.findall(r'^\s*\*\s*Version:\s*(\d+\.\d+\.\d+)\s*$', text, re.M)
    constant = re.findall(r"const VERSION\s*=\s*'(\d+\.\d+\.\d+)'", text)
    if len(header) != 1 or constant != header:
        raise Refused('Plugin header/constant identity mismatch')
    return header[0]


def materialize_payload(repo, sha, work):
    spec = importlib.util.spec_from_file_location('pm_selected_packaging', HERE / 'package-equivalence.py')
    producer = importlib.util.module_from_spec(spec); spec.loader.exec_module(producer)
    proof = producer.package(repo, sha, work)
    return work / 'payload', proof


def fresh_selected_head(spec, repo):
    # This is deliberately the last external check before rsync, after queue/target waits.
    lines = command(['git', 'ls-remote', '--exit-code', 'origin', spec['ref']], cwd=repo).splitlines()
    if len(lines) != 1 or lines[0].split() != [spec['sha'], spec['ref']]:
        raise Refused('Selected branch advanced while waiting; no copy authorized')


def fresh_staging_lease(spec):
    if spec['environment'] != 'staging':
        raise Refused('Production must not consume staging leases')
    target = spec['user'] + '@' + spec['host']
    result = json.loads(command(['ssh', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'ConnectTimeout=20', '-p', str(spec['port']), target, 'python3 - --plugin ' + SLUG], data=(HERE / 'staging-lease-check.py').read_text(), timeout=120))
    if not isinstance(result, dict) or result.get('plugin') != SLUG or result.get('free') is not True or result.get('blocking') != []:
        raise Refused('Fresh exact-plugin staging lease admission refused')
    return result


def remote(spec, mode, version):
    target = spec['user'] + '@' + spec['host']
    arguments = ['python3', '-', mode, spec['base'], spec['site'], spec['environment'], version]
    script = (HERE / 'remote-guard.py').read_text()
    stdout = command(['ssh', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'ConnectTimeout=20', '-p', str(spec['port']), target, ' '.join(shlex.quote(x) for x in arguments)], data=script, timeout=300)
    result = json.loads(stdout)
    if not isinstance(result, dict) or any(result.get(key) is not True for key in ('alreadyActive', 'rootMatched', 'pluginPathMatched', 'siteMatched', 'environmentMatched')):
        raise Refused('Remote existing-target admission/readback is incomplete')
    if version and result.get('version') != version:
        raise Refused('Remote version receipt differs from expected baseline/candidate')
    if mode == 'effects' and (result.get('version') != version or result.get('effects') != ['already-active-activation', 'active-readback', 'object-cache-flush', 'kinsta-page-cache-purge', 'version-readback']):
        raise Refused('Mandatory effect/version receipt is incomplete')
    return result


def write_receipt(path, receipt):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(receipt, indent=2, sort_keys=True) + '\n')


def perform(environment, env, repo, journal):
    if journal == repo or repo in journal.parents:
        raise Refused('Deployment receipt must remain outside the source checkout/payload')
    receipt = {'schemaVersion': 'hp_pm_deploy_control_v1', 'environment': environment, 'phase': 'not_started', 'operatorRequestedTargetMutation': False, 'automaticRetry': False, 'events': []}
    write_receipt(journal, receipt)
    mutation_possible = False
    try:
        spec = request(environment, env)
        verify_checkout(spec, repo)
        with tempfile.TemporaryDirectory(prefix='pm-selected-deployment-') as directory:
            payload, proof = materialize_payload(repo, spec['sha'], Path(directory) / 'selected')
            version = entry_version(payload)
            receipt.update(selectedSHA=spec['sha'], selectedFullTree=spec['tree'], selectedRef=spec['ref'], selectedVersion=version, expectedSite=spec['site'], packaging={'sha': proof['sha'], 'fullTree': proof['fullTree'], 'excludesSHA256': proof['excludesSHA256'], 'deployedFiles': proof['files']})
            if proof['sha'] != spec['sha'] or proof['fullTree'] != spec['tree']:
                raise Refused('Immutable archive identity differs from selected SHA/full tree')
            receipt['preflight'] = remote(spec, 'preflight', spec['priorVersion'])
            if environment == 'staging':
                receipt['freshStagingLease'] = fresh_staging_lease(spec)
            receipt['phase'] = 'copy_intent_unknown_if_interrupted'
            receipt['operatorRequestedTargetMutation'] = 'possible_after_this_intent'
            write_receipt(journal, receipt)
            fresh_selected_head(spec, repo)
            # No target mkdir or other host operation occurs between this fresh head check and copy.
            mutation_possible = True
            transport = 'ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=20 -p ' + str(spec['port'])
            host = '[' + spec['host'] + ']' if ':' in spec['host'] else spec['host']
            destination = spec['user'] + '@' + host + ':' + shlex.quote(spec['base'] + '/' + SLUG + '/')
            # Only the selected immutable, exclusion-filtered Git archive is copied.
            command(['rsync', '-az', '--delete', '-e', transport, '--exclude-from=' + str(payload.parent / 'source/.github/deploy-exclude.txt'), str(payload) + '/', destination], cwd=repo, timeout=600)
            receipt['phase'] = 'copy_returned_required_effects_pending'
            receipt['operatorRequestedTargetMutation'] = True
            write_receipt(journal, receipt)
            receipt['postEffects'] = remote(spec, 'effects', version)
            receipt['phase'] = 'required_effects_verified'
            receipt['outcome'] = 'commands_succeeded_served_and_human_verification_separate'
            write_receipt(journal, receipt)
        return 0
    except Exception as error:
        receipt['phase'] = 'mutation_outcome_unknown' if mutation_possible else 'refused_before_copy'
        receipt['operatorRequestedTargetMutation'] = mutation_possible
        receipt['error'] = str(error)
        receipt['nextAction'] = 'Reconcile actual target and retained attempt; no automatic retry or rollback' if mutation_possible else 'Review corrected inputs/admission; target copy was not dispatched'
        write_receipt(journal, receipt)
        return 2 if mutation_possible else 1


if __name__ == '__main__':
    if len(sys.argv) != 2:
        raise SystemExit('Specify staging or production')
    repo = Path.cwd().resolve()
    journal = Path(os.environ['PM_DEPLOY_RECEIPT']).resolve()
    result = perform(sys.argv[1], os.environ, repo, journal)
    saved = json.loads(journal.read_text())
    print(json.dumps({'phase': saved['phase'], 'receipt': str(journal), 'automaticRetry': False}))
    raise SystemExit(result)

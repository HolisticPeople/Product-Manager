#!/usr/bin/env python3
"""Compare immutable Git archives after applying each tree's actual rsync exclusions."""
import argparse
import hashlib
import json
import subprocess
import tarfile
import tempfile
from pathlib import Path


def run(*args):
    return subprocess.check_output(args, text=True).strip()


def package(repo, revision, work):
    sha = run('git', '-C', str(repo), 'rev-parse', revision + '^{commit}')
    tree = run('git', '-C', str(repo), 'rev-parse', sha + '^{tree}')
    archive, source, payload = work / 'source.tar', work / 'source', work / 'payload'
    source.mkdir(parents=True); payload.mkdir()
    subprocess.run(['git', '-C', str(repo), 'archive', '--format=tar', '--output=' + str(archive), sha], check=True)
    with tarfile.open(archive) as tar:
        for member in tar.getmembers():
            if Path(member.name).is_absolute() or '..' in Path(member.name).parts:
                raise RuntimeError('Unsafe archive path')
        tar.extractall(source, filter='data')
    excludes = source / '.github/deploy-exclude.txt'
    subprocess.run(['rsync', '-a', '--delete', '--exclude-from=' + str(excludes), str(source) + '/', str(payload) + '/'], check=True)
    files = {}
    for path in sorted(payload.rglob('*')):
        if path.is_symlink():
            raise RuntimeError('Symlink payload requires separate ownership review')
        if path.is_file():
            files[path.relative_to(payload).as_posix()] = hashlib.sha256(path.read_bytes()).hexdigest()
    return {'sha': sha, 'fullTree': tree, 'excludesSHA256': hashlib.sha256(excludes.read_bytes()).hexdigest(), 'files': files}


def compare(repo, baseline, candidate):
    with tempfile.TemporaryDirectory(prefix='pm-package-equivalence-') as temporary:
        work = Path(temporary)
        before = package(repo, baseline, work / 'before')
        after = package(repo, candidate, work / 'after')
    changed = [path for path in sorted(set(before['files']) | set(after['files'])) if before['files'].get(path) != after['files'].get(path)]
    return {'schemaVersion': 'hp_pm_packaging_equivalence_v1', 'baseline': before, 'candidate': after, 'deployedPayloadEqual': not changed, 'changedDeployedPaths': changed, 'fullTreeChanged': before['fullTree'] != after['fullTree'], 'method': 'immutable Git archives + actual rsync exclusion file; tracked/deployed bytes only'}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(); parser.add_argument('--baseline', required=True); parser.add_argument('--candidate', required=True); parser.add_argument('--output', required=True); parser.add_argument('--require-equal', action='store_true'); args = parser.parse_args()
    receipt = compare(Path.cwd(), args.baseline, args.candidate)
    Path(args.output).write_text(json.dumps(receipt, indent=2, sort_keys=True) + '\n')
    if args.require_equal and not receipt['deployedPayloadEqual']:
        raise SystemExit('Deployed payload differs from selected baseline')
    print(json.dumps({key: receipt[key] for key in ('deployedPayloadEqual', 'changedDeployedPaths', 'fullTreeChanged')}))

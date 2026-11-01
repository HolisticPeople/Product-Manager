#!/usr/bin/env python3
"""Offline command/FS tests for the actual performer; no target host calls."""
import importlib.util
import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

HERE = Path(__file__).resolve().parent
REPO = HERE.parent.parent


def module(name, file):
    spec = importlib.util.spec_from_file_location(name, HERE / file)
    value = importlib.util.module_from_spec(spec); spec.loader.exec_module(value); return value


deploy = module('pm_deploy', 'deploy.py')
remote = module('pm_remote_guard', 'remote-guard.py')
packaging = module('pm_packaging', 'package-equivalence.py')
SHA, TREE = 'a' * 40, 'b' * 40
FLAGS = {'rootMatched': True, 'pluginPathMatched': True, 'siteMatched': True, 'environmentMatched': True, 'alreadyActive': True, 'version': '2.7.2'}
EFFECTS = ['already-active-activation', 'active-readback', 'object-cache-flush', 'kinsta-page-cache-purge', 'version-readback']


class PerformerTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='pm-control-test-'); self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name) / 'repo'; self.repo.mkdir()
        (self.repo / 'products-manager.php').write_text("<?php\n/**\n * Version: 2.7.2\n */\nclass Test { const VERSION = '2.7.2'; }\n")
        self.frozen_entry = (self.repo / 'products-manager.php').read_bytes()
        self.journal = Path(self.temp.name) / 'receipt.json'
        self.env = {'GITHUB_EVENT_NAME': 'workflow_dispatch', 'GITHUB_REF': 'refs/heads/main', 'GITHUB_SHA': SHA, 'PM_EXPECTED_SHA': SHA, 'PM_EXPECTED_TREE': TREE, 'PM_EXPECTED_SITE': 'https://production.example/', 'PM_EXPECTED_CURRENT_VERSION': '2.7.1', 'PM_HOST': 'example.invalid', 'PM_USER': 'existing-user', 'PM_PORT': '22000', 'PM_PLUGINS_BASE': '/qualified/site/public/wp-content/plugins'}
        self.calls = []; self.fault = None

    def command(self, argv, **kwargs):
        self.calls.append(argv)
        if argv[:3] == ['git', 'rev-parse', 'HEAD']:
            return 'c' * 40 if self.fault == 'checkout_sha' else SHA
        if argv[:3] == ['git', 'rev-parse', 'HEAD^{tree}']:
            return 'c' * 40 if self.fault == 'checkout_tree' else TREE
        if argv[:2] == ['git', 'check-ref-format']:
            return ''
        if argv[:2] == ['git', 'ls-remote']:
            return ('c' * 40 if self.fault == 'stale_head' else SHA) + '\t' + self.env['GITHUB_REF']
        if argv[0] == 'ssh':
            if '--plugin products-manager' in argv[-1]:
                if self.fault == 'lease_during_preflight': raise deploy.Refused('Fresh staging lease appeared during preflight')
                return json.dumps({'plugin': 'products-manager', 'free': True, 'blocking': []})
            mode = 'effects' if ' effects ' in argv[-1] else 'preflight'
            if self.fault == mode:
                raise deploy.Refused('Required remote ' + mode + ' failed')
            value = dict(FLAGS)
            if mode == 'preflight': value['version'] = self.env.get('PM_EXPECTED_CURRENT_VERSION') or '2.7.2'
            if mode == 'effects':
                value['effects'] = [] if self.fault == 'missing_effect' else EFFECTS
            return json.dumps(value)
        if argv[0] == 'rsync':
            if self.fault == 'copy':
                raise deploy.Refused('rsync returned 23')
            return ''
        raise AssertionError(argv)

    def payload(self, repo, sha, work):
        path = work / 'payload'; path.mkdir(parents=True); (path / 'products-manager.php').write_bytes(self.frozen_entry)
        return path, {'sha': SHA, 'fullTree': TREE, 'excludesSHA256': 'f' * 64, 'files': {'products-manager.php': 'e' * 64}}

    def run_performer(self, environment='production'):
        with patch.object(deploy, 'command', side_effect=self.command), patch.object(deploy, 'materialize_payload', side_effect=self.payload):
            code = deploy.perform(environment, self.env, self.repo, self.journal)
        return code, json.loads(self.journal.read_text())

    def test_exact_copy_and_effect_order(self):
        code, receipt = self.run_performer()
        self.assertEqual(code, 0); self.assertEqual(receipt['phase'], 'required_effects_verified')
        names = [call[0] + ('-fresh' if call[:2] == ['git', 'ls-remote'] else '') for call in self.calls]
        self.assertEqual(names[-3:], ['git-fresh', 'rsync', 'ssh'])
        self.assertEqual(sum(call[0] == 'rsync' for call in self.calls), 1)
        self.assertFalse(any('mkdir' in ' '.join(call) for call in self.calls))
        copied = next(call for call in self.calls if call[0] == 'rsync')
        self.assertTrue(any(arg.startswith('--exclude-from=') and '/source/.github/deploy-exclude.txt' in arg for arg in copied))
        self.assertNotEqual(copied[-2].rstrip('/'), str(self.repo))
        self.assertTrue(copied[-2].endswith('/payload/'))
        self.assertFalse(any('--plugin products-manager' in call[-1] for call in self.calls))

    def test_fresh_staging_lease_after_preflight_before_head_and_copy(self):
        self.env.update(GITHUB_REF='refs/heads/dev', PM_EXPECTED_CURRENT_VERSION='')
        code, receipt = self.run_performer('staging')
        self.assertEqual(code, 0); self.assertTrue(receipt['freshStagingLease']['free'])
        names = [('lease' if '--plugin products-manager' in call[-1] else call[0]) for call in self.calls]
        self.assertEqual(names[-5:], ['ssh', 'lease', 'git', 'rsync', 'ssh'])
        self.calls = []; self.fault = 'lease_during_preflight'
        code, receipt = self.run_performer('staging')
        self.assertEqual(code, 1); self.assertEqual(receipt['phase'], 'refused_before_copy')
        self.assertEqual(sum(call[0] == 'ssh' for call in self.calls), 2)
        self.assertFalse(any(call[0] == 'rsync' or call[:2] == ['git', 'ls-remote'] for call in self.calls))

    def test_unsupported_base_namespace_refuses_before_ssh(self):
        for value in ('/qualified/site with spaces/public/wp-content/plugins', '/qualified/site;id/public/wp-content/plugins', '/qualified/../public/wp-content/plugins', '/qualified/\u00e9/public/wp-content/plugins'):
            with self.subTest(value=value):
                self.calls = []; self.env['PM_PLUGINS_BASE'] = value
                code, receipt = self.run_performer()
                self.assertEqual(code, 1); self.assertEqual(receipt['phase'], 'refused_before_copy')
                self.assertFalse(any(call[0] in ('ssh', 'rsync') for call in self.calls))

    def test_bad_input_and_checkout_refuse_without_ssh(self):
        for change in ('bad_sha', 'bad_tree', 'wrong_dispatch', 'missing_prior', 'checkout_sha', 'checkout_tree'):
            with self.subTest(change=change):
                self.calls = []; self.fault = change
                original = dict(self.env)
                if change == 'bad_sha': self.env['PM_EXPECTED_SHA'] = 'not-a-sha'
                if change == 'bad_tree': self.env['PM_EXPECTED_TREE'] = 'PR75'
                if change == 'wrong_dispatch': self.env['GITHUB_REF'] = 'refs/heads/dev'
                if change == 'missing_prior': self.env['PM_EXPECTED_CURRENT_VERSION'] = ''
                code, receipt = self.run_performer(); self.env = original
                self.assertEqual(code, 1); self.assertEqual(receipt['phase'], 'refused_before_copy')
                self.assertFalse(any(call[0] in ('ssh', 'rsync') for call in self.calls))

    def test_stale_head_refuses_after_read_guards_before_copy(self):
        self.fault = 'stale_head'; code, receipt = self.run_performer()
        self.assertEqual(code, 1); self.assertEqual(receipt['phase'], 'refused_before_copy')
        self.assertTrue(any(call[0] == 'ssh' for call in self.calls))
        self.assertFalse(any(call[0] == 'rsync' for call in self.calls))

    def test_preflight_refusal_has_no_copy(self):
        self.fault = 'preflight'; code, receipt = self.run_performer()
        self.assertEqual(code, 1); self.assertFalse(any(call[0] == 'rsync' for call in self.calls))

    def test_every_copy_or_post_effect_failure_is_unknown_no_retry(self):
        for fault in ('copy', 'effects', 'missing_effect'):
            with self.subTest(fault=fault):
                self.calls = []; self.fault = fault; code, receipt = self.run_performer()
                self.assertEqual(code, 2); self.assertEqual(receipt['phase'], 'mutation_outcome_unknown')
                self.assertEqual(sum(call[0] == 'rsync' for call in self.calls), 1)
                self.assertFalse(receipt['automaticRetry'])

    def test_known_remote_error_is_retained_without_environment_dump(self):
        import sys
        code = "import sys,json; print(json.dumps({'error':'Required WP command failed: cache flush; exit=1','mode':'effects'}),file=sys.stderr);sys.exit(1)"
        with self.assertRaises(deploy.Refused) as error:
            deploy.command([sys.executable, '-c', code])
        self.assertIn('cache flush', str(error.exception)); self.assertIn('diagnostics-sha256=', str(error.exception))


class RemoteGuardTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='pm-remote-guard-'); self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name).resolve() / 'site with spaces' / 'public'
        self.base = self.root / 'wp-content/plugins'; self.plugin = self.base / 'products-manager'; self.plugin.mkdir(parents=True)
        for path in (self.root / 'wp-load.php', self.root / 'wp-config.php', self.plugin / 'products-manager.php'): path.write_text('<?php')
        self.identity = {'ABSPATH': str(self.root), 'content': str(self.root / 'wp-content'), 'plugins': str(self.base), 'home': 'https://production.example/', 'environment': 'production'}
        self.calls = []; self.failure = None; self.inactive = False

    def subprocess(self, argv, **kwargs):
        self.calls.append(argv)
        command = argv[5:]
        self.assertIn('--skip-plugins', argv); self.assertIn('--skip-themes', argv)
        if self.failure == command or (self.inactive and command[:2] == ['plugin', 'is-active']):
            return subprocess.CompletedProcess(argv, 1, '', 'deliberate required effect refusal')
        if command and command[0] == 'eval': output = json.dumps(self.identity)
        elif command[:2] == ['plugin', 'get']: output = '2.7.2'
        else: output = ''
        return subprocess.CompletedProcess(argv, 0, output, '')

    def perform(self, mode='effects', version='2.7.2'):
        with patch.object(remote.subprocess, 'run', side_effect=self.subprocess):
            return remote.perform(mode, str(self.base), 'https://production.example/', 'production', version)

    def test_existing_root_identity_active_and_mandatory_effects(self):
        result = self.perform(); self.assertEqual(result['effects'], EFFECTS)
        commands = [call[5:] for call in self.calls]
        self.assertEqual(commands[-5:], [['plugin', 'activate', 'products-manager'], ['plugin', 'is-active', 'products-manager'], ['cache', 'flush'], ['kinsta', 'cache', 'purge', '--all'], ['plugin', 'get', 'products-manager', '--field=version']])
        self.assertTrue(commands.index(['cache', 'flush']) < commands.index(['kinsta', 'cache', 'purge', '--all']))

    def test_missing_root_and_plugin_refuse_before_any_wp_command(self):
        for path in (self.root / 'wp-load.php', self.plugin / 'products-manager.php'):
            content = path.read_text(); path.unlink(); self.calls = []
            with self.assertRaises(RuntimeError): self.perform()
            self.assertEqual(self.calls, []); path.write_text(content)
        with self.assertRaises(RuntimeError): remote.layout(str(self.base / '..' / 'plugins'))

    def test_wrong_site_environment_content_version_and_inactive_refuse_before_activation(self):
        for key, value in (('home', 'https://another.example/'), ('environment', 'staging'), ('content', '/wrong/wp-content')):
            previous = self.identity[key]; self.identity[key] = value; self.calls = []
            with self.assertRaises(RuntimeError): self.perform()
            self.assertFalse(any(call[5:7] == ['plugin', 'activate'] for call in self.calls)); self.identity[key] = previous
        self.inactive = True; self.calls = []
        with self.assertRaises(RuntimeError): self.perform()
        self.assertFalse(any(call[5:7] == ['plugin', 'activate'] for call in self.calls))
        self.inactive = False
        with self.assertRaises(RuntimeError): self.perform(version='2.7.1')

    def test_each_required_effect_failure_stops_and_is_not_swallowed(self):
        for effect in (['plugin', 'activate', 'products-manager'], ['cache', 'flush'], ['kinsta', 'cache', 'purge', '--all']):
            with self.subTest(effect=effect):
                self.failure = effect; self.calls = []
                with self.assertRaises(RuntimeError): self.perform()
                self.assertEqual(sum(call[5:] == effect for call in self.calls), 1)
                if effect != ['kinsta', 'cache', 'purge', '--all']:
                    self.assertFalse(any(call[5:] == ['kinsta', 'cache', 'purge', '--all'] for call in self.calls))


class PackagingTests(unittest.TestCase):

    def test_actual_copy_argv_preserves_excluded_target_and_deletes_unexcluded_stale(self):
        with tempfile.TemporaryDirectory(prefix='pm-local-copy-test-') as directory:
            work = Path(directory).resolve(); repo = work / 'repo'; repo.mkdir()
            def git(*args): return subprocess.check_output(['git', '-C', str(repo), *args], text=True).strip()
            git('init', '-q'); git('config', 'user.email', 'qa@example.invalid'); git('config', 'user.name', 'Local copy test')
            (repo / '.github').mkdir(); (repo / '.github/deploy-exclude.txt').write_bytes((REPO / '.github/deploy-exclude.txt').read_bytes())
            entry = "<?php\n/**\n * Version: 2.7.2\n */\nclass Test { const VERSION = '2.7.2'; }\n"
            (repo / 'products-manager.php').write_text(entry); git('add', '.'); git('commit', '-qm', 'immutable copy fixture')
            sha, tree = git('rev-parse', 'HEAD'), git('rev-parse', 'HEAD^{tree}')
            # Prove the actual performer never transfers working bytes or untracked PHP.
            (repo / 'products-manager.php').write_text(entry + 'DIRTY_WORKING_BYTES')
            (repo / 'untracked-private.php').write_text('private untracked bytes')
            target = work / 'target'; (target / 'vendor').mkdir(parents=True); (target / '.github').mkdir()
            (target / 'vendor/server-sentinel.php').write_text('protected server vendor')
            (target / '.github/server-sentinel').write_text('protected server control')
            (target / 'stale-unexcluded.php').write_text('must be deleted')
            tools = work / 'tools'; tools.mkdir(); marker = work / 'ssh-was-called'
            blocker = tools / 'ssh'; blocker.write_text('#!/bin/sh\necho forbidden > "' + str(marker) + '"\nexit 97\n'); blocker.chmod(0o755)
            env = {'GITHUB_EVENT_NAME': 'workflow_dispatch', 'GITHUB_REF': 'refs/heads/main', 'GITHUB_SHA': sha, 'PM_EXPECTED_SHA': sha, 'PM_EXPECTED_TREE': tree, 'PM_EXPECTED_SITE': 'https://production.example/', 'PM_EXPECTED_CURRENT_VERSION': '2.7.1', 'PM_HOST': 'example.invalid', 'PM_USER': 'existing-user', 'PM_PORT': '22000', 'PM_PLUGINS_BASE': '/qualified/site/public/wp-content/plugins'}
            actual_command = deploy.command; copies = []
            def controlled(argv, **kwargs):
                if argv[:2] == ['git', 'ls-remote']: return sha + '\trefs/heads/main'
                if argv[0] == 'ssh':
                    value = dict(FLAGS); value['version'] = '2.7.2' if ' effects ' in argv[-1] else '2.7.1'
                    if ' effects ' in argv[-1]: value['effects'] = EFFECTS
                    return json.dumps(value)
                if argv[0] == 'rsync':
                    copies.append(argv)
                    # Bind only the destination to a local directory; retain every actual copy flag/source/exclude.
                    local = argv[:-1] + [str(target) + '/']
                    process_env = os.environ.copy(); process_env['PATH'] = str(tools) + os.pathsep + process_env['PATH']
                    result = subprocess.run(local, cwd=kwargs.get('cwd'), env=process_env, text=True, capture_output=True)
                    self.assertEqual(result.returncode, 0, result.stderr); return result.stdout
                return actual_command(argv, **kwargs)
            with patch.object(deploy, 'command', side_effect=controlled):
                result = deploy.perform('production', env, repo, work / 'receipt.json')
            self.assertEqual(result, 0); self.assertEqual(len(copies), 1); self.assertFalse(marker.exists())
            self.assertEqual((target / 'products-manager.php').read_text(), entry)
            self.assertFalse((target / 'untracked-private.php').exists())
            self.assertEqual((target / 'vendor/server-sentinel.php').read_text(), 'protected server vendor')
            self.assertEqual((target / '.github/server-sentinel').read_text(), 'protected server control')
            self.assertFalse((target / 'stale-unexcluded.php').exists())
            immutable_exclude = next(x for x in copies[0] if x.startswith('--exclude-from='))
            self.assertNotEqual(immutable_exclude, '--exclude-from=' + str(repo / '.github/deploy-exclude.txt'))

    def test_actual_git_archives_and_rsync_filters(self):
        with tempfile.TemporaryDirectory(prefix='pm-packaging-test-') as directory:
            repo = Path(directory) / 'repo'; repo.mkdir()
            def git(*args): return subprocess.check_output(['git', '-C', str(repo), *args], text=True).strip()
            git('init', '-q'); git('config', 'user.email', 'qa@example.invalid'); git('config', 'user.name', 'Local packaging test')
            (repo / '.github').mkdir(); (repo / 'tests').mkdir()
            (repo / '.github/deploy-exclude.txt').write_bytes((REPO / '.github/deploy-exclude.txt').read_bytes())
            (repo / 'products-manager.php').write_text('runtime bytes unchanged')
            git('add', '.'); git('commit', '-qm', 'fixture baseline'); before = git('rev-parse', 'HEAD')
            (repo / '.github/control.py').write_text('control only'); (repo / 'tests/control-test.py').write_text('test only')
            git('add', '.'); git('commit', '-qm', 'fixture controls'); after = git('rev-parse', 'HEAD')
            value = packaging.compare(repo, before, after)
            self.assertTrue(value['deployedPayloadEqual']); self.assertTrue(value['fullTreeChanged'])
            # Actual immutable archive construction ignores dirty and untracked deployed-looking files.
            (repo / 'products-manager.php').write_text('dirty working bytes must never deploy')
            (repo / 'untracked-private.php').write_text('must never deploy')
            with tempfile.TemporaryDirectory(prefix='pm-copy-proof-') as copy_dir:
                payload, proof = deploy.materialize_payload(repo, after, Path(copy_dir) / 'selected')
                self.assertEqual((payload / 'products-manager.php').read_text(), 'runtime bytes unchanged')
                self.assertFalse((payload / 'untracked-private.php').exists())
                self.assertEqual(proof['sha'], after)
            (repo / 'products-manager.php').write_text('changed runtime'); git('add', 'products-manager.php'); git('commit', '-qm', 'fixture runtime change')
            value = packaging.compare(repo, before, git('rev-parse', 'HEAD'))
            self.assertFalse(value['deployedPayloadEqual']); self.assertEqual(value['changedDeployedPaths'], ['products-manager.php'])


if __name__ == '__main__':
    unittest.main(verbosity=2)

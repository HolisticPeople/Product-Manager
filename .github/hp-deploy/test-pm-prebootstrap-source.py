"""R2a local filesystem/privacy fault cases; no production or native calls."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

SCRIPT = Path(__file__).with_name('pm-prebootstrap-source.py')
spec = importlib.util.spec_from_file_location('census', SCRIPT)
census = importlib.util.module_from_spec(spec)
spec.loader.exec_module(census)


class SourceCases(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name).resolve()

    def tearDown(self):
        self.temp.cleanup()

    def test_config_secret_is_only_hashed_not_exported(self):
        secret = b"<?php define('DB_PASSWORD','DO-NOT-EXPORT-THIS-SECRET');"
        (self.root / 'wp-config.php').write_bytes(secret)
        with patch.object(census, 'ROOT', self.root), patch.object(census, 'PRIVATE', self.root / 'absent-private'), patch.object(census.shutil, 'which', return_value=None):
            result = census.census()
        encoded = json.dumps(result)
        self.assertNotIn('DO-NOT-EXPORT', encoded)
        self.assertEqual(result['files']['wp-config.php']['sha256'], hashlib.sha256(secret).hexdigest())
        self.assertEqual(result['targetWrites'], 0)
        self.assertEqual(result['nativeInvocations'], 0)
        self.assertFalse(result['configurationExecution'])
        self.assertEqual(result['files']['wp-load.php']['state'], 'HOLD')

    def test_symlink_file_and_parent_alias_refuse(self):
        actual = self.root / 'actual'; actual.mkdir()
        (actual / 'file').write_bytes(b'code')
        alias = self.root / 'alias'; alias.symlink_to(actual, target_is_directory=True)
        direct = self.root / 'linked'; direct.symlink_to(actual / 'file')
        for path in (alias / 'file', direct):
            self.assertEqual(census.file_record(path)['state'], 'HOLD')

    def test_startup_environment_is_fingerprinted_without_value_export(self):
        with patch.object(census, 'ROOT', self.root), patch.object(census, 'PRIVATE', self.root / 'absent-private'), patch.object(census.shutil, 'which', return_value=None), patch.dict(os.environ, {'WP_CLI_PHP_ARGS': 'DO-NOT-EXPORT-ARGS'}):
            result = census.census()
        self.assertNotIn('DO-NOT-EXPORT-ARGS', json.dumps(result))
        self.assertEqual(len(result['startupContext']['environmentSHA256']), 64)

    def test_hard_link_and_oversize_refuse(self):
        one = self.root / 'one'; one.write_bytes(b'code')
        os.link(one, self.root / 'two')
        self.assertEqual(census.file_record(one)['state'], 'HOLD')
        big = self.root / 'big'
        with big.open('wb') as stream:
            stream.truncate(census.MAX_FILE + 1)
        self.assertEqual(census.file_record(big)['state'], 'HOLD')

    def test_actual_named_replacement_during_read_is_not_a_source_match(self):
        path = self.root / 'source'; path.write_bytes(b'original')
        replacement = self.root / 'replacement'; replacement.write_bytes(b'replaced')
        original_fstat = os.fstat
        calls = 0
        def changed(fd):
            nonlocal calls
            calls += 1
            if calls == 2:
                replacement.replace(path)
            return original_fstat(fd)
        with patch.object(census.os, 'fstat', side_effect=changed):
            self.assertEqual(census.file_record(path)['state'], 'HOLD')
        self.assertEqual(path.read_bytes(), b'replaced')

    def test_immediate_mu_names_do_not_recurse_or_follow_links(self):
        (self.root / 'nested').mkdir()
        (self.root / 'nested' / 'secret-source.php').write_text('private body')
        (self.root / 'link').symlink_to(self.root / 'nested', target_is_directory=True)
        result = census.mu_names(self.root)
        self.assertEqual(result['state'], 'OBSERVED_POINT_NAMES')
        self.assertEqual({x['name']: x['type'] for x in result['entries']}, {'link': 'symlink', 'nested': 'directory'})
        self.assertNotIn('secret-source', json.dumps(result))

    def test_too_many_mu_entries_hold_instead_of_truncating_success(self):
        for index in range(census.MAX_NAMES + 1):
            (self.root / str(index)).touch()
        result = census.mu_names(self.root)
        self.assertEqual(result['state'], 'HOLD')
        self.assertNotIn('entries', result)

    def test_literal_executable_resolution_returns_only_source_identity(self):
        actual = self.root / 'actual'; actual.write_bytes(b'not executed')
        link = self.root / 'wp'; link.symlink_to(actual)
        with patch.object(census.shutil, 'which', return_value=str(link)):
            result = census.executable_record('wp')
        self.assertEqual(result['path'], str(link))
        self.assertEqual(result['resolvedPath'], str(actual))
        self.assertEqual(result['source']['sha256'], hashlib.sha256(b'not executed').hexdigest())
        with patch.object(census.shutil, 'which', return_value='relative/wp'):
            self.assertEqual(census.executable_record('wp')['state'], 'HOLD')

    def test_cli_has_no_path_or_native_override(self):
        result = subprocess.run([sys.executable, '-I', '-B', str(SCRIPT), '--path=/tmp'], capture_output=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(result.stdout, b'')
        self.assertIn(b'Fixed no-argument collector', result.stderr)


if __name__ == '__main__':
    unittest.main()

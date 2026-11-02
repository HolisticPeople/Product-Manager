"""Disconnected state-transition regressions. No target, WP, SSH or declaration loads."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import shutil
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch
import uuid

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('pm_state_bound', HERE / 'state-bound.py')
control = importlib.util.module_from_spec(spec)
spec.loader.exec_module(control)
REFERENCES = json.loads((HERE / 'state-bound-packages.json').read_text())


class OfflinePerformer:
    """Fictional collaborator only. Not selectable from the live CLI."""
    def __init__(self, plan):
        self.current = copy.deepcopy(plan['expectedBefore'])
        self.prior = copy.deepcopy(REFERENCES['references']['prior']['files'])
        self.calls = []
        self.copy_error = None
        self.effects_code = 0
        self.effects_stderr = b''
        self.effect_rows = control.EFFECTS
        self.source_tree = plan['releaseTree']
        self.admit_error = None
        self.drift_after_effects = False

    def admit(self, plan, expected):
        self.calls.append('admit')
        if self.admit_error:
            raise self.admit_error
        if self.current != expected:
            raise control.Refused('Fictional current admission mismatch')

    def source(self, plan):
        self.calls.append('source')
        return {'sha': plan['releaseSHA'], 'tree': self.source_tree, 'files': plan['expectedAfter']}

    def prior_image(self, plan):
        self.calls.append('prior')
        return self.prior

    def observe(self, plan):
        self.calls.append('observe')
        return self.current

    def copy(self, plan):
        self.calls.append('copy')
        if self.copy_error:
            raise self.copy_error
        self.current = copy.deepcopy(plan['expectedAfter'])
        return SimpleNamespace(returncode=0, stdout=b'fictional rsync raw result', stderr=b'')

    def effects(self, plan):
        self.calls.append('effects')
        if self.drift_after_effects:
            self.current = {}
        return SimpleNamespace(returncode=self.effects_code,
                               stdout=control.encoded({'effects': self.effect_rows}),
                               stderr=self.effects_stderr)

    def verify_effects_and_continuity(self, plan):
        self.calls.append('verify-effects-and-continuity')


class StateBoundTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='pm-fictional-state-bound-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name).resolve()
        self.root.chmod(0o700)

    def selection(self, action='forward'):
        refs = REFERENCES['references']
        selected = refs['forward' if action == 'forward' else 'recovery']
        return {'schema': 'hp_pm_state_bound_selection_v1', 'action': action,
                'referencesSHA256': control.digest(REFERENCES), 'site': REFERENCES['site'],
                'root': REFERENCES['root'], 'plugin': 'products-manager', 'operationId': uuid.uuid4().hex,
                'journalRoot': str(self.root), 'releaseSHA': 'f' * 40, 'releaseTree': 'e' * 40,
                'preparedSource': {'sha': selected['sha'], 'tree': selected['tree']},
                'expectedBefore': copy.deepcopy(refs['prior' if action == 'forward' else 'forward']['files']),
                'expectedAfter': copy.deepcopy(selected['files']), 'ownedPartial': None,
                'fieldBoundary': 'BEFORE_ANY_FIELD', 'requiredEffects': control.EFFECTS.copy()}

    def store(self, plan):
        path = self.root / plan['operationId']
        path.mkdir(mode=0o700)
        return control.Store(path)

    def snapshots(self, store):
        return {p.name: (p.read_bytes(), p.stat().st_mtime_ns, p.stat().st_mode & 0o777)
                for p in store.path.iterdir()}

    def test_exact_forward_and_recovery_keep_full_modes(self):
        self.assertEqual(len(REFERENCES['references']['prior']['files']), 27)
        self.assertEqual(len(REFERENCES['references']['forward']['files']), 31)
        self.assertEqual(REFERENCES['references']['prior']['files'], REFERENCES['references']['recovery']['files'])
        for action in ('forward', 'recovery'):
            with self.subTest(action=action):
                plan = self.selection(action)
                store, performer = self.store(plan), OfflinePerformer(plan)
                complete = control.transition(plan, REFERENCES, store, performer)
                self.assertEqual(complete['state'], 'CONTROLLED_TRANSITION_COMPLETE')
                self.assertEqual(performer.current, plan['expectedAfter'])
                self.assertEqual(performer.calls.count('copy'), 1)
                self.assertEqual(performer.calls.count('effects'), 1)
                self.assertTrue(all(mode == 0o600 for _, _, mode in self.snapshots(store).values()))

    def test_completed_repeat_is_immutable_and_never_copies_or_runs_effects(self):
        plan = self.selection()
        store, performer = self.store(plan), OfflinePerformer(plan)
        control.transition(plan, REFERENCES, store, performer)
        before = self.snapshots(store)
        performer.calls.clear()
        result = control.completed_repeat(plan, REFERENCES, store, performer)
        self.assertEqual(result['journalWrites'], 0)
        self.assertEqual(before, self.snapshots(store))
        self.assertNotIn('copy', performer.calls)
        self.assertNotIn('effects', performer.calls)

    def test_held_or_drifted_repeat_never_becomes_pass(self):
        for fault in ('current', 'prior', 'source', 'held', 'changed-plan'):
            with self.subTest(fault=fault):
                plan = self.selection()
                store, performer = self.store(plan), OfflinePerformer(plan)
                control.transition(plan, REFERENCES, store, performer)
                if fault == 'current': performer.current = {}
                if fault == 'prior': performer.prior = {}
                if fault == 'source': performer.source_tree = '0' * 40
                if fault == 'held': store.create('held.json', b'{}')
                if fault == 'changed-plan': plan['releaseSHA'] = '0' * 40
                before = self.snapshots(store)
                with self.assertRaises(control.Refused):
                    control.completed_repeat(plan, REFERENCES, store, performer)
                self.assertEqual(before, self.snapshots(store))

    def test_missing_extra_wrong_bytes_modes_and_foreign_bindings_refuse_before_admission(self):
        for fault in ('site', 'root', 'reference', 'prepared-source', 'missing', 'extra', 'bytes',
                      'mode', 'version-only', 'field-started', 'effects', 'action', 'release-tree'):
            with self.subTest(fault=fault):
                plan = self.selection('recovery')
                name = next(iter(plan['expectedAfter']))
                if fault in ('site', 'root'): plan[fault] += '/foreign'
                if fault == 'reference': plan['referencesSHA256'] = '0' * 64
                if fault == 'prepared-source': plan['preparedSource']['tree'] = '0' * 40
                if fault == 'missing': del plan['expectedAfter'][name]
                if fault == 'extra': plan['expectedAfter']['foreign.php'] = plan['expectedAfter'][name]
                if fault == 'bytes': plan['expectedAfter'][name]['sha256'] = '0' * 64
                if fault == 'mode': plan['expectedAfter'][name]['mode'] = 0o755
                if fault == 'version-only': plan['expectedBefore'] = {'version': '2.7.2'}
                if fault == 'field-started': plan['fieldBoundary'] = 'FIELD_PARTIAL'
                if fault == 'effects': plan['requiredEffects'] = []
                if fault == 'action': plan['action'] = 'automatic-rollback'
                if fault == 'release-tree': plan['releaseTree'] = 'version-2.5.11'
                store, performer = self.store(plan), OfflinePerformer(plan)
                with self.assertRaises(control.Refused): control.transition(plan, REFERENCES, store, performer)
                self.assertFalse(performer.calls)
                self.assertFalse(list(store.path.iterdir()))

    def test_actual_source_prior_and_admission_gaps_hold_before_copy(self):
        for fault in ('source', 'prior', 'native-admission'):
            with self.subTest(fault=fault):
                plan = self.selection()
                store, performer = self.store(plan), OfflinePerformer(plan)
                if fault == 'source': performer.source_tree = '0' * 40
                if fault == 'prior': performer.prior = {}
                if fault == 'native-admission': performer.admit_error = control.UnsupportedAdmission('No actual performer')
                with self.assertRaises(control.Refused): control.transition(plan, REFERENCES, store, performer)
                self.assertNotIn('copy', performer.calls)
                self.assertFalse(control.decode(store.read('held.json'))['mutationPossible'])
                self.assertIn(b'trace', store.read('caught.json'))

    def test_timeout_retains_original_partial_raw_streams_before_hold(self):
        plan = self.selection()
        store, performer = self.store(plan), OfflinePerformer(plan)
        performer.copy_error = subprocess.TimeoutExpired('FICTIONAL RSYNC', 1, output=b'partial-copy', stderr=b'original-error')
        with self.assertRaises(subprocess.TimeoutExpired): control.transition(plan, REFERENCES, store, performer)
        self.assertEqual(control.decode(store.read('copy.error.json'))['class'], 'TimeoutExpired')
        self.assertEqual(store.read('copy.stdout'), b'partial-copy')
        self.assertEqual(store.read('copy.stderr'), b'original-error')
        self.assertTrue(control.decode(store.read('held.json'))['mutationPossible'])
        self.assertNotIn('effects', performer.calls)
        self.assertFalse((store.path / 'complete.json').exists())

    def test_required_cache_effect_or_bad_result_and_final_drift_hold_without_retry(self):
        for fault in ('effect-failure', 'missing-effect', 'poststate'):
            with self.subTest(fault=fault):
                plan = self.selection()
                store, performer = self.store(plan), OfflinePerformer(plan)
                if fault == 'effect-failure': performer.effects_code, performer.effects_stderr = 1, b'cache flush failed'
                if fault == 'missing-effect': performer.effect_rows = control.EFFECTS[:-1]
                if fault == 'poststate': performer.drift_after_effects = True
                with self.assertRaises(control.Refused): control.transition(plan, REFERENCES, store, performer)
                self.assertTrue((store.path / 'effects.stdout').exists())
                self.assertTrue(control.decode(store.read('held.json'))['mutationPossible'])
                calls = performer.calls.copy()
                with self.assertRaises(FileExistsError): control.transition(plan, REFERENCES, store, performer)
                self.assertEqual(calls, performer.calls)

    def test_existing_intent_and_other_local_path_cannot_replay_same_selection(self):
        plan = self.selection()
        store, performer = self.store(plan), OfflinePerformer(plan)
        control.transition(plan, REFERENCES, store, performer)
        calls = performer.calls.copy()
        with self.assertRaises(FileExistsError): control.transition(plan, REFERENCES, store, performer)
        other = self.root / 'other-attempt'
        other.mkdir(mode=0o700)
        with self.assertRaises(control.Refused):
            control.transition(plan, REFERENCES, control.Store(other), performer)
        self.assertEqual(calls, performer.calls)

    def test_only_explicit_owned_reviewed_known_partial_recovery_can_proceed(self):
        forward = self.selection()
        parent, runtime = self.store(forward), OfflinePerformer(forward)
        runtime.copy_error = RuntimeError('FICTIONAL partial copy, outcome unknown')
        with self.assertRaises(RuntimeError): control.transition(forward, REFERENCES, parent, runtime)
        plan = self.selection('recovery')
        plan['expectedBefore'] = copy.deepcopy(REFERENCES['references']['prior']['files'])
        # A concrete partial state with one new forward-only file, all bytes explicitly pinned.
        name = next(n for n in forward['expectedAfter'] if n not in plan['expectedBefore'])
        plan['expectedBefore'][name] = forward['expectedAfter'][name]
        plan['ownedPartial'] = {'reviewedCurrentMapSHA256': control.digest(plan['expectedBefore']),
                                'parentIntentSHA256': control.hashlib.sha256(parent.read('intent.json')).hexdigest(),
                                'parentHeldSHA256': control.hashlib.sha256(parent.read('held.json')).hexdigest()}
        for fault in ('no-parent', 'foreign-map', 'wrong-held', 'wrong-reviewed-map'):
            changed = copy.deepcopy(plan)
            if fault == 'foreign-map': changed['expectedBefore'][name]['sha256'] = '0' * 64
            if fault == 'wrong-held': changed['ownedPartial']['parentHeldSHA256'] = '0' * 64
            if fault == 'wrong-reviewed-map': changed['ownedPartial']['reviewedCurrentMapSHA256'] = '0' * 64
            with self.assertRaises(control.Refused):
                control.validate(changed, REFERENCES, None if fault == 'no-parent' else parent)
        copied = self.root / 'copied-parent'
        shutil.copytree(parent.path, copied)
        with self.assertRaisesRegex(control.Refused, 'Parent journal relocated'):
            control.validate(plan, REFERENCES, control.Store(copied))
        store, performer = self.store(plan), OfflinePerformer(plan)
        self.assertEqual(control.transition(plan, REFERENCES, store, performer, parent)['state'], 'CONTROLLED_TRANSITION_COMPLETE')

    def test_live_entry_refuses_before_files_process_credentials_or_caller_certificate(self):
        with patch.object(os, 'open', side_effect=AssertionError('No file access')) as opened, \
             patch.object(subprocess, 'run', side_effect=AssertionError('No processes')) as process:
            with self.assertRaisesRegex(control.UnsupportedAdmission, 'NO_SUPPORTED_PM_NATIVE_PREFIELD'):
                control.live_main({'qualified': True, 'admission': True, 'performer': OfflinePerformer})
            self.assertEqual(opened.call_count, 0)
            self.assertEqual(process.call_count, 0)

    def test_rehashing_foreign_reference_maps_does_not_self_authorize(self):
        references = copy.deepcopy(REFERENCES)
        references['references']['forward']['sha'] = '0' * 40
        plan = self.selection()
        plan['referencesSHA256'] = control.digest(references)
        with self.assertRaisesRegex(control.Refused, 'Finite reviewed package references changed'):
            control.validate(plan, references)

    def test_intent_directory_fsync_failure_prevents_any_performer_call(self):
        plan = self.selection()
        store, performer = self.store(plan), OfflinePerformer(plan)
        real_fsync = os.fsync
        def fsync(fd):
            import stat
            if stat.S_ISDIR(os.fstat(fd).st_mode):
                raise OSError('FICTIONAL directory fsync failure')
            return real_fsync(fd)
        with patch.object(os, 'fsync', side_effect=fsync):
            with self.assertRaisesRegex(OSError, 'directory fsync failure'):
                control.transition(plan, REFERENCES, store, performer)
        self.assertFalse(performer.calls)
        self.assertTrue((store.path / 'intent.json').exists())
        self.assertFalse((store.path / 'complete.json').exists())

    def test_duplicate_json_private_modes_and_symlink_journal_refuse(self):
        with self.assertRaises(control.Refused): control.decode(b'{"state":1,"state":2}')
        plan = self.selection()
        store = self.store(plan)
        store.create('private.json', b'{}')
        (store.path / 'private.json').chmod(0o644)
        with self.assertRaises(control.Refused): store.read('private.json')
        alias = self.root / 'alias'
        alias.symlink_to(store.path)
        with self.assertRaises(control.Refused): control.Store(alias)


if __name__ == '__main__':
    unittest.main(verbosity=2)

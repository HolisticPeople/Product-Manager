#!/usr/bin/env python3
"""New successor parser/lifecycle cases using explicit disconnected collaborators."""
import importlib.util
import json
from pathlib import Path
from types import SimpleNamespace
import unittest

HERE=Path(__file__).resolve().parent

def load(name,file):
    spec=importlib.util.spec_from_file_location(name,HERE/file);module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module);return module

a=load('actual','pm-actual-adapter.py');old=load('oldfixtures','test-state-bound.py')


class MetadataPerformer(old.OfflinePerformer):
    def __init__(self,plan):
        super().__init__(plan);self.payload=Path('/fictional-only');self.version='2.7.2' if plan['action']=='forward' else '2.5.11'
        self.deploy=SimpleNamespace(entry_version=lambda payload:self.version)
        self.receipt={'alreadyActive':True,'rootMatched':True,'pluginPathMatched':True,'siteMatched':True,'environmentMatched':True,'version':self.version,'effects':a.EFFECTS}
    def effects(self,plan):
        self.calls.append('effects')
        return SimpleNamespace(returncode=self.effects_code,stdout=a.encoded(self.receipt),stderr=self.effects_stderr)


class SuccessorTests(unittest.TestCase):
    def setUp(self):
        self.fixture=old.StateBoundTests();self.fixture.setUp()
    def tearDown(self):
        self.fixture.doCleanups()
    def test_full_original_metadata_forward_and_recovery_then_immutable_repeat(self):
        for action in ('forward','recovery'):
            plan=self.fixture.selection(action);store=self.fixture.store(plan);p=MetadataPerformer(plan)
            complete=a.transition(old.control,plan,old.REFERENCES,store,p)
            self.assertEqual(complete['state'],'CONTROLLED_TRANSITION_COMPLETE')
            self.assertEqual(store.read('effects.stdout'),a.encoded(p.receipt))
            before=self.fixture.snapshots(store);p.calls.clear()
            repeat=old.control.completed_repeat(plan,old.REFERENCES,store,p)
            self.assertEqual(repeat['journalWrites'],0);self.assertEqual(before,self.fixture.snapshots(store))
            self.assertNotIn('copy',p.calls);self.assertNotIn('effects',p.calls)
    def test_incomplete_or_wrong_version_metadata_stays_held_with_actual_raw(self):
        for fault in ('wrongversion','missingflag','incompleteeffects','literalstderr'):
            plan=self.fixture.selection();store=self.fixture.store(plan);p=MetadataPerformer(plan)
            if fault=='wrongversion':p.receipt['version']='wrong'
            if fault=='missingflag':del p.receipt['rootMatched']
            if fault=='incompleteeffects':p.receipt['effects']=a.EFFECTS[:-1]
            if fault=='literalstderr':p.effects_stderr=b'literal effect error'
            with self.assertRaises((a.Refused,old.control.Refused)):a.transition(old.control,plan,old.REFERENCES,store,p)
            self.assertEqual(store.read('effects.stdout'),a.encoded(p.receipt))
            self.assertEqual(store.read('effects.stderr'),p.effects_stderr)
            self.assertTrue(json.loads(store.read('held.json'))['mutationPossible'])
            self.assertFalse((store.path/'complete.json').exists())


if __name__=='__main__':unittest.main()

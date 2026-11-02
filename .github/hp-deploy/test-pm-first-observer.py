"""New wrapper mechanics only: synthetic native output, real files/FD/child.

No PHP, WordPress, target, source qualification or native admission is tested.
Existing PM/Zen suites are not replayed. Immutable helpers are used unchanged.
"""
import copy
import hashlib
import importlib.util
import inspect
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import sys
import tempfile
import time
import unittest
from unittest.mock import patch

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('first_observer', HERE / 'pm-first-observer.py')
observer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(observer)


def producer_value(root, request):
    """Synthetic values in the exact producer structure, never admission proof."""
    startup = {name: {'rows': [{'option_id': str(index+3), 'option_name': name,
        'option_value': value, 'autoload': 'yes'}], 'cachedValue': value}
        for index, (name, value) in enumerate(request['startup'].items())}
    schedules = {'hp_inventory_fulfillment_intake':'hp_fulfillment_five_minutes',
        'hp_inventory_demand_refresh':'daily','hp_inventory_combine_return_scan':'hourly',
        'hp_inventory_ss_v2_shadow_audit_tick':'hourly',
        'hp_inventory_shipstation_inbound_reconcile':'hp_inventory_ten_minutes',
        'hp_inventory_expiration_scan':'daily','hp_inventory_fulfillment_jobs':'hp_fulfillment_five_minutes',
        'hp_inventory_fulfillment_review':'daily','hp_inventory_dropship_tracking_reconcile':'hourly',
        'hp_inventory_parcel_tick':'hp_inventory_parcel_five_minutes',
        'hp_inventory_order_status_audit_v1':'hp_fulfillment_five_minutes',
        'hp_inventory_ss_health_check':'hp_inventory_fifteen_minutes'}
    cron = {name: {'state':'OBSERVED_SINGLETON','schedule':schedule,'args':[],
                  'argumentKey':hashlib.md5(b'a:0:{}').hexdigest()} for name,schedule in schedules.items()}
    frame = {'schema':'hp_pm_actual_native_before_v1','root':str(root),'site':'https://holisticpeople.com/',
        'environment':'production','context':'full-native','projection':'code-before',
        'siteRows':[{'option_id':str(i+1),'option_name':name,'option_value':'https://holisticpeople.com',
                     'autoload':'yes'} for i,name in enumerate(('home','siteurl'))],
        'startup':startup,'inventoryCron':cron,'optionCallbacks':request['allowedOptionCallbacks']['full-native'],
        'executionAuthority':False,'disposition':'PRE_FIELD_CLEAR',
        'actor':{'context':'full-native-WPCLI','id':7474},
        'erp':{'callbacks':request['allowedErpCallbacks'],'retired':True,'persist':False,'enabled':False},
        'products':{str(i):{'postSHA256':'1'*64,'sourceValueAndPointerRows':1,
            'sourceValueAndPointerSHA256':'2'*64,'journalRows':[],'pendingBoundaryAndLeaseRows':[]}
            for i in request['productIds']},
        'policyRows':[], 'cron':{'state':'OBSERVED','autoload':'yes','selectedOwnerEvents':[]},
        'actionScheduler':{'tableExists':False,'selectedOwnerActions':None}}
    return {'contract':'hp_pm_three_source_observation','version':1,'context':'cli_code',
            'state':'COMPLETE','disposition':'PRE_FIELD_CLEAR','projection':frame}


CHILD = r'''#!/usr/bin/env python3
import os,json,hashlib,fcntl
from pathlib import Path
FIXTURE_FUNCTION
config=Path(os.environ['PM_NATIVE_CONFIG'])
raw=config.read_bytes()
assert hashlib.sha256(raw).hexdigest()==os.environ['PM_NATIVE_CONFIG_SHA256']
c=json.loads(raw);root=Path(c['request']['root']);path=root/'wp-content/hp-deploy/selected-phase.lock'
fd=int(os.environ['PM_NATIVE_PHASE_FD']);row=os.fstat(fd);named=path.stat()
assert os.getppid()==int(os.environ['PM_NATIVE_OWNER_PID'])
assert (row.st_dev,row.st_ino)==(named.st_dev,named.st_ino)
assert fcntl.fcntl(fd,fcntl.F_GETFL)&os.O_ACCMODE==os.O_RDONLY
matches=[]
for number in range(3,64):
 try:
  x=os.fstat(number)
  if (x.st_dev,x.st_ino)==(row.st_dev,row.st_ino):matches.append(number)
 except OSError:pass
assert matches==[fd],matches
fcntl.flock(fd,fcntl.LOCK_UN)
probe=os.open(path,os.O_RDWR)
try:
 try:
  fcntl.flock(probe,fcntl.LOCK_EX|fcntl.LOCK_NB)
  raise AssertionError('child observation unlocked parent')
 except BlockingIOError:pass
finally:os.close(probe)
(config.parent/'child-mechanics.json').write_text(json.dumps({'pid':os.getpid(),'parent':os.getppid(),'readonlyViewOnly':True}))
value=producer_value(root,c['request'])
start={k:v for k,v in value['projection'].items() if k in ('schema','root','site','environment','context',
    'projection','siteRows','startup','inventoryCron','optionCallbacks','executionAuthority','disposition')}
start.update(context='pre-init',projection='startup',disposition='STARTUP_CONDITIONS_OBSERVED',
             optionCallbacks=c['request']['allowedOptionCallbacks']['pre-init'])
if (root/'bad-startup').exists():start={'schema':'hp_pm_actual_native_before_v1','context':'pre-init',
    'projection':'startup','executionAuthority':False}
p=config.parent/'preinit-readback.json';p.write_text(json.dumps(start));p.chmod(0o600)
if (root/'wrong-type').exists():print(json.dumps({'version':True}));raise SystemExit(0)
print(json.dumps(value))
'''.replace('FIXTURE_FUNCTION', inspect.getsource(producer_value))


class ObserverCases(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.base = Path(self.temp.name).resolve(); self.base.chmod(0o700)
        self.root = self.base / 'public'; self.root.mkdir()
        for name in ('wp-content/plugins', 'wp-content/themes', 'wp-content/hp-deploy/products-manager', 'wp-includes'):
            (self.root / name).mkdir(parents=True, exist_ok=True)
        for name in ('wp-load.php', 'wp-settings.php', 'wp-includes/option.php', 'wp-includes/cron.php', 'wp-includes/class-wp-hook.php', 'wp-config.php'):
            (self.root / name).write_bytes(b'<?php /* synthetic source */')
        self.lock = self.root / 'wp-content/hp-deploy/selected-phase.lock'
        self.lock.touch(); self.lock.chmod(0o600)
        self.code = self.base / 'code'; self.code.mkdir(mode=0o700)
        kit = self.code / 'first-observer-kit'; kit.mkdir(mode=0o700)
        for name in observer.KIT:
            shutil.copyfile(HERE / 'first-observer-kit' / name, kit / name)
            (kit / name).chmod(0o600)
        for name in observer.PHP:
            shutil.copyfile(HERE / name, self.code / name)
            (self.code / name).chmod(0o600)
        self.cli = self.base / 'wp-double'; self.cli.write_text(CHILD); self.cli.chmod(0o700)
        self.resource = self.base / 'resource.raw'; self.resource.write_bytes(b'Synthetic resource evidence; not permission'); self.resource.chmod(0o600)
        self.root_patch = patch.object(observer, 'ROOT', self.root); self.root_patch.start()
        self.here_patch = patch.object(observer, 'HERE', self.code); self.here_patch.start()
        pins = {}
        for path in [self.cli, Path(sys.executable).resolve(), *[self.root / name for name in ('wp-load.php', 'wp-settings.php', 'wp-includes/option.php', 'wp-includes/cron.php', 'wp-includes/class-wp-hook.php', 'wp-config.php')]]:
            row = path.stat(); pins[str(path)] = {'sha256': observer.sha(path.read_bytes()), 'bytes': row.st_size,
                                                'mode': format(stat.S_IMODE(row.st_mode), '04o'), 'uid': row.st_uid}
        self.selection = {'schema': 'hp_pm_first_observation_v1', 'operation': 'a'*32, 'attempt': 'test-once',
            'privateAttempt': str(self.base / 'attempt'), 'deadlineUTC': int(time.time())+600, 'sourcePins': pins,
            'wpCli': {'path': str(self.cli), 'sha256': pins[str(self.cli)]['sha256'],
                      'startupEnvironmentSHA256': observer.sha(observer.encoded({n:os.environ.get(n) for n in observer.ENV_NAMES})),
                      'cwd': str(Path.cwd().resolve())},
            'nativeRequest': {'schema':'hp_pm_actual_native_reader_observation_v1', 'root':str(self.root),
                'site':'https://holisticpeople.com/', 'environment':'production', 'context':'full-native',
                'projection':'code-before', 'productIds':[43395,43406,43457], 'phaseFile':str(self.lock),
                'startup':dict(observer.STARTUP), 'allowedOptionCallbacks':{c:{n:[] for n in observer.OPTION_TAGS} for c in ('pre-init','full-native')},
                'allowedErpCallbacks':{n:[] for n in observer.ERP_TAGS}},
            'resourceAdmissionRef':{'path':str(self.resource),'sha256':observer.sha(self.resource.read_bytes())}}

    def tearDown(self):
        self.here_patch.stop(); self.root_patch.stop()
        for filename in observer.KIT:
            name = filename[:-3]
            module = sys.modules.get(name)
            if module and str(getattr(module, '__file__', '')).startswith(str(self.code)):
                del sys.modules[name]
        self.temp.cleanup()

    def run_once(self):
        raw = observer.encoded(self.selection)
        return observer.perform(raw, observer.sha(raw))

    def test_real_readonly_same_inode_direct_child_does_not_unlock_parent(self):
        result = self.run_once()
        self.assertEqual(result['state'], 'FIRST_OBSERVATION_COMPLETE')
        self.assertTrue(result['childExitObserved'])
        self.assertFalse(result['resourceClearance'])
        self.assertFalse(result['nextOperationAuthorized'])
        attempt = self.base / 'attempt'
        self.assertEqual(json.loads((attempt/'native/child-mechanics.json').read_text())['readonlyViewOnly'], True)
        self.assertEqual((attempt/'selection.original.json').read_bytes(), observer.encoded(self.selection))
        self.assertEqual(stat.S_IMODE(attempt.stat().st_mode), 0o700)
        self.assertEqual(stat.S_IMODE((attempt/'native/config.json').stat().st_mode), 0o600)
        self.assertFalse((attempt/'native/config.json').read_bytes().endswith(b'\n'))
        self.assertFalse((self.root/'wp-content/hp-deploy/selected-phase-owner.json').exists())

    def test_literal_zero_wrong_typed_output_is_hold_with_original_raw(self):
        (self.root/'wrong-type').touch()
        result = self.run_once()
        self.assertEqual(result['state'], 'UNKNOWN_HOLD')
        self.assertTrue(result['childExitObserved'])
        self.assertEqual(result['childReturnCode'], 0)
        self.assertEqual(json.loads((self.base/'attempt/native/actual.stdout').read_bytes()), {'version':True})
        self.assertTrue((self.base/'attempt/original-wrapper-error.json').exists())

    def test_missing_lock_or_state_does_not_create_setup(self):
        self.lock.unlink()
        with self.assertRaises(FileNotFoundError): self.run_once()
        self.assertFalse(self.lock.exists())
        self.assertFalse((self.base/'attempt').exists())

    def test_any_owner_even_empty_or_symlink_refuses_without_clearing(self):
        owner=self.root/'wp-content/hp-deploy/selected-phase-owner.json'
        owner.symlink_to(self.base/'missing')
        with self.assertRaises(observer.Refused): self.run_once()
        self.assertTrue(owner.is_symlink())
        self.assertFalse((self.base/'attempt').exists())

    def test_foreign_physical_lock_refuses_without_attempt_or_child(self):
        import fcntl
        with self.lock.open('r+') as locked:
            fcntl.flock(locked, fcntl.LOCK_EX|fcntl.LOCK_NB)
            with self.assertRaises(BlockingIOError): self.run_once()
        self.assertFalse((self.base/'attempt').exists())

    def test_source_drift_expired_window_and_manufactured_expected_native_refuse(self):
        for change in ('drift','deadline','expected'):
            selected=copy.deepcopy(self.selection)
            if change=='drift': selected['sourcePins'][str(self.cli)]['sha256']='0'*64
            elif change=='deadline': selected['deadlineUTC']=int(time.time())
            else: selected['expectedNativeBefore']={'state':'PASS'}
            raw=observer.encoded(selected)
            with self.assertRaises(observer.Refused): observer.perform(raw,observer.sha(raw))
        self.assertFalse((self.base/'attempt').exists())

    def test_changed_startup_environment_is_not_silently_replaced(self):
        self.selection['wpCli']['startupEnvironmentSHA256']='0'*64
        with self.assertRaises(observer.Refused): self.run_once()
        self.assertFalse((self.base/'attempt').exists())

    def test_existing_private_attempt_is_never_adopted(self):
        attempt=self.base/'attempt';attempt.mkdir(mode=0o700);(attempt/'foreign').write_bytes(b'evidence')
        with self.assertRaises(observer.Refused): self.run_once()
        self.assertEqual((attempt/'foreign').read_bytes(),b'evidence')

    def test_cleanup_wait_failure_null_return_means_child_lifetime_unknown(self):
        # Exercise the ACTUAL pinned raw_process error/cleanup paths with a
        # mechanical process double. No process is spawned or signalled here.
        pair, phase, process=observer.load_kit()
        class Unresolved:
            pid=123456789
            returncode=None
            def communicate(self, data, timeout): raise subprocess.TimeoutExpired(['local-double'],timeout)
            def poll(self): return None
            def wait(self,timeout): raise subprocess.TimeoutExpired(['local-double-cleanup'],timeout)
        def actual_fault(*args,**kwargs):
            with patch.object(process.subprocess,'Popen',return_value=Unresolved()), patch.object(process.os,'killpg'):
                return original_phase(*args,**kwargs)
        original_phase=phase.observation_process
        with patch.object(observer,'load_kit',return_value=(pair,phase,process)), patch.object(phase,'observation_process',side_effect=actual_fault):
            result=self.run_once()
        self.assertEqual(result['state'],'CHILD_LIFETIME_UNKNOWN')
        self.assertFalse(result['childExitObserved'])
        self.assertFalse(result['resourceClearance'])
        self.assertFalse(result['nextOperationAuthorized'])
        self.assertEqual(result['ownershipRetained'],'NOT_ESTABLISHED_BY_WRAPPER')
        invocation=self.base/'attempt/native'
        self.assertIsNone(json.loads((invocation/'actual.cleanup.return.json').read_bytes())['returncode'])
        self.assertEqual(json.loads((invocation/'actual.error.json').read_bytes())['class'],'TimeoutExpired')
        self.assertTrue((invocation/'actual.cleanup.error.json').exists())

    def test_boolean_returncode_is_not_an_observed_exit(self):
        directory=self.base/'raw';directory.mkdir(mode=0o700)
        p=directory/'actual.return.json';p.write_bytes(b'{"returncode":false}');p.chmod(0o600)
        self.assertFalse(observer.lifetime(directory)['childExitObserved'])

    def test_nested_producer_empty_missing_wrong_types_and_presence_refuse(self):
        positive=producer_value(self.root,self.selection['nativeRequest'])
        observer.result_contract(positive)
        for field in ('siteRows','startup','inventoryCron','optionCallbacks','erp','cron','actionScheduler'):
            value=copy.deepcopy(positive);value['projection'][field]=[] if field=='siteRows' else {}
            with self.assertRaises(observer.Refused):observer.result_contract(value)
        changes=[('product-empty',lambda f:f['products'].__setitem__('43395',{})),
            ('bool-count',lambda f:f['products']['43395'].__setitem__('sourceValueAndPointerRows',True)),
            ('nonempty-journal',lambda f:f['products']['43395'].__setitem__('journalRows',[{}])),
            ('cached-null',lambda f:f['startup']['hp_core_version'].__setitem__('cachedValue',None)),
            ('erp-numeric',lambda f:f['erp'].__setitem__('persist',0)),
            ('absent-as-list',lambda f:f['actionScheduler'].__setitem__('selectedOwnerActions',[])),
            ('present-as-null',lambda f:f['actionScheduler'].__setitem__('tableExists',True)),
            ('cron-missing-list',lambda f:f['cron'].__setitem__('selectedOwnerEvents',None))]
        for name,change in changes:
            value=copy.deepcopy(positive);change(value['projection'])
            with self.subTest(name=name),self.assertRaises(observer.Refused):observer.result_contract(value)
        observed_empty=copy.deepcopy(positive)
        observed_empty['projection']['actionScheduler']={'tableExists':True,'selectedOwnerActions':[]}
        observer.result_contract(observed_empty)

    def test_header_only_startup_literal_zero_is_not_complete(self):
        (self.root/'bad-startup').touch()
        result=self.run_once()
        self.assertEqual(result['state'],'UNKNOWN_HOLD')
        self.assertEqual(result['childReturnCode'],0)
        self.assertTrue((self.base/'attempt/native/actual.stdout').exists())
        self.assertNotEqual(result['state'],'FIRST_OBSERVATION_COMPLETE')


if __name__=='__main__':
    unittest.main()

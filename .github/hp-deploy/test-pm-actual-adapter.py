#!/usr/bin/env python3
"""Causal local process/filesystem faults. No target, network or WordPress."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch, Mock

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('actual', HERE / 'pm-actual-adapter.py')
a = importlib.util.module_from_spec(spec); spec.loader.exec_module(a)


class ActualTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.path = Path(self.temp.name).resolve()
        self.path.chmod(0o700)

    def tearDown(self):
        self.temp.cleanup()

    def test_durable_precedes_process_and_full_failure_retained(self):
        original = subprocess.Popen
        def run(argv, **kwargs):
            self.assertEqual(a.decode(a.private_read(self.path / 'p.intent.json'))['outcome'], 'UNKNOWN')
            return original(argv, **kwargs)
        with patch.object(a.subprocess, 'Popen', run):
            result = a.raw_process(self.path,'p',[sys.executable,'-c','import sys; print("original"); sys.stderr.write("real failure"); sys.exit(7)'])
        self.assertEqual(a.private_read(self.path / 'p.stdout'), b'original\n')
        self.assertEqual(a.private_read(self.path / 'p.stderr'), b'real failure')
        self.assertEqual(a.decode(a.private_read(self.path / 'p.return.json')), {'returncode':7})
        with self.assertRaises(a.Refused):
            a.success(result, 'failed')

    def test_timeout_retains_partial_original_streams(self):
        with self.assertRaises(subprocess.TimeoutExpired):
            a.raw_process(self.path,'timeout',[sys.executable,'-c','import time,sys; print("before timeout",flush=True); sys.stderr.write("partial error"); sys.stderr.flush(); time.sleep(2)'],timeout=.1)
        self.assertEqual(a.private_read(self.path / 'timeout.stdout'), b'before timeout\n')
        self.assertEqual(a.private_read(self.path / 'timeout.stderr'), b'partial error')
        error = a.decode(a.private_read(self.path / 'timeout.error.json'))
        self.assertEqual(error['class'],'TimeoutExpired'); self.assertEqual(error['outcome'],'UNKNOWN')
        self.assertIn('timed out',error['message']); self.assertIn('TimeoutExpired',error['trace'])

    def test_oserror_retains_errno_and_unknown(self):
        with self.assertRaises(FileNotFoundError):
            a.raw_process(self.path,'missing',['/no-such-pm-test-program'])
        error = a.decode(a.private_read(self.path / 'missing.error.json'))
        self.assertEqual(error['errno'],2); self.assertEqual(error['filename'],'/no-such-pm-test-program')
        self.assertEqual(error['outcome'],'UNKNOWN')

    def test_original_timeout_survives_cleanup_fault(self):
        process=Mock(pid=43210)
        error=subprocess.TimeoutExpired(['fixture'],.1,output=b'original-partial',stderr=b'original-error')
        process.communicate.side_effect=error
        process.poll.return_value=None
        process.wait.side_effect=OSError('second cleanup failure')
        with patch.object(a.subprocess,'Popen',return_value=process),patch.object(a.os,'killpg'):
            with self.assertRaises(subprocess.TimeoutExpired):
                a.raw_process(self.path,'double',['fixture'])
        self.assertEqual(a.decode(a.private_read(self.path/'double.error.json'))['class'],'TimeoutExpired')
        self.assertEqual(a.private_read(self.path/'double.exception.stdout'),b'original-partial')
        self.assertEqual(a.private_read(self.path/'double.exception.stderr'),b'original-error')
        self.assertEqual(a.decode(a.private_read(self.path/'double.cleanup.error.json'))['message'],'second cleanup failure')

    def test_original_bytes_filename_is_losslessly_retained(self):
        error=OSError(2,'literal failure',b'\xff-private-path')
        with patch.object(a.subprocess,'Popen',side_effect=error):
            with self.assertRaises(OSError):a.raw_process(self.path,'bytes',['fixture'])
        row=a.decode(a.private_read(self.path/'bytes.error.json'))
        self.assertEqual(row['filename'],{'type':'bytes','base64':'/y1wcml2YXRlLXBhdGg='})

    def test_oversize_retains_both_complete_streams_and_actual_exit(self):
        with self.assertRaisesRegex(a.Refused,'complete raw files retained'):
            a.raw_process(self.path,'large',[sys.executable,'-c','import sys; sys.stdout.buffer.write(b"x"*'+str(a.MAX+1)+'); sys.stderr.buffer.write(b"entire-error")'])
        self.assertEqual((self.path/'large.stdout').stat().st_size,a.MAX+1)
        self.assertEqual((self.path/'large.stderr').read_bytes(),b'entire-error')
        self.assertEqual(a.decode(a.private_read(self.path/'large.return.json')),{'returncode':0})

    def test_existing_intent_refuses_before_spawn(self):
        a.durable(self.path / 'same.intent.json',b'original')
        with patch.object(a.subprocess,'Popen') as run:
            with self.assertRaises(FileExistsError):
                a.raw_process(self.path,'same',['never'])
            run.assert_not_called()
        self.assertEqual(a.private_read(self.path / 'same.intent.json'), b'original')

    def test_fsync_failure_refuses_before_spawn(self):
        with patch.object(a.os,'fsync',side_effect=OSError('actual durability fault')), patch.object(a.subprocess,'Popen') as run:
            with self.assertRaises(OSError):
                a.raw_process(self.path,'sync',['never'])
            run.assert_not_called()

    def test_parent_mode_and_hardlink_refused(self):
        a.durable(self.path / 'private', b'kept')
        self.path.chmod(0o755)
        with self.assertRaises(a.Refused): a.private_read(self.path / 'private')
        self.path.chmod(0o700)
        os.link(self.path / 'private',self.path / 'alias')
        with self.assertRaises(a.Refused): a.private_read(self.path / 'private')

    def test_complete_map_modes_extra_and_symlink(self):
        payload = self.path / 'payload'; payload.mkdir()
        (payload / 'entry').write_bytes(b'payload'); (payload / 'entry').chmod(0o755)
        observed = a.observed_map(payload)
        self.assertEqual(observed['entry']['mode'],0o755)
        (payload / 'foreign').write_bytes(b'foreign'); (payload / 'foreign').chmod(0o644)
        self.assertEqual(set(a.observed_map(payload)),{'entry','foreign'})
        (payload / 'alias').symlink_to(payload / 'entry')
        with self.assertRaises(a.Refused): a.observed_map(payload)

    def test_duplicate_json_not_adopted(self):
        with self.assertRaises(a.Refused): a.decode(b'{"operationId":1,"operationId":2}')

    def test_actual_php_observation_unlock_cannot_release_parent_owner(self):
        import fcntl
        lock = self.path / 'lock'; lock.write_bytes(b''); lock.chmod(0o600)
        fd = os.open(lock,os.O_RDWR)
        try:
            fcntl.flock(fd,fcntl.LOCK_EX | fcntl.LOCK_NB)
            observation = os.open(lock,os.O_RDWR)
            try:
                result = subprocess.run(['php','-n','-r', '$h=fopen("php://fd/".$argv[1],"r+"); if(!is_resource($h)){exit(4);} flock($h,LOCK_UN); if(flock($h,LOCK_EX|LOCK_NB)){exit(5);} echo "actual-observation-cannot-unlock-owner";',str(observation)],pass_fds=(observation,),capture_output=True)
                self.assertEqual((result.returncode,result.stdout,result.stderr),(0,b'actual-observation-cannot-unlock-owner',b''))
            finally:
                os.close(observation)
            result = subprocess.run([sys.executable,'-c','import os,fcntl,sys; f=os.open(sys.argv[1],os.O_RDWR); fcntl.flock(f,fcntl.LOCK_EX|fcntl.LOCK_NB)',str(lock)],capture_output=True)
            self.assertNotEqual(result.returncode,0)
        finally:
            os.close(fd)

    def test_existing_remote_effect_order_and_error_propagation(self):
        ns = {'__name__':'existing_pm'}
        exec(compile((HERE / 'remote-guard.py').read_bytes(),'existing-remote-guard.py','exec'),ns)
        seen=[]
        ns['guard'] = lambda *args: (Path('/fixture'),{'alreadyActive':True,'rootMatched':True,'pluginPathMatched':True,'siteMatched':True,'environmentMatched':True,'version':'2.7.2'})
        def wp(root,*args):
            seen.append(args)
            if args == ('kinsta','cache','purge','--all'): raise RuntimeError('literal cache failure')
            return '2.7.2' if args[:2] == ('plugin','get') else ''
        ns['wp']=wp
        with self.assertRaisesRegex(RuntimeError,'literal cache failure'):
            ns['perform']('effects','base','https://holisticpeople.com','production','2.7.2')
        self.assertEqual(seen,[('plugin','activate','products-manager'),('plugin','is-active','products-manager'),('cache','flush'),('kinsta','cache','purge','--all')])


if __name__ == '__main__': unittest.main()

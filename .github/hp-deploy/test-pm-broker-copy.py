#!/usr/bin/env python3
"""Linux-only actual AF_UNIX/pidfd/process fixture; fixed temporary filesystem.

No SSH, rsync binary, PHP, WordPress, target or customer data. The server is a
local fixture script. Actual broker/guardian code executes on a substituted root.
"""
import hashlib
import importlib.util
import os
from pathlib import Path
import shlex
import signal
import socket
import subprocess
import sys
import tempfile
import time
import unittest
from unittest.mock import patch

HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('actual',HERE/'pm-actual-adapter.py')
a=importlib.util.module_from_spec(spec);spec.loader.exec_module(a)


@unittest.skipUnless(sys.platform.startswith('linux') and hasattr(socket,'SO_PEERCRED') and hasattr(os,'pidfd_open'), 'Actual Linux SO_PEERCRED/proc/pidfd required; no fallback')
class BrokerCopyTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory(prefix='pm-b-');self.base=Path(self.temp.name).resolve();self.base.chmod(0o700)
        self.root=self.base/'public';self.root.mkdir();self.content=self.root/'wp-content';self.content.mkdir()
        (self.content/'hp-deploy').mkdir();(self.root/'wp-includes').mkdir()
        self.private=self.base/'p';self.private.mkdir(mode=0o700);(self.private/'readers').mkdir(mode=0o700)
        self.operation='a'*32;self.op=self.private/self.operation;self.op.mkdir(mode=0o700)
        self.attempt=self.op/'one';self.attempt.mkdir(mode=0o700)
        self.lock=self.content/'hp-deploy/selected-phase.lock';self.lock.write_bytes(b'');self.lock.chmod(0o600)
        self.controls={name:(HERE/name).read_bytes() for name in ('remote-guard.py','pm-native-before-reader.php','pm-preinit-guard.php','pm-copy-guardian.py')}
        self.reader_hash=a.sha(self.controls['pm-native-before-reader.php']);a.durable(self.private/'readers'/(self.reader_hash+'.php'),self.controls['pm-native-before-reader.php'])
        self.output=self.base/'late-write'
        self.server=self.base/'server.py'
        self.server.write_text('#!'+sys.executable+'\nimport time\nfrom pathlib import Path\ntime.sleep(.3)\nPath('+repr(str(self.output))+').write_text("actual fixture write")\n')
        self.server.chmod(0o755)
        self.cli=self.base/'wp-fixture';self.cli.write_bytes(b'not a WP runtime');self.cli.chmod(0o755)
        for name in ('wp-includes/option.php','wp-includes/cron.php','wp-load.php','wp-settings.php'):
            (self.root/name).write_bytes(b'fixture source only');(self.root/name).chmod(0o644)
        python=Path(sys.executable).resolve()
        pin_paths=[self.root/'wp-includes/option.php',self.root/'wp-includes/cron.php',self.root/'wp-load.php',self.root/'wp-settings.php',self.cli,self.server,python]
        pins={str(p):{'sha256':a.sha(p.read_bytes()),'bytes':p.stat().st_size,'mode':p.stat().st_mode&0o777} for p in pin_paths}
        selected={'operation':self.operation,'slug':'products-manager','attempt':'one','phases':['code'],
                  'nativeReader':{'file':str(self.private/'readers'/(self.reader_hash+'.php')),'sha256':self.reader_hash},'nativeBefore':{'fixture':'native-not-executed'}}
        selected_raw=a.encoded(selected);a.durable(self.op/'selected.json',selected_raw)
        a.durable(self.op/'state.json',a.encoded({'state':'INFLIGHT','phase':'code'}))
        token='b'*64;a.durable(self.op/'token.json',a.encoded({'hash':a.sha(token.encode()),'expires':int(time.time())+300}))
        owner={'operation':self.operation,'slug':'products-manager','privateRoot':str(self.private),'selectedDigest':a.sha(selected_raw)}
        owner_path=self.content/'hp-deploy/selected-phase-owner.json';owner_path.write_bytes(a.encoded(owner));owner_path.chmod(0o600)
        self.selection={'operationId':self.operation,'sourcePins':pins,'python':{'path':str(python),'sha256':pins[str(python)]['sha256']},
                        'wpCli':{'path':str(self.cli),'sha256':pins[str(self.cli)]['sha256']},'nativeRequest':{'phaseFile':str(self.lock)},
                        'phase':{'selectedDigest':a.sha(selected_raw),'phase':'code','attempt':'one'},'token':token,
                        'controlsSHA256':{k:a.sha(v) for k,v in self.controls.items()},'expectedNativeBefore':selected['nativeBefore'],
                        'rsync':{'path':str(self.server),'sha256':pins[str(self.server)]['sha256'],'serverArgs':['--server','--fixture',str(self.root)+'/wp-content/plugins/products-manager/']}}
        self.root_patch=patch.object(a,'ROOT',str(self.root));self.root_patch.start()
        self.broker=a.Broker(self.selection,str(self.attempt),self.controls)
        self.guardian=None;self.server_pidfd=None

    def tearDown(self):
        if self.guardian is not None and self.guardian.poll() is None:
            self.guardian.kill();self.guardian.wait()
        if self.server_pidfd is not None:
            select_ready=__import__('select').select([self.server_pidfd],[],[],3)[0]
            self.assertTrue(select_ready,'Owned fixture child did not finish');os.close(self.server_pidfd)
        for key in ('fd','observation_fd','guardian_pidfd'):
            fd=getattr(self.broker,key,None)
            if fd is not None:
                try:os.close(fd)
                except OSError:pass
        if self.guardian is not None:
            if self.guardian.stdout:self.guardian.stdout.close()
            if self.guardian.stderr:self.guardian.stderr.close()
        self.root_patch.stop();self.temp.cleanup()

    def start(self):
        armed=self.broker.call('arm-copy')
        argv=shlex.split(armed['rsyncPath'])+self.selection['rsync']['serverArgs']
        self.guardian=subprocess.Popen(argv,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        deadline=time.monotonic()+3
        while not (self.attempt/'copy-server-process.json').exists():
            if self.guardian.poll() is not None:
                self.fail('Guardian failed before server: '+repr(self.guardian.communicate()))
            self.assertLess(time.monotonic(),deadline);time.sleep(.01)
        child=a.decode(a.private_read(self.attempt/'copy-server-process.json'))['childPID']
        self.server_pidfd=os.pidfd_open(child,0)

    def test_actual_authenticated_fd_handoff_and_server_exit_receipt(self):
        self.start();stdout,stderr=self.guardian.communicate(timeout=3)
        self.assertEqual((self.guardian.returncode,stdout,stderr),(0,b'',b''))
        result=self.broker.call('copy-result');self.assertEqual(result['returncode'],0)
        self.assertEqual(self.output.read_text(),'actual fixture write')
        self.assertTrue(a.decode(a.private_read(self.attempt/'copy-fd-handoff.json'))['acknowledged'])

    def test_guardian_death_late_writer_missing_receipt_stays_inflight(self):
        self.start();self.guardian.kill();self.guardian.wait()
        self.assertTrue(__import__('select').select([self.server_pidfd],[],[],3)[0])
        self.assertEqual(self.output.read_text(),'actual fixture write')
        with self.assertRaises(FileNotFoundError):self.broker.call('copy-result')
        self.assertEqual(a.decode(a.private_read(self.op/'state.json')),{'state':'INFLIGHT','phase':'code'})
        with self.assertRaisesRegex(a.Refused,'never adopted'):a.Broker(self.selection,str(self.attempt),self.controls)

    def test_real_repeat_reuses_immutable_attempt_in_read_only_namespace(self):
        self.start();self.guardian.communicate(timeout=3);self.broker.call('copy-result')
        originals={p.name:p.read_bytes() for p in self.attempt.iterdir() if p.is_file()}
        # Close this fixture's broker owner only after its server/guardian exited.
        os.close(self.broker.fd);self.broker.fd=None
        repeated=a.Broker(self.selection,str(self.attempt),self.controls,'completed-repeat','c'*32)
        try:
            self.assertEqual(repeated.base_directory,self.attempt)
            self.assertEqual(repeated.directory,self.attempt/'observations'/('c'*32))
            with self.assertRaisesRegex(a.Refused,'cannot dispatch'):repeated.call('arm-copy')
            with self.assertRaisesRegex(a.Refused,'cannot dispatch'):repeated.call('effects','fixture')
            self.assertEqual(originals,{p.name:p.read_bytes() for p in self.attempt.iterdir() if p.is_file()})
        finally:os.close(repeated.fd);os.close(repeated.observation_fd)
        with self.assertRaises(FileExistsError):a.Broker(self.selection,str(self.attempt),self.controls,'completed-repeat','c'*32)

    def test_combined_owner_loss_does_not_release_unknown_selection(self):
        self.start();self.guardian.kill();self.guardian.wait();os.close(self.broker.fd);self.broker.fd=None
        self.assertTrue(__import__('select').select([self.server_pidfd],[],[],3)[0])
        self.assertEqual(self.output.read_text(),'actual fixture write')
        with self.assertRaisesRegex(a.Refused,'never adopted'):a.Broker(self.selection,str(self.attempt),self.controls)
        other=self.op/'other';other.mkdir(mode=0o700)
        changed={**self.selection,'phase':{**self.selection['phase'],'attempt':'other'}}
        with self.assertRaisesRegex(a.Refused,'attempt/phase graph'):a.Broker(changed,str(other),self.controls)
        self.assertFalse((self.attempt/'copy-server-return.json').exists())
        self.assertEqual(a.decode(a.private_read(self.op/'state.json')),{'state':'INFLIGHT','phase':'code'})


if __name__=='__main__':unittest.main()

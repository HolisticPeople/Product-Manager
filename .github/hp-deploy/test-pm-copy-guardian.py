#!/usr/bin/env python3
"""Actual local FD transfer/process lifetime tests; no rsync, SSH, WP or target."""
import array
import fcntl
import importlib.util
import json
import os
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('guardian',HERE/'pm-copy-guardian.py')
g=importlib.util.module_from_spec(spec);spec.loader.exec_module(g)


class GuardianTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.path=Path(self.temp.name).resolve();self.path.chmod(0o700)
        self.lock=self.path/'phase';self.lock.write_bytes(b'');self.lock.chmod(0o600)
        self.fd=os.open(self.lock,os.O_RDWR);fcntl.flock(self.fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
        row=os.fstat(self.fd);self.config={'phaseFile':str(self.lock),'phaseDevice':row.st_dev,'phaseInode':row.st_ino}

    def tearDown(self):
        os.close(self.fd);self.temp.cleanup()

    def blocked(self):
        result=subprocess.run([sys.executable,'-c','import os,fcntl,sys; f=os.open(sys.argv[1],os.O_RDWR); fcntl.flock(f,fcntl.LOCK_EX|fcntl.LOCK_NB)',str(self.lock)],capture_output=True)
        return result.returncode!=0

    def test_real_scm_rights_same_ofd_and_original_parent_lock(self):
        left,right=socket.socketpair()
        try:
            left.sendmsg([b'owned-selected-phase-v1'],[(socket.SOL_SOCKET,socket.SCM_RIGHTS,array.array('i',[self.fd]))])
            fd=g.receive_phase(right,self.config)
            try:
                self.assertTrue(self.blocked())
            finally:os.close(fd)
            self.assertTrue(self.blocked())
        finally:left.close();right.close()

    def test_replaced_name_refuses_borrowed_fd(self):
        left,right=socket.socketpair()
        try:
            left.sendmsg([b'owned-selected-phase-v1'],[(socket.SOL_SOCKET,socket.SCM_RIGHTS,array.array('i',[self.fd]))])
            self.lock.rename(self.path/'old-phase');self.lock.write_bytes(b'');self.lock.chmod(0o600)
            with self.assertRaisesRegex(RuntimeError,'identity differs'):g.receive_phase(right,self.config)
        finally:left.close();right.close()

    def test_real_supervision_lifetime_and_literal_nonzero(self):
        original=subprocess.Popen
        def spawn(argv,**kwargs):
            self.assertTrue((self.path/'copy-server-intent.json').is_file())
            return original(argv,**kwargs)
        with patch.object(g.subprocess,'Popen',spawn):
            code=g.supervise([sys.executable,'-c','import sys; sys.exit(9)'],self.fd,self.path)
        self.assertEqual(code,9)
        self.assertEqual(g.decode(g.read(self.path/'copy-server-return.json'))['returncode'],9)
        self.assertTrue(self.blocked())

    def test_timeout_terminates_only_created_session_keeps_parent_lock(self):
        with self.assertRaises(subprocess.TimeoutExpired):
            g.supervise([sys.executable,'-c','import time; time.sleep(5)'],self.fd,self.path,timeout=.05)
        error=g.decode(g.read(self.path/'copy-server-error.json'))
        self.assertEqual(error['class'],'TimeoutExpired');self.assertEqual(error['outcome'],'UNKNOWN')
        cancelled=g.decode(g.read(self.path/'copy-server-cancelled.json'))
        self.assertLess(cancelled['returncode'],0)
        self.assertTrue(self.blocked())

    def test_durability_fault_prevents_server_spawn(self):
        with patch.object(g.os,'fsync',side_effect=OSError('durability fault')),patch.object(g.subprocess,'Popen') as spawn:
            with self.assertRaises(OSError):g.supervise(['never'],self.fd,self.path)
            spawn.assert_not_called()


if __name__=='__main__':unittest.main()

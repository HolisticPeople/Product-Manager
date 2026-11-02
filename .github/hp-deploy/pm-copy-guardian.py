#!/usr/bin/env python3
"""Selected rsync server parent; borrows the broker's SAME open-file description.

Private file/config + AF_UNIX peer authentication only. No token in process argv.
The guardian retains the phase FD until its own rsync child is confirmed exited,
even when the other SSH/broker connection disappears. No retry or phase cleanup.
"""
import array
import fcntl
import hashlib
import json
import os
from pathlib import Path
import signal
import socket
import stat
import subprocess
import sys
import traceback

MAX = 4 * 1024 * 1024


def need(ok, message):
    if not ok:
        raise RuntimeError(message)


def encoded(value):
    return json.dumps(value, sort_keys=True, separators=(',',':'), allow_nan=False).encode()


def decode(raw):
    def pairs(rows):
        value = {}
        for key,item in rows:
            need(key not in value, 'Duplicate guardian config key')
            value[key]=item
        return value
    return json.loads(raw, object_pairs_hook=pairs)


def read(path):
    path=Path(path); parent=path.parent.lstat()
    need(path.resolve(strict=True)==path and stat.S_ISDIR(parent.st_mode)
         and stat.S_IMODE(parent.st_mode)==0o700 and parent.st_uid==os.geteuid(), 'Private guardian path differs')
    fd=os.open(path,os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd,'rb') as stream:
        before=os.fstat(stream.fileno()); raw=stream.read(MAX+1); after=os.fstat(stream.fileno())
    named=path.lstat()
    need(stat.S_ISREG(before.st_mode) and before.st_uid==os.geteuid() and before.st_nlink==1
         and stat.S_IMODE(before.st_mode)==0o600 and len(raw)<=MAX
         and (before.st_dev,before.st_ino,before.st_size,before.st_mtime_ns,before.st_ctime_ns)
         == (after.st_dev,after.st_ino,after.st_size,after.st_mtime_ns,after.st_ctime_ns)
         and (named.st_dev,named.st_ino)==(before.st_dev,before.st_ino), 'Private guardian config changed')
    return raw


def record(directory,name,value):
    raw=encoded(value); path=directory/name
    fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    with os.fdopen(fd,'wb') as stream:
        stream.write(raw); stream.flush(); os.fsync(stream.fileno())
    fd=os.open(directory,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
    try: os.fsync(fd)
    finally: os.close(fd)
    need(read(path)==raw, 'Guardian durable record differs')


def receive_phase(channel, config):
    fds=array.array('i')
    message, ancillary, flags, address=channel.recvmsg(4096,socket.CMSG_SPACE(fds.itemsize))
    need(not flags and message==b'owned-selected-phase-v1', 'Unknown guardian FD message')
    for level,kind,data in ancillary:
        if level==socket.SOL_SOCKET and kind==socket.SCM_RIGHTS:
            fds.frombytes(data[:len(data)-(len(data)%fds.itemsize)])
    need(len(fds)==1, 'Exactly one actual borrowed phase FD required')
    fd=fds[0]
    try:
        row=os.fstat(fd); named=Path(config['phaseFile']).lstat()
        need(stat.S_ISREG(row.st_mode) and row.st_nlink==1 and row.st_uid==os.geteuid()
             and stat.S_IMODE(row.st_mode)==0o600
             and (row.st_dev,row.st_ino)==(config['phaseDevice'],config['phaseInode'])
             and (named.st_dev,named.st_ino)==(row.st_dev,row.st_ino), 'Actual borrowed phase identity differs')
        fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
        os.set_inheritable(fd,False)
        return fd
    except BaseException:
        os.close(fd)
        raise


def supervise(argv, fd, directory, timeout=600):
    """Only this created child's session can be cancelled; never LOCK_UN."""
    process=None
    record(directory,'copy-server-intent.json',{'guardianPID':os.getpid(),'argv':argv,'outcome':'UNKNOWN','automaticRetry':False})
    try:
        process=subprocess.Popen(argv,close_fds=True,start_new_session=True)
        record(directory,'copy-server-process.json',{'guardianPID':os.getpid(),'childPID':process.pid,'sessionPID':process.pid})
        try:
            code=process.wait(timeout=timeout)
        except BaseException as error:
            record(directory,'copy-server-error.json',{'class':type(error).__name__,'message':str(error),'trace':traceback.format_exc(),'outcome':'UNKNOWN'})
            try: os.killpg(process.pid,signal.SIGKILL)
            except ProcessLookupError: pass
            # Retain the actual borrowed FD until this exact child is confirmed
            # gone. A disconnected client never authorizes closing an active FD.
            code=process.wait()
            record(directory,'copy-server-cancelled.json',{'returncode':code,'outcome':'UNKNOWN','automaticRetry':False})
            raise
        record(directory,'copy-server-return.json',{'returncode':code,'guardianPID':os.getpid(),'childPID':process.pid})
        return code
    finally:
        if process is not None and process.poll() is None:
            process.wait()


def main():
    need(len(sys.argv)>=4, 'Exact private config/hash and native rsync server args required')
    path=Path(sys.argv[1]); raw=read(path)
    need(hashlib.sha256(raw).hexdigest()==sys.argv[2], 'Guardian config pin differs')
    config=decode(raw)
    need(set(config)=={'operation','attempt','socket','phaseFile','phaseDevice','phaseInode','serverArgs','rsyncPath','rsyncSHA256','guardianSHA256'}, 'Closed guardian config required')
    need(hashlib.sha256(Path(__file__).read_bytes()).hexdigest()==config['guardianSHA256'], 'Guardian source changed')
    need(sys.argv[3:]==config['serverArgs'] and config['serverArgs'][0]=='--server', 'Exact selected native rsync server args differ')
    need(Path(config['rsyncPath']).resolve(strict=True)==Path(config['rsyncPath'])
         and hashlib.sha256(Path(config['rsyncPath']).read_bytes()).hexdigest()==config['rsyncSHA256'], 'Actual rsync server source differs')
    # Survive this SSH transport's HUP/TERM. This task-owned session/child has an
    # explicit bounded timeout; no unrelated process is signalled.
    if os.getsid(0) != os.getpid():
        os.setsid()
    signal.signal(signal.SIGHUP,signal.SIG_IGN)
    signal.signal(signal.SIGTERM,signal.SIG_IGN)
    channel=socket.socket(socket.AF_UNIX,socket.SOCK_STREAM); channel.settimeout(30)
    channel.connect(config['socket'])
    channel.sendall(encoded({'operation':config['operation'],'attempt':config['attempt'],'configSHA256':sys.argv[2],'pid':os.getpid()})+b'\n')
    fd=receive_phase(channel,config)
    record(path.parent,'copy-fd-ack.json',{'guardianPID':os.getpid(),'phaseDevice':os.fstat(fd).st_dev,'phaseInode':os.fstat(fd).st_ino})
    channel.sendall(b'owner-description-retained-v1\n'); channel.close()
    try:
        code=supervise([config['rsyncPath'],*config['serverArgs']],fd,path.parent)
    finally:
        os.close(fd)  # All own children confirmed exited; never LOCK_UN.
    raise SystemExit(code)


if __name__=='__main__': main()

"""Reviewed generic process helpers extracted byte-for-byte from PM5409.

Source: Product-Manager/.github/hp-deploy/pm-actual-adapter.py
SHA256 87b5bd725a4767e129e0c2d68311f7b44427e4b48e60379e869d86f4c1e2bd93.
No PM broker, fixed target, policy, bootstrap or selected-operation admission is
imported. Function-body provenance is in selected-source-dependencies.json.
"""
import base64
import json
import os
from pathlib import Path
import re
import signal
import stat
import subprocess
import traceback

MAX = 4 * 1024 * 1024

class Refused(RuntimeError):
    pass


def need(value, message):
    if not value:
        raise Refused(message)


def encoded(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False).encode()


def private_dir(path):
    path = Path(path)
    row = path.lstat()
    need(path.is_absolute() and path.resolve(strict=True) == path and stat.S_ISDIR(row.st_mode)
         and stat.S_IMODE(row.st_mode) == 0o700 and row.st_uid == os.geteuid(),
         'Canonical owned0700 private directory required')
    return path


def private_read(path):
    path = Path(path)
    private_dir(path.parent)
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'rb') as stream:
        before = os.fstat(stream.fileno())
        need(stat.S_ISREG(before.st_mode) and before.st_nlink == 1 and before.st_uid == os.geteuid()
             and stat.S_IMODE(before.st_mode) == 0o600 and before.st_size <= MAX,
             'Private bounded owned0600 single-link file required')
        raw = stream.read(MAX + 1)
        after = os.fstat(stream.fileno())
        named = path.lstat()
        fields = ('st_dev', 'st_ino', 'st_mode', 'st_uid', 'st_gid', 'st_nlink',
                  'st_size', 'st_mtime_ns', 'st_ctime_ns')
        need(len(raw) <= MAX and all(getattr(before, k) == getattr(after, k) for k in fields)
             and (named.st_dev, named.st_ino) == (before.st_dev, before.st_ino), 'Private file changed')
        return raw


def durable(path, raw):
    path = Path(path)
    private_dir(path.parent)
    need(isinstance(raw, bytes) and len(raw) <= MAX, 'Bounded raw bytes required')
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'wb') as stream:
        stream.write(raw)
        stream.flush()
        os.fsync(stream.fileno())
    directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(directory)
    finally:
        os.close(directory)
    need(private_read(path) == raw, 'Private durable readback differs')


def error_value(error):
    def safe(value):
        return {'type':'bytes','base64':base64.b64encode(value).decode()} if isinstance(value,bytes) else value
    return {'class':type(error).__name__,'message':str(error),'trace':traceback.format_exc(),
            'errno':getattr(error,'errno',None),'filename':safe(getattr(error,'filename',None)),
            'filename2':safe(getattr(error,'filename2',None)),'outcome':'UNKNOWN'}


def raw_process(directory, name, argv, *, data=None, pass_fds=(), env=None, cwd=None, timeout=180):
    """Spool complete actual streams privately from spawn; parse only after fsync.

    Original errors/partial streams are recorded BEFORE cancellation/cleanup, so
    a second cleanup fault cannot replace the first observed command failure.
    """
    directory = private_dir(directory)
    need(re.fullmatch('[a-z0-9.-]+', name) is not None, 'Private process basename required')
    durable(directory / (name + '.intent.json'), encoded({'argv':argv,'outcome':'UNKNOWN','automaticRetry':False}))
    streams = {}
    process = None
    try:
        for suffix in ('stdout','stderr'):
            fd = os.open(directory / (name + '.' + suffix),os.O_RDWR|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
            streams[suffix] = os.fdopen(fd,'w+b')
        fd = os.open(directory,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
        try: os.fsync(fd)
        finally: os.close(fd)
        try:
            process = subprocess.Popen(argv,cwd=cwd,env=env,pass_fds=pass_fds,start_new_session=True,
                                       stdin=subprocess.PIPE if data is not None else subprocess.DEVNULL,
                                       stdout=streams['stdout'],stderr=streams['stderr'])
            process.communicate(data,timeout=timeout)
        except BaseException as original:
            durable(directory / (name + '.error.json'),encoded(error_value(original)))
            for suffix in ('stdout','stderr'):
                value = getattr(original,suffix,None)
                # These are the original exception's partial bytes, distinct
                # from the actual complete files written directly by the child.
                if value is not None:
                    value = value.encode() if isinstance(value,str) else value
                    fd = os.open(directory / (name + '.exception.' + suffix),os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
                    with os.fdopen(fd,'wb') as extra:
                        extra.write(value); extra.flush(); os.fsync(extra.fileno())
            if process is not None:
                try:
                    if process.poll() is None:
                        try: os.killpg(process.pid,signal.SIGKILL)
                        except ProcessLookupError: pass
                    process.wait(timeout=20)
                except BaseException as cleanup:
                    durable(directory / (name + '.cleanup.error.json'),encoded(error_value(cleanup)))
                durable(directory / (name + '.cleanup.return.json'),encoded({'returncode':process.poll(),'outcome':'UNKNOWN'}))
            raise
        finally:
            for stream in streams.values():
                stream.flush(); os.fsync(stream.fileno())
        durable(directory / (name + '.return.json'),encoded({'returncode':process.returncode}))
        # Oversize is a parser refusal only. BOTH original files, including all
        # bytes beyond MAX, and the literal process exit remain durably retained.
        need(all(os.fstat(stream.fileno()).st_size <= MAX for stream in streams.values()),
             'Actual output exceeds parser bound; complete raw files retained, outcome requires review')
        output = {}
        for suffix,stream in streams.items():
            stream.seek(0); output[suffix] = stream.read(MAX+1)
        return subprocess.CompletedProcess(argv,process.returncode,output['stdout'],output['stderr'])
    finally:
        for stream in streams.values(): stream.close()


def success(result, label):
    need(type(result.returncode) is int and result.returncode == 0 and not result.stderr,
         label + ' failed; retained original outcome, no automatic retry')
    return result.stdout

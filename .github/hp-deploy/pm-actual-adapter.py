#!/usr/bin/env python3
"""Concrete excluded PM adapter. Import is pure; execution requires exact selection.

One persistent SSH broker owns the actual phase FD for native reads, legacy WP
primitives, and the separate unchanged rsync transport. No retry or repair path.
This source does not authorize its own execution or qualify a target.
"""
import base64
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import shlex
import stat
import subprocess
import sys
import tempfile
import time
import select
import signal
import socket
import struct
import array
import threading
import traceback

MAX = 4 * 1024 * 1024
ROOT = '/www/holisticpeoplecom_349/public'
EFFECTS = ['already-active-activation', 'active-readback', 'object-cache-flush',
           'kinsta-page-cache-purge', 'version-readback']


class Refused(RuntimeError):
    pass


def need(value, message):
    if not value:
        raise Refused(message)


def encoded(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False).encode()


def decode(raw):
    def pairs(rows):
        value = {}
        for key, item in rows:
            need(key not in value, 'Duplicate JSON key')
            value[key] = item
        return value
    return json.loads(raw, object_pairs_hook=pairs)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


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


def observed_map(directory):
    """Actual complete regular-file image; refuses foreign links/special files."""
    directory = Path(directory)
    need(directory.resolve(strict=True) == directory and directory.is_dir(), 'Canonical existing payload required')
    result = {}
    for path in sorted(directory.rglob('*')):
        row = path.lstat()
        need(not stat.S_ISLNK(row.st_mode), 'Symlink in actual payload')
        if stat.S_ISDIR(row.st_mode):
            continue
        need(stat.S_ISREG(row.st_mode) and row.st_nlink == 1 and stat.S_IMODE(row.st_mode) in (0o644, 0o755)
             and row.st_size <= MAX, 'Unqualified actual payload entry')
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
        with os.fdopen(fd, 'rb') as stream:
            before = os.fstat(stream.fileno()); raw = stream.read(MAX + 1); after = os.fstat(stream.fileno())
        fields = ('st_dev', 'st_ino', 'st_mode', 'st_uid', 'st_gid', 'st_nlink', 'st_size', 'st_mtime_ns', 'st_ctime_ns')
        named = path.lstat()
        need(len(raw) <= MAX and all(getattr(before, k) == getattr(after, k) for k in fields)
             and (named.st_dev, named.st_ino) == (before.st_dev, before.st_ino), 'Payload changed during read')
        result[path.relative_to(directory).as_posix()] = {
            'mode': stat.S_IMODE(before.st_mode), 'type': 'blob', 'bytes': len(raw), 'sha256': sha(raw),
            'gitBlobSHA1': hashlib.sha1(b'blob ' + str(len(raw)).encode() + b'\0' + raw).hexdigest()}
    need(0 < len(result) <= 100, 'Bounded complete actual PM payload required')
    return result


def source_pins(rows):
    need(isinstance(rows, dict) and 1 <= len(rows) <= 200, 'Finite critical source pins required')
    for name, expected in rows.items():
        path = Path(name)
        need(path.is_absolute() and path.resolve(strict=True) == path and not path.is_symlink()
             and isinstance(expected, dict) and set(expected) == {'sha256','bytes','mode'}, 'Closed canonical source pin required')
        row = path.lstat()
        need(stat.S_ISREG(row.st_mode) and row.st_nlink == 1 and row.st_size <= 64 * 1024 * 1024
             and stat.S_IMODE(row.st_mode) == expected['mode'], 'Critical source type/mode differs')
        fd = os.open(path,os.O_RDONLY|os.O_NOFOLLOW)
        with os.fdopen(fd,'rb') as stream:
            opened = os.fstat(stream.fileno()); raw = stream.read(64*1024*1024+1); after = os.fstat(stream.fileno())
        named = path.lstat()
        fields = ('st_dev','st_ino','st_mode','st_uid','st_gid','st_nlink','st_size','st_mtime_ns','st_ctime_ns')
        need(all(getattr(row,k) == getattr(opened,k) == getattr(after,k) for k in fields)
             and (named.st_dev,named.st_ino) == (row.st_dev,row.st_ino)
             and len(raw) == expected['bytes'] and sha(raw) == expected['sha256'], 'Critical source bytes changed')


def phase_resource(path):
    need(path == ROOT + '/wp-content/hp-deploy/selected-phase.lock', 'Finite physical phase path required')
    path = Path(path)
    need(path.resolve(strict=True) == path and not path.is_symlink(), 'Canonical existing phase file required; no install')
    fd = os.open(path, os.O_RDWR | os.O_NOFOLLOW)
    try:
        row = os.fstat(fd); named = path.lstat()
        need(stat.S_ISREG(row.st_mode) and stat.S_IMODE(row.st_mode) == 0o600
             and row.st_uid == os.geteuid() and row.st_nlink == 1
             and (row.st_dev,row.st_ino) == (named.st_dev,named.st_ino), 'Phase identity/owner/mode differs')
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        return fd
    except BaseException:
        os.close(fd)
        raise


class Broker:
    """Runs only inside the selected target's persistent SSH process."""
    def __init__(self, selection, directory, controls, mode='transition', observation_id=None):
        self.selection = selection
        need(mode in ('transition','completed-repeat'), 'Closed broker mode required')
        self.mode = mode
        self.base_directory = private_dir(directory)
        if mode == 'transition':
            need(observation_id is None and not list(self.base_directory.iterdir()), 'Existing/unknown target attempt is never adopted')
            self.directory = self.base_directory
        else:
            need(isinstance(observation_id,str) and re.fullmatch('[a-f0-9]{32}',observation_id), 'Fresh read-only observation identity required')
            reservation = decode(private_read(self.base_directory / 'broker-reservation.json'))
            need(reservation == {'operation':selection['operationId'],'attempt':selection['phase']['attempt'],
                'phase':selection['phase']['phase'],'selectedDigest':selection['phase']['selectedDigest'],
                'state':'UNKNOWN_BEFORE_FIRST_NATIVE','automaticRetry':False}, 'Original immutable target reservation differs')
            parent = private_dir(self.base_directory / 'observations')
            self.directory = parent / observation_id
            self.directory.mkdir(mode=0o700)  # Exclusive fresh read evidence, never a new mutation attempt.
            fd = os.open(parent,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
            try: os.fsync(fd)
            finally: os.close(fd)
        self.token_raw = None
        need(not (Path(ROOT) == self.directory or Path(ROOT) in self.directory.parents), 'Outside-public target journal required')
        self.count = 0
        self.controls = controls
        self.fd = phase_resource(selection['nativeRequest']['phaseFile'])
        self.phase_identity = os.fstat(self.fd)
        # Read-only native children never receive the owner open-file description.
        self.observation_fd = os.open(selection['nativeRequest']['phaseFile'], os.O_RDWR | os.O_NOFOLLOW)
        observed = os.fstat(self.observation_fd)
        need((observed.st_dev,observed.st_ino) == (self.phase_identity.st_dev,self.phase_identity.st_ino), 'Separate phase observation inode differs')
        try:
            self.check()
            if mode == 'transition':
                durable(self.directory / 'broker-reservation.json',encoded({'operation':selection['operationId'],
                    'attempt':selection['phase']['attempt'],'phase':selection['phase']['phase'],
                    'selectedDigest':selection['phase']['selectedDigest'],'state':'UNKNOWN_BEFORE_FIRST_NATIVE','automaticRetry':False}))
                (self.directory / 'observations').mkdir(mode=0o700)
                fd = os.open(self.directory,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
                try: os.fsync(fd)
                finally: os.close(fd)
        except BaseException:
            os.close(self.observation_fd); os.close(self.fd)
            raise

    def check(self):
        required = {ROOT + '/wp-includes/option.php', ROOT + '/wp-includes/cron.php',
                    ROOT + '/wp-load.php', ROOT + '/wp-settings.php', self.selection['wpCli']['path'], self.selection['python']['path']}
        need(required <= set(self.selection['sourcePins']), 'Finite actual WordPress/CLI source closure missing')
        source_pins(self.selection['sourcePins'])
        need(Path(sys.executable).resolve(strict=True) == Path(self.selection['python']['path'])
             and self.selection['sourcePins'][self.selection['python']['path']]['sha256'] == self.selection['python']['sha256'], 'Actual broker interpreter source differs')
        row = os.fstat(self.fd)
        named = Path(self.selection['nativeRequest']['phaseFile']).lstat()
        need((row.st_dev,row.st_ino,row.st_mode,row.st_uid,row.st_nlink)
             == (self.phase_identity.st_dev,self.phase_identity.st_ino,self.phase_identity.st_mode,self.phase_identity.st_uid,1)
             and (named.st_dev,named.st_ino) == (row.st_dev,row.st_ino), 'Actual held phase changed')
        # The durable cross-handler phase protocol is independently source-pinned.
        # A physical flock alone cannot prove adoption by MU/other entrypoints.
        owner_path = Path(ROOT) / 'wp-content/hp-deploy/selected-phase-owner.json'
        fd = os.open(owner_path, os.O_RDONLY | os.O_NOFOLLOW)
        with os.fdopen(fd, 'rb') as stream:
            before = os.fstat(stream.fileno()); owner_raw = stream.read(MAX + 1); after = os.fstat(stream.fileno())
        named = owner_path.lstat()
        need(stat.S_ISREG(before.st_mode) and before.st_uid == os.geteuid() and before.st_nlink == 1
             and stat.S_IMODE(before.st_mode) == 0o600 and len(owner_raw) <= MAX
             and (before.st_dev,before.st_ino,before.st_size,before.st_mtime_ns,before.st_ctime_ns)
             == (after.st_dev,after.st_ino,after.st_size,after.st_mtime_ns,after.st_ctime_ns)
             and (named.st_dev,named.st_ino) == (before.st_dev,before.st_ino), 'Opaque phase owner changed/unsafe')
        owner = decode(owner_raw)
        need(set(owner) == {'operation','slug','privateRoot','selectedDigest'}
             and owner['operation'] == self.selection['operationId'] and owner['slug'] == 'products-manager',
             'Unknown/foreign active phase owner')
        private_root = private_dir(owner['privateRoot'])
        need(not (private_root == Path(ROOT) or Path(ROOT) in private_root.parents), 'Phase private base is under public')
        operation = private_dir(private_root / owner['operation'])
        selected_raw = private_read(operation / 'selected.json')
        need(sha(selected_raw) == owner['selectedDigest'] == self.selection['phase']['selectedDigest'], 'Exact selected phase bytes changed')
        selected = decode(selected_raw)
        descriptor = selected.get('nativeReader')
        need(isinstance(descriptor,dict) and set(descriptor) == {'file','sha256'}
             and descriptor['sha256'] == self.selection['controlsSHA256']['pm-native-before-reader.php']
             and descriptor['file'] == str(private_root / 'readers' / (descriptor['sha256'] + '.php')),
             'Selected native reader is not bound to trusted immutable caller release')
        need(private_read(descriptor['file']) == self.controls['pm-native-before-reader.php'], 'Selected reader source differs from immutable release')
        need(selected.get('nativeBefore') == self.selection['expectedNativeBefore'], 'Selected phase native-before context differs')
        need(selected.get('operation') == owner['operation'] and selected.get('slug') == owner['slug'], 'Phase selection ownership differs')
        token_raw = private_read(operation / 'token.json')
        token = decode(token_raw)
        need(set(token) == {'hash','expires'} and isinstance(token['hash'], str)
             and re.fullmatch('[a-f0-9]{64}',token['hash']) and type(token['expires']) is int
             and token['expires'] > time.time() and isinstance(self.selection['token'], str)
             and re.fullmatch('[a-f0-9]{64}',self.selection['token'])
             and sha(self.selection['token'].encode()) == token['hash'], 'Actual private phase token authentication failed/expired')
        if self.token_raw is None:
            self.token_raw = token_raw
        need(token_raw == self.token_raw, 'Immutable selected token rewritten; no escape from UNKNOWN')
        need(selected.get('attempt') == self.selection['phase']['attempt']
             and isinstance(selected.get('phases'),list) and self.selection['phase']['phase'] in selected['phases'],
             'Immutable selected attempt/phase graph differs or unavailable')
        state = decode(private_read(operation / 'state.json'))
        need(set(state) == {'state','phase'} and state['state'] in (['INFLIGHT'] if self.mode == 'transition' else ['INFLIGHT','PHASE_VERIFIED'])
             and state['phase'] == self.selection['phase']['phase'], 'Selected durable phase unknown/not INFLIGHT')
        need(operation == self.base_directory.parent and self.base_directory.name == self.selection['phase']['attempt'],
             'Exact selected fresh attempt directory differs')

    def wp(self, root, *arguments):
        self.check()
        need(str(root) == ROOT, 'Legacy primitive selected a different root')
        self.count += 1
        directory = self.directory / ('wp-' + str(self.count))
        directory.mkdir(mode=0o700)
        parent = os.open(self.directory, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(parent)
        finally:
            os.close(parent)
        for name in ('pm-native-before-reader.php', 'pm-preinit-guard.php'):
            durable(directory / name, self.controls[name])
        config = {'schema': 'hp_pm_preinit_config_v1', 'guardSHA256': sha(self.controls['pm-preinit-guard.php']),
                  'readerFile': str(directory / 'pm-native-before-reader.php'),
                  'readerSHA256': sha(self.controls['pm-native-before-reader.php']), 'request': self.selection['nativeRequest']}
        raw = encoded(config)
        durable(directory / 'config.json', raw)
        env = os.environ.copy()
        env.update(PM_NATIVE_CONFIG=str(directory / 'config.json'), PM_NATIVE_CONFIG_SHA256=sha(raw), PM_NATIVE_PHASE_FD=str(self.observation_fd), PM_NATIVE_OWNER_PID=str(os.getpid()))
        cli = self.selection['wpCli']
        need(isinstance(cli,dict) and set(cli) == {'path','sha256'} and Path(cli['path']).is_absolute(), 'Closed actual CLI source identity required')
        need(sha(Path(cli['path']).read_bytes()) == cli['sha256'], 'Actual WPCLI launcher source changed')
        argv = [cli['path'], '--path=' + ROOT, '--require=' + str(directory / 'pm-preinit-guard.php'),
                '--no-color', *arguments]
        result = raw_process(directory, 'actual', argv, pass_fds=(self.observation_fd,), env=env, timeout=180)
        output = success(result, 'Actual guarded WP primitive')
        private_read(directory / 'preinit-readback.json')
        self.check()
        return output.decode().strip()

    def native(self):
        # Real eval-file body, normal plugin bootstrap and exact native WP actor.
        directory = self.directory
        path = directory / 'native-observer.php'
        body = b'<?php echo json_encode(HP_PM_Actual_Preinit_Guard::read_code_before(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);\n'
        if not path.exists():
            durable(path, body)
        need(private_read(path) == body, 'Native observer source changed')
        return decode(self.wp(ROOT, '--user=7474', 'eval-file', str(path)))

    def legacy(self, mode, version):
        namespace = {'__name__': 'pm_existing_remote_guard'}
        exec(compile(self.controls['remote-guard.py'], 'pinned-remote-guard.py', 'exec'), namespace)
        # Keep original guard/perform ordering and WP flags. The adapter adds
        # unchanged-value guards and raw durable subprocess capture around wp().
        namespace['wp'] = lambda root, *args: self.wp(root, '--skip-plugins', '--skip-themes', *args)
        return namespace['perform'](mode, ROOT + '/wp-content/plugins', 'https://holisticpeople.com', 'production', version)

    def arm_copy(self):
        need(not hasattr(self, 'copy_channel'), 'Copy already armed; no repeated dispatch')
        transport = self.selection['rsync']
        need(set(transport) == {'path','sha256','serverArgs'} and isinstance(transport['serverArgs'], list)
             and transport['serverArgs'] and transport['serverArgs'][0] == '--server'
             and transport['serverArgs'][-1] == ROOT + '/wp-content/plugins/products-manager/'
             and all(isinstance(v,str) for v in transport['serverArgs']), 'Exact qualified native rsync server argv required')
        need(transport['path'] in self.selection['sourcePins']
             and self.selection['sourcePins'][transport['path']]['sha256'] == transport['sha256'], 'Actual rsync executable source pin missing')
        need(hasattr(os,'pidfd_open'), 'Actual kernel process identity descriptor unavailable')
        need(hasattr(socket,'SO_PEERCRED'), 'Actual Linux local peer credential API unavailable')
        path = self.directory / 'copy.sock'
        need(len(str(path).encode()) < 104, 'Canonical selected private socket path too long; no fallback')
        self.copy_channel = socket.socket(socket.AF_UNIX,socket.SOCK_STREAM)
        self.copy_channel.bind(str(path)); os.chmod(path,0o600); self.copy_channel.listen(1); self.copy_channel.settimeout(60)
        config = {'operation':self.selection['operationId'],'attempt':self.selection['phase']['attempt'],
                  'socket':str(path),'phaseFile':self.selection['nativeRequest']['phaseFile'],
                  'phaseDevice':self.phase_identity.st_dev,'phaseInode':self.phase_identity.st_ino,
                  'serverArgs':transport['serverArgs'],'rsyncPath':transport['path'],'rsyncSHA256':transport['sha256'],
                  'guardianSHA256':sha(self.controls['pm-copy-guardian.py'])}
        raw = encoded(config); config_hash = sha(raw)
        durable(self.directory / 'copy.json',raw)
        durable(self.directory / 'pm-copy-guardian.py',self.controls['pm-copy-guardian.py'])
        self.copy_error = None
        def handoff():
            try:
                connection,_ = self.copy_channel.accept()
                with connection:
                    connection.settimeout(30)
                    pid,uid,gid = struct.unpack('3i',connection.getsockopt(socket.SOL_SOCKET,socket.SO_PEERCRED,struct.calcsize('3i')))
                    message = bytearray()
                    while not message.endswith(b'\n'):
                        part = connection.recv(4096)
                        need(part and len(message)+len(part)<=4096, 'Bounded authenticated copy handoff required')
                        message.extend(part)
                    request = decode(bytes(message))
                    need(uid == os.geteuid() and request == {'operation':self.selection['operationId'],
                        'attempt':self.selection['phase']['attempt'],'configSHA256':config_hash,'pid':pid}, 'Actual copy peer/attempt authentication failed')
                    self.check()
                    proc = Path('/proc') / str(pid)
                    actual_exe = Path(os.readlink(proc / 'exe')).resolve(strict=True)
                    need(actual_exe == Path(self.selection['python']['path']), 'Actual guardian peer executable differs')
                    expected_argv = [self.selection['python']['path'],str(self.directory / 'pm-copy-guardian.py'),
                                     str(self.directory / 'copy.json'),config_hash,*transport['serverArgs']]
                    commandline = (proc / 'cmdline').read_bytes().split(b'\0')
                    need(commandline == [v.encode() for v in expected_argv] + [b''], 'Actual guardian peer command/source/attempt differs')
                    start = (proc / 'stat').read_text().rsplit(')',1)[1].split()[19]
                    self.guardian_pidfd = os.pidfd_open(pid,0)
                    need(not select.select([self.guardian_pidfd],[],[],0)[0]
                         and (proc / 'stat').read_text().rsplit(')',1)[1].split()[19] == start,
                         'Actual guardian process ended/reused before authenticated handoff')
                    durable(self.directory / 'copy-fd-transfer-intent.json',encoded({'peerPID':pid,'peerUID':uid,
                        'peerStartTime':start,'actualExecutable':str(actual_exe),'actualArgvSHA256':sha(encoded(expected_argv)),
                        'phaseDevice':self.phase_identity.st_dev,'phaseInode':self.phase_identity.st_ino,
                        'configSHA256':config_hash,'outcome':'UNKNOWN'}))
                    connection.sendmsg([b'owned-selected-phase-v1'],[(socket.SOL_SOCKET,socket.SCM_RIGHTS,array.array('i',[self.fd]))])
                    need(connection.recv(128) == b'owner-description-retained-v1\n', 'Guardian actual FD acknowledgement missing')
                    durable(self.directory / 'copy-fd-handoff.json',encoded({'peerPID':pid,'peerUID':uid,
                        'peerStartTime':start,'configSHA256':config_hash,'acknowledged':True}))
            except BaseException as error:
                self.copy_error = error
                durable(self.directory / 'copy-handoff-error.json',encoded({'class':type(error).__name__,'message':str(error),'trace':traceback.format_exc(),'outcome':'UNKNOWN'}))
            finally:
                self.copy_channel.close()
        self.copy_thread = threading.Thread(target=handoff,daemon=True); self.copy_thread.start()
        return {'rsyncPath':shlex.quote(self.selection['python']['path']) + ' ' + shlex.quote(str(self.directory / 'pm-copy-guardian.py')) + ' '
                + shlex.quote(str(self.directory / 'copy.json')) + ' ' + config_hash}

    def copy_result(self):
        need(hasattr(self,'copy_thread'), 'No selected copy attempt')
        self.copy_thread.join(timeout=90)
        need(not self.copy_thread.is_alive() and self.copy_error is None, 'Actual FD handoff incomplete; copy outcome UNKNOWN')
        need(select.select([self.guardian_pidfd],[],[],20)[0], 'Actual guardian process exit not confirmed')
        result = decode(private_read(self.directory / 'copy-server-return.json'))
        ack = decode(private_read(self.directory / 'copy-fd-ack.json'))
        need(ack['guardianPID'] == result['guardianPID']
             and ack['phaseDevice'] == self.phase_identity.st_dev and ack['phaseInode'] == self.phase_identity.st_ino,
             'Actual retained owner description acknowledgement differs')
        handoff = decode(private_read(self.directory / 'copy-fd-handoff.json'))
        need(set(result) == {'returncode','guardianPID','childPID'} and result['returncode'] == 0
             and result['guardianPID'] == handoff['peerPID'] and type(result['childPID']) is int,
             'Actual selected remote rsync process did not confirm successful exit')
        self.check()
        return result

    def call(self, action, version=None):
        need(self.mode != 'completed-repeat' or action in ('native','observe','preflight'),
             'Read-only completed repeat cannot dispatch copy or effects')
        self.check()
        if action == 'arm-copy':
            return self.arm_copy()
        if action == 'copy-result':
            return self.copy_result()
        if action == 'observe':
            return observed_map(Path(ROOT) / 'wp-content/plugins/products-manager')
        if action == 'native':
            return self.native()
        if action in ('preflight','effects'):
            return self.legacy(action, version)
        raise Refused('Unknown bounded broker action')


def broker_main():
    """The source is supplied by the selected immutable local control tree."""
    first = sys.stdin.buffer.readline(MAX + 1)
    need(len(first) <= MAX, 'Bounded broker initialization required')
    message = decode(first)
    controls = {name: base64.b64decode(value, validate=True) for name,value in message['controls'].items()}
    need(set(controls) == {'remote-guard.py','pm-native-before-reader.php','pm-preinit-guard.php','pm-copy-guardian.py'}, 'Exact broker controls required')
    for name, raw in controls.items():
        need(sha(raw) == message['selection']['controlsSHA256'][name], 'Pinned broker control differs')
    broker = Broker(message['selection'], message['directory'], controls, message['mode'], message['observationId'])
    for line in sys.stdin.buffer:
        need(len(line) <= MAX, 'Bounded broker request required')
        request = decode(line)
        if request == {'action':'close'}:
            return
        try:
            result = broker.call(request['action'], request.get('version'))
            response = {'result':result}
        except BaseException as error:
            response = {'error':{'class':type(error).__name__, 'message':str(error), 'trace':traceback.format_exc()}}
        raw = encoded(response)
        durable(broker.directory / ('broker-' + str(broker.count) + '-' + request['name'] + '.json'), raw)
        sys.stdout.buffer.write(raw + b'\n'); sys.stdout.buffer.flush()


class SSHBroker:
    def __init__(self, spec, selection, directory, controls, source, local_store, mode, observation_id):
        self.store = local_store
        target = spec['user'] + '@' + spec['host']
        bootstrap = 'import base64; namespace={"__name__":"pm_actual_broker"}; exec(compile(base64.b64decode(' + repr(base64.b64encode(source).decode()) + '),"pinned-pm-actual-adapter.py","exec"),namespace); namespace["broker_main"]()'
        command = 'python3 -c ' + shlex.quote(bootstrap)
        argv = ['ssh','-o','BatchMode=yes','-o','StrictHostKeyChecking=yes','-o','ConnectTimeout=20',
                '-p',str(spec['port']),target,command]
        self.store.create('broker-start-intent.json', encoded({'argvSHA256':sha(encoded(argv)), 'outcome':'UNKNOWN'}))
        # Capture stderr to a private file from spawn, including initialization failure.
        self.error_path = self.store.path / 'broker.stderr'
        self.error_fd = os.open(self.error_path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        os.fsync(self.error_fd)
        fd = os.open(self.store.path,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
        try: os.fsync(fd)
        finally: os.close(fd)
        self.process = None
        try:
            self.process = subprocess.Popen(argv,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=self.error_fd,start_new_session=True)
            message = {'selection':selection,'directory':directory,'mode':mode,'observationId':observation_id,
                       'controls':{name:base64.b64encode(raw).decode() for name,raw in controls.items()}}
            self.process.stdin.write(encoded(message) + b'\n'); self.process.stdin.flush()
            self.count = 0
        except BaseException as error:
            self.store.create('broker-start.error.json',encoded(error_value(error)))
            if self.process is not None:
                try:
                    self.process.stdin.close()
                    self.process.wait(timeout=20)
                except BaseException as cleanup:
                    self.store.create('broker-start.cleanup.error.json',encoded(error_value(cleanup)))
            os.fsync(self.error_fd); os.close(self.error_fd)
            raise

    def call(self, action, version=None):
        self.count += 1
        name = 'call-' + str(self.count)
        self.store.create(name + '.intent.json', encoded({'action':action,'version':version,'outcome':'UNKNOWN'}))
        self.process.stdin.write(encoded({'action':action,'version':version,'name':name}) + b'\n')
        self.process.stdin.flush()
        deadline = time.monotonic() + 300
        chunks = bytearray()
        fd = self.process.stdout.fileno()
        os.set_blocking(fd, False)
        try:
            while not chunks.endswith(b'\n'):
                remaining = deadline - time.monotonic()
                need(remaining > 0 and select.select([fd], [], [], max(0, remaining))[0],
                     'Broker timed out; outcome UNKNOWN, no retry')
                piece = os.read(fd, min(65536, MAX + 1 - len(chunks)))
                need(piece, 'Broker closed before a complete result; outcome UNKNOWN')
                chunks.extend(piece)
                need(len(chunks) <= MAX and b'\n' not in chunks[:-1], 'Broker returned unbounded/multiple outcomes')
        finally:
            # Retain partial bytes even if timeout/EOF/format validation fails.
            self.store.create(name + '.stdout', bytes(chunks))
        raw = bytes(chunks)
        response = decode(raw)
        need(set(response) == {'result'}, 'Broker refused: ' + str(response.get('error')))
        return response['result']

    def close(self):
        try:
            if self.process.poll() is None:
                self.process.stdin.write(encoded({'action':'close'}) + b'\n'); self.process.stdin.flush()
                self.process.stdin.close()
            self.process.wait(timeout=20)
        finally:
            os.fsync(self.error_fd); os.close(self.error_fd)
        need(self.process.returncode == 0, 'Broker exit failed; retained stderr, no retry')


class ActualPerformer:
    def __init__(self, spec, repo, payload, prior, broker, store, deploy, selection):
        self.spec,self.repo,self.payload,self.prior = spec,repo,payload,prior
        self.broker,self.store,self.deploy,self.selection = broker,store,deploy,selection
        self.before = None

    def admit(self, plan, expected):
        self.before = self.broker.call('native')
        need(self.before.get('disposition') == 'PRE_FIELD_CLEAR' and self.before.get('context') == 'full-native'
             and self.before == self.selection['expectedNativeBefore'], 'Fresh actual native-before differs from reviewed selection')
        entry = expected.get('products-manager.php')
        prior_entry = observed_map(self.prior).get('products-manager.php')
        selected_entry = observed_map(self.payload).get('products-manager.php')
        if entry == prior_entry:
            version = self.deploy.entry_version(self.prior)
        elif entry == selected_entry:
            version = self.deploy.entry_version(self.payload)
        else:
            raise Refused('Actual partial entry has no qualified native/bootstrap version; retain owned partial')
        receipt = self.broker.call('preflight', version)
        need(all(receipt.get(k) is True for k in ('alreadyActive','rootMatched','pluginPathMatched','siteMatched','environmentMatched')),
             'Actual existing-target preflight incomplete')
        need(self.observe(plan) == expected, 'Fresh actual full PM map differs')

    def source(self, plan):
        return {'sha': self.spec['sha'], 'tree': self.spec['tree'], 'files': observed_map(self.payload)}

    def prior_image(self, plan):
        return observed_map(self.prior)

    def observe(self, plan):
        return self.broker.call('observe')

    def copy(self, plan):
        # Recheck native + full bytes immediately before unchanged copy transport.
        need(self.broker.call('native') == self.before and self.observe(plan) == plan['expectedBefore'], 'Native/current state changed before copy')
        armed = self.broker.call('arm-copy')
        need(set(armed) == {'rsyncPath'} and isinstance(armed['rsyncPath'],str), 'Concrete remote rsync guardian missing')
        self.deploy.fresh_selected_head(self.spec, self.repo)
        transport = 'ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=20 -p ' + str(self.spec['port'])
        host = '[' + self.spec['host'] + ']' if ':' in self.spec['host'] else self.spec['host']
        destination = self.spec['user'] + '@' + host + ':' + shlex.quote(self.spec['base'] + '/products-manager/')
        argv = ['rsync','-az','--delete','-e',transport,'--rsync-path=' + armed['rsyncPath'],
                '--exclude-from=' + str(self.payload.parent / 'source/.github/deploy-exclude.txt'),
                str(self.payload) + '/',destination]
        result = raw_process(self.store.path, 'actual-rsync', argv, cwd=self.repo, timeout=600)
        if result.returncode == 0 and not result.stderr:
            self.broker.call('copy-result')
        return result

    def effects(self, plan):
        result = self.broker.call('effects', self.deploy.entry_version(self.payload))
        # Preserve the full unprojected existing receipt, never synthesize the
        # old core's {'effects': ...} success-shaped JSON around a failed result.
        return subprocess.CompletedProcess([], 0, encoded(result), b'')

    def verify_effects_and_continuity(self, plan):
        need(self.broker.call('native') == self.before, 'Native source/policy/jobs/lifecycle changed during code transition')


def transition(core, plan, references, store, performer, parent=None):
    """335 core lifecycle with bounded successor full-metadata effects parser."""
    core.validate(plan, references, parent)
    need(store.path == Path(plan['journalRoot']) / plan['operationId'], 'Selected local journal path differs')
    intent = {'schema':'hp_pm_state_bound_intent_v1','plan':plan,'state':'OUTCOME_UNKNOWN_BEFORE_ADMISSION','automaticRetry':False}
    raw = encoded(intent); store.create('intent.json', raw)
    mutation = False
    try:
        performer.admit(plan, plan['expectedBefore'])
        need(performer.source(plan) == {'sha':plan['releaseSHA'],'tree':plan['releaseTree'],'files':plan['expectedAfter']}, 'Actual release source differs')
        need(performer.prior_image(plan) == references['references']['prior']['files'], 'Complete prior image differs')
        need(performer.observe(plan) == plan['expectedBefore'], 'Current actual image differs')
        store.create('copy-intent.json', encoded({'intentSHA256':sha(raw),'expectedBeforeSHA256':core.digest(plan['expectedBefore']),'outcome':'UNKNOWN'}))
        mutation = True
        core.capture(store, 'copy', lambda: performer.copy(plan))
        effects = decode(core.capture(store, 'effects', lambda: performer.effects(plan)))
        need(effects.get('version') == performer.deploy.entry_version(performer.payload)
             and effects.get('effects') == EFFECTS and all(effects.get(k) is True for k in
             ('alreadyActive','rootMatched','pluginPathMatched','siteMatched','environmentMatched')),
             'Full original required-effects receipt incomplete')
        performer.verify_effects_and_continuity(plan)
        need(performer.observe(plan) == plan['expectedAfter'], 'Final full actual image differs')
        complete = {'schema':'hp_pm_state_bound_complete_v1','intentSHA256':sha(raw),'planSHA256':core.digest(plan),
                    'postMapSHA256':core.digest(plan['expectedAfter']),'state':'CONTROLLED_TRANSITION_COMPLETE','automaticRetry':False}
        store.create('complete.json',encoded(complete))
        return complete
    except BaseException as error:
        store.create('caught.json', encoded({'class':type(error).__name__,'message':str(error),'trace':traceback.format_exc()}))
        store.create('held.json',encoded({'intentSHA256':sha(raw),'state':'HELD_NO_RETRY','mutationPossible':mutation,'automaticRetry':False}))
        raise


def main():
    # Explicit CLI invocation is required. Import/test never reads target env.
    need(len(sys.argv) == 2, 'Specify one private exact adapter selection file')
    selection = decode(private_read(Path(sys.argv[1])))
    need(set(selection) == {'schema','plan','adapter','mode','evidenceDirectory','observationId'}, 'Closed actual adapter selection required')
    need(selection['schema'] == 'hp_pm_actual_adapter_selection_v1', 'Actual adapter schema differs')
    need(selection['mode'] in ('transition','completed-repeat'), 'Explicit bounded execution mode required')
    need((selection['mode'] == 'transition' and selection['observationId'] is None)
         or (selection['mode'] == 'completed-repeat' and isinstance(selection['observationId'],str)
             and re.fullmatch('[a-f0-9]{32}',selection['observationId'])), 'Explicit read-only observation identity required')
    plan, adapter = selection['plan'],selection['adapter']
    keys = {'targetJournal','sourcePins','expectedNativeBefore','nativeRequest','wpCli','controlsSHA256',
            'phase','operationId','token','rsync','python'}
    need(set(adapter) == keys and adapter['operationId'] == plan['operationId'], 'Closed bound target selection required')
    need(isinstance(adapter['phase'], dict) and set(adapter['phase']) == {'selectedDigest','phase','attempt'}
         and re.fullmatch('[a-f0-9]{64}',adapter['phase']['selectedDigest'])
         and re.fullmatch('[a-z0-9-]{1,64}',adapter['phase']['phase'])
         and re.fullmatch('[a-z0-9-]{1,64}',adapter['phase']['attempt']), 'Closed immutable phase/attempt binding required')
    here = Path(__file__).resolve().parent
    repo = Path.cwd().resolve()
    # Bind executable controls to the independently reviewed actual release tree,
    # not an arbitrary selected descriptor or mutable working-copy helper.
    for name in ('pm-actual-adapter.py','state-bound.py','state-bound-packages.json',
                 'deploy.py','package-equivalence.py','remote-guard.py',
                 'pm-native-before-reader.php','pm-preinit-guard.php','pm-copy-guardian.py'):
        result = subprocess.run(['git','-C',str(repo),'show',plan['releaseSHA'] + ':.github/hp-deploy/' + name],capture_output=True,timeout=30)
        need(result.returncode == 0 and not result.stderr and result.stdout == (here / name).read_bytes(),
             'Actual executable controls differ from selected immutable release: ' + name)
    core = load('pm_state_bound', here / 'state-bound.py')
    deploy = load('pm_existing_deploy', here / 'deploy.py')
    refs = core.decode((here / 'state-bound-packages.json').read_bytes())
    parent = None
    if plan.get('ownedPartial'):
        parent = core.Store(Path(plan['ownedPartial']['parentJournal']))
    core.validate(plan, refs, parent)
    store = core.Store(Path(plan['journalRoot']) / plan['operationId'])
    evidence = core.Store(Path(selection['evidenceDirectory']))
    need(evidence.path != store.path and store.path not in evidence.path.parents
         and not (Path(ROOT) == evidence.path or Path(ROOT) in evidence.path.parents),
         'Fresh separate private observation evidence directory required')
    spec = deploy.request('production', os.environ)
    need(spec['sha'] == plan['releaseSHA'] and spec['tree'] == plan['releaseTree']
         and spec['site'].rstrip('/') == plan['site'].rstrip('/') and spec['base'] == ROOT + '/wp-content/plugins', 'Release/target selection differs')
    repo = Path.cwd().resolve(); deploy.verify_checkout(spec,repo)
    controls = {name:(here / name).read_bytes() for name in ('remote-guard.py','pm-native-before-reader.php','pm-preinit-guard.php','pm-copy-guardian.py')}
    need({name:sha(raw) for name,raw in controls.items()} == adapter['controlsSHA256'], 'Control source pin differs')
    with tempfile.TemporaryDirectory(prefix='pm-actual-selected-') as directory:
        work = Path(directory)
        payload, proof = deploy.materialize_payload(repo,plan['releaseSHA'],work / 'selected')
        prior, prior_proof = deploy.materialize_payload(repo,refs['references']['prior']['sha'],work / 'prior')
        need(observed_map(payload) == plan['expectedAfter'] and observed_map(prior) == refs['references']['prior']['files'], 'Immutable payload/prior complete map differs')
        broker = SSHBroker(spec,adapter,adapter['targetJournal'],controls,Path(__file__).read_bytes(),evidence,selection['mode'],selection['observationId'])
        try:
            performer = ActualPerformer(spec,repo,payload,prior,broker,evidence,deploy,adapter)
            if selection['mode'] == 'completed-repeat':
                result = core.completed_repeat(plan,refs,store,performer,parent)
            else:
                result = transition(core,plan,refs,store,performer,parent)
                # Immediate immutable verification uses this SAME broker; no
                # second mutation reservation or transport attempt is opened.
                repeated = core.completed_repeat(plan,refs,store,performer,parent)
                evidence.create('immediate-completed-repeat.json',encoded(repeated))
        finally:
            broker.close()
    print(encoded(result).decode())


if __name__ == '__main__':
    main()

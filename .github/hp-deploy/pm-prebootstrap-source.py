#!/usr/bin/env python3
"""Fixed R2a filesystem census, never PHP/configuration/application execution.

Actual invocation needs a separate Publisher selection. Hashes and layout facts
are point observations, not bootstrap, private-profile or native admission.
No configuration/source bytes, environment values or credentials are returned.
"""
import datetime
import hashlib
import json
import os
from pathlib import Path
import shutil
import stat
import sys

ROOT = Path('/www/holisticpeoplecom_349/public')
PRIVATE = Path('/www/holisticpeoplecom_349/private')
MAX_FILE = 16 * 1024 * 1024
MAX_NAMES = 128
MAX_OUTPUT = 65536
FILES = (
    'wp-load.php', 'wp-settings.php', 'wp-includes/option.php',
    'wp-includes/cron.php', 'wp-includes/class-wp-hook.php', 'wp-config.php',
    'wp-content/mu-plugins/hp-deploy-swap.php', 'wp-content/object-cache.php',
    'wp-content/advanced-cache.php', 'wp-content/db.php', 'wp-content/sunrise.php',
    'wp-content/hp-deploy/selected-phase.lock',
    'wp-content/hp-deploy/selected-phase-owner.json',
)
DIRECTORIES = ('wp-content/hp-deploy', 'wp-content/hp-deploy/products-manager',
               'wp-content/mu-plugins')
EXECUTABLES = ('wp', 'php', 'python3')
ENV_NAMES = ('PATH', 'HOME', 'WP_CLI_CONFIG_PATH', 'WP_CLI_PACKAGES_DIR', 'WP_CLI_PHP',
             'WP_CLI_PHP_ARGS', 'PHPRC', 'PHP_INI_SCAN_DIR', 'BASH_ENV', 'ENV',
             'LD_PRELOAD', 'LD_LIBRARY_PATH')
STABLE = ('st_dev', 'st_ino', 'st_mode', 'st_uid', 'st_gid', 'st_nlink',
          'st_size', 'st_mtime_ns', 'st_ctime_ns')


class Refused(RuntimeError):
    pass


def need(value, message):
    if not value:
        raise Refused(message)


def metadata(row):
    return {'mode': format(stat.S_IMODE(row.st_mode), '04o'),
            'uid': row.st_uid, 'gid': row.st_gid, 'bytes': row.st_size,
            'device': row.st_dev, 'inode': row.st_ino, 'links': row.st_nlink}


def failure(error):
    # Keep type/errno, never interpolate a file body/configuration or environment.
    return {'state': 'HOLD', 'errorType': type(error).__name__,
            'errno': getattr(error, 'errno', None)}


def file_record(path):
    """Hash only a stable, named, canonical single-link regular file."""
    path = Path(path)
    try:
        before = path.lstat()
        need(path.is_absolute() and path.resolve(strict=True) == path
             and stat.S_ISREG(before.st_mode) and before.st_nlink == 1
             and before.st_size <= MAX_FILE, 'Unsafe/noncanonical/oversize file')
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
        with os.fdopen(fd, 'rb') as stream:
            actual = os.fstat(stream.fileno())
            need(all(getattr(before, x) == getattr(actual, x) for x in STABLE),
                 'Named file changed before open')
            digest, total = hashlib.sha256(), 0
            while True:
                part = stream.read(min(262144, MAX_FILE + 1 - total))
                if not part:
                    break
                total += len(part)
                need(total <= MAX_FILE, 'File grew beyond bound')
                digest.update(part)
            after = os.fstat(stream.fileno())
        named = path.lstat()
        need(total == before.st_size
             and all(getattr(before, x) == getattr(after, x) == getattr(named, x)
                     for x in STABLE)
             and path.resolve(strict=True) == path, 'File changed during capture')
        return {'state': 'OBSERVED_POINT_FILE', **metadata(before),
                'sha256': digest.hexdigest()}
    except (OSError, Refused) as error:
        return failure(error)


def directory_record(path):
    path = Path(path)
    try:
        row = path.lstat()
        need(path.is_absolute() and path.resolve(strict=True) == path
             and stat.S_ISDIR(row.st_mode), 'Canonical directory required')
        return {'state': 'OBSERVED_POINT_DIRECTORY', **metadata(row)}
    except (OSError, Refused) as error:
        return failure(error)


def mu_names(path):
    """Immediate names/types only; no recursive source or callback scan."""
    try:
        need(directory_record(path)['state'] == 'OBSERVED_POINT_DIRECTORY',
             'Canonical MU directory required')
        rows = []
        with os.scandir(path) as entries:
            for entry in entries:
                need(len(rows) < MAX_NAMES, 'MU name count exceeds scope')
                row = entry.stat(follow_symlinks=False)
                kind = ('file' if stat.S_ISREG(row.st_mode) else 'directory'
                        if stat.S_ISDIR(row.st_mode) else 'symlink'
                        if stat.S_ISLNK(row.st_mode) else 'special')
                rows.append({'name': entry.name, 'type': kind,
                             'mode': format(stat.S_IMODE(row.st_mode), '04o')})
        return {'state': 'OBSERVED_POINT_NAMES', 'entries': sorted(rows, key=lambda x: x['name'])}
    except (OSError, Refused) as error:
        return failure(error)


def executable_record(name):
    # Only three literal lookups. Do not execute, enumerate PATH or export it.
    found = shutil.which(name)
    if found is None:
        return {'state': 'HOLD', 'errorType': 'ExecutableNotFound', 'errno': None}
    try:
        original = Path(found)
        need(original.is_absolute(), 'Relative PATH executable route refused')
        resolved = original.resolve(strict=True)
        return {'lookup': name, 'path': str(original), 'resolvedPath': str(resolved),
                'source': file_record(resolved)}
    except (OSError, Refused) as error:
        return failure(error)


def census():
    need(ROOT.is_absolute() and ROOT.resolve(strict=True) == ROOT
         and ROOT.is_dir(), 'Fixed production root unavailable/noncanonical')
    return {'schema': 'hp_pm_prebootstrap_source_census_v1',
            'observedUTC': datetime.datetime.now(datetime.timezone.utc).isoformat(),
            'root': str(ROOT),
            'files': {name: file_record(ROOT / name) for name in FILES},
            'directories': {name: directory_record(ROOT / name) for name in DIRECTORIES},
            'privateDirectory': directory_record(PRIVATE),
            'muNames': mu_names(ROOT / 'wp-content/mu-plugins'),
            'executables': {name: executable_record(name) for name in EXECUTABLES},
            'startupContext': {
                'cwd': str(Path.cwd().resolve(strict=True)),
                'environmentNames': list(ENV_NAMES),
                'environmentSHA256': hashlib.sha256(json.dumps(
                    {name: os.environ.get(name) for name in ENV_NAMES},
                    separators=(',', ':')).encode()).hexdigest(),
                'meaning': 'Identity of existing finite startup context, not effect qualification'},
            'targetWrites': 0, 'nativeInvocations': 0, 'configurationExecution': False,
            'qualification': 'POINT_SOURCE_LAYOUT_ONLY_NO_NATIVE_BOOTSTRAP_OR_PERMISSION'}


def main():
    need(len(sys.argv) == 1 and sys.flags.isolated and sys.dont_write_bytecode,
         'Fixed no-argument collector requires isolated Python -I -B')
    output = json.dumps(census(), sort_keys=True, separators=(',', ':')).encode()
    need(len(output) + 1 <= MAX_OUTPUT, 'Census output exceeds parser bound')
    sys.stdout.buffer.write(output + b'\n')


if __name__ == '__main__':
    main()

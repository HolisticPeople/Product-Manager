"""State-bound forward/recovery control contract; live performer is unsupported.

No imports perform I/O. Collaborators below exist for disconnected qualification.
The CLI cannot select a collaborator or invoke the legacy deployment transport.
"""
import hashlib
import json
import os
import re
import stat
import traceback
from pathlib import Path, PurePosixPath

MAX = 4 * 1024 * 1024
REFERENCE_SHA256 = '97a68d5cceb2a9113e0018ba16deeefd156e8ad8506d9854c58d63ee100f0071'
EFFECTS = ['already-active-activation', 'active-readback', 'object-cache-flush',
           'kinsta-page-cache-purge', 'version-readback']


class Refused(RuntimeError):
    pass


class UnsupportedAdmission(Refused):
    pass


def need(value, message):
    if not value:
        raise Refused(message)


def encoded(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False).encode()


def digest(value):
    return hashlib.sha256(encoded(value)).hexdigest()


def decode(raw):
    def pairs(items):
        result = {}
        for key, value in items:
            need(key not in result, 'Duplicate JSON key')
            result[key] = value
        return result
    return json.loads(raw, object_pairs_hook=pairs)


def sha40(value):
    return isinstance(value, str) and re.fullmatch('[a-f0-9]{40}', value) is not None


def file_map(files):
    need(isinstance(files, dict) and 0 < len(files) <= 100, 'Complete bounded file map required')
    for name, row in files.items():
        path = PurePosixPath(name)
        need(isinstance(name, str) and not path.is_absolute() and '..' not in path.parts
             and str(path) == name and name not in ('', '.'), 'Canonical relative payload path required')
        need(isinstance(row, dict) and set(row) == {'mode', 'type', 'gitBlobSHA1', 'sha256', 'bytes'},
             'Full byte/blob/mode map required; version-only recovery refused')
        need(type(row['mode']) is int and row['mode'] in (0o644, 0o755) and row['type'] == 'blob'
             and sha40(row['gitBlobSHA1']) and isinstance(row['sha256'], str)
             and re.fullmatch('[a-f0-9]{64}', row['sha256']) is not None
             and type(row['bytes']) is int and 0 <= row['bytes'] <= MAX, 'Invalid payload record')
    return files


class Store:
    """Append-only private records. Existing attempts are never adopted for dispatch."""
    def __init__(self, path):
        self.path = Path(path)
        s = self.path.lstat()
        need(self.path.resolve() == self.path and stat.S_ISDIR(s.st_mode)
             and s.st_uid == os.geteuid() and stat.S_IMODE(s.st_mode) == 0o700,
             'Canonical owned0700 journal required')

    def read(self, name):
        need(re.fullmatch('[a-z0-9.-]+', name) is not None, 'Private basename required')
        fd = os.open(self.path / name, os.O_RDONLY | os.O_NOFOLLOW)
        with os.fdopen(fd, 'rb') as stream:
            before = os.fstat(stream.fileno())
            need(stat.S_ISREG(before.st_mode) and before.st_nlink == 1
                 and before.st_uid == os.geteuid() and stat.S_IMODE(before.st_mode) == 0o600
                 and before.st_size <= MAX, 'Private bounded regular file required')
            raw = stream.read(MAX + 1)
            after = os.fstat(stream.fileno())
            stable = ('st_dev', 'st_ino', 'st_uid', 'st_nlink', 'st_mode', 'st_size', 'st_mtime_ns', 'st_ctime_ns')
            need(len(raw) <= MAX and all(getattr(before, key) == getattr(after, key) for key in stable)
                 and (before.st_dev, before.st_ino) == ((self.path / name).lstat().st_dev,
                                                      (self.path / name).lstat().st_ino), 'Journal changed')
            return raw

    def create(self, name, raw):
        need(re.fullmatch('[a-z0-9.-]+', name) is not None and isinstance(raw, bytes)
             and len(raw) <= MAX, 'Bounded raw private record required')
        fd = os.open(self.path / name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, 'wb') as stream:
            stream.write(raw)
            stream.flush()
            os.fsync(stream.fileno())
        directory_fd = os.open(self.path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            os.fsync(directory_fd)
        finally:
            os.close(directory_fd)
        need(self.read(name) == raw, 'Journal readback mismatch')


def validate(plan, references, parent=None):
    need(digest(references) == REFERENCE_SHA256, 'Finite reviewed package references changed')
    need(references['schema'] == 'hp_pm_state_bound_package_references_v1', 'Reference schema')
    refs = references['references']
    need(set(refs) == {'prior', 'forward', 'recovery'}, 'All reference images required')
    for row in refs.values():
        need(sha40(row['sha']) and sha40(row['tree']), 'Exact reference source identity required')
        file_map(row['files'])
    need(refs['prior']['files'] == refs['recovery']['files'], 'Recovery must equal complete prior image')
    need(plan['schema'] == 'hp_pm_state_bound_selection_v1' and plan['action'] in ('forward', 'recovery')
         and plan['referencesSHA256'] == digest(references), 'Exact selected reference/action binding required')
    need(plan['site'] == references['site'] and plan['root'] == references['root']
         and plan['plugin'] == references['plugin'], 'Site/root/plugin mismatch')
    need(re.fullmatch('[a-f0-9]{32}', plan['operationId']) is not None, 'Exclusive operation identity required')
    need(sha40(plan['releaseSHA']) and sha40(plan['releaseTree']), 'Actual post-promotion source identity required')
    after = refs['forward' if plan['action'] == 'forward' else 'recovery']
    need(plan['preparedSource'] == {'sha': after['sha'], 'tree': after['tree']}, 'Wrong prepared source')
    need(plan['expectedAfter'] == after['files'], 'Wrong complete forward/recovery image')
    current = file_map(plan['expectedBefore'])
    ordinary = refs['prior' if plan['action'] == 'forward' else 'forward']['files']
    if current == ordinary:
        need(plan.get('ownedPartial') is None and parent is None, 'Unexpected parent/partial disposition')
    else:
        need(plan['action'] == 'recovery' and parent is not None, 'Foreign/mixed/unknown state refused')
        partial = plan.get('ownedPartial')
        need(isinstance(partial, dict) and partial['reviewedCurrentMapSHA256'] == digest(current),
             'Explicit reviewed partial map required')
        intent_raw, held_raw = parent.read('intent.json'), parent.read('held.json')
        origin, held = decode(intent_raw), decode(held_raw)
        validate(origin['plan'], references)
        need(parent.path == Path(origin['plan']['journalRoot']) / origin['plan']['operationId'],
             'Parent journal relocated from selected operation path')
        need(partial['parentIntentSHA256'] == hashlib.sha256(intent_raw).hexdigest()
             and partial['parentHeldSHA256'] == hashlib.sha256(held_raw).hexdigest()
             and held['intentSHA256'] == partial['parentIntentSHA256']
             and held['mutationPossible'] is True and origin['plan']['action'] == 'forward'
             and origin['plan']['operationId'] != plan['operationId']
             and origin['plan']['referencesSHA256'] == plan['referencesSHA256']
             and all(origin['plan'][key] == plan[key] for key in ('site', 'root', 'plugin')),
             'Partial state lacks owned forward intent and caught outcome')
        need(set(current) <= (set(refs['prior']['files']) | set(refs['forward']['files'])), 'Foreign partial file')
        for name, row in current.items():
            need(row in [refs[key]['files'].get(name) for key in ('prior', 'forward')], 'Unqualified partial bytes/mode')
    need(plan['requiredEffects'] == EFFECTS and plan['fieldBoundary'] == 'BEFORE_ANY_FIELD',
         'Exact effects and preFIELD-only boundary required')


def capture(store, name, call):
    """Persist the real command return/exception before inspecting expected outcome."""
    try:
        result = call()
    except BaseException as error:
        # Exception details first; partial streams must not be replaced by an assertion error.
        store.create(name + '.error.json', encoded({'class': type(error).__name__,
                     'message': str(error), 'trace': traceback.format_exc()}))
        for suffix, value in [('stdout', getattr(error, 'stdout', None)),
                              ('stderr', getattr(error, 'stderr', None))]:
            store.create(name + '.' + suffix, value.encode() if isinstance(value, str) else (value or b''))
        raise
    # The collaborator contract returns raw bytes, never an already-summarized success certificate.
    store.create(name + '.stdout', result.stdout)
    store.create(name + '.stderr', result.stderr)
    store.create(name + '.return.json', encoded({'returncode': result.returncode}))
    need(type(result.returncode) is int and result.returncode == 0 and not result.stderr,
         name + ' failed; actual state requires review, no retry')
    return result.stdout


def transition(plan, references, store, performer, parent=None):
    """Source control core. No supported live performer is currently supplied.

    A future reviewed performer must enforce source/lease/native continuity itself;
    accepting caller booleans or a JSON admission certificate does not implement it.
    """
    validate(plan, references, parent)
    need(store.path == Path(plan['journalRoot']) / plan['operationId'], 'Exact selected operation journal required')
    intent = {'schema': 'hp_pm_state_bound_intent_v1', 'plan': plan,
              'state': 'OUTCOME_UNKNOWN_BEFORE_ADMISSION', 'automaticRetry': False}
    raw = encoded(intent)
    store.create('intent.json', raw)  # Exclusive durable reservation before any performer call.
    mutation = False
    try:
        performer.admit(plan, plan['expectedBefore'])
        # admission must cover actual current effective retirement (Inventory23448),
        # callback reachability, FIELD/journal absence, bootstrap/cache/queue/outbound,
        # current native state and exclusive lease; no checksum/boolean substitutes.
        need(performer.source(plan) == {'sha': plan['releaseSHA'], 'tree': plan['releaseTree'],
                                      'files': plan['expectedAfter']}, 'Selected release source payload mismatch')
        need(performer.prior_image(plan) == references['references']['prior']['files'], 'Prior image drift')
        need(performer.observe(plan) == plan['expectedBefore'], 'Current full bytes/modes differ')
        store.create('copy-intent.json', encoded({'intentSHA256': hashlib.sha256(raw).hexdigest(),
                     'expectedBeforeSHA256': digest(plan['expectedBefore']), 'outcome': 'UNKNOWN'}))
        mutation = True
        capture(store, 'copy', lambda: performer.copy(plan))
        output = capture(store, 'effects', lambda: performer.effects(plan))
        need(decode(output) == {'effects': EFFECTS}, 'Exact required effect results missing')
        performer.verify_effects_and_continuity(plan)
        need(performer.observe(plan) == plan['expectedAfter'], 'Final full bytes/modes differ')
        complete = {'schema': 'hp_pm_state_bound_complete_v1', 'intentSHA256': hashlib.sha256(raw).hexdigest(),
                    'planSHA256': digest(plan), 'postMapSHA256': digest(plan['expectedAfter']),
                    'state': 'CONTROLLED_TRANSITION_COMPLETE', 'automaticRetry': False}
        store.create('complete.json', encoded(complete))
        return complete
    except BaseException as error:
        # Preserve uncaught admission/readback/effect-parser failures too, before classification.
        store.create('caught.json', encoded({'class': type(error).__name__, 'message': str(error),
                     'trace': traceback.format_exc()}))
        store.create('held.json', encoded({'intentSHA256': hashlib.sha256(raw).hexdigest(),
                     'state': 'HELD_NO_RETRY', 'mutationPossible': mutation, 'automaticRetry': False}))
        raise


def completed_repeat(plan, references, store, performer, parent=None):
    """Read-only completed verification; original records stay byte-identical."""
    validate(plan, references, parent)
    need(store.path == Path(plan['journalRoot']) / plan['operationId'], 'Exact selected operation journal required')
    intent_raw, complete_raw = store.read('intent.json'), store.read('complete.json')
    intent, complete = decode(intent_raw), decode(complete_raw)
    need(intent['plan'] == plan and complete['state'] == 'CONTROLLED_TRANSITION_COMPLETE'
         and complete['planSHA256'] == digest(plan)
         and complete['intentSHA256'] == hashlib.sha256(intent_raw).hexdigest()
         and complete['postMapSHA256'] == digest(plan['expectedAfter']), 'Immutable completion mismatch')
    need(not (store.path / 'held.json').exists(), 'Held attempt cannot become completed repeat')
    performer.admit(plan, plan['expectedAfter'])
    need(performer.source(plan) == {'sha': plan['releaseSHA'], 'tree': plan['releaseTree'], 'files': plan['expectedAfter']}
         and performer.prior_image(plan) == references['references']['prior']['files']
         and performer.observe(plan) == plan['expectedAfter'], 'Completed repeat state/source/prior drift')
    performer.verify_effects_and_continuity(plan)
    need(store.read('intent.json') == intent_raw and store.read('complete.json') == complete_raw,
         'Completed records changed')
    return {'state': 'COMPLETED_REPEAT_ZERO_EFFECTS', 'journalWrites': 0, 'copyCalls': 0, 'effectCalls': 0}


def live_main(*_args):
    # BEFORE reading selection/journals/credentials/environment or creating subprocesses.
    raise UnsupportedAdmission('NO_SUPPORTED_PM_NATIVE_PREFIELD_EFFECT_CONTINUITY_PERFORMER; '
                               'Publisher+PM/Core/Inventory/runtime owners via00F; DO_NOT_MERGE')


if __name__ == '__main__':
    live_main()

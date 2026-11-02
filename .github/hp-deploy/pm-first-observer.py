#!/usr/bin/env python3
"""One independently admitted raw first observation; no CODE/FIELD executor.

Source and selection hashes bind bytes, never permission. The Publisher must
qualify current startup/effects/resources and select this exact invocation.
Unknown child lifetime requires external reconciliation of this exact attempt;
closing the wrapper's lock never establishes child exit or retained ownership.
"""
import argparse
import hashlib
import importlib.util
import json
import math
import os
from pathlib import Path
import re
import stat
import sys
import time
import traceback

ROOT = Path('/www/holisticpeoplecom_349/public')
HERE = Path(__file__).resolve().parent
MAX = 4 * 1024 * 1024
KIT = {
    'selected_wire.py': '7e55a035baa3156e5e75db59e9070927c5a31ba3b1d1f96dd6fb83c32f5861d8',
    'zen_pair_files.py': '40d7362549013bfaa013d82020547f86afa568768c89394b54dfd57c1540632d',
    'pm_process.py': 'f486451d256bb26a52bb6fc227be4aaec1d94e89910638be9164ab2858ac3396',
    'zen_phase_process.py': 'ca2a17e9146eeec1f3070d157bb3bb00ab6fadd1006d9a37660e6285c92bfcda',
}
PHP = {
    'pm-native-before-reader.php': '81db585a5996a1c102afa175f346d752841689cb663ec817bad811cb9c11c67e',
    'pm-preinit-guard.php': 'abe8672513a9d2014dd6a88945ba9feb926b476502061f8d3825a960c9bf8123',
    'first-observer-kit/hp-deploy-native-read.php': '93c06287816e31923dbfeae0b333cc5cddd4b5573d86e5a453a16c09300d3a76',
}
STARTUP = {'hp_core_version': '3.4.24', 'hp_inventory_schema_version': '0.22.0',
           'hp_inventory_product_manager_erp_migrated': 'yes'}
OPTION_TAGS = {'pre_option', 'pre_option_cron', 'option_cron', 'default_option_cron',
               'pre_get_scheduled_event', 'get_scheduled_event', 'wp_next_scheduled'} | {
    prefix + name for name in STARTUP for prefix in ('pre_option_', 'option_', 'default_option_')}
ERP_TAGS = {'hp_pm_erp_retired_by_hp_inventory', 'hp_pm_erp_persist_enabled', 'hp_pm_erp_enabled'}
INVENTORY_HOOKS = {'hp_inventory_fulfillment_intake', 'hp_inventory_demand_refresh',
    'hp_inventory_combine_return_scan', 'hp_inventory_ss_v2_shadow_audit_tick',
    'hp_inventory_shipstation_inbound_reconcile', 'hp_inventory_expiration_scan',
    'hp_inventory_fulfillment_jobs', 'hp_inventory_fulfillment_review',
    'hp_inventory_dropship_tracking_reconcile', 'hp_inventory_parcel_tick',
    'hp_inventory_order_status_audit_v1', 'hp_inventory_ss_health_check'}
JOBS = {'hp_catalog_index_product_ingredients', 'hp_catalog_categorize_product_v1',
        'hp_catalog_semantic_source_review_v1', 'hp_catalog_categorization_sweep_v1',
        'hp_catalog_ingredient_index_recovery'}
POLICY_KEYS = {'active_plugins', 'hp_catalog_ingredient_lifecycle_policy',
               'hp_catalog_product_categorization_policy_v1', 'hp_catalog_semantic_rescan_policy_v1'}
ENV_NAMES = ('PATH', 'HOME', 'WP_CLI_CONFIG_PATH', 'WP_CLI_PACKAGES_DIR', 'WP_CLI_PHP',
             'WP_CLI_PHP_ARGS', 'PHPRC', 'PHP_INI_SCAN_DIR', 'BASH_ENV', 'ENV',
             'LD_PRELOAD', 'LD_LIBRARY_PATH')
STABLE = ('st_dev', 'st_ino', 'st_mode', 'st_uid', 'st_gid', 'st_nlink',
          'st_size', 'st_mtime_ns', 'st_ctime_ns')


class Refused(RuntimeError):
    pass


def need(ok, message):
    if not ok:
        raise Refused(message)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def encoded(value):
    return json.dumps(value, separators=(',', ':'), allow_nan=False).encode()


def decoded(raw):
    need(isinstance(raw, bytes) and 0 < len(raw) <= MAX, 'Bounded original JSON bytes required')
    def pairs(rows):
        result = {}
        for key, value in rows:
            need(key not in result, 'Duplicate JSON key')
            result[key] = value
        return result
    def invalid(_value):
        raise Refused('Nonfinite JSON number')
    def finite(value):
        result = float(value)
        need(math.isfinite(result), 'Nonfinite JSON number')
        return result
    return json.loads(raw.decode('utf-8', errors='strict'), object_pairs_hook=pairs,
                      parse_constant=invalid, parse_float=finite)


def private_dir(path):
    path = Path(path)
    row = path.lstat()
    need(path.is_absolute() and path.resolve(strict=True) == path
         and stat.S_ISDIR(row.st_mode) and stat.S_IMODE(row.st_mode) == 0o700
         and row.st_uid == os.geteuid()
         and path != ROOT and ROOT not in path.parents, 'Owned canonical outside-public 0700 directory required')
    return path


def capture(path, *, private=False):
    path = Path(path)
    if private:
        private_dir(path.parent)
    row = path.lstat()
    limit = MAX if private else 16 * 1024 * 1024
    need(path.is_absolute() and path.resolve(strict=True) == path
         and stat.S_ISREG(row.st_mode) and row.st_nlink == 1 and row.st_size <= limit,
         'Canonical bounded unaliased source required')
    if private:
        need(row.st_uid == os.geteuid() and stat.S_IMODE(row.st_mode) == 0o600,
             'Owned private 0600 file required')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'rb') as stream:
        before = os.fstat(stream.fileno())
        raw = stream.read(limit + 1)
        after = os.fstat(stream.fileno())
    named = path.lstat()
    need(len(raw) == row.st_size and len(raw) <= limit
         and all(getattr(row, x) == getattr(before, x) == getattr(after, x) == getattr(named, x)
                 for x in STABLE), 'Source changed during capture')
    return raw, row


def load_kit():
    """Compile only exact captured immutable dependency bytes, no ambient fallback."""
    sources = {}
    for name, digest in KIT.items():
        need(name[:-3] not in sys.modules, 'Ambient/previous shared module refused')
        data, _row = capture(HERE / 'first-observer-kit' / name, private=True)
        need(sha(data) == digest, 'Immutable shared dependency changed')
        sources[name] = data
    for name, data in sources.items():
        module = importlib.util.module_from_spec(importlib.util.spec_from_loader(name[:-3], loader=None))
        module.__file__ = str(HERE / 'first-observer-kit' / name)
        sys.modules[name[:-3]] = module
        exec(compile(data, module.__file__, 'exec'), module.__dict__)
    return sys.modules['zen_pair_files'], sys.modules['zen_phase_process'], sys.modules['pm_process']


def source_pins(pins):
    need(isinstance(pins, dict) and 1 <= len(pins) <= 64, 'Closed finite source map required')
    for path, descriptor in pins.items():
        need(isinstance(descriptor, dict) and set(descriptor) == {'sha256', 'bytes', 'mode', 'uid'},
             'Exact source descriptor required')
        data, row = capture(Path(path))
        need(sha(data) == descriptor['sha256'] and type(descriptor['bytes']) is int
             and len(data) == descriptor['bytes'] and type(descriptor['uid']) is int
             and row.st_uid == descriptor['uid']
             and format(stat.S_IMODE(row.st_mode), '04o') == descriptor['mode'], 'Current source map differs')


def callbacks(value, tags, pins=None):
    need(isinstance(value, dict) and set(value) == tags, 'Closed callback tag scope required')
    for rows in value.values():
        need(isinstance(rows, list) and len(rows) <= 128, 'Bounded ordered callbacks required')
        for row in rows:
            need(isinstance(row, dict) and set(row) == {'priority', 'acceptedArgs', 'callback', 'file',
                 'startLine', 'endLine', 'sourceSHA256'} and type(row['priority']) is int
                 and type(row['acceptedArgs']) is int and 0 <= row['acceptedArgs'] <= 99
                 and isinstance(row['callback'], str) and 0 < len(row['callback']) <= 1024,
                 'Typed source callback fingerprint required')
            for field in ('startLine', 'endLine'):
                need(row[field] is False or (type(row[field]) is int and row[field] > 0), 'Typed callback source line required')
            if row['file'] is None:
                need(row['sourceSHA256'] is None, 'Native callback source mismatch')
            else:
                need(isinstance(row['file'], str) and Path(row['file']).is_absolute()
                     and digest(row['sourceSHA256']), 'Typed callback source identity required')
                if pins is not None:
                    need(row['file'] in pins and pins[row['file']]['sha256'] == row['sourceSHA256'],
                         'Callback source not in actual frozen map')


def validate(selection):
    keys = {'schema', 'operation', 'attempt', 'privateAttempt', 'deadlineUTC', 'sourcePins',
            'wpCli', 'nativeRequest', 'resourceAdmissionRef'}
    need(isinstance(selection, dict) and set(selection) == keys
         and selection['schema'] == 'hp_pm_first_observation_v1'
         and isinstance(selection['operation'], str) and re.fullmatch('[a-f0-9]{32}', selection['operation'])
         and isinstance(selection['attempt'], str) and re.fullmatch('[A-Za-z0-9_-]{1,100}', selection['attempt'])
         and type(selection['deadlineUTC']) is int and selection['deadlineUTC'] - time.time() >= 45,
         'Closed current first-observation selection required')
    directory = Path(selection['privateAttempt'])
    private_dir(directory.parent)
    need(directory.is_absolute() and directory.resolve(strict=False) == directory
         and directory != ROOT and ROOT not in directory.parents and not os.path.lexists(directory),
         'New exclusive outside-public private attempt required')
    pins, cli = selection['sourcePins'], selection['wpCli']
    source_pins(pins)
    required = {str(ROOT / name) for name in ('wp-load.php', 'wp-settings.php', 'wp-includes/option.php',
                'wp-includes/cron.php', 'wp-includes/class-wp-hook.php', 'wp-config.php')}
    need(isinstance(cli, dict) and set(cli) == {'path', 'sha256', 'startupEnvironmentSHA256', 'cwd'}
         and cli['path'] in pins and pins[cli['path']]['sha256'] == cli['sha256']
         and required <= set(pins) and str(Path(sys.executable).resolve(strict=True)) in pins,
         'Actual loader/config/interpreter source map required')
    need(os.access(cli['path'], os.X_OK) and cli['cwd'] == str(Path.cwd().resolve(strict=True))
         and cli['startupEnvironmentSHA256'] == sha(encoded({name: os.environ.get(name) for name in ENV_NAMES})),
         'Exact existing CLI startup environment/working directory changed')
    request = selection['nativeRequest']
    need(isinstance(request, dict) and set(request) == {'schema', 'root', 'site', 'environment', 'context',
         'projection', 'productIds', 'phaseFile', 'startup', 'allowedOptionCallbacks', 'allowedErpCallbacks'}
         and request['schema'] == 'hp_pm_actual_native_reader_observation_v1'
         and request['root'] == str(ROOT) and request['site'] == 'https://holisticpeople.com/'
         and request['environment'] == 'production' and request['context'] == 'full-native'
         and request['projection'] == 'code-before' and request['productIds'] == [43395, 43406, 43457]
         and all(type(x) is int for x in request['productIds'])
         and request['phaseFile'] == str(ROOT / 'wp-content/hp-deploy/selected-phase.lock')
         and request['startup'] == STARTUP, 'Fixed PM source observation request required')
    need(isinstance(request['allowedOptionCallbacks'], dict)
         and set(request['allowedOptionCallbacks']) == {'pre-init', 'full-native'}, 'Two exact callback contexts required')
    for value in request['allowedOptionCallbacks'].values():
        callbacks(value, OPTION_TAGS, pins)
    callbacks(request['allowedErpCallbacks'], ERP_TAGS, pins)
    ref = selection['resourceAdmissionRef']
    need(isinstance(ref, dict) and set(ref) == {'path', 'sha256'}, 'Original resource evidence reference required')
    resource, _row = capture(Path(ref['path']), private=True)
    need(sha(resource) == ref['sha256'], 'Original independent resource evidence changed')
    # This retains independently qualified evidence, never a caller PASS boolean.
    return directory, resource


def absent_owner(files):
    need(not os.path.lexists(files.content / 'hp-deploy/selected-phase-owner.json'),
         'Any existing selected operation owner requires its own disposition')


def lifetime(directory):
    for name in ('actual.return.json', 'actual.cleanup.return.json'):
        path = directory / name
        if path.exists():
            try:
                value = decoded(capture(path, private=True)[0]).get('returncode')
                if type(value) is int:
                    return {'childExitObserved': True, 'childReturnCode': value}
            except (OSError, ValueError, Refused):
                pass
    return {'childExitObserved': False, 'childReturnCode': None}


def closed(value, keys):
    need(isinstance(value, dict) and set(value) == set(keys), 'Finite observed object fields missing/changed')


def digest(value, size=64):
    return isinstance(value, str) and re.fullmatch('[a-f0-9]{' + str(size) + '}', value) is not None


def row_id(value):
    return ((type(value) is int and value > 0) or
            (isinstance(value, str) and re.fullmatch('[0-9]+', value) and int(value) > 0))


def startup_contract(frame, context, projection):
    """Finite producer structure only; no predicted native snapshot comparison."""
    need(isinstance(frame, dict) and frame.get('schema') == 'hp_pm_actual_native_before_v1'
         and frame.get('root') == str(ROOT) and frame.get('site') == 'https://holisticpeople.com/'
         and frame.get('environment') == 'production' and frame.get('context') == context
         and frame.get('projection') == projection and frame.get('executionAuthority') is False,
         'Observed native context missing/changed')
    if projection == 'startup':
        closed(frame, ('schema','root','site','environment','context','projection','siteRows',
                       'startup','inventoryCron','optionCallbacks','executionAuthority','disposition'))
        need(frame['disposition'] == 'STARTUP_CONDITIONS_OBSERVED', 'Startup disposition missing/changed')
    need(isinstance(frame.get('siteRows'), list) and len(frame['siteRows']) == 2,
         'Two observed site singleton rows required')
    def option_row(row, name):
        closed(row, ('option_id','option_name','option_value','autoload'))
        need(row_id(row['option_id']) and row['option_name'] == name
             and isinstance(row['option_value'], str) and isinstance(row['autoload'], str), 'Typed observed option row required')
    for row, name in zip(frame['siteRows'], ('home','siteurl')):
        option_row(row, name)
    closed(frame.get('startup'), STARTUP)
    for name, observation in frame['startup'].items():
        closed(observation, ('rows','cachedValue'))
        need(isinstance(observation['rows'], list) and len(observation['rows']) == 1
             and isinstance(observation['cachedValue'], str), 'Observed raw/cached startup shape required')
        option_row(observation['rows'][0], name)
    closed(frame.get('inventoryCron'), INVENTORY_HOOKS)
    for observation in frame['inventoryCron'].values():
        closed(observation, ('state','schedule','args','argumentKey'))
        need(observation['state'] == 'OBSERVED_SINGLETON' and isinstance(observation['schedule'], str)
             and bool(observation['schedule']) and observation['args'] == []
             and digest(observation['argumentKey'], 32), 'Observed Inventory startup predicate shape required')
    callbacks(frame.get('optionCallbacks'), OPTION_TAGS)


def result_contract(value):
    need(isinstance(value, dict) and set(value) == {'contract', 'version', 'context', 'state', 'disposition', 'projection'}
         and value['contract'] == 'hp_pm_three_source_observation' and type(value['version']) is int
         and value['version'] == 1 and value['context'] == 'cli_code' and value['state'] == 'COMPLETE'
         and value['disposition'] == 'PRE_FIELD_CLEAR' and isinstance(value['projection'], dict), 'Raw first observation incomplete/wrong typed')
    frame = value['projection']
    need(set(frame) == {'schema', 'root', 'site', 'environment', 'context', 'projection',
         'siteRows', 'startup', 'inventoryCron', 'optionCallbacks', 'executionAuthority',
         'disposition', 'actor', 'erp', 'products', 'policyRows', 'cron', 'actionScheduler'}
         and frame.get('schema') == 'hp_pm_actual_native_before_v1' and frame.get('root') == str(ROOT)
         and frame.get('site') == 'https://holisticpeople.com/' and frame.get('environment') == 'production'
         and frame.get('context') == 'full-native' and frame.get('projection') == 'code-before'
         and frame.get('executionAuthority') is False and frame.get('disposition') == 'PRE_FIELD_CLEAR'
         and frame.get('actor') == {'context': 'full-native-WPCLI', 'id': 7474}
         and type(frame['actor']['id']) is int
         and isinstance(frame.get('products'), dict) and set(frame['products']) == {'43395', '43406', '43457'}
         and isinstance(frame['siteRows'], list) and isinstance(frame['startup'], dict)
         and isinstance(frame['inventoryCron'], dict) and isinstance(frame['optionCallbacks'], dict)
         and isinstance(frame['erp'], dict) and isinstance(frame['policyRows'], list)
         and isinstance(frame['cron'], dict) and isinstance(frame['actionScheduler'], dict),
         'Actual native projection incomplete; no expected snapshot supplied')
    startup_contract(frame, 'full-native', 'code-before')
    closed(frame['erp'], ('callbacks','retired','persist','enabled'))
    need(frame['erp']['retired'] is True and frame['erp']['persist'] is False
         and frame['erp']['enabled'] is False, 'Typed effective ERP admission observation required')
    callbacks(frame['erp']['callbacks'], ERP_TAGS)
    for product in frame['products'].values():
        closed(product, ('postSHA256','sourceValueAndPointerRows','sourceValueAndPointerSHA256',
                         'journalRows','pendingBoundaryAndLeaseRows'))
        need(digest(product['postSHA256']) and digest(product['sourceValueAndPointerSHA256'])
             and type(product['sourceValueAndPointerRows']) is int and 1 <= product['sourceValueAndPointerRows'] <= 4096
             and product['journalRows'] == [] and product['pendingBoundaryAndLeaseRows'] == [], 'Complete finite product observation required')
    need(len(frame['policyRows']) <= 4, 'Finite selected policy observation required')
    names = []
    for row in frame['policyRows']:
        closed(row, ('option_id','option_name','autoload','bytes','rawSHA256'))
        need(row_id(row['option_id']) and row['option_name'] in POLICY_KEYS
             and isinstance(row['autoload'], str) and type(row['bytes']) is int and row['bytes'] >= 0
             and digest(row['rawSHA256']), 'Typed policy row observation required')
        names.append(row['option_name'])
    need(len(set(names)) == len(names), 'Duplicate selected policy observation')
    cron = frame['cron']; closed(cron, ('state','autoload','selectedOwnerEvents'))
    need(cron['state'] in ('ABSENT','OBSERVED') and isinstance(cron['selectedOwnerEvents'], list)
         and len(cron['selectedOwnerEvents']) <= 4096, 'Observed cron state/list required')
    need((cron['state'] == 'ABSENT' and cron['autoload'] is None and cron['selectedOwnerEvents'] == [])
         or (cron['state'] == 'OBSERVED' and isinstance(cron['autoload'], str)), 'Cron absence and observed value must remain distinct')
    for event in cron['selectedOwnerEvents']:
        closed(event, ('time','hook','key','event'))
        need(row_id(event['time']) and event['hook'] in JOBS and digest(event['key'],32), 'Typed owner cron identity required')
        body=event['event']
        need(isinstance(body,dict) and {'args','schedule'} <= set(body) <= {'args','schedule','interval'}
             and isinstance(body['args'],list) and (body['schedule'] is False or isinstance(body['schedule'],str))
             and ('interval' not in body or (type(body['interval']) is int and body['interval'] > 0)), 'Typed owner cron body required')
    actions = frame['actionScheduler']; closed(actions, ('tableExists','selectedOwnerActions'))
    need(type(actions['tableExists']) is bool, 'Observed ActionScheduler presence must be boolean')
    if actions['tableExists']:
        need(isinstance(actions['selectedOwnerActions'], list) and len(actions['selectedOwnerActions']) <= 4096,
             'Observed ActionScheduler action list required')
        for action in actions['selectedOwnerActions']:
            closed(action, ('action_id','hook','status','argsSHA256'))
            need(row_id(action['action_id']) and action['hook'] in JOBS
                 and action['status'] in ('pending','in-progress') and digest(action['argsSHA256']), 'Typed owner action observation required')
    else:
        need(actions['selectedOwnerActions'] is None, 'Absent ActionScheduler is null, not observed empty')


def perform(raw, expected_sha):
    need(re.fullmatch('[a-f0-9]{64}', expected_sha) and sha(raw) == expected_sha, 'Original selected bytes changed')
    selection = decoded(raw)
    directory, resource = validate(selection)
    source = {}
    for name, digest in PHP.items():
        data, _row = capture(HERE / name, private=True)
        need(sha(data) == digest, 'Reviewed PM/shim source changed')
        source[name] = data
    pair, phase, process = load_kit()
    files = pair.PairFiles(ROOT, slug='products-manager', theme_slug=None)
    absent_owner(files)
    outcome = None
    with files.phase_lock(create=False):
        absent_owner(files)
        source_pins(selection['sourcePins'])
        directory.mkdir(mode=0o700)
        pair.sync_directory(directory.parent)
        process.durable(directory / 'selection.original.json', raw)
        process.durable(directory / 'resource.original.raw', resource)
        process.durable(directory / 'intent.json', encoded({'operation': selection['operation'],
            'attempt': selection['attempt'], 'outcome': 'UNKNOWN', 'executionAuthority': False,
            'selectionSHA256': expected_sha, 'ownerPID': os.getpid(),
            'lockIdentity': [os.fstat(files._lock_fd).st_dev, os.fstat(files._lock_fd).st_ino]}))
        invocation = directory / 'native'
        invocation.mkdir(mode=0o700)
        pair.sync_directory(directory)
        config = {'schema': 'hp_pm_preinit_config_v1', 'guardSHA256': PHP['pm-preinit-guard.php'],
                  'readerFile': str(HERE / 'pm-native-before-reader.php'),
                  'readerSHA256': PHP['pm-native-before-reader.php'], 'request': selection['nativeRequest']}
        config_raw = encoded(config)  # PM guard requires no final newline.
        config_path = invocation / 'config.json'
        process.durable(config_path, config_raw)
        env = dict(os.environ, PM_NATIVE_CONFIG=str(config_path), PM_NATIVE_CONFIG_SHA256=sha(config_raw))
        argv = [selection['wpCli']['path'], '--path=' + str(ROOT), '--user=7474',
                '--require=' + str(HERE / 'pm-preinit-guard.php'), '--no-color', 'eval-file',
                str(HERE / 'first-observer-kit/hp-deploy-native-read.php')]
        try:
            need(selection['deadlineUTC'] - time.time() >= 45, 'Original native start window exhausted')
            files.require_lock()
            raw_result = phase.observation_process(files, invocation, argv, env=env, timeout=45)
            need(type(raw_result.returncode) is int and raw_result.returncode == 0 and not raw_result.stderr,
                 'Actual process outcome unqualified; original raw retained')
            start = decoded(capture(invocation / 'preinit-readback.json', private=True)[0])
            startup_contract(start, 'pre-init', 'startup')
            value = decoded(raw_result.stdout)
            result_contract(value)
            source_pins(selection['sourcePins'])
            absent_owner(files)
            files.require_lock()
            for name, data in source.items():
                need(capture(HERE / name, private=True)[0] == data, 'Private native source changed during invocation')
            observed = lifetime(invocation)
            need(observed['childExitObserved'] and observed['childReturnCode'] == 0, 'Actual child exit not observed')
            outcome = {'state': 'FIRST_OBSERVATION_COMPLETE', **observed, 'projectionSHA256': sha(raw_result.stdout)}
        except BaseException as error:
            process.durable(directory / 'original-wrapper-error.json', encoded({
                'type': type(error).__name__, 'message': str(error), 'trace': traceback.format_exc(), 'outcome': 'UNKNOWN'}))
            observed = lifetime(invocation)
            outcome = {'state': 'UNKNOWN_HOLD' if observed['childExitObserved'] else 'CHILD_LIFETIME_UNKNOWN', **observed}
        outcome.update(executionAuthority=False, retryAuthorized=False, nextOperationAuthorized=False,
                       resourceClearance=False, ownershipRetained='NOT_ESTABLISHED_BY_WRAPPER',
                       resourceDisposition='EXTERNAL_EXACT_ATTEMPT_RECONCILIATION_REQUIRED')
        process.durable(directory / 'result.json', encoded(outcome))
    # Closing this wrapper's FD establishes neither child exit nor external clearance.
    return outcome


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--selection', required=True)
    parser.add_argument('--selection-sha256', required=True)
    args = parser.parse_args()
    need(sys.flags.isolated and sys.dont_write_bytecode, 'Invoke isolated Python -I -B')
    raw = capture(Path(args.selection), private=True)[0]
    result = perform(raw, args.selection_sha256)
    print(json.dumps(result, sort_keys=True))
    return 0 if result['state'] == 'FIRST_OBSERVATION_COMPLETE' else 78


if __name__ == '__main__':
    raise SystemExit(main())

<?php
/**
 * Private finite native reader factory. No I/O, callbacks or hooks on import.
 * Designed to be compiled from exact captured bytes by the private guard loader.
 * No __FILE__, relative include, provider registration or caller success flag.
 */
declare(strict_types=1);

return static function (array $request, $phaseHandle, ?array $transportContext = null): array {
    $need = static function (bool $ok, string $message): void {
        if (!$ok) { throw new RuntimeException($message); }
    };
    $envelope = $transportContext !== null;
    if ($envelope) {
        $keys = array_keys($request); sort($keys);
        $closed = ['operation','slug','action','contentRoot','siteOrigin','source','nativeScope']; sort($closed);
        $need($keys === $closed && is_string($request['operation'])
            && preg_match('/^[a-f0-9]{32}$/D', $request['operation']) === 1
            && in_array($request['slug'], ['products-manager','hp-catalog','hp-zen'], true)
            && in_array($request['action'], ['stage-only','swap-qualified-staged','swap','rollback','invalidate'], true)
            && $request['contentRoot'] === '/www/holisticpeoplecom_349/public/wp-content'
            && $request['siteOrigin'] === 'https://holisticpeople.com', 'Closed finite MU/CLI owner scope differs');
        $need(is_array($request['source']) && $request['source'] !== [], 'Reviewed actual caller source identity required');
        $keys = array_keys($transportContext); sort($keys);
        $need($keys === ['actor','entry','wordpressActorAssumed']
            && in_array($transportContext['entry'], ['mu_early','cli_code'], true)
            && $transportContext['actor'] === 'os_owned_code_token'
            && $transportContext['wordpressActorAssumed'] === false, 'Unknown actual code context');
        $need(is_array($phaseHandle), 'Actual inherited phase handle descriptor required');
        $keys = array_keys($phaseHandle); sort($keys);
        $need($keys === ['device','inode','ownerPID','path','readerPID','stream'] && is_resource($phaseHandle['stream'])
            && is_int($phaseHandle['ownerPID']) && $phaseHandle['ownerPID'] > 0
            && is_int($phaseHandle['readerPID']) && $phaseHandle['readerPID'] === getmypid(), 'Closed actual physical phase descriptor required');
        $physical = fstat($phaseHandle['stream']);
        $need($physical !== false && $physical['dev'] === $phaseHandle['device']
            && $physical['ino'] === $phaseHandle['inode'], 'Phase descriptor metadata is not its actual resource');
        if ($transportContext['entry'] === 'mu_early') {
            $need(PHP_SAPI !== 'cli' && getmypid() === $phaseHandle['ownerPID'], 'Early MU process identity unavailable/different');
            $context = 'early-mu';
        } else {
            $need(PHP_SAPI === 'cli' && defined('WP_CLI') && WP_CLI === true
                && function_exists('posix_getppid') && posix_getppid() === $phaseHandle['ownerPID'], 'Actual CLI child/parent phase lifetime differs');
            $context = 'full-native';
        }
        $scope = $request['nativeScope']; $need(is_array($scope), 'Finite native scope object required');
        $keys = array_keys($scope); sort($keys);
        $need($keys === ['erpCallbackSourcePins','optionCallbackSourcePins','productIds','schema']
            && $scope['schema'] === 'hp_pm_three_source_observation_scope_v1' && $scope['productIds'] === [43395,43406,43457], 'Closed native source scope differs');
        $need($phaseHandle['path'] === '/www/holisticpeoplecom_349/public/wp-content/hp-deploy/selected-phase.lock', 'Actual descriptor path differs');
        $phaseHandle = $phaseHandle['stream'];
        // Callback pins are source-dispatch safety scope, never expected native
        // product rows/values. No selected.nativeBefore enters this callable.
        $request = ['schema'=>'hp_pm_actual_native_reader_observation_v1','root'=>'/www/holisticpeoplecom_349/public',
            'site'=>'https://holisticpeople.com/','environment'=>'production','context'=>$context,'projection'=>'code-before',
            'productIds'=>$scope['productIds'],'phaseFile'=>'/www/holisticpeoplecom_349/public/wp-content/hp-deploy/selected-phase.lock',
            'startup'=>['hp_core_version'=>'3.4.24','hp_inventory_schema_version'=>'0.22.0','hp_inventory_product_manager_erp_migrated'=>'yes'],
            'allowedOptionCallbacks'=>$scope['optionCallbackSourcePins'],'allowedErpCallbacks'=>$scope['erpCallbackSourcePins']];
    }
    $canonical = static function ($value) use (&$canonical): string {
        $sort = static function ($v) use (&$sort) {
            if (!is_array($v)) { return $v; }
            if (!array_is_list($v)) { ksort($v, SORT_STRING); }
            foreach ($v as $k => $item) { $v[$k] = $sort($item); }
            return $v;
        };
        return json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    };
    $keys = array_keys($request); sort($keys);
    $wanted = ['schema','root','site','environment','context','projection','productIds',
        'phaseFile','startup','allowedOptionCallbacks','allowedErpCallbacks']; sort($wanted);
    $need($keys === $wanted && $request['schema'] === 'hp_pm_actual_native_reader_observation_v1', 'Closed native reader request required');
    $root = '/www/holisticpeoplecom_349/public';
    $need($request['root'] === $root && $request['site'] === 'https://holisticpeople.com/'
        && $request['environment'] === 'production', 'Finite selected site/root required');
    $need(defined('ABSPATH') && rtrim(ABSPATH, '/') === $root
        && function_exists('wp_get_environment_type') && wp_get_environment_type() === 'production',
        'Actual WordPress root/environment unavailable or different');
    $need(in_array($request['context'], ['early-mu','pre-init','full-native'], true)
        && in_array($request['projection'], ['startup','code-before'], true)
        && $request['productIds'] === [43395,43406,43457], 'Finite context/product scope required');
    $need($request['context'] !== 'full-native' || $request['projection'] === 'code-before', 'Full native observer requires complete finite projection');

    // A separate real observation resource, never the guard's owner OFD. The
    // qualified caller retains the exclusive owner privately. A failed probe
    // plus actual inode and qualified caller context is observational evidence;
    // this library never unlocks or closes either caller resource.
    $need($request['phaseFile'] === $root . '/wp-content/hp-deploy/selected-phase.lock'
        && is_resource($phaseHandle) && get_resource_type($phaseHandle) === 'stream', 'Actual phase resource required');
    $path = $request['phaseFile'];
    $before = fstat($phaseHandle); $named = lstat($path);
    $need(function_exists('posix_geteuid'), 'Actual process UID method unavailable');
    $uid = posix_geteuid();
    $need($before !== false && $named !== false && !is_link($path) && realpath($path) === $path
        && ($before['mode'] & 0170000) === 0100000 && ($before['mode'] & 0777) === 0600
        && $before['uid'] === $uid && $before['nlink'] === 1
        && $before['dev'] === $named['dev'] && $before['ino'] === $named['ino'], 'Phase resource identity/mode/owner drift');
    // Exclusive ownership is established by the independently reviewed caller
    // holding its PRIVATE owner description, not by this observation resource.
    // This library never attempts an acquisition, close, or unlock.

    global $wpdb, $wp_filter;
    $need(is_object($wpdb) && is_callable([$wpdb, 'get_results']) && is_callable([$wpdb, 'prepare'])
        && is_callable([$wpdb, 'esc_like']), 'Actual native wpdb read channel required');
    foreach (['options','posts','postmeta','term_relationships'] as $table) {
        $need(is_string($wpdb->$table ?? null) && preg_match('/^[A-Za-z0-9_]+$/D', $wpdb->$table) === 1,
            'Unsafe native table identifier');
    }
    $select = static function (string $sql, array $args = []) use ($wpdb, $need): array {
        $need(str_starts_with($sql, 'SELECT '), 'Reader permits SELECT only');
        $rows = $wpdb->get_results($args ? $wpdb->prepare($sql, ...$args) : $sql, ARRAY_A);
        $need($wpdb->last_error === '' && is_array($rows) && count($rows) <= 4096, 'Native read failed/unbounded');
        return $rows;
    };
    $callbacks = static function (array $tags) use (&$wp_filter, $need): array {
        $result = [];
        foreach ($tags as $tag) {
            $result[$tag] = [];
            foreach (($wp_filter[$tag]->callbacks ?? []) as $priority => $items) {
                foreach ($items as $row) {
                    $fn = $row['function'];
                    // Only this same private source-verified process guard's
                    // installed unchanged-value callbacks are excluded. No
                    // arbitrary caller registration or success predicate.
                    if (class_exists('HP_PM_Actual_Preinit_Guard', false)
                        && HP_PM_Actual_Preinit_Guard::owns_callback($fn, $tag)) { continue; }
                    if (is_array($fn) && count($fn) === 2) {
                        $reflection = new ReflectionMethod($fn[0], $fn[1]);
                        $name = (is_object($fn[0]) ? get_class($fn[0]) : $fn[0]) . '::' . $fn[1];
                    } elseif (is_string($fn) && str_contains($fn, '::')) {
                        [$class,$method] = explode('::',$fn,2);
                        $reflection = new ReflectionMethod($class,$method); $name = $class . '::' . $method;
                    } elseif ($fn instanceof Closure || is_string($fn)) {
                        $reflection = new ReflectionFunction($fn); $name = $fn instanceof Closure ? 'Closure' : $fn;
                    } elseif (is_object($fn) && is_callable($fn)) {
                        $reflection = new ReflectionMethod($fn, '__invoke'); $name = get_class($fn) . '::__invoke';
                    } else { throw new RuntimeException('Unattributable selected callback'); }
                    $file = $reflection->getFileName();
                    $need($file === false || (is_file($file) && !is_link($file) && realpath($file) === $file),
                        'Selected callback source unavailable');
                    $result[$tag][] = ['priority'=>(int)$priority,'acceptedArgs'=>(int)$row['accepted_args'],
                        'callback'=>$name,'file'=>$file ?: null,'startLine'=>$reflection->getStartLine(),
                        'endLine'=>$reflection->getEndLine(),'sourceSHA256'=>$file ? hash_file('sha256',$file) : null];
                }
            }
        }
        return $result;
    };

    $startup = ['hp_core_version'=>'3.4.24','hp_inventory_schema_version'=>'0.22.0',
        'hp_inventory_product_manager_erp_migrated'=>'yes'];
    $need($canonical($request['startup']) === $canonical($startup), 'This finite source has different startup expectations');
    $optionTags = ['pre_option','pre_option_cron','option_cron','default_option_cron',
        'pre_get_scheduled_event','get_scheduled_event','wp_next_scheduled'];
    foreach (array_keys($startup) as $key) {
        $optionTags[] = 'pre_option_' . $key;
        $optionTags[] = 'option_' . $key;
        $optionTags[] = 'default_option_' . $key;
    }
    $actualOptionCallbacks = $callbacks($optionTags);
    $need($canonical($actualOptionCallbacks) === $canonical($request['allowedOptionCallbacks'][$request['context']] ?? null), 'Selected option callback set/source changed before getter dispatch');
    $need(function_exists('get_option'), 'Native option/cache view unavailable');
    $startupFrame = [];
    foreach ($startup as $key => $expected) {
        $sql = "SELECT option_id,option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name=%s ORDER BY option_id";
        $rows = $select($sql, [$key]);
        $need(count($rows) === 1 && $rows[0]['option_value'] === $expected, 'Persisted startup condition differs: ' . $key);
        $cached = get_option($key, null);
        $need(is_string($cached) && $cached === $expected, 'Actual get_option/cache startup condition differs: ' . $key);
        $need($select($sql, [$key]) === $rows, 'Startup persisted state changed during getter: ' . $key);
        $startupFrame[$key] = ['rows'=>$rows,'cachedValue'=>$cached];
    }
    // Known canonical Inventory register branches only. An absent/wrong event
    // is a selected startup effect requiring owner disposition, never repaired.
    $schedules = [
        'hp_inventory_fulfillment_intake'=>'hp_fulfillment_five_minutes',
        'hp_inventory_demand_refresh'=>'daily',
        'hp_inventory_combine_return_scan'=>'hourly',
        'hp_inventory_ss_v2_shadow_audit_tick'=>'hourly',
        'hp_inventory_shipstation_inbound_reconcile'=>'hp_inventory_ten_minutes',
        'hp_inventory_expiration_scan'=>'daily',
        'hp_inventory_fulfillment_jobs'=>'hp_fulfillment_five_minutes',
        'hp_inventory_fulfillment_review'=>'daily',
        'hp_inventory_dropship_tracking_reconcile'=>'hourly',
        'hp_inventory_parcel_tick'=>'hp_inventory_parcel_five_minutes',
        'hp_inventory_order_status_audit_v1'=>'hp_fulfillment_five_minutes',
        'hp_inventory_ss_health_check'=>'hp_inventory_fifteen_minutes',
    ];
    $cronSource = $select("SELECT option_id,option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name='cron' ORDER BY option_id");
    $need(count($cronSource) === 1, 'Startup cron singleton unavailable/ambiguous');
    $persistedCron = unserialize($cronSource[0]['option_value'], ['allowed_classes'=>false]);
    $cachedCron = get_option('cron', null);
    $need(is_array($persistedCron) && is_array($cachedCron)
        && $canonical($persistedCron) === $canonical($cachedCron), 'Actual cron option/cache view differs before Inventory startup');
    $inventoryCron = [];
    foreach ($schedules as $hook => $schedule) {
        $events = [];
        foreach ($cachedCron as $time => $hooks) {
            if ($time === 'version') { continue; }
            $need(is_array($hooks), 'Malformed startup cron timestamp');
            foreach (($hooks[$hook] ?? []) as $key => $event) {
                $need(is_array($event) && is_array($event['args'] ?? null), 'Malformed Inventory startup event');
                if ($event['args'] === []) {
                    $need($key === md5(serialize([])), 'Inventory startup event argument key differs: ' . $hook);
                    $need(($event['schedule'] ?? null) === $schedule && ctype_digit((string)$time)
                        && (int)$time > 0, 'Inventory startup schedule differs: ' . $hook);
                    $events[] = ['time'=>$time,'key'=>$key,'event'=>$event];
                }
            }
        }
        $need(count($events) === 1, 'Inventory startup event absent/ambiguous: ' . $hook);
        // Only startup predicates enter continuity; an ambient business worker
        // advancing its existing timestamp is not a platform-quiet requirement.
        $inventoryCron[$hook] = ['state'=>'OBSERVED_SINGLETON','schedule'=>$schedule,
            'args'=>[],'argumentKey'=>md5(serialize([]))];
    }
    $need($select("SELECT option_id,option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name='cron' ORDER BY option_id") === $cronSource,
        'Persisted cron changed during startup getter');
    $siteRows = $select("SELECT option_id,option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name IN ('home','siteurl') ORDER BY option_name,option_id");
    $need(count($siteRows) === 2 && array_column($siteRows, 'option_name') === ['home','siteurl']
        && array_column($siteRows, 'option_value') === ['https://holisticpeople.com','https://holisticpeople.com'], 'Actual site singleton rows differ');
    $frame = ['schema'=>'hp_pm_actual_native_before_v1','root'=>$root,'site'=>$request['site'],
        'environment'=>'production','context'=>$request['context'],'projection'=>$request['projection'],
        'siteRows'=>$siteRows,'startup'=>$startupFrame,'inventoryCron'=>$inventoryCron,'optionCallbacks'=>$actualOptionCallbacks,
        'executionAuthority'=>false,'disposition'=>'STARTUP_CONDITIONS_OBSERVED'];
    if ($request['projection'] === 'startup') {
        $after = fstat($phaseHandle); $named = lstat($path);
        $need($after !== false && $named !== false && $after['dev'] === $before['dev']
            && $after['ino'] === $before['ino'] && $named['dev'] === $before['dev']
            && $named['ino'] === $before['ino'] && $after['nlink'] === 1, 'Phase resource changed during startup read');
        return $frame;
    }

    if ($request['context'] === 'full-native') {
        $need(defined('WP_CLI') && WP_CLI === true && function_exists('get_current_user_id')
            && get_current_user_id() === 7474 && function_exists('current_user_can'), 'Actual full native actor7474 required');
        foreach (['manage_options','manage_woocommerce','edit_products'] as $cap) {
            $need(current_user_can($cap), 'Actual capability absent: ' . $cap);
        }
        foreach ($request['productIds'] as $id) { $need(current_user_can('edit_post', $id), 'Exact product edit capability absent'); }
        $tags = ['hp_pm_erp_retired_by_hp_inventory','hp_pm_erp_persist_enabled','hp_pm_erp_enabled'];
        $actual = $callbacks($tags);
        $need($canonical($actual) === $canonical($request['allowedErpCallbacks']), 'Actual ERP admission callback set/source differs before dispatch');
        $retired = (bool)apply_filters($tags[0], get_option('hp_inventory_product_manager_erp_migrated') === 'yes');
        $persist = $retired ? false : (bool)apply_filters($tags[1], true);
        $enabled = !$retired && (bool)apply_filters($tags[2], true);
        $need($retired && !$persist && !$enabled, 'Actual effective ERP retirement is not qualified');
        foreach ($startupFrame as $key => $observed) {
            $need($select("SELECT option_id,option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name=%s ORDER BY option_id", [$key]) === $observed['rows'],
                'Actual ERP dispatch changed startup raw policy: ' . $key);
        }
        $frame['actor'] = ['context'=>'full-native-WPCLI','id'=>7474];
        $frame['erp'] = ['callbacks'=>$actual,'retired'=>$retired,'persist'=>$persist,'enabled'=>$enabled];
    } else {
        $frame['codeActor'] = ['context'=>'os-owned-physical-phase','wordpressActorAssumed'=>false];
    }
    $frame['products'] = [];
    $sourceFields = ['slogan','description_long','ingredients','ingredients_other','how_to_use','cautions',
        'application_methods','oil_preparation','natural_constituents'];
    $sourceKeys = ['_sku'];
    foreach ($sourceFields as $name) { $sourceKeys[] = $name; $sourceKeys[] = '_' . $name; }
    $skus = [43395=>'DOT-LAV15',43406=>'DOT-ORE15',43457=>'DOT-OG15'];
    foreach ($request['productIds'] as $id) {
        $posts = $select("SELECT ID,post_type,post_status,post_name,post_password FROM {$wpdb->posts} WHERE ID=%d ORDER BY ID", [$id]);
        $need(count($posts) === 1 && $posts[0]['post_type'] === 'product'
            && $posts[0]['post_status'] === 'publish' && $posts[0]['post_password'] === '', 'Selected native product is unavailable');
        // CODE-before-FIELD depends on source rows/pointers and operation state,
        // not a quiet stock/order/price namespace. Full FIELD protected frames
        // are owned separately by the native import operator at action time.
        $placeholders = implode(',', array_fill(0, count($sourceKeys), '%s'));
        $meta = $select("SELECT meta_id,post_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key IN ({$placeholders}) ORDER BY meta_id", [$id, ...$sourceKeys]);
        $sku = array_values(array_filter($meta, static fn(array $row): bool => $row['meta_key'] === '_sku'));
        $need(count($sku) === 1 && $sku[0]['meta_value'] === $skus[$id], 'Selected SKU singleton differs');
        $like = $wpdb->esc_like('hp_pm_reviewed_import_' . $id . '_') . '%';
        $journals = $select("SELECT option_id,option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name,option_id", [$like]);
        $locks = ['hp_catalog_source_update_' . $id, 'hp_catalog_ingredient_index_product_' . $id,
            'hp_catalog_categorization_lock_' . $id, 'hp_catalog_semantic_review_lock_' . hash('sha256', serialize(['product',$id]))];
        $pending = $select("SELECT option_id,option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name IN (%s,%s,%s,%s) ORDER BY option_name,option_id", $locks);
        // This reader is for CODE-before-FIELD, not a downgrade of completed or
        // in-flight native data. No old/sealed product is in this finite scope.
        $need($journals === [] && $pending === [], 'FIELD/source lifecycle exists; inspect native owner before code recovery');
        $frame['products'][(string)$id] = ['postSHA256'=>hash('sha256',$canonical($posts)),
            'sourceValueAndPointerRows'=>count($meta),'sourceValueAndPointerSHA256'=>hash('sha256',$canonical($meta)),
            'journalRows'=>[],'pendingBoundaryAndLeaseRows'=>[]];
    }
    $policyKeys = ['active_plugins','hp_catalog_ingredient_lifecycle_policy',
        'hp_catalog_product_categorization_policy_v1','hp_catalog_semantic_rescan_policy_v1','cron'];
    $policyRows = $select("SELECT option_id,option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name IN (%s,%s,%s,%s,%s) ORDER BY option_name,option_id", $policyKeys);
    $frame['policyRows'] = array_map(static fn(array $row): array => [
        'option_id'=>$row['option_id'],'option_name'=>$row['option_name'],'autoload'=>$row['autoload'],
        'bytes'=>strlen($row['option_value']),'rawSHA256'=>hash('sha256',$row['option_value'])],
        array_values(array_filter($policyRows, static fn(array $row): bool => $row['option_name'] !== 'cron')));
    foreach (array_slice($policyKeys, 0, 4) as $key) {
        $need(count(array_filter($policyRows, static fn(array $row): bool => $row['option_name'] === $key)) <= 1,
            'Ambiguous selected policy singleton: ' . $key);
    }
    $cronRows = array_values(array_filter($policyRows, static fn(array $row): bool => $row['option_name'] === 'cron'));
    $need(count($cronRows) <= 1, 'Ambiguous native cron singleton');
    $jobs = ['hp_catalog_index_product_ingredients','hp_catalog_categorize_product_v1',
        'hp_catalog_semantic_source_review_v1','hp_catalog_categorization_sweep_v1',
        'hp_catalog_ingredient_index_recovery'];
    $selectedArgs = static function (string $hook, array $args) use ($request, $need): bool {
        $need(array_is_list($args), 'Owner job requires positional native arguments');
        if ($hook === 'hp_catalog_ingredient_index_recovery') {
            $need($args === [] || (count($args) === 1 && is_int($args[0]) && $args[0] >= 0), 'Malformed ingredient recovery cursor');
            return true;
        }
        if ($hook === 'hp_catalog_categorization_sweep_v1') {
            $need((count($args) === 1 && is_int($args[0]) && $args[0] >= 0)
                || (count($args) === 2 && is_int($args[0]) && $args[0] >= 0 && $args[1] === 'semantic'), 'Malformed owner sweep cursor');
            return true;
        }
        if ($hook === 'hp_catalog_index_product_ingredients') {
            $need(count($args) === 2 && is_int($args[0]) && $args[0] > 0
                && is_int($args[1]) && $args[1] >= 0, 'Malformed ingredient job generation');
            return in_array($args[0], $request['productIds'], true);
        }
        if ($hook === 'hp_catalog_categorize_product_v1') {
            $need(count($args) === 1 && is_int($args[0]) && $args[0] > 0, 'Malformed categorization product');
            return in_array($args[0], $request['productIds'], true);
        }
        if ($hook === 'hp_catalog_semantic_source_review_v1') {
            $need(count($args) === 2 && in_array($args[0], ['product','media'], true)
                && is_int($args[1]) && $args[1] > 0, 'Malformed semantic source job');
            return $args[0] === 'product' && in_array($args[1], $request['productIds'], true);
        }
        throw new RuntimeException('Unqualified Catalog owner job hook');
    };
    $events = [];
    if ($cronRows !== []) {
        $cron = unserialize($cronRows[0]['option_value'], ['allowed_classes'=>false]);
        $need(is_array($cron), 'Malformed cron value; absence is not zero');
        foreach ($cron as $time => $hooks) {
            if ($time === 'version') { continue; }
            $need(is_array($hooks), 'Malformed cron timestamp');
            foreach ($jobs as $hook) {
                foreach (($hooks[$hook] ?? []) as $eventKey => $event) {
                    $need(is_array($event) && is_array($event['args'] ?? null), 'Malformed Catalog cron event');
                    if ($selectedArgs($hook, $event['args'])) {
                        $events[] = ['time'=>$time,'hook'=>$hook,'key'=>$eventKey,'event'=>$event];
                    }
                }
            }
        }
    }
    // No unrelated site-wide cron hash enters the critical comparison. Owner
    // sweep events are relevant and retained; unrelated hooks stay outside scope.
    $frame['cron'] = ['state'=>$cronRows === [] ? 'ABSENT' : 'OBSERVED',
        'autoload'=>$cronRows === [] ? null : $cronRows[0]['autoload'],'selectedOwnerEvents'=>$events];
    $need(is_string($wpdb->prefix ?? null) && preg_match('/^[A-Za-z0-9_]+$/D', $wpdb->prefix) === 1,
        'Actual native prefix unavailable');
    $asTable = $wpdb->prefix . 'actionscheduler_actions';
    $exists = $select('SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=%s', [$asTable]);
    $need(count($exists) === 1 && in_array($exists[0]['total'] ?? null, ['0','1',0,1], true), 'ActionScheduler table presence unknown');
    $present = (string)$exists[0]['total'] === '1'; $actions = null;
    if ($present) {
        $rows = $select("SELECT action_id,hook,status,args FROM {$asTable} WHERE hook IN (%s,%s,%s,%s,%s) AND status IN ('pending','in-progress') ORDER BY action_id LIMIT 4097", $jobs);
        $actions = [];
        foreach ($rows as $row) {
            $args = json_decode($row['args'], true, 32, JSON_THROW_ON_ERROR);
            $need(is_array($args), 'Malformed ActionScheduler owner arguments');
            if ($selectedArgs($row['hook'], $args)) {
                $actions[] = ['action_id'=>$row['action_id'],'hook'=>$row['hook'],'status'=>$row['status'],
                    'argsSHA256'=>hash('sha256',$row['args'])];
            }
        }
    }
    $frame['actionScheduler'] = ['tableExists'=>$present,'selectedOwnerActions'=>$actions];

    $frame['disposition'] = 'PRE_FIELD_CLEAR';
    $after = fstat($phaseHandle); $named = lstat($path);
    $need($after !== false && $named !== false && $after['dev'] === $before['dev']
        && $after['ino'] === $before['ino'] && $named['dev'] === $before['dev']
        && $named['ino'] === $before['ino'] && $after['nlink'] === 1, 'Phase resource changed during actual native read');
    return $envelope ? ['contract'=>'hp_pm_three_source_observation','version'=>1,
        'context'=>$transportContext['entry'],'state'=>'COMPLETE','disposition'=>'PRE_FIELD_CLEAR','projection'=>$frame] : $frame;
};

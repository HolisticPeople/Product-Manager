<?php
/** Private --require entry; normal WordPress load order, finite unchanged-value guards. */
declare(strict_types=1);

final class HP_PM_Actual_Preinit_Guard {
    private static $reader;
    private static $phase;
    private static array $request;
    private static string $directory;
    private static bool $installed = false;
    private const SCHEDULES = [
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
    private const EXPECTED = ['hp_core_version'=>'3.4.24','hp_inventory_schema_version'=>'0.22.0',
        'hp_inventory_product_manager_erp_migrated'=>'yes'];

    private static function need(bool $ok, string $message): void {
        if (!$ok) { throw new RuntimeException($message); }
    }

    private static function private_bytes(string $path): string {
        self::need(str_starts_with($path, '/') && realpath($path) === $path && !is_link($path)
            && !str_starts_with($path, '/www/holisticpeoplecom_349/public/'), 'Canonical outside-public private file required');
        $parent = dirname($path); $dir = lstat($parent); $row = lstat($path);
        self::need(function_exists('posix_geteuid'), 'Actual process UID unavailable');
        $uid = posix_geteuid();
        self::need($dir !== false && ($dir['mode'] & 0170000) === 0040000 && ($dir['mode'] & 0777) === 0700
            && $dir['uid'] === $uid && $row !== false && ($row['mode'] & 0170000) === 0100000
            && ($row['mode'] & 0777) === 0600 && $row['uid'] === $uid && $row['nlink'] === 1
            && $row['size'] <= 4194304, 'Private file/parent owner mode or size differs');
        $raw = file_get_contents($path); $after = lstat($path);
        self::need(is_string($raw) && strlen($raw) <= 4194304 && $after !== false
            && $row['dev'] === $after['dev'] && $row['ino'] === $after['ino']
            && $row['size'] === $after['size'] && $row['mtime'] === $after['mtime']
            && $row['ctime'] === $after['ctime'] && $after['nlink'] === 1, 'Private source changed during read');
        return $raw;
    }

    public static function install(): void {
        self::need(defined('WP_CLI') && WP_CLI === true && class_exists('WP_CLI', false)
            && is_callable(['WP_CLI','add_wp_hook']), 'Supported WPCLI before-plugin hook loader unavailable');
        $path = (string)getenv('PM_NATIVE_CONFIG'); $hash = (string)getenv('PM_NATIVE_CONFIG_SHA256');
        self::need(preg_match('/^[a-f0-9]{64}$/D', $hash) === 1, 'Exact private configuration pin required');
        $raw = self::private_bytes($path);
        self::need(hash('sha256', $raw) === $hash, 'Private configuration drift');
        $config = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        self::need(is_array($config) && json_encode($config, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) === $raw,
            'Private configuration must be duplicate-free canonical JSON');
        $keys = array_keys($config); sort($keys);
        $expected = ['schema','guardSHA256','readerFile','readerSHA256','request']; sort($expected);
        self::need($keys === $expected && $config['schema'] === 'hp_pm_preinit_config_v1'
            && hash_file('sha256', __FILE__) === $config['guardSHA256'], 'Private guard source/config mismatch');
        $source = self::private_bytes($config['readerFile']);
        self::need(hash('sha256', $source) === $config['readerSHA256']
            && str_starts_with($source, '<?php'), 'Native reader source differs');
        // The authored reader is an import-pure, context-independent factory.
        // Compile the same captured bytes that were hashed, not a mutable reread.
        $reader = eval(substr($source, 5));
        self::need($reader instanceof Closure, 'Pinned reader factory did not return its callable');
        $fd = (string)getenv('PM_NATIVE_PHASE_FD');
        self::need(preg_match('/^[0-9]{1,3}$/D', $fd) === 1 && (int)$fd >= 3, 'Inherited actual phase descriptor required');
        $owner = (string)getenv('PM_NATIVE_OWNER_PID');
        self::need(ctype_digit($owner) && (int)$owner > 0 && function_exists('posix_getppid')
            && posix_getppid() === (int)$owner, 'Actual direct CLI child/owner process differs');
        $phase = fopen('php://fd/' . $fd, 'r+');
        self::need(is_resource($phase), 'Inherited physical phase descriptor unavailable');
        self::$reader = $reader; self::$phase = $phase;
        self::$request = $config['request']; self::$directory = dirname($path);
        // This hook precedes regular plugin loading, including the PM retirement
        // getter. Guard actual option return values on every relevant getter;
        // an after-load observer alone cannot fence Core5/Inventory15 writers.
        WP_CLI::add_wp_hook('muplugins_loaded', [self::class,'before_regular_plugins'], PHP_INT_MIN, 0);
    }

    public static function owns_callback($callback, string $tag): bool {
        if (!self::$installed || !is_array($callback) || count($callback) !== 2 || $callback[0] !== self::class) { return false; }
        return ($tag === 'pre_option' && $callback[1] === 'pre_option')
            || (str_starts_with($tag, 'option_') && $callback[1] === 'option_value')
            || (str_starts_with($tag, 'default_option_') && $callback[1] === 'option_value')
            || ($tag === 'pre_get_scheduled_event' && $callback[1] === 'pre_event')
            || ($tag === 'get_scheduled_event' && $callback[1] === 'event')
            || ($tag === 'wp_next_scheduled' && $callback[1] === 'next_event');
    }

    private static function last_on(string $tag, string $method): void {
        global $wp_filter;
        $rows = $wp_filter[$tag]->callbacks[PHP_INT_MAX] ?? [];
        $last = $rows === [] ? null : end($rows);
        self::need(is_array($last) && ($last['function'] ?? null) === [self::class,$method],
            'Finite option guard is no longer last: ' . $tag);
    }

    private static function exact_value(string $option, $value): void {
        global $wpdb;
        self::need($option === 'cron' || array_key_exists($option, self::EXPECTED), 'Unexpected guarded option');
        self::need(isset($wpdb->options) && preg_match('/^[A-Za-z0-9_]+$/D', $wpdb->options) === 1, 'Native options table unavailable');
        $sql = $wpdb->prepare("SELECT option_id,option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name=%s ORDER BY option_id", $option);
        $before = $wpdb->get_results($sql, ARRAY_A);
        self::need($wpdb->last_error === '' && is_array($before) && count($before) === 1, 'Finite startup option singleton absent/ambiguous: ' . $option);
        if ($option === 'cron') {
            $raw = unserialize($before[0]['option_value'], ['allowed_classes'=>false]);
            self::need(is_array($raw) && is_array($value) && serialize($raw) === serialize($value), 'Finite startup raw/get_option cron mismatch');
            foreach (self::SCHEDULES as $hook => $schedule) {
                $found = [];
                foreach ($value as $time => $hooks) {
                    if ($time === 'version') { continue; }
                    self::need(is_array($hooks), 'Malformed cron timestamp before Inventory startup');
                    foreach (($hooks[$hook] ?? []) as $eventKey => $event) {
                        self::need(is_array($event) && is_array($event['args'] ?? null), 'Malformed Inventory cron entry');
                        if ($event['args'] === []) {
                            self::need($eventKey === md5(serialize([])), 'Inventory startup event argument key differs: ' . $hook);
                            self::need(($event['schedule'] ?? null) === $schedule && ctype_digit((string)$time) && (int)$time > 0,
                                'Inventory startup schedule differs: ' . $hook);
                            $found[] = $event;
                        }
                    }
                }
                self::need(count($found) === 1, 'Inventory startup event absent/ambiguous: ' . $hook);
            }
        } else {
            self::need($before[0]['option_value'] === self::EXPECTED[$option]
                && is_string($value) && $value === self::EXPECTED[$option], 'Finite startup raw/get_option mismatch: ' . $option);
        }
        self::need($wpdb->get_results($sql, ARRAY_A) === $before && $wpdb->last_error === '', 'Startup option changed during guard');
    }

    public static function pre_option($pre, string $option, $default = false) {
        if ($option !== 'cron' && !array_key_exists($option, self::EXPECTED)) { return $pre; }
        self::last_on('pre_option', 'pre_option');
        if ($pre !== false) { self::exact_value($option, $pre); }
        return $pre; // Never replace/suppress an option value or another callback.
    }

    public static function option_value($value, string $option, $default = false) {
        if ($option !== 'cron' && !array_key_exists($option, self::EXPECTED)) { return $value; }
        $tag = function_exists('current_filter') ? current_filter() : '';
        self::need($tag === 'option_' . $option || $tag === 'default_option_' . $option, 'Unexpected option guard context');
        self::last_on($tag, 'option_value'); self::exact_value($option, $value);
        return $value;
    }

    private static function exact_event($event, string $hook, array $args): void {
        if (!array_key_exists($hook, self::SCHEDULES)) { return; }
        self::need($args === [] && is_object($event) && isset($event->timestamp, $event->schedule)
            && is_int($event->timestamp) && $event->timestamp > 0
            && $event->schedule === self::SCHEDULES[$hook], 'Finite Inventory startup event missing/different: ' . $hook);
    }

    public static function pre_event($pre, string $hook, array $args, $timestamp = null) {
        if (!array_key_exists($hook, self::SCHEDULES)) { return $pre; }
        self::last_on('pre_get_scheduled_event', 'pre_event');
        if ($pre !== null) { self::exact_event($pre, $hook, $args); }
        return $pre;
    }

    public static function event($event, string $hook, array $args, $timestamp = null) {
        if (!array_key_exists($hook, self::SCHEDULES)) { return $event; }
        self::last_on('get_scheduled_event', 'event'); self::exact_event($event, $hook, $args);
        return $event;
    }

    public static function next_event($time, $event, string $hook, array $args) {
        if (!array_key_exists($hook, self::SCHEDULES)) { return $time; }
        self::last_on('wp_next_scheduled', 'next_event'); self::exact_event($event, $hook, $args);
        self::need($time === $event->timestamp, 'Finite Inventory next-event getter changed: ' . $hook);
        return $time;
    }

    public static function before_regular_plugins(): void {
        self::need(!self::$installed && function_exists('add_filter'), 'Actual before-plugin context unavailable or repeated');
        add_filter('pre_option', [self::class,'pre_option'], PHP_INT_MAX, 3);
        foreach ([...array_keys(self::EXPECTED), 'cron'] as $key) {
            add_filter('option_' . $key, [self::class,'option_value'], PHP_INT_MAX, 3);
            add_filter('default_option_' . $key, [self::class,'option_value'], PHP_INT_MAX, 3);
        }
        add_filter('pre_get_scheduled_event', [self::class,'pre_event'], PHP_INT_MAX, 4);
        add_filter('get_scheduled_event', [self::class,'event'], PHP_INT_MAX, 4);
        add_filter('wp_next_scheduled', [self::class,'next_event'], PHP_INT_MAX, 4);
        self::$installed = true;
        $request = self::$request; $request['context'] = 'pre-init'; $request['projection'] = 'startup';
        $frame = (self::$reader)($request, self::$phase);
        self::record('preinit-readback.json', $frame);
    }

    private static function record(string $name, array $frame): void {
        $raw = json_encode($frame, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        self::need(strlen($raw) <= 4194304, 'Bounded private readback required');
        $path = self::$directory . '/' . $name;
        $old = umask(0077);
        try { $stream = fopen($path, 'x+b'); } finally { umask($old); }
        self::need(is_resource($stream), 'Existing/unknown private readback; no retry');
        try {
            self::need((fstat($stream)['mode'] & 0777) === 0600 && fwrite($stream, $raw) === strlen($raw)
                && fflush($stream) && fsync($stream), 'Private startup readback not durable');
        } finally { fclose($stream); }
        $dir = fopen(self::$directory, 'r'); self::need(is_resource($dir), 'Private directory descriptor unavailable');
        try { self::need(fsync($dir), 'Private directory entry not durable'); } finally { fclose($dir); }
    }

    public static function read_code_before(): array {
        self::need(self::$installed && is_file(self::$directory . '/preinit-readback.json'), 'Actual pre-init guard did not complete');
        $request = self::$request; $request['context'] = 'full-native'; $request['projection'] = 'code-before';
        return (self::$reader)($request, self::$phase);
    }
}

HP_PM_Actual_Preinit_Guard::install();

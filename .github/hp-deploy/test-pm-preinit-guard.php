<?php
/** Causal disconnected PHP getter/event/private-record faults. No WP/bootstrap. */
declare(strict_types=1);
$source = file_get_contents(__DIR__ . '/pm-preinit-guard.php');
$footer = "\nHP_PM_Actual_Preinit_Guard::install();";
if (!is_string($source) || !str_ends_with(rtrim($source), trim($footer))) { throw new RuntimeException('Guard fixture source footer differs'); }
// Load the actual authored class without its explicit WPCLI entry dispatch.
eval(substr(substr($source, 0, strrpos($source, $footer)), 5));
define('ARRAY_A', 'ARRAY_A');
$checks = 0;
function check(bool $ok, string $message): void { global $checks; if (!$ok) { throw new RuntimeException($message); } $checks++; }
function refuses(callable $call, string $part): void {
    try { $call(); } catch (RuntimeException $error) { check(str_contains($error->getMessage(), $part), 'Original refusal differs: ' . $error->getMessage()); return; }
    throw new RuntimeException('Expected actual refusal: ' . $part);
}
function current_filter(): string { return $GLOBALS['filterTag']; }
final class ReadOnlyFixtureDB {
    public string $options = 'fixture_options';
    public string $last_error = '';
    public string $value = '0.22.0';
    public int $reads = 0;
    public function prepare(string $sql, ...$args): string { return $sql; }
    public function get_results(string $sql, $format): array {
        check(str_starts_with($sql, 'SELECT '), 'No database setter selected'); $this->reads++;
        return [['option_id'=>'1','option_name'=>'hp_inventory_schema_version','option_value'=>$this->value,'autoload'=>'no']];
    }
}
$wpdb = new ReadOnlyFixtureDB();
function last_callback(string $tag, string $method): void {
    $GLOBALS['wp_filter'][$tag] = (object)['callbacks'=>[PHP_INT_MAX=>['guard'=>['function'=>[HP_PM_Actual_Preinit_Guard::class,$method]]]]];
}
$filterTag = 'option_hp_inventory_schema_version'; last_callback($filterTag,'option_value');
check(HP_PM_Actual_Preinit_Guard::option_value('0.22.0','hp_inventory_schema_version') === '0.22.0', 'Correct actual value preserved unchanged');
refuses(fn()=>HP_PM_Actual_Preinit_Guard::option_value('0.21.0','hp_inventory_schema_version'), 'raw/get_option mismatch');
$wpdb->value = '0.21.0';
refuses(fn()=>HP_PM_Actual_Preinit_Guard::option_value('0.22.0','hp_inventory_schema_version'), 'raw/get_option mismatch');
$wpdb->value = '0.22.0';
$wp_filter[$filterTag]->callbacks[PHP_INT_MAX]['later'] = ['function'=>'later_fixture_callback'];
refuses(fn()=>HP_PM_Actual_Preinit_Guard::option_value('0.22.0','hp_inventory_schema_version'), 'no longer last');
last_callback('pre_option','pre_option');
check(HP_PM_Actual_Preinit_Guard::pre_option(false,'hp_inventory_schema_version') === false, 'False sentinel remains normal getter');
refuses(fn()=>HP_PM_Actual_Preinit_Guard::pre_option('different','hp_inventory_schema_version'), 'raw/get_option mismatch');
check(HP_PM_Actual_Preinit_Guard::pre_option('other','unrelated') === 'other', 'Unrelated option remains outside selected scope');

$hook='hp_inventory_parcel_tick'; $event=(object)['timestamp'=>1900000000,'schedule'=>'hp_inventory_parcel_five_minutes'];
last_callback('get_scheduled_event','event'); last_callback('pre_get_scheduled_event','pre_event'); last_callback('wp_next_scheduled','next_event');
check(HP_PM_Actual_Preinit_Guard::event($event,$hook,[]) === $event, 'Actual valid event object unchanged');
refuses(fn()=>HP_PM_Actual_Preinit_Guard::event(false,$hook,[]), 'startup event missing/different');
refuses(fn()=>HP_PM_Actual_Preinit_Guard::event((object)['timestamp'=>1900000000,'schedule'=>'hourly'],$hook,[]), 'startup event missing/different');
check(HP_PM_Actual_Preinit_Guard::pre_event(null,$hook,[]) === null, 'Default cron lookup remains normal');
refuses(fn()=>HP_PM_Actual_Preinit_Guard::pre_event(false,$hook,[]), 'startup event missing/different');
refuses(fn()=>HP_PM_Actual_Preinit_Guard::next_event(0,$event,$hook,[]), 'next-event getter changed');
check(HP_PM_Actual_Preinit_Guard::next_event($event->timestamp,$event,$hook,[]) === $event->timestamp, 'Valid next timestamp unchanged');
check(HP_PM_Actual_Preinit_Guard::event(false,'unrelated',[]) === false, 'Unrelated cron hook unchanged');

// Exercise actual fopen/exclusive mode/file+directory fsync; the former fchmod
// mistake fails this path before durable publication, not just a string check.
$directory = sys_get_temp_dir() . '/pm-preinit-fixture-' . bin2hex(random_bytes(8));
if (!mkdir($directory,0700)) { throw new RuntimeException('Private fixture directory unavailable'); }
$property = new ReflectionProperty(HP_PM_Actual_Preinit_Guard::class,'directory'); $property->setValue(null,$directory);
$record = new ReflectionMethod(HP_PM_Actual_Preinit_Guard::class,'record');
try {
    $record->invoke(null,'readback.json',['state'=>'actual-private-readback']);
    check((fileperms($directory . '/readback.json') & 0777) === 0600, 'Private readback mode0600');
    $before=file_get_contents($directory . '/readback.json');
    set_error_handler(static function(int $severity,string $message): never { throw new RuntimeException($message); });
    try { refuses(fn()=>$record->invoke(null,'readback.json',['state'=>'replacement']), 'File exists'); }
    finally { restore_error_handler(); }
    check(file_get_contents($directory . '/readback.json') === $before, 'Existing raw readback preserved');
} finally { @unlink($directory . '/readback.json'); rmdir($directory); }
echo json_encode(['state'=>'PASS','checks'=>$checks,'actualWordPressOrTargetCalls'=>0],JSON_THROW_ON_ERROR) . "\n";

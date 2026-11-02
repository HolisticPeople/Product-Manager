<?php
/** Execute actual reader body with local wpdb/WP/filesystem boundary doubles. */
declare(strict_types=1);
namespace PMReaderFixture {
const ROOT='/www/holisticpeoplecom_349/public';
function wp_get_environment_type(): string { return 'production'; }
function get_option(string $key,$default=null) { return $GLOBALS['fixture_options'][$key] ?? $default; }
function get_current_user_id(): int { return 7474; }
function current_user_can(...$args): bool { return true; }
function apply_filters(string $tag,$value) { return $value; }
function realpath(string $path) { return $path === ROOT.'/wp-content/hp-deploy/selected-phase.lock' ? $path : \realpath($path); }
function is_link(string $path): bool { return $path === ROOT.'/wp-content/hp-deploy/selected-phase.lock' ? false : \is_link($path); }
function lstat(string $path) { return $path === ROOT.'/wp-content/hp-deploy/selected-phase.lock' ? \fstat($GLOBALS['fixture_lock']) : \lstat($path); }
function function_exists(string $name): bool { return \function_exists($name) || \function_exists(__NAMESPACE__.'\\'.$name); }
final class DB {
    public string $options='test_options'; public string $posts='test_posts'; public string $postmeta='test_postmeta';
    public string $term_relationships='test_term_relationships'; public string $prefix='test_'; public string $last_error='';
    public array $args=[]; public array $actions=[]; public int $journalProduct=0; public string $journalState=""; public int $queries=0;
    public function prepare(string $sql,...$args): string { $this->args=$args; return $sql; }
    public function esc_like(string $value): string { return addcslashes($value,'_%\\'); }
    public function get_results(string $sql,$format): array {
        $this->queries++; \check(str_starts_with($sql,'SELECT '),'Read body never calls a setter');
        $args=$this->args; $this->args=[];
        if (str_contains($sql,'information_schema.tables')) { return [['total'=>'1']]; }
        if (str_contains($sql,'actionscheduler_actions')) { return $this->actions; }
        if (str_contains($sql,'FROM test_posts')) { return [['ID'=>(string)$args[0],'post_type'=>'product','post_status'=>'publish','post_name'=>'fixture','post_password'=>'']]; }
        if (str_contains($sql,'FROM test_postmeta')) {
            $skus=[43395=>'DOT-LAV15',43406=>'DOT-ORE15',43457=>'DOT-OG15'];
            return [['meta_id'=>'1','post_id'=>(string)$args[0],'meta_key'=>'_sku','meta_value'=>$skus[$args[0]]]];
        }
        if (str_contains($sql,"IN ('home','siteurl')")) { return [$this->row('home','https://holisticpeople.com'),$this->row('siteurl','https://holisticpeople.com')]; }
        if (str_contains($sql,"option_name='cron'")) { return [$this->row('cron',serialize($GLOBALS['fixture_options']['cron']))]; }
        if (str_contains($sql,'WHERE option_name=%s')) { return [$this->row($args[0],$GLOBALS['fixture_options'][$args[0]])]; }
        if (count($args)===5 && $args[0]==='active_plugins') { return [$this->row('cron',serialize($GLOBALS['fixture_options']['cron']))]; }
        if (str_contains($sql,'LIKE %s')) {
            return $this->journalProduct > 0 && str_contains($args[0],(string)$this->journalProduct)
                ? [$this->row('hp_pm_reviewed_import_'.$this->journalProduct.'_fixture',$this->journalState)] : [];
        }
        if (count($args)===4) { return []; }
        throw new \RuntimeException('Fixture refuses unexpected native query: '.$sql);
    }
    private function row(string $key,string $value): array { return ['option_id'=>'1','option_name'=>$key,'option_value'=>$value,'autoload'=>'no']; }
}
}
namespace {
define('ABSPATH',\PMReaderFixture\ROOT.'/'); define('ARRAY_A','ARRAY_A'); define('WP_CLI',true);
$checks=0;
function check(bool $ok,string $why): void { global $checks; if(!$ok){throw new RuntimeException($why);} $checks++; }
function refuses(callable $call,string $part): void { try{$call();}catch(RuntimeException $e){check(str_contains($e->getMessage(),$part),'Original refusal differs: '.$e->getMessage());return;} throw new RuntimeException('Expected refusal: '.$part); }
// Only boundary resolution is namespaced; the full actual reader body executes.
$source=file_get_contents(__DIR__.'/pm-native-before-reader.php');
$source=preg_replace('/^<\?php\s*/','',$source,1);
$source=str_replace('declare(strict_types=1);','',$source);
$reader=eval('declare(strict_types=1); namespace PMReaderFixture; use \RuntimeException; use \Closure; use \ReflectionMethod; use \ReflectionFunction; '.$source);
check($reader instanceof Closure,'Import-pure factory returns actual callable');
$fixture_options=['hp_core_version'=>'3.4.24','hp_inventory_schema_version'=>'0.22.0','hp_inventory_product_manager_erp_migrated'=>'yes'];
$guardSource=file_get_contents(__DIR__.'/pm-preinit-guard.php');
preg_match('/private const SCHEDULES = (\[.*?\]);/s',$guardSource,$matches);
$schedules=eval('return '.$matches[1].';');
$cron=['version'=>2];
foreach($schedules as $hook=>$schedule){$cron[1900000000][$hook][md5(serialize([]))]=['schedule'=>$schedule,'args'=>[],'interval'=>300];}
$fixture_options['cron']=$cron;
$wpdb=new \PMReaderFixture\DB(); $wp_filter=[];
$path=sys_get_temp_dir().'/pm-reader-lock-'.bin2hex(random_bytes(8));
$fixture_owner=fopen($path,'x+b'); chmod($path,0600); flock($fixture_owner,LOCK_EX|LOCK_NB);
$fixture_lock=fopen($path,'r+b');
$tags=['pre_option','pre_option_cron','option_cron','default_option_cron','pre_get_scheduled_event','get_scheduled_event','wp_next_scheduled'];
foreach(['hp_core_version','hp_inventory_schema_version','hp_inventory_product_manager_erp_migrated'] as $key){$tags[]='pre_option_'.$key;$tags[]='option_'.$key;$tags[]='default_option_'.$key;}
$options=array_fill_keys($tags,[]);
$request=['schema'=>'hp_pm_actual_native_reader_observation_v1','root'=>\PMReaderFixture\ROOT,'site'=>'https://holisticpeople.com/','environment'=>'production','context'=>'full-native','projection'=>'code-before','productIds'=>[43395,43406,43457],'phaseFile'=>\PMReaderFixture\ROOT.'/wp-content/hp-deploy/selected-phase.lock','startup'=>array_slice($fixture_options,0,3,true),'allowedOptionCallbacks'=>['full-native'=>$options,'pre-init'=>$options,'early-mu'=>$options],'allowedErpCallbacks'=>array_fill_keys(['hp_pm_erp_retired_by_hp_inventory','hp_pm_erp_persist_enabled','hp_pm_erp_enabled'],[])];
try {
    $frame=$reader($request,$fixture_lock);
    check($frame['disposition']==='PRE_FIELD_CLEAR' && $frame['actor']['id']===7474 && $frame['erp']['retired']===true,'Full native boundary doubles execute actual source');
    check($frame['actionScheduler']['selectedOwnerActions']===[],'Observed empty actions separate from absence');
    $wpdb->actions=[['action_id'=>'1','hook'=>'hp_catalog_index_product_ingredients','status'=>'pending','args'=>'[43395,2]'],['action_id'=>'2','hook'=>'hp_catalog_index_product_ingredients','status'=>'pending','args'=>'[99,7]'],['action_id'=>'3','hook'=>'hp_catalog_ingredient_index_recovery','status'=>'pending','args'=>'[]'],['action_id'=>'4','hook'=>'hp_catalog_semantic_source_review_v1','status'=>'pending','args'=>'["media",43395]']];
    $frame=$reader($request,$fixture_lock);
    check(array_column($frame['actionScheduler']['selectedOwnerActions'],'action_id')===['1','3'],'Actual hook-specific generation/media/sweep selection');
    foreach ([43395,43406,43457] as $product) {
        foreach (['INFLIGHT','COMPLETED','PARTIAL','UNKNOWN'] as $state) {
            $wpdb->journalProduct=$product; $wpdb->journalState=$state;
            refuses(fn()=>$reader($request,$fixture_lock),'FIELD/source lifecycle exists');
        }
    }
    $wpdb->journalProduct=0;
    $wpdb->actions[0]['args']='[43395]';
    refuses(fn()=>$reader($request,$fixture_lock),'Malformed ingredient job generation');
    $wpdb->actions=[];
    $request['context']='early-mu';
    $frame=$reader($request,$fixture_lock);
    check(!isset($frame['actor'],$frame['erp']) && $frame['codeActor']['wordpressActorAssumed']===false,'Early facts do not claim full native ERP/actor');
    $fixture_options['cron'][1900000000]['hp_inventory_parcel_tick']=['wrong-key'=>['schedule'=>'hp_inventory_parcel_five_minutes','args'=>[]]];
    refuses(fn()=>$reader($request,$fixture_lock),'argument key differs');
    $fixture_options['cron']=$cron;
    unset($fixture_options['cron'][1900000000]['hp_inventory_parcel_tick']);
    refuses(fn()=>$reader($request,$fixture_lock),'event absent/ambiguous');
    $fixture_options['cron']=$cron;
    $fixture_options['hp_inventory_schema_version']='old';
    refuses(fn()=>$reader($request,$fixture_lock),'Persisted startup condition differs');
} finally { fclose($fixture_lock); fclose($fixture_owner); unlink($path); }
echo json_encode(['state'=>'PASS','checks'=>$checks,'actualWordPressOrTargetCalls'=>0,'boundaryDoubles'=>'wpdb/WP/phase pathname only; actual reader body'],JSON_THROW_ON_ERROR)."\n";
}

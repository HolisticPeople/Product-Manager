<?php
/** Synthetic native API faults, not a live import or ACF integration claim. */
declare(strict_types=1);
namespace HP_Catalog {
    if (!isset($GLOBALS['realCatalogRoot'])) {
    final class Product_Source_Update {
        public const VERSION = '1.0.0';
        public static array $pending = []; public static int $begins = 0; public static int $completes = 0; public static bool $stale = false; public static bool $failComplete = false;
        public static function begin(int $id, string $op): array { if (isset(self::$pending[$id]) && self::$pending[$id] !== $op) { throw new \RuntimeException('foreign_operation'); } self::$begins++; self::$pending[$id] = $op; return ['productId'=>$id,'operationId'=>$op]; }
        public static function active(int $id): bool { return isset(self::$pending[$id]); }
        public static function assert_current(array $token): void { if (self::$stale || (self::$pending[$token['productId']] ?? '') !== $token['operationId']) { throw new \RuntimeException('stale_lease'); } }
        public static function complete(array $token): void { self::assert_current($token); if (self::$failComplete) { throw new \RuntimeException('completion_fault'); } unset(self::$pending[$token['productId']]); self::$completes++; }
        public static function abort(array $token): void {}
    }
    }
}
namespace {
    define('ABSPATH', __DIR__); define('WP_CLI', true);
    $meta = $options = []; $posts = []; $writes = 0; $fault = ''; $checks = 0; $allowed = true;
    function get_current_user_id(){return 9;}
    function current_user_can($cap){return $GLOBALS['allowed'];}
    function home_url($path){return 'https://synthetic.example/';}
    function wp_get_environment_type(){return 'staging';}
    function get_post_type($id){return isset($GLOBALS['posts'][$id])?'product':false;}
    function get_post_status($id){return $GLOBALS['posts'][$id]->post_status??false;}
    function get_post($id){return $GLOBALS['posts'][$id]??null;}
    function metadata_exists($kind,$id,$key){return array_key_exists($key,$GLOBALS['meta'][$id]??[]);}
    function get_post_meta($id,$key=null,$single=false){if($key==='_application_methods'&&is_callable($GLOBALS['duringMetaRead']??null)){$callback=$GLOBALS['duringMetaRead'];$GLOBALS['duringMetaRead']=null;$callback();}$m=$GLOBALS['meta'][$id]??[]; if($key===null){$out=[];foreach($m as $k=>$v){$out[$k]=[$v];}return $out;}return $single?($m[$key]??null):(array_key_exists($key,$m)?[$m[$key]]:[]);}
    function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
    function add_option($key,$v,$deprecated='',$autoload=false){if(array_key_exists($key,$GLOBALS['options']))return false;$GLOBALS['options'][$key]=$v;return true;}
    function update_option($key,$v,$autoload=false){if($GLOBALS['fault']==='rollback_drift'&&($v['phase']??'')==='rollback_intent'){$GLOBALS['meta'][101]['ingredients']='foreign during rollback intent';}if($GLOBALS['fault']==='rollback_between_rows'&&($v['rows']['natural_constituents']['phase']??'')==='restored'){$GLOBALS['meta'][101]['ingredients']='foreign between inverse rows';}if($GLOBALS['fault']==='journal'&&($v['rows']['ingredients']['phase']??'')==='verified'){return false;}$GLOBALS['options'][$key]=$v;return true;}
    function update_post_meta($id,$key,$v){$GLOBALS['writes']++;$GLOBALS['meta'][$id][$key]=$v;return true;}
    function add_post_meta($id,$key,$v,$unique=false){if($unique&&metadata_exists('post',$id,$key))return false;return update_post_meta($id,$key,$v);}
    function delete_post_meta($id,$key){$GLOBALS['writes']++;unset($GLOBALS['meta'][$id][$key]);return true;}
    function wp_slash($v){return $v;}
    function wp_kses_post($v){return str_replace('<script>','',$v);}
    function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
    function get_object_taxonomies($type){return ['product_cat','product_visibility'];}
    function wp_get_object_terms($id,$tax,$args){return $tax==='product_cat'?[55,66]:[];}
    function is_wp_error($v){return false;}
    function wp_update_post($values,$error=false){$GLOBALS['writes']++;$id=$values['ID'];foreach($values as $k=>$v){if($k!=='ID')$GLOBALS['posts'][$id]->$k=$v;}return $id;}
    function acf_get_field($name){$schema=HP_PM_Product_Composition_Fields::schema()['fields'];if(isset($schema[$name]))return $schema[$name]+['name'=>$name];return ['key'=>'field_test_'.$name,'name'=>$name,'type'=>'textarea'];}
    function update_field($key,$v,$id){
        if(!\HP_Catalog\Product_Source_Update::active($id))throw new \RuntimeException('missing_boundary');
        $schema=HP_PM_Product_Composition_Fields::schema()['fields'];$name=str_replace('field_test_','',$key);foreach($schema as $k=>$row){if($row['key']===$key)$name=$k;}
        update_post_meta($id,$name,$v);if($GLOBALS['fault']==='pointer')throw new \RuntimeException('pointer_fault');update_post_meta($id,'_'.$name,$key);return true;
    }
    function apply_filters($hook,$default,$args=[]){if(is_callable($GLOBALS['duringSummaryFilter']??null)){$callback=$GLOBALS['duringSummaryFilter'];$GLOBALS['duringSummaryFilter']=null;$callback();}return ['schemaVersion'=>'hp_catalog_ingredient_summary_v1','status'=>'available','results'=>[['termId'=>55,'status'=>'resolved','canonical'=>['slug'=>'linalool','name'=>'Linalool']]]];}
    function get_term($id,$tax){return(object)['term_id'=>$id,'slug'=>'linalool','name'=>'Linalool'];}
    require __DIR__.'/../includes/class-hp-pm-product-composition-fields.php';
    require __DIR__.'/../includes/class-hp-pm-reviewed-product-import.php';
    function check($ok,$label){$GLOBALS['checks']++;if(!$ok)throw new \RuntimeException($label);}
    function refuses(callable $fn,string $label){try{$fn();}catch(\Throwable $e){check(true,$label);return;}throw new \RuntimeException('Expected refusal: '.$label);}
    function reset_fixture(){
        $GLOBALS['meta']=[101=>['_sku'=>'DOT-SYN15','manufacturer_acf'=>'Synthetic','sku_mfr'=>'SYN15','bottle_size_eu'=>'15','bottle_size_units_eu'=>'mL','bottle_size_usa'=>'','bottle_size_units_usa'=>'','ingredients'=>'old oil','_ingredients'=>'field_test_ingredients','_price'=>'9.99','_thumbnail_id'=>'200']];
        $GLOBALS['posts']=[101=>(object)['post_type'=>'product','post_status'=>'draft','post_name'=>'synthetic-oil','post_password'=>'','post_parent'=>0,'post_title'=>'Synthetic Oil','post_excerpt'=>'old summary','post_content'=>'old body']];
        $GLOBALS['duringMetaRead']=null;$GLOBALS['duringSummaryFilter']=null;
        $GLOBALS['options']=[];$GLOBALS['writes']=0;$GLOBALS['fault']='';$GLOBALS['allowed']=true;
        if (!isset($GLOBALS['realCatalogRoot'])) {
        \HP_Catalog\Product_Source_Update::$pending=[];\HP_Catalog\Product_Source_Update::$begins=0;\HP_Catalog\Product_Source_Update::$completes=0;\HP_Catalog\Product_Source_Update::$stale=false;\HP_Catalog\Product_Source_Update::$failComplete=false;
        }
    }
    function packet(){ $packet = ['schemaVersion'=>HP_PM_Reviewed_Product_Import::SCHEMA,'operationId'=>'synthetic-lavender-1','siteUrl'=>home_url('/'),'environment'=>'staging','productId'=>101,'identity'=>HP_PM_Reviewed_Product_Import::identity(101),'review'=>['decision'=>'approved','reference'=>'review/synthetic','reviewer'=>'Independent synthetic reviewer','identityApproved'=>true,'sourceMarket'=>'US','holds'=>[]],'fields'=>[
        ['field'=>'ingredients','before'=>['exists'=>true,'value'=>'old oil'],'proposed'=>'Lavender essential oil','referenceBefore'=>['exists'=>true,'value'=>'field_test_ingredients'],'evidence'=>['pip:ingredients']],
        ['field'=>'application_methods','before'=>['exists'=>false,'value'=>null],'proposed'=>['aromatic','topical_external'],'referenceBefore'=>['exists'=>false,'value'=>null],'evidence'=>['pip:explicit badges']],
        ['field'=>'natural_constituents','before'=>['exists'=>false,'value'=>null],'proposed'=>[['sourceName'=>'Linalool','canonicalIngredient'=>['termId'=>55,'slug'=>'linalool','name'=>'Linalool','reviewRef'=>'identity/review'],'chemicalClass'=>'monoterpenoid alcohol','amount'=>'UNKNOWN','species'=>'UNKNOWN','isomer'=>'UNKNOWN']],'referenceBefore'=>['exists'=>false,'value'=>null],'evidence'=>['pip:main components']],
    ]];$packet['review']['finalCopySha256']=HP_PM_Reviewed_Product_Import::final_copy_hash($packet['fields']);$packet['review']['sourceFidelityReviewer']='Independent source reviewer';$packet['review']['sourceFidelityReference']='review/final-copy';return $packet;}
    if ($GLOBALS['fixtureOnly'] ?? false) { return; }
    reset_fixture();$p=packet();$before=$meta;
    check(HP_PM_Reviewed_Product_Import::run($p)['status']==='reviewed_preview','reviewed preview');check($writes===0&&$options===[],'preview no writes');
    check(HP_PM_Reviewed_Product_Import::run($p,'apply')['status']==='applied','apply');check($posts[101]->post_status==='draft','draft remains draft');check($meta[101]['_price']==='9.99'&&$meta[101]['_thumbnail_id']==='200','commerce/art protected');check(!\HP_Catalog\Product_Source_Update::active(101),'boundary finished');
    $n=$writes;$j=$options;$b=\HP_Catalog\Product_Source_Update::$begins;
    check(HP_PM_Reviewed_Product_Import::run($p,'apply')['status']==='noop','repeat noop');check($n===$writes&&$j===$options&&$b===\HP_Catalog\Product_Source_Update::$begins,'repeat zero native/journal/lock writes');
    check(HP_PM_Reviewed_Product_Import::run($p,'rollback')['status']==='rolled_back','rollback');check($meta===$before,'exact value/pointer absence restored');check(HP_PM_Reviewed_Product_Import::run($p,'rollback')['status']==='noop_restored','repeat rollback noop');
    reset_fixture();$p=packet();$p['review']['holds']=['identity unresolved'];refuses(fn()=>HP_PM_Reviewed_Product_Import::run($p,'apply'),'Frankincense identity hold');check($writes===0&&$options===[],'held no writes');
    reset_fixture();$p=packet();$meta[101]['sku_mfr']='TOUCH';refuses(fn()=>HP_PM_Reviewed_Product_Import::run($p,'apply'),'variant drift');check($writes===0&&$options===[],'variant drift no field/journal writes');
    reset_fixture();$p=packet();$meta[101]['application_methods']=null;refuses(fn()=>HP_PM_Reviewed_Product_Import::run($p,'apply'),'absent distinct from present null');
    reset_fixture();$p=packet();$meta[101]['application_methods']=[];refuses(fn()=>HP_PM_Reviewed_Product_Import::run($p,'apply'),'absent distinct from empty list');
    reset_fixture();$p=packet();$fault='pointer';refuses(fn()=>HP_PM_Reviewed_Product_Import::run($p,'apply'),'ACF pointer failure');check(\HP_Catalog\Product_Source_Update::active(101),'partial failure keeps boundary');check(count($options)===1&&reset($options)['rows']['ingredients']['phase']==='intent','intent precedes partial write');$fault='';HP_PM_Reviewed_Product_Import::run($p,'rollback');check($meta[101]['ingredients']==='old oil'&&!\HP_Catalog\Product_Source_Update::active(101),'partial rollback finishes boundary');
    reset_fixture();$p=packet();$fault='journal';refuses(fn()=>HP_PM_Reviewed_Product_Import::run($p,'apply'),'verified checkpoint failure');check(\HP_Catalog\Product_Source_Update::active(101),'journal fault retains hold');$fault='';$meta[101]['ingredients']='foreign editorial change';$n=$writes;refuses(fn()=>HP_PM_Reviewed_Product_Import::run($p,'rollback'),'conditional rollback foreign drift');check($writes===$n,'foreign drift no inverse write');
    reset_fixture();$p=packet();\HP_Catalog\Product_Source_Update::$stale=true;refuses(fn()=>HP_PM_Reviewed_Product_Import::run($p,'apply'),'expired lease');check($writes===0&&$options===[],'expired lease no write');
    reset_fixture();$p=packet();\HP_Catalog\Product_Source_Update::$failComplete=true;refuses(fn()=>HP_PM_Reviewed_Product_Import::run($p,'apply'),'completion failure');check(\HP_Catalog\Product_Source_Update::active(101),'completion failure pending');\HP_Catalog\Product_Source_Update::$failComplete=false;$n=$writes;$b=\HP_Catalog\Product_Source_Update::$begins;$o=$options;check(HP_PM_Reviewed_Product_Import::run($p,'preview')['boundaryActive']===true,'completed preview reports pending boundary');check($writes===$n&&$b===\HP_Catalog\Product_Source_Update::$begins&&$options===$o,'completed preview zero boundary/journal/native writes');check(HP_PM_Reviewed_Product_Import::run($p,'apply')['status']==='recovered_completion','exact completed journal releases retained boundary');check($writes===$n&&!\HP_Catalog\Product_Source_Update::active(101),'completion recovery no field writes');
    reset_fixture();$p=packet();HP_PM_Reviewed_Product_Import::run($p,'apply');$fault='rollback_drift';$n=$writes;refuses(fn()=>HP_PM_Reviewed_Product_Import::run($p,'rollback'),'foreign edit after inverse intent');check($writes===$n&&$meta[101]['ingredients']==='foreign during rollback intent','inverse intent drift refuses before writes');
    reset_fixture();$p=packet();HP_PM_Reviewed_Product_Import::run($p,'apply');$fault='rollback_between_rows';refuses(fn()=>HP_PM_Reviewed_Product_Import::run($p,'rollback'),'foreign edit between inverse rows');check($meta[101]['ingredients']==='foreign between inverse rows','foreign inter-row edit never overwritten');
    reset_fixture();$p=packet();HP_PM_Reviewed_Product_Import::run($p,'apply');\HP_Catalog\Product_Source_Update::$failComplete=true;refuses(fn()=>HP_PM_Reviewed_Product_Import::run($p,'rollback'),'restored boundary completion fault');\HP_Catalog\Product_Source_Update::$failComplete=false;$n=$writes;$o=$options;check(HP_PM_Reviewed_Product_Import::run($p,'rollback')['status']==='recovered_restoration','restored journal completion recovery');check($n===$writes&&$o===$options&&!\HP_Catalog\Product_Source_Update::active(101),'restored recovery no native or journal writes');
    reset_fixture();$p=packet();HP_PM_Reviewed_Product_Import::run($p,'apply');$key=array_key_first($options);$options[$key]['rows']['ingredients']['after']['value']='foreign widened ownership';$n=$writes;refuses(fn()=>HP_PM_Reviewed_Product_Import::run($p,'rollback'),'corrupt row cannot widen inverse ownership');check($writes===$n,'corrupt owned row no inverse writes');
    reset_fixture();$p=packet();$allowed=false;refuses(fn()=>HP_PM_Reviewed_Product_Import::run($p,'apply'),'permission');check($writes===0&&$options===[],'permission no writes');
    reset_fixture();$p=packet();$p['fields'][0]['proposed']='changed after final copy review';refuses(fn()=>HP_PM_Reviewed_Product_Import::run($p,'apply'),'final copy review hash mismatch');check($writes===0&&$options===[],'unreviewed rewrite no writes');
    refuses(fn()=>hp_pm_validate_product_composition_v1('application_methods',['aromatic','aromatic']),'method duplicates');
    refuses(fn()=>hp_pm_validate_product_composition_v1('application_methods',['Internal']),'enum exactness');
    refuses(fn()=>hp_pm_validate_product_composition_v1('oil_preparation','essential-oil'),'preparation exactness');
    $row=packet()['fields'][2]['proposed'];$row[0]['amount']=null;refuses(fn()=>hp_pm_validate_product_composition_v1('natural_constituents',$row),'UNKNOWN explicit');
    reset_fixture();$posts[101]->post_status='publish';$meta[101]['natural_constituents']=packet()['fields'][2]['proposed'];$meta[101]['_natural_constituents']='field_hp_pm_natural_constituents_v1';$summary=hp_pm_product_composition_v1(101);check(!isset($summary['fields']['natural_constituents'][0]['canonicalIngredient']['reviewRef']),'private reviewRef stripped');$meta[101]['natural_constituents'][0]['canonicalIngredient']['name']='foreign';check(hp_pm_product_composition_v1(101)['fields']['natural_constituents'][0]['canonicalIngredient']===null,'native identity drift suppresses canonical ref');$posts[101]->post_status='draft';check(hp_pm_product_composition_v1(101)['status']==='unavailable','draft no public source summary');
    foreach(['begin_getter','completed_cycle_getter','begin_filter','completed_cycle_filter','status_filter'] as $scenario){
        reset_fixture();$posts[101]->post_status='publish';$meta[101]['application_methods']=['aromatic'];$meta[101]['_application_methods']='field_hp_pm_application_methods_v1';$meta[101]['natural_constituents']=packet()['fields'][2]['proposed'];$meta[101]['_natural_constituents']='field_hp_pm_natural_constituents_v1';
        $change=function()use($scenario){if(str_starts_with($scenario,'begin')){\HP_Catalog\Product_Source_Update::$pending[101]='concurrent-import';}elseif($scenario==='status_filter'){$GLOBALS['posts'][101]->post_status='draft';}else{$GLOBALS['meta'][101]['application_methods']=['internal'];$GLOBALS['meta'][101]['oil_preparation']='essential_oil';$GLOBALS['meta'][101]['_oil_preparation']='field_hp_pm_oil_preparation_v1';}};
        if(str_contains($scenario,'getter')){$GLOBALS['duringMetaRead']=$change;}else{$GLOBALS['duringSummaryFilter']=$change;}
        check(hp_pm_product_composition_v1(101)['status']==='unavailable','source snapshot race failclosed:'.$scenario);
    }
    echo $checks." product composition/import checks passed\n";
}

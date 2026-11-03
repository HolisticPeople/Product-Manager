<?php
/** Supplied actual Catalog source integration; native APIs remain synthetic. */
declare(strict_types=1);
$root = null;
foreach ($argv as $arg) { if (str_starts_with($arg, '--catalog-root=')) { $root = substr($arg, 15); } }
if (!$root || !is_file($root . '/src/Product_Source_Update.php')) { fwrite(STDERR, "Provide --catalog-root=/exact/reviewed/catalog/source\n"); exit(2); }
$GLOBALS['realCatalogRoot'] = $root; $GLOBALS['fixtureOnly'] = true;
require __DIR__ . '/product-composition-import-test.php';
function maybe_serialize($value){return is_array($value)?serialize($value):$value;}
function wp_cache_delete(...$args){}
function wp_generate_uuid4(){static $serial=0;return 'synthetic-uuid-'.++$serial;}
function wp_is_post_revision($id){return false;}
function wp_is_post_autosave($id){return false;}
$GLOBALS['wpdb']=new class {
 public string $options='options';
 function prepare($sql,...$args){return [$sql,$args];}
 function query($q){[$sql,$a]=$q;if(str_starts_with($sql,'UPDATE')){[$new,$key,$old]=$a;if(maybe_serialize(get_option($key,null))!==$old)return 0;$GLOBALS['options'][$key]=unserialize($new);return 1;}[$key,$old]=$a;if(maybe_serialize(get_option($key,null))!==$old)return 0;unset($GLOBALS['options'][$key]);return 1;}
};
require $root.'/src/Ingredient_Lifecycle_Matcher.php';
require $root.'/src/Ingredient_Lifecycle_Indexer.php';
require $root.'/src/Product_Categorization_Runtime.php';
require $root.'/src/Semantic_Rescan_Runtime.php';
require $root.'/src/Product_Source_Update.php';
use HP_Catalog\Product_Source_Update as B;
use HP_Catalog\Ingredient_Lifecycle_Indexer as I;
use HP_Catalog\Product_Categorization_Runtime as C;
use HP_Catalog\Semantic_Rescan_Runtime as S;
reset_fixture();$p=packet();$before=$meta;
check(HP_PM_Reviewed_Product_Import::run($p,'apply')['status']==='applied','actual boundary apply');check(!B::active(101),'actual boundary complete');check(array_filter(array_keys($options),fn($k)=>!str_starts_with($k,'hp_pm_reviewed_import_'))===[],'no lock/boundary debris');
$n=$writes;$o=$options;check(HP_PM_Reviewed_Product_Import::run($p,'apply')['status']==='noop','actual boundary repeat noop');check($writes===$n&&$options===$o,'actual boundary repeat no writes');
HP_PM_Reviewed_Product_Import::run($p,'rollback');check($meta===$before,'actual boundary inverse exact absent pointers');
reset_fixture();$p=packet();$fault='pointer';refuses(fn()=>HP_PM_Reviewed_Product_Import::run($p,'apply'),'actual partial failure');check(B::active(101),'actual marker persists');$n=$writes;I::request(101);I::process(101);I::flush();C::request(101);C::process(101);C::flush();S::request('product',101);S::process('product',101);check($writes===$n,'actual workers held during partial formula');
$posts[101]->post_status='publish';$meta[101]['natural_constituents']=packet()['fields'][2]['proposed'];$meta[101]['_natural_constituents']='field_hp_pm_natural_constituents_v1';check(hp_pm_product_composition_v1(101)['status']==='unavailable','public source withheld during partial update');$posts[101]->post_status='draft';unset($meta[101]['natural_constituents'],$meta[101]['_natural_constituents']);
$fault='';HP_PM_Reviewed_Product_Import::run($p,'rollback');check(!B::active(101)&&$meta===$before,'actual boundary partial inverse clears hold');
reset_fixture();$p=packet();$options[I::POLICY]=['enabled'=>true,'siteUrl'=>home_url('/'),'environment'=>'staging','productIds'=>[101]];HP_PM_Reviewed_Product_Import::run($p,'apply');check(($meta[101][I::STATE]['dirty']??false)===true,'existing enabled lifecycle dirtied once after source complete');$n=$writes;$o=$options;check(HP_PM_Reviewed_Product_Import::run($p,'apply')['status']==='noop','derived index state does not falsify source noop');check($writes===$n&&$options===$o,'enabled-worker source noop no writes');
$meta[101]['_price']='changed';$n=$writes;check(HP_PM_Reviewed_Product_Import::run($p,'apply')['status']==='fields_noop_protected_state_requires_review','later protected drift is explicit review');check($writes===$n,'protected drift no repeated writes');refuses(fn()=>HP_PM_Reviewed_Product_Import::run($p,'rollback'),'protected drift inverse refuses');
reset_fixture();$posts[101]->post_status='publish';$meta[101]['application_methods']=['aromatic'];$meta[101]['_application_methods']='field_hp_pm_application_methods_v1';$meta[101]['natural_constituents']=packet()['fields'][2]['proposed'];$meta[101]['_natural_constituents']='field_hp_pm_natural_constituents_v1';
$race=packet();$race['operationId']='concurrent-read-101';$race['fields']=[$race['fields'][1]];$race['fields'][0]['before']=['exists'=>true,'value'=>['aromatic']];$race['fields'][0]['referenceBefore']=['exists'=>true,'value'=>'field_hp_pm_application_methods_v1'];$race['fields'][0]['proposed']=['internal'];$race['review']['finalCopySha256']=HP_PM_Reviewed_Product_Import::final_copy_hash($race['fields']);
$GLOBALS['duringMetaRead']=static function()use($race){HP_PM_Reviewed_Product_Import::run($race,'apply');};
check(hp_pm_product_composition_v1(101)['status']==='unavailable','actual full begin/apply/complete cycle during source read suppresses mixed snapshot');check(!B::active(101)&&$meta[101]['application_methods']===['internal'],'concurrent real operator completed but old snapshot withheld');
echo $checks." actual Catalog boundary / Product-Manager integration checks passed\n";

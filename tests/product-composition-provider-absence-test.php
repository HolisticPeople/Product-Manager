<?php
/** An absent/older boundary cannot expose an interrupted product or admit writes. */
declare(strict_types=1);
$GLOBALS['realCatalogRoot'] = 'not-loaded'; $GLOBALS['fixtureOnly'] = true;
require __DIR__ . '/product-composition-import-test.php';
if (in_array('--older', $argv, true)) {
    eval('namespace HP_Catalog; final class Product_Source_Update { public const VERSION="0.9.0"; public static function active(int $id):bool{return true;} public static function begin(int $id,string $op):array{$GLOBALS["unexpectedBegins"]++;return [];} public static function assert_current(array $t):void{} public static function complete(array $t):void{} public static function abort(array $t):void{} }');
}
if (in_array('--partial', $argv, true)) { eval('namespace HP_Catalog; final class Product_Source_Update { public const VERSION="1.0.0"; public static function active(int $id):bool{return true;} public static function begin(int $id,string $op):array{$GLOBALS["unexpectedBegins"]++;return [];} public static function assert_current(array $t):void{} public static function complete(array $t):void{} }'); }
$GLOBALS['unexpectedBegins']=0;
reset_fixture(); $p = packet(); $posts[101]->post_status = 'publish';
$meta[101]['application_methods'] = ['aromatic']; $meta[101]['_application_methods'] = 'field_hp_pm_application_methods_v1';
check(hp_pm_product_composition_v1(101)['status'] === 'unavailable', 'missing/old boundary never serves possibly partial composition');
$posts[101]->post_status = 'draft'; unset($meta[101]['application_methods'], $meta[101]['_application_methods']);
refuses(fn() => HP_PM_Reviewed_Product_Import::run($p, 'apply'), 'missing/old boundary refuses finite native write');
check($writes === 0 && $options === [], 'missing/old boundary zero native/journal/lock writes');
// A completed journal with retained boundary must not bypass capability admission.
foreach($p['fields'] as $row){$meta[101][$row['field']]=$row['proposed'];$meta[101]['_'.$row['field']]=acf_get_field($row['field'])['key'];}
$method=new ReflectionMethod(HP_PM_Reviewed_Product_Import::class,'protected_hash');
$rows=[];foreach($p['fields'] as $row){$rows[$row['field']]=['phase'=>'verified','before'=>$row['before'],'referenceBefore'=>$row['referenceBefore'],'after'=>['exists'=>true,'value'=>$row['proposed']],'referenceAfter'=>['exists'=>true,'value'=>acf_get_field($row['field'])['key']]];}
$key='hp_pm_reviewed_import_101_'.hash('sha256',$p['operationId']);$options[$key]=['schemaVersion'=>HP_PM_Reviewed_Product_Import::SCHEMA,'manifestHash'=>hash('sha256',serialize($p)),'productId'=>101,'operationId'=>$p['operationId'],'phase'=>'completed','protectedHash'=>$method->invoke(null,$p),'beforeIdentity'=>$p['identity'],'afterIdentity'=>$p['identity'],'rows'=>$rows];
$o=$options;$n=$writes;refuses(fn()=>HP_PM_Reviewed_Product_Import::run($p,'apply'),'completed journal cannot bypass missing/older/partial boundary admission');check($writes===$n&&$options===$o&&$GLOBALS['unexpectedBegins']===0,'completed journal capability refusal zero native/journal/boundary writes');
echo $checks . " absent/older boundary fail-soft checks passed\n";

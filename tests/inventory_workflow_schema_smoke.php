<?php
/** Validate the production workflow SQL against temporary baseline tables only. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('FCPATH',dirname(__DIR__).DIRECTORY_SEPARATOR);define('ENVIRONMENT','development');
require FCPATH.'app/Config/Paths.php';$paths=new Config\Paths();require $paths->systemDirectory.'/Boot.php';CodeIgniter\Boot::bootConsole($paths);
$db=db_connect();$original=[];$shadowed=[];
function verifySchema(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
try {
    foreach (['products','web_stock_adjustment_details','selling_option_groups','product_opening_stocks'] as $table) $original[$table]=hash('sha256',serialize($db->table($table)->get()->getResultArray()));
    foreach (['products','web_stock_adjustment_details'] as $table) {
        $ddl=array_values($db->query("SHOW CREATE TABLE `$table`")->getRowArray())[1];
        $ddl=preg_replace('/^CREATE TABLE/','CREATE TEMPORARY TABLE',$ddl);
        $ddl=preg_replace('/^\s*CONSTRAINT .*\n/m','',$ddl);$ddl=preg_replace('/,\s*\)/',"\n)",$ddl);
        $db->query($ddl);$shadowed[]=$table;
    }
    $db->query('ALTER TABLE products DROP INDEX product_creation_request, DROP selling_group_id, DROP default_sale_mode, DROP creation_request_key, DROP creation_request_hash, MODIFY reorder_level INT NOT NULL DEFAULT 10');
    $db->query('ALTER TABLE web_stock_adjustment_details DROP stock_mode, DROP selected_quantity, DROP unit_label');
    $db->table('products')->insert(['name'=>'Migration QA','sku'=>'MIGRATION-QA','unit'=>'kg','cost_price'=>1.02,'sell_price'=>1.5,'reorder_level'=>10,'package_unit'=>'Loor','package_size'=>50,'package_price'=>60]);
    $sql=file_get_contents(FCPATH.'docs/sql/2026-11-07_inventory_workflow.sql');
    verifySchema($sql===file_get_contents(APPPATH.'Database/TenantSchema/18_inventory_workflow.sql'),'Production workflow SQL exactly matches the migration source.');
    verifySchema(file_get_contents(FCPATH.'docs/sql/2026-11-07_inventory_packages.sql')===file_get_contents(APPPATH.'Database/TenantSchema/17_packaged_inventory.sql'),'Production prerequisite SQL exactly matches the package migration.');
    foreach (explode(';',$sql) as $statement) {
        if(trim($statement)==='')continue;
        $statement=preg_replace('/CREATE TABLE (selling_option_groups|product_opening_stocks)/','CREATE TEMPORARY TABLE $1',$statement);
        $db->query($statement);
        foreach (['selling_option_groups','product_opening_stocks'] as $table) if(str_contains($statement,'CREATE TEMPORARY TABLE '.$table))$shadowed[]=$table;
    }
    $product=$db->table('products')->where('sku','MIGRATION-QA')->get()->getRowArray();
    verifySchema((float)$product['cost_price']===1.02 && (float)$product['package_size']===50.0 && (float)$product['reorder_level']===10.0,'Workflow migration preserves existing prices, capacity and reorder value.');
    verifySchema($product['default_sale_mode']==='loose' && $product['selling_group_id']===null,'Existing products receive safe POS defaults without an invented shortcut group.');
    $db->table('products')->where('id',$product['id'])->update(['reorder_level'=>0.125]);
    verifySchema((float)$db->table('products')->where('id',$product['id'])->get()->getRow('reorder_level')===0.125,'New schema supports fractional ordinary-product reorder thresholds.');
} finally {
    foreach(array_reverse($shadowed) as $table)$db->query("DROP TEMPORARY TABLE IF EXISTS `$table`");
    foreach($original as $table=>$hash)verifySchema(hash('sha256',serialize($db->table($table)->get()->getResultArray()))===$hash,"$table real records unchanged after schema test.");
}

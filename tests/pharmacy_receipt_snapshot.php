<?php
/** Read-only ledger snapshot for the disposable browser fixture, never a real database. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$database=$argv[1]??'';
if(!preg_match('/^pharmacy_test_[a-f0-9]{12}$/D',$database))throw new InvalidArgumentException('Only disposable pharmacy fixtures are allowed.');
define('FCPATH',dirname(__DIR__).DIRECTORY_SEPARATOR);
define('ENVIRONMENT','development');
require FCPATH.'app/Config/Paths.php';
$paths=new Config\Paths();require $paths->systemDirectory.'/Boot.php';
CodeIgniter\Boot::bootConsole($paths);
$config=config(Config\Database::class)->default;
$db=new mysqli($config['hostname'],$config['username'],$config['password'],$database,$config['port']);
$snapshot=[];
foreach(['sales','payments','stock_movements','stock_levels','journal_entries','journal_entry_lines','pharmacy_dispensings'] as $table){
    $result=$db->query('SELECT * FROM `'.$table.'`');
    $rows=$result->fetch_all(MYSQLI_ASSOC);
    // Stable row ordering also covers tables with compound primary keys.
    $encoded=array_map(static fn($row)=>json_encode($row,JSON_THROW_ON_ERROR),$rows);sort($encoded);
    $snapshot[$table]=['count'=>count($rows),'sha256'=>hash('sha256',implode("\n",$encoded))];
}
echo json_encode($snapshot,JSON_THROW_ON_ERROR);

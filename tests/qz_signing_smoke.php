<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
define('FCPATH', dirname(__DIR__).DIRECTORY_SEPARATOR);
define('ENVIRONMENT', 'testing');
require FCPATH.'app/Config/Paths.php';
$paths = new Config\Paths();
require $paths->systemDirectory.'/Boot.php';
CodeIgniter\Boot::bootConsole($paths);

use App\Libraries\AuthContext;
use App\Services\QzCertificateStore;
use App\Services\QzSigningService;

function check(bool $value, string $description): void {
    if (!$value) throw new RuntimeException($description);
    echo "PASS: $description\n";
}
function reject(callable $action, string $description): void {
    try { $action(); } catch (Throwable) { check(true, $description); return; }
    throw new RuntimeException($description);
}
$root = sys_get_temp_dir().'/erp-qz-test-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
$workstation = 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa';
$other = 'bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb';
try {
    $options = ['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA];
    if (is_file('C:/xampp/apache/conf/openssl.cnf')) $options['config']='C:/xampp/apache/conf/openssl.cnf';
    $key = openssl_pkey_new($options);
    $csr = openssl_csr_new(['commonName'=>'ERP QZ isolated test'], $key, $options);
    $certificate = openssl_csr_sign($csr, null, $key, 1, $options);
    openssl_x509_export($certificate, $certPem);
    openssl_pkey_export($key, $keyPem, null, $options);
    AuthContext::$tenant=['code'=>'qa-one','database'=>'qa_one','plan'=>'premium','name'=>'QA'];
    try { (new QzCertificateStore(''))->save($workstation,$certPem,$keyPem); throw new RuntimeException('Missing storage accepted'); }
    catch (App\Services\QzSetupException $error) { check($error->reason==='storage_not_configured','Missing production environment variable has a specific actionable reason.'); }
    try { (new QzCertificateStore($root.'/missing'))->signer($workstation); throw new RuntimeException('Missing folder accepted'); }
    catch (App\Services\QzSetupException $error) { check($error->reason==='storage_missing','Missing private folder is distinguished from invalid signing files.'); }
    $store = new QzCertificateStore($root);
    $store->save($workstation, $certPem, $keyPem);
    $signer = $store->signer($workstation);
    check($signer->certificate() === $certPem, 'Configured workstation returns only its public certificate.');
    check($store->signer($other)->certificate() === null, 'Another workstation has no access to this certificate.');
    AuthContext::$tenant['code']='qa-two';
    check($store->signer($workstation)->certificate() === null, 'Another tenant has separate signing credentials.');
    AuthContext::$tenant['code']='qa-one';
    $bytes=base64_encode("TEST\n");
    $params=['printer'=>['name'=>'POS-58C'],'options'=>['copies'=>1],
        'data'=>[['type'=>'raw','format'=>'command','flavor'=>'base64','data'=>$bytes]]];
    $message=fn ($call,$params)=>json_encode(['call'=>$call,'params'=>$params,'timestamp'=>(int)(microtime(true)*1000)],JSON_UNESCAPED_SLASHES);
    $jobs=[['hash'=>hash('sha256',$bytes),'printer'=>['name'=>'POS-58C'],'expires'=>time()+60]];
    $request=$message('print',$params);
    $signature=$signer->sign($request,$jobs);
    check(openssl_verify(hash('sha256',$request),base64_decode($signature),$certPem,OPENSSL_ALGO_SHA512)===1,'Signature verifies against QZ SHA256/SHA512 protocol.');
    check($signer->sign($message('printers.find',(object)[]),[]) !== '', 'Printer discovery can be signed.');
    reject(fn()=>$signer->sign(json_encode(['call'=>'printers.find','params'=>(object)[],'timestamp'=>0]),[]),'Expired signature requests are rejected.');
    reject(fn()=>$signer->sign($message('file.write',[]),$jobs),'File operations cannot use the signing endpoint.');
    reject(fn()=>$signer->sign($request,[]),'Unissued receipt bytes cannot be signed.');
    $changed=$params;$changed['printer']=['name'=>'Other'];
    reject(fn()=>$signer->sign($message('print',$changed),$jobs),'Changed printer targets are rejected.');
    $changed=$params;$changed['options']['copies']=2;
    reject(fn()=>$signer->sign($message('print',$changed),$jobs),'Additional copies cannot be silently signed.');
    reject(fn()=>$store->save($workstation,$certPem,'invalid key'),'Invalid private key cannot replace a working pair.');
    check($store->signer($workstation)->certificate()===$certPem,'Failed upload preserves the previous pair.');
    reject(fn()=>$store->signer('../escape'),'Invalid workstation paths are rejected.');
    reject(fn()=>(new QzCertificateStore(FCPATH))->signer($workstation),'Public website storage is rejected.');
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
    AuthContext::reset();
}

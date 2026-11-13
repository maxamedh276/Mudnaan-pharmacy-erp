<?php
declare(strict_types=1);

/** CLI-only snapshot and optional restore drill; never restores over an existing database. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('FCPATH',dirname(__DIR__).DIRECTORY_SEPARATOR);
define('ENVIRONMENT','production');
require FCPATH.'app/Config/Paths.php';
$paths=new Config\Paths();require $paths->systemDirectory.'/Boot.php';
CodeIgniter\Boot::bootConsole($paths);
$options=getopt('',['database:','verify']);
$config=config(Config\Database::class)->default;
$source=$options['database']??$config['database'];
if (!is_string($source) || !preg_match('/^pharmacy_(main|test_[a-f0-9]{12})$/D',$source)) throw new InvalidArgumentException('Backup is limited to the pharmacy installation or an isolated fixture.');
$bin=dirname(FCPATH).DIRECTORY_SEPARATOR.'mysql'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR;
$dump=$bin.'mysqldump.exe';$mysql=$bin.'mysql.exe';
if (!is_file($dump)||!is_file($mysql)) throw new RuntimeException('MySQL client tools are unavailable. Configure the installation before scheduling backups.');
$directory=FCPATH.'.tools'.DIRECTORY_SEPARATOR.'backups';
if (!is_dir($directory)&&!mkdir($directory,0700,true)) throw new RuntimeException('Unable to create the protected backup directory.');
$target=$directory.DIRECTORY_SEPARATOR.$source.'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.sql';
$common=['--host='.$config['hostname'],'--port='.(int)$config['port'],'--user='.$config['username'],'--default-character-set=utf8mb4'];
$environment=getenv();$environment['MYSQL_PWD']=(string)$config['password'];
$run=static function(array $command,?string $input=null)use($environment):void{
    $descriptors=[0=>$input?['file',$input,'r']:['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
    $process=proc_open($command,$descriptors,$pipes,FCPATH,$environment,['bypass_shell'=>true,'create_no_window'=>true]);
    if (!is_resource($process)) throw new RuntimeException('Unable to start the database utility.');
    if (!$input) fclose($pipes[0]);
    $output=stream_get_contents($pipes[1]);fclose($pipes[1]);
    $error=stream_get_contents($pipes[2]);fclose($pipes[2]);
    if (proc_close($process)!==0) throw new RuntimeException('Database utility failed: '.trim($error));
};
$admin=new mysqli($config['hostname'],$config['username'],$config['password'],'',(int)$config['port']);
$verify=null;
try {
    // This application implements business rules in PHP. Do not activate external SQL
    // events/triggers during a restore drill against a disposable database.
    $run([$dump,...$common,'--single-transaction','--quick','--hex-blob','--skip-routines','--skip-triggers','--skip-events','--skip-lock-tables','--result-file='.$target,$source]);
    if (!is_file($target)||filesize($target)<100) throw new RuntimeException('Backup output is empty.');
    $result=['database'=>$source,'backup'=>$target,'bytes'=>filesize($target),'sha256'=>hash_file('sha256',$target),'restore_verified'=>false];
    $documents=$admin->query('SHOW TABLES FROM `'.$source.'` LIKE \'pharmacy_prescription_documents\'');
    $files=[];
    if ($documents->num_rows) {
        $documents=$admin->query('SELECT stored_name,sha256 FROM `'.$source.'`.pharmacy_prescription_documents');
        while ($document=$documents->fetch_assoc()) {
            if (!preg_match('/^[a-f0-9]{32}\.(pdf|png|jpg)$/D',$document['stored_name'])) throw new RuntimeException('Invalid clinical file reference; backup requires investigation.');
            $directory=realpath(WRITEPATH.'pharmacy-documents');
            $file=$directory?realpath($directory.DIRECTORY_SEPARATOR.$document['stored_name']):false;
            if (!$file || !str_starts_with(strtolower($file),strtolower($directory.DIRECTORY_SEPARATOR)) || !hash_equals($document['sha256'],hash_file('sha256',$file))) throw new RuntimeException('A clinical attachment is missing or changed; backup is incomplete.');
            if (!is_dir($target.'.files')&&!mkdir($target.'.files',0700,true)) throw new RuntimeException('Cannot create private file backup storage.');
            $copy=$target.'.files'.DIRECTORY_SEPARATOR.$document['stored_name'];
            if (!copy($file,$copy)||!hash_equals($document['sha256'],hash_file('sha256',$copy))) throw new RuntimeException('Clinical attachment copy verification failed.');
            $files[$document['stored_name']]=$document['sha256'];
        }
    }
    $result['clinical_files']=$files;
    if (array_key_exists('verify',$options)) {
        $verify='pharmacy_restore_'.bin2hex(random_bytes(6));
        if (!preg_match('/^pharmacy_restore_[a-f0-9]{12}$/D',$verify)) throw new RuntimeException('Invalid verification database name.');
        $admin->query('CREATE DATABASE `'.$verify.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $run([$mysql,...$common,$verify],$target);
        $tables=$admin->query('SHOW FULL TABLES FROM `'.$verify.'` WHERE Table_type = \'BASE TABLE\'');
        $count=0;
        while ($table=$tables->fetch_row()) {
            $name=str_replace('`','``',$table[0]);
            $admin->query('SELECT COUNT(*) FROM `'.$verify.'`.`'.$name.'`');
            $count++;
        }
        if (!$count) throw new RuntimeException('Restore produced no tables.');
        $result['restore_verified']=true;$result['restored_tables']=$count;
    }
    file_put_contents($target.'.manifest.json',json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    if ($verify!==null && preg_match('/^pharmacy_restore_[a-f0-9]{12}$/D',$verify)) $admin->query('DROP DATABASE `'.$verify.'`');
    $admin->close();
}

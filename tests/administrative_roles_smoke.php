<?php

/** Administrative role boundaries; all writes are connection-local temporary tables. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ENVIRONMENT', 'development');
require FCPATH . 'app/Config/Paths.php';
$paths = new Config\Paths();
require $paths->systemDirectory . '/Boot.php';
CodeIgniter\Boot::bootConsole($paths);

use App\Libraries\AuthContext;
use App\Libraries\JwtService;
use App\Libraries\TenantManager;
use App\Models\UserModel;
use App\Services\AdministrativeRolesService;
use App\Services\MenuPermissionService;
use App\Services\RoleService;
use App\Services\UserService;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
}
function forbidden(callable $operation, string $message): void
{
    try { $operation(); }
    catch (CodeIgniter\HTTP\Exceptions\HTTPException $error) {
        check($error->getCode() === 403, $message);
        return;
    }
    throw new RuntimeException('Permission bypass: ' . $message);
}
function actingAs(int $id): void
{
    $model = new UserModel();
    AuthContext::$user = $model->findActiveById($id);
    AuthContext::$permissions = $model->permissionCodes((int)AuthContext::$user->role_id);
    session()->set('user', (array)AuthContext::$user);
}
function settingsController(array $body): App\Controllers\Web\SettingsWebController
{
    $request = new CodeIgniter\HTTP\IncomingRequest(config(Config\App::class), new CodeIgniter\HTTP\URI('http://localhost/settings/save'), json_encode($body), new CodeIgniter\HTTP\UserAgent());
    $request->setHeader('Content-Type','application/json');
    $controller = new App\Controllers\Web\SettingsWebController();
    $controller->initController($request, new CodeIgniter\HTTP\Response(config(Config\App::class)), service('logger'));
    return $controller;
}
/** Direct controller calls prove the restriction does not depend on URL/filter matching. */
function operationalController(array $body = []): App\Controllers\Admin\SettingsController
{
    $request = new CodeIgniter\HTTP\IncomingRequest(config(Config\App::class), new CodeIgniter\HTTP\URI('http://localhost/api/v1/settings'), json_encode($body), new CodeIgniter\HTTP\UserAgent());
    $request->setHeader('Content-Type', 'application/json');
    $controller = new App\Controllers\Admin\SettingsController();
    $controller->initController($request, new CodeIgniter\HTTP\Response(config(Config\App::class)), service('logger'));
    return $controller;
}

$manager = new TenantManager();
$tenant = $manager->find(config(Config\Tenants::class)->default);
$manager->connect($tenant);
AuthContext::$tenant = $tenant;
session()->set('user', []);
$db = db_connect();
$original = [];
$shadowed = [];
try {
    foreach (['roles','permissions','role_permissions','users','role_menu_permissions','user_menu_permissions','audit_log','settings','branches'] as $table) {
        $original[$table] = hash('sha256',serialize($db->table($table)->get()->getResultArray()));
        $copy = in_array($table,['permissions','branches'],true) ? $db->table($table)->get()->getResultArray() : [];
        $definition = array_values($db->query("SHOW CREATE TABLE `$table`")->getRowArray())[1];
        $definition = preg_replace('/^CREATE TABLE/','CREATE TEMPORARY TABLE',$definition);
        $definition = preg_replace('/^\s*CONSTRAINT .*\n/m','',$definition);
        $definition = preg_replace('/,\s*\)/',"\n)",$definition);
        $db->query($definition);
        $shadowed[] = $table;
        if ($copy) $db->table($table)->insertBatch($copy);
    }
    $db->table('roles')->insertBatch([
        ['id'=>71,'name'=>'Owner','is_system'=>1],
        ['id'=>72,'name'=>'Manager','is_system'=>1],
        ['id'=>73,'name'=>'Cashier','is_system'=>1],
    ]);
    (new AdministrativeRolesService())->upgrade();
    (new AdministrativeRolesService())->upgrade();
    $super = $db->table('roles')->where('name','Superadmin')->get()->getRowArray();
    $admin = $db->table('roles')->where('name','Admin')->get()->getRowArray();
    check((int)$super['id'] === 71 && $db->table('roles')->where('name','Admin')->countAllResults() === 1,'Upgrade preserves Owner role ID, creates Admin, and can run repeatedly.');
    $all = array_column($db->table('permissions')->get()->getResultArray(),'code');
    $model = new UserModel();
    check(count($model->permissionCodes(71)) === count($all),'Superadmin receives the complete permission catalog.');
    check(!in_array('settings.operational',$model->permissionCodes((int)$admin['id']),true) && count($model->permissionCodes((int)$admin['id'])) === count($all)-1,'Admin receives every permission except Operational Settings.');
    $db->table('users')->insertBatch([
        ['id'=>101,'branch_id'=>1,'role_id'=>71,'name'=>'QA Superadmin','email'=>'super@qa.invalid','password_hash'=>password_hash('qa-only',PASSWORD_DEFAULT),'is_active'=>1],
        ['id'=>202,'branch_id'=>1,'role_id'=>$admin['id'],'name'=>'QA Admin','email'=>'admin@qa.invalid','password_hash'=>password_hash('qa-only',PASSWORD_DEFAULT),'is_active'=>1],
        ['id'=>303,'branch_id'=>1,'role_id'=>73,'name'=>'QA Cashier','email'=>'cashier@qa.invalid','password_hash'=>password_hash('qa-only',PASSWORD_DEFAULT),'is_active'=>1],
    ]);
    $roles = new RoleService(); $users = new UserService(); $menus = new MenuPermissionService();
    actingAs(202);
    check(AuthContext::can('settings.manage') && AuthContext::can('users.manage') && !AuthContext::can('settings.operational'),'Admin can manage ordinary settings and users but cannot access operations.');
    $beforeSettings = (new App\Models\SettingModel())->allAssoc();
    check(operationalController()->index()->getStatusCode() === 403, 'Operational controller itself denies Admin reads.');
    check(operationalController(['currency'=>'BAD'])->update()->getStatusCode() === 403, 'Operational controller itself denies Admin writes.');
    check((new App\Models\SettingModel())->allAssoc() === $beforeSettings, 'Rejected direct operational writes leave settings unchanged.');
    forbidden(fn()=>settingsController([])->operational(),'Admin direct Operational Settings URL returns 403.');
    check(settingsController(['business_name'=>'QA Admin Settings'])->save()->getStatusCode()===200,'Admin can save ordinary settings.');
    settingsController(['currency'=>'BAD','tax_rate'=>'99','uses_serials'=>'1'])->save();
    check((new App\Models\SettingModel())->getVal('currency')===null&&(new App\Models\SettingModel())->getVal('tax_rate')===null&&(new App\Models\SettingModel())->getVal('uses_serials')===null,'Ordinary settings ignore operational fields in a forged Admin payload.');
    forbidden(fn()=>$users->update(202,['role_id'=>71]),'Admin cannot promote themselves to Superadmin.');
    forbidden(fn()=>$users->create(['name'=>'Bad promotion','email'=>'promoted@qa.invalid','password'=>'qa-only','role_id'=>71]),'Admin cannot create another Superadmin account.');
    forbidden(fn()=>$users->update(101,['password'=>'hijack-qa']),'Admin cannot reset a Superadmin password.');
    forbidden(fn()=>$users->setActive(101,false,202),'Admin cannot deactivate a Superadmin.');
    $protected = $db->table('users')->where('id',101)->get()->getRowArray();
    foreach ([['name'=>'Hijacked'], ['email'=>'other@qa.invalid'], ['phone'=>'000'], ['branch_id'=>999], ['role_id'=>$admin['id']]] as $payload) {
        forbidden(fn()=>$users->update(101,$payload), 'Admin cannot change Superadmin ' . array_key_first($payload) . '.');
    }
    forbidden(fn()=>$users->setActive(101,true,202),'Admin cannot change a Superadmin activation state, even to active.');
    forbidden(fn()=>$menus->resetUserToRole(101),'Admin cannot reset Superadmin menu permissions.');
    check($db->table('users')->where('id',101)->get()->getRowArray() === $protected, 'All denied account changes leave the complete Superadmin record unchanged.');
    forbidden(fn()=>$roles->create(['name'=>'superadmin']),'Reserved role names cannot create a privilege escalation.');
    forbidden(fn()=>$roles->update(72,['name'=>'Superadmin']),'Renaming a lower role cannot grant Superadmin.');
    forbidden(fn()=>$roles->update(71,['permissions'=>[]]),'Superadmin permissions cannot be removed through the role editor.');
    forbidden(fn()=>$roles->update((int)$admin['id'],['name'=>'Superadmin']),'Admin role cannot be renamed or re-permissioned.');
    forbidden(fn()=>$roles->delete(71),'Superadmin role cannot be deleted.');
    forbidden(fn()=>$roles->create(['name'=>'Bypass','permissions'=>['settings.operational']]),'Operational permission cannot be delegated to a custom role.');
    forbidden(fn()=>$menus->saveRoleMenuKeys(71,[]),'Role menu overrides cannot remove Superadmin access.');
    forbidden(fn()=>$menus->saveUserMenuKeys(101,[]),'User menu overrides cannot modify a Superadmin account.');
    $custom = $roles->create(['name'=>'QA Clerk','permissions'=>['sales.view']]);
    $roles->update((int)$custom['id'],['permissions'=>['sales.view','settings.manage']]);
    check(in_array('settings.manage',$roles->find((int)$custom['id'])['permissions'],true),'Custom role permissions remain editable.');
    try {$roles->update((int)$custom['id'],['name'=>'Partly changed','permissions'=>['unknown.permission']]);throw new RuntimeException('Unknown permission accepted.');}
    catch (InvalidArgumentException $error) {check($error->getMessage()==='INVALID_PERMISSIONS'&&$roles->find((int)$custom['id'])['name']==='QA Clerk','Invalid permission payload leaves the role name and permissions unchanged.');}
    // Even a stale or manually inserted permission row cannot bypass the reserved tier.
    $opId = $db->table('permissions')->where('code','settings.operational')->get()->getRow('id');
    $db->table('role_permissions')->insert(['role_id'=>73,'permission_id'=>$opId]);
    actingAs(303); AuthContext::$permissions[]='settings.operational';
    check(!AuthContext::can('settings.operational'),'Forged/stale operational permission does not promote a Cashier.');
    AuthContext::$permissions = $all;
    check(operationalController()->index()->getStatusCode()===403 && operationalController(['currency'=>'BAD'])->update()->getStatusCode()===403, 'Giving a lower role every permission still cannot grant operational access.');
    forbidden(fn()=>$users->update(101,['password'=>'bad-reset']), 'Lower role with every permission cannot reset a Superadmin password.');
    forbidden(fn()=>$users->setActive(101,false,303), 'Lower role with every permission cannot block a Superadmin.');
    forbidden(fn()=>$users->update(303,['role_id'=>71]), 'Lower role with every permission cannot become Superadmin.');
    actingAs(303); AuthContext::$permissions[]='settings.operational';
    forbidden(fn()=>settingsController([])->index(),'Cashier without settings permission cannot open Settings.');
    forbidden(fn()=>settingsController(['business_name'=>'Bypass'])->save(),'Cashier cannot save Settings.');
    foreach ([202=>false,101=>true] as $id=>$allowed) {
        foreach (['GET','POST'] as $method) {
            // The role claim deliberately lies; the filter must use the fresh database role.
            $token=(new JwtService())->issue($id,$tenant['code'],$tenant['plan'],'Superadmin');
            $request=new CodeIgniter\HTTP\IncomingRequest(config(Config\App::class),new CodeIgniter\HTTP\URI('http://localhost/api/v1/settings'),null,new CodeIgniter\HTTP\UserAgent());
            $request->setMethod($method)->setHeader('Authorization','Bearer '.$token);
            $response=(new App\Filters\AuthFilter())->before($request);
            check($allowed?$response===null:$response?->getStatusCode()===403,"$method legacy operational endpoint ".($allowed?'allows Superadmin.':'denies Admin, even with a Superadmin token role claim.'));
        }
    }
    actingAs(101);
    check(AuthContext::can('settings.operational')&&AuthContext::can('settings.manage'),'Superadmin has both settings capabilities.');
    check(operationalController()->index()->getStatusCode()===200, 'Superadmin can read the operational controller directly.');
    check(operationalController(['currency'=>'USD'])->update()->getStatusCode()===200 && (new App\Models\SettingModel())->getVal('currency')==='USD', 'Superadmin can save operational settings.');
    forbidden(fn()=>$users->update(101,['role_id'=>$admin['id']]),'The last active Superadmin cannot be demoted.');
    forbidden(fn()=>$users->setActive(101,false,202),'The last active Superadmin cannot be deactivated.');
    check($db->table('users')->where('id',101)->get()->getRow('role_id')==71,'Rejected promotions and demotions leave accounts unchanged.');
    $second=$users->create(['name'=>'Second QA Super','email'=>'second@qa.invalid','password'=>'qa-only','role_id'=>71]);
    check($second['role']==='Superadmin','Superadmin can assign the Superadmin role.');
    actingAs($second['id']);
    $users->update(101,['name'=>'QA Superadmin updated','password'=>'updated-qa-only']);
    check(password_verify('updated-qa-only',$db->table('users')->where('id',101)->get()->getRow('password_hash')), 'One Superadmin can edit another Superadmin and reset their password.');
    $users->setActive(101,false,$second['id']);
    check(!$users->find(101)['is_active'], 'One Superadmin can deactivate another while keeping an active Superadmin.');
    $users->setActive(101,true,$second['id']);
    check($users->find(101)['is_active'], 'One Superadmin can reactivate another Superadmin.');
    actingAs(101);
    $users->update($second['id'],['role_id'=>$admin['id']]);
    check($users->find($second['id'])['role']==='Admin','A Superadmin can demote another account while preserving an active Superadmin.');
    check(count($menus->getUserMenuKeys(101,71))===count($menus->getAllMenuKeys()) && count($menus->getRoleMenuKeys((int)$admin['id']))===count($menus->getAllMenuKeys()),'Both administrative tiers receive all ordinary navigation menus.');
    // Render once: the existing sidebar declares shared PHP helper functions.
    actingAs(202);
    if (in_array('--users-view', $argv, true) || in_array('--superadmin-view', $argv, true)) {
        $superadminView = in_array('--superadmin-view', $argv, true);
        if ($superadminView) actingAs(101);
        $html=view('web/users/index',['title'=>'QA Users','users'=>$users->list(),'roles'=>$roles->list(),'user'=>(array)AuthContext::$user]);
        $document=new DOMDocument();libxml_use_internal_errors(true);$document->loadHTML($html);libxml_clear_errors();
        $rows=(new DOMXPath($document))->query('//table[@id="usersTable"]/tbody/tr');
        $seen=[];
        foreach ($rows as $row) {
            $cells=$row->getElementsByTagName('td');$label=trim($cells->item(2)->textContent);
            if(str_contains($cells->item(0)->textContent,'QA Superadmin'))$seen['super']=$label;
            if(str_contains($cells->item(0)->textContent,'QA Superadmin')) {
                $buttons=$row->getElementsByTagName('button');
                foreach ($buttons as $button) {
                    $selfDeactivate = $superadminView && $button->hasAttribute('data-user-status-action');
                    check($button->hasAttribute('disabled') === (!$superadminView || $selfDeactivate), $selfDeactivate ? 'Self-deactivation is disabled in Actions.' : ($superadminView ? 'Superadmin can edit their account.' : 'Admin sees disabled controls for Superadmin accounts.'));
                }
            }
            $actions=$cells->item(5);
            check(str_contains($actions->textContent,'Deactivate'), 'Active user has a labeled Deactivate action.');
            check($cells->item(3)->getElementsByTagName('button')->length===0, 'Status badge is read-only.');
            if(str_contains($cells->item(0)->textContent,'QA Cashier'))$seen['cashier']=$label;
            if(str_contains($cells->item(0)->textContent,'QA Admin'))$seen['admin']=$label;
        }
        check($seen===['admin'=>'Admin','cashier'=>'Cashier','super'=>'Superadmin'],'Assigned Role cells show the actual service role for Admin, Cashier and Superadmin.');
    } else {
        $html=settingsController([])->index();
        check(str_contains($html,'id="appearanceSettings"')&&!str_contains($html,'href="'.site_url('settings/operational').'"'),'Admin Settings shows ordinary controls and hides the Operational Settings link.');
    }
} finally {
    foreach (array_reverse($shadowed) as $table) $db->query("DROP TEMPORARY TABLE IF EXISTS `$table`");
    foreach ($original as $table=>$hash) check(hash('sha256',serialize($db->table($table)->get()->getResultArray()))===$hash,"$table real data remains unchanged.");
    AuthContext::reset();
}

<?php

/**
 * Run: php tests/finance_web_smoke.php
 * Uses connection-local temporary tables; existing ERP records are never changed.
 * Requires the configured local tenant database and its schema.
 */
declare(strict_types=1);

define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ENVIRONMENT', 'development');
require FCPATH . 'app/Config/Paths.php';
$paths = new Config\Paths();
require $paths->systemDirectory . '/Boot.php';
CodeIgniter\Boot::bootConsole($paths);

use App\Controllers\Web\FinanceWebController;
use App\Libraries\AuthContext;
use App\Libraries\TenantManager;
use App\Services\ChartOfAccountsService;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;

function verify(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
}

$tenant = (new TenantManager())->find(config(Config\Tenants::class)->default);
if (!$tenant) throw new RuntimeException('No configured tenant.');
(new TenantManager())->connect($tenant);
$db = db_connect();
AuthContext::$tenant = $tenant;
AuthContext::$user = (object) ['id' => 1, 'branch_id' => 1];
AuthContext::$permissions = ['accounting.view', 'accounting.manage', 'periods.manage'];

$tables = ['financial_periods', 'audit_log', 'accounts', 'pos_payment_methods', 'journal_entries', 'journal_entry_lines'];
$originalCounts = [];
foreach ($tables as $table) {
    $rows = $db->table($table)->get()->getResultArray();
    $originalCounts[$table] = count($rows);
    $definition = array_values($db->query("SHOW CREATE TABLE `$table`")->getRowArray())[1];
    $definition = preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $definition);
    $definition = preg_replace('/^\s*CONSTRAINT .*\n/m', '', $definition);
    $definition = preg_replace('/,\s*\)/', "\n)", $definition);
    $db->query($definition);
    if ($table !== 'financial_periods' && $rows !== []) $db->table($table)->insertBatch($rows);
}

function controller(array $body = []): FinanceWebController
{
    $request = new IncomingRequest(config(Config\App::class), new URI('http://localhost/finance'), json_encode($body), new CodeIgniter\HTTP\UserAgent());
    $request->setHeader('Content-Type', 'application/json');
    $controller = new FinanceWebController();
    $controller->initController($request, new Response(config(Config\App::class)), service('logger'));
    return $controller;
}

try {
    $first = controller(['name' => 'Test January', 'start_date' => '2090-01-01', 'end_date' => '2090-01-31'])->savePeriod();
    verify($first->getStatusCode() === 200, 'Create the first active period.');
    $firstId = json_decode($first->getBody(), true)['data']['id'];
    $second = controller(['name' => 'Test February', 'start_date' => '2090-02-01', 'end_date' => '2090-02-28'])->savePeriod();
    $secondId = json_decode($second->getBody(), true)['data']['id'];
    verify($db->table('financial_periods')->where('status', 'open')->countAllResults() === 1, 'Creating a second period leaves exactly one active.');
    verify($db->table('financial_periods')->where('id', $firstId)->get()->getRow()->status === 'closed', 'The previous period is closed.');

    $invalid = controller(['name' => 'Invalid', 'start_date' => '2090-02-30', 'end_date' => '2090-03-31'])->savePeriod();
    verify($invalid->getStatusCode() === 422, 'Reject impossible calendar dates.');
    verify($db->table('financial_periods')->where('id', $secondId)->get()->getRow()->status === 'open', 'Invalid creation rolls back the previous period transition.');
    $overlap = controller(['name' => 'Overlap', 'start_date' => '2090-02-10', 'end_date' => '2090-02-20'])->savePeriod();
    verify($overlap->getStatusCode() === 422, 'Reject overlapping date ranges.');
    verify($db->table('financial_periods')->where('id', $secondId)->get()->getRow()->status === 'open', 'Overlap failure preserves the active period.');

    $reactivate = controller()->changePeriod($firstId, 'activate');
    verify($reactivate->getStatusCode() === 200 && $db->table('financial_periods')->where('status', 'open')->countAllResults() === 1, 'Reactivation atomically switches the active period.');
    controller()->changePeriod($firstId, 'close');
    verify($db->table('financial_periods')->where('status', 'open')->countAllResults() === 0, 'Closing the active period locks it.');

    AuthContext::$permissions = ['accounting.view'];
    try {
        controller()->changePeriod($firstId, 'activate');
        throw new RuntimeException('Period permission check was bypassed.');
    } catch (CodeIgniter\HTTP\Exceptions\HTTPException $e) {
        verify($e->getCode() === 403, 'Read-only users cannot change periods.');
    }
    AuthContext::$permissions[] = 'accounting.manage';
    $root = $db->table('accounts')->where('code', '1000')->get()->getRowArray();
    $account = (new ChartOfAccountsService())->create(['code' => 'WEB-QA-CASH', 'name' => 'Web QA Cash', 'account_group_id' => $root['account_group_id'], 'parent_id' => $root['id']]);
    verify((int) $account['parent_id'] === (int) $root['id'], 'Custom accounts retain their selected account category.');

    $automaticAccount = (new App\Services\Web\AccountCreationService())->create([
        'name' => 'QA Automatic Code', 'account_group_id' => $root['account_group_id'], 'parent_id' => $root['id'],
    ]);
    $nextAutomaticAccount = (new App\Services\Web\AccountCreationService())->create([
        'name' => 'QA Next Automatic Code', 'account_group_id' => $root['account_group_id'], 'parent_id' => $root['id'], 'code' => 'IGNORED-CLIENT-CODE',
    ]);
    verify(ctype_digit((string) $automaticAccount['code']) && $automaticAccount['code'] !== $nextAutomaticAccount['code'], 'Generate unique account codes on the backend without user input.');
    verify($nextAutomaticAccount['code'] !== 'IGNORED-CLIENT-CODE', 'Ignore any client-supplied account code in the web form.');

    $chartService = new ChartOfAccountsService();
    $assetCategories = (new App\Services\Web\ChartOfAccountsPresenter())->categories($chartService->list(), $chartService->groups(), [], 'ASSET');
    $cashCategory = array_values(array_filter($assetCategories, static fn ($category) => $category['label'] === 'Cash'))[0];
    verify(in_array((int) $account['id'], array_column($cashCategory['rows'], 'id'), true), 'AJAX chart presentation keeps custom accounts under their selected type.');
    $accountGroupResponse = controller()->accountGroup();
    $accountGroupData = json_decode($accountGroupResponse->getBody(), true)['data'];
    verify($accountGroupData['group'] === 'ASSET' && str_contains($accountGroupData['html'], '<details'), 'The account-group endpoint returns accordion content without a full page.');

    $request = new IncomingRequest(config(Config\App::class), new URI('http://localhost/api/v1/journal-entries'), json_encode([
        'entry_date' => '2090-03-01', 'description' => 'Web form smoke test',
        'lines' => [
            ['account_id' => (int) $root['id'], 'debit' => 25, 'credit' => 0],
            ['account_id' => (int) $account['id'], 'debit' => 0, 'credit' => 25],
        ],
    ]), new CodeIgniter\HTTP\UserAgent());
    $request->setHeader('Content-Type', 'application/json');
    $journalController = new App\Controllers\Accounting\JournalController();
    $journalController->initController($request, new Response(config(Config\App::class)), service('logger'));
    $posted = $journalController->create();
    verify($posted->getStatusCode() === 201, 'The dedicated journal form payload posts through the unchanged API.');

    $bad = controller(['name' => 'Bad payment', 'code' => 'web_qa_bad', 'account_id' => 99999999])->savePosPaymentMethod();
    verify($bad->getStatusCode() === 422, 'Reject payment methods linked to nonexistent accounts.');
    $good = controller(['name' => 'QA Cash', 'code' => 'ignored_manual_code', 'account_id' => $root['id'], 'type' => 'cash', 'is_active' => 0, 'sort_order' => -50])->savePosPaymentMethod();
    verify($good->getStatusCode() === 200, 'Save a payment method linked to a valid asset account.');
    $duplicate = controller(['name' => 'QA Cash', 'account_id' => $root['id']])->savePosPaymentMethod();
    verify($duplicate->getStatusCode() === 422, 'Reject duplicate display labels without a manual code field.');

    $createdMethod = $db->table('pos_payment_methods')->where('name', 'QA Cash')->get()->getRowArray();
    verify($createdMethod['code'] === 'QA Cash' && (int) $createdMethod['is_active'] === 1, 'Derive code from label and enable new methods regardless of hidden client fields.');
    $createdMethodId = (int) $createdMethod['id'];
    $otherMethods = $db->table('pos_payment_methods')->where('id !=', $createdMethodId)->get()->getResultArray();
    $previousMaximum = $otherMethods === [] ? 0 : max(array_column($otherMethods, 'sort_order'));
    verify((int) $createdMethod['sort_order'] === $previousMaximum + 1, 'New methods append to the current POS order automatically.');
    controller()->togglePosPaymentMethod($createdMethodId);
    $originalOrder = (int) $createdMethod['sort_order'];
    controller(['id' => $createdMethodId, 'name' => 'QA Cash Renamed', 'account_id' => $root['id'], 'type' => 'cash', 'is_active' => 1, 'sort_order' => 999])->savePosPaymentMethod();
    $editedMethod = $db->table('pos_payment_methods')->where('id', $createdMethodId)->get()->getRowArray();
    verify((int) $editedMethod['is_active'] === 0 && (int) $editedMethod['sort_order'] === $originalOrder, 'Editing a method preserves its separately managed status and order.');
    verify($editedMethod['code'] === $editedMethod['name'], 'Renaming keeps the derived method code in sync.');

    $orderedIds = array_map('intval', array_column($db->table('pos_payment_methods')->orderBy('sort_order')->orderBy('id')->get()->getResultArray(), 'id'));
    $reversedIds = array_reverse($orderedIds);
    $reordered = controller(['ordered_ids' => $reversedIds])->reorderPaymentMethods();
    verify($reordered->getStatusCode() === 200, 'Save a complete dragged payment-method order.');
    $savedIds = array_map('intval', array_column($db->table('pos_payment_methods')->orderBy('sort_order')->get()->getResultArray(), 'id'));
    verify($savedIds === $reversedIds, 'Persist the exact visual order used by the POS list.');
    $invalidOrder = controller(['ordered_ids' => [$createdMethodId, $createdMethodId]])->reorderPaymentMethods();
    verify($invalidOrder->getStatusCode() === 422, 'Reject duplicate reorder IDs.');
    $incompleteOrder = controller(['ordered_ids' => [$createdMethodId]])->reorderPaymentMethods();
    verify($incompleteOrder->getStatusCode() === 422, 'Reject incomplete or stale reorder sets without partial writes.');
    $unchangedIds = array_map('intval', array_column($db->table('pos_payment_methods')->orderBy('sort_order')->get()->getResultArray(), 'id'));
    verify($unchangedIds === $reversedIds, 'Invalid reorder requests leave the saved order unchanged.');
    AuthContext::$permissions = ['accounting.view'];
    try {
        controller(['ordered_ids' => $orderedIds])->reorderPaymentMethods();
        throw new RuntimeException('Reorder permission check was bypassed.');
    } catch (CodeIgniter\HTTP\Exceptions\HTTPException $exception) {
        verify($exception->getCode() === 403, 'Read-only users cannot reorder payment methods.');
    }
} finally {
    foreach (array_reverse($tables) as $table) $db->query("DROP TEMPORARY TABLE IF EXISTS `$table`");
    foreach ($originalCounts as $table => $count) {
        verify($db->table($table)->countAllResults() === $count, "$table original records remain unchanged.");
    }
    AuthContext::reset();
}

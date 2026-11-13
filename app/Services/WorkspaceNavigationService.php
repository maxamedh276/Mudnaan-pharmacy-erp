<?php

namespace App\Services;

use App\Libraries\AdministrativeAccess;
use App\Libraries\AuthContext;

/** One permission-filtered navigation map for module cards, tabs and search. */
final class WorkspaceNavigationService
{
    public function modules(): array
    {
        $user = session()->get('user') ?? [];
        $menus = new MenuPermissionService();
        $owner = AdministrativeAccess::isFixedRole($user['role_name'] ?? '');
        $allowed = $owner ? $menus->getAllMenuKeys() : $menus->getUserMenuKeys((int) ($user['id'] ?? 0), (int) ($user['role_id'] ?? 0));
        $canMenu = static fn (string $key): bool => $owner || in_array($key, $allowed, true);
        $modules = [];
        $add = static function (string $module, string $label, string $icon, string $description, string $group, string $groupLabel, string $route, string $pageLabel, string $hint) use (&$modules): void {
            if (!isset($modules[$module])) $modules[$module] = ['id'=>$module, 'label'=>$label, 'icon'=>$icon, 'description'=>$description, 'groups'=>[]];
            if (!isset($modules[$module]['groups'][$group])) $modules[$module]['groups'][$group] = ['label'=>$groupLabel, 'pages'=>[]];
            $modules[$module]['groups'][$group]['pages'][] = ['route'=>$route, 'label'=>$pageLabel, 'description'=>$hint];
        };
        $definitions = [
            'home'=>['Home','layout-grid','Your pharmacy, at a glance.'],
            'inventory'=>['Medicines & Stock','pill','Catalog, units, batches and stock control.'],
            'clinical'=>['Patients & Prescriptions','heart-pulse','Patient records and prescription approvals.'],
            'sales'=>['Sales & Dispensing','scan-line','Dispense medicines and manage sales.'],
            'suppliers'=>['Purchasing','package-plus','Suppliers, purchase orders and receiving.'],
            'clients'=>['Customers','users','Customer accounts, receipts and credit.'],
            'finance'=>['Finance','landmark','Accounts, journals and payment settings.'],
            'reports'=>['Reports & Insights','chart-no-axes-combined','Operational and financial reporting.'],
            'users'=>['Administration','shield-check','People, access and pharmacy settings.'],
        ];
        $page = static function (string $module, string $group, string $groupLabel, string $route, string $label, string $hint) use ($add, $definitions): void {
            $add($module, ...[...$definitions[$module], $group, $groupLabel, $route, $label, $hint]);
        };
        if (AuthContext::can('pharmacy.view')) {
            $page('home','overview','Overview','pharmacy/overview','Pharmacy Overview','View stock, expiry alerts and daily pharmacy activity.');
            $page('inventory','catalog','Medicine Catalog','pharmacy/medicines','Medicines & Units','Manage dosage forms, strengths, packaging and selling units.');
            if (AuthContext::can('pharmacy.medicines.manage')) $page('inventory','catalog','Medicine Catalog','pharmacy/imports','Medicine Import','Preview and import medicine profiles, selling units and opening batches.');
            $page('inventory','stock','Stock Control','pharmacy/stock','Batches & Stock','Receive opening stock, manage batches, quarantine and disposal.');
            $page('inventory','stock','Stock Control','pharmacy/storage','Storage & Cold Chain','Storage locations, temperature observations and excursion records.');
            $page('inventory','stock','Stock Control','pharmacy/opened-packs','Opened Packs & Use-by','Open packaging into traceable lots and enforce recorded use-by times.');
            $page('inventory','stock','Stock Control','pharmacy/counts','Physical Stock Counts','Count batches, review variances and approve reconciled adjustments.');
            $page('reports','operations','Operations','pharmacy/reports','Pharmacy Reports','Review expiry, low stock and sellable batch balances.');
        }
        if (AuthContext::can('pharmacy.patients.view')) $page('clinical','care','Patient Care','pharmacy/patients','Patients','Patient details, allergies and contact information.');
        if (AuthContext::can('pharmacy.prescriptions.view')) $page('clinical','care','Patient Care','pharmacy/prescriptions','Prescriptions','Record, approve and track prescription dispensing.');
        if (AuthContext::can('sales.create')) {
            $page('sales','checkout','Dispensing','pharmacy/dispense','Dispensing Workspace','Scan medicines, select units and complete a sale.');
            $page('sales','checkout','Dispensing','pharmacy/cash-shifts','Cash Shifts','Open a drawer, reconcile recorded cash movements and retain closing differences.');
        }

        // Keep existing menu grants and extra action restrictions intact.
        foreach (MenuPermissionService::MENU_STRUCTURE as $section) {
            foreach ($section['groups'] ?? [] as $module=>$definition) {
                foreach ($definition['items'] as $key=>$item) {
                    if (!$canMenu($key)) continue;
                    if ($key === 'sales.returns' && !AuthContext::can('sales.refund')) continue;
                    if ($key === 'suppliers.returns' && !AuthContext::can('purchases.return')) continue;
                    if ($key === 'sales.pos') continue; // Pharmacy POS is registered above with its action permission.
                    [$group, $groupLabel] = match ($module) {
                        'inventory' => str_contains($key, 'stock') || str_contains($key, 'adjustments') ? ['stock','Stock Control'] : ['catalog','Medicine Catalog'],
                        'suppliers' => str_starts_with($key, 'finance.') ? ['settlement','Bills & Payments'] : ['procurement','Procurement'],
                        'finance' => in_array($key, ['finance.pos_methods','finance.taxes','finance.periods'], true) ? ['configuration','Configuration'] : ['accounting','Accounting'],
                        'reports' => str_starts_with($key, 'reports.') && in_array(substr($key,8), ['financial','income-statement','balance-sheet','cash-flow','trial-balance'],true) ? ['financial','Financial Statements'] : ['operations','Operations'],
                        'users' => ['access','People & Access'],
                        'clients' => ['accounts','Customer Accounts'],
                        default => ['transactions','Sales & Returns'],
                    };
                    $page($module,$group,$groupLabel,$item['route'],$item['label'],$item['desc']);
                }
            }
        }
        if ($canMenu('inventory.products')) $page('inventory','catalog','Medicine Catalog','inventory/selling-options','Selling Options','Manage additional catalog selling options.');
        if (AuthContext::can('settings.manage')) $page('users','settings','Settings','settings','Pharmacy Settings','Business identity, appearance and receipt settings.');
        if (AuthContext::can('settings.operational')) $page('users','settings','Settings','settings/operational','Operational Settings','Configure protected operational settings.');
        // Stable ordering regardless of which permission contributed the first page.
        return array_replace(array_intersect_key($definitions, $modules), $modules);
    }

    public function context(array $modules, string $uri, ?string $requestedGroup = null): array
    {
        $moduleId = 'home'; $groupId = null; $activeRoute = null; $bestLength = -1;
        if (preg_match('~^workspace(?:/([a-z]+))?$~', $uri, $match)) {
            $moduleId = $match[1] ?? 'home';
        } else {
            foreach ($modules as $id=>$module) foreach ($module['groups'] as $group=>$info) foreach ($info['pages'] as $page) {
                $route = $page['route'];
                if (($uri === $route || str_starts_with($uri, $route.'/')) && strlen($route) > $bestLength) {
                    $moduleId=$id; $groupId=$group; $activeRoute=$route; $bestLength=strlen($route);
                }
            }
            if (in_array($uri, ['','dashboard','pharmacy'],true)) $moduleId='home';
            if (in_array($uri,['pos','sales/pos'],true) && isset($modules['sales'])) { $moduleId='sales'; $groupId=array_key_first($modules['sales']['groups']); }
        }
        $module = $modules[$moduleId] ?? null;
        if ($module && !$groupId) $groupId = isset($module['groups'][$requestedGroup ?? '']) ? $requestedGroup : array_key_first($module['groups']);
        return compact('moduleId','module','groupId','activeRoute');
    }
}

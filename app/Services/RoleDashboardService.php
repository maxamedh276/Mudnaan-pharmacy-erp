<?php

namespace App\Services;

use App\Libraries\AdministrativeAccess;
use App\Libraries\AuthContext;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Profile, branch and actor are derived on the server, never from dashboard query parameters. */
class RoleDashboardService
{
    public function profile(): string
    {
        $role = strtolower(trim(AuthContext::$user->role_name ?? ''));
        if (AdministrativeAccess::isFixedRole($role) || $role === 'manager') return 'admin';
        if ($role === 'cashier') return 'cashier';
        return $role === 'accountant' || AuthContext::can('accounting.view') ? 'accountant' : 'cashier';
    }

    public function get(mixed $range = null): array
    {
        $profile = $this->profile();
        $branch = (int)(AuthContext::$user->branch_id ?? 0);
        $actor = (int)(AuthContext::userId() ?? 0);
        if (!$actor || !$branch) throw new HTTPException('Sign in with an authorized branch to view this dashboard.', 403);
        $range ??= $profile === 'cashier' ? 'today' : 'month';
        if (!is_string($range)) throw new InvalidArgumentException('Select a valid dashboard date range.');
        $today = new DateTimeImmutable('today', new DateTimeZone('Africa/Nairobi'));
        $to = $range === 'yesterday' ? $today->modify('-1 day') : $today;
        $from = match ($range) {
            'today', 'yesterday' => $to,
            '7days' => $today->modify('-6 days'),
            '30days' => $today->modify('-29 days'),
            'month' => $today->modify('first day of this month'),
            default => throw new InvalidArgumentException('Select a valid dashboard date range.'),
        };
        $access = [
            'sales' => $profile !== 'accountant' && (AuthContext::can('sales.view') || AuthContext::can('sales.create')),
            'ledger' => $profile !== 'cashier' && AuthContext::isPremium() && (AuthContext::can('accounting.view') || AuthContext::can('reports.advanced')),
            'receivables' => $profile !== 'cashier' && (AuthContext::can('invoices.view') || AuthContext::can('invoices.manage') || AuthContext::can('reports.advanced')),
            'payables' => $profile !== 'cashier' && (AuthContext::can('bills.view') || AuthContext::can('bills.manage') || AuthContext::can('reports.advanced')),
            'stock' => $profile === 'admin' && AuthContext::can('stock.view'),
        ];
        return FinancialTransaction::run(function ($db) use ($profile, $branch, $actor, $range, $from, $to, $access) {
            $start = $from->format('Y-m-d');
            $end = $to->format('Y-m-d');
            $result = ['profile' => $profile, 'access' => $access, 'range' => $range, 'from' => $start, 'to' => $end, 'updated_at' => (new DateTimeImmutable('now', new DateTimeZone('Africa/Nairobi')))->format('H:i'), 'name' => AuthContext::$user->name ?? 'there', 'sales' => null, 'finance' => null, 'stock' => [], 'activity' => []];
            if ($access['sales']) $result['sales'] = (new DashboardSalesService())->get($branch, $actor, $start, $end, $profile === 'cashier');
            if ($access['ledger'] || $access['receivables'] || $access['payables']) $result['finance'] = (new DashboardAccountingService())->get($branch, $start, $end, $access);
            if ($access['stock']) {
                $result['stock'] = $db->query('SELECT p.name,p.unit,p.reorder_level,COALESCE(s.quantity,0) quantity FROM products p LEFT JOIN stock_levels s ON s.product_id=p.id AND s.branch_id=? WHERE p.is_active=1 AND p.deleted_at IS NULL AND COALESCE(s.quantity,0)<=p.reorder_level ORDER BY quantity,p.name LIMIT 6', [$branch])->getResultArray();
                $result['low_stock_count'] = (int)$db->query('SELECT COUNT(*) value FROM products p LEFT JOIN stock_levels s ON s.product_id=p.id AND s.branch_id=? WHERE p.is_active=1 AND p.deleted_at IS NULL AND COALESCE(s.quantity,0)<=p.reorder_level', [$branch])->getRow('value');
            }
            if ($profile === 'admin') {
                foreach (array_slice($result['sales']['orders'] ?? [], 0, 3) as $order) $result['activity'][] = ['label' => 'Sale ' . $order['reference'], 'detail' => $order['customer'], 'date' => $order['date']];
                foreach (array_slice($result['sales']['receipts'] ?? [], 0, 3) as $receipt) $result['activity'][] = ['label' => 'Receipt ' . $receipt['reference'], 'detail' => $receipt['customer'], 'date' => $receipt['date']];
                if ($access['sales'] || $access['payables']) {
                    $kinds = $access['sales'] && $access['payables'] ? ['sale', 'purchase'] : [$access['sales'] ? 'sale' : 'purchase'];
                    foreach ($db->table('document_returns')->select('return_no,source_no,party_name,returned_at,kind')->where('branch_id', $branch)->where('returned_at >=', $start)->where('returned_at <=', $end . ' 23:59:59')->whereIn('kind', $kinds)->orderBy('returned_at', 'DESC')->limit(3)->get()->getResultArray() as $row) $result['activity'][] = ['label' => ($row['kind'] === 'sale' ? 'Sales return ' : 'Purchase return ') . $row['return_no'], 'detail' => $row['source_no'] . ' · ' . $row['party_name'], 'date' => $row['returned_at']];
                }
                if ($access['payables']) {
                    foreach ($db->query("SELECT p.payment_no,p.paid_at,s.name FROM supplier_payments p LEFT JOIN suppliers s ON s.id=p.supplier_id WHERE p.branch_id=? AND p.paid_at>=? AND p.paid_at<=? AND (p.status='active' OR p.voided_at>?) ORDER BY p.paid_at DESC,p.id DESC LIMIT 3", [$branch, $start, $end . ' 23:59:59', $end . ' 23:59:59'])->getResultArray() as $row) $result['activity'][] = ['label' => 'Supplier payment ' . $row['payment_no'], 'detail' => $row['name'] ?? 'Supplier', 'date' => $row['paid_at']];
                }
                if (AuthContext::can('users.manage')) {
                    foreach ($db->query("SELECT a.action,a.created_at,u.name FROM audit_log a JOIN users u ON u.id=a.user_id WHERE u.branch_id=? AND a.module IN ('users','roles') AND a.created_at>=? AND a.created_at<=? ORDER BY a.id DESC LIMIT 3", [$branch, $start, $end . ' 23:59:59'])->getResultArray() as $row) $result['activity'][] = ['label' => ucwords(str_replace(['.', '_'], ' ', $row['action'])), 'detail' => $row['name'], 'date' => $row['created_at']];
                }
                usort($result['activity'], static fn($a, $b) => strcmp($b['date'], $a['date']));
                $result['activity'] = array_slice($result['activity'], 0, 5);
            }
            return $result;
        });
    }
}

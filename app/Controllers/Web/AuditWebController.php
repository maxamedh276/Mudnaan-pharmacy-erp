<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use Config\Database;

class AuditWebController extends BaseController
{
    public function index(): string
    {
        if (!\App\Libraries\AuthContext::can('settings.manage')) throw new \CodeIgniter\HTTP\Exceptions\HTTPException('You do not have access to this page.',403);
        $db = Database::connect();

        // 1. Fetch audit logs with actor and role details
        $auditLogs = $db->table('audit_log')
            ->select('audit_log.*, users.name as user_name, users.email as user_email, roles.name as user_role_name')
            ->join('users', 'users.id = audit_log.user_id', 'left')
            ->join('roles', 'roles.id = users.role_id', 'left')
            ->orderBy('audit_log.id', 'DESC')
            ->limit(1000)
            ->get()
            ->getResultArray();

        // 2. Fetch distinct staff members for the Actor filter
        $actors = $db->table('users')
            ->select('users.id, users.name, users.email, roles.name as role_name')
            ->join('roles', 'roles.id = users.role_id', 'left')
            ->orderBy('users.name', 'ASC')
            ->get()
            ->getResultArray();

        // 3. Fetch distinct modules for the Module filter
        $modulesQuery = $db->table('audit_log')
            ->select('DISTINCT(module) as module')
            ->where('module IS NOT NULL')
            ->where('module !=', '')
            ->orderBy('module', 'ASC')
            ->get()
            ->getResultArray();
        $modules = array_column($modulesQuery, 'module');

        // 4. Calculate KPI statistics
        $totalLogs = count($auditLogs);
        $todayCount = 0;
        $authCount = 0;
        $adminChangesCount = 0;
        $todayDate = date('Y-m-d');

        foreach ($auditLogs as $log) {
            $logDate = substr((string)$log['created_at'], 0, 10);
            if ($logDate === $todayDate) {
                $todayCount++;
            }
            if ($log['module'] === 'auth' || str_starts_with((string)$log['action'], 'auth.')) {
                $authCount++;
            }
            if (
                str_contains((string)$log['action'], '.create') || 
                str_contains((string)$log['action'], '.update') || 
                str_contains((string)$log['action'], '.delete') ||
                str_contains((string)$log['action'], '.void') ||
                str_contains((string)$log['action'], 'settings.')
            ) {
                $adminChangesCount++;
            }
        }

        return view('web/audit/index', [
            'title'             => 'Audit Trail & Security Log — Mudnaan Pharmacy',
            'auditLogs'         => $auditLogs,
            'actors'            => $actors,
            'modules'           => $modules,
            'totalLogs'         => $totalLogs,
            'todayCount'        => $todayCount,
            'authCount'         => $authCount,
            'adminChangesCount' => $adminChangesCount,
            'user'              => session()->get('user') ?? [],
            'tenant'            => session()->get('tenant') ?? [],
            'plan'              => session()->get('plan') ?? 'premium',
        ]);
    }
}

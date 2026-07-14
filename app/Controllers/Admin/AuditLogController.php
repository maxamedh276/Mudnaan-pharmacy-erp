<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The audit trail (Premium). Read-only listing with filters so every
 * financial and administrative action can be traced. Requires
 * settings.manage — an Owner-level permission.
 */
class AuditLogController extends BaseApiController
{
    /**
     * GET /audit-log?date_from=&date_to=&user_id=&module=&action=&entity=&q=
     */
    public function index(): ResponseInterface
    {
        if ($denied = $this->requirePermission('settings.manage')) {
            return $denied;
        }

        ['page' => $page, 'perPage' => $perPage] = $this->paginationParams(30);

        $builder = db_connect()->table('audit_log')
            ->select('audit_log.id, audit_log.user_id, users.name AS user_name,
                      audit_log.role, audit_log.action, audit_log.module,
                      audit_log.entity, audit_log.entity_id, audit_log.details,
                      audit_log.before_values, audit_log.after_values,
                      audit_log.ip_address, audit_log.user_agent, audit_log.created_at')
            ->join('users', 'users.id = audit_log.user_id', 'left');

        if ($from = $this->request->getGet('date_from')) {
            $builder->where('audit_log.created_at >=', $from . ' 00:00:00');
        }
        if ($to = $this->request->getGet('date_to')) {
            $builder->where('audit_log.created_at <=', $to . ' 23:59:59');
        }
        if ($userId = $this->request->getGet('user_id')) {
            $builder->where('audit_log.user_id', (int) $userId);
        }
        if ($module = $this->request->getGet('module')) {
            $builder->where('audit_log.module', $module);
        }
        if ($action = $this->request->getGet('action')) {
            $builder->where('audit_log.action', $action);
        }
        if ($entity = $this->request->getGet('entity')) {
            $builder->where('audit_log.entity', $entity);
        }
        if ($recordId = $this->request->getGet('record_id')) {
            $builder->where('audit_log.entity_id', (int) $recordId);
        }
        if ($q = $this->request->getGet('q')) {
            $builder->groupStart()
                ->like('audit_log.action', $q)
                ->orLike('audit_log.module', $q)
                ->orLike('users.name', $q)
                ->groupEnd();
        }

        $total = $builder->countAllResults(false);
        $rows  = $builder->orderBy('audit_log.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        foreach ($rows as &$row) {
            $row['id']        = (int) $row['id'];
            $row['user_id']   = $row['user_id'] === null ? null : (int) $row['user_id'];
            $row['entity_id'] = $row['entity_id'] === null ? null : (int) $row['entity_id'];
            $row['details']       = $this->decode($row['details']);
            $row['before_values'] = $this->decode($row['before_values']);
            $row['after_values']  = $this->decode($row['after_values']);
        }

        return $this->respondData($rows, 200, $this->paginationMeta($page, $perPage, $total));
    }

    /**
     * GET /audit-log/filters — distinct modules + actions for the UI.
     */
    public function filters(): ResponseInterface
    {
        if ($denied = $this->requirePermission('settings.manage')) {
            return $denied;
        }

        $database = db_connect();

        return $this->respondData([
            'modules' => array_column($database->table('audit_log')
                ->distinct()->select('module')->where('module IS NOT NULL')
                ->orderBy('module')->get()->getResultArray(), 'module'),
            'actions' => array_column($database->table('audit_log')
                ->distinct()->select('action')->orderBy('action')
                ->get()->getResultArray(), 'action'),
        ]);
    }

    private function decode(?string $json): mixed
    {
        return $json === null ? null : json_decode($json, true);
    }
}

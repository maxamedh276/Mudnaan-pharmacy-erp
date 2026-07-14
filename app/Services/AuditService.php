<?php

namespace App\Services;

use App\Libraries\AuthContext;

/**
 * Writes rich rows to audit_log so every money-affecting and admin
 * action can be traced to a user: who (id + role), where (module + ip +
 * device), what (action + record), and the before/after snapshots for
 * changes. Failures here never break the business operation.
 */
class AuditService
{
    /**
     * @param string     $action  e.g. 'supplier_payment.void'
     * @param string     $module  e.g. 'payables'
     * @param string     $entity  table name, e.g. 'supplier_payments'
     * @param array|null $before  record state before the change
     * @param array|null $after   record state after the change
     * @param array|null $details free-form extra context
     */
    public static function log(
        string $action,
        string $module,
        string $entity,
        ?int $entityId,
        ?array $before = null,
        ?array $after = null,
        ?array $details = null,
    ): void {
        try {
            $request = service('request');

            db_connect()->table('audit_log')->insert([
                'user_id'       => AuthContext::userId(),
                'role'          => AuthContext::$user->role_name ?? null,
                'action'        => $action,
                'module'        => $module,
                'entity'        => $entity,
                'entity_id'     => $entityId,
                'details'       => $details === null ? null : json_encode($details),
                'before_values' => $before === null ? null : json_encode($before),
                'after_values'  => $after === null ? null : json_encode($after),
                'ip_address'    => $request->getIPAddress(),
                'user_agent'    => substr((string) $request->getUserAgent(), 0, 255),
            ]);
        } catch (\Throwable $error) {
            log_message('error', 'audit_log write failed: ' . $error->getMessage());
        }
    }
}

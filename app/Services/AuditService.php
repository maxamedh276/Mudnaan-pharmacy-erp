<?php

namespace App\Services;

use App\Libraries\AuthContext;

/**
 * Writes rows to audit_log so money-affecting actions can always be
 * traced to a user. Failures here never break the business operation.
 */
class AuditService
{
    public static function log(string $action, string $entity, ?int $entityId, ?array $details = null): void
    {
        try {
            db_connect()->table('audit_log')->insert([
                'user_id'    => AuthContext::userId(),
                'action'     => $action,
                'entity'     => $entity,
                'entity_id'  => $entityId,
                'details'    => $details === null ? null : json_encode($details),
                'ip_address' => service('request')->getIPAddress(),
            ]);
        } catch (\Throwable $error) {
            log_message('error', 'audit_log write failed: ' . $error->getMessage());
        }
    }
}

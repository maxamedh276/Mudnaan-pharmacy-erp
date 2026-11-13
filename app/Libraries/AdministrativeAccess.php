<?php

namespace App\Libraries;

use CodeIgniter\HTTP\Exceptions\HTTPException;

/** Administrative tiers are server-defined; editing permission rows cannot promote a role. */
final class AdministrativeAccess
{
    public static function isSuperadminRole(string $name): bool
    {
        // Owner is retained as a compatibility alias until a tenant has been upgraded.
        return in_array(strtolower(trim($name)), ['superadmin', 'owner'], true);
    }

    public static function isAdminRole(string $name): bool
    {
        return strtolower(trim($name)) === 'admin';
    }

    public static function isFixedRole(string $name): bool
    {
        return self::isSuperadminRole($name) || self::isAdminRole($name);
    }

    public static function assertRoleEditable(array $role): void
    {
        if (self::isFixedRole($role['name'])) {
            throw new HTTPException('Superadmin and Admin have fixed access rules.', 403);
        }
    }

    public static function assertRoleAssignable(int $roleId): void
    {
        $role = db_connect()->table('roles')->where('id', $roleId)->get()->getRowArray();
        if ($role && self::isSuperadminRole($role['name']) && !AuthContext::isSuperadmin()) {
            throw new HTTPException('Only a Superadmin can assign the Superadmin role.', 403);
        }
    }

    public static function assertUserManageable(int $userId): void
    {
        // Protection follows the actor's role, not ownership: a Superadmin may manage
        // another Superadmin. No permission assignment can grant this to a lower tier.
        $user = db_connect()->table('users u')->select('r.name')
            ->join('roles r', 'r.id=u.role_id')->where('u.id', $userId)->get()->getRowArray();
        if ($user && self::isSuperadminRole($user['name']) && !AuthContext::isSuperadmin()) {
            throw new HTTPException('Only a Superadmin can change a Superadmin account.', 403);
        }
    }

    /** Serialize demotions/deactivations so concurrent requests cannot remove the last Superadmin. */
    public static function lockSuperadminRoles(): void
    {
        db_connect()->query("SELECT id FROM roles WHERE LOWER(TRIM(name)) IN ('superadmin','owner') ORDER BY id FOR UPDATE");
    }

    public static function assertOtherSuperadmin(int $userId): void
    {
        $others = db_connect()->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE LOWER(TRIM(r.name)) IN ('superadmin','owner') AND u.is_active=1 AND u.deleted_at IS NULL AND u.id<>? FOR UPDATE", [$userId])->getResultArray();
        if (!$others) {
            throw new HTTPException('Keep at least one active Superadmin account.', 403);
        }
    }
}

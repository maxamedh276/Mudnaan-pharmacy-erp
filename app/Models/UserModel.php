<?php

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table          = 'users';
    protected $primaryKey     = 'id';
    protected $returnType     = 'object';
    protected $useSoftDeletes = true;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $deletedField   = 'deleted_at';
    protected $allowedFields  = [
        'branch_id', 'role_id', 'name', 'email', 'phone',
        'password_hash', 'is_active', 'last_login_at',
    ];

    /**
     * Looks a login identity up by email OR phone (the login screen
     * accepts either). Includes the role name for the session payload.
     */
    public function findActiveByIdentity(string $identity): ?object
    {
        return $this->select('users.*, roles.name AS role_name')
            ->join('roles', 'roles.id = users.role_id')
            ->groupStart()
                ->where('users.email', $identity)
                ->orWhere('users.phone', $identity)
            ->groupEnd()
            ->where('users.is_active', 1)
            ->first();
    }

    public function findActiveById(int $userId): ?object
    {
        return $this->select('users.*, roles.name AS role_name')
            ->join('roles', 'roles.id = users.role_id')
            ->where('users.id', $userId)
            ->where('users.is_active', 1)
            ->first();
    }

    /**
     * @return list<string> permission codes attached to a role
     */
    public function permissionCodes(int $roleId): array
    {
        $rows = $this->db->table('role_permissions')
            ->select('permissions.code')
            ->join('permissions', 'permissions.id = role_permissions.permission_id')
            ->where('role_permissions.role_id', $roleId)
            ->get()
            ->getResultArray();

        return array_column($rows, 'code');
    }
}

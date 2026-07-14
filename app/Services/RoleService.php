<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Role and permission management. A role is a named bundle of
 * permission codes; changing a role's permissions takes effect on the
 * next request for every user who has it (permissions are re-read per
 * request in AuthFilter). System roles can be re-permissioned but not
 * deleted, and a role in use can't be deleted either.
 */
class RoleService
{
    /** @return list<array> roles with their permission codes + user count */
    public function list(): array
    {
        $database = db_connect();

        $roles = $database->table('roles')->orderBy('id')->get()->getResultArray();

        foreach ($roles as &$role) {
            $roleId = (int) $role['id'];
            $role['id']          = $roleId;
            $role['is_system']   = (bool) $role['is_system'];
            $role['permissions'] = $this->permissionCodes($roleId);
            $role['user_count']  = $database->table('users')
                ->where('role_id', $roleId)->where('deleted_at', null)->countAllResults();
        }

        return $roles;
    }

    public function find(int $roleId): ?array
    {
        $role = db_connect()->table('roles')->where('id', $roleId)->get()->getRowArray();

        if ($role === null) {
            return null;
        }

        $role['id']          = (int) $role['id'];
        $role['is_system']   = (bool) $role['is_system'];
        $role['permissions'] = $this->permissionCodes($roleId);

        return $role;
    }

    /** All permissions, for building role forms. */
    public function allPermissions(): array
    {
        return db_connect()->table('permissions')
            ->select('code, description')->orderBy('code')->get()->getResultArray();
    }

    /**
     * @param array $data {name, permissions: [codes]}
     */
    public function create(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            throw new InvalidArgumentException('NAME_REQUIRED');
        }

        $this->assertUniqueName($name);

        $database = db_connect();
        $database->transException(true)->transStart();

        $database->table('roles')->insert(['name' => $name, 'is_system' => 0]);
        $roleId = (int) $database->insertID();

        $this->syncPermissions($roleId, $data['permissions'] ?? []);

        $database->transComplete();

        AuditService::log('role.create', 'roles', 'roles', $roleId, after: ['name' => $name]);

        return $this->find($roleId);
    }

    /**
     * @param array $data {name?, permissions?: [codes]}
     */
    public function update(int $roleId, array $data): array
    {
        $database = db_connect();
        $role     = $database->table('roles')->where('id', $roleId)->get()->getRowArray();

        if ($role === null) {
            throw new InvalidArgumentException('ROLE_NOT_FOUND');
        }

        $database->transException(true)->transStart();

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                throw new InvalidArgumentException('NAME_REQUIRED');
            }
            $this->assertUniqueName($name, $roleId);
            $database->table('roles')->where('id', $roleId)->update(['name' => $name]);
        }

        if (array_key_exists('permissions', $data)) {
            $this->syncPermissions($roleId, $data['permissions'] ?? []);
        }

        $database->transComplete();

        AuditService::log('role.update', 'roles', 'roles', $roleId);

        return $this->find($roleId);
    }

    public function delete(int $roleId): void
    {
        $database = db_connect();
        $role     = $database->table('roles')->where('id', $roleId)->get()->getRowArray();

        if ($role === null) {
            throw new InvalidArgumentException('ROLE_NOT_FOUND');
        }
        if ((int) $role['is_system'] === 1) {
            throw new InvalidArgumentException('SYSTEM_ROLE');
        }

        $inUse = $database->table('users')
            ->where('role_id', $roleId)->where('deleted_at', null)->countAllResults() > 0;

        if ($inUse) {
            throw new InvalidArgumentException('ROLE_HAS_USERS');
        }

        // role_permissions cascade on the FK; remove the role itself.
        $database->table('roles')->where('id', $roleId)->delete();

        AuditService::log('role.delete', 'roles', 'roles', $roleId,
            before: ['name' => $role['name']]);
    }

    private function permissionCodes(int $roleId): array
    {
        return array_column(db_connect()->table('role_permissions')
            ->select('permissions.code')
            ->join('permissions', 'permissions.id = role_permissions.permission_id')
            ->where('role_permissions.role_id', $roleId)
            ->get()->getResultArray(), 'code');
    }

    /**
     * Replaces a role's permissions with exactly the given codes.
     * Unknown codes are ignored so a typo can't grant nothing silently
     * without warning — only real permissions are attached.
     */
    private function syncPermissions(int $roleId, array $codes): void
    {
        $database = db_connect();
        $database->table('role_permissions')->where('role_id', $roleId)->delete();

        if ($codes === []) {
            return;
        }

        $ids = $database->table('permissions')->select('id')
            ->whereIn('code', $codes)->get()->getResultArray();

        if ($ids === []) {
            return;
        }

        $database->table('role_permissions')->insertBatch(array_map(
            static fn (array $row): array => ['role_id' => $roleId, 'permission_id' => (int) $row['id']],
            $ids,
        ));
    }

    private function assertUniqueName(string $name, ?int $exceptId = null): void
    {
        $builder = db_connect()->table('roles')->where('name', $name);
        if ($exceptId !== null) {
            $builder->where('id !=', $exceptId);
        }

        if ($builder->countAllResults() > 0) {
            throw new InvalidArgumentException('ROLE_NAME_TAKEN');
        }
    }
}

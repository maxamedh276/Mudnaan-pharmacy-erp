<?php

namespace App\Services;

use App\Models\UserModel;
use App\Libraries\AdministrativeAccess;
use InvalidArgumentException;

/**
 * Staff user management. Enforces the rules that keep an account safe:
 * a login identity (email or phone), a real role, a strong-enough
 * password, unique email/phone, and you can never lock yourself out.
 */
class UserService
{
    private const MIN_PASSWORD = 6;

    public function __construct(
        private readonly UserModel $users = new UserModel(),
    ) {
    }

    public function list(?string $query = null): array
    {
        $builder = $this->users
            ->select('users.id, users.name, users.email, users.phone,
                      users.role_id, roles.name AS role_name,
                      users.branch_id, users.is_active, users.last_login_at')
            ->join('roles', 'roles.id = users.role_id')
            ->where('users.deleted_at', null);

        if ($query !== null && $query !== '') {
            $builder->groupStart()
                ->like('users.name', $query)
                ->orLike('users.email', $query)
                ->orLike('users.phone', $query)
                ->groupEnd();
        }

        return array_map([self::class, 'present'],
            $builder->orderBy('users.name', 'ASC')->findAll());
    }

    public function find(int $userId): ?array
    {
        $user = $this->users
            ->select('users.id, users.name, users.email, users.phone,
                      users.role_id, roles.name AS role_name,
                      users.branch_id, users.is_active, users.last_login_at')
            ->join('roles', 'roles.id = users.role_id')
            ->where('users.id', $userId)
            ->where('users.deleted_at', null)
            ->first();

        return $user === null ? null : self::present($user);
    }

    /**
     * @param array $data {name, email?, phone?, password, role_id, branch_id?}
     */
    public function create(array $data): array
    {
        $name     = trim((string) ($data['name'] ?? ''));
        $email    = $this->clean($data['email'] ?? null);
        $phone    = $this->clean($data['phone'] ?? null);
        $password = (string) ($data['password'] ?? '');
        $roleId   = (int) ($data['role_id'] ?? 0);

        if ($name === '') {
            throw new InvalidArgumentException('NAME_REQUIRED');
        }
        if ($email === null && $phone === null) {
            throw new InvalidArgumentException('IDENTITY_REQUIRED');
        }
        if (strlen($password) < self::MIN_PASSWORD) {
            throw new InvalidArgumentException('WEAK_PASSWORD');
        }

        $this->assertRoleExists($roleId);
        AdministrativeAccess::assertRoleAssignable($roleId);
        $this->assertUniqueEmail($email);
        $this->assertUniquePhone($phone);

        return FinancialTransaction::run(function () use ($name, $email, $phone, $password, $roleId, $data) {
            $userId = (int) $this->users->insert([
                'name'          => $name,
                'email'         => $email,
                'phone'         => $phone,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role_id'       => $roleId,
                'branch_id'     => $data['branch_id'] ?? 1,
                'is_active'     => 1,
            ]);

            AuditService::log('user.create', 'users', 'users', $userId, after: [
                'name' => $name, 'email' => $email, 'role_id' => $roleId,
            ]);

            return $this->find($userId);
        });
    }

    public function update(int $userId, array $data): array
    {
        return FinancialTransaction::run(function () use ($userId, $data) {
            AdministrativeAccess::lockSuperadminRoles();
            AdministrativeAccess::assertUserManageable($userId);
            $existing = $this->users->where('deleted_at', null)->find($userId);

            if ($existing === null) {
                throw new InvalidArgumentException('USER_NOT_FOUND');
            }

            $fields = [];

            if (array_key_exists('name', $data)) {
                $name = trim((string) $data['name']);
                if ($name === '') {
                    throw new InvalidArgumentException('NAME_REQUIRED');
                }
                $fields['name'] = $name;
            }

            if (array_key_exists('email', $data)) {
                $email = $this->clean($data['email']);
                $this->assertUniqueEmail($email, $userId);
                $fields['email'] = $email;
            }

            if (array_key_exists('phone', $data)) {
                $phone = $this->clean($data['phone']);
                $this->assertUniquePhone($phone, $userId);
                $fields['phone'] = $phone;
            }

            if (array_key_exists('role_id', $data)) {
                $roleId = (int) $data['role_id'];
                $this->assertRoleExists($roleId);
                AdministrativeAccess::assertRoleAssignable($roleId);
                $oldRole = db_connect()->table('roles')->where('id', $existing->role_id)->get()->getRowArray();
                $newRole = db_connect()->table('roles')->where('id', $roleId)->get()->getRowArray();
                if ((bool)$existing->is_active && AdministrativeAccess::isSuperadminRole($oldRole['name'] ?? '')
                    && !AdministrativeAccess::isSuperadminRole($newRole['name'] ?? '')) {
                    AdministrativeAccess::assertOtherSuperadmin($userId);
                }
                $fields['role_id'] = $roleId;
            }

            if (array_key_exists('branch_id', $data)) {
                $fields['branch_id'] = $data['branch_id'];
            }

            if (! empty($data['password'])) {
                if (strlen((string) $data['password']) < self::MIN_PASSWORD) {
                    throw new InvalidArgumentException('WEAK_PASSWORD');
                }
                $fields['password_hash'] = password_hash((string) $data['password'], PASSWORD_DEFAULT);
            }

            $email = array_key_exists('email', $fields) ? $fields['email'] : $existing->email;
            $phone = array_key_exists('phone', $fields) ? $fields['phone'] : $existing->phone;
            if (empty($email) && empty($phone)) {
                throw new InvalidArgumentException('IDENTITY_REQUIRED');
            }

            if ($fields !== []) {
                $this->users->update($userId, $fields);
                AuditService::log('user.update', 'users', 'users', $userId,
                    before: ['role_id' => $existing->role_id],
                    after: array_diff_key($fields, ['password_hash' => '']));
            }

            return $this->find($userId);
        });
    }

    public function setActive(int $userId, bool $active, int $actingUserId): array
    {
        if ($userId === $actingUserId && ! $active) {
            throw new InvalidArgumentException('CANNOT_DEACTIVATE_SELF');
        }

        return FinancialTransaction::run(function () use ($userId, $active) {
            AdministrativeAccess::lockSuperadminRoles();
            AdministrativeAccess::assertUserManageable($userId);
            $existing = $this->users->where('deleted_at', null)->find($userId);

            if ($existing === null) {
                throw new InvalidArgumentException('USER_NOT_FOUND');
            }

            $role = db_connect()->table('roles')->where('id', $existing->role_id)->get()->getRowArray();
            if (!$active && (bool)$existing->is_active && AdministrativeAccess::isSuperadminRole($role['name'] ?? '')) {
                AdministrativeAccess::assertOtherSuperadmin($userId);
            }

            $this->users->update($userId, ['is_active' => $active ? 1 : 0]);

            AuditService::log('user.' . ($active ? 'activate' : 'deactivate'),
                'users', 'users', $userId);

            return $this->find($userId);
        });
    }

    private function assertRoleExists(int $roleId): void
    {
        $exists = $this->users->db->table('roles')->where('id', $roleId)->countAllResults() > 0;

        if (! $exists) {
            throw new InvalidArgumentException('ROLE_NOT_FOUND');
        }
    }

    private function assertUniqueEmail(?string $email, ?int $exceptId = null): void
    {
        if ($email === null) {
            return;
        }

        $builder = $this->users->where('email', $email)->where('deleted_at', null);
        if ($exceptId !== null) {
            $builder->where('id !=', $exceptId);
        }

        if ($builder->countAllResults() > 0) {
            throw new InvalidArgumentException('EMAIL_TAKEN');
        }
    }

    private function assertUniquePhone(?string $phone, ?int $exceptId = null): void
    {
        if ($phone === null) {
            return;
        }

        $builder = $this->users->where('phone', $phone)->where('deleted_at', null);
        if ($exceptId !== null) {
            $builder->where('id !=', $exceptId);
        }

        if ($builder->countAllResults() > 0) {
            throw new InvalidArgumentException('PHONE_TAKEN');
        }
    }

    private function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return ($value === null || $value === '') ? null : $value;
    }

    private static function present(object $user): array
    {
        return [
            'id'            => (int) $user->id,
            'name'          => $user->name,
            'email'         => $user->email,
            'phone'         => $user->phone,
            'role_id'       => (int) $user->role_id,
            'role'          => $user->role_name,
            'branch_id'     => $user->branch_id === null ? null : (int) $user->branch_id,
            'is_active'     => (bool) $user->is_active,
            'last_login_at' => $user->last_login_at,
        ];
    }
}

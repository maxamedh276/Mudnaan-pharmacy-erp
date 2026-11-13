<?php

namespace App\Services;

/** Idempotent tenant upgrade. Existing Owner accounts keep their role ID and full access. */
class AdministrativeRolesService
{
    public function upgrade(): void
    {
        FinancialTransaction::run(function ($db) {
            $permission = $db->table('permissions')->where('code', 'settings.operational')->get()->getRowArray();
            if (!$permission) {
                $db->table('permissions')->insert(['code' => 'settings.operational', 'description' => 'Superadmin only: operational configuration']);
            }
            $superadmin = $db->table('roles')->where('name', 'Superadmin')->get()->getRowArray();
            $owner = $db->table('roles')->where('name', 'Owner')->get()->getRowArray();
            if (!$superadmin && $owner) {
                $db->table('roles')->where('id', $owner['id'])->update(['name' => 'Superadmin', 'is_system' => 1]);
            }
            foreach (['Superadmin', 'Admin'] as $name) {
                $role = $db->table('roles')->where('name', $name)->get()->getRowArray();
                if (!$role) {
                    $db->table('roles')->insert(['name' => $name, 'is_system' => 1]);
                    $roleId = (int)$db->insertID();
                } else {
                    $roleId = (int)$role['id'];
                    $db->table('roles')->where('id', $roleId)->update(['is_system' => 1]);
                }
                $db->table('role_permissions')->where('role_id', $roleId)->delete();
                $permissions = $db->table('permissions')->select('id,code')->get()->getResultArray();
                $rows = [];
                foreach ($permissions as $permission) {
                    if ($name === 'Admin' && $permission['code'] === 'settings.operational') continue;
                    $rows[] = ['role_id' => $roleId, 'permission_id' => $permission['id']];
                }
                if ($rows) $db->table('role_permissions')->insertBatch($rows);
            }
        });
    }
}

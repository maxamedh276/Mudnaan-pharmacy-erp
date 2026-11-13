<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseApiController;
use App\Services\MenuPermissionService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * REST API for configuring granular sidebar menu visibility by Role or User.
 */
class MenuPermissionsController extends BaseApiController
{
    private MenuPermissionService $service;

    public function __construct()
    {
        $this->service = new MenuPermissionService();
    }

    /**
     * GET api/v1/menu-permissions/role/{roleId}
     */
    public function getRole(int $roleId): ResponseInterface
    {
        if ($denied = $this->requirePermission('users.manage')) {
            return $denied;
        }

        $keys = $this->service->getRoleMenuKeys($roleId);

        return $this->respondData([
            'role_id'   => $roleId,
            'menu_keys' => $keys,
        ]);
    }

    /**
     * PUT api/v1/menu-permissions/role/{roleId}
     */
    public function updateRole(int $roleId): ResponseInterface
    {
        if ($denied = $this->requirePermission('users.manage')) {
            return $denied;
        }

        $body = $this->body();
        $keys = (array) ($body['menu_keys'] ?? []);

        $this->service->saveRoleMenuKeys($roleId, $keys);

        return $this->respondData([
            'role_id'   => $roleId,
            'menu_keys' => $this->service->getRoleMenuKeys($roleId),
            'message'   => 'Role menu permissions updated successfully.',
        ]);
    }

    /**
     * GET api/v1/menu-permissions/user/{userId}
     */
    public function getUser(int $userId): ResponseInterface
    {
        if ($denied = $this->requirePermission('users.manage')) {
            return $denied;
        }

        $user = db_connect()->table('users')->where('id', $userId)->get()->getRowArray();
        if (! $user) {
            return $this->respondNotFound('User');
        }

        $roleId = (int) $user['role_id'];
        $hasCustom = $this->service->hasCustomUserOverrides($userId);
        $keys = $this->service->getUserMenuKeys($userId, $roleId);

        return $this->respondData([
            'user_id'            => $userId,
            'role_id'            => $roleId,
            'has_custom_overrides' => $hasCustom,
            'menu_keys'          => $keys,
        ]);
    }

    /**
     * PUT api/v1/menu-permissions/user/{userId}
     */
    public function updateUser(int $userId): ResponseInterface
    {
        if ($denied = $this->requirePermission('users.manage')) {
            return $denied;
        }

        $user = db_connect()->table('users')->where('id', $userId)->get()->getRowArray();
        if (! $user) {
            return $this->respondNotFound('User');
        }

        $body = $this->body();
        $keys = (array) ($body['menu_keys'] ?? []);

        $this->service->saveUserMenuKeys($userId, $keys);

        return $this->respondData([
            'user_id'            => $userId,
            'has_custom_overrides' => true,
            'menu_keys'          => $this->service->getUserMenuKeys($userId, (int) $user['role_id']),
            'message'            => 'User custom menu permissions updated successfully.',
        ]);
    }

    /**
     * POST api/v1/menu-permissions/user/{userId}/reset
     */
    public function resetUser(int $userId): ResponseInterface
    {
        if ($denied = $this->requirePermission('users.manage')) {
            return $denied;
        }

        $user = db_connect()->table('users')->where('id', $userId)->get()->getRowArray();
        if (! $user) {
            return $this->respondNotFound('User');
        }

        $this->service->resetUserToRole($userId);

        return $this->respondData([
            'user_id'            => $userId,
            'has_custom_overrides' => false,
            'menu_keys'          => $this->service->getUserMenuKeys($userId, (int) $user['role_id']),
            'message'            => 'User permissions reset to role defaults.',
        ]);
    }
}

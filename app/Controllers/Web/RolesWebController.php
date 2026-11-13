<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Services\MenuPermissionService;
use App\Services\RoleService;
use App\Services\UserService;

class RolesWebController extends BaseController
{
    public function create(): \CodeIgniter\HTTP\ResponseInterface
    {
        if (!\App\Libraries\AuthContext::can('users.manage')) throw new \CodeIgniter\HTTP\Exceptions\HTTPException('You do not have access to create roles.',403);
        try {
            $data = $this->request->getJSON(true);
            if (!is_array($data)) throw new \InvalidArgumentException('Enter valid role details.');
            $role = (new \App\Services\Web\RolePresetService())->create($data);
            return $this->response->setJSON(['success' => true, 'data' => $role]);
        } catch (\InvalidArgumentException $error) {
            $message = match ($error->getMessage()) {
                'NAME_REQUIRED' => 'Enter a role name.',
                'ROLE_NAME_TAKEN' => 'A role with this name already exists.',
                default => $error->getMessage(),
            };
            return $this->response->setStatusCode(422)->setJSON(['success' => false, 'message' => $message]);
        }
    }

    public function index(): string
    {
        if (!\App\Libraries\AuthContext::can('users.manage')) throw new \CodeIgniter\HTTP\Exceptions\HTTPException('You do not have access to this page.',403);
        $roleService   = new RoleService();
        $userService   = new UserService();
        $menuService   = new MenuPermissionService();

        $roles         = $roleService->list();
        $permissions   = $roleService->allPermissions();
        $permissions = array_values(array_filter($permissions, static fn($permission) => $permission['code'] !== 'settings.operational'));
        $users         = $userService->list();
        $menuStructure = MenuPermissionService::MENU_STRUCTURE;

        $roleMenuMap = [];
        foreach ($roles as $r) {
            $roleId = (int) $r['id'];
            $roleMenuMap[$roleId] = $menuService->getRoleMenuKeys($roleId);
        }

        return view('web/roles/index', [
            'title'         => 'Roles & Permissions Matrix — Mudnaan Pharmacy',
            'roles'         => $roles,
            'users'         => $users,
            'permissions'   => $permissions,
            'menuStructure' => $menuStructure,
            'roleMenuMap'   => $roleMenuMap,
            'user'          => session()->get('user') ?? [],
            'tenant'        => session()->get('tenant') ?? [],
            'plan'          => session()->get('plan') ?? 'premium',
        ]);
    }
}

<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Services\RoleService;
use App\Services\UserService;
use CodeIgniter\HTTP\ResponseInterface;

class UsersWebController extends BaseController
{
    public function index(): string
    {
        if (!\App\Libraries\AuthContext::can('users.manage')) throw new \CodeIgniter\HTTP\Exceptions\HTTPException('You do not have access to this page.',403);
        $users = (new UserService())->list($this->request->getGet('q'));
        $roles = (new RoleService())->list();
        if (!\App\Libraries\AuthContext::isSuperadmin()) {
            $roles = array_values(array_filter($roles, static fn($role) => !\App\Libraries\AdministrativeAccess::isSuperadminRole($role['name'])));
        }

        return view('web/users/index', [
            'title'  => 'Staff User Management — Mudnaan Pharmacy',
            'users'  => $users,
            'roles'  => $roles,
            'user'   => session()->get('user') ?? [],
            'tenant' => session()->get('tenant') ?? [],
            'plan'   => session()->get('plan') ?? 'premium',
        ]);
    }

    public function audit(): string
    {
        return (new AuditWebController())->index();
    }
}

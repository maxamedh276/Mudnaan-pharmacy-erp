<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseApiController;
use App\Services\RoleService;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;

/**
 * Roles and permissions. All actions require users.manage.
 */
class RolesController extends BaseApiController
{
    private const ERRORS = [
        'NAME_REQUIRED'   => 'A role name is required.',
        'ROLE_NAME_TAKEN' => 'A role with that name already exists.',
        'ROLE_NOT_FOUND'  => 'That role does not exist.',
        'SYSTEM_ROLE'     => 'Built-in roles cannot be deleted.',
        'ROLE_HAS_USERS'  => 'Users are still assigned to this role.',
    ];

    public function index(): ResponseInterface
    {
        if ($denied = $this->requirePermission('users.manage')) {
            return $denied;
        }

        return $this->respondData((new RoleService())->list());
    }

    public function show(int $roleId): ResponseInterface
    {
        if ($denied = $this->requirePermission('users.manage')) {
            return $denied;
        }

        $role = (new RoleService())->find($roleId);

        return $role === null ? $this->respondNotFound('Role') : $this->respondData($role);
    }

    /** GET /permissions — the full permission catalog for role forms. */
    public function permissions(): ResponseInterface
    {
        if ($denied = $this->requirePermission('users.manage')) {
            return $denied;
        }

        return $this->respondData((new RoleService())->allPermissions());
    }

    public function create(): ResponseInterface
    {
        if ($denied = $this->requirePermission('users.manage')) {
            return $denied;
        }

        return $this->handle(fn () => (new RoleService())->create($this->body()), 201);
    }

    public function update(int $roleId): ResponseInterface
    {
        if ($denied = $this->requirePermission('users.manage')) {
            return $denied;
        }

        return $this->handle(fn () => (new RoleService())->update($roleId, $this->body()));
    }

    public function delete(int $roleId): ResponseInterface
    {
        if ($denied = $this->requirePermission('users.manage')) {
            return $denied;
        }

        return $this->handle(function () use ($roleId) {
            (new RoleService())->delete($roleId);

            return ['message' => 'Role deleted.'];
        });
    }

    private function handle(callable $operation, int $successStatus = 200): ResponseInterface
    {
        try {
            return $this->respondData($operation(), $successStatus);
        } catch (InvalidArgumentException $exception) {
            $code = $exception->getMessage();

            return $this->respondError($code, self::ERRORS[$code] ?? 'Invalid request.', 422);
        }
    }
}

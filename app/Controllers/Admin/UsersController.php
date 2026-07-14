<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseApiController;
use App\Libraries\AuthContext;
use App\Services\UserService;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;

/**
 * Staff user management. All actions require the users.manage
 * permission (Owner-level).
 */
class UsersController extends BaseApiController
{
    private const ERRORS = [
        'NAME_REQUIRED'          => 'A name is required.',
        'IDENTITY_REQUIRED'      => 'An email or phone is required.',
        'WEAK_PASSWORD'          => 'The password must be at least 6 characters.',
        'ROLE_NOT_FOUND'         => 'Choose a valid role.',
        'EMAIL_TAKEN'            => 'That email is already in use.',
        'PHONE_TAKEN'            => 'That phone is already in use.',
        'USER_NOT_FOUND'         => 'That user does not exist.',
        'CANNOT_DEACTIVATE_SELF' => 'You cannot deactivate your own account.',
    ];

    public function index(): ResponseInterface
    {
        if ($denied = $this->requirePermission('users.manage')) {
            return $denied;
        }

        return $this->respondData(
            (new UserService())->list($this->request->getGet('q')),
        );
    }

    public function show(int $userId): ResponseInterface
    {
        if ($denied = $this->requirePermission('users.manage')) {
            return $denied;
        }

        $user = (new UserService())->find($userId);

        return $user === null ? $this->respondNotFound('User') : $this->respondData($user);
    }

    public function create(): ResponseInterface
    {
        if ($denied = $this->requirePermission('users.manage')) {
            return $denied;
        }

        return $this->handle(fn () => (new UserService())->create($this->body()), 201);
    }

    public function update(int $userId): ResponseInterface
    {
        if ($denied = $this->requirePermission('users.manage')) {
            return $denied;
        }

        return $this->handle(fn () => (new UserService())->update($userId, $this->body()));
    }

    public function setActive(int $userId): ResponseInterface
    {
        if ($denied = $this->requirePermission('users.manage')) {
            return $denied;
        }

        $active = (bool) ($this->body()['is_active'] ?? false);

        return $this->handle(fn () => (new UserService())->setActive(
            $userId, $active, (int) AuthContext::userId(),
        ));
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

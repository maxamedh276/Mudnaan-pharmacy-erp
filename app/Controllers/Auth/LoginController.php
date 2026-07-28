<?php

namespace App\Controllers\Auth;

use App\Controllers\BaseApiController;
use App\Libraries\AuthContext;
use App\Libraries\JwtService;
use App\Libraries\TenantManager;
use App\Models\UserModel;
use App\Services\AuditService;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Tenants;

class LoginController extends BaseApiController
{
    /**
     * POST /api/v1/auth/login
     * Body: { "identity": "email or phone", "password": "...", "tenant": "demo"? }
     *
     * `tenant` is optional while we run on the config-map tenant registry;
     * it falls back to Config\Tenants::$default.
     */
    public function login(): ResponseInterface
    {
        $body     = $this->body();
        $identity = trim((string) ($body['identity'] ?? $body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($identity === '' || $password === '') {
            return $this->respondValidationErrors([
                'identity' => 'Email or phone is required.',
                'password' => 'Password is required.',
            ]);
        }

        $tenantManager = new TenantManager();
        $tenantCode    = (string) ($body['tenant'] ?? config(Tenants::class)->default);
        $tenant        = $tenantManager->find($tenantCode);

        if ($tenant === null) {
            return $this->respondError('TENANT_NOT_FOUND', 'Unknown business account.', 401);
        }

        $tenantManager->connect($tenant);

        $userModel = new UserModel();
        $user      = $userModel->findActiveByIdentity($identity);

        if ($user === null || ! password_verify($password, $user->password_hash)) {
            // Traceable failed attempt (no actor — bad credentials).
            AuditService::log('auth.login_failed', 'auth', 'users', null, details: ['identity' => $identity]);

            return $this->respondError('INVALID_CREDENTIALS', 'Wrong email/phone or password.', 401);
        }

        $userModel->update($user->id, ['last_login_at' => date('Y-m-d H:i:s')]);

        // Attribute the login to this user, then record it on the trail.
        AuthContext::$user = $user;
        AuditService::log('auth.login', 'auth', 'users', (int) $user->id);

        $token = (new JwtService())->issue((int) $user->id, $tenant['code'], $tenant['plan'], $user->role_name);

        return $this->respondData([
            'token' => $token,
            'user'  => $this->userPayload($user),
            'plan'  => $tenant['plan'],
            'permissions' => $userModel->permissionCodes((int) $user->role_id),
        ]);
    }

    /**
     * GET /api/v1/auth/me — the current session (runs behind AuthFilter).
     */
    public function me(): ResponseInterface
    {
        return $this->respondData([
            'user'        => $this->userPayload(AuthContext::$user),
            'plan'        => AuthContext::plan(),
            'permissions' => AuthContext::$permissions,
        ]);
    }

    /**
     * POST /api/v1/auth/refresh — a fresh token for a still-valid session.
     */
    public function refresh(): ResponseInterface
    {
        $user   = AuthContext::$user;
        $tenant = AuthContext::$tenant;
        $token  = (new JwtService())->issue((int) $user->id, $tenant['code'], $tenant['plan'], $user->role_name);

        return $this->respondData(['token' => $token]);
    }

    /**
     * POST /api/v1/auth/logout — stateless JWT: the client discards the
     * token; the endpoint exists so the app has a single logout call
     * (and a place to blacklist tokens later if we ever need to).
     */
    public function logout(): ResponseInterface
    {
        AuditService::log('auth.logout', 'auth', 'users', AuthContext::userId());

        return $this->respondData(['message' => 'Logged out.']);
    }

    private function userPayload(object $user): array
    {
        return [
            'id'        => (int) $user->id,
            'name'      => $user->name,
            'email'     => $user->email,
            'phone'     => $user->phone,
            'role'      => $user->role_name,
            'branch_id' => $user->branch_id === null ? null : (int) $user->branch_id,
        ];
    }
}

<?php

namespace App\Filters;

use App\Libraries\AuthContext;
use App\Libraries\JwtService;
use App\Libraries\TenantManager;
use App\Models\UserModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Gatekeeper for every protected route: "who are you, and which database?"
 *
 * 1. Reads and verifies the Bearer JWT (or session/cookie for web requests).
 * 2. Resolves the tenant from the token and connects to that tenant's DB.
 * 3. Loads the user and their permissions into AuthContext.
 */
class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $authorizationHeader = $request->getHeaderLine('Authorization');
        $token = null;

        if (preg_match('/Bearer\s+(\S+)/', $authorizationHeader, $matches)) {
            $token = $matches[1];
        } else {
            // Web browser fallback: session or cookie
            $token = session()->get('token') ?? $request->getCookie('erp_token');
        }

        if (empty($token)) {
            return $this->deny('UNAUTHORIZED', 'Missing authentication token.');
        }

        try {
            $claims = (new JwtService())->verify($token);
        } catch (Throwable) {
            return $this->deny('TOKEN_INVALID', 'Your session has expired. Please log in again.');
        }

        $tenantManager = new TenantManager();
        $tenant        = $tenantManager->find($claims->tenant ?? '');

        if ($tenant === null) {
            return $this->deny('TENANT_NOT_FOUND', 'Unknown business account.');
        }

        $tenantManager->connect($tenant);

        $userModel = new UserModel();
        $user      = $userModel->findActiveById((int) $claims->sub);

        if ($user === null) {
            return $this->deny('USER_DISABLED', 'This user account is disabled.');
        }

        AuthContext::$user        = $user;
        AuthContext::$tenant      = $tenant;
        AuthContext::$permissions = $userModel->permissionCodes((int) $user->role_id);

        // The legacy settings endpoint backs Operational Settings, not the appearance/printer forms.
        // Keep its controller and mobile route contract intact while enforcing the same web boundary.
        if (preg_match('#(?:^|/)api/v1/settings/?$#i', rawurldecode($request->getUri()->getPath()))
            && !AuthContext::can('settings.operational')) {
            return service('response')->setStatusCode(403)->setJSON([
                'success' => false,
                'error' => ['code' => 'FORBIDDEN', 'message' => 'Operational Settings is available to Superadmins only.'],
            ]);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Nothing to do after the response.
    }

    private function deny(string $errorCode, string $message): ResponseInterface
    {
        return service('response')
            ->setStatusCode(401)
            ->setJSON([
                'success' => false,
                'error'   => ['code' => $errorCode, 'message' => $message],
            ]);
    }
}

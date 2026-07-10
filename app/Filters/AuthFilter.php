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
 * 1. Reads and verifies the Bearer JWT.
 * 2. Resolves the tenant from the token and connects to that tenant's DB.
 * 3. Loads the user and their permissions into AuthContext.
 */
class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $authorizationHeader = $request->getHeaderLine('Authorization');

        if (! preg_match('/Bearer\s+(\S+)/', $authorizationHeader, $matches)) {
            return $this->deny('UNAUTHORIZED', 'Missing authentication token.');
        }

        try {
            $claims = (new JwtService())->verify($matches[1]);
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

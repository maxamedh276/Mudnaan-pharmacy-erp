<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\AuthContext;
use App\Libraries\JwtService;
use App\Libraries\TenantManager;
use App\Models\UserModel;
use App\Services\AuditService;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Tenants;
use Throwable;

class AuthController extends BaseController
{
    /**
     * Show login page. If user already has a valid session, redirect to dashboard.
     */
    public function login(): string|ResponseInterface
    {
        $this->response->setHeader('X-Frame-Options','SAMEORIGIN')->setHeader('X-Content-Type-Options','nosniff');
        $session = session();
        $token   = $session->get('token') ?? $this->request->getCookie('erp_token');

        if ($token) {
            try {
                (new JwtService())->verify($token);
                return redirect()->to(site_url('dashboard'));
            } catch (Throwable) {
                $session->remove(['token', 'user', 'permissions', 'plan', 'tenant']);
            }
        }

        $businessName = \App\Services\ReceiptBrandingService::SYSTEM_NAME;
        try {
            $tenantManager = new TenantManager();
            $defaultTenant = $tenantManager->find(config(Tenants::class)->default);
            if ($defaultTenant !== null) {
                $tenantManager->connect($defaultTenant);
                $businessName = (new \App\Services\ReceiptBrandingService())->get(1)['store_name'];
            }
        } catch (Throwable $error) {
            // Keep sign-in available even when business branding cannot be loaded.
            log_message('error', 'Login branding unavailable: '.$error->getMessage());
        }

        return view('auth/login', [
            'title'    => 'Sign In — '.$businessName,
            'businessName' => $businessName,
            'error'    => $session->getFlashdata('error'),
            'success'  => $session->getFlashdata('success'),
            'identity' => old('identity', ''),
        ]);
    }

    /**
     * Process standard HTML Form login.
     */
    public function authenticate(): ResponseInterface
    {
        $identityInput=$this->request->getPost('identity');$passwordInput=$this->request->getPost('password');$tenantInput=$this->request->getPost('tenant');
        $identity=is_string($identityInput)?trim($identityInput):'';
        $password=is_string($passwordInput)?$passwordInput:'';
        $tenantCode=is_string($tenantInput)&&trim($tenantInput)!==''?trim($tenantInput):config(Tenants::class)->default;

        if ($identity === '' || $password === '' || mb_strlen($identity)>180 || strlen($password)>4096 || strlen($tenantCode)>100) {
            return redirect()->back()
                ->with('_ci_old_input', ['get'=>[], 'post'=>['identity'=>$identity]])
                ->with('error', 'Please enter your email/phone and password.');
        }

        $ip=$this->request->getIPAddress();
        if (!service('throttler')->check('web-login-ip-'.hash('sha256',$ip),60,60)
            || !service('throttler')->check('web-login-user-'.hash('sha256',strtolower($identity).'|'.$tenantCode.'|'.$ip),10,60)) {
            return $this->response->setStatusCode(429)->setHeader('Retry-After','60')->setBody(view('auth/login',[
                'title'=>'Sign in','businessName'=>\App\Services\ReceiptBrandingService::SYSTEM_NAME,
                'error'=>'Too many sign-in attempts. Wait one minute and try again.','success'=>null,'identity'=>$identity,
            ]));
        }

        $tenantManager = new TenantManager();
        $tenant = $tenantManager->find($tenantCode);

        if ($tenant === null) {
            return redirect()->back()
                ->with('_ci_old_input', ['get'=>[], 'post'=>['identity'=>$identity]])
                ->with('error', 'Unknown business account code.');
        }

        $tenantManager->connect($tenant);

        $userModel = new UserModel();
        $user = $userModel->findActiveByIdentity($identity);

        if ($user === null || ! password_verify($password, $user->password_hash)) {
            AuditService::log('auth.login_failed', 'auth', 'users', null, details: ['identity' => $identity]);

            return redirect()->back()
                ->with('_ci_old_input', ['get'=>[], 'post'=>['identity'=>$identity]])
                ->with('error', 'Wrong email/phone or password. Please try again.');
        }

        $userModel->update($user->id, ['last_login_at' => date('Y-m-d H:i:s')]);

        AuthContext::$user = $user;
        AuditService::log('auth.login', 'auth', 'users', (int) $user->id);

        $roleName = $user->role_name ?? ($user->role_id == 1 ? 'Owner' : ($user->role_id == 2 ? 'Manager' : 'Cashier'));
        $token = (new JwtService())->issue((int) $user->id, $tenant['code'], $tenant['plan'], $roleName);
        $permissions = $userModel->permissionCodes((int) $user->role_id);

        $userPayload = [
            'id'        => (int) $user->id,
            'name'      => $user->name,
            'email'     => $user->email,
            'phone'     => $user->phone,
            'role_id'   => (int) $user->role_id,
            'role_name' => $roleName,
            'branch_id' => (int) $user->branch_id,
        ];

        // Store in CodeIgniter Session
        session()->regenerate(true);
        session()->set([
            'token'       => $token,
            'user'        => $userPayload,
            'tenant'      => $tenant,
            'plan'        => $tenant['plan'],
            'permissions' => $permissions,
            'web_csrf'=>bin2hex(random_bytes(32)),
            'pharmacy_csrf'=>bin2hex(random_bytes(32)),
        ]);

        $redirectUrl = session()->get('redirect_url') ?: site_url('dashboard');
        session()->remove('redirect_url');

        return redirect()->to($redirectUrl)
            ->setCookie('erp_token', $token, 86400 * 7, '', '/', '', $this->request->isSecure(), true, 'Lax')
            ->with('success', 'Welcome back, ' . $user->name . '!');
    }

    /**
     * AJAX endpoint for front-end to sync session when logging in via REST API fetch.
     */
    public function sessionSync(): ResponseInterface
    {
        $body = $this->request->getJSON(true) ?? [];
        $token = $body['token'] ?? '';

        if (! $token) {
            return $this->response->setStatusCode(400)->setJSON(['success' => false, 'message' => 'Token required']);
        }

        try {
            $claims = (new JwtService())->verify($token);
            $tenantManager = new TenantManager();
            $tenant = $tenantManager->find($claims->tenant ?? '');

            if ($tenant) {
                $tenantManager->connect($tenant);
                $userModel = new UserModel();
                $user = $userModel->findActiveById((int) $claims->sub);

                if ($user) {
                    $roleName = $user->role_name ?? ($user->role_id == 1 ? 'Owner' : ($user->role_id == 2 ? 'Manager' : 'Cashier'));
                    session()->regenerate(true);
                    session()->set([
                        'token'       => $token,
                        'user'        => [
                            'id'        => (int) $user->id,
                            'name'      => $user->name,
                            'email'     => $user->email,
                            'phone'     => $user->phone,
                            'role_id'   => (int) $user->role_id,
                            'role_name' => $roleName,
                            'branch_id' => (int) $user->branch_id,
                        ],
                        'tenant'      => $tenant,
                        'plan'        => $tenant['plan'],
                        'permissions' => $userModel->permissionCodes((int) $user->role_id),
                        'web_csrf'=>bin2hex(random_bytes(32)),
                        'pharmacy_csrf'=>bin2hex(random_bytes(32)),
                    ]);

                    $this->response->setCookie('erp_token', $token, 86400 * 7, '', '/', '', $this->request->isSecure(), true, 'Lax');

                    return $this->response->setJSON(['success' => true]);
                }
            }
        } catch (Throwable $e) {
            return $this->response->setStatusCode(401)->setJSON(['success' => false, 'message' => $e->getMessage()]);
        }

        return $this->response->setStatusCode(400)->setJSON(['success' => false, 'message' => 'Failed to synchronize session']);
    }

    /**
     * Sign out user and clear state.
     */
    public function logout(): ResponseInterface
    {
        $session = session();
        $session->remove(['token', 'user', 'permissions', 'plan', 'tenant', 'redirect_url', '_ci_old_input']);
        // Destroy the authenticated session ID while retaining an anonymous session for the success message.
        $session->regenerate(true);
        AuthContext::reset();

        // Cookies must be attached to the response actually returned to the browser.
        return redirect()->to(site_url('login'))
            ->deleteCookie('erp_token', '', '/')
            ->setHeader('Cache-Control', 'no-store, private')
            ->with('success', 'You have been signed out.');
    }
}

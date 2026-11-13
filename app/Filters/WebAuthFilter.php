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
 * Web Authentication Filter for browser-facing sessions.
 * Inspects CI session or cookie for JWT token, validates it,
 * establishes tenant connection and populates AuthContext.
 */
class WebAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $session = session();
        $locale = $request->getGet('language');
        if (in_array($locale, ['en', 'so'], true)) $session->set('locale', $locale);
        $request->setLocale(in_array($session->get('locale'), ['en', 'so'], true) ? $session->get('locale') : 'en');
        $token   = $session->get('token') ?? $request->getCookie('erp_token');

        if (! $token) {
            // Store target URL for redirect after login
            $session->set('redirect_url', (string) current_url());

            return redirect()->to(site_url('login'));
        }

        try {
            $claims = (new JwtService())->verify($token);
        } catch (Throwable) {
            $session->remove(['token', 'user', 'permissions', 'plan', 'tenant']);

            return redirect()->to(site_url('login'))->with('error', 'Session expired. Please sign in again.');
        }

        $tenantManager = new TenantManager();
        $tenant        = $tenantManager->find($claims->tenant ?? '');

        if ($tenant === null) {
            $session->destroy();

            return redirect()->to(site_url('login'))->with('error', 'Unknown organization account.');
        }

        $tenantManager->connect($tenant);

        $userModel = new UserModel();
        $user      = $userModel->findActiveById((int) $claims->sub);

        if ($user === null) {
            $session->destroy();

            return redirect()->to(site_url('login'))->with('error', 'Account is disabled or inactive.');
        }

        AuthContext::$user        = $user;
        AuthContext::$tenant      = $tenant;
        AuthContext::$permissions = $userModel->permissionCodes((int) $user->role_id);

        // All cookie-authenticated web mutations need a session-bound request token.
        // Mobile/API authentication and route definitions are deliberately unchanged.
        $webCsrf=$session->get('web_csrf');
        if (!is_string($webCsrf) || strlen($webCsrf)!==64) {$webCsrf=bin2hex(random_bytes(32));$session->set('web_csrf',$webCsrf);}
        if (!in_array(strtoupper($request->getMethod()),['GET','HEAD','OPTIONS'],true)) {
            $supplied=$request->getHeaderLine('X-Web-CSRF');
            if ($supplied==='') $supplied=$request->getPost('_web_csrf');
            if (!is_string($supplied)||!hash_equals($webCsrf,$supplied)) return service('response')->setStatusCode(403)->setHeader('Cache-Control','private, no-store')->setJSON(['success'=>false,'message'=>'Your request token is missing or expired. Reload the page and try again.']);
        }

        // Keep session payload fresh
        $session->set([
            'user'        => [
                'id'         => (int) $user->id,
                'name'       => $user->name,
                'email'      => $user->email,
                'phone'      => $user->phone,
                'role_id'    => (int) $user->role_id,
                'role_name'  => $user->role_name ?? ($user->role_id == 1 ? 'Owner' : ($user->role_id == 2 ? 'Manager' : 'Cashier')),
                'branch_id'  => (int) $user->branch_id,
            ],
            'tenant'      => $tenant,
            'plan'        => $tenant['plan'],
            'permissions' => AuthContext::$permissions,
        ]);

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $response->setHeader('Cache-Control', 'no-store, private');
        $response->setHeader('X-Content-Type-Options','nosniff');
        $response->setHeader('X-Frame-Options','SAMEORIGIN');
        $response->setHeader('Referrer-Policy','same-origin');
    }
}

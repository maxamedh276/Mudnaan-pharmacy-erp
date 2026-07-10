<?php

namespace App\Filters;

use App\Libraries\AuthContext;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Blocks routes that require a higher plan than the tenant has.
 * Usage in routes: ['filter' => 'plan:premium']  — runs after AuthFilter.
 */
class PlanFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $requiredPlan = $arguments[0] ?? 'premium';

        if (AuthContext::plan() !== $requiredPlan) {
            return service('response')
                ->setStatusCode(403)
                ->setJSON([
                    'success' => false,
                    'error'   => [
                        'code'    => 'PLAN_UPGRADE_REQUIRED',
                        'message' => 'Upgrade to Premium to use this feature.',
                    ],
                ]);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Nothing to do after the response.
    }
}

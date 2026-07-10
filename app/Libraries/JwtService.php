<?php

namespace App\Libraries;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;

/**
 * Issues and verifies the JWT access tokens used by all clients.
 *
 * Claims carried in every token:
 *   sub    - user id (within the tenant database)
 *   tenant - tenant code (decides which database we connect to)
 *   plan   - basic|premium (informational; the tenant registry is authoritative)
 *   role   - role name, for logging/UI only (permissions are re-read per request)
 */
class JwtService
{
    private const ALGORITHM = 'HS256';
    private const ISSUER    = 'mudnaan-erp';

    private string $secret;
    private int $timeToLiveSeconds;

    public function __construct()
    {
        $secret = env('jwt.secret');

        if (empty($secret)) {
            throw new RuntimeException('jwt.secret is not configured in .env');
        }

        $this->secret            = $secret;
        $this->timeToLiveSeconds = (int) (env('jwt.ttl') ?: 86400);
    }

    public function issue(int $userId, string $tenantCode, string $plan, string $role): string
    {
        $issuedAt = time();

        return JWT::encode([
            'iss'    => self::ISSUER,
            'sub'    => (string) $userId,
            'tenant' => $tenantCode,
            'plan'   => $plan,
            'role'   => $role,
            'iat'    => $issuedAt,
            'exp'    => $issuedAt + $this->timeToLiveSeconds,
        ], $this->secret, self::ALGORITHM);
    }

    /**
     * @throws \Exception when the token is invalid or expired
     */
    public function verify(string $token): object
    {
        return JWT::decode($token, new Key($this->secret, self::ALGORITHM));
    }
}

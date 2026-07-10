<?php

namespace App\Libraries;

/**
 * Per-request holder for the authenticated user, tenant and permissions.
 *
 * Populated by AuthFilter after the JWT is verified; read by controllers,
 * services and PlanFilter. Static because the API is stateless — one
 * request, one context.
 */
class AuthContext
{
    public static ?object $user = null;

    /** @var array{code: string, name: string, database: string, plan: string}|null */
    public static ?array $tenant = null;

    /** @var list<string> permission codes of the user's role */
    public static array $permissions = [];

    public static function userId(): ?int
    {
        return self::$user->id ?? null;
    }

    public static function plan(): ?string
    {
        return self::$tenant['plan'] ?? null;
    }

    public static function isPremium(): bool
    {
        return self::plan() === 'premium';
    }

    public static function can(string $permissionCode): bool
    {
        return in_array($permissionCode, self::$permissions, true);
    }

    public static function reset(): void
    {
        self::$user        = null;
        self::$tenant      = null;
        self::$permissions = [];
    }
}

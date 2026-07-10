<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Tenant registry — how a tenant code resolves to its own database.
 *
 * Until the master database exists, this simple map IS the tenant
 * lookup (see backend_structure.md §7). When the master DB is built
 * later, only TenantManager::find() changes — nothing else moves.
 */
class Tenants extends BaseConfig
{
    /**
     * Tenant used when a login request does not specify one.
     */
    public string $default = 'demo';

    /**
     * code => [display name, tenant database, plan (basic|premium)]
     *
     * @var array<string, array{name: string, database: string, plan: string}>
     */
    public array $tenants = [
        'demo' => [
            'name'     => 'Mudnaan Demo Store',
            'database' => 'erp_tenant_demo',
            'plan'     => 'premium',
        ],
    ];
}

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
    public function __construct()
    {
        parent::__construct();
        $this->tenants['pharmacy']['database'] = (string) env('PHARMACY_DATABASE', 'pharmacy_main');
    }
    /**
     * Tenant used when a login request does not specify one.
     */
    public string $default = 'pharmacy';

    /**
     * code => [display name, tenant database, plan (basic|premium)]
     *
     * @var array<string, array{name: string, database: string, plan: string}>
     */
    public array $tenants = [
        'pharmacy' => [
            'name'     => 'Mudnaan Pharmacy',
            'database' => 'pharmacy_main',
            'plan'     => 'premium',
        ],
    ];
}

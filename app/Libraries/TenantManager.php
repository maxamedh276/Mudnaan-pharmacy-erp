<?php

namespace App\Libraries;

use Config\Database;
use Config\Tenants;

/**
 * Resolves a tenant code to its configuration and switches the
 * default database connection to that tenant's database.
 *
 * Every authenticated request passes through here (via AuthFilter),
 * which is what guarantees tenant isolation: the JWT decides which
 * database we connect to, and only that one.
 */
class TenantManager
{
    /**
     * @return array{code: string, name: string, database: string, plan: string}|null
     */
    public function find(string $code): ?array
    {
        $tenants = config(Tenants::class)->tenants;

        if (! isset($tenants[$code])) {
            return null;
        }

        return ['code' => $code] + $tenants[$code];
    }

    /**
     * Points the default connection group at the tenant's database.
     * Runs in a filter, before any model has opened a connection.
     */
    public function connect(array $tenant): void
    {
        $dbConfig                        = config(Database::class);
        $dbConfig->default['database']   = $tenant['database'];

        $connection = \Config\Database::connect();

        if ($connection->getDatabase() !== $tenant['database']) {
            $connection->setDatabase($tenant['database']);
        }
    }
}

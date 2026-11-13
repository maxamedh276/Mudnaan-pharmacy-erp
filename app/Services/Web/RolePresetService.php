<?php

namespace App\Services\Web;

use App\Services\RoleService;
use InvalidArgumentException;

/** Explicit web-form presets. A role name alone never grants additional privileges. */
class RolePresetService
{
    public const ACCOUNTANT_READ = [
        'reports.basic', 'reports.advanced', 'accounting.view',
        'invoices.view', 'bills.view', 'banking.view',
    ];

    public function create(array $data): array
    {
        $preset = $data['preset'] ?? 'custom';
        if (!is_string($preset) || !in_array($preset, ['custom', 'accountant_read'], true)) {
            throw new InvalidArgumentException('Select a valid role preset.');
        }
        $permissions = $preset === 'accountant_read' ? self::ACCOUNTANT_READ : ($data['permissions'] ?? []);
        return (new RoleService())->create(['name' => $data['name'] ?? '', 'permissions' => $permissions]);
    }
}

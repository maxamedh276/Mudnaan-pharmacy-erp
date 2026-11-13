<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseApiController;
use App\Models\SettingModel;
use App\Services\AuditService;
use CodeIgniter\HTTP\ResponseInterface;

class SettingsController extends BaseApiController
{
    /**
     * GET /api/v1/settings
     */
    public function index(): ResponseInterface
    {
        // This endpoint serves Operational Settings. Ordinary appearance/printer settings
        // have their own web endpoints; settings.manage must not grant this access.
        if ($denied = $this->requirePermission('settings.operational')) {
            return $denied;
        }

        $settings = (new SettingModel())->allAssoc();

        // Also fetch branch info
        $branch = db_connect()->table('branches')->where('is_main', 1)->get()->getRowArray() ?? [];

        return $this->respondData([
            'settings' => $settings,
            'branch'   => $branch,
        ]);
    }

    /**
     * POST /api/v1/settings
     */
    public function update(): ResponseInterface
    {
        if ($denied = $this->requirePermission('settings.operational')) {
            return $denied;
        }

        $body = $this->body();
        $model = new SettingModel();
        $before = $model->allAssoc();

        $allowedKeys = [
            'business_type', 'currency', 'tax_rate', 'receipt_header',
            'receipt_footer', 'uses_batches', 'uses_serials', 'company_name',
            'phone', 'address', 'email', 'tax_number',
            'thermal_printer_enabled', 'thermal_printer_type', 'thermal_printer_name',
            'thermal_printer_ip', 'thermal_printer_port', 'thermal_paper_width',
            'thermal_auto_drawer', 'thermal_auto_cut'
        ];

        $updated = [];
        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $body)) {
                $model->setVal($key, (string) $body[$key]);
                $updated[$key] = $body[$key];
            }
        }

        // If branch name/phone/address sent, update main branch
        if (! empty($body['company_name']) || ! empty($body['phone']) || ! empty($body['address'])) {
            $branchData = [];
            if (! empty($body['company_name'])) $branchData['name'] = $body['company_name'];
            if (! empty($body['phone'])) $branchData['phone'] = $body['phone'];
            if (! empty($body['address'])) $branchData['address'] = $body['address'];

            db_connect()->table('branches')->where('is_main', 1)->update($branchData);
        }

        AuditService::log('settings.update', 'settings', 'settings', null, before: $before, after: $updated);

        return $this->respondData([
            'message'  => 'Settings saved successfully.',
            'settings' => $model->allAssoc(),
        ]);
    }
}

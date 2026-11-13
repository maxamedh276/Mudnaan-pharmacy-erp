<?php

namespace App\Services;

use App\Libraries\AuthContext;
use App\Models\SettingModel;

/** One business identity for on-screen receipts and ESC/POS output. */
class ReceiptBrandingService
{
    public const SYSTEM_NAME = 'Mudnaan Pharmacy';

    public function get(?int $branchId = null): array
    {
        $settings = (new SettingModel())->allAssoc();
        $branchId ??= (int) (AuthContext::$user->branch_id ?? 1);
        $branch = db_connect()->table('branches')->where('id', $branchId)->get()->getRowArray() ?? [];
        $businessName = self::SYSTEM_NAME;
        foreach ([$settings['business_name'] ?? '', $settings['company_name'] ?? '', $branch['name'] ?? ''] as $candidate) {
            if (trim((string)$candidate) !== '') {
                $businessName = trim((string)$candidate);
                break;
            }
        }
        return [
            'store_name' => $businessName,
            'address' => $settings['business_address'] ?? $branch['address'] ?? '',
            'phone' => $settings['business_phone'] ?? $branch['phone'] ?? '',
            'header' => $settings['receipt_header'] ?? '',
            'footer' => $settings['receipt_footer'] ?? 'Thank you for your visit!',
            // 'system_name' => self::SYSTEM_NAME,
            'system_name' => 'www.mudnaantechnology.com',
        ];
    }
}

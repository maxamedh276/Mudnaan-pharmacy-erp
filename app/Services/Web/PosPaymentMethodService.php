<?php

namespace App\Services\Web;

use App\Models\PosPaymentMethodModel;
use InvalidArgumentException;
use RuntimeException;

/** Web payment-method rules; mobile API contracts remain unchanged. */
class PosPaymentMethodService
{
    public function save(array $input): array
    {
        $database = db_connect();
        $paymentMethodModel = new PosPaymentMethodModel();
        $methodId = !empty($input['id']) ? (int) $input['id'] : null;
        $displayLabel = trim((string) ($input['name'] ?? ''));
        $accountId = (int) ($input['account_id'] ?? 0);
        $paymentType = (string) ($input['type'] ?? 'mobile_money');

        // The code follows the display label; no second identifier is entered by the user.
        if ($displayLabel === '' || mb_strlen($displayLabel) > 50) {
            throw new InvalidArgumentException('Enter a display label of up to 50 characters.');
        }
        if (!in_array($paymentType, ['cash', 'mobile_money', 'bank'], true)) {
            throw new InvalidArgumentException('Choose a valid payment type.');
        }
        $account = $database->query(
            "SELECT a.id FROM accounts a JOIN account_groups g ON g.id = a.account_group_id
             JOIN account_types t ON t.id = g.account_type_id
             WHERE a.id = ? AND a.is_active = 1 AND a.is_postable = 1
               AND a.deleted_at IS NULL AND t.code = 'ASSET'",
            [$accountId],
        )->getRowArray();
        if (!$account) {
            throw new InvalidArgumentException('Select an active, postable asset account.');
        }

        $database->transException(true)->transBegin();
        try {
            // Lock the ordered set so concurrent creates/reorders cannot assign conflicting positions.
            $existingMethods = $database->query('SELECT * FROM pos_payment_methods ORDER BY id FOR UPDATE')->getResultArray();
            $methodsById = array_column($existingMethods, null, 'id');
            $existingMethod = $methodId ? ($methodsById[$methodId] ?? null) : null;
            if ($methodId && !$existingMethod) {
                throw new InvalidArgumentException('Payment method not found.');
            }
            $duplicateQuery = $database->table('pos_payment_methods')->groupStart()
                ->where('code', $displayLabel)->orWhere('name', $displayLabel)->groupEnd();
            if ($methodId) {
                $duplicateQuery->where('id !=', $methodId);
            }
            if ($duplicateQuery->countAllResults() > 0) {
                throw new InvalidArgumentException('A payment method with this display label already exists.');
            }
            $maximumOrder = $existingMethods === [] ? 0 : max(array_column($existingMethods, 'sort_order'));
            $fields = [
                'account_id' => $accountId,
                'name' => $displayLabel,
                'code' => $displayLabel,
                'type' => $paymentType,
                'is_active' => $existingMethod ? (int) $existingMethod['is_active'] : 1,
                'sort_order' => $existingMethod ? (int) $existingMethod['sort_order'] : $maximumOrder + 1,
            ];
            if ($methodId) {
                $paymentMethodModel->update($methodId, $fields);
            } else {
                $methodId = (int) $paymentMethodModel->insert($fields);
            }
            if (!$database->transStatus()) {
                throw new RuntimeException('Could not save the payment method.');
            }
            $database->transCommit();
            return $paymentMethodModel->find($methodId);
        } catch (\Throwable $exception) {
            $database->transRollback();
            throw $exception;
        }
    }

    /** Accept the complete ordered ID list to prevent partial or stale reorder writes. */
    public function reorder(array $orderedIds): void
    {
        if (!array_is_list($orderedIds) || $orderedIds === []) {
            throw new InvalidArgumentException('Provide the complete payment-method order.');
        }
        foreach ($orderedIds as $methodId) {
            if (!is_int($methodId) || $methodId <= 0) {
                throw new InvalidArgumentException('The payment-method order contains an invalid ID.');
            }
        }
        if (count(array_unique($orderedIds)) !== count($orderedIds)) {
            throw new InvalidArgumentException('Each payment method must appear once.');
        }

        $database = db_connect();
        $database->transException(true)->transBegin();
        try {
            $currentIds = array_map('intval', array_column($database->query('SELECT id FROM pos_payment_methods ORDER BY id FOR UPDATE')->getResultArray(), 'id'));
            $expectedIds = $orderedIds;
            sort($expectedIds);
            if ($currentIds !== $expectedIds) {
                throw new InvalidArgumentException('Payment methods changed. Refresh the list before reordering.');
            }
            foreach ($orderedIds as $position => $methodId) {
                $database->table('pos_payment_methods')->where('id', $methodId)->update(['sort_order' => $position + 1]);
            }
            if (!$database->transStatus()) {
                throw new RuntimeException('Could not save the payment-method order.');
            }
            $database->transCommit();
        } catch (\Throwable $exception) {
            $database->transRollback();
            throw $exception;
        }
    }
}

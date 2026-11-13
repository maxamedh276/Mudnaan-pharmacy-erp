<?php

namespace App\Services\Web;

use App\Libraries\AuthContext;
use App\Models\CustomerModel;
use App\Services\AuditService;
use App\Services\CustomerCreditService;
use App\Services\FinancialTransaction;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use InvalidArgumentException;

/** Web profile edits never accept balances or other financial fields from a form. */
final class CustomerDetailsService
{
    public function save(array $data, ?int $id): array
    {
        $fields = $this->validate($data);
        return $this->persist($fields, $id);
    }

    /** Shared by the form and workbook preflight. Does not write to the database. */
    public function validate(array $data): array
    {
        if (!AuthContext::can('customers.manage')) throw new HTTPException('You do not have access to manage customers.', 403);
        if (array_key_exists('credit_limit', $data) && !CustomerCreditService::canManage()) {
            throw new HTTPException('Only an Admin or Superadmin can change customer credit limits.', 403);
        }
        $fields = [];
        foreach (['name' => 150, 'phone' => 40, 'email' => 150, 'address' => 255] as $field => $maximum) {
            $value = $data[$field] ?? '';
            if (!is_string($value) && $value !== null) throw new InvalidArgumentException('Enter valid customer details.');
            $value = trim($value ?? '');
            if (mb_strlen($value) > $maximum) throw new InvalidArgumentException(ucfirst($field) . ' is too long.');
            $fields[$field] = $value === '' && $field !== 'name' ? null : $value;
        }
        if ($fields['name'] === '') throw new InvalidArgumentException('Customer name is required.');
        if ($fields['email'] !== null && !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address.');
        if (array_key_exists('credit_limit', $data)) $fields['credit_limit'] = CustomerCreditService::limitAmount($data['credit_limit']);
        return $fields;
    }

    private function persist(array $fields, ?int $id): array
    {
        return FinancialTransaction::run(function () use ($fields, $id) {
            $model = new CustomerModel();
            $before = $id === null ? null : (new CustomerCreditService())->lockCustomer($id);
            if ($id === null) $id = (int)$model->insert($fields);
            else $model->update($id, $fields);
            $after = $model->find($id);
            AuditService::log($before === null ? 'customer.create' : 'customer.update', 'customers', 'customers', $id, $before, $after);
            if (array_key_exists('credit_limit', $fields) && (float)($before['credit_limit'] ?? 0) !== (float)$after['credit_limit']) {
                AuditService::log('customer.credit_limit.change', 'customers', 'customers', $id,
                    ['credit_limit' => (float)($before['credit_limit'] ?? 0)], ['credit_limit' => (float)$after['credit_limit']]);
            }
            return array_merge(CustomerModel::cast($after), (new CustomerCreditService())->statusFor($after));
        });
    }
}

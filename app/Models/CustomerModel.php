<?php

namespace App\Models;


class CustomerModel extends FinanciallyProtectedModel
{
    protected $table          = 'customers';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = true;
    protected $useTimestamps  = true;
    protected $deletedField   = 'deleted_at';
    protected $allowedFields  = ['name', 'phone', 'email', 'address', 'balance', 'credit_limit'];

    public function search(?string $term, int $page, int $perPage): array
    {
        $builder = $this->where('deleted_at', null);

        if ($term !== null && $term !== '') {
            $builder = $builder->groupStart()
                ->like('name', $term)
                ->orLike('phone', $term)
                ->orLike('email', $term)
                ->groupEnd();
        }

        $total = $builder->countAllResults(false);
        $rows  = $builder->orderBy('name', 'ASC')
            ->findAll($perPage, ($page - 1) * $perPage);

        return ['items' => array_map([self::class, 'cast'], $rows), 'total' => $total];
    }

    public static function cast(array $customer): array
    {
        $customer['id']      = (int) $customer['id'];
        $customer['balance'] = (float) $customer['balance'];
        $customer['credit_limit'] = (float) ($customer['credit_limit'] ?? 0);

        return $customer;
    }
}

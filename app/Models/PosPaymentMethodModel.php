<?php

namespace App\Models;

use CodeIgniter\Model;

class PosPaymentMethodModel extends Model
{
    protected $table          = 'pos_payment_methods';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $allowedFields  = [
        'account_id',
        'name',
        'code',
        'type',
        'is_active',
        'sort_order',
        'created_at',
        'updated_at',
    ];

    /**
     * Active payment methods formatted for the POS checkout terminal.
     */
    public function getActiveMethods(): array
    {
        return $this->select('pos_payment_methods.*, accounts.name as account_name, accounts.code as account_code')
            ->join('accounts', 'accounts.id = pos_payment_methods.account_id', 'left')
            ->where('pos_payment_methods.is_active', 1)
            ->orderBy('pos_payment_methods.sort_order', 'ASC')
            ->orderBy('pos_payment_methods.id', 'ASC')
            ->findAll();
    }

    /**
     * All payment methods with their linked Chart of Accounts details.
     */
    public function getAllWithAccounts(): array
    {
        return $this->select('pos_payment_methods.*, accounts.name as account_name, accounts.code as account_code, account_groups.name as group_name')
            ->join('accounts', 'accounts.id = pos_payment_methods.account_id', 'left')
            ->join('account_groups', 'account_groups.id = accounts.account_group_id', 'left')
            ->orderBy('pos_payment_methods.sort_order', 'ASC')
            ->orderBy('pos_payment_methods.id', 'ASC')
            ->findAll();
    }
}

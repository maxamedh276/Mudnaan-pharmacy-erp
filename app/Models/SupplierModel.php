<?php

namespace App\Models;


class SupplierModel extends FinanciallyProtectedModel
{
    protected $table          = 'suppliers';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = true;
    protected $useTimestamps  = true;
    protected $updatedField   = '';       // table has no updated_at column
    protected $deletedField   = 'deleted_at';
    protected $allowedFields  = ['name', 'phone', 'email', 'address', 'is_active'];

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

        return ['items' => array_map([self::class, 'cast'], $this->withBalances($rows)), 'total' => $total];
    }

    /**
     * Attaches each supplier's outstanding payable as `balance` (>= 0;
     * 0 = nothing owed). There's no persisted balance column (unlike
     * customers), so this is always computed live as total billed minus
     * total paid.
     *
     * Deliberately NOT "sum each bill's (total - paid_total)": a payment
     * can be recorded on-account (no bill_id — see supplier_payments'
     * schema comment) without ever being applied against a specific
     * bill's paid_total, so a per-bill sum would understate how much has
     * actually been paid. Billed-minus-paid stays correct regardless of
     * whether payments were allocated to a bill.
     *
     * @param list<array<string,mixed>> $suppliers
     * @return list<array<string,mixed>>
     */
    public function withBalances(array $suppliers): array
    {
        if ($suppliers === []) {
            return $suppliers;
        }

        $ids = array_map('intval', array_column($suppliers, 'id'));

        $billed = $this->db->table('supplier_bills')
            ->select('supplier_id, SUM(total + refunded_total) AS billed')
            ->whereIn('supplier_id', $ids)
            ->where('status !=', 'cancelled')
            ->groupBy('supplier_id')
            ->get()->getResultArray();

        $paid = $this->db->table('supplier_payments')
            ->select('supplier_id, SUM(amount) AS paid')
            ->whereIn('supplier_id', $ids)
            ->where('status', 'active')
            ->groupBy('supplier_id')
            ->get()->getResultArray();

        $billedBySupplier = [];

        foreach ($billed as $row) {
            $billedBySupplier[(int) $row['supplier_id']] = (float) $row['billed'];
        }

        $paidBySupplier = [];

        foreach ($paid as $row) {
            $paidBySupplier[(int) $row['supplier_id']] = (float) $row['paid'];
        }

        foreach ($suppliers as &$supplier) {
            $id = (int) $supplier['id'];
            $supplier['balance'] = max(
                0.0,
                round(($billedBySupplier[$id] ?? 0.0) - ($paidBySupplier[$id] ?? 0.0), 2),
            );
        }

        return $suppliers;
    }

    public static function cast(array $supplier): array
    {
        $supplier['id']        = (int) $supplier['id'];
        $supplier['is_active'] = (bool) ($supplier['is_active'] ?? true);
        $supplier['balance']   = round((float) ($supplier['balance'] ?? 0), 2);

        return $supplier;
    }

    /**
     * True if another (non-deleted) supplier already uses this phone.
     * Pass the supplier's own id as [$exceptId] when editing so it
     * doesn't collide with itself.
     */
    public function phoneExists(string $phone, ?int $exceptId = null): bool
    {
        $builder = $this->where('deleted_at', null)->where('phone', $phone);

        if ($exceptId !== null) {
            $builder = $builder->where('id !=', $exceptId);
        }

        return $builder->countAllResults() > 0;
    }
}

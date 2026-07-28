<?php

namespace App\Models;

use CodeIgniter\Model;

class SupplierModel extends Model
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

        return ['items' => array_map([self::class, 'cast'], $rows), 'total' => $total];
    }

    public static function cast(array $supplier): array
    {
        $supplier['id']        = (int) $supplier['id'];
        $supplier['is_active'] = (bool) ($supplier['is_active'] ?? true);

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

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

        foreach ($rows as &$row) {
            $row['id']        = (int) $row['id'];
            $row['is_active'] = (bool) $row['is_active'];
        }

        return ['items' => $rows, 'total' => $total];
    }
}

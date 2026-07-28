<?php

namespace App\Models;

use CodeIgniter\Model;

class CategoryModel extends Model
{
    protected $table          = 'categories';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = true;
    protected $useTimestamps  = true;
    protected $updatedField   = '';       // table has no updated_at column
    protected $deletedField   = 'deleted_at';
    protected $allowedFields  = ['parent_id', 'name', 'is_active'];

    public static function cast(array $category): array
    {
        $category['id']        = (int) $category['id'];
        $category['parent_id'] = $category['parent_id'] === null ? null : (int) $category['parent_id'];
        $category['is_active'] = (bool) ($category['is_active'] ?? true);

        return $category;
    }
}

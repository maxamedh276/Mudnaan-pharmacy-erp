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
}

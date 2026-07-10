<?php

namespace App\Controllers\Pos;

use App\Controllers\BaseApiController;
use App\Models\CategoryModel;
use CodeIgniter\HTTP\ResponseInterface;

class CategoriesController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        if ($denied = $this->requirePermission('products.view')) {
            return $denied;
        }

        $categories = (new CategoryModel())
            ->where('is_active', 1)
            ->orderBy('name', 'ASC')
            ->findAll();

        foreach ($categories as &$category) {
            $category['id']        = (int) $category['id'];
            $category['parent_id'] = $category['parent_id'] === null ? null : (int) $category['parent_id'];
            $category['is_active'] = (bool) $category['is_active'];
        }

        return $this->respondData($categories);
    }

    public function create(): ResponseInterface
    {
        if ($denied = $this->requirePermission('products.create')) {
            return $denied;
        }

        $body = $this->body();
        $name = trim((string) ($body['name'] ?? ''));

        if ($name === '') {
            return $this->respondValidationErrors(['name' => 'Category name is required.']);
        }

        $categoryModel = new CategoryModel();
        $categoryId    = $categoryModel->insert([
            'name'      => $name,
            'parent_id' => $body['parent_id'] ?? null,
        ]);

        return $this->respondData($categoryModel->find($categoryId), 201);
    }
}

<?php

namespace App\Controllers\Pos;

use App\Controllers\BaseApiController;
use App\Libraries\AuthContext;
use App\Models\ProductModel;
use App\Services\InventoryService;
use CodeIgniter\HTTP\ResponseInterface;

class ProductsController extends BaseApiController
{
    /**
     * GET /api/v1/products?q=&category_id=&stock=low|out&page=&per_page=
     */
    public function index(): ResponseInterface
    {
        if ($denied = $this->requirePermission('products.view')) {
            return $denied;
        }

        ['page' => $page, 'perPage' => $perPage] = $this->paginationParams();

        $filters = [
            'q'           => $this->request->getGet('q'),
            'category_id' => $this->request->getGet('category_id'),
            'stock'       => $this->request->getGet('stock'),
        ];

        $result = (new ProductModel())->listWithStock($this->branchId(), $filters, $page, $perPage);

        return $this->respondData(
            $result['items'],
            200,
            $this->paginationMeta($page, $perPage, $result['total']),
        );
    }

    public function show(int $productId): ResponseInterface
    {
        if ($denied = $this->requirePermission('products.view')) {
            return $denied;
        }

        $product = (new ProductModel())->findWithStock($productId, $this->branchId());

        return $product === null
            ? $this->respondNotFound('Product')
            : $this->respondData($product);
    }

    public function create(): ResponseInterface
    {
        if ($denied = $this->requirePermission('products.create')) {
            return $denied;
        }

        $body = $this->body();

        if ($errors = $this->validateProduct($body, requireName: true)) {
            return $this->respondValidationErrors($errors);
        }

        $productModel = new ProductModel();
        $productId    = $productModel->insert($this->productFields($body));

        // Optional opening stock so a new product is sellable immediately.
        $openingQuantity = (float) ($body['opening_stock'] ?? 0);

        if ($openingQuantity > 0) {
            (new InventoryService())->recordMovement(
                $this->branchId(),
                (int) $productId,
                $openingQuantity,
                'opening',
                'opening',
                null,
                AuthContext::userId(),
            );
        }

        return $this->respondData(
            $productModel->findWithStock((int) $productId, $this->branchId()),
            201,
        );
    }

    public function update(int $productId): ResponseInterface
    {
        if ($denied = $this->requirePermission('products.edit')) {
            return $denied;
        }

        $productModel = new ProductModel();

        if ($productModel->find($productId) === null) {
            return $this->respondNotFound('Product');
        }

        $body = $this->body();

        if ($errors = $this->validateProduct($body, requireName: false)) {
            return $this->respondValidationErrors($errors);
        }

        $fields = array_intersect_key($this->productFields($body), $body);

        if ($fields !== []) {
            $productModel->update($productId, $fields);
        }

        return $this->respondData($productModel->findWithStock($productId, $this->branchId()));
    }

    public function delete(int $productId): ResponseInterface
    {
        if ($denied = $this->requirePermission('products.delete')) {
            return $denied;
        }

        $productModel = new ProductModel();

        if ($productModel->find($productId) === null) {
            return $this->respondNotFound('Product');
        }

        $productModel->delete($productId);

        return $this->respondData(['message' => 'Product deleted.']);
    }

    private function validateProduct(array $body, bool $requireName): array
    {
        $errors = [];

        if ($requireName && trim((string) ($body['name'] ?? '')) === '') {
            $errors['name'] = 'Product name is required.';
        }

        foreach (['cost_price', 'sell_price', 'tax_rate', 'opening_stock'] as $numericField) {
            if (isset($body[$numericField]) && (! is_numeric($body[$numericField]) || (float) $body[$numericField] < 0)) {
                $errors[$numericField] = 'Must be a number of zero or more.';
            }
        }

        return $errors;
    }

    private function productFields(array $body): array
    {
        return [
            'category_id'   => $body['category_id'] ?? null,
            'supplier_id'   => $body['supplier_id'] ?? null,
            'sku'           => $body['sku'] ?? null,
            'barcode'       => $body['barcode'] ?? null,
            'name'          => $body['name'] ?? null,
            'brand'         => $body['brand'] ?? null,
            'unit'          => $body['unit'] ?? 'pcs',
            'cost_price'    => $body['cost_price'] ?? 0,
            'sell_price'    => $body['sell_price'] ?? 0,
            'tax_rate'      => $body['tax_rate'] ?? 0,
            'reorder_level' => $body['reorder_level'] ?? 0,
            'attributes'    => isset($body['attributes']) ? json_encode($body['attributes']) : null,
            'track_batches' => (int) ($body['track_batches'] ?? 0),
            'track_serials' => (int) ($body['track_serials'] ?? 0),
            'is_active'     => (int) ($body['is_active'] ?? 1),
        ];
    }
}

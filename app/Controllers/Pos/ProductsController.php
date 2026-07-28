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
        // Booked to the ledger AND the books (Inventory / Owner's Capital).
        $openingQuantity = (float) ($body['opening_stock'] ?? 0);

        if ($openingQuantity > 0) {
            $branchId = $this->branchId();

            (new InventoryService())->recordMovement(
                $branchId,
                (int) $productId,
                $openingQuantity,
                'opening',
                'opening',
                null,
                AuthContext::userId(),
            );

            if (AuthContext::isPremium()) {
                $openingValue = round($openingQuantity * (float) ($body['cost_price'] ?? 0), 2);
                (new \App\Services\AccountingService())->postOpeningStock(
                    $branchId,
                    $openingValue,
                    (int) $productId,
                    AuthContext::userId(),
                );
            }
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

    /**
     * POST /api/v1/products/{id}/image  (multipart, field: image)
     * Stores the photo under the web-served uploads/products/ folder and
     * saves its relative path on the product.
     */
    public function uploadImage(int $productId): ResponseInterface
    {
        if ($denied = $this->requirePermission('products.edit')) {
            return $denied;
        }

        $productModel = new ProductModel();
        $existing     = $productModel->find($productId);

        if ($existing === null) {
            return $this->respondNotFound('Product');
        }

        $file = $this->request->getFile('image');

        if ($file === null || ! $file->isValid()) {
            return $this->respondValidationErrors(['image' => 'A valid image file is required.']);
        }

        if (! in_array(strtolower((string) $file->getExtension()), ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return $this->respondValidationErrors(['image' => 'Only JPG, PNG or WEBP images are allowed.']);
        }

        if ($file->getSize() > 4 * 1024 * 1024) {
            return $this->respondValidationErrors(['image' => 'Image must be 4 MB or smaller.']);
        }

        $dir = FCPATH . 'uploads/products/';

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $newName = $file->getRandomName();
        $file->move($dir, $newName);

        // Drop the previous image file, if any, so old uploads don't pile up.
        if (! empty($existing['image_path'])) {
            $old = FCPATH . $existing['image_path'];

            if (is_file($old)) {
                @unlink($old);
            }
        }

        $productModel->update($productId, ['image_path' => 'uploads/products/' . $newName]);

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

        // Any stock still on hand is written off first, so deleting a
        // product never leaves its value stranded in the Inventory
        // account — stock value and the books stay reconciled.
        $branchId = $this->branchId();
        $onHand   = (new InventoryService())->currentQuantity($branchId, $productId);

        if ($onHand > 0) {
            (new InventoryService())->adjustStock(
                $branchId,
                $productId,
                -$onHand,
                'Written off on product deletion',
                AuthContext::userId(),
                (string) AuthContext::plan(),
            );
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

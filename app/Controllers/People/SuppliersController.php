<?php

namespace App\Controllers\People;

use App\Controllers\BaseApiController;
use App\Models\SupplierModel;
use CodeIgniter\HTTP\ResponseInterface;

class SuppliersController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        if ($denied = $this->requirePermission('suppliers.view')) {
            return $denied;
        }

        ['page' => $page, 'perPage' => $perPage] = $this->paginationParams();

        $result = (new SupplierModel())->search($this->request->getGet('q'), $page, $perPage);

        return $this->respondData(
            $result['items'],
            200,
            $this->paginationMeta($page, $perPage, $result['total']),
        );
    }

    public function show(int $supplierId): ResponseInterface
    {
        if ($denied = $this->requirePermission('suppliers.view')) {
            return $denied;
        }

        $supplier = (new SupplierModel())->find($supplierId);

        return $supplier === null
            ? $this->respondNotFound('Supplier')
            : $this->respondData($supplier);
    }

    public function create(): ResponseInterface
    {
        if ($denied = $this->requirePermission('suppliers.manage')) {
            return $denied;
        }

        $body = $this->body();

        if (trim((string) ($body['name'] ?? '')) === '') {
            return $this->respondValidationErrors(['name' => 'Supplier name is required.']);
        }

        $supplierModel = new SupplierModel();
        $supplierId    = $supplierModel->insert([
            'name'    => trim($body['name']),
            'phone'   => $body['phone'] ?? null,
            'email'   => $body['email'] ?? null,
            'address' => $body['address'] ?? null,
        ]);

        return $this->respondData($supplierModel->find($supplierId), 201);
    }

    public function update(int $supplierId): ResponseInterface
    {
        if ($denied = $this->requirePermission('suppliers.manage')) {
            return $denied;
        }

        $supplierModel = new SupplierModel();

        if ($supplierModel->find($supplierId) === null) {
            return $this->respondNotFound('Supplier');
        }

        $body   = $this->body();
        $fields = array_intersect_key($body, array_flip(['name', 'phone', 'email', 'address', 'is_active']));

        if ($fields !== []) {
            $supplierModel->update($supplierId, $fields);
        }

        return $this->respondData($supplierModel->find($supplierId));
    }

    public function delete(int $supplierId): ResponseInterface
    {
        if ($denied = $this->requirePermission('suppliers.manage')) {
            return $denied;
        }

        $supplierModel = new SupplierModel();

        if ($supplierModel->find($supplierId) === null) {
            return $this->respondNotFound('Supplier');
        }

        $supplierModel->delete($supplierId);

        return $this->respondData(['message' => 'Supplier deleted.']);
    }
}

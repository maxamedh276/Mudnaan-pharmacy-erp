<?php

namespace App\Controllers\People;

use App\Controllers\BaseApiController;
use App\Models\CustomerModel;
use CodeIgniter\HTTP\ResponseInterface;

class CustomersController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        if ($denied = $this->requirePermission('customers.view')) {
            return $denied;
        }

        ['page' => $page, 'perPage' => $perPage] = $this->paginationParams();

        $result = (new CustomerModel())->search($this->request->getGet('q'), $page, $perPage);

        return $this->respondData(
            $result['items'],
            200,
            $this->paginationMeta($page, $perPage, $result['total']),
        );
    }

    public function show(int $customerId): ResponseInterface
    {
        if ($denied = $this->requirePermission('customers.view')) {
            return $denied;
        }

        $customer = (new CustomerModel())->find($customerId);

        return $customer === null
            ? $this->respondNotFound('Customer')
            : $this->respondData(CustomerModel::cast($customer));
    }

    public function create(): ResponseInterface
    {
        if ($denied = $this->requirePermission('customers.manage')) {
            return $denied;
        }

        $body = $this->body();

        if (trim((string) ($body['name'] ?? '')) === '') {
            return $this->respondValidationErrors(['name' => 'Customer name is required.']);
        }

        $customerModel = new CustomerModel();
        $customerId    = $customerModel->insert([
            'name'    => trim($body['name']),
            'phone'   => $body['phone'] ?? null,
            'email'   => $body['email'] ?? null,
            'address' => $body['address'] ?? null,
        ]);

        return $this->respondData(CustomerModel::cast($customerModel->find($customerId)), 201);
    }

    public function update(int $customerId): ResponseInterface
    {
        if ($denied = $this->requirePermission('customers.manage')) {
            return $denied;
        }

        $customerModel = new CustomerModel();

        if ($customerModel->find($customerId) === null) {
            return $this->respondNotFound('Customer');
        }

        $body   = $this->body();
        $fields = array_intersect_key($body, array_flip(['name', 'phone', 'email', 'address']));

        if ($fields !== []) {
            $customerModel->update($customerId, $fields);
        }

        return $this->respondData(CustomerModel::cast($customerModel->find($customerId)));
    }

    public function delete(int $customerId): ResponseInterface
    {
        if ($denied = $this->requirePermission('customers.manage')) {
            return $denied;
        }

        $customerModel = new CustomerModel();

        if ($customerModel->find($customerId) === null) {
            return $this->respondNotFound('Customer');
        }

        $customerModel->delete($customerId);

        return $this->respondData(['message' => 'Customer deleted.']);
    }
}

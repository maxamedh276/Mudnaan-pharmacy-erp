<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\CLIRequest;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Shared helpers for every API controller: the standard JSON envelope,
 * request-body parsing, and pagination parameters.
 *
 * Success:  { "success": true,  "data": ..., "meta": ... }
 * Failure:  { "success": false, "error": { "code", "message", "details" } }
 *
 * Error `code` values are machine-readable so the mobile app can map
 * them to translated messages (see mobile_app_structure_provider.md §9).
 */
abstract class BaseApiController extends Controller
{
    /** @var CLIRequest|IncomingRequest */
    protected $request;

    protected const MAX_PER_PAGE = 100;

    public function initController(
        RequestInterface $request,
        ResponseInterface $response,
        LoggerInterface $logger,
    ): void {
        parent::initController($request, $response, $logger);
    }

    protected function respondData(mixed $data, int $status = 200, ?array $meta = null): ResponseInterface
    {
        $payload = ['success' => true, 'data' => $data];

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return $this->response->setStatusCode($status)->setJSON($payload);
    }

    protected function respondError(
        string $errorCode,
        string $message,
        int $status = 400,
        mixed $details = null,
    ): ResponseInterface {
        $error = ['code' => $errorCode, 'message' => $message];

        if ($details !== null) {
            $error['details'] = $details;
        }

        return $this->response->setStatusCode($status)->setJSON([
            'success' => false,
            'error'   => $error,
        ]);
    }

    protected function respondValidationErrors(array $errors): ResponseInterface
    {
        return $this->respondError(
            'VALIDATION_ERROR',
            'Some fields are missing or invalid.',
            422,
            $errors,
        );
    }

    protected function respondNotFound(string $what = 'Resource'): ResponseInterface
    {
        return $this->respondError('NOT_FOUND', $what . ' not found.', 404);
    }

    /**
     * Request body as an array, whether sent as JSON or form data.
     */
    protected function body(): array
    {
        $json = $this->request->getJSON(true);

        if (is_array($json)) {
            return $json;
        }

        return $this->request->getPost() ?: [];
    }

    /**
     * @return array{page: int, perPage: int}
     */
    protected function paginationParams(int $defaultPerPage = 20): array
    {
        $page    = max(1, (int) $this->request->getGet('page'));
        $perPage = (int) ($this->request->getGet('per_page') ?: $defaultPerPage);
        $perPage = min(max(1, $perPage), self::MAX_PER_PAGE);

        return ['page' => $page, 'perPage' => $perPage];
    }

    protected function paginationMeta(int $page, int $perPage, int $total): array
    {
        return [
            'page'       => $page,
            'per_page'   => $perPage,
            'total'      => $total,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }
}

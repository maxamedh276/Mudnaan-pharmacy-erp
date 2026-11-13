<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\AuthContext;
use App\Services\DocumentReturnService;
use App\Services\MenuPermissionService;
use App\Services\ReceiptCashAccountService;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;
use Throwable;

class ReturnsWebController extends BaseController
{
    private function authorize(string $kind): int
    {
        $sale = $kind === 'sale';
        if (!in_array($kind, ['sale', 'purchase'], true) || !AuthContext::$user || !AuthContext::can($sale ? 'sales.refund' : 'purchases.return')) {
            throw new HTTPException('You do not have permission to process returns.', 403);
        }
        $menus = (new MenuPermissionService())->getUserMenuKeys((int)AuthContext::$user->id, (int)AuthContext::$user->role_id);
        if (!in_array($sale ? 'sales.returns' : 'suppliers.returns', $menus, true)) throw new HTTPException('You do not have access to this return page.', 403);
        $branch = (int)(AuthContext::$user->branch_id ?? 0);
        if ($branch <= 0) throw new HTTPException('Select an authorized branch.', 403);
        return $branch;
    }

    public function index(string $kind): string
    {
        $branch = $this->authorize($kind);
        $service = new DocumentReturnService();
        return view('web/returns/index', [
            'title' => $kind === 'sale' ? 'Sales Returns' : 'Purchase Returns', 'kind' => $kind,
            'returns' => $service->listing($kind, $branch), 'sources' => $service->sources($kind, $branch),
            'accounts' => (new ReceiptCashAccountService())->list(),
        ]);
    }

    public function source(string $kind, int $id): ResponseInterface
    {
        $branch = $this->authorize($kind);
        return $this->result(fn() => (new DocumentReturnService())->source($kind, $id, $branch));
    }

    public function details(string $kind, int $id): ResponseInterface
    {
        $branch = $this->authorize($kind);
        return $this->result(fn() => (new DocumentReturnService())->find($kind, $id, $branch));
    }

    public function quote(string $kind, int $id): ResponseInterface
    {
        $branch = $this->authorize($kind);
        return $this->result(fn() => (new DocumentReturnService())->quote($kind, $id, $this->body(), $branch));
    }

    public function save(string $kind, int $id): ResponseInterface
    {
        $branch = $this->authorize($kind);
        return $this->result(function () use ($kind, $id, $branch) {
            $body = $this->body();
            if (!is_string($body['quote_token'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $body['quote_token'])) throw new InvalidArgumentException('Preview the return before posting it.');
            return (new DocumentReturnService())->create($kind, $id, $body, $branch, (int)AuthContext::userId(), AuthContext::plan() ?? 'basic');
        });
    }

    private function body(): array
    {
        $body = $this->request->getJSON(true);
        if (!is_array($body)) throw new InvalidArgumentException('Enter valid return details.');
        return $body;
    }

    private function result(callable $operation): ResponseInterface
    {
        try {
            return $this->response->setJSON(['success' => true, 'data' => $operation()]);
        } catch (InvalidArgumentException $error) {
            $message = $error->getMessage();
            if ($message === 'PERIOD_CLOSED') $message = 'The current financial period is closed. Ask an administrator to open it before posting this return.';
            return $this->response->setStatusCode(422)->setJSON(['success' => false, 'message' => $message]);
        } catch (Throwable $error) {
            log_message('error', 'Return failed: ' . $error->getMessage());
            $message = $error->getMessage() === 'PERIOD_CLOSED'
                ? 'The current financial period is closed. No return was posted.'
                : 'The return could not be completed. Refresh the order and retry. No partial return was saved.';
            return $this->response->setStatusCode(409)->setJSON(['success' => false, 'message' => $message]);
        }
    }
}

<?php
namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Services\Web\ImportWorkbookReader;
use App\Services\Web\WorkbookImportService;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;

final class WorkbookImportsController extends BaseController
{
    public function template(string $kind): ResponseInterface
    {
        (new WorkbookImportService())->authorize($kind);
        return $this->response->download(FCPATH . "assets/import-templates/$kind.xlsx", null)->setFileName("$kind-sample.xlsx");
    }

    public function upload(string $kind): ResponseInterface
    {
        $service = new WorkbookImportService();
        $service->authorize($kind);
        if (!$this->request->isAJAX()) return $this->response->setStatusCode(403)->setJSON(['success'=>false, 'message'=>'Use the import form.']);
        try {
            $action = $this->request->getPost('action');
            if (!in_array($action, ['validate', 'import'], true)) throw new InvalidArgumentException('Choose Validate Excel or Import all rows.');
            $file = $this->request->getFile('workbook');
            if (!$file || !$file->isValid() || strtolower($file->getClientExtension()) !== 'xlsx' || $file->getSize() > ImportWorkbookReader::MAX_BYTES) throw new InvalidArgumentException('Choose a valid .xlsx file no larger than 5 MB.');
            $hash = hash_file('sha256', $file->getTempName());
            $sessionKey = 'workbook_import_' . hash('sha256', db_connect()->database . ':' . (\App\Libraries\AuthContext::$user->branch_id ?? 0) . ':' . $kind);
            $previous = session()->get($sessionKey);
            if ($action === 'import' && is_array($previous) && ($previous['hash'] ?? '') === $hash && ($previous['user'] ?? null) === \App\Libraries\AuthContext::userId()) {
                return $this->response->setJSON(['success'=>true, 'data'=>$previous['result']]);
            }
            $result = $service->process($kind, $file->getTempName(), $action === 'import');
            unset($result['records']);
            if (isset($result['imported'])) session()->set($sessionKey, ['hash'=>$hash, 'user'=>\App\Libraries\AuthContext::userId(), 'result'=>$result]);
            return $this->response->setJSON(['success'=>true, 'data'=>$result]);
        } catch (InvalidArgumentException $error) {
            return $this->response->setStatusCode(422)->setJSON(['success'=>false, 'message'=>$error->getMessage()]);
        } catch (\Throwable $error) {
            log_message('error', 'Workbook import failed: ' . $error->getMessage());
            return $this->response->setStatusCode(500)->setJSON(['success'=>false, 'message'=>'The import could not finish. No records were imported. Please validate and try again.']);
        }
    }
}

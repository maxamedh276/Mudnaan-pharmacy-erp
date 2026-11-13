<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\AuthContext;
use App\Services\ThermalPrintService;
use CodeIgniter\HTTP\ResponseInterface;

/** Generate authorized bytes only. The browser delivers them to its local QZ Tray. */
class PrintingWebController extends BaseController
{
    private function authorize(string $permission): void
    {
        if (!AuthContext::can($permission)) {
            throw new \CodeIgniter\HTTP\Exceptions\HTTPException('You do not have permission to print this document.', 403);
        }
        // Require an AJAX request, preventing cross-origin form submissions.
        if (!$this->request->isAJAX()) {
            throw new \CodeIgniter\HTTP\Exceptions\HTTPException('Use the receipt print button.', 403);
        }
    }

    public function sale(int $id): ResponseInterface
    {
        $this->authorize('sales.view');
        $service = new ThermalPrintService(browserPrint: true);
        return $this->generate($service, 'sale', $id, fn () => $service->printSale($id, (int) AuthContext::$user->branch_id));
    }

    public function receipt(int $id): ResponseInterface
    {
        $this->authorize('invoices.view');
        $service = new ThermalPrintService(browserPrint: true);
        return $this->generate($service, 'receipt', $id, fn () => $service->printCustomerReceipt($id, (int) AuthContext::$user->branch_id));
    }

    public function test(): ResponseInterface
    {
        $this->authorize('settings.manage');
        $service = new ThermalPrintService(browserPrint: true);
        return $this->generate($service, 'test', null, fn () => $service->printTestSlip());
    }

    private function authorizeSigning(): void
    {
        if (!$this->request->isAJAX() || !(AuthContext::can('sales.view') || AuthContext::can('invoices.view') || AuthContext::can('settings.manage'))) {
            throw new \CodeIgniter\HTTP\Exceptions\HTTPException('Printing access is required.', 403);
        }
    }

    public function signing(): ResponseInterface
    {
        $this->authorizeSigning();
        try {
            $store = new \App\Services\QzCertificateStore();
            $certificate = $store->signer($this->request->getHeaderLine('X-Print-Workstation'))->certificate();
            return $this->response->setHeader('Cache-Control','no-store')->setJSON(['success'=>true,'data'=>[
                'storageAvailable'=>$store->available(), 'certificate'=>$certificate, 'configured'=>$certificate !== null]]);
        } catch (\App\Services\QzSetupException $error) {
            log_message('error','QZ_PRINT signing_configuration_failed reason='.$error->reason);
            return $this->response->setStatusCode(422)->setJSON(['success'=>false,'code'=>$error->reason,'message'=>$error->getMessage()]);
        } catch (\Throwable $error) {
            log_message('error','QZ_PRINT signing_configuration_failed exception='.get_class($error));
            return $this->response->setStatusCode(422)->setJSON(['success'=>false,'message'=>'QZ signing configuration is unavailable. Ask the administrator to check the private storage directory and certificate.']);
        }
    }

    public function saveSigning(): ResponseInterface
    {
        $this->authorize('settings.manage');
        try {
            $certificate = $this->request->getFile('certificate');
            $key = $this->request->getFile('private_key');
            if (!$certificate || !$key || !$certificate->isValid() || !$key->isValid()
                || $certificate->getSize() > 32768 || $key->getSize() > 16384) {
                throw new \InvalidArgumentException('Select a certificate and matching unencrypted PEM private key.');
            }
            (new \App\Services\QzCertificateStore())->save($this->request->getHeaderLine('X-Print-Workstation'),
                file_get_contents($certificate->getTempName()), file_get_contents($key->getTempName()));
            \App\Services\AuditService::log('printer.signing_configured','settings','settings',null);
            return $this->response->setJSON(['success'=>true]);
        } catch (\InvalidArgumentException $error) {
            return $this->response->setStatusCode(422)->setJSON(['success'=>false,'message'=>$error->getMessage()]);
        } catch (\App\Services\QzSetupException $error) {
            log_message('error','QZ_PRINT signing_upload_failed reason='.$error->reason);
            return $this->response->setStatusCode(422)->setJSON(['success'=>false,'code'=>$error->reason,'message'=>$error->getMessage()]);
        } catch (\Throwable $error) {
            log_message('error','QZ_PRINT signing_upload_failed exception='.get_class($error));
            return $this->response->setStatusCode(422)->setJSON(['success'=>false,'message'=>'Signing setup failed. Check the key/certificate pair, expiry dates and protected server storage.']);
        }
    }

    public function sign(): ResponseInterface
    {
        $this->authorizeSigning();
        try {
            $body = $this->request->getJSON(true);
            if (!is_array($body) || !is_string($body['message'] ?? null)) throw new \InvalidArgumentException('Invalid signing request.');
            $workstation = $this->request->getHeaderLine('X-Print-Workstation');
            $jobs = array_filter(session()->get('qz_signing_jobs') ?? [], static fn ($job) =>
                $job['user_id'] === AuthContext::userId() && $job['workstation'] === $workstation
                && $job['branch_id'] === (int) AuthContext::$user->branch_id && $job['tenant'] === AuthContext::$tenant['code']
                && AuthContext::can(match($job['kind']) {'sale'=>'sales.view','receipt'=>'invoices.view',default=>'settings.manage'}));
            $signature = (new \App\Services\QzCertificateStore())->signer($workstation)->sign($body['message'], $jobs);
            return $this->response->setHeader('Cache-Control','no-store')->setJSON(['success'=>true,'data'=>['signature'=>$signature]]);
        } catch (\Throwable $error) {
            log_message('error','QZ_PRINT signing_request_rejected');
            return $this->response->setStatusCode(422)->setJSON(['success'=>false,'message'=>'QZ signing failed. Reload the receipt and check this computer signing setup.']);
        }
    }

    /** Browser reports are untrusted diagnostics, never proof of physical printing. */
    public function event(): ResponseInterface
    {
        if (!$this->request->isAJAX()) return $this->response->setStatusCode(403)->setJSON(['success'=>false]);
        $body = $this->request->getJSON(true);
        if (!is_array($body)) return $this->response->setStatusCode(422)->setJSON(['success'=>false]);
        $attempt = $body['attempt'] ?? '';
        $jobs = session()->get('qz_print_attempts') ?? [];
        $job = is_string($attempt) ? ($jobs[$attempt] ?? null) : null;
        $codes = ['accepted','permission_denied','certificate_error','timeout','printer_not_found','unavailable'];
        $stages = ['configuration','library','signing','connection','discovery','submission','accepted'];
        if (!$job || $job['expires'] < time() || $job['user_id'] !== AuthContext::userId()
            || !in_array($body['code'] ?? null, $codes, true)
            || !in_array($body['stage'] ?? null, $stages, true) || !is_bool($body['success'] ?? null)) {
            return $this->response->setStatusCode(422)->setJSON(['success'=>false]);
        }
        unset($jobs[$attempt]); // At most one terminal browser event per generated job.
        session()->set('qz_print_attempts', $jobs);
        unset($job['expires']);
        log_message($body['success'] ? 'info' : 'error', 'QZ_PRINT '.json_encode($job + [
            'attempt'=>$attempt, 'source'=>'browser', 'stage'=>$body['stage'],
            'outcome'=>$body['success'] ? 'qz_accepted' : 'failed', 'code'=>$body['code'],
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        return $this->response->setHeader('Cache-Control','no-store')->setJSON(['success'=>true]);
    }

    private function generate(ThermalPrintService $service, string $kind, ?int $id, callable $generate): ResponseInterface
    {
        $attempt = $this->request->getHeaderLine('X-Print-Attempt');
        if (!preg_match('/^[a-f0-9-]{36}$/i', $attempt)) $attempt = \App\Libraries\Uuid::v4();
        $context = ['attempt'=>$attempt, 'source'=>'server', 'kind'=>$kind, 'document_id'=>$id,
            'user_id'=>AuthContext::userId(), 'branch_id'=>(int) AuthContext::$user->branch_id,
            'tenant'=>AuthContext::$tenant['code'] ?? 'unknown'];
        try {
            $result = $generate();
            if (!$result['success']) {
                // Do not copy exception messages, SQL, receipt bytes or customer data into diagnostics.
                log_message('error', 'QZ_PRINT '.json_encode($context + ['stage'=>'generation','outcome'=>'failed']));
            } else {
                $config = $service->getConfig();
                $jobs = array_filter(session()->get('qz_print_attempts') ?? [], static fn ($job) => $job['expires'] >= time());
                $jobs = array_slice($jobs, -19, null, true);
                unset($context['attempt'], $context['source']);
                $jobs[$attempt] = $context + ['printer'=>$config['name'], 'type'=>$config['type'], 'expires'=>time()+600];
                session()->set('qz_print_attempts', $jobs);
                $signingJobs = array_filter(session()->get('qz_signing_jobs') ?? [], static fn ($job) => $job['expires'] >= time());
                $signingJobs = array_slice($signingJobs, -19, null, true);
                $signingJobs[$attempt] = $context + ['hash'=>hash('sha256',$result['data']),
                    'printer'=>$config['type'] === 'network' ? ['host'=>$config['ip'],'port'=>$config['port']] : ['name'=>$config['name']],
                    'workstation'=>$this->request->getHeaderLine('X-Print-Workstation'), 'expires'=>time()+600];
                session()->set('qz_signing_jobs', $signingJobs);
                log_message('info', 'QZ_PRINT '.json_encode($context + ['attempt'=>$attempt,'stage'=>'generation','outcome'=>'ready']));
            }
            return $this->job($service, $result, $attempt);
        } catch (\Throwable $error) {
            log_message('error', 'QZ_PRINT '.json_encode($context + ['stage'=>'generation','outcome'=>'exception','exception'=>get_class($error)]));
            return $this->response->setHeader('Cache-Control','no-store')->setStatusCode(500)
                ->setJSON(['success'=>false,'message'=>'Receipt generation failed. Reference: '.$attempt]);
        }
    }

    private function job(ThermalPrintService $service, array $result, string $attempt): ResponseInterface
    {
        $config = $service->getConfig();
        if (!$result['success']) {
            return $this->response->setHeader('Cache-Control', 'no-store')->setStatusCode(422)
                ->setJSON(['success' => false, 'message' => $result['error']]);
        }
        return $this->response->setHeader('Cache-Control', 'no-store')->setJSON([
            'success' => true,
            'data' => ['attempt' => $attempt, 'bytes' => $result['data'], 'printer' => $config['name'], 'type' => $config['type'],
                'host' => $config['ip'], 'port' => $config['port']],
        ]);
    }
}

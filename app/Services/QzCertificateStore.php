<?php

namespace App\Services;

use App\Libraries\AuthContext;

/** Tenant and workstation isolated credentials, never stored under the web root. */
class QzCertificateStore
{
    public function __construct(private ?string $root = null)
    {
        $this->root ??= (string) env('QZ_SIGNING_DIRECTORY', '');
    }

    public function available(): bool
    {
        return $this->root !== '';
    }

    private function directory(string $workstation): string
    {
        if (!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/i', $workstation)) {
            throw new \InvalidArgumentException('This browser has no valid computer ID. Reload Settings.');
        }
        if (!AuthContext::$tenant) throw new \RuntimeException('Organization context is missing.');
        if ($this->root === '') throw new QzSetupException('storage_not_configured', 'QZ_SIGNING_DIRECTORY is missing from the production .env. Configure a private server folder before uploading.');
        $root = realpath($this->root);
        if (!$root || !is_dir($root)) throw new QzSetupException('storage_missing', 'The QZ signing folder does not exist or PHP cannot access it. Ask your hosting administrator to create it and check open_basedir restrictions.');
        if (!is_writable($root)) throw new QzSetupException('storage_not_writable', 'PHP cannot write to the QZ signing folder. Give the PHP service account access to that private folder.');
        foreach ([FCPATH, $_SERVER['DOCUMENT_ROOT'] ?? FCPATH] as $public) {
            $public = realpath($public);
            if ($public && str_starts_with(strtolower(str_replace('\\', '/', $root)).'/', strtolower(rtrim(str_replace('\\', '/', $public), '/')).'/')) {
                throw new QzSetupException('storage_public', 'QZ_SIGNING_DIRECTORY must be outside public_html and the entire public website directory.');
            }
        }
        $tenant = hash('sha256', AuthContext::$tenant['code'].'|'.AuthContext::$tenant['database']);
        return $root.DIRECTORY_SEPARATOR.$tenant.DIRECTORY_SEPARATOR.strtolower($workstation);
    }

    public function signer(string $workstation): QzSigningService
    {
        if (!$this->available()) return new QzSigningService('', '');
        $directory = $this->directory($workstation);
        $manifest = is_file($directory.'/current.json') ? json_decode(file_get_contents($directory.'/current.json'), true) : null;
        if (!$manifest || !preg_match('/^[a-f0-9]{32}$/', $manifest['version'] ?? '')) return new QzSigningService('', '');
        return new QzSigningService($directory.'/'.$manifest['version'].'.crt', $directory.'/'.$manifest['version'].'.pem');
    }

    public function save(string $workstation, string $certificate, string $privateKey): void
    {
        if (strlen($certificate) > 32768 || strlen($privateKey) > 16384) throw new \InvalidArgumentException('The signing files are too large.');
        $directory = $this->directory($workstation);
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) throw new QzSetupException('storage_create_failed', 'PHP could not create the organization/computer signing folder. Check folder ownership and permissions.');
        $version = bin2hex(random_bytes(16));
        $certPath = $directory.'/'.$version.'.crt';
        $keyPath = $directory.'/'.$version.'.pem';
        try {
            foreach ([$certPath=>$certificate, $keyPath=>$privateKey] as $path=>$contents) {
                $handle = fopen($path, 'x');
                if (!$handle) throw new QzSetupException('storage_write_failed', 'PHP could not save the signing files. Check private folder permissions and available disk space.');
                chmod($path, 0600);
                try { if (fwrite($handle, $contents) !== strlen($contents)) throw new QzSetupException('storage_write_failed', 'PHP could not save the signing files. Check private folder permissions and available disk space.'); }
                finally { fclose($handle); }
            }
            (new QzSigningService($certPath, $keyPath))->certificate();
            $temporary = $directory.'/'.$version.'.json';
            file_put_contents($temporary, json_encode(['version'=>$version]), LOCK_EX);
            chmod($temporary, 0600);
            if (!rename($temporary, $directory.'/current.json')) throw new QzSetupException('storage_activate_failed', 'PHP could not activate the signing files. Check permissions on the existing private signing folder.');
        } catch (\Throwable $error) {
            foreach ([$certPath, $keyPath, $directory.'/'.$version.'.json'] as $path) if (is_file($path)) unlink($path);
            throw $error;
        }
    }
}

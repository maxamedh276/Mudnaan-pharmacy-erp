<?php

namespace App\Services;

/** Sign only printer discovery and previously authorized receipt bytes. */
class QzSigningService
{
    public function __construct(private ?string $certificatePath = null, private ?string $keyPath = null)
    {
        $this->certificatePath ??= (string) env('QZ_CERTIFICATE_PATH', '');
        $this->keyPath ??= (string) env('QZ_PRIVATE_KEY_PATH', '');
    }

    public function certificate(): ?string
    {
        if ($this->certificatePath === '' && $this->keyPath === '') return null;
        [$certificate] = $this->credentials();
        return $certificate;
    }

    private function credentials(): array
    {
        $paths = [];
        foreach ([$this->certificatePath, $this->keyPath] as $path) {
            $real = realpath($path);
            $public = rtrim(str_replace('\\', '/', realpath(FCPATH)), '/') . '/';
            if (!$real || !is_readable($real) || !is_file($real)
                || str_starts_with(strtolower(str_replace('\\', '/', $real)), strtolower($public))) {
                throw new \RuntimeException('Store readable QZ signing files outside the website directory.');
            }
            $paths[] = $real;
        }
        if (!extension_loaded('openssl')) throw new QzSetupException('openssl_missing', 'Enable the PHP OpenSSL extension on the production server.');
        $certificate = file_get_contents($paths[0]);
        $key = @openssl_pkey_get_private(file_get_contents($paths[1]));
        $details = @openssl_x509_parse($certificate);
        $keyDetails = $key ? openssl_pkey_get_details($key) : false;
        if (!$details) throw new QzSetupException('certificate_invalid', 'The certificate file is not a valid PEM certificate. Select digital-certificate.txt from the QZ-generated folder.');
        if (!$key) throw new QzSetupException('key_invalid', 'The private key could not be opened. Select the unencrypted private-key.pem from the same QZ-generated folder.');
        if (!$keyDetails || $keyDetails['type'] !== OPENSSL_KEYTYPE_RSA || $keyDetails['bits'] < 2048) {
            throw new QzSetupException('key_strength', 'QZ requires an RSA private key of at least 2048 bits.');
        }
        if (!openssl_x509_check_private_key($certificate, $key)) throw new QzSetupException('key_mismatch', 'The certificate and private key do not match. Upload both files from the same QZ certificate folder.');
        if ($details['validTo_time_t'] < time()) throw new QzSetupException('certificate_expired', 'The QZ certificate has expired. Generate a new pair.');
        if ($details['validFrom_time_t'] > time()) throw new QzSetupException('certificate_not_yet_valid', 'The certificate is not valid yet. Check the production server clock and certificate start date.');
        return [$certificate, $key];
    }

    public function sign(string $message, array $jobs): string
    {
        if (strlen($message) > 1048576) throw new \InvalidArgumentException('Signing request is too large.');
        $request = json_decode($message, true);
        if (!is_array($request) || !is_numeric($request['timestamp'] ?? null)
            || abs(microtime(true) * 1000 - $request['timestamp']) > 120000) {
            throw new \InvalidArgumentException('Invalid or expired signing request.');
        }
        $params = $request['params'] ?? [];
        $authorized = false;
        if (($request['call'] ?? '') === 'printers.find' && is_array($params) && $params === []) {
            $authorized = true;
        } elseif (($request['call'] ?? '') === 'print' && is_array($params)) {
            $data = $params['data'] ?? [];
            $options = $params['options'] ?? [];
            $item = $data[0] ?? [];
            if (count($data) === 1 && is_array($item) && count($item) === 4
                && ($item['type'] ?? '') === 'raw' && ($item['format'] ?? '') === 'command'
                && ($item['flavor'] ?? '') === 'base64' && is_string($item['data'] ?? null)
                && ($options['copies'] ?? 1) === 1) {
                foreach ($jobs as $job) {
                    if ($job['expires'] >= time() && hash_equals($job['hash'], hash('sha256', $item['data']))
                        && ($params['printer'] ?? null) === $job['printer']) {
                        $authorized = true;
                        break;
                    }
                }
            }
        }
        if (!$authorized) throw new \InvalidArgumentException('This QZ operation or receipt was not authorized.');
        [, $key] = $this->credentials();
        // qz-tray.js signs the SHA256 hex digest of its exact serialized request.
        if (!openssl_sign(hash('sha256', $message), $signature, $key, OPENSSL_ALGO_SHA512)) {
            throw new \RuntimeException('QZ signing failed.');
        }
        return base64_encode($signature);
    }
}

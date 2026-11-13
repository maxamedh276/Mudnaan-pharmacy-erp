<?php
namespace App\Services\Pharmacy;

/** Preserve real HTTP status codes for pharmacy web authorization and request errors. */
final class PharmacyHttpException extends \RuntimeException implements \CodeIgniter\Exceptions\HTTPExceptionInterface
{
}

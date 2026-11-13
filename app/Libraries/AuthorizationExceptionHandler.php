<?php
namespace App\Libraries;

use CodeIgniter\Debug\BaseExceptionHandler;
use CodeIgniter\Debug\ExceptionHandler;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/** Legacy permission guards throw HTTPException, which CI otherwise classifies as HTTP 500. */
final class AuthorizationExceptionHandler extends BaseExceptionHandler implements ExceptionHandlerInterface
{
    public function handle(Throwable $exception, RequestInterface $request, ResponseInterface $response, int $statusCode, int $exitCode): void
    {
        // Retain the framework's HTML/JSON/CLI negotiation, using the actual permission status.
        (new ExceptionHandler($this->config))->handle($exception, $request, $response, 403, $exitCode);
    }
}

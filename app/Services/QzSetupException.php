<?php
namespace App\Services;

/** Safe setup diagnostics: never include filesystem paths or key material. */
class QzSetupException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}

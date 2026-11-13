<?php
namespace App\Services;

/** The open POS ticket must be reviewed against the current active tax before saving. */
class TaxChangedException extends \RuntimeException
{
    public function __construct(public readonly ?array $activeTax)
    {
        parent::__construct('The active tax changed. Review the updated total and try again.');
    }
}

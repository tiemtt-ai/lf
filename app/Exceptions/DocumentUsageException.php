<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/** Measured completed local extraction units, not a price or estimated charge. */
class DocumentUsageException extends RuntimeException
{
    public function __construct(Throwable $previous, public readonly int $pages, public readonly string $unitType = 'page')
    {
        parent::__construct($previous->getMessage(), 0, $previous);
    }
}

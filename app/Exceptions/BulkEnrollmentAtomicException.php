<?php

namespace App\Exceptions;

use RuntimeException;

class BulkEnrollmentAtomicException extends RuntimeException
{
    public function __construct(public readonly array $preflight)
    {
        parent::__construct('Bulk Enrollment submission failed atomic revalidation.');
    }
}

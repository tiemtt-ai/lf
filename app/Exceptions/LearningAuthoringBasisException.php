<?php

namespace App\Exceptions;

use RuntimeException;

/** Learning owner-port refusal for a Step 7 Framework basis. */
class LearningAuthoringBasisException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}

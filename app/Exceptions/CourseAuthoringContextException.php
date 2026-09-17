<?php

namespace App\Exceptions;

use RuntimeException;

/** Course owner-port refusal. `not_found` deliberately covers "not yours" too. */
class CourseAuthoringContextException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}

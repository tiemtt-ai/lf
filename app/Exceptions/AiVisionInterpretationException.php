<?php

namespace App\Exceptions;

use RuntimeException;

class AiVisionInterpretationException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}

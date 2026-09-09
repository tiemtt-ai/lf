<?php

namespace App\Exceptions;

use RuntimeException;

class AiKnowledgeIngestionException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}

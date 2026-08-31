<?php

namespace App\Exceptions;

use RuntimeException;

class DocumentCommandFailure extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $exitCode, public readonly ?int $signal)
    {
        parent::__construct($message);
    }
}

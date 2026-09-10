<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Carries only a stable error code. The message is the code itself so that no
 * caller can accidentally surface payload, prompt content or a credential
 * through an exception string.
 */
class AiProviderGateException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}

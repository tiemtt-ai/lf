<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Carries a Step 7 application-local outcome code (contract § Request/response
 * contract). `detail` names the offending field for invalid_proposal; it never
 * contains payload, source text or provider output.
 */
class AiAuthoringProposalException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly ?string $detail = null)
    {
        parent::__construct($errorCode);
    }
}

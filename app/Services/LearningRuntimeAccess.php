<?php

namespace App\Services;

use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

final class LearningRuntimeAccess
{
    public function tenantId(): int
    {
        $customerId = TenantContext::customerId();

        if ($customerId === null) {
            throw new AuthorizationException('Learning runtime requires a tenant context.');
        }

        return $customerId;
    }

    public function denyExternalRead(string $principal, string $resource): never
    {
        throw new AuthorizationException(
            "LF_LEARNING_EXTERNAL_READ_DENIED:{$principal}:{$resource}",
        );
    }

    public function denyExternalWrite(string $principal, string $resource): never
    {
        throw new AuthorizationException(
            "LF_LEARNING_EXTERNAL_WRITE_DENIED:{$principal}:{$resource}",
        );
    }
}

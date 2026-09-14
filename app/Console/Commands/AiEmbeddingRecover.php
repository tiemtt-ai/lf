<?php

namespace App\Console\Commands;

use App\Exceptions\AiEmbeddingException;
use App\Services\Ai\ControlledEmbeddingRecovery;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class AiEmbeddingRecover extends Command
{
    protected $signature = 'ai:embedding-recover
        {--customer= : Tenant ID}
        {--actor= : Active customer_admin ID for the operations audit}
        {--run-uuid= : Exact interrupted embedding run UUID}
        {--evidence= : Non-sensitive incident/ticket reference, letters/digits/dot/dash/underscore only}
        {--confirm-writer-stopped : Attest all workers for this run have stopped and cannot resume}
        {--confirm-store-quiesced : Attest all outstanding writes for this run have drained}';

    protected $description = 'Close a confirmed stopped embedding attempt and request vector cleanup; never refund quota or call providers.';

    public function handle(ControlledEmbeddingRecovery $recovery): int
    {
        $customerId = filter_var($this->option('customer'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $actorId = filter_var($this->option('actor'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($customerId === false || $actorId === false) {
            $this->error('Customer and actor must be positive integer IDs.');

            return self::FAILURE;
        }
        $customer = DB::table('saas_customers')->where('id', $customerId)->where('status', 'active')->first();
        if ($customer === null) {
            $this->error('Active customer not found.');

            return self::FAILURE;
        }
        $previous = TenantContext::customer();
        TenantContext::set($customer);
        try {
            $result = $recovery->recover($actorId, (string) $this->option('run-uuid'), (string) $this->option('evidence'),
                (bool) $this->option('confirm-writer-stopped'), (bool) $this->option('confirm-store-quiesced'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (AiEmbeddingException $exception) {
            $this->error($exception->errorCode);

            return self::FAILURE;
        } finally {
            TenantContext::set($previous);
        }
    }
}

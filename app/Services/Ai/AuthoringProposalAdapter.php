<?php

namespace App\Services\Ai;

use App\Contracts\Ai\AiProviderAdapter;
use App\Contracts\Ai\AuthoringProposalProvider;
use App\Exceptions\AiAuthoringProposalException;
use App\Support\Ai\AllowedExecution;
use App\Support\Ai\AuthoringProposalInput;
use JsonException;
use RuntimeException;

/**
 * Bridges one Step 7 generation call into the execution gate.
 *
 * The answer is validated HERE, inside execute(): an unusable answer makes the
 * gate record the run failed (AI_PROVIDER_CALL_FAILED), so a `completed` run
 * always has a validated, bounded item list behind it. One invalid item fails
 * the whole answer; nothing is partially accepted.
 */
final class AuthoringProposalAdapter implements AiProviderAdapter
{
    public const MAX_ITEMS = 100;

    /** @var array<int,array<string,mixed>>|null */
    private ?array $items = null;

    /**
     * @param  array<int,int>|null  $candidates  node_id => definition_id
     */
    public function __construct(
        private readonly AuthoringProposalProvider $provider,
        private readonly AuthoringProposalInput $input,
        private readonly AuthoringPayloadValidator $validator,
        private readonly ?array $candidates,
    ) {}

    public function provider(): string
    {
        return $this->provider->provider();
    }

    public function supportsModel(string $model): bool
    {
        return $this->provider->supportsModel($model);
    }

    /** @return array<int,array<string,mixed>>|null validated items; refs use generation source ordinals */
    public function items(): ?array
    {
        return $this->items;
    }

    /** @return array<string,mixed> */
    public function execute(AllowedExecution $execution): array
    {
        $raw = $this->provider->propose($execution->request->model, $this->input);

        try {
            $decoded = json_decode($raw, true, AuthoringPayloadValidator::MAX_DEPTH + 3, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('LF_AUTHORING_OUTPUT_INVALID_JSON');
        }
        if (! is_array($decoded) || array_keys($decoded) !== ['items'] || ! is_array($decoded['items']) || ! array_is_list($decoded['items'])) {
            throw new RuntimeException('LF_AUTHORING_OUTPUT_INVALID_SHAPE');
        }
        if (count($decoded['items']) > self::MAX_ITEMS) {
            throw new RuntimeException('LF_AUTHORING_OUTPUT_TOO_MANY_ITEMS');
        }

        $items = [];
        foreach ($decoded['items'] as $item) {
            $kind = is_array($item) ? ($item['kind'] ?? null) : null;
            if (! is_string($kind) || ! in_array($kind, $this->input->requestedKinds, true)) {
                throw new RuntimeException('LF_AUTHORING_OUTPUT_UNREQUESTED_KIND');
            }
            try {
                $items[] = $this->validator->validate($item, $kind, count($this->input->sources), $this->candidates);
            } catch (AiAuthoringProposalException) {
                // The field name is not surfaced: it could echo model output.
                throw new RuntimeException('LF_AUTHORING_OUTPUT_INVALID_ITEM');
            }
        }

        $this->items = $items;

        // Non-sensitive measurements only: never item text or source text.
        return ['quota_quantity' => 1.0, 'item_count' => count($items)];
    }
}

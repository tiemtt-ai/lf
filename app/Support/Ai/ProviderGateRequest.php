<?php

namespace App\Support\Ai;

/**
 * The complete, non-sensitive description of one intended provider execution.
 *
 * Everything the gate needs to decide must be here, and nothing here may be
 * sensitive: this object is hashed into `ai_model_runs.prompt_hash` and its
 * shape is echoed into run metadata. Payload text, prompt bodies, PII and
 * signed URLs never enter it — the gate reasons about data *classes*, not
 * data.
 */
final readonly class ProviderGateRequest
{
    /**
     * @param  array<int,string>  $dataClasses
     */
    public function __construct(
        public string $provider,
        public string $model,
        public string $purpose,
        public array $dataClasses,
        public string $executionRegion,
        public string $retentionClass,
        public string $correlationId,
        public ?int $userId = null,
        public ?int $promptTemplateId = null,
        public ?int $promptScopeCustomerId = null,
        public ?int $promptVersion = null,
        public ?string $promptHash = null,
        public ?string $runUuid = null,
        public float $quotaQuantity = 1.0,
        public string $quotaUnit = 'call',
    ) {}

    /** @return array<int,string> */
    public function normalizedDataClasses(): array
    {
        $classes = array_values(array_unique(array_map('strval', $this->dataClasses)));
        sort($classes, SORT_STRING);

        return $classes;
    }

    /**
     * Canonical identity of the attempt. Deterministic so a retry of the same
     * intent resolves to the same run instead of multiplying audit rows.
     */
    public function identity(): string
    {
        return implode('|', [
            $this->provider,
            $this->model,
            $this->purpose,
            implode(',', $this->normalizedDataClasses()),
            $this->executionRegion,
            $this->retentionClass,
            $this->correlationId,
            (string) $this->promptTemplateId,
            (string) $this->promptVersion,
        ]);
    }

    /**
     * Effective prompt fingerprint for `ai_model_runs.prompt_hash`, which is
     * NOT NULL. A template hash wins when one exists; an attempt blocked
     * before a prompt was ever built still needs a stable, non-sensitive
     * fingerprint, so the canonical request envelope is used and the basis is
     * recorded in run metadata rather than left ambiguous.
     */
    public function effectivePromptHash(): string
    {
        return $this->promptHash ?? 'sha256:'.hash('sha256', $this->identity());
    }

    public function promptHashBasis(): string
    {
        return $this->promptHash === null ? 'request_envelope' : 'prompt_template';
    }

    /** @return array<string,mixed> Safe provenance for run metadata. */
    public function safeProvenance(): array
    {
        return [
            'data_classes' => $this->normalizedDataClasses(),
            'execution_region' => $this->executionRegion,
            'retention_class' => $this->retentionClass,
            'prompt_hash_basis' => $this->promptHashBasis(),
            'quota' => ['quantity' => $this->quotaQuantity, 'unit' => $this->quotaUnit],
        ];
    }
}

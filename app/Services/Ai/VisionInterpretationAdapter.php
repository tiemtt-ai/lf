<?php

namespace App\Services\Ai;

use App\Contracts\Ai\AiProviderAdapter;
use App\Contracts\Ai\VisionInterpretationProvider;
use App\Support\Ai\AllowedExecution;
use App\Support\Ai\VisionImageInput;
use RuntimeException;

/**
 * Bridges one vision provider call into the execution gate.
 *
 * Output is validated HERE, inside execute(), and not by the caller afterwards.
 * An adapter that throws makes the gate record the run `failed` with the
 * approved AI_PROVIDER_CALL_FAILED code, so an unusable answer can never leave
 * a `completed` run that looks like it produced an interpretation. That is what
 * lets `ai_vision_interpretations` have no `failed` status (database doc v1.1 F5).
 */
final class VisionInterpretationAdapter implements AiProviderAdapter
{
    private ?string $interpretation = null;

    public function __construct(
        private readonly VisionInterpretationProvider $provider,
        private readonly VisionImageInput $image,
        private readonly int $maxChars,
    ) {}

    public function provider(): string
    {
        return $this->provider->provider();
    }

    public function supportsModel(string $model): bool
    {
        return $this->provider->supportsModel($model);
    }

    /** The validated interpretation; null until execute() succeeded. */
    public function interpretation(): ?string
    {
        return $this->interpretation;
    }

    /** @return array<string,mixed> */
    public function execute(AllowedExecution $execution): array
    {
        $text = $this->provider->interpret($execution->request->model, $this->image);

        $trimmed = trim($text);
        if ($trimmed === '') {
            throw new RuntimeException('LF_VISION_OUTPUT_EMPTY');
        }
        if (! mb_check_encoding($trimmed, 'UTF-8') || str_contains($trimmed, "\0")) {
            throw new RuntimeException('LF_VISION_OUTPUT_INVALID_ENCODING');
        }
        if (mb_strlen($trimmed) > $this->maxChars) {
            throw new RuntimeException('LF_VISION_OUTPUT_TOO_LONG');
        }
        // A provider that echoes the signed crop URL back would get it persisted
        // with the interpretation. The URL is sensitive and short-lived; refuse
        // the answer rather than strip it and pretend the rest is trustworthy.
        if (str_contains($trimmed, $this->image->deliveryUrl)) {
            throw new RuntimeException('LF_VISION_OUTPUT_CONTAINS_DELIVERY_URL');
        }

        $this->interpretation = $trimmed;

        // Only non-sensitive measurements: never the text, never the URL.
        return [
            'quota_quantity' => 1.0,
            'output_chars' => mb_strlen($trimmed),
        ];
    }
}

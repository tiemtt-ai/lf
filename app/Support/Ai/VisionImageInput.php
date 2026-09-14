<?php

namespace App\Support\Ai;

use SensitiveParameter;

/**
 * The image a vision provider may look at: one region crop, as Media Read
 * delivered it.
 *
 * `deliveryUrl` is a short-lived signed URL and is sensitive. It exists only
 * for the duration of one provider call. It must never be persisted — not in
 * `ai_vision_interpretations`, its metadata, `ai_model_runs` or any log — so it
 * is marked #[SensitiveParameter] (redacted from stack traces) and hidden from
 * var_dump/print_r via __debugInfo().
 */
final readonly class VisionImageInput
{
    public function __construct(
        #[SensitiveParameter]
        public string $deliveryUrl,
        public int $width,
        public int $height,
        public int $bytes,
        public ?string $role,
        public ?string $locale,
    ) {}

    /** @return array<string,mixed> */
    public function __debugInfo(): array
    {
        return [
            'deliveryUrl' => '[redacted]',
            'width' => $this->width,
            'height' => $this->height,
            'bytes' => $this->bytes,
            'role' => $this->role,
            'locale' => $this->locale,
        ];
    }
}

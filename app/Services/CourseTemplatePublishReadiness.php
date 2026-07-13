<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class CourseTemplatePublishReadiness
{
    public function __construct(
        private readonly Collection $blockers,
        private readonly Collection $warnings = new Collection,
    ) {}

    public function isReady(): bool
    {
        return $this->blockers->isEmpty();
    }

    public function blockers(): Collection
    {
        return $this->blockers;
    }

    public function warnings(): Collection
    {
        return $this->warnings;
    }

    public function assertReady(): void
    {
        if ($this->isReady()) {
            return;
        }

        throw ValidationException::withMessages([
            'publish' => $this->blockers->first()->message(),
        ]);
    }
}

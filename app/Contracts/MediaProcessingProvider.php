<?php

namespace App\Contracts;

interface MediaProcessingProvider
{
    /** @return array<string, mixed> */
    public function process(object $mediaFile, object $job): array;
}

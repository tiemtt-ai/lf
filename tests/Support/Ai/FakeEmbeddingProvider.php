<?php

namespace Tests\Support\Ai;

use App\Contracts\Ai\EmbeddingProvider;
use RuntimeException;

/**
 * Stands in for a vendor. It never leaves the process, so a test that
 * accidentally reaches "the provider" is still provably offline.
 *
 * It can misbehave in the two ways that matter: returning the wrong number of
 * vectors, and returning vectors of the wrong width. Both are silent data
 * corruption if unguarded, so both need a test.
 */
final class FakeEmbeddingProvider implements EmbeddingProvider
{
    /** @var array<int,array{model:string,count:int}> */
    public array $calls = [];

    public function __construct(
        private readonly string $name = 'approved-provider',
        private readonly int $dimensions = 3,
        private readonly ?int $returnCount = null,
        private readonly ?int $returnDimensions = null,
        private readonly ?RuntimeException $failure = null,
    ) {}

    public function provider(): string
    {
        return $this->name;
    }

    public function supportsModel(string $model): bool
    {
        return $model === 'approved-model';
    }

    public function embed(string $model, array $texts): array
    {
        $this->calls[] = ['model' => $model, 'count' => count($texts)];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        $width = $this->returnDimensions ?? $this->dimensions;
        $count = $this->returnCount ?? count($texts);
        $vectors = [];

        for ($i = 0; $i < $count; $i++) {
            // Deterministic and text-derived, so a test can assert that a
            // particular chunk's vector reached a particular point.
            $seed = crc32($texts[$i] ?? 'missing');
            $vectors[] = array_map(
                static fn (int $d): float => (float) (($seed + $d) % 100) / 100,
                range(1, $width),
            );
        }

        return $vectors;
    }
}

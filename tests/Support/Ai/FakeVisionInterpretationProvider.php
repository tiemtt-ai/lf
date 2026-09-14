<?php

namespace Tests\Support\Ai;

use App\Contracts\Ai\VisionInterpretationProvider;
use App\Support\Ai\VisionImageInput;
use Closure;

/**
 * Stands in for a vision vendor without leaving the process.
 *
 * `respond` decides what the "model" answers, which is how tests reach the
 * adapter's output validation. `during` runs inside the call — after the gate
 * claimed the run and before the service writes — which is how a concurrent
 * writer landing in that window is simulated without threads.
 */
final class FakeVisionInterpretationProvider implements VisionInterpretationProvider
{
    /** @var array<int,array{model:string,width:int,height:int,has_url:bool,role:?string}> */
    public array $calls = [];

    private ?Closure $during = null;

    public function __construct(
        private readonly string $name = 'approved-provider',
        private readonly string $model = 'vision-model',
        private ?Closure $respond = null,
    ) {}

    public function provider(): string
    {
        return $this->name;
    }

    public function supportsModel(string $model): bool
    {
        return $model === $this->model;
    }

    public function interpret(string $model, VisionImageInput $image): string
    {
        $this->calls[] = [
            'model' => $model,
            'width' => $image->width,
            'height' => $image->height,
            'has_url' => $image->deliveryUrl !== '',
            'role' => $image->role,
        ];

        if ($this->during !== null) {
            $during = $this->during;
            $this->during = null;
            $during();
        }

        return $this->respond !== null
            ? ($this->respond)($image)
            : 'Biểu đồ cột cho thấy số học viên tăng dần qua ba quý.';
    }

    public function during(Closure $callback): void
    {
        $this->during = $callback;
    }

    public function respondWith(Closure $respond): void
    {
        $this->respond = $respond;
    }
}

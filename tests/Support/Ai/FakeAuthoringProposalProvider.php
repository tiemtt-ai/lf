<?php

namespace Tests\Support\Ai;

use App\Contracts\Ai\AuthoringProposalProvider;
use App\Support\Ai\AuthoringProposalInput;
use Closure;

/**
 * Stands in for an authoring model without leaving the process. `respond`
 * decides the raw answer; the default proposes one summary citing source 1
 * and, when a basis is offered, one reuse_existing mapping to its first Node.
 */
final class FakeAuthoringProposalProvider implements AuthoringProposalProvider
{
    /** @var array<int,array{model:string,kinds:array<int,string>,source_count:int,has_basis:bool}> */
    public array $calls = [];

    public function __construct(
        private readonly string $name = 'approved-provider',
        private readonly string $model = 'authoring-model',
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

    public function propose(string $model, AuthoringProposalInput $input): string
    {
        $this->calls[] = [
            'model' => $model,
            'kinds' => $input->requestedKinds,
            'source_count' => count($input->sources),
            'has_basis' => $input->frameworkBasis !== null,
        ];

        if ($this->respond !== null) {
            return ($this->respond)($input);
        }

        $items = [];
        if (in_array('summary', $input->requestedKinds, true)) {
            $items[] = [
                'kind' => 'summary', 'title' => 'Tóm tắt bài học', 'body' => 'Bài học giới thiệu nội dung chính.',
                'confidence' => 0.8, 'rationale' => 'Dựa trên đoạn nguồn đầu tiên.', 'source_refs' => [1],
            ];
        }
        if (in_array('node_mapping', $input->requestedKinds, true) && $input->frameworkBasis !== null) {
            $candidate = $input->frameworkBasis['candidates'][0];
            $items[] = [
                'kind' => 'node_mapping', 'title' => 'Liên kết năng lực', 'body' => 'Hoạt động luyện tập năng lực này.',
                'confidence' => 0.6, 'rationale' => 'Nội dung khớp tiêu chí.', 'source_refs' => [1],
                'mapping' => [
                    'mode' => 'reuse_existing', 'node_id' => $candidate['node_id'], 'definition_id' => $candidate['definition_id'],
                    'role' => 'practices', 'weight' => null,
                ],
            ];
        }

        return json_encode(['items' => $items], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public function respondWith(Closure $respond): void
    {
        $this->respond = $respond;
    }
}

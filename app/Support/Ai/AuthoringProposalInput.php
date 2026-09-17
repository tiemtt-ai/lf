<?php

namespace App\Support\Ai;

use SensitiveParameter;

/**
 * Everything one generation call may see. Source text is tenant content, so it
 * is marked sensitive and redacted from debug output; it is never persisted by
 * the adapter, the request ledger or run metadata.
 */
final readonly class AuthoringProposalInput
{
    /**
     * @param  array<int,string>  $requestedKinds
     * @param  array<string,mixed>  $courseContext
     * @param  array<string,mixed>|null  $frameworkBasis
     * @param  array<int,array{ordinal:int,usage_type:string,content_type:string,locale:?string,text:string}>  $sources
     */
    public function __construct(
        public string $promptContractId,
        public int $promptVersion,
        public string $promptTemplate,
        public array $requestedKinds,
        public array $courseContext,
        public ?array $frameworkBasis,
        #[SensitiveParameter] public array $sources,
    ) {}

    /** @return array<string,mixed> */
    public function __debugInfo(): array
    {
        return [
            'promptContractId' => $this->promptContractId,
            'promptVersion' => $this->promptVersion,
            'requestedKinds' => $this->requestedKinds,
            'sources' => count($this->sources).' source unit(s) redacted',
        ];
    }
}

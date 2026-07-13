<?php

namespace App\Services;

final readonly class CourseTemplatePublishIssue
{
    public function __construct(
        public string $code,
        public string $messageKey,
        public string $targetTab,
        public array $context = [],
        public ?string $fragment = null,
    ) {}

    public function message(): string
    {
        return __($this->messageKey, $this->context);
    }

    public function targetUrl(string $routePrefix, int $templateId): string
    {
        return route($routePrefix.'.edit', [
            'id' => $templateId,
            'tab' => $this->targetTab,
        ]).($this->fragment ? '#'.$this->fragment : '');
    }
}

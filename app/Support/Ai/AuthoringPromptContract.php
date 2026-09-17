<?php

namespace App\Support\Ai;

/**
 * Code-owned prompt contract for Step 7 generation (contract § P1-5).
 *
 * The hash covers the template body, the variables schema and the output
 * schema, and nothing request-specific: no correlation ID, no run UUID, no
 * rendered source text. The same contract therefore hashes identically across
 * requests, which is what lets a pending generated proposal detect that the
 * deployed prompt moved on.
 *
 * Not final: a test binds a subclass to simulate a deployed prompt update.
 */
class AuthoringPromptContract
{
    public const ID = 'learnforge.authoring.proposal';

    public function id(): string
    {
        return self::ID;
    }

    public function version(): int
    {
        return 1;
    }

    public function template(): string
    {
        return <<<'PROMPT'
You propose learning-authoring suggestions for ONE course activity.
Use only the numbered source units provided. Every item cites the source unit
numbers it relies on. Propose only the requested kinds. For node_mapping, prefer
reusing a listed Framework candidate (reuse_existing) over proposing a new node
(propose_new). Confidence is your certainty in [0,1]; it is never a pedagogical
weight. Return JSON {"items":[...]} exactly matching the output schema.
PROMPT;
    }

    /** @return array<string,mixed> */
    public function variablesSchema(): array
    {
        return [
            'requested_kinds' => 'list<summary|concept|learning_objective|competency|node_mapping>',
            'course_context' => 'course-authoring-v1 DTO',
            'framework_basis' => 'learning-authoring-v1 candidates or null',
            'sources' => 'list<{ordinal, usage_type, content_type, locale, text}>',
        ];
    }

    /** @return array<string,mixed> */
    public function outputSchema(): array
    {
        return [
            'items' => [[
                'kind' => 'summary|concept|learning_objective|competency|node_mapping',
                'title' => 'string',
                'body' => 'string',
                'confidence' => 'number [0,1]',
                'rationale' => 'string',
                'source_refs' => 'list<int source ordinal>',
                'mapping' => 'node_mapping only: {mode, node_id, definition_id} | {mode, code, label, node_type, criteria}; role; weight',
            ]],
        ];
    }

    /** Lowercase hex; the gate receives it with the `sha256:` prefix. */
    public function hash(): string
    {
        return CanonicalJson::hash([
            'id' => $this->id(),
            'version' => $this->version(),
            'template' => $this->template(),
            'variables' => $this->variablesSchema(),
            'output' => $this->outputSchema(),
        ]);
    }
}

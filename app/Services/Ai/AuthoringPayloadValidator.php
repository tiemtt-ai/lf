<?php

namespace App\Services\Ai;

use App\Exceptions\AiAuthoringProposalException;
use App\Support\Ai\CanonicalJson;

/**
 * Candidate payload schema version 1 (contract § Payload and provenance).
 *
 * Closed shape: unknown keys and inconsistent branches are rejected, never
 * dropped. The same validator serves generated output and human edits, so a
 * human cannot store anything a model could not. Bounds: 64 KiB canonical
 * UTF-8, JSON depth 8.
 *
 * Confidence is the model's certainty and stays separate from mapping weight;
 * neither is derived from the other here or anywhere else.
 */
final class AuthoringPayloadValidator
{
    public const SCHEMA_VERSION = 1;

    public const MAX_BYTES = 65536;

    public const MAX_DEPTH = 8;

    public const KINDS = ['summary', 'concept', 'learning_objective', 'competency', 'node_mapping'];

    private const ROLES = ['teaches', 'practices', 'assesses'];

    private const NODE_TYPES = ['objective', 'concept', 'competency'];

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<int,int>|null  $candidates  node_id => definition_id of the current basis
     * @param  bool  $humanSuccessor  a human-successor draft: confidence may be NULL (no model
     *                                confidence is invented) and source_refs may still be empty;
     *                                acceptance separately requires fresh references
     * @return array<string,mixed> normalized payload
     */
    public function validate(mixed $payload, string $kind, int $sourceCount, ?array $candidates, bool $humanSuccessor = false): array
    {
        if (! is_array($payload) || array_is_list($payload)) {
            $this->fail('payload');
        }
        if (! in_array($kind, self::KINDS, true) || ($payload['kind'] ?? null) !== $kind) {
            $this->fail('kind');
        }
        $allowed = ['kind', 'title', 'body', 'confidence', 'rationale', 'source_refs'];
        if ($kind === 'node_mapping') {
            $allowed[] = 'mapping';
        }
        if (array_diff(array_keys($payload), $allowed) !== [] || array_diff($allowed, array_keys($payload)) !== []) {
            $this->fail('keys');
        }

        $normalized = [
            'kind' => $kind,
            'title' => $this->text($payload['title'], 'title', 1, 255),
            'body' => $this->text($payload['body'], 'body', 0, 20000),
            'confidence' => $this->unit($payload['confidence'], 'confidence', $humanSuccessor),
            'rationale' => $this->text($payload['rationale'], 'rationale', 0, 4000),
            'source_refs' => $this->sourceRefs($payload['source_refs'], $sourceCount, $humanSuccessor),
        ];
        if ($kind === 'node_mapping') {
            $normalized['mapping'] = $this->mapping($payload['mapping'], $candidates);
        }

        if ($this->depth($normalized) > self::MAX_DEPTH) {
            $this->fail('depth');
        }
        if (strlen(CanonicalJson::encode($normalized)) > self::MAX_BYTES) {
            $this->fail('size');
        }

        return $normalized;
    }

    /** @return array<string,mixed> */
    private function mapping(mixed $mapping, ?array $candidates): array
    {
        if (! is_array($mapping) || array_is_list($mapping)) {
            $this->fail('mapping');
        }
        $mode = $mapping['mode'] ?? null;
        $keys = match ($mode) {
            'reuse_existing' => ['mode', 'node_id', 'definition_id', 'role', 'weight'],
            'propose_new' => ['mode', 'code', 'label', 'node_type', 'criteria', 'role', 'weight'],
            default => $this->fail('mapping.mode'),
        };
        if (array_diff(array_keys($mapping), $keys) !== [] || array_diff($keys, array_keys($mapping)) !== []) {
            $this->fail('mapping.keys');
        }
        if (! in_array($mapping['role'], self::ROLES, true)) {
            $this->fail('mapping.role');
        }
        $result = ['mode' => $mode, 'role' => $mapping['role'], 'weight' => $this->unit($mapping['weight'], 'mapping.weight', true)];

        if ($mode === 'reuse_existing') {
            if (! is_int($mapping['node_id']) || ! is_int($mapping['definition_id']) || $mapping['node_id'] < 1 || $mapping['definition_id'] < 1) {
                $this->fail('mapping.node_id');
            }
            // Reuse must name an exact Node of the basis the proposal was made against.
            if ($candidates === null || ($candidates[$mapping['node_id']] ?? null) !== $mapping['definition_id']) {
                $this->fail('mapping.candidate');
            }

            return $result + ['node_id' => $mapping['node_id'], 'definition_id' => $mapping['definition_id']];
        }

        $code = $mapping['code'];
        if (! is_string($code) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/', $code) !== 1) {
            $this->fail('mapping.code');
        }
        if (! in_array($mapping['node_type'], self::NODE_TYPES, true)) {
            $this->fail('mapping.node_type');
        }
        if ($mapping['criteria'] !== null && ! is_array($mapping['criteria'])) {
            $this->fail('mapping.criteria');
        }

        return $result + [
            'code' => $code,
            'label' => $this->text($mapping['label'], 'mapping.label', 1, 255),
            'node_type' => $mapping['node_type'],
            'criteria' => $mapping['criteria'],
        ];
    }

    private function text(mixed $value, string $field, int $min, int $max): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            $this->fail($field);
        }
        $length = mb_strlen($min > 0 ? trim($value) : $value);
        if ($length < $min || mb_strlen($value) > $max) {
            $this->fail($field);
        }

        return $value;
    }

    private function unit(mixed $value, string $field, bool $nullable): ?float
    {
        if ($value === null && $nullable) {
            return null;
        }
        if ((! is_int($value) && ! is_float($value)) || $value < 0 || $value > 1 || is_nan((float) $value)) {
            $this->fail($field);
        }

        return (float) $value;
    }

    /** @return array<int,int> sorted distinct ordinals within the proposal's own sources */
    private function sourceRefs(mixed $refs, int $sourceCount, bool $allowEmpty): array
    {
        if (! is_array($refs) || ! array_is_list($refs) || ($refs === [] && ! $allowEmpty)) {
            $this->fail('source_refs');
        }
        foreach ($refs as $ref) {
            if (! is_int($ref) || $ref < 1 || $ref > $sourceCount) {
                $this->fail('source_refs');
            }
        }
        if (count(array_unique($refs)) !== count($refs)) {
            $this->fail('source_refs');
        }
        sort($refs);

        return $refs;
    }

    private function depth(mixed $value): int
    {
        if (! is_array($value)) {
            return 0;
        }
        $max = 0;
        foreach ($value as $child) {
            $max = max($max, $this->depth($child));
        }

        return $max + 1;
    }

    private function fail(string $field): never
    {
        throw new AiAuthoringProposalException('invalid_proposal', $field);
    }
}

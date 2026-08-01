<?php

namespace App\Services;

class BulkEnrollmentPayload
{
    public function canonical(array $studentIds, array $productIds, array $confirmations, array $configuration): array
    {
        $students = collect($studentIds)->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
        $products = collect($productIds)->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
        $normalizedConfirmations = collect($confirmations)->map(fn (array $item): array => [
            'student_id' => (int) $item['student_id'],
            'product_id' => (int) $item['product_id'],
            'previous_enrollment_id' => (int) $item['previous_enrollment_id'],
        ])->sortBy(fn (array $item): string => sprintf('%020d:%020d:%020d', $item['student_id'], $item['product_id'], $item['previous_enrollment_id']))
            ->values()->all();

        $canonicalConfiguration = collect(['notes'])
            ->mapWithKeys(function (string $field) use ($configuration): array {
                $value = $configuration[$field] ?? null;

                if (is_string($value)) {
                    $value = trim($value);
                }

                return [$field => $value === '' ? null : $value];
            })->all();
        if (filled($configuration['enrolled_at'] ?? null)) {
            $canonicalConfiguration['enrolled_at'] = trim((string) $configuration['enrolled_at']);
        }

        return [
            'student_ids' => $students,
            'product_ids' => $products,
            'reenrollment_confirmations' => $normalizedConfirmations,
            'configuration' => $canonicalConfiguration,
        ];
    }

    public function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}

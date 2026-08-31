<?php

namespace App\Services;

use RuntimeException;

/** Sweep rectangles without expanding merged areas; O(cells log cells). */
class DocumentCellOverlap
{
    public function validate(array $cells): void
    {
        $events = [];
        $coordinates = [];
        foreach ($cells as $cell) {
            $left = (int) $cell['column'];
            $right = $left + (int) ($cell['column_span'] ?? 1);
            $top = (int) $cell['row'];
            $bottom = $top + (int) ($cell['row_span'] ?? 1);
            $coordinates[$left] = true;
            $coordinates[$right] = true;
            $events[] = [$top, 1, $left, $right];
            $events[] = [$bottom, -1, $left, $right];
        }
        ksort($coordinates, SORT_NUMERIC);
        $position = 0;
        foreach ($coordinates as &$value) {
            $value = ++$position;
        }
        unset($value);
        usort($events, static fn (array $a, array $b): int => ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]));
        $starts = [];
        $ends = [];
        foreach ($events as [, $delta, $left, $right]) {
            $left = $coordinates[$left];
            $right = $coordinates[$right];
            // Half-open rectangles may touch edges. At a given row, remove
            // ending rectangles before checking those that start there.
            if ($delta === 1 && $this->sum($starts, $right - 1) - $this->sum($ends, $left) > 0) {
                throw new RuntimeException('structured_extraction_invalid');
            }
            $this->add($starts, $left, $delta, $position);
            $this->add($ends, $right, $delta, $position);
        }
    }

    private function sum(array &$tree, int $index): int
    {
        $sum = 0;
        for (; $index > 0; $index -= $index & -$index) {
            $sum += $tree[$index] ?? 0;
        }

        return $sum;
    }

    private function add(array &$tree, int $index, int $delta, int $size): void
    {
        for (; $index <= $size; $index += $index & -$index) {
            $tree[$index] = ($tree[$index] ?? 0) + $delta;
        }
    }
}

<?php

namespace Tests\Unit;

use App\Services\DocumentCellOverlap;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DocumentCellOverlapTest extends TestCase
{
    public function test_huge_merged_area_does_not_expand_and_touching_edges_are_valid(): void
    {
        (new DocumentCellOverlap)->validate([
            ['row' => 1, 'column' => 1, 'row_span' => 1000000, 'column_span' => 1000000],
            ['row' => 1000001, 'column' => 1],
            ['row' => 1, 'column' => 1000001],
        ]);
        $this->addToAssertionCount(1);
    }

    public function test_sweep_matches_exhaustive_rectangle_oracle(): void
    {
        mt_srand(90210);
        for ($sample = 0; $sample < 150; $sample++) {
            $cells = [];
            $overlap = false;
            $occupied = [];
            for ($i = 0; $i < 20; $i++) {
                $cell = ['row' => mt_rand(1, 30), 'column' => mt_rand(1, 30),
                    'row_span' => mt_rand(1, 5), 'column_span' => mt_rand(1, 5)];
                $cells[] = $cell;
                for ($r = $cell['row']; $r < $cell['row'] + $cell['row_span']; $r++) {
                    for ($c = $cell['column']; $c < $cell['column'] + $cell['column_span']; $c++) {
                        $overlap = $overlap || isset($occupied[$r.':'.$c]);
                        $occupied[$r.':'.$c] = true;
                    }
                }
            }
            try {
                (new DocumentCellOverlap)->validate($cells);
                $actual = false;
            } catch (RuntimeException $e) {
                $this->assertSame('structured_extraction_invalid', $e->getMessage());
                $actual = true;
            }
            $this->assertSame($overlap, $actual);
        }
    }
}

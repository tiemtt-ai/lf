<?php

namespace App\Services;

use Closure;
use DOMDocument;
use DOMElement;
use RuntimeException;
use ZipArchive;

/** Bounded native worksheet observations shared by canonical text and cell output. */
class DocumentSpreadsheetReader
{
    private int $characters = 0;

    private int $cellCount = 0;

    private bool $structured = true;

    public function read(string $source, Closure $completed, bool $structured = true): array
    {
        $this->characters = $this->cellCount = 0;
        $this->structured = $structured;
        $zip = new ZipArchive;
        if ($zip->open($source) !== true) {
            throw new RuntimeException('corrupt_source');
        }
        try {
            $relationships = $this->xml($zip, 'xl/_rels/workbook.xml.rels');
            $targets = [];
            foreach ($relationships->getElementsByTagName('Relationship') as $relation) {
                if ($relation->getAttribute('TargetMode') === 'External') {
                    continue;
                }
                $target = $relation->getAttribute('Target');
                if (str_contains($target, '..') || str_contains($target, '\\')) {
                    throw new RuntimeException('corrupt_source');
                }
                $targets[$relation->getAttribute('Id')] = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
            }
            $shared = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                $sharedSize = 0;
                foreach ($this->xml($zip, 'xl/sharedStrings.xml')->getElementsByTagName('si') as $item) {
                    $value = $this->textRuns($item);
                    $sharedSize += mb_strlen($value);
                    $this->limit($sharedSize);
                    $shared[] = $value;
                }
            }
            $sheets = $this->xml($zip, 'xl/workbook.xml')->getElementsByTagName('sheet');
            if ($sheets->length > (int) config('media.processing.'.($this->structured ? 'structured_extraction' : 'local_document').'.max_pages', 100)) {
                throw new RuntimeException('page_limit_exceeded');
            }
            $units = $tables = [];
            foreach ($sheets as $index => $sheet) {
                $relationship = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
                if (! isset($targets[$relationship])) {
                    throw new RuntimeException('corrupt_source');
                }
                [$text, $cells, $rows, $columns] = $this->sheet($this->xml($zip, $targets[$relationship]), $shared);
                $units[] = ['locator_type' => 'sheet', 'locator_value' => (string) ($index + 1),
                    'sequence' => $index + 1, 'text' => $text, 'extraction_method' => 'spreadsheet_cells',
                    'metadata' => ['sheet_name' => $sheet->getAttribute('name')]];
                if ($this->structured && $cells !== []) {
                    $tables[] = ['locator_type' => 'sheet', 'locator_value' => (string) ($index + 1),
                        'sequence' => count($tables) + 1, 'row_count' => $rows, 'column_count' => $columns,
                        'title' => $sheet->getAttribute('name'), 'has_header' => false,
                        'quality_status' => 'undetermined',
                        'extraction_method' => 'spreadsheet_cells', 'cells' => array_values($cells)];
                }
                $completed($index + 1);
            }

            return ['units' => $units, 'regions' => [], 'tables' => $tables];
        } finally {
            $zip->close();
        }
    }

    private function xml(ZipArchive $zip, string $part): DOMDocument
    {
        $stat = $zip->statName($part);
        if ($stat === false) {
            throw new RuntimeException('corrupt_source');
        }
        $limit = (int) config('media.processing.local_document.max_docx_xml_bytes', 8388608);
        if ($stat['size'] > $limit) {
            throw new RuntimeException('source_expansion_limit_exceeded');
        }
        $xml = $zip->getFromName($part, $limit + 1);
        if ($xml === false || strlen($xml) > $limit || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new RuntimeException('corrupt_source');
        }
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT) || $document->doctype !== null) {
                throw new RuntimeException('corrupt_source');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }

    private function textRuns(DOMElement $element): string
    {
        $text = '';
        foreach ($element->getElementsByTagName('t') as $run) {
            $text .= $run->textContent;
        }

        return $text;
    }

    private function coordinate(string $reference): array
    {
        if (! preg_match('/^([A-Z]{1,3})([1-9][0-9]{0,6})$/D', $reference, $match)) {
            throw new RuntimeException('corrupt_source');
        }
        $column = 0;
        foreach (str_split($match[1]) as $letter) {
            $column = $column * 26 + ord($letter) - 64;
        }
        $row = (int) $match[2];
        if ($column > 16384 || $row > 1048576) {
            throw new RuntimeException('corrupt_source');
        }

        return [$row, $column];
    }

    private function sheet(DOMDocument $xml, array $shared): array
    {
        $cells = [];
        $rows = $columns = $valueCharacters = 0;
        foreach ($xml->getElementsByTagName('c') as $node) {
            [$row, $column] = $this->coordinate($node->getAttribute('r'));
            $key = $row.':'.$column;
            if (isset($cells[$key])) {
                throw new RuntimeException('corrupt_source');
            }
            $raw = $node->getElementsByTagName('v')->item(0)?->textContent ?? '';
            $type = $node->getAttribute('t');
            if ($type === 's' && (! ctype_digit($raw) || ! array_key_exists((int) $raw, $shared))) {
                throw new RuntimeException('corrupt_source');
            }
            $value = match ($type) {
                's' => $shared[(int) $raw],
                'inlineStr' => $this->textRuns($node),
                'b' => match ($raw) {
                    '1' => 'TRUE', '0' => 'FALSE', default => throw new RuntimeException('corrupt_source')
                },
                default => $raw,
            };
            if ($value === '') {
                continue; // Empty styled OOXML cells do not occupy merged ranges.
            }
            $valueCharacters += mb_strlen($value);
            $this->limit($this->characters + $valueCharacters);
            $cells[$key] = ['row' => $row, 'column' => $column, 'text' => $value, 'row_span' => 1, 'column_span' => 1];
            $rows = max($rows, $row);
            $columns = max($columns, $column);
            if ($this->structured && ++$this->cellCount > (int) config('media.processing.structured_extraction.max_table_cells_per_document', 200000)) {
                throw new RuntimeException('structured_extraction_too_large');
            }
        }
        foreach ($xml->getElementsByTagName('mergeCell') as $merge) {
            $range = explode(':', $merge->getAttribute('ref'));
            if (count($range) !== 2) {
                throw new RuntimeException('corrupt_source');
            }
            [$r1, $c1] = $this->coordinate($range[0]);
            [$r2, $c2] = $this->coordinate($range[1]);
            if ($r2 < $r1 || $c2 < $c1) {
                throw new RuntimeException('corrupt_source');
            }
            $key = $r1.':'.$c1;
            if ($this->structured && ! isset($cells[$key]) && ++$this->cellCount > (int) config('media.processing.structured_extraction.max_table_cells_per_document', 200000)) {
                throw new RuntimeException('structured_extraction_too_large');
            }
            $cells[$key] ??= ['row' => $r1, 'column' => $c1, 'text' => ''];
            if (($cells[$key]['row_span'] ?? 1) > 1 || ($cells[$key]['column_span'] ?? 1) > 1) {
                throw new RuntimeException('corrupt_source');
            }
            $cells[$key]['row_span'] = $r2 - $r1 + 1;
            $cells[$key]['column_span'] = $c2 - $c1 + 1;
            $rows = max($rows, $r2);
            $columns = max($columns, $c2);
        }
        app(DocumentCellOverlap::class)->validate(array_values($cells));
        $lines = [];
        foreach ($cells as $cell) {
            if ($cell['text'] !== '') {
                $lines[$cell['row']][$cell['column']] = $cell['text'];
            }
        }
        ksort($lines, SORT_NUMERIC);
        $textLines = [];
        foreach ($lines as $values) {
            ksort($values, SORT_NUMERIC);
            // Charge tab padding BEFORE allocating it; never expand merged area.
            $size = max(array_keys($values)) - 1 + array_sum(array_map(mb_strlen(...), $values));
            $this->characters += $size + ($textLines !== [] ? 1 : 0);
            $this->limit($this->characters);
            $line = '';
            $previous = 1;
            foreach ($values as $column => $value) {
                $line .= str_repeat("\t", $column - $previous).$value;
                $previous = $column;
            }
            $textLines[] = $line;
        }

        return [implode("\n", $textLines), $cells, $rows, $columns];
    }

    private function limit(int $characters): void
    {
        if ($characters > (int) config('media.processing.'.($this->structured ? 'structured_extraction' : 'local_document').'.max_extracted_characters', 500000)) {
            throw new RuntimeException($this->structured ? 'structured_extraction_too_large' : 'extracted_text_too_large');
        }
    }
}

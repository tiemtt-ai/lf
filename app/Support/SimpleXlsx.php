<?php

namespace App\Support;

use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Minimal .xlsx reader / writer for plain-text tables (question import): the first worksheet only, cell values as
 * strings, no formulas, styles or dates. Built on ZipArchive + XMLReader so no spreadsheet package is needed.
 */
final class SimpleXlsx
{
    private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /**
     * Rows of the first worksheet, each a list of strings indexed from column A (gaps filled with '').
     *
     * @return list<list<string>>
     */
    public static function read(string $path, int $maxRows = 2000): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('not an xlsx file');
        }

        try {
            $sheetPath = self::firstSheetPath($zip);
            $shared = self::sharedStrings($zip);
            $xml = $zip->getFromName($sheetPath);
            if ($xml === false) {
                throw new RuntimeException('worksheet missing');
            }

            return self::rows($xml, $shared, $maxRows);
        } finally {
            $zip->close();
        }
    }

    /**
     * A one-sheet workbook; the first row is bold (header).
     *
     * @param  list<list<string>>  $rows
     */
    public static function write(array $rows, string $sheetName = 'Sheet1'): string
    {
        $esc = fn (string $text) => htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $sheetRows = '';
        foreach (array_values($rows) as $r => $row) {
            $cells = '';
            foreach (array_values($row) as $c => $value) {
                $ref = self::columnName($c).($r + 1);
                $style = $r === 0 ? ' s="1"' : '';
                $cells .= '<c r="'.$ref.'" t="inlineStr"'.$style.'><is><t xml:space="preserve">'.$esc((string) $value).'</t></is></c>';
            }
            $sheetRows .= '<row r="'.($r + 1).'">'.$cells.'</row>';
        }

        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                .'</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<workbook xmlns="'.self::MAIN_NS.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets><sheet name="'.$esc($sheetName).'" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                .'</Relationships>',
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<styleSheet xmlns="'.self::MAIN_NS.'">'
                .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
                .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
                .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
                .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                .'<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
                .'</styleSheet>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<worksheet xmlns="'.self::MAIN_NS.'"><sheetData>'.$sheetRows.'</sheetData></worksheet>',
        ];

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $binary = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $binary;
    }

    public static function columnName(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26).$name;
        }

        return $name;
    }

    private static function columnIndex(string $ref): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref));
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }

    private static function firstSheetPath(ZipArchive $zip): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook === false || $rels === false) {
            throw new RuntimeException('workbook missing');
        }
        if (! preg_match('/<(?:\w+:)?sheet\b[^>]*\br:id="([^"]+)"/', $workbook, $sheet)) {
            throw new RuntimeException('no worksheet');
        }
        if (! preg_match('/<Relationship\b[^>]*\bId="'.preg_quote($sheet[1], '/').'"[^>]*\bTarget="([^"]+)"/', $rels, $target)
            && ! preg_match('/<Relationship\b[^>]*\bTarget="([^"]+)"[^>]*\bId="'.preg_quote($sheet[1], '/').'"/', $rels, $target)) {
            throw new RuntimeException('worksheet relation missing');
        }
        $path = $target[1];

        return str_starts_with($path, '/') ? ltrim($path, '/') : 'xl/'.$path;
    }

    /** @return list<string> */
    private static function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $reader = new XMLReader;
        $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
        $strings = [];
        $current = null;
        $phonetic = false; // text inside <rPh> is a reading hint, not part of the value
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                $current = '';
                if ($reader->isEmptyElement) {
                    $strings[] = '';
                    $current = null;
                }
            } elseif ($reader->localName === 'rPh' && ! $reader->isEmptyElement) {
                $phonetic = $reader->nodeType === XMLReader::ELEMENT;
            } elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 't' && $current !== null && ! $phonetic) {
                $current .= $reader->readString();
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'si') {
                $strings[] = (string) $current;
                $current = null;
            }
        }
        $reader->close();

        return $strings;
    }

    /**
     * @param  list<string>  $shared
     * @return list<list<string>>
     */
    private static function rows(string $xml, array $shared, int $maxRows): array
    {
        $reader = new XMLReader;
        $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
        $rows = [];
        $row = null;
        $rowIndex = -1;
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'row') {
                $rowIndex = $reader->getAttribute('r') !== null ? (int) $reader->getAttribute('r') - 1 : $rowIndex + 1;
                $row = [];
                if ($reader->isEmptyElement) {
                    $rows[$rowIndex] = [];
                    $row = null;
                }
            } elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'c' && $row !== null) {
                $ref = (string) $reader->getAttribute('r');
                $col = $ref !== '' ? self::columnIndex($ref) : count($row);
                $type = (string) $reader->getAttribute('t');
                $value = '';
                if (! $reader->isEmptyElement) {
                    $cell = $reader->readOuterXml();
                    $value = self::cellValue($cell, $type, $shared);
                }
                $row[$col] = $value;
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'row' && $row !== null) {
                $rows[$rowIndex] = $row;
                $row = null;
                if (count($rows) > $maxRows) {
                    break;
                }
            }
        }
        $reader->close();

        if ($rows === []) {
            return [];
        }
        $out = [];
        for ($r = 0, $last = max(array_keys($rows)); $r <= $last; $r++) {
            $cells = $rows[$r] ?? [];
            $line = [];
            for ($c = 0, $width = $cells === [] ? 0 : max(array_keys($cells)) + 1; $c < $width; $c++) {
                $line[] = $cells[$c] ?? '';
            }
            $out[] = $line;
        }

        return $out;
    }

    /** @param  list<string>  $shared */
    private static function cellValue(string $cellXml, string $type, array $shared): string
    {
        $node = simplexml_load_string($cellXml, 'SimpleXMLElement', LIBXML_NONET);
        if ($node === false) {
            return '';
        }
        $node->registerXPathNamespace('m', self::MAIN_NS);
        $first = fn (string $path) => ($found = $node->xpath($path)) ? (string) $found[0] : '';

        return match ($type) {
            's' => $shared[(int) $first('m:v')] ?? '',
            'inlineStr' => implode('', array_map('strval', $node->xpath('.//m:t') ?: [])),
            'b' => $first('m:v') === '1' ? 'TRUE' : 'FALSE',
            default => self::number($first('m:v')),
        };
    }

    /** A whole number stored as 3.0 or 1E+2 reads back as "3" / "100"; other numbers keep their text. */
    private static function number(string $value): string
    {
        if ($value !== '' && is_numeric($value) && (float) $value == (int) (float) $value && abs((float) $value) < 1e15) {
            return (string) (int) (float) $value;
        }

        return $value;
    }
}

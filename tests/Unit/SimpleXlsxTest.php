<?php

namespace Tests\Unit;

use App\Support\SimpleXlsx;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class SimpleXlsxTest extends TestCase
{
    public function test_reads_a_workbook_saved_the_way_excel_saves_it(): void
    {
        // Shared strings (one with rich-text runs and a phonetic hint), a number, a boolean, a skipped column and an empty row.
        $path = $this->zip([
            'xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Câu hỏi" sheetId="1" r:id="rId3"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Target="worksheets/sheet7.xml" Id="rId3" Type="x"/></Relationships>',
            'xl/sharedStrings.xml' => '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Dạng câu</t></si><si><r><t>Đề </t></r><r><rPr><b/></rPr><t>bài</t></r><rPh><t>x</t></rPh></si><si/></sst>',
            'xl/worksheets/sheet7.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
                .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="C1" t="s"><v>1</v></c></row>'
                .'<row r="3"><c r="A3"><v>3.0</v></c><c r="B3" t="b"><v>1</v></c><c r="D3"><v>2.5</v></c><c r="E3" t="s"><v>2</v></c></row>'
                .'</sheetData></worksheet>',
        ]);

        $this->assertSame([
            ['Dạng câu', '', 'Đề bài'],
            [],
            ['3', 'TRUE', '', '2.5', ''],
        ], SimpleXlsx::read($path));
    }

    public function test_written_workbook_reads_back(): void
    {
        $rows = [['Cột A', 'Cột "B" & <C>'], ['', '감사합니다'], ['1']];
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, SimpleXlsx::write($rows, 'Mẫu'));

        $this->assertSame($rows, SimpleXlsx::read($path));
        $this->assertSame(['A', 'Z', 'AA', 'AZ', 'BA'], array_map([SimpleXlsx::class, 'columnName'], [0, 25, 26, 51, 52]));
    }

    /** @param  array<string, string>  $files */
    private function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }
}

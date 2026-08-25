<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DocumentProcessRunner;
use App\Services\LocalDocumentProcessingProvider;
use App\Services\MediaProcessingOrchestrator;
use App\Services\MediaService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class LocalDocumentSpreadsheetTest extends TestCase
{
    use RefreshDatabase;

    private int $customerId;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media_local');
        config([
            'media.disk' => 'media_local',
            'media.bucket' => 'test-media',
            'media.processing.providers.ocr' => 'local_document',
            'media.processing.versions.ocr' => 'local-document-v1',
        ]);
        $this->customerId = DB::table('saas_customers')->insertGetId([
            'name' => 'Tenant A', 'slug' => 'tenant-a', 'subdomain' => 'tenant-a', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin = User::forceCreate([
            'customer_id' => $this->customerId, 'name' => 'Admin', 'email' => uniqid().'@example.test',
            'password' => Hash::make('password'), 'role' => 'customer_admin', 'status' => 'active', 'email_verified_at' => now(),
        ]);
        TenantContext::set((object) ['id' => $this->customerId]);
        $this->actingAs($this->admin);
    }

    public function test_cells_are_read_directly_without_a_libreoffice_rendering(): void
    {
        Storage::disk('media_local')->put('book.xlsx', $this->workbook());
        $runner = Mockery::mock(DocumentProcessRunner::class);
        $runner->shouldNotReceive('run');

        $units = (new LocalDocumentProcessingProvider($runner))->process(
            (object) ['file_type' => 'document', 'extension' => 'xlsx', 'storage_disk' => 'media_local', 'storage_key' => 'book.xlsx'],
            (object) ['job_type' => 'ocr', 'output_profile' => 'layout=preserve;locale=vi'],
        )['units'];

        $this->assertCount(2, $units);
        $this->assertSame("Region\tRevenue\nNorth\t1500\n1500\t\tnote", $units[0]['text']);
        $this->assertSame(['page', '1', 1], [$units[0]['locator_type'], $units[0]['locator_value'], $units[0]['sequence']]);
        $this->assertSame('embedded_text', $units[0]['extraction_method']);
        $this->assertSame(['sheet_name' => 'Sales'], $units[0]['metadata']);
        $this->assertSame("TRUE\tGhi chú tiếng Việt", $units[1]['text']);
        $this->assertSame(['sheet_name' => 'Notes'], $units[1]['metadata']);
    }

    public function test_a_workbook_without_cell_text_still_falls_back_to_the_office_pipeline(): void
    {
        Storage::disk('media_local')->put('empty.xlsx', $this->workbook(empty: true));
        $runner = Mockery::mock(DocumentProcessRunner::class);
        $runner->shouldReceive('run')->once()->andReturn('');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('provider_command_failed');
        (new LocalDocumentProcessingProvider($runner))->process(
            (object) ['file_type' => 'document', 'extension' => 'xlsx', 'storage_disk' => 'media_local', 'storage_key' => 'empty.xlsx'],
            (object) ['job_type' => 'ocr', 'output_profile' => 'layout=preserve;locale=vi'],
        );
    }

    public function test_the_sheet_count_is_capped_by_the_same_page_limit(): void
    {
        config(['media.processing.local_document.max_pages' => 1]);
        Storage::disk('media_local')->put('wide.xlsx', $this->workbook());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('page_limit_exceeded');
        (new LocalDocumentProcessingProvider(Mockery::mock(DocumentProcessRunner::class)))->process(
            (object) ['file_type' => 'document', 'extension' => 'xlsx', 'storage_disk' => 'media_local', 'storage_key' => 'wide.xlsx'],
            (object) ['job_type' => 'ocr', 'output_profile' => 'layout=preserve;locale=vi'],
        );
    }

    public function test_an_oversized_sheet_part_fails_before_it_is_copied(): void
    {
        config(['media.processing.local_document.max_docx_xml_bytes' => 200]);
        Storage::disk('media_local')->put('big.xlsx', $this->workbook(padSheet: true));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('source_expansion_limit_exceeded');
        (new LocalDocumentProcessingProvider(Mockery::mock(DocumentProcessRunner::class)))->process(
            (object) ['file_type' => 'document', 'extension' => 'xlsx', 'storage_disk' => 'media_local', 'storage_key' => 'big.xlsx'],
            (object) ['job_type' => 'ocr', 'output_profile' => 'layout=preserve;locale=vi'],
        );
    }

    public function test_the_sheet_name_reaches_the_extracted_text_row_as_provenance(): void
    {
        $media = app(MediaService::class)->upload(
            UploadedFile::fake()->createWithContent('book.xlsx', $this->workbook()),
            ['file_type' => 'document', 'module' => 'course', 'entity_type' => 'activities', 'entity_id' => 99, 'purpose' => 'document'],
            $this->admin->id,
        );

        app(MediaProcessingOrchestrator::class)->materializeForCourseActivity($this->customerId, $media->id, 'vi', $this->admin->id);

        $rows = DB::table('media_extracted_texts')->where('media_file_id', $media->id)->orderBy('sequence')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('Sales', json_decode($rows[0]->metadata, true)['sheet_name']);
        $this->assertSame('embedded_text', $rows[0]->extraction_method);
        $this->assertDatabaseHas('media_processing_jobs', [
            'media_file_id' => $media->id, 'job_type' => 'ocr', 'provider' => 'local_document', 'status' => 'ready',
        ]);
    }

    private function workbook(bool $empty = false, bool $padSheet = false): string
    {
        $sales = $empty
            ? '<row r="1"><c r="A1"/></row>'
            : '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
                .'<row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2"><v>1500</v></c></row>'
                .'<row r="3"><c r="A3"><f>SUM(B2:B2)</f><v>1500</v></c><c r="C3" t="inlineStr"><is><t>note</t></is></c></row>';
        if ($padSheet) {
            $sales .= '<row r="4"><c r="A4" t="inlineStr"><is><t>'.str_repeat('x', 400).'</t></is></c></row>';
        }
        $notes = $empty
            ? '<row r="1"><c r="A1"/></row>'
            : '<row r="1"><c r="A1" t="b"><v>1</v></c><c r="B1" t="s"><v>3</v></c></row>';

        $path = tempnam(sys_get_temp_dir(), 'lf-xlsx-');
        $archive = new ZipArchive;
        $archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $archive->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Sales" sheetId="1" r:id="rId1"/><sheet name="Notes" sheetId="2" r:id="rId2"/></sheets></workbook>');
        $archive->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8"?><Relationships>'
            .'<Relationship Id="rId1" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Target="worksheets/sheet2.xml"/></Relationships>');
        $archive->addFromString('xl/sharedStrings.xml',
            '<?xml version="1.0" encoding="UTF-8"?><sst><si><t>Region</t></si><si><t>Revenue</t></si><si><t>North</t></si>'
            .'<si><t>Ghi chú tiếng Việt</t></si></sst>');
        $archive->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet><sheetData>'.$sales.'</sheetData></worksheet>');
        $archive->addFromString('xl/worksheets/sheet2.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet><sheetData>'.$notes.'</sheetData></worksheet>');
        $archive->close();
        $contents = (string) file_get_contents($path);
        unlink($path);

        return $contents;
    }
}

<?php

namespace Tests\Integration;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Closure gate cho `media_region_languages` (ADR-0019 v1.8).
 *
 * SQLite bo qua moi CHECK va khong dung FK composite theo cach MariaDB dung,
 * nen suite Feature khong chung minh duoc gi o tang nay. Nhung dieu duoc kiem
 * o day la thu chi database moi bao dam: FK composite theo tenant, hai unique
 * key, hai CHECK, CASCADE khi xoa region, va `down()` fail-closed.
 */
class MediaRegionLanguageMariaDbTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_04_000100_add_region_language_evidence.php';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Region language physical constraints require MariaDB.');
        }
    }

    public function test_composite_foreign_key_rejects_a_region_from_another_tenant(): void
    {
        [$tenant, $region] = $this->fixture();
        [$otherTenant] = $this->fixture('secondary');

        $this->assertTrue($this->insertSucceeds($tenant, $region, ['script' => 'Hang', 'locale' => 'ko']));
        // Region ton tai, tenant khong khop: FK mot cot se cho qua, FK composite
        // thi khong. Day la duong ro ri tenant ma bang con phai chan.
        $this->assertFalse($this->insertSucceeds($otherTenant, $region, ['script' => 'Latn', 'locale' => 'vi']));
    }

    public function test_one_row_per_ordinal_and_one_row_per_script(): void
    {
        [$tenant, $region] = $this->fixture();

        $this->assertTrue($this->insertSucceeds($tenant, $region, ['ordinal' => 1, 'script' => 'Latn']));
        $this->assertFalse($this->insertSucceeds($tenant, $region, ['ordinal' => 1, 'script' => 'Hang']),
            'Hai chu viet khong duoc dung chung mot thu hang.');
        $this->assertFalse($this->insertSucceeds($tenant, $region, ['ordinal' => 2, 'script' => 'Latn']),
            'Mot chu viet khong duoc ghi hai lan cho cung mot vung.');
        $this->assertTrue($this->insertSucceeds($tenant, $region, ['ordinal' => 2, 'script' => 'Hang']));
    }

    public function test_ordinal_and_char_count_checks_are_physically_enforced(): void
    {
        [$tenant, $region] = $this->fixture();

        $this->assertFalse($this->insertSucceeds($tenant, $region, ['ordinal' => 0]));
        $this->assertFalse($this->insertSucceeds($tenant, $region, ['ordinal' => 6]));
        $this->assertFalse($this->insertSucceeds($tenant, $region, ['char_count' => 0]),
            '`char_count` la phep dem ky tu quan sat duoc; 0 nghia la khong quan sat thay gi.');
        $this->assertTrue($this->insertSucceeds($tenant, $region, ['ordinal' => 5, 'char_count' => 1]));
    }

    /** Script quan sat duoc nhung khong co locale trong profile van la du lieu co ten. */
    public function test_locale_is_nullable_while_script_is_not(): void
    {
        [$tenant, $region] = $this->fixture();

        $this->assertTrue($this->insertSucceeds($tenant, $region, ['script' => 'Latn', 'locale' => null]));
        $this->assertSame(1, DB::table('media_region_languages')
            ->where('region_id', $region)->whereNull('locale')->count());
    }

    public function test_language_rows_cascade_when_the_region_is_deleted(): void
    {
        [$tenant, $region] = $this->fixture();
        $this->insertSucceeds($tenant, $region, ['ordinal' => 1, 'script' => 'Hang', 'locale' => 'ko']);
        $this->insertSucceeds($tenant, $region, ['ordinal' => 2, 'script' => 'Latn', 'locale' => 'vi']);
        $this->assertSame(2, DB::table('media_region_languages')->where('region_id', $region)->count());

        DB::table('media_extracted_regions')->where('id', $region)->delete();

        $this->assertSame(0, DB::table('media_region_languages')->where('region_id', $region)->count(),
            'Bang chung ngon ngu khong duoc song sot sau region so huu no.');
    }

    public function test_rollback_is_refused_while_evidence_exists_and_succeeds_once_empty(): void
    {
        [$tenant, $region] = $this->fixture();
        $this->insertSucceeds($tenant, $region, ['script' => 'Hang', 'locale' => 'ko']);

        try {
            (require base_path(self::MIGRATION))->down();
            $this->fail('Rollback discarded region language evidence.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Rollback refused', $error->getMessage());
        }
        $this->assertSame(1, DB::table('media_region_languages')->count());

        DB::table('media_extracted_regions')->where('id', $region)->delete();
        (require base_path(self::MIGRATION))->down();
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('media_region_languages'));

        // `RefreshDatabase` migrate mot lan cho ca process va boc tung test
        // trong transaction — nhung DROP TABLE khong rollback duoc. Khong dung
        // lai o day thi moi test chay sau, ke ca o file khac, se thay bang da
        // bien mat. Dung lai bang chinh up() de test nay khong phu thuoc thu tu.
        (require base_path(self::MIGRATION))->up();
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('media_region_languages'));
    }

    // ------------------------------------------------------------ helpers ---

    /** @param array<string, mixed> $overrides */
    private function insertSucceeds(int $tenant, int $region, array $overrides = []): bool
    {
        try {
            DB::table('media_region_languages')->insert(array_merge([
                'customer_id' => $tenant,
                'region_id' => $region,
                'ordinal' => 1,
                'script' => 'Hang',
                'locale' => 'ko',
                'char_count' => 5,
                'created_at' => now(),
                'updated_at' => now(),
            ], $overrides));

            return true;
        } catch (QueryException) {
            return false;
        }
    }

    /** @return array{0: int, 1: int} */
    private function fixture(string $suffix = 'primary'): array
    {
        $tenant = DB::table('saas_customers')->insertGetId([
            'name' => 'Region Language Tenant '.$suffix,
            'slug' => 'region-language-tenant-'.$suffix,
            'subdomain' => 'region-language-tenant-'.$suffix,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $userId = DB::table('users')->insertGetId([
            'customer_id' => $tenant,
            'name' => 'Region Language Admin',
            'email' => 'region-language-'.$suffix.'@example.test',
            'password' => bcrypt('password'),
            'role' => 'customer_admin',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $mediaId = DB::table('media_files')->insertGetId([
            'customer_id' => $tenant,
            'uploaded_by' => $userId,
            'file_type' => 'document',
            'mime_type' => 'application/pdf',
            'original_name' => 'region-language-'.$suffix.'.pdf',
            'display_name' => 'region-language-'.$suffix.'.pdf',
            'extension' => 'pdf',
            'storage_disk' => 'media_local',
            'storage_bucket' => 'test-media',
            'storage_key' => 'region-language/'.$suffix.'.pdf',
            'checksum' => 'sha256:'.$suffix,
            'file_size_bytes' => 1,
            'visibility' => 'private',
            'status' => 'ready',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $region = DB::table('media_extracted_regions')->insertGetId([
            'customer_id' => $tenant,
            'media_file_id' => $mediaId,
            'locale' => 'vi',
            'locator_type' => 'region',
            'locator_value' => '1#1',
            'page' => 1,
            'ordinal' => 1,
            'reading_order' => 1,
            'role' => 'paragraph',
            'extraction_method' => 'embedded_text',
            'processing_version' => 'structure-v2',
            'source_fingerprint' => str_repeat('a', 64),
            'status' => 'ready',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$tenant, $region];
    }
}

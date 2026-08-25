<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CourseMediaOwnerContextAuthorizer;
use App\Services\MediaProcessingOrchestrator;
use App\Services\MediaService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class MediaReadDerivedCommandTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER_ID = 99;

    private int $customerId;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media_local');
        config(['media.disk' => 'media_local', 'media.bucket' => 'test-media']);
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

    public function test_it_reads_the_current_revision_and_audits_the_consumer(): void
    {
        $this->preparedMedia();
        $this->allowOwnerContext();

        $this->artisan('media:read-derived', $this->arguments())
            ->expectsOutputToContain('locator=timespan=0-1000 locale=vi processing_version=fake-v1')
            ->assertExitCode(0);

        $log = DB::table('media_access_logs')->where('action', 'read_derived')->latest('id')->first();
        $this->assertSame('console', $log->source_type);
        $this->assertSame('allowed', json_decode($log->metadata, true)['decision']);
    }

    public function test_json_output_carries_the_full_contract_unit(): void
    {
        $this->preparedMedia();
        $this->allowOwnerContext();

        $this->assertSame(0, Artisan::call('media:read-derived', $this->arguments(['--format' => 'json'])));

        $payload = json_decode(Artisan::output(), true);
        $this->assertSame('allowed', $payload['decision']);
        $unit = $payload['units'][0];
        $this->assertSame(
            ['media_file_id', 'source_fingerprint', 'processing_version', 'content_type', 'locale', 'locator', 'text', 'delivery_url', 'confidence', 'status'],
            array_keys($unit)
        );
        $this->assertSame('transcript', $unit['content_type']);
        $this->assertSame(['type' => 'timespan', 'value' => '0-1000'], $unit['locator']);
        $this->assertSame('ready', $unit['status']);
    }

    public function test_an_archived_revision_is_readable_only_when_named(): void
    {
        $media = $this->preparedMedia();
        $this->allowOwnerContext();
        $superseded = DB::table('media_transcripts')->where('media_file_id', $media->id)->first();

        config(['media.processing.versions.speech_to_text' => 'fake-v2']);
        app(MediaProcessingOrchestrator::class)->materializeForCourseActivity($this->customerId, $media->id, 'vi', $this->admin->id);

        $this->artisan('media:read-derived', $this->arguments())
            ->expectsOutputToContain('processing_version=fake-v2')->assertExitCode(0);

        $this->artisan('media:read-derived', $this->arguments([
            '--processing-version' => $superseded->processing_version,
            '--source-fingerprint' => $superseded->source_fingerprint,
        ]))->expectsOutputToContain('status=archived')->assertExitCode(0);
    }

    public function test_a_mismatched_fingerprint_is_a_named_error_and_a_denied_audit_row(): void
    {
        $this->preparedMedia();
        $this->allowOwnerContext();

        $this->artisan('media:read-derived', $this->arguments(['--source-fingerprint' => str_repeat('a', 64)]))
            ->expectsOutputToContain('revision_mismatch')->assertExitCode(1);

        $log = DB::table('media_access_logs')->where('action', 'read_derived')->latest('id')->first();
        $metadata = json_decode($log->metadata, true);
        $this->assertSame('denied', $metadata['decision']);
        $this->assertSame('revision_mismatch', $metadata['error_code']);
    }

    public function test_the_real_authorizer_denies_an_owner_the_actor_has_no_course_context_for(): void
    {
        $this->preparedMedia();

        $this->artisan('media:read-derived', $this->arguments())
            ->expectsOutputToContain('unauthorized')->assertExitCode(1);
    }

    public function test_unsupported_content_type_and_missing_actor_are_rejected(): void
    {
        $this->artisan('media:read-derived', $this->arguments(['--content-type' => 'region']))
            ->expectsOutputToContain('--content-type must be one of')->assertExitCode(1);

        $arguments = $this->arguments();
        unset($arguments['--actor']);
        $this->artisan('media:read-derived', $arguments)
            ->expectsOutputToContain('--actor is required')->assertExitCode(1);
    }

    /** @param array<string, string|int> $overrides */
    private function arguments(array $overrides = []): array
    {
        return array_merge([
            '--customer' => $this->customerId,
            '--actor' => $this->admin->id,
            '--owner-type' => 'course_activity',
            '--owner-id' => self::OWNER_ID,
            '--content-type' => 'transcript',
            '--locale' => 'vi',
        ], $overrides);
    }

    private function preparedMedia(): object
    {
        $media = app(MediaService::class)->upload(UploadedFile::fake()->create('lesson.mp4', 32, 'video/mp4'), [
            'file_type' => 'video', 'module' => 'course', 'entity_type' => 'activities', 'entity_id' => self::OWNER_ID, 'purpose' => 'video',
        ], $this->admin->id);
        app(MediaProcessingOrchestrator::class)->materializeForCourseActivity($this->customerId, $media->id, 'vi', $this->admin->id);
        DB::table('media_file_usages')->insert([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id, 'owner_type' => 'course_activity',
            'owner_id' => self::OWNER_ID, 'usage_type' => 'video', 'status' => 'active', 'created_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $media;
    }

    private function allowOwnerContext(): void
    {
        $authorizer = Mockery::mock(CourseMediaOwnerContextAuthorizer::class);
        $authorizer->shouldReceive('authorized')->andReturnTrue();
        $this->app->instance(CourseMediaOwnerContextAuthorizer::class, $authorizer);
    }
}

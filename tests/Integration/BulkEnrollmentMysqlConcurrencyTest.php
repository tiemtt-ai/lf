<?php

namespace Tests\Integration;

use App\Exceptions\BulkEnrollmentAtomicException;
use App\Models\User;
use App\Services\BulkEnrollmentPayload;
use App\Services\BulkEnrollmentService;
use App\Services\BulkEnrollmentSubmissionToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class BulkEnrollmentMysqlConcurrencyTest extends TestCase
{
    private const DATABASE = 'lf_bulk_enrollment_concurrency_test_20260721';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires a real MySQL/MariaDB connection.');
        }
        if (DB::connection()->getDatabaseName() !== self::DATABASE) {
            throw new RuntimeException('Concurrency test refused a non-dedicated database.');
        }
        if (config('cache.default') === 'array') {
            throw new RuntimeException('Concurrency token test requires an atomic shared cache store.');
        }
    }

    public function test_complete_mysql_concurrency_matrix(): void
    {
        $engine = DB::selectOne('select version() as version')->version;
        $isolation = DB::selectOne('select @@tx_isolation as isolation_level')->isolation_level;
        $this->assertStringContainsString('MariaDB', $engine);
        $this->assertSame('REPEATABLE-READ', $isolation);
        $this->assertSame('database', config('cache.default'));

        $context = $this->context('token-replay');
        [$token, $payload] = $this->preparedSubmission($context, $context['admin_a']);
        $case1 = $this->runPairWorkers([
            $this->pairSpec($context, $context['admin_a'], $token, $payload),
            $this->pairSpec($context, $context['admin_a'], $token, $payload),
        ], $context['product']);
        $this->assertSame(['created', 'created'], $this->resultStatuses($case1));
        $this->assertSame(1, $this->nonTerminalCount($context));
        $this->assertSame(1, DB::table('core_course_enrollment_submissions')->where('status', 'completed')->count());

        $context = $this->context('two-admins');
        [$tokenA, $payloadA] = $this->preparedSubmission($context, $context['admin_a']);
        [$tokenB, $payloadB] = $this->preparedSubmission($context, $context['admin_b']);
        $case2 = $this->runPairWorkers([
            $this->pairSpec($context, $context['admin_a'], $tokenA, $payloadA),
            $this->pairSpec($context, $context['admin_b'], $tokenB, $payloadB),
        ], $context['product']);
        $statuses = $this->resultStatuses($case2);
        sort($statuses);
        $this->assertSame(['atomic_failed', 'created'], $statuses);
        $this->assertSame(1, $this->nonTerminalCount($context));

        $context = $this->context('reenrollment');
        $terminalId = $this->createEnrollment($context, 'completed');
        $confirmations = [['student_id' => $context['student'], 'product_id' => $context['product'], 'previous_enrollment_id' => $terminalId]];
        [$tokenA, $payloadA] = $this->preparedSubmission($context, $context['admin_a'], $confirmations);
        [$tokenB, $payloadB] = $this->preparedSubmission($context, $context['admin_b'], $confirmations);
        $case3 = $this->runPairWorkers([
            $this->pairSpec($context, $context['admin_a'], $tokenA, $payloadA),
            $this->pairSpec($context, $context['admin_b'], $tokenB, $payloadB),
        ], $context['product']);
        $statuses = $this->resultStatuses($case3);
        sort($statuses);
        $this->assertSame(['atomic_failed', 'reenrolled'], $statuses);
        $this->assertSame('completed', DB::table('core_course_enrollments')->where('id', $terminalId)->value('status'));
        $this->assertSame(1, $this->nonTerminalCount($context));
    }

    private function runPairWorkers(array $specs, int $lockedProductId, ?callable $lockedMutation = null): array
    {
        DB::disconnect();
        $workers = [];
        foreach ($specs as $spec) {
            [$parentSocket, $childSocket] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            $pid = pcntl_fork();
            if ($pid === 0) {
                fclose($parentSocket);
                fread($childSocket, 1);
                DB::purge();
                $result = $this->executeSpec($spec);
                fwrite($childSocket, json_encode($result, JSON_THROW_ON_ERROR));
                fclose($childSocket);
                exit(0);
            }
            fclose($childSocket);
            $workers[] = ['pid' => $pid, 'socket' => $parentSocket];
        }

        $lock = $this->independentConnection('bulk_parent_lock');
        $lock->beginTransaction();
        $lock->table('core_course_products')->where('id', $lockedProductId)->lockForUpdate()->first();
        if ($lockedMutation) {
            $lockedMutation($lock);
        }
        foreach ($workers as $worker) {
            fwrite($worker['socket'], 'g');
        }
        usleep(150000);
        $lock->commit();

        $results = [];
        foreach ($workers as $worker) {
            stream_set_timeout($worker['socket'], 10);
            $payload = stream_get_contents($worker['socket']);
            fclose($worker['socket']);
            pcntl_waitpid($worker['pid'], $status);
            $this->assertTrue(pcntl_wifexited($status));
            $results[] = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        }
        DB::purge('bulk_parent_lock');
        DB::reconnect();

        return $results;
    }

    private function executeSpec(array $spec): array
    {
        try {
            $result = app(BulkEnrollmentService::class)->commit(
                $spec['customer'], $spec['admin'], $spec['token'], $spec['payload']
            );

            return ['status' => $result['items'][0]['status']];
        } catch (BulkEnrollmentAtomicException) {
            return ['status' => 'atomic_failed'];
        } catch (Throwable $exception) {
            return ['status' => 'worker_error', 'error' => $exception->getMessage()];
        }
    }

    private function independentConnection(string $name)
    {
        config(['database.connections.'.$name => config('database.connections.mysql')]);

        return DB::connection($name);
    }

    private function pairSpec(
        array $context,
        int $admin,
        string $token,
        array $payload
    ): array {
        return $context + compact('admin', 'token', 'payload');
    }

    private function preparedSubmission(array $context, int $admin, array $confirmations = []): array
    {
        $payload = app(BulkEnrollmentPayload::class)->canonical(
            [$context['student']], [$context['product']], $confirmations, []
        );
        $token = app(BulkEnrollmentSubmissionToken::class)->issue($context['customer'], $admin, $payload);

        return [$token, $payload];
    }

    private function resultStatuses(array $results): array
    {
        return array_column($results, 'status');
    }

    private function nonTerminalCount(array $context): int
    {
        return DB::table('core_course_enrollments')->where('customer_id', $context['customer'])
            ->where('student_id', $context['student'])->where('product_id', $context['product'])
            ->whereIn('status', ['pending', 'active', 'suspended'])->count();
    }

    private function context(string $key): array
    {
        $key .= '-'.bin2hex(random_bytes(3));
        $now = now();
        $customer = DB::table('saas_customers')->insertGetId([
            'name' => $key, 'slug' => $key, 'subdomain' => $key, 'status' => 'active',
            'organization_type' => 'school', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $adminA = $this->createUser($customer, 'customer_admin', $key.'-a');
        $adminB = $this->createUser($customer, 'customer_admin', $key.'-b');
        $student = $this->createUser($customer, 'student', $key.'-student');
        $template = DB::table('core_course_templates')->insertGetId([
            'customer_id' => $customer, 'category_id' => null, 'title' => $key,
            'short_description' => null, 'description' => null, 'publisher_name' => null,
            'intro_video_source' => null, 'intro_image_media_file_id' => null,
            'intro_video_media_file_id' => null, 'difficulty_level' => null,
            'estimated_minutes_per_lesson' => 0, 'estimated_lesson_count' => null,
            'lesson_count' => 0, 'meta_title' => null, 'meta_description' => null,
            'meta_keywords' => null, 'working_revision' => 1, 'status' => 'active',
            'created_by' => $adminA, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $version = $this->createVersion($customer, $adminA, $template, 1);
        $product = DB::table('core_course_products')->insertGetId($this->productRow($customer, $key, $now));
        $item = DB::table('core_course_product_items')->insertGetId([
            'customer_id' => $customer, 'product_id' => $product, 'template_id' => $template,
            'version_id' => $version, 'sort_order' => 0, 'is_required' => true,
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);

        return ['customer' => $customer, 'admin_a' => $adminA, 'admin_b' => $adminB,
            'student' => $student, 'template' => $template, 'version' => $version,
            'product' => $product, 'item' => $item];
    }

    private function createUser(int $customer, string $role, string $key): int
    {
        return User::forceCreate([
            'customer_id' => $customer, 'name' => $key,
            'email' => $key.'@example.test', 'password' => Hash::make('password123'),
            'role' => $role, 'status' => 'active', 'email_verified_at' => now(),
        ])->id;
    }

    private function createVersion(int $customer, int $admin, int $template, int $number): int
    {
        $now = now();

        return DB::table('core_course_template_versions')->insertGetId([
            'customer_id' => $customer, 'template_id' => $template, 'version_number' => $number,
            'version_code' => 'V-'.$template.'-'.$number, 'is_current' => true,
            'title_snapshot' => 'Version '.$number, 'estimated_minutes_per_lesson_snapshot' => 0,
            'lesson_count_snapshot' => 0, 'source_working_revision' => 1,
            'status' => 'published', 'published_at' => $now, 'published_by' => $admin,
            'source_template_updated_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function createEnrollment(array $context, string $status): int
    {
        $now = now();

        return DB::table('core_course_enrollments')->insertGetId([
            'customer_id' => $context['customer'], 'student_id' => $context['student'],
            'product_id' => $context['product'], 'version_id' => $context['version'],
            'source' => 'admin', 'enrolled_at' => $now, 'status' => $status,
            'completed_at' => $status === 'completed' ? $now : null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function createAdditionalProduct(array $context, string $suffix): int
    {
        $key = 'additional-'.$suffix.'-'.bin2hex(random_bytes(3));
        $product = DB::table('core_course_products')->insertGetId(
            $this->productRow($context['customer'], $key, now())
        );
        DB::table('core_course_product_items')->insert([
            'customer_id' => $context['customer'], 'product_id' => $product,
            'template_id' => $context['template'], 'version_id' => $context['version'],
            'sort_order' => 0, 'is_required' => true, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $product;
    }

    private function productRow(int $customer, string $key, object $now): array
    {
        return [
            'customer_id' => $customer, 'product_code' => strtoupper($key),
            'product_type' => 'single_course', 'offering_type' => 'self_paced_course',
            'title' => $key, 'slug' => $key, 'thumbnail_type' => 'image',
            'price' => 0, 'currency' => 'VND', 'enrollment_type' => 'paid',
            'enrollment_count' => 0, 'access_duration_days' => 30,
            'review_duration_days' => 0, 'is_certificate_enabled' => false,
            'is_refundable' => false, 'show_enrollment_count' => true,
            'is_featured' => false, 'sort_order' => 0, 'visibility' => 'public',
            'uses_custom_description' => false, 'uses_custom_intro_media' => false,
            'promotion_enabled' => false, 'status' => 'active',
            'published_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ];
    }
}

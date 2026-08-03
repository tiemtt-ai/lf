<?php

namespace Tests\Integration;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EnrollmentBindingTriggerMysqlTest extends TestCase
{
    use RefreshDatabase;

    public function test_mysql_trigger_blocks_binding_updates_but_allows_other_updates(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL trigger verification requires the mysql driver.');
        }

        $customerId = DB::table('saas_customers')->insertGetId([
            'name' => 'Trigger Tenant', 'slug' => 'trigger-tenant',
            'subdomain' => 'trigger-tenant', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $adminId = DB::table('users')->insertGetId([
            'customer_id' => $customerId, 'name' => 'Trigger Admin',
            'email' => 'trigger-admin@example.test', 'password' => bcrypt('password'),
            'role' => 'customer_admin', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $studentId = DB::table('users')->insertGetId([
            'customer_id' => $customerId, 'name' => 'Trigger Student',
            'email' => 'trigger-student@example.test', 'password' => bcrypt('password'),
            'role' => 'student', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $productA = $this->product($customerId, 'TRIGGER-A');
        $productB = $this->product($customerId, 'TRIGGER-B');
        $versionA = $this->version($customerId, $adminId, 'TRIGGER-V1');
        $versionB = $this->version($customerId, $adminId, 'TRIGGER-V2');
        $enrollmentId = DB::table('core_course_enrollments')->insertGetId([
            'customer_id' => $customerId, 'student_id' => $studentId,
            'product_id' => $productA, 'version_id' => $versionA,
            'source' => 'admin', 'enrolled_at' => now(), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('core_course_enrollments')->where('id', $enrollmentId)->update([
            'status' => 'suspended', 'notes' => 'Allowed update', 'updated_at' => now(),
        ]);
        $this->assertDatabaseHas('core_course_enrollments', [
            'id' => $enrollmentId, 'status' => 'suspended', 'notes' => 'Allowed update',
        ]);

        foreach ([['product_id' => $productB], ['version_id' => $versionB]] as $update) {
            try {
                DB::table('core_course_enrollments')->where('id', $enrollmentId)->update($update);
                $this->fail('The immutable Enrollment binding trigger did not reject the update.');
            } catch (QueryException $exception) {
                $this->assertSame('45000', $exception->errorInfo[0] ?? null);
                $this->assertStringContainsString(
                    'LF_ENROLLMENT_BINDING_IMMUTABLE:trg_core_course_enrollments_binding_immutable_bu',
                    $exception->getMessage()
                );
            }
        }

        $this->assertDatabaseHas('core_course_enrollments', [
            'id' => $enrollmentId, 'product_id' => $productA, 'version_id' => $versionA,
        ]);
    }

    private function product(int $customerId, string $code): int
    {
        return DB::table('core_course_products')->insertGetId([
            'customer_id' => $customerId, 'title' => $code, 'slug' => strtolower($code),
            'product_code' => $code, 'product_type' => 'single_course',
            'offering_type' => 'self_paced_course', 'thumbnail_type' => 'image',
            'price' => 0, 'currency' => 'VND', 'enrollment_type' => 'paid',
            'access_duration_days' => 30, 'status' => 'active',
            'visibility' => 'public',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function version(int $customerId, int $adminId, string $code): int
    {
        $templateId = DB::table('core_course_templates')->insertGetId([
            'customer_id' => $customerId, 'title' => $code,
            'working_revision' => 1, 'status' => 'active',
            'created_by' => $adminId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('core_course_template_versions')->insertGetId([
            'customer_id' => $customerId, 'template_id' => $templateId,
            'version_number' => 1, 'version_code' => $code, 'title_snapshot' => $code,
            'source_working_revision' => 1, 'status' => 'published',
            'published_at' => now(), 'published_by' => $adminId,
            'source_template_updated_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0033 (Owner 2026-11-17): the tenant owner and the teachers' permission groups.
 *
 * Every tenant gets its owner (the earliest active admin) and its default group holding the permissions that are on
 * by default, so nobody loses a right: a teacher left at NULL uses that default group.
 */
return new class extends Migration
{
    private const GROUPS = 'saas_teacher_permission_groups';

    private const GRANTS = 'saas_teacher_permission_grants';

    /** ADR-0033 D3, column "Mặc định": what every teacher could do before the ADR. */
    private const DEFAULT_KEYS = ['content.template.create', 'assessment.authoring', 'assessment.grading', 'class.operate'];

    public function up(): void
    {
        Schema::create('saas_teacher_permission_groups', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->string('name', 150);
            // 1 marks the tenant's default group, NULL any other: NULLs never collide in the unique key below.
            $table->boolean('is_default')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'name'], 'uk_stpg_customer_name');
            $table->unique(['customer_id', 'is_default'], 'uk_stpg_customer_default');
            $table->unique(['id', 'customer_id'], 'uk_stpg_id_customer');
            $table->foreign('customer_id', 'fk_stpg_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            $table->foreign('created_by', 'fk_stpg_created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by', 'fk_stpg_updated_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('saas_teacher_permission_grants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('permission_group_id');
            $table->string('permission_key', 100);
            $table->timestamp('created_at')->nullable();

            $table->unique(['permission_group_id', 'permission_key'], 'uk_stpgr_group_key');
            $table->index(['customer_id', 'permission_key'], 'idx_stpgr_customer_key');
            $table->foreign('customer_id', 'fk_stpgr_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            $table->foreign(['permission_group_id', 'customer_id'], 'fk_stpgr_group')
                ->references(['id', 'customer_id'])->on(self::GROUPS)->cascadeOnDelete();
        });

        if ($this->enforcesChecks()) {
            DB::unprepared('ALTER TABLE '.self::GROUPS.' ADD CONSTRAINT chk_stpg_is_default CHECK (is_default = 1)');
        }

        $now = now();
        DB::table(self::GROUPS)->insertUsing(
            ['customer_id', 'name', 'is_default', 'created_at', 'updated_at'],
            DB::table('saas_customers')->selectRaw('id, ?, 1, ?, ?', ['Mặc định', $now, $now])
        );
        foreach (self::DEFAULT_KEYS as $key) {
            DB::table(self::GRANTS)->insertUsing(
                ['customer_id', 'permission_group_id', 'permission_key', 'created_at'],
                DB::table(self::GROUPS)->where('is_default', 1)->selectRaw('customer_id, id, ?, ?', [$key, $now])
            );
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('teacher_permission_group_id')->nullable()->after('role');
            // The pair keeps a teacher in a group of their own tenant; NULL (the default group) is not checked.
            $table->index(['teacher_permission_group_id', 'customer_id'], 'idx_users_permission_group_customer');
            $table->foreign(['teacher_permission_group_id', 'customer_id'], 'fk_users_permission_group')
                ->references(['id', 'customer_id'])->on(self::GROUPS)->restrictOnDelete();
        });

        Schema::table('saas_customers', function (Blueprint $table): void {
            $table->unsignedBigInteger('owner_user_id')->nullable();
            // The pair keeps the owner a user of this very tenant (uk_users_id_customer).
            $table->index(['owner_user_id', 'id'], 'idx_sc_owner_user');
            $table->foreign(['owner_user_id', 'id'], 'fk_sc_owner_user')
                ->references(['id', 'customer_id'])->on('users')->restrictOnDelete();
        });

        // The owner of an existing tenant is its earliest active admin; a tenant without one keeps NULL until its first.
        foreach (DB::table('saas_customers')->pluck('id') as $customerId) {
            $ownerId = DB::table('users')->where('customer_id', $customerId)->where('role', 'customer_admin')
                ->where('status', 'active')->orderBy('created_at')->orderBy('id')->value('id');
            if ($ownerId !== null) {
                DB::table('saas_customers')->where('id', $customerId)->update(['owner_user_id' => $ownerId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('saas_customers', function (Blueprint $table): void {
            $table->dropForeign('fk_sc_owner_user');
            $table->dropIndex('idx_sc_owner_user');
            $table->dropColumn('owner_user_id');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign('fk_users_permission_group');
            $table->dropIndex('idx_users_permission_group_customer');
            $table->dropColumn('teacher_permission_group_id');
        });
        Schema::dropIfExists(self::GRANTS);
        Schema::dropIfExists(self::GROUPS);
    }

    private function enforcesChecks(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_file_shares', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('media_file_id');
            $table->string('grantee_type', 20);
            $table->unsignedBigInteger('grantee_user_id')->nullable();
            $table->string('grantee_role', 50)->nullable();
            // 'u:<user id>' or 'r:<role>'; one value to make the "active share is unique" key a plain column.
            $table->string('grantee_key', 80);
            $table->unsignedBigInteger('shared_by');
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->timestamp('revoked_at', 6)->nullable();
            $table->timestamps(6);
            // 1 while the share is active, NULL once revoked: MariaDB has no partial unique index, and NULLs never collide.
            $table->tinyInteger('active_flag')->nullable()->storedAs('CASE WHEN revoked_at IS NULL THEN 1 END');

            $table->unique(['id', 'customer_id'], 'uk_mfs_tenant_identity');
            $table->unique(['customer_id', 'media_file_id', 'grantee_key', 'active_flag'], 'uk_mfs_active_share');
            $table->index(['customer_id', 'media_file_id'], 'idx_mfs_media');
            $table->index(['customer_id', 'grantee_user_id', 'revoked_at'], 'idx_mfs_user');
            $table->index(['customer_id', 'grantee_role', 'revoked_at'], 'idx_mfs_role');
            $table->foreign('customer_id', 'fk_mfs_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            $table->foreign(['media_file_id', 'customer_id'], 'fk_mfs_media_tenant')
                ->references(['id', 'customer_id'])->on('media_files')->restrictOnDelete();
            $table->foreign('grantee_user_id', 'fk_mfs_grantee_user')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('shared_by', 'fk_mfs_shared_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('revoked_by', 'fk_mfs_revoked_by')->references('id')->on('users')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE media_file_shares ADD CONSTRAINT chk_mfs_type CHECK (grantee_type IN ('user','role')), ADD CONSTRAINT chk_mfs_grantee CHECK ((grantee_type = 'user' AND grantee_user_id IS NOT NULL AND grantee_role IS NULL) OR (grantee_type = 'role' AND grantee_role IS NOT NULL AND grantee_user_id IS NULL)), ADD CONSTRAINT chk_mfs_role CHECK (grantee_role IS NULL OR grantee_role IN ('teacher'))");
        }
    }

    public function down(): void
    {
        $rows = DB::table('media_file_shares')->count();

        if ($rows > 0) {
            throw new RuntimeException(
                "Rollback refused: {$rows} media share row(s) are audit history of who could see which Media. "
                .'Revoke and archive them deliberately before rolling back.'
            );
        }

        Schema::dropIfExists('media_file_shares');
    }
};

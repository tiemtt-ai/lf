<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE ai_embeddings
                ADD COLUMN generation INT UNSIGNED NOT NULL DEFAULT 1,
                DROP INDEX uk_aem_chunk_model,
                ADD UNIQUE KEY uk_aem_chunk_model (customer_id, knowledge_chunk_id, provider, model, embedding_hash, generation),
                ADD CONSTRAINT chk_aem_generation CHECK (generation >= 1)');

            return;
        }

        Schema::table('ai_embeddings', function (Blueprint $table): void {
            $table->unsignedInteger('generation')->default(1);
            $table->dropUnique('uk_aem_chunk_model');
            $table->unique(['customer_id', 'knowledge_chunk_id', 'provider', 'model', 'embedding_hash', 'generation'], 'uk_aem_chunk_model');
        });
    }

    public function down(): void
    {
        // Preflight before ALL DDL: cannot erase re-registration provenance.
        if (DB::table('ai_embeddings')->where('generation', '>', 1)->exists()) {
            throw new RuntimeException('LF_EMBEDDING_GENERATION_ROLLBACK_REFUSED');
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE ai_embeddings
                DROP CONSTRAINT chk_aem_generation,
                DROP INDEX uk_aem_chunk_model,
                ADD UNIQUE KEY uk_aem_chunk_model (customer_id, knowledge_chunk_id, provider, model, embedding_hash),
                DROP COLUMN generation');

            return;
        }

        Schema::table('ai_embeddings', function (Blueprint $table): void {
            $table->dropUnique('uk_aem_chunk_model');
            $table->dropColumn('generation');
            $table->unique(['customer_id', 'knowledge_chunk_id', 'provider', 'model', 'embedding_hash'], 'uk_aem_chunk_model');
        });
    }
};

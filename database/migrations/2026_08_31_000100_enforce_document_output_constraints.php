<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// D1: Owner approved 2026-08-31; architecture gate in Document Final Code Review.
return new class extends Migration
{
    private function checks(): array
    {
        return [
            'media_extracted_texts' => ['chk_met_ocr_provider' => "extraction_method <> 'ocr' OR provider IS NOT NULL"],
            'media_extracted_tables' => [
                'chk_mtt_ocr_provider' => "extraction_method <> 'ocr' OR provider IS NOT NULL",
                'chk_mtt_header' => 'has_header IN (0,1)',
            ],
            'media_table_cells' => ['chk_mtc_header' => 'is_header IN (0,1)'],
        ];
    }

    private function cropCheck(): string
    {
        return '(crop_storage_key IS NULL AND crop_mime_type IS NULL AND crop_width IS NULL AND crop_height IS NULL AND crop_bytes IS NULL) OR '
            .'(crop_storage_key IS NOT NULL AND crop_mime_type IS NOT NULL AND crop_width IS NOT NULL AND crop_height IS NOT NULL AND crop_bytes IS NOT NULL AND crop_width > 0 AND crop_height > 0 AND crop_bytes > 0)';
    }

    public function up(): void
    {
        // Scan BEFORE any DDL; report identifiers only, never source text/crop paths.
        $checks = $this->checks();
        $checks['media_extracted_regions'] = [
            'crop_complete' => $this->cropCheck(),
            'crop_mime' => "crop_mime_type IS NULL OR crop_mime_type = 'image/png'",
            'ocr_provider' => "extraction_method <> 'ocr' OR provider IS NOT NULL",
        ];
        $violations = [];
        foreach ($checks as $table => $rules) {
            foreach ($rules as $name => $expression) {
                $query = DB::table($table)->whereRaw("NOT ({$expression})");
                $count = (clone $query)->count();
                if ($count > 0) {
                    $violations[$table.'.'.$name] = ['count' => $count, 'first_ids' => $query->orderBy('id')->limit(100)->pluck('id')->all()];
                }
            }
        }
        if ($violations !== []) {
            throw new RuntimeException('Document constraint preflight failed: '.json_encode($violations, JSON_THROW_ON_ERROR));
        }
        $this->timestampDefault(true);
        if (DB::getDriverName() === 'sqlite') {
            return; // Physical CHECK evidence requires the supported MariaDB harness.
        }
        foreach ($this->checks() as $table => $rules) {
            foreach ($rules as $name => $expression) {
                $exists = DB::table('information_schema.TABLE_CONSTRAINTS')->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
                    ->where('TABLE_NAME', $table)->where('CONSTRAINT_NAME', $name)->exists();
                if (! $exists) {
                    DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
                }
            }
        }
        DB::statement('ALTER TABLE media_extracted_regions DROP CONSTRAINT chk_mer_crop_complete, ADD CONSTRAINT chk_mer_crop_complete CHECK ('.$this->cropCheck().')');
    }

    private function timestampDefault(bool $current): void
    {
        // SQLite rebuilds the table for change(); preserve its append-only triggers.
        $triggers = DB::getDriverName() === 'sqlite'
            ? DB::select("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name = 'media_access_logs'") : [];
        Schema::table('media_access_logs', function (Blueprint $table) use ($current): void {
            $column = $table->timestamp('accessed_at');
            $current ? $column->useCurrent() : $column->default(null);
            $column->change();
        });
        foreach ($triggers as $trigger) {
            DB::unprepared($trigger->sql);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            foreach ($this->checks() as $table => $rules) {
                foreach (array_keys($rules) as $name) {
                    DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$name}");
                }
            }
            $old = str_replace('crop_width IS NOT NULL AND crop_height IS NOT NULL AND crop_bytes IS NOT NULL AND ', '', $this->cropCheck());
            DB::statement('ALTER TABLE media_extracted_regions DROP CONSTRAINT chk_mer_crop_complete, ADD CONSTRAINT chk_mer_crop_complete CHECK ('.$old.')');
        }
        $this->timestampDefault(false);
    }
};

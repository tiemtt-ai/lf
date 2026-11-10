<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-0028: course window columns hold dates past 2038, so they move from TIMESTAMP to
 * DATETIME on MySQL/MariaDB. The ALTER runs on the application connection, so MariaDB
 * converts with the same session time zone the application writes with and every value
 * reads back unchanged. Lesson unlock times saved before LessonUnlockTime were local wall
 * clock; they move to UTC. Published Version snapshots are immutable and stay as they are.
 */
return new class extends Migration
{
    private const NULLABLE = 'NULL DEFAULT NULL';

    /** @var array<string, array<string, string>> column => null and default clause */
    private const COLUMNS = [
        'core_course_enrollments' => [
            'enrolled_at' => 'NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'access_starts_at' => self::NULLABLE,
            'access_ends_at' => self::NULLABLE,
            'review_starts_at' => self::NULLABLE,
            'review_ends_at' => self::NULLABLE,
        ],
        'core_course_products' => [
            'sale_starts_at' => self::NULLABLE,
            'sale_ends_at' => self::NULLABLE,
            'available_from' => self::NULLABLE,
            'available_until' => self::NULLABLE,
            'registration_starts_at' => self::NULLABLE,
            'registration_ends_at' => self::NULLABLE,
        ],
        'core_course_template_lessons' => ['unlock_at' => self::NULLABLE],
        'core_course_template_activities' => ['unlock_at' => self::NULLABLE],
    ];

    public function up(): void
    {
        if ($this->usesMysql()) {
            $this->alter('DATETIME');
        }

        $this->shiftLessonUnlock(toUtc: true);
    }

    public function down(): void
    {
        if ($this->usesMysql()) {
            foreach (self::COLUMNS as $table => $columns) {
                foreach (array_keys($columns) as $column) {
                    $outside = DB::table($table)
                        ->where(fn ($query) => $query->where($column, '<', '1970-01-02 00:00:00')
                            ->orWhere($column, '>', '2038-01-18 23:59:59'))
                        ->exists();

                    if ($outside) {
                        throw new RuntimeException("{$table}.{$column} holds a value TIMESTAMP cannot store.");
                    }
                }
            }
        }

        $this->shiftLessonUnlock(toUtc: false);

        if ($this->usesMysql()) {
            $this->alter('TIMESTAMP');
        }
    }

    private function alter(string $type): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            $clauses = array_map(
                fn (string $column, string $clause): string => sprintf('MODIFY `%s` %s %s', $column, $type, $clause),
                array_keys($columns),
                $columns,
            );

            DB::statement(sprintf('ALTER TABLE `%s` %s', $table, implode(', ', $clauses)));
        }
    }

    private function shiftLessonUnlock(bool $toUtc): void
    {
        $local = (string) config('app.timezone');

        DB::table('core_course_template_lessons')
            ->whereNotNull('unlock_at')
            ->select(['id', 'unlock_at'])
            ->orderBy('id')
            ->chunkById(500, function ($lessons) use ($local, $toUtc): void {
                foreach ($lessons as $lesson) {
                    $value = $toUtc
                        ? Carbon::parse((string) $lesson->unlock_at, $local)->utc()
                        : Carbon::parse((string) $lesson->unlock_at, 'UTC')->setTimezone($local);

                    DB::table('core_course_template_lessons')
                        ->where('id', $lesson->id)
                        ->update(['unlock_at' => $value->format('Y-m-d H:i:s')]);
                }
            });
    }

    private function usesMysql(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $this->assertPrerequisites();

        try {
            DB::unprepared(<<<'SQL'
CREATE TABLE `core_liveclass_teacher_judgments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `customer_id` BIGINT UNSIGNED NOT NULL,
    `submission_uuid` CHAR(36) NOT NULL,
    `cohort_id` BIGINT UNSIGNED NOT NULL,
    `cohort_teacher_assignment_id` BIGINT UNSIGNED NOT NULL,
    `cohort_student_membership_id` BIGINT UNSIGNED NOT NULL,
    `enrollment_id` BIGINT UNSIGNED NOT NULL,
    `teacher_id` BIGINT UNSIGNED NOT NULL,
    `student_id` BIGINT UNSIGNED NOT NULL,
    `framework_id` BIGINT UNSIGNED NOT NULL,
    `basis_framework_version_id` BIGINT UNSIGNED NOT NULL,
    `learning_node_id` BIGINT UNSIGNED NOT NULL,
    `mastery_level_key` VARCHAR(100) NOT NULL,
    `mastery_score` DECIMAL(9,6) NULL,
    `reason` TEXT NOT NULL,
    `context_snapshot` JSON NOT NULL,
    `occurred_at` DATETIME(6) NOT NULL,
    `submitted_at` DATETIME(6) NOT NULL,
    `supersedes_judgment_id` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP(6) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_ltj_001` (`customer_id`,`submission_uuid`),
    UNIQUE KEY `idx_ltj_002` (`id`,`customer_id`),
    UNIQUE KEY `idx_ltj_003` (`customer_id`,`supersedes_judgment_id`),
    KEY `idx_ltj_004` (`customer_id`,`cohort_id`,`student_id`,`occurred_at`),
    KEY `idx_ltj_005` (`customer_id`,`learning_node_id`,`student_id`,`occurred_at`),
    CONSTRAINT `chk_ltj_001` CHECK (`mastery_score` IS NULL OR (`mastery_score` >= 0 AND `mastery_score` <= 1)),
    CONSTRAINT `chk_ltj_002` CHECK (TRIM(`reason`) <> ''),
    CONSTRAINT `chk_ltj_003` CHECK (JSON_VALID(`context_snapshot`)),
    CONSTRAINT `chk_ltj_004` CHECK (TRIM(`mastery_level_key`) <> ''),
    CONSTRAINT `chk_ltj_005` CHECK (`occurred_at` <= `submitted_at`),
    CONSTRAINT `fk_ltj_001` FOREIGN KEY (`customer_id`) REFERENCES `saas_customers` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_ltj_002` FOREIGN KEY (`cohort_id`,`customer_id`) REFERENCES `core_course_cohorts` (`id`,`customer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_ltj_003` FOREIGN KEY (`cohort_teacher_assignment_id`,`customer_id`) REFERENCES `core_course_cohort_teachers` (`id`,`customer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_ltj_004` FOREIGN KEY (`cohort_student_membership_id`,`customer_id`) REFERENCES `core_course_cohort_students` (`id`,`customer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_ltj_005` FOREIGN KEY (`enrollment_id`,`customer_id`) REFERENCES `core_course_enrollments` (`id`,`customer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_ltj_006` FOREIGN KEY (`teacher_id`,`customer_id`) REFERENCES `users` (`id`,`customer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_ltj_007` FOREIGN KEY (`student_id`,`customer_id`) REFERENCES `users` (`id`,`customer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_ltj_008` FOREIGN KEY (`basis_framework_version_id`,`customer_id`,`framework_id`) REFERENCES `core_learning_framework_versions` (`id`,`customer_id`,`framework_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_ltj_009` FOREIGN KEY (`learning_node_id`,`customer_id`,`framework_id`,`basis_framework_version_id`) REFERENCES `core_learning_nodes` (`id`,`customer_id`,`framework_id`,`framework_version_id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_ltj_010` FOREIGN KEY (`supersedes_judgment_id`,`customer_id`) REFERENCES `core_liveclass_teacher_judgments` (`id`,`customer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER `trg_ltj_bi_correction`
BEFORE INSERT ON `core_liveclass_teacher_judgments`
FOR EACH ROW
BEGIN
    IF NEW.supersedes_judgment_id IS NOT NULL AND NOT EXISTS (
        SELECT 1
        FROM core_liveclass_teacher_judgments prior
        WHERE prior.id = NEW.supersedes_judgment_id
          AND prior.customer_id <=> NEW.customer_id
          AND prior.cohort_id <=> NEW.cohort_id
          AND prior.cohort_student_membership_id <=> NEW.cohort_student_membership_id
          AND prior.enrollment_id <=> NEW.enrollment_id
          AND prior.student_id <=> NEW.student_id
          AND prior.framework_id <=> NEW.framework_id
          AND prior.basis_framework_version_id <=> NEW.basis_framework_version_id
          AND prior.learning_node_id <=> NEW.learning_node_id
          AND prior.occurred_at <=> NEW.occurred_at
          AND prior.submitted_at <= NEW.submitted_at
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'LF_TEACHER_JUDGMENT_CORRECTION_INVALID';
    END IF;
END
SQL);

            DB::unprepared(<<<'SQL'
CREATE TRIGGER `trg_ltj_bu_immutable`
BEFORE UPDATE ON `core_liveclass_teacher_judgments`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'LF_TEACHER_JUDGMENT_IMMUTABLE';
END
SQL);

            DB::unprepared(<<<'SQL'
CREATE TRIGGER `trg_ltj_bd_immutable`
BEFORE DELETE ON `core_liveclass_teacher_judgments`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'LF_TEACHER_JUDGMENT_IMMUTABLE';
END
SQL);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Teacher Judgment DDL stopped before ledger completion; use the approved interrupted-DDL recovery runbook.',
                0,
                $exception
            );
        }
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        if (! Schema::hasTable('core_liveclass_teacher_judgments')) {
            return;
        }

        if (DB::table('core_liveclass_teacher_judgments')->exists()) {
            throw new RuntimeException('Refusing to drop non-empty Teacher Judgment source table.');
        }

        if (Schema::hasTable('core_learning_evidence') && DB::table('core_learning_evidence')
            ->where('source_type', 'teacher_judgment')
            ->exists()) {
            throw new RuntimeException('Refusing to orphan Teacher Judgment Learning Evidence.');
        }

        $this->assertInstalledTriggerIdentity();

        DB::unprepared('DROP TRIGGER IF EXISTS `trg_ltj_bd_immutable`');
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_ltj_bu_immutable`');
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_ltj_bi_correction`');
        DB::unprepared('DROP TABLE `core_liveclass_teacher_judgments`');
    }

    private function assertPrerequisites(): void
    {
        if (Schema::hasTable('core_liveclass_teacher_judgments')) {
            throw new RuntimeException('core_liveclass_teacher_judgments already exists.');
        }

        foreach ([
            'saas_customers',
            'users',
            'core_course_cohorts',
            'core_course_cohort_teachers',
            'core_course_cohort_students',
            'core_course_enrollments',
            'core_learning_framework_versions',
            'core_learning_nodes',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Teacher Judgment prerequisite table is missing: {$table}.");
            }
        }

        foreach ([
            'saas_customers' => ['id'],
            'users' => ['id', 'customer_id'],
            'core_course_cohorts' => ['id', 'customer_id'],
            'core_course_cohort_teachers' => ['id', 'customer_id'],
            'core_course_cohort_students' => ['id', 'customer_id'],
            'core_course_enrollments' => ['id', 'customer_id'],
            'core_learning_framework_versions' => ['id', 'customer_id', 'framework_id'],
            'core_learning_nodes' => ['id', 'customer_id', 'framework_id', 'framework_version_id'],
        ] as $table => $columns) {
            if (! $this->hasExactUniqueKey($table, $columns)) {
                throw new RuntimeException("Teacher Judgment prerequisite key is missing: {$table} (".implode(', ', $columns).').');
            }
        }

        $triggerNames = ['trg_ltj_bi_correction', 'trg_ltj_bu_immutable', 'trg_ltj_bd_immutable'];
        $existing = DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::getDatabaseName())
            ->whereIn('TRIGGER_NAME', $triggerNames)
            ->pluck('TRIGGER_NAME');

        if ($existing->isNotEmpty()) {
            throw new RuntimeException('Teacher Judgment trigger name is already in use: '.$existing->implode(', ').'.');
        }
    }

    private function hasExactUniqueKey(string $table, array $columns): bool
    {
        $indexes = DB::select(
            <<<'SQL'
SELECT INDEX_NAME,
       GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS column_list
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = ?
  AND NON_UNIQUE = 0
GROUP BY INDEX_NAME
SQL,
            [$table]
        );

        $expected = implode(',', $columns);

        return collect($indexes)->contains(
            fn (object $index): bool => $index->column_list === $expected
        );
    }

    private function assertInstalledTriggerIdentity(): void
    {
        $expected = $this->expectedTriggerStatements();
        $rows = DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::getDatabaseName())
            ->where('EVENT_OBJECT_TABLE', 'core_liveclass_teacher_judgments')
            ->get(['TRIGGER_NAME', 'EVENT_OBJECT_TABLE', 'ACTION_STATEMENT'])
            ->keyBy('TRIGGER_NAME');

        if ($rows->keys()->sort()->values()->all() !== collect(array_keys($expected))->sort()->values()->all()) {
            throw new RuntimeException('Refusing rollback because Teacher Judgment trigger inventory drifted.');
        }

        foreach ($expected as $name => $statement) {
            $row = $rows->get($name);
            if ($row === null
                || $row->EVENT_OBJECT_TABLE !== 'core_liveclass_teacher_judgments'
                || $this->normalizeSql($row->ACTION_STATEMENT) !== $this->normalizeSql($statement)) {
                throw new RuntimeException("Refusing rollback because trigger identity drifted: {$name}.");
            }
        }
    }

    /** @return array<string, string> */
    private function expectedTriggerStatements(): array
    {
        return [
            'trg_ltj_bi_correction' => <<<'SQL'
BEGIN
    IF NEW.supersedes_judgment_id IS NOT NULL AND NOT EXISTS (
        SELECT 1
        FROM core_liveclass_teacher_judgments prior
        WHERE prior.id = NEW.supersedes_judgment_id
          AND prior.customer_id <=> NEW.customer_id
          AND prior.cohort_id <=> NEW.cohort_id
          AND prior.cohort_student_membership_id <=> NEW.cohort_student_membership_id
          AND prior.enrollment_id <=> NEW.enrollment_id
          AND prior.student_id <=> NEW.student_id
          AND prior.framework_id <=> NEW.framework_id
          AND prior.basis_framework_version_id <=> NEW.basis_framework_version_id
          AND prior.learning_node_id <=> NEW.learning_node_id
          AND prior.occurred_at <=> NEW.occurred_at
          AND prior.submitted_at <= NEW.submitted_at
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'LF_TEACHER_JUDGMENT_CORRECTION_INVALID';
    END IF;
END
SQL,
            'trg_ltj_bu_immutable' => <<<'SQL'
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'LF_TEACHER_JUDGMENT_IMMUTABLE';
END
SQL,
            'trg_ltj_bd_immutable' => <<<'SQL'
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'LF_TEACHER_JUDGMENT_IMMUTABLE';
END
SQL,
        ];
    }

    private function normalizeSql(string $sql): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $sql)));
    }
};

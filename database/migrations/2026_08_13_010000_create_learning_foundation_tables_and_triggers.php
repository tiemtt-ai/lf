<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = array (
  0 => 'core_learning_frameworks',
  1 => 'core_learning_framework_versions',
  2 => 'core_learning_node_definitions',
  3 => 'core_learning_nodes',
  4 => 'core_learning_node_relations',
  5 => 'core_learning_node_mappings',
  6 => 'core_learning_evidence',
  7 => 'core_learning_mastery_calculations',
  8 => 'core_learning_calculation_evidence',
  9 => 'core_learning_mastery_profiles',
);

    public function up(): void
    {
        // Physical enforcement here (named CHECK constraints, composite foreign
        // keys and the 24 trigger bodies) exists only on MySQL/MariaDB. The
        // SQLite test connection skips the migration exactly as down() does;
        // schema:drift refuses SQLite outright, so no contract target is lost.
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                throw new RuntimeException("Phase 4B/4C table already exists: {$table}");
            }
        }
        DB::unprepared('CREATE TABLE `core_learning_frameworks` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `customer_id` BIGINT UNSIGNED NOT NULL, `code` VARCHAR(100) NOT NULL, `name` VARCHAR(255) NOT NULL, `description` TEXT NULL, `default_mastery_scale_key` VARCHAR(100) NOT NULL, `default_mastery_scale_version` VARCHAR(50) NOT NULL, `default_mastery_scale` JSON NOT NULL, `status` VARCHAR(50) NOT NULL, `archived_at` TIMESTAMP(6) NULL, `archived_by` BIGINT UNSIGNED NULL, `created_by` BIGINT UNSIGNED NOT NULL, `updated_by` BIGINT UNSIGNED NOT NULL, `created_at` TIMESTAMP(6) NULL, `updated_at` TIMESTAMP(6) NULL, PRIMARY KEY (`id`), UNIQUE KEY `idx_lrn_001` (`customer_id`,`code`), UNIQUE KEY `idx_lrn_002` (`id`,`customer_id`), KEY `idx_lrn_003` (`customer_id`,`status`,`name`), CONSTRAINT `chk_lrn_001` CHECK (status in (\'active\',\'archived\')), CONSTRAINT `chk_lrn_002` CHECK (json_valid(default_mastery_scale)), CONSTRAINT `chk_lrn_003` CHECK ((status = \'active\' and archived_at is null and archived_by is null) or (status = \'archived\' and archived_at is not null and archived_by is not null))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::unprepared('CREATE TABLE `core_learning_framework_versions` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `customer_id` BIGINT UNSIGNED NOT NULL, `framework_id` BIGINT UNSIGNED NOT NULL, `version_number` INT UNSIGNED NOT NULL, `version_code` VARCHAR(100) NOT NULL, `title_snapshot` VARCHAR(255) NOT NULL, `description_snapshot` TEXT NULL, `mastery_scale_key` VARCHAR(100) NOT NULL, `mastery_scale_version` VARCHAR(50) NOT NULL, `mastery_scale_snapshot` JSON NOT NULL, `status` VARCHAR(50) NOT NULL, `published_at` TIMESTAMP(6) NULL, `deprecated_at` TIMESTAMP(6) NULL, `archived_at` TIMESTAMP(6) NULL, `published_by` BIGINT UNSIGNED NULL, `deprecated_by` BIGINT UNSIGNED NULL, `archived_by` BIGINT UNSIGNED NULL, `created_by` BIGINT UNSIGNED NOT NULL, `updated_by` BIGINT UNSIGNED NOT NULL, `created_at` TIMESTAMP(6) NULL, `updated_at` TIMESTAMP(6) NULL, PRIMARY KEY (`id`), UNIQUE KEY `idx_lrn_004` (`customer_id`,`framework_id`,`version_number`), UNIQUE KEY `idx_lrn_005` (`customer_id`,`framework_id`,`version_code`), UNIQUE KEY `idx_lrn_006` (`id`,`customer_id`,`framework_id`), KEY `idx_lrn_007` (`customer_id`,`framework_id`,`status`,`published_at`), CONSTRAINT `chk_lrn_004` CHECK (status in (\'draft_snapshot\',\'published\',\'deprecated\',\'archived\')), CONSTRAINT `chk_lrn_005` CHECK (json_valid(mastery_scale_snapshot))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::unprepared('CREATE TABLE `core_learning_node_definitions` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `customer_id` BIGINT UNSIGNED NOT NULL, `framework_id` BIGINT UNSIGNED NOT NULL, `code` VARCHAR(120) NOT NULL, `node_type` VARCHAR(50) NOT NULL, `canonical_name` VARCHAR(255) NOT NULL, `description` TEXT NULL, `status` VARCHAR(50) NOT NULL, `created_by` BIGINT UNSIGNED NOT NULL, `updated_by` BIGINT UNSIGNED NOT NULL, `created_at` TIMESTAMP(6) NULL, `updated_at` TIMESTAMP(6) NULL, PRIMARY KEY (`id`), UNIQUE KEY `idx_lrn_008` (`customer_id`,`framework_id`,`code`), UNIQUE KEY `idx_lrn_009` (`id`,`customer_id`,`framework_id`), KEY `idx_lrn_010` (`customer_id`,`framework_id`,`node_type`,`status`), CONSTRAINT `chk_lrn_006` CHECK (node_type in (\'objective\',\'concept\',\'competency\')), CONSTRAINT `chk_lrn_007` CHECK (status in (\'active\',\'archived\'))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::unprepared('CREATE TABLE `core_learning_nodes` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `customer_id` BIGINT UNSIGNED NOT NULL, `framework_id` BIGINT UNSIGNED NOT NULL, `framework_version_id` BIGINT UNSIGNED NOT NULL, `node_definition_id` BIGINT UNSIGNED NOT NULL, `code_snapshot` VARCHAR(120) NOT NULL, `name_snapshot` VARCHAR(255) NOT NULL, `description_snapshot` TEXT NULL, `criteria_snapshot` JSON NULL, `sequence` INT UNSIGNED NOT NULL, `status` VARCHAR(50) NOT NULL, `created_by` BIGINT UNSIGNED NOT NULL, `updated_by` BIGINT UNSIGNED NOT NULL, `created_at` TIMESTAMP(6) NULL, `updated_at` TIMESTAMP(6) NULL, PRIMARY KEY (`id`), UNIQUE KEY `idx_lrn_011` (`customer_id`,`node_definition_id`,`framework_version_id`), UNIQUE KEY `idx_lrn_012` (`id`,`customer_id`,`framework_id`,`framework_version_id`), UNIQUE KEY `idx_lrn_013` (`id`,`customer_id`), KEY `idx_lrn_014` (`customer_id`,`framework_version_id`,`sequence`), CONSTRAINT `chk_lrn_008` CHECK (status in (\'active\',\'retired\')), CONSTRAINT `chk_lrn_009` CHECK (json_valid(criteria_snapshot) or criteria_snapshot is null)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::unprepared('CREATE TABLE `core_learning_node_relations` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `customer_id` BIGINT UNSIGNED NOT NULL, `framework_id` BIGINT UNSIGNED NOT NULL, `owning_framework_version_id` BIGINT UNSIGNED NOT NULL, `relation_scope` VARCHAR(50) NOT NULL, `relation_type` VARCHAR(50) NOT NULL, `source_learning_node_id` BIGINT UNSIGNED NOT NULL, `target_learning_node_id` BIGINT UNSIGNED NOT NULL, `source_framework_version_id` BIGINT UNSIGNED NOT NULL, `target_framework_version_id` BIGINT UNSIGNED NOT NULL, `continuity_policy` VARCHAR(50) NULL, `continuity_policy_key` VARCHAR(100) NULL, `continuity_policy_version` VARCHAR(50) NULL, `continuity_policy_snapshot` JSON NULL, `review_status` VARCHAR(50) NOT NULL, `resolved_continuity_policy` VARCHAR(50) NULL, `approved_by` BIGINT UNSIGNED NULL, `approved_at` TIMESTAMP(6) NULL, `reviewed_by` BIGINT UNSIGNED NULL, `reviewed_at` TIMESTAMP(6) NULL, `review_reason` TEXT NULL, `created_by` BIGINT UNSIGNED NOT NULL, `created_at` TIMESTAMP(6) NULL, `updated_at` TIMESTAMP(6) NULL, PRIMARY KEY (`id`), UNIQUE KEY `idx_lrn_015` (`id`,`customer_id`,`framework_id`), UNIQUE KEY `idx_lrn_016` (`customer_id`,`relation_scope`,`source_learning_node_id`,`target_learning_node_id`), KEY `idx_lrn_017` (`customer_id`,`relation_scope`,`source_framework_version_id`,`target_framework_version_id`), KEY `idx_lrn_018` (`customer_id`,`target_learning_node_id`,`source_learning_node_id`), CONSTRAINT `chk_lrn_010` CHECK (source_learning_node_id <> target_learning_node_id), CONSTRAINT `chk_lrn_011` CHECK (relation_scope in (\'semantic\',\'version_transition\')), CONSTRAINT `chk_lrn_012` CHECK (review_status in (\'not_required\',\'pending\',\'approved\',\'rejected\')), CONSTRAINT `chk_lrn_013` CHECK ((relation_scope = \'semantic\' and source_framework_version_id = target_framework_version_id and owning_framework_version_id = source_framework_version_id) or (relation_scope = \'version_transition\' and source_framework_version_id <> target_framework_version_id and owning_framework_version_id = target_framework_version_id))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::unprepared('CREATE TABLE `core_learning_node_mappings` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `customer_id` BIGINT UNSIGNED NOT NULL, `learning_node_id` BIGINT UNSIGNED NOT NULL, `source_type` VARCHAR(100) NOT NULL, `source_id` BIGINT UNSIGNED NOT NULL, `source_discriminator` VARCHAR(191) NOT NULL, `mapping_role` VARCHAR(50) NOT NULL, `weight` DECIMAL(9,6) NULL, `source_snapshot` JSON NOT NULL, `created_by` BIGINT UNSIGNED NOT NULL, `created_at` TIMESTAMP(6) NULL, `invalidated_at` TIMESTAMP(6) NULL, `invalidated_by` BIGINT UNSIGNED NULL, `invalidation_reason` TEXT NULL, PRIMARY KEY (`id`), UNIQUE KEY `idx_lrn_019` (`customer_id`,`source_type`,`source_id`,`source_discriminator`,`learning_node_id`,`mapping_role`), KEY `idx_lrn_020` (`customer_id`,`learning_node_id`,`mapping_role`), KEY `idx_lrn_021` (`customer_id`,`source_type`,`source_id`,`source_discriminator`), CONSTRAINT `chk_lrn_014` CHECK (source_type in (\'course_version_lesson\',\'course_version_activity\')), CONSTRAINT `chk_lrn_015` CHECK (mapping_role in (\'teaches\',\'practices\',\'assesses\')), CONSTRAINT `chk_lrn_016` CHECK (weight is null or (weight >= 0 and weight <= 1)), CONSTRAINT `chk_lrn_017` CHECK ((invalidated_at is null and invalidated_by is null and invalidation_reason is null) or (invalidated_at is not null and invalidated_by is not null and invalidation_reason is not null))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::unprepared('CREATE TABLE `core_learning_evidence` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `customer_id` BIGINT UNSIGNED NOT NULL, `user_id` BIGINT UNSIGNED NOT NULL, `learning_node_id` BIGINT UNSIGNED NOT NULL, `evidence_type` VARCHAR(50) NOT NULL, `source_type` VARCHAR(100) NOT NULL, `source_id` BIGINT UNSIGNED NOT NULL, `source_discriminator` VARCHAR(191) NOT NULL, `producer_idempotency_key` VARCHAR(191) NOT NULL, `source_occurred_at` DATETIME(6) NOT NULL, `evaluated_at` DATETIME(6) NOT NULL, `value_numeric` DECIMAL(18,6) NULL, `value_label` VARCHAR(100) NULL, `qualification_rule_key` VARCHAR(100) NOT NULL, `qualification_rule_version` VARCHAR(50) NOT NULL, `qualification_rule_snapshot` JSON NOT NULL, `valid_from` TIMESTAMP(6) NULL, `valid_until` TIMESTAMP(6) NULL, `reassessment_due_at` TIMESTAMP(6) NULL, `supersedes_evidence_id` BIGINT UNSIGNED NULL, `recorded_by` BIGINT UNSIGNED NULL, `created_at` TIMESTAMP(6) NULL, PRIMARY KEY (`id`), UNIQUE KEY `idx_lrn_022` (`customer_id`,`source_type`,`producer_idempotency_key`,`learning_node_id`,`user_id`,`evidence_type`), UNIQUE KEY `idx_lrn_023` (`id`,`customer_id`,`user_id`), UNIQUE KEY `idx_lrn_024` (`customer_id`,`supersedes_evidence_id`), KEY `idx_lrn_025` (`customer_id`,`user_id`,`learning_node_id`,`evaluated_at`), KEY `idx_lrn_026` (`customer_id`,`valid_until`,`reassessment_due_at`), CONSTRAINT `chk_lrn_018` CHECK (value_numeric is not null or value_label is not null), CONSTRAINT `chk_lrn_019` CHECK (valid_until is null or valid_from is null or valid_from <= valid_until), CONSTRAINT `chk_lrn_020` CHECK (evidence_type <> \'expert_judgment\' or (source_type = \'teacher_judgment\' and recorded_by is not null))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::unprepared('CREATE TABLE `core_learning_mastery_calculations` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `customer_id` BIGINT UNSIGNED NOT NULL, `user_id` BIGINT UNSIGNED NOT NULL, `framework_id` BIGINT UNSIGNED NOT NULL, `node_definition_id` BIGINT UNSIGNED NOT NULL, `basis_framework_version_id` BIGINT UNSIGNED NOT NULL, `calculation_source` VARCHAR(50) NOT NULL, `calculation_idempotency_key` VARCHAR(191) NOT NULL, `mastery_level_key` VARCHAR(100) NOT NULL, `mastery_score` DECIMAL(9,6) NULL, `calculation_rule_key` VARCHAR(100) NOT NULL, `calculation_rule_version` VARCHAR(50) NOT NULL, `calculation_rule_snapshot` JSON NOT NULL, `mastery_scale_key` VARCHAR(100) NOT NULL, `mastery_scale_version` VARCHAR(50) NOT NULL, `mastery_scale_snapshot` JSON NOT NULL, `continuity_policy_snapshot` JSON NULL, `source_node_relation_id` BIGINT UNSIGNED NULL, `source_calculation_id` BIGINT UNSIGNED NULL, `mastery_status_result` VARCHAR(50) NOT NULL, `reassessment_due_at` TIMESTAMP(6) NULL, `reason` TEXT NULL, `calculated_at` DATETIME(6) NOT NULL, `calculated_by` BIGINT UNSIGNED NULL, `created_at` TIMESTAMP(6) NULL, PRIMARY KEY (`id`), UNIQUE KEY `idx_lrn_027` (`id`,`customer_id`,`user_id`), UNIQUE KEY `idx_lrn_028` (`id`,`customer_id`,`user_id`,`node_definition_id`,`basis_framework_version_id`), UNIQUE KEY `idx_lrn_029` (`customer_id`,`calculation_source`,`calculation_idempotency_key`), KEY `idx_lrn_030` (`customer_id`,`user_id`,`node_definition_id`,`basis_framework_version_id`,`calculated_at`), KEY `idx_lrn_031` (`customer_id`,`source_calculation_id`), CONSTRAINT `chk_lrn_021` CHECK (calculation_source in (\'system\',\'teacher_override\',\'carry_forward\')), CONSTRAINT `chk_lrn_022` CHECK (mastery_status_result in (\'established\',\'needs_review\',\'reassessment_due\')), CONSTRAINT `chk_lrn_023` CHECK (json_valid(calculation_rule_snapshot)), CONSTRAINT `chk_lrn_024` CHECK (json_valid(mastery_scale_snapshot)), CONSTRAINT `chk_lrn_025` CHECK (calculation_source <> \'teacher_override\' or (calculated_by is not null and reason is not null)), CONSTRAINT `chk_lrn_026` CHECK (calculation_source <> \'carry_forward\' or (source_calculation_id is not null and source_node_relation_id is not null and continuity_policy_snapshot is not null))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::unprepared('CREATE TABLE `core_learning_calculation_evidence` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `customer_id` BIGINT UNSIGNED NOT NULL, `user_id` BIGINT UNSIGNED NOT NULL, `mastery_calculation_id` BIGINT UNSIGNED NOT NULL, `evidence_id` BIGINT UNSIGNED NOT NULL, `evidence_role` VARCHAR(50) NOT NULL, `effective_weight` DECIMAL(9,6) NOT NULL, `contribution` DECIMAL(18,6) NULL, `reason_code` VARCHAR(100) NOT NULL, `reason_snapshot` JSON NULL, `created_at` TIMESTAMP(6) NULL, PRIMARY KEY (`id`), UNIQUE KEY `idx_lrn_032` (`customer_id`,`mastery_calculation_id`,`evidence_id`), KEY `idx_lrn_033` (`customer_id`,`user_id`,`evidence_id`,`mastery_calculation_id`), CONSTRAINT `chk_lrn_027` CHECK (effective_weight >= 0), CONSTRAINT `chk_lrn_028` CHECK (evidence_role in (\'included\',\'excluded\',\'continuity_input\')), CONSTRAINT `chk_lrn_029` CHECK (evidence_role <> \'excluded\' or (effective_weight = 0 and reason_code <> \'\'))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::unprepared('CREATE TABLE `core_learning_mastery_profiles` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `customer_id` BIGINT UNSIGNED NOT NULL, `user_id` BIGINT UNSIGNED NOT NULL, `framework_id` BIGINT UNSIGNED NOT NULL, `node_definition_id` BIGINT UNSIGNED NOT NULL, `basis_framework_version_id` BIGINT UNSIGNED NOT NULL, `current_calculation_id` BIGINT UNSIGNED NOT NULL, `mastery_level_key` VARCHAR(100) NOT NULL, `mastery_score` DECIMAL(9,6) NULL, `mastery_status` VARCHAR(50) NOT NULL, `calculated_at` DATETIME(6) NOT NULL, `reassessment_due_at` TIMESTAMP(6) NULL, `projected_at` DATETIME(6) NOT NULL, `created_at` TIMESTAMP(6) NULL, `updated_at` TIMESTAMP(6) NULL, PRIMARY KEY (`id`), UNIQUE KEY `idx_lrn_034` (`customer_id`,`user_id`,`node_definition_id`,`basis_framework_version_id`), UNIQUE KEY `idx_lrn_035` (`customer_id`,`current_calculation_id`), KEY `idx_lrn_036` (`customer_id`,`user_id`,`basis_framework_version_id`,`mastery_status`), CONSTRAINT `chk_lrn_030` CHECK (mastery_status in (\'established\',\'needs_review\',\'reassessment_due\'))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::unprepared('ALTER TABLE `core_learning_frameworks` ADD CONSTRAINT `fk_lrn_001` FOREIGN KEY (`customer_id`) REFERENCES `saas_customers` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_frameworks` ADD CONSTRAINT `fk_lrn_002` FOREIGN KEY (`created_by`,`customer_id`) REFERENCES `users` (`id`,`customer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_frameworks` ADD CONSTRAINT `fk_lrn_003` FOREIGN KEY (`updated_by`,`customer_id`) REFERENCES `users` (`id`,`customer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_frameworks` ADD CONSTRAINT `fk_lrn_004` FOREIGN KEY (`archived_by`,`customer_id`) REFERENCES `users` (`id`,`customer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_framework_versions` ADD CONSTRAINT `fk_lrn_005` FOREIGN KEY (`customer_id`) REFERENCES `saas_customers` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_framework_versions` ADD CONSTRAINT `fk_lrn_006` FOREIGN KEY (`framework_id`,`customer_id`) REFERENCES `core_learning_frameworks` (`id`,`customer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_framework_versions` ADD CONSTRAINT `fk_lrn_007` FOREIGN KEY (`published_by`,`customer_id`) REFERENCES `users` (`id`,`customer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_framework_versions` ADD CONSTRAINT `fk_lrn_008` FOREIGN KEY (`deprecated_by`,`customer_id`) REFERENCES `users` (`id`,`customer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_framework_versions` ADD CONSTRAINT `fk_lrn_009` FOREIGN KEY (`archived_by`,`customer_id`) REFERENCES `users` (`id`,`customer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_framework_versions` ADD CONSTRAINT `fk_lrn_010` FOREIGN KEY (`created_by`,`customer_id`) REFERENCES `users` (`id`,`customer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_framework_versions` ADD CONSTRAINT `fk_lrn_011` FOREIGN KEY (`updated_by`,`customer_id`) REFERENCES `users` (`id`,`customer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_node_definitions` ADD CONSTRAINT `fk_lrn_012` FOREIGN KEY (`customer_id`) REFERENCES `saas_customers` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_node_definitions` ADD CONSTRAINT `fk_lrn_013` FOREIGN KEY (`framework_id`,`customer_id`) REFERENCES `core_learning_frameworks` (`id`,`customer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_node_definitions` ADD CONSTRAINT `fk_lrn_014` FOREIGN KEY (`created_by`,`customer_id`) REFERENCES `users` (`id`,`customer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_node_definitions` ADD CONSTRAINT `fk_lrn_015` FOREIGN KEY (`updated_by`,`customer_id`) REFERENCES `users` (`id`,`customer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_nodes` ADD CONSTRAINT `fk_lrn_016` FOREIGN KEY (`customer_id`) REFERENCES `saas_customers` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_nodes` ADD CONSTRAINT `fk_lrn_017` FOREIGN KEY (`node_definition_id`,`customer_id`,`framework_id`) REFERENCES `core_learning_node_definitions` (`id`,`customer_id`,`framework_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_nodes` ADD CONSTRAINT `fk_lrn_018` FOREIGN KEY (`framework_version_id`,`customer_id`,`framework_id`) REFERENCES `core_learning_framework_versions` (`id`,`customer_id`,`framework_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_nodes` ADD CONSTRAINT `fk_lrn_019` FOREIGN KEY (`created_by`,`customer_id`) REFERENCES `users` (`id`,`customer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_nodes` ADD CONSTRAINT `fk_lrn_020` FOREIGN KEY (`updated_by`,`customer_id`) REFERENCES `users` (`id`,`customer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_node_relations` ADD CONSTRAINT `fk_lrn_021` FOREIGN KEY (`customer_id`) REFERENCES `saas_customers` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_node_relations` ADD CONSTRAINT `fk_lrn_022` FOREIGN KEY (`owning_framework_version_id`,`customer_id`,`framework_id`) REFERENCES `core_learning_framework_versions` (`id`,`customer_id`,`framework_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_node_relations` ADD CONSTRAINT `fk_lrn_023` FOREIGN KEY (`source_learning_node_id`,`customer_id`,`framework_id`,`source_framework_version_id`) REFERENCES `core_learning_nodes` (`id`,`customer_id`,`framework_id`,`framework_version_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_node_relations` ADD CONSTRAINT `fk_lrn_024` FOREIGN KEY (`target_learning_node_id`,`customer_id`,`framework_id`,`target_framework_version_id`) REFERENCES `core_learning_nodes` (`id`,`customer_id`,`framework_id`,`framework_version_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_node_relations` ADD CONSTRAINT `fk_lrn_025` FOREIGN KEY (`approved_by`,`customer_id`) REFERENCES `users` (`id`,`customer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_node_relations` ADD CONSTRAINT `fk_lrn_026` FOREIGN KEY (`reviewed_by`,`customer_id`) REFERENCES `users` (`id`,`customer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_node_relations` ADD CONSTRAINT `fk_lrn_027` FOREIGN KEY (`created_by`,`customer_id`) REFERENCES `users` (`id`,`customer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_node_mappings` ADD CONSTRAINT `fk_lrn_028` FOREIGN KEY (`customer_id`) REFERENCES `saas_customers` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_node_mappings` ADD CONSTRAINT `fk_lrn_029` FOREIGN KEY (`learning_node_id`,`customer_id`) REFERENCES `core_learning_nodes` (`id`,`customer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_node_mappings` ADD CONSTRAINT `fk_lrn_030` FOREIGN KEY (`created_by`,`customer_id`) REFERENCES `users` (`id`,`customer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_node_mappings` ADD CONSTRAINT `fk_lrn_031` FOREIGN KEY (`invalidated_by`,`customer_id`) REFERENCES `users` (`id`,`customer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_evidence` ADD CONSTRAINT `fk_lrn_032` FOREIGN KEY (`customer_id`) REFERENCES `saas_customers` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_evidence` ADD CONSTRAINT `fk_lrn_033` FOREIGN KEY (`user_id`,`customer_id`) REFERENCES `users` (`id`,`customer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_evidence` ADD CONSTRAINT `fk_lrn_034` FOREIGN KEY (`recorded_by`,`customer_id`) REFERENCES `users` (`id`,`customer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_evidence` ADD CONSTRAINT `fk_lrn_035` FOREIGN KEY (`learning_node_id`,`customer_id`) REFERENCES `core_learning_nodes` (`id`,`customer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_evidence` ADD CONSTRAINT `fk_lrn_036` FOREIGN KEY (`supersedes_evidence_id`,`customer_id`,`user_id`) REFERENCES `core_learning_evidence` (`id`,`customer_id`,`user_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_mastery_calculations` ADD CONSTRAINT `fk_lrn_037` FOREIGN KEY (`customer_id`) REFERENCES `saas_customers` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_mastery_calculations` ADD CONSTRAINT `fk_lrn_038` FOREIGN KEY (`user_id`,`customer_id`) REFERENCES `users` (`id`,`customer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_mastery_calculations` ADD CONSTRAINT `fk_lrn_039` FOREIGN KEY (`calculated_by`,`customer_id`) REFERENCES `users` (`id`,`customer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_mastery_calculations` ADD CONSTRAINT `fk_lrn_040` FOREIGN KEY (`node_definition_id`,`customer_id`,`framework_id`) REFERENCES `core_learning_node_definitions` (`id`,`customer_id`,`framework_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_mastery_calculations` ADD CONSTRAINT `fk_lrn_041` FOREIGN KEY (`basis_framework_version_id`,`customer_id`,`framework_id`) REFERENCES `core_learning_framework_versions` (`id`,`customer_id`,`framework_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_mastery_calculations` ADD CONSTRAINT `fk_lrn_042` FOREIGN KEY (`source_node_relation_id`,`customer_id`,`framework_id`) REFERENCES `core_learning_node_relations` (`id`,`customer_id`,`framework_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_mastery_calculations` ADD CONSTRAINT `fk_lrn_043` FOREIGN KEY (`source_calculation_id`,`customer_id`,`user_id`) REFERENCES `core_learning_mastery_calculations` (`id`,`customer_id`,`user_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_calculation_evidence` ADD CONSTRAINT `fk_lrn_044` FOREIGN KEY (`customer_id`) REFERENCES `saas_customers` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_calculation_evidence` ADD CONSTRAINT `fk_lrn_045` FOREIGN KEY (`mastery_calculation_id`,`customer_id`,`user_id`) REFERENCES `core_learning_mastery_calculations` (`id`,`customer_id`,`user_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_calculation_evidence` ADD CONSTRAINT `fk_lrn_046` FOREIGN KEY (`evidence_id`,`customer_id`,`user_id`) REFERENCES `core_learning_evidence` (`id`,`customer_id`,`user_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_mastery_profiles` ADD CONSTRAINT `fk_lrn_047` FOREIGN KEY (`customer_id`) REFERENCES `saas_customers` (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_mastery_profiles` ADD CONSTRAINT `fk_lrn_048` FOREIGN KEY (`user_id`,`customer_id`) REFERENCES `users` (`id`,`customer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_mastery_profiles` ADD CONSTRAINT `fk_lrn_049` FOREIGN KEY (`node_definition_id`,`customer_id`,`framework_id`) REFERENCES `core_learning_node_definitions` (`id`,`customer_id`,`framework_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_mastery_profiles` ADD CONSTRAINT `fk_lrn_050` FOREIGN KEY (`basis_framework_version_id`,`customer_id`,`framework_id`) REFERENCES `core_learning_framework_versions` (`id`,`customer_id`,`framework_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('ALTER TABLE `core_learning_mastery_profiles` ADD CONSTRAINT `fk_lrn_051` FOREIGN KEY (`current_calculation_id`,`customer_id`,`user_id`,`node_definition_id`,`basis_framework_version_id`) REFERENCES `core_learning_mastery_calculations` (`id`,`customer_id`,`user_id`,`node_definition_id`,`basis_framework_version_id`) ON UPDATE RESTRICT ON DELETE RESTRICT');
        DB::unprepared('CREATE TRIGGER trg_lrn_frameworks_bi_scale BEFORE INSERT ON core_learning_frameworks FOR EACH ROW
BEGIN
    DECLARE scale_index INT DEFAULT 0;
    DECLARE scale_count INT;
    DECLARE scale_key VARCHAR(255);
    DECLARE scale_threshold DECIMAL(18,6);
    IF JSON_VALID(NEW.default_mastery_scale) = 0
       OR COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.default_mastery_scale, \'$.levels\')), \'\') <> \'ARRAY\'
       OR COALESCE(JSON_LENGTH(JSON_EXTRACT(NEW.default_mastery_scale, \'$.levels\')), 0) < 2 THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_SCALE_INVALID\';
    END IF;
    SET scale_count = JSON_LENGTH(JSON_EXTRACT(NEW.default_mastery_scale, \'$.levels\'));
    WHILE scale_index < scale_count DO
        SET scale_key = JSON_UNQUOTE(JSON_EXTRACT(NEW.default_mastery_scale, CONCAT(\'$.levels[\',scale_index,\'].key\')));
        SET scale_threshold = CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.default_mastery_scale, CONCAT(\'$.levels[\',scale_index,\'].threshold\'))) AS DECIMAL(18,6));
        IF COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.default_mastery_scale, CONCAT(\'$.levels[\',scale_index,\'].key\'))), \'\') <> \'STRING\'
           OR NULLIF(TRIM(scale_key), \'\') IS NULL
           OR COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.default_mastery_scale, CONCAT(\'$.levels[\',scale_index,\'].threshold\'))), \'\') NOT IN (\'INTEGER\',\'DOUBLE\')
           OR (scale_index > 0 AND scale_threshold <= CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.default_mastery_scale, CONCAT(\'$.levels[\',scale_index - 1,\'].threshold\'))) AS DECIMAL(18,6)))
           OR JSON_UNQUOTE(JSON_SEARCH(NEW.default_mastery_scale, \'one\', scale_key, NULL, \'$.levels[*].key\')) <> CONCAT(\'$.levels[\',scale_index,\'].key\') THEN
            SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_SCALE_INVALID\';
        END IF;
        SET scale_index = scale_index + 1;
    END WHILE;
    IF NEW.status <> \'active\' OR NEW.archived_at IS NOT NULL OR NEW.archived_by IS NOT NULL THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_FRAMEWORK_LIFECYCLE_INVALID\';
    END IF;
END;');
        DB::unprepared('CREATE TRIGGER trg_lrn_frameworks_bu_scale BEFORE UPDATE ON core_learning_frameworks FOR EACH ROW
BEGIN
    DECLARE scale_index INT DEFAULT 0;
    DECLARE scale_count INT;
    DECLARE scale_key VARCHAR(255);
    DECLARE scale_threshold DECIMAL(18,6);
    IF JSON_VALID(NEW.default_mastery_scale) = 0
       OR COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.default_mastery_scale, \'$.levels\')), \'\') <> \'ARRAY\'
       OR COALESCE(JSON_LENGTH(JSON_EXTRACT(NEW.default_mastery_scale, \'$.levels\')), 0) < 2 THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_SCALE_INVALID\';
    END IF;
    SET scale_count = JSON_LENGTH(JSON_EXTRACT(NEW.default_mastery_scale, \'$.levels\'));
    WHILE scale_index < scale_count DO
        SET scale_key = JSON_UNQUOTE(JSON_EXTRACT(NEW.default_mastery_scale, CONCAT(\'$.levels[\',scale_index,\'].key\')));
        SET scale_threshold = CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.default_mastery_scale, CONCAT(\'$.levels[\',scale_index,\'].threshold\'))) AS DECIMAL(18,6));
        IF COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.default_mastery_scale, CONCAT(\'$.levels[\',scale_index,\'].key\'))), \'\') <> \'STRING\'
           OR NULLIF(TRIM(scale_key), \'\') IS NULL
           OR COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.default_mastery_scale, CONCAT(\'$.levels[\',scale_index,\'].threshold\'))), \'\') NOT IN (\'INTEGER\',\'DOUBLE\')
           OR (scale_index > 0 AND scale_threshold <= CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.default_mastery_scale, CONCAT(\'$.levels[\',scale_index - 1,\'].threshold\'))) AS DECIMAL(18,6)))
           OR JSON_UNQUOTE(JSON_SEARCH(NEW.default_mastery_scale, \'one\', scale_key, NULL, \'$.levels[*].key\')) <> CONCAT(\'$.levels[\',scale_index,\'].key\') THEN
            SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_SCALE_INVALID\';
        END IF;
        SET scale_index = scale_index + 1;
    END WHILE;
    IF NOT ((OLD.status = \'active\' AND NEW.status = \'active\'
             AND NEW.archived_at IS NULL AND NEW.archived_by IS NULL)
         OR (OLD.status = \'active\' AND NEW.status = \'archived\'
             AND NEW.archived_at IS NOT NULL AND NEW.archived_by IS NOT NULL)
         OR (OLD.status = \'archived\' AND NEW.status = \'archived\'
             AND NEW.archived_at <=> OLD.archived_at
             AND NEW.archived_by <=> OLD.archived_by)) THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_FRAMEWORK_LIFECYCLE_INVALID\';
    END IF;
    IF OLD.status = \'archived\' AND (
       NOT (NEW.customer_id <=> OLD.customer_id) OR NOT (NEW.code <=> OLD.code)
       OR NOT (NEW.name <=> OLD.name) OR NOT (NEW.description <=> OLD.description)
       OR NOT (NEW.default_mastery_scale_key <=> OLD.default_mastery_scale_key)
       OR NOT (NEW.default_mastery_scale_version <=> OLD.default_mastery_scale_version)
       OR NOT (NEW.default_mastery_scale <=> OLD.default_mastery_scale)
       OR NOT (NEW.created_by <=> OLD.created_by) OR NOT (NEW.updated_by <=> OLD.updated_by)
       OR NOT (NEW.created_at <=> OLD.created_at) OR NOT (NEW.updated_at <=> OLD.updated_at)) THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_FRAMEWORK_LIFECYCLE_INVALID\';
    END IF;
END;');
        DB::unprepared('CREATE TRIGGER trg_lrn_fw_versions_bi_validate BEFORE INSERT ON core_learning_framework_versions FOR EACH ROW
BEGIN
    DECLARE scale_index INT DEFAULT 0;
    DECLARE scale_count INT;
    DECLARE scale_key VARCHAR(255);
    DECLARE scale_threshold DECIMAL(18,6);
    IF JSON_VALID(NEW.mastery_scale_snapshot) = 0
       OR COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.mastery_scale_snapshot, \'$.levels\')), \'\') <> \'ARRAY\'
       OR COALESCE(JSON_LENGTH(JSON_EXTRACT(NEW.mastery_scale_snapshot, \'$.levels\')), 0) < 2 THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_SCALE_INVALID\';
    END IF;
    SET scale_count = JSON_LENGTH(JSON_EXTRACT(NEW.mastery_scale_snapshot, \'$.levels\'));
    WHILE scale_index < scale_count DO
        SET scale_key = JSON_UNQUOTE(JSON_EXTRACT(NEW.mastery_scale_snapshot, CONCAT(\'$.levels[\',scale_index,\'].key\')));
        SET scale_threshold = CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.mastery_scale_snapshot, CONCAT(\'$.levels[\',scale_index,\'].threshold\'))) AS DECIMAL(18,6));
        IF COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.mastery_scale_snapshot, CONCAT(\'$.levels[\',scale_index,\'].key\'))), \'\') <> \'STRING\'
           OR NULLIF(TRIM(scale_key), \'\') IS NULL
           OR COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.mastery_scale_snapshot, CONCAT(\'$.levels[\',scale_index,\'].threshold\'))), \'\') NOT IN (\'INTEGER\',\'DOUBLE\')
           OR (scale_index > 0 AND scale_threshold <= CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.mastery_scale_snapshot, CONCAT(\'$.levels[\',scale_index - 1,\'].threshold\'))) AS DECIMAL(18,6)))
           OR JSON_UNQUOTE(JSON_SEARCH(NEW.mastery_scale_snapshot, \'one\', scale_key, NULL, \'$.levels[*].key\')) <> CONCAT(\'$.levels[\',scale_index,\'].key\') THEN
            SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_SCALE_INVALID\';
        END IF;
        SET scale_index = scale_index + 1;
    END WHILE;
    IF NEW.status <> \'draft_snapshot\' OR NEW.published_at IS NOT NULL
       OR NEW.deprecated_at IS NOT NULL OR NEW.archived_at IS NOT NULL
       OR NEW.published_by IS NOT NULL OR NEW.deprecated_by IS NOT NULL
       OR NEW.archived_by IS NOT NULL THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_VERSION_LIFECYCLE_INVALID\';
    END IF;
END;');
        DB::unprepared('CREATE TRIGGER trg_lrn_fw_versions_bu_immutable BEFORE UPDATE ON core_learning_framework_versions FOR EACH ROW
BEGIN
    DECLARE scale_index INT DEFAULT 0;
    DECLARE scale_count INT;
    DECLARE scale_key VARCHAR(255);
    DECLARE scale_threshold DECIMAL(18,6);
    IF JSON_VALID(NEW.mastery_scale_snapshot) = 0
       OR COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.mastery_scale_snapshot, \'$.levels\')), \'\') <> \'ARRAY\'
       OR COALESCE(JSON_LENGTH(JSON_EXTRACT(NEW.mastery_scale_snapshot, \'$.levels\')), 0) < 2 THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_SCALE_INVALID\';
    END IF;
    SET scale_count = JSON_LENGTH(JSON_EXTRACT(NEW.mastery_scale_snapshot, \'$.levels\'));
    WHILE scale_index < scale_count DO
        SET scale_key = JSON_UNQUOTE(JSON_EXTRACT(NEW.mastery_scale_snapshot, CONCAT(\'$.levels[\',scale_index,\'].key\')));
        SET scale_threshold = CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.mastery_scale_snapshot, CONCAT(\'$.levels[\',scale_index,\'].threshold\'))) AS DECIMAL(18,6));
        IF COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.mastery_scale_snapshot, CONCAT(\'$.levels[\',scale_index,\'].key\'))), \'\') <> \'STRING\'
           OR NULLIF(TRIM(scale_key), \'\') IS NULL
           OR COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.mastery_scale_snapshot, CONCAT(\'$.levels[\',scale_index,\'].threshold\'))), \'\') NOT IN (\'INTEGER\',\'DOUBLE\')
           OR (scale_index > 0 AND scale_threshold <= CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.mastery_scale_snapshot, CONCAT(\'$.levels[\',scale_index - 1,\'].threshold\'))) AS DECIMAL(18,6)))
           OR JSON_UNQUOTE(JSON_SEARCH(NEW.mastery_scale_snapshot, \'one\', scale_key, NULL, \'$.levels[*].key\')) <> CONCAT(\'$.levels[\',scale_index,\'].key\') THEN
            SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_SCALE_INVALID\';
        END IF;
        SET scale_index = scale_index + 1;
    END WHILE;
    IF NOT (NEW.customer_id <=> OLD.customer_id)
       OR NOT (NEW.framework_id <=> OLD.framework_id)
       OR NOT (NEW.version_number <=> OLD.version_number)
       OR NOT (NEW.version_code <=> OLD.version_code)
       OR NOT (NEW.created_by <=> OLD.created_by)
       OR NOT (NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_VERSION_IMMUTABLE\';
    END IF;
    IF OLD.status <> \'draft_snapshot\' AND (
       NOT (NEW.title_snapshot <=> OLD.title_snapshot)
       OR NOT (NEW.description_snapshot <=> OLD.description_snapshot)
       OR NOT (NEW.mastery_scale_key <=> OLD.mastery_scale_key)
       OR NOT (NEW.mastery_scale_version <=> OLD.mastery_scale_version)
       OR NOT (NEW.mastery_scale_snapshot <=> OLD.mastery_scale_snapshot)
       OR NOT (NEW.updated_by <=> OLD.updated_by)
       OR NOT (NEW.updated_at <=> OLD.updated_at)) THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_VERSION_IMMUTABLE\';
    END IF;
    IF NOT ((OLD.status = \'draft_snapshot\' AND NEW.status = \'draft_snapshot\'
             AND NEW.published_at IS NULL AND NEW.published_by IS NULL
             AND NEW.deprecated_at IS NULL AND NEW.deprecated_by IS NULL
             AND NEW.archived_at IS NULL AND NEW.archived_by IS NULL)
         OR (OLD.status = \'draft_snapshot\' AND NEW.status = \'published\'
             AND NEW.published_at IS NOT NULL AND NEW.published_by IS NOT NULL
             AND NEW.deprecated_at IS NULL AND NEW.deprecated_by IS NULL
             AND NEW.archived_at IS NULL AND NEW.archived_by IS NULL)
         OR (OLD.status = \'published\' AND NEW.status = \'deprecated\'
             AND NEW.published_at <=> OLD.published_at
             AND NEW.published_by <=> OLD.published_by
             AND NEW.deprecated_at IS NOT NULL AND NEW.deprecated_by IS NOT NULL
             AND NEW.archived_at IS NULL AND NEW.archived_by IS NULL)
         OR (OLD.status = \'deprecated\' AND NEW.status = \'archived\'
             AND NEW.published_at <=> OLD.published_at
             AND NEW.published_by <=> OLD.published_by
             AND NEW.deprecated_at <=> OLD.deprecated_at
             AND NEW.deprecated_by <=> OLD.deprecated_by
             AND NEW.archived_at IS NOT NULL AND NEW.archived_by IS NOT NULL)) THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_VERSION_LIFECYCLE_INVALID\';
    END IF;
END;');
        DB::unprepared('CREATE TRIGGER trg_lrn_fw_versions_bd_immutable BEFORE DELETE ON core_learning_framework_versions FOR EACH ROW
BEGIN
    IF OLD.status <> \'draft_snapshot\' THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_VERSION_DELETE_FORBIDDEN\';
    END IF;
END;');
        DB::unprepared('CREATE TRIGGER trg_lrn_definitions_bu_identity BEFORE UPDATE ON core_learning_node_definitions FOR EACH ROW
BEGIN
    IF NOT (NEW.customer_id <=> OLD.customer_id) OR NOT (NEW.framework_id <=> OLD.framework_id) THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_DEFINITION_IDENTITY_IMMUTABLE\';
    END IF;
END;');
        DB::unprepared('CREATE TRIGGER trg_lrn_nodes_bu_immutable BEFORE UPDATE ON core_learning_nodes FOR EACH ROW
BEGIN
    DECLARE version_status VARCHAR(50);
    DECLARE parent_found BOOLEAN DEFAULT TRUE;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET parent_found = FALSE;
    IF NOT (NEW.customer_id <=> OLD.customer_id)
       OR NOT (NEW.framework_id <=> OLD.framework_id)
       OR NOT (NEW.framework_version_id <=> OLD.framework_version_id)
       OR NOT (NEW.node_definition_id <=> OLD.node_definition_id) THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_NODE_IDENTITY_IMMUTABLE\';
    END IF;
    SELECT status INTO version_status FROM core_learning_framework_versions
     WHERE id = OLD.framework_version_id AND customer_id = OLD.customer_id
       AND framework_id = OLD.framework_id;
    IF parent_found = FALSE OR version_status <> \'draft_snapshot\' THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_NODE_IMMUTABLE\';
    END IF;
END;');
        DB::unprepared('CREATE TRIGGER trg_lrn_nodes_bd_immutable BEFORE DELETE ON core_learning_nodes FOR EACH ROW
BEGIN
    DECLARE version_status VARCHAR(50);
    DECLARE parent_found BOOLEAN DEFAULT TRUE;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET parent_found = FALSE;
    SELECT status INTO version_status FROM core_learning_framework_versions
     WHERE id = OLD.framework_version_id AND customer_id = OLD.customer_id
       AND framework_id = OLD.framework_id;
    IF parent_found = FALSE OR version_status <> \'draft_snapshot\' THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_NODE_DELETE_FORBIDDEN\';
    END IF;
END;');
        DB::unprepared('CREATE TRIGGER trg_lrn_relations_bi_validate BEFORE INSERT ON core_learning_node_relations FOR EACH ROW
BEGIN
    IF NOT ((NEW.relation_scope = \'semantic\'
             AND NEW.relation_type IN (\'prerequisite\',\'part_of\',\'supports\')
             AND NEW.source_framework_version_id = NEW.target_framework_version_id
             AND NEW.owning_framework_version_id = NEW.source_framework_version_id
             AND NEW.continuity_policy IS NULL AND NEW.continuity_policy_key IS NULL
             AND NEW.continuity_policy_version IS NULL AND NEW.continuity_policy_snapshot IS NULL
             AND NEW.review_status = \'not_required\'
             AND NEW.resolved_continuity_policy IS NULL
             AND NEW.approved_by IS NULL AND NEW.approved_at IS NULL
             AND NEW.reviewed_by IS NULL AND NEW.reviewed_at IS NULL
             AND NEW.review_reason IS NULL)
         OR (NEW.relation_scope = \'version_transition\'
             AND NEW.relation_type IN (\'equivalent_to\',\'supersedes\',\'splits_into\',\'merges_into\')
             AND NEW.source_framework_version_id <> NEW.target_framework_version_id
             AND NEW.owning_framework_version_id = NEW.target_framework_version_id
             AND NEW.continuity_policy IN (\'no_carry_forward\',\'allow_as_input\',\'carry_forward\',\'requires_review\')
             AND NEW.continuity_policy_key IS NOT NULL
             AND NEW.continuity_policy_version IS NOT NULL
             AND NEW.continuity_policy_snapshot IS NOT NULL
             AND JSON_VALID(NEW.continuity_policy_snapshot) = 1
             AND COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.continuity_policy_snapshot, \'$.source_framework_version_id\')) AS UNSIGNED) = NEW.source_framework_version_id, FALSE)
             AND COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.continuity_policy_snapshot, \'$.target_framework_version_id\')) AS UNSIGNED) = NEW.target_framework_version_id, FALSE)
             AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.continuity_policy_snapshot, \'$.policy\')) = NEW.continuity_policy, FALSE)
             AND ((NEW.continuity_policy = \'requires_review\'
                   AND NEW.review_status = \'pending\'
                   AND NEW.resolved_continuity_policy IS NULL
                   AND NEW.approved_by IS NULL AND NEW.approved_at IS NULL
                   AND NEW.reviewed_by IS NULL AND NEW.reviewed_at IS NULL
                   AND NEW.review_reason IS NULL)
               OR (NEW.continuity_policy <> \'requires_review\'
                   AND NEW.review_status = \'not_required\'
                   AND NEW.resolved_continuity_policy IS NULL
                   AND NEW.reviewed_by IS NULL AND NEW.reviewed_at IS NULL
                   AND NEW.review_reason IS NULL
                   AND ((NEW.continuity_policy IN (\'allow_as_input\',\'carry_forward\')
                         AND NEW.approved_by IS NOT NULL AND NEW.approved_at IS NOT NULL)
                     OR (NEW.continuity_policy = \'no_carry_forward\'
                         AND NEW.approved_by IS NULL AND NEW.approved_at IS NULL)))
               OR (NEW.continuity_policy <> \'requires_review\'
                   AND NEW.review_status = \'approved\'
                   AND NEW.resolved_continuity_policy IN (\'no_carry_forward\',\'allow_as_input\',\'carry_forward\')
                   AND NEW.approved_by IS NOT NULL AND NEW.approved_at IS NOT NULL
                   AND NEW.reviewed_by = NEW.approved_by AND NEW.reviewed_at = NEW.approved_at
                   AND NULLIF(TRIM(NEW.review_reason), \'\') IS NOT NULL)))) THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_RELATION_INVALID\';
    END IF;
    IF NEW.relation_scope = \'semantic\' AND NEW.relation_type IN (\'prerequisite\',\'part_of\')
       AND EXISTS (
          WITH RECURSIVE graph_walk AS (
            SELECT target_learning_node_id AS node_id
              FROM core_learning_node_relations
             WHERE customer_id = NEW.customer_id AND framework_id = NEW.framework_id
               AND relation_scope = \'semantic\' AND relation_type = NEW.relation_type
               AND source_framework_version_id = NEW.source_framework_version_id
               AND source_learning_node_id = NEW.target_learning_node_id
            UNION DISTINCT
            SELECT relation_row.target_learning_node_id
              FROM core_learning_node_relations relation_row
              JOIN graph_walk ON relation_row.source_learning_node_id = graph_walk.node_id
             WHERE relation_row.customer_id = NEW.customer_id
               AND relation_row.framework_id = NEW.framework_id
               AND relation_row.relation_scope = \'semantic\'
               AND relation_row.relation_type = NEW.relation_type
               AND relation_row.source_framework_version_id = NEW.source_framework_version_id
          ) SELECT 1 FROM graph_walk WHERE node_id = NEW.source_learning_node_id
       ) THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_RELATION_CYCLE\';
    END IF;
END;');
        DB::unprepared('CREATE TRIGGER trg_lrn_relations_bu_immutable BEFORE UPDATE ON core_learning_node_relations FOR EACH ROW
BEGIN
    DECLARE version_status VARCHAR(50);
    DECLARE parent_found BOOLEAN DEFAULT TRUE;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET parent_found = FALSE;
    IF NOT (NEW.customer_id <=> OLD.customer_id) OR NOT (NEW.framework_id <=> OLD.framework_id)
       OR NOT (NEW.owning_framework_version_id <=> OLD.owning_framework_version_id)
       OR NOT (NEW.relation_scope <=> OLD.relation_scope) OR NOT (NEW.relation_type <=> OLD.relation_type)
       OR NOT (NEW.source_learning_node_id <=> OLD.source_learning_node_id)
       OR NOT (NEW.target_learning_node_id <=> OLD.target_learning_node_id)
       OR NOT (NEW.source_framework_version_id <=> OLD.source_framework_version_id)
       OR NOT (NEW.target_framework_version_id <=> OLD.target_framework_version_id)
       OR NOT (NEW.continuity_policy <=> OLD.continuity_policy)
       OR NOT (NEW.continuity_policy_key <=> OLD.continuity_policy_key)
       OR NOT (NEW.continuity_policy_version <=> OLD.continuity_policy_version)
       OR NOT (NEW.continuity_policy_snapshot <=> OLD.continuity_policy_snapshot)
       OR NOT (NEW.created_by <=> OLD.created_by) OR NOT (NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_RELATION_IMMUTABLE\';
    END IF;
    SELECT status INTO version_status FROM core_learning_framework_versions
     WHERE id = OLD.owning_framework_version_id AND customer_id = OLD.customer_id
       AND framework_id = OLD.framework_id;
    IF version_status = \'draft_snapshot\' AND NOT (
       (NEW.relation_scope = \'semantic\'
        AND NEW.relation_type IN (\'prerequisite\',\'part_of\',\'supports\')
        AND NEW.source_framework_version_id = NEW.target_framework_version_id
        AND NEW.owning_framework_version_id = NEW.source_framework_version_id
        AND NEW.continuity_policy IS NULL AND NEW.continuity_policy_key IS NULL
        AND NEW.continuity_policy_version IS NULL AND NEW.continuity_policy_snapshot IS NULL
        AND NEW.review_status = \'not_required\' AND NEW.resolved_continuity_policy IS NULL
        AND NEW.approved_by IS NULL AND NEW.approved_at IS NULL
        AND NEW.reviewed_by IS NULL AND NEW.reviewed_at IS NULL AND NEW.review_reason IS NULL)
       OR
       (NEW.relation_scope = \'version_transition\'
        AND NEW.relation_type IN (\'equivalent_to\',\'supersedes\',\'splits_into\',\'merges_into\')
        AND NEW.source_framework_version_id <> NEW.target_framework_version_id
        AND NEW.owning_framework_version_id = NEW.target_framework_version_id
        AND NEW.continuity_policy IN (\'no_carry_forward\',\'allow_as_input\',\'carry_forward\',\'requires_review\')
        AND NEW.continuity_policy_key IS NOT NULL AND NEW.continuity_policy_version IS NOT NULL
        AND NEW.continuity_policy_snapshot IS NOT NULL
        AND JSON_VALID(NEW.continuity_policy_snapshot) = 1
        AND COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.continuity_policy_snapshot, \'$.source_framework_version_id\')) AS UNSIGNED) = NEW.source_framework_version_id, FALSE)
        AND COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.continuity_policy_snapshot, \'$.target_framework_version_id\')) AS UNSIGNED) = NEW.target_framework_version_id, FALSE)
        AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.continuity_policy_snapshot, \'$.policy\')) = NEW.continuity_policy, FALSE)
        AND ((NEW.continuity_policy = \'requires_review\' AND NEW.review_status = \'pending\'
              AND NEW.resolved_continuity_policy IS NULL AND NEW.approved_by IS NULL
              AND NEW.approved_at IS NULL AND NEW.reviewed_by IS NULL
              AND NEW.reviewed_at IS NULL AND NEW.review_reason IS NULL)
          OR (NEW.continuity_policy <> \'requires_review\' AND NEW.review_status = \'not_required\'
              AND NEW.resolved_continuity_policy IS NULL AND NEW.reviewed_by IS NULL
              AND NEW.reviewed_at IS NULL AND NEW.review_reason IS NULL
              AND ((NEW.continuity_policy IN (\'allow_as_input\',\'carry_forward\')
                    AND NEW.approved_by IS NOT NULL AND NEW.approved_at IS NOT NULL)
                OR (NEW.continuity_policy = \'no_carry_forward\'
                    AND NEW.approved_by IS NULL AND NEW.approved_at IS NULL)))
          OR (NEW.continuity_policy <> \'requires_review\' AND NEW.review_status = \'approved\'
              AND NEW.resolved_continuity_policy IN (\'no_carry_forward\',\'allow_as_input\',\'carry_forward\')
              AND NEW.approved_by IS NOT NULL AND NEW.approved_at IS NOT NULL
              AND NEW.reviewed_by = NEW.approved_by AND NEW.reviewed_at = NEW.approved_at
              AND NULLIF(TRIM(NEW.review_reason), \'\') IS NOT NULL))
       )) THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_RELATION_INVALID\';
    END IF;
    IF parent_found = FALSE THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_RELATION_INVALID\';
    END IF;
    IF version_status <> \'draft_snapshot\' AND NOT (
       OLD.review_status = \'pending\' AND NEW.review_status IN (\'approved\',\'rejected\')
       AND OLD.reviewed_by IS NULL AND OLD.reviewed_at IS NULL
       AND NEW.reviewed_by IS NOT NULL AND NEW.reviewed_at IS NOT NULL
       AND NULLIF(TRIM(NEW.review_reason), \'\') IS NOT NULL
       AND ((NEW.review_status = \'approved\' AND NEW.approved_by IS NOT NULL
             AND NEW.approved_at IS NOT NULL
             AND NEW.approved_by = NEW.reviewed_by
             AND NEW.approved_at = NEW.reviewed_at
             AND NEW.resolved_continuity_policy IN (\'no_carry_forward\',\'allow_as_input\',\'carry_forward\'))
         OR (NEW.review_status = \'rejected\' AND NEW.approved_by IS NULL
             AND NEW.approved_at IS NULL AND NEW.resolved_continuity_policy IS NULL))
       ) THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_RELATION_IMMUTABLE\';
    END IF;
END;');
        DB::unprepared('CREATE TRIGGER trg_lrn_relations_bd_immutable BEFORE DELETE ON core_learning_node_relations FOR EACH ROW
BEGIN
    DECLARE version_status VARCHAR(50);
    DECLARE parent_found BOOLEAN DEFAULT TRUE;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET parent_found = FALSE;
    SELECT status INTO version_status FROM core_learning_framework_versions
     WHERE id = OLD.owning_framework_version_id AND customer_id = OLD.customer_id
       AND framework_id = OLD.framework_id;
    IF parent_found = FALSE OR version_status <> \'draft_snapshot\' THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_RELATION_DELETE_FORBIDDEN\';
    END IF;
END;');
        DB::unprepared('CREATE TRIGGER trg_lrn_mappings_bu_lifecycle BEFORE UPDATE ON core_learning_node_mappings FOR EACH ROW
BEGIN
    IF NOT (NEW.customer_id <=> OLD.customer_id) OR NOT (NEW.learning_node_id <=> OLD.learning_node_id)
       OR NOT (NEW.source_type <=> OLD.source_type) OR NOT (NEW.source_id <=> OLD.source_id)
       OR NOT (NEW.source_discriminator <=> OLD.source_discriminator)
       OR NOT (NEW.mapping_role <=> OLD.mapping_role) OR NOT (NEW.weight <=> OLD.weight)
       OR NOT (NEW.source_snapshot <=> OLD.source_snapshot) OR NOT (NEW.created_by <=> OLD.created_by)
       OR NOT (NEW.created_at <=> OLD.created_at)
       OR OLD.invalidated_at IS NOT NULL OR OLD.invalidated_by IS NOT NULL
       OR OLD.invalidation_reason IS NOT NULL OR NEW.invalidated_at IS NULL
       OR NEW.invalidated_by IS NULL OR NULLIF(TRIM(NEW.invalidation_reason), \'\') IS NULL THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_MAPPING_IMMUTABLE\';
    END IF;
END;');
        DB::unprepared('CREATE TRIGGER trg_lrn_mappings_bd_immutable BEFORE DELETE ON core_learning_node_mappings FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_MAPPING_IMMUTABLE\';
END;');
        DB::unprepared('CREATE TRIGGER trg_lrn_evidence_bi_validate BEFORE INSERT ON core_learning_evidence FOR EACH ROW
BEGIN
    IF NEW.source_type <> \'teacher_judgment\' THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_EVIDENCE_SOURCE_CLOSED\';
    END IF;
    IF NEW.evidence_type <> \'expert_judgment\' OR NEW.recorded_by IS NULL OR NEW.source_id = 0
       OR NULLIF(TRIM(NEW.source_discriminator), \'\') IS NULL
       OR NULLIF(TRIM(NEW.producer_idempotency_key), \'\') IS NULL
       OR JSON_VALID(NEW.qualification_rule_snapshot) = 0
       OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.qualification_rule_snapshot, \'$.rule_key\')) = NEW.qualification_rule_key, FALSE) = FALSE
       OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.qualification_rule_snapshot, \'$.rule_version\')) = NEW.qualification_rule_version, FALSE) = FALSE
       OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.qualification_rule_snapshot, \'$.source_type\')) = NEW.source_type, FALSE) = FALSE
       OR (NEW.evaluated_at < NEW.source_occurred_at
           AND COALESCE(JSON_EXTRACT(NEW.qualification_rule_snapshot, \'$.delayed_source_approved\') = TRUE, FALSE) = FALSE)
       OR (NEW.supersedes_evidence_id IS NOT NULL AND NOT EXISTS (
           SELECT 1 FROM core_learning_evidence prior
            WHERE prior.id = NEW.supersedes_evidence_id AND prior.customer_id = NEW.customer_id
              AND prior.user_id = NEW.user_id AND prior.id <> NEW.id)) THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_EVIDENCE_INVALID\';
    END IF;
END;');
        DB::unprepared('CREATE TRIGGER trg_lrn_evidence_bu_immutable BEFORE UPDATE ON core_learning_evidence FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_EVIDENCE_IMMUTABLE\';
END;');
        DB::unprepared('CREATE TRIGGER trg_lrn_evidence_bd_immutable BEFORE DELETE ON core_learning_evidence FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_EVIDENCE_IMMUTABLE\';
END;');
        DB::unprepared('CREATE TRIGGER trg_lrn_calcs_bi_validate BEFORE INSERT ON core_learning_mastery_calculations FOR EACH ROW
BEGIN
    DECLARE scale_index INT DEFAULT 0;
    DECLARE scale_count INT;
    DECLARE scale_key VARCHAR(255);
    DECLARE scale_threshold DECIMAL(18,6);
    IF JSON_VALID(NEW.mastery_scale_snapshot) = 0
       OR COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.mastery_scale_snapshot, \'$.levels\')), \'\') <> \'ARRAY\'
       OR COALESCE(JSON_LENGTH(JSON_EXTRACT(NEW.mastery_scale_snapshot, \'$.levels\')), 0) < 2 THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_CALCULATION_INVALID\';
    END IF;
    SET scale_count = JSON_LENGTH(JSON_EXTRACT(NEW.mastery_scale_snapshot, \'$.levels\'));
    WHILE scale_index < scale_count DO
        SET scale_key = JSON_UNQUOTE(JSON_EXTRACT(NEW.mastery_scale_snapshot, CONCAT(\'$.levels[\',scale_index,\'].key\')));
        SET scale_threshold = CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.mastery_scale_snapshot, CONCAT(\'$.levels[\',scale_index,\'].threshold\'))) AS DECIMAL(18,6));
        IF COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.mastery_scale_snapshot, CONCAT(\'$.levels[\',scale_index,\'].key\'))), \'\') <> \'STRING\'
           OR NULLIF(TRIM(scale_key), \'\') IS NULL
           OR COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.mastery_scale_snapshot, CONCAT(\'$.levels[\',scale_index,\'].threshold\'))), \'\') NOT IN (\'INTEGER\',\'DOUBLE\')
           OR (scale_index > 0 AND scale_threshold <= CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.mastery_scale_snapshot, CONCAT(\'$.levels[\',scale_index - 1,\'].threshold\'))) AS DECIMAL(18,6)))
           OR JSON_UNQUOTE(JSON_SEARCH(NEW.mastery_scale_snapshot, \'one\', scale_key, NULL, \'$.levels[*].key\')) <> CONCAT(\'$.levels[\',scale_index,\'].key\') THEN
            SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_CALCULATION_INVALID\';
        END IF;
        SET scale_index = scale_index + 1;
    END WHILE;
    IF NOT EXISTS (
        SELECT 1 FROM core_learning_framework_versions basis
         WHERE basis.id = NEW.basis_framework_version_id AND basis.customer_id = NEW.customer_id
           AND basis.framework_id = NEW.framework_id
           AND basis.mastery_scale_key = NEW.mastery_scale_key
           AND basis.mastery_scale_version = NEW.mastery_scale_version
           AND basis.mastery_scale_snapshot = NEW.mastery_scale_snapshot)
       OR JSON_SEARCH(NEW.mastery_scale_snapshot, \'one\', NEW.mastery_level_key, NULL, \'$.levels[*].key\') IS NULL
       OR JSON_VALID(NEW.calculation_rule_snapshot) = 0
       OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.calculation_rule_snapshot, \'$.rule_key\')) = NEW.calculation_rule_key, FALSE) = FALSE
       OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.calculation_rule_snapshot, \'$.rule_version\')) = NEW.calculation_rule_version, FALSE) = FALSE
       OR NOT ((NEW.calculation_source = \'system\' AND NEW.calculated_by IS NULL
                AND NEW.reason IS NULL AND NEW.source_node_relation_id IS NULL
                AND NEW.continuity_policy_snapshot IS NULL)
            OR (NEW.calculation_source = \'teacher_override\' AND NEW.calculated_by IS NOT NULL
                AND NULLIF(TRIM(NEW.reason), \'\') IS NOT NULL
                AND NEW.source_node_relation_id IS NULL AND NEW.continuity_policy_snapshot IS NULL)
            OR (NEW.calculation_source = \'carry_forward\' AND NEW.source_calculation_id IS NOT NULL
                AND NEW.source_node_relation_id IS NOT NULL AND NEW.continuity_policy_snapshot IS NOT NULL
                AND JSON_VALID(NEW.continuity_policy_snapshot) = 1
                AND EXISTS (
                  SELECT 1 FROM core_learning_node_relations relation_row
                   WHERE relation_row.id = NEW.source_node_relation_id
                     AND relation_row.customer_id = NEW.customer_id
                     AND relation_row.framework_id = NEW.framework_id
                     AND relation_row.target_framework_version_id = NEW.basis_framework_version_id
                     AND relation_row.review_status = \'approved\'
                     AND COALESCE(relation_row.resolved_continuity_policy,
                                  relation_row.continuity_policy) IN (\'allow_as_input\',\'carry_forward\')
                     AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.continuity_policy_snapshot, \'$.relation_id\')) AS UNSIGNED) = relation_row.id
                     AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.continuity_policy_snapshot, \'$.source_framework_version_id\')) AS UNSIGNED) = relation_row.source_framework_version_id
                     AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.continuity_policy_snapshot, \'$.target_framework_version_id\')) AS UNSIGNED) = relation_row.target_framework_version_id
                     AND JSON_UNQUOTE(JSON_EXTRACT(NEW.continuity_policy_snapshot, \'$.policy\')) = COALESCE(relation_row.resolved_continuity_policy, relation_row.continuity_policy)
                ))) THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_CALCULATION_INVALID\';
    END IF;
END;');
        DB::unprepared('CREATE TRIGGER trg_lrn_calcs_bu_immutable BEFORE UPDATE ON core_learning_mastery_calculations FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_CALCULATION_IMMUTABLE\';
END;');
        DB::unprepared('CREATE TRIGGER trg_lrn_calcs_bd_immutable BEFORE DELETE ON core_learning_mastery_calculations FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_CALCULATION_IMMUTABLE\';
END;');
        DB::unprepared('CREATE TRIGGER trg_lrn_calc_evidence_bi_validate BEFORE INSERT ON core_learning_calculation_evidence FOR EACH ROW
BEGIN
    IF NOT EXISTS (
       SELECT 1
         FROM core_learning_mastery_calculations calculation_row
         JOIN core_learning_evidence evidence_row
           ON evidence_row.id = NEW.evidence_id AND evidence_row.customer_id = NEW.customer_id
          AND evidence_row.user_id = NEW.user_id
         JOIN core_learning_nodes evidence_node ON evidence_node.id = evidence_row.learning_node_id
          AND evidence_node.customer_id = evidence_row.customer_id
        WHERE calculation_row.id = NEW.mastery_calculation_id
          AND calculation_row.customer_id = NEW.customer_id
          AND calculation_row.user_id = NEW.user_id
          AND (evidence_node.node_definition_id = calculation_row.node_definition_id
            OR (calculation_row.calculation_source = \'carry_forward\'
                AND NEW.evidence_role = \'continuity_input\'
                AND EXISTS (
                  SELECT 1 FROM core_learning_node_relations relation_row
                  JOIN core_learning_nodes source_node
                    ON source_node.id = relation_row.source_learning_node_id
                   AND source_node.customer_id = relation_row.customer_id
                  JOIN core_learning_nodes target_node
                    ON target_node.id = relation_row.target_learning_node_id
                   AND target_node.customer_id = relation_row.customer_id
                  WHERE relation_row.id = calculation_row.source_node_relation_id
                    AND relation_row.review_status = \'approved\'
                    AND source_node.node_definition_id = evidence_node.node_definition_id
                    AND target_node.node_definition_id = calculation_row.node_definition_id
                    AND COALESCE(relation_row.resolved_continuity_policy,
                                 relation_row.continuity_policy) IN (\'allow_as_input\',\'carry_forward\')
                )))
    ) THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_CALC_EVIDENCE_INVALID\';
    END IF;
END;');
        DB::unprepared('CREATE TRIGGER trg_lrn_calc_evidence_bu_immutable BEFORE UPDATE ON core_learning_calculation_evidence FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_CALC_EVIDENCE_IMMUTABLE\';
END;');
        DB::unprepared('CREATE TRIGGER trg_lrn_calc_evidence_bd_immutable BEFORE DELETE ON core_learning_calculation_evidence FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_CALC_EVIDENCE_IMMUTABLE\';
END;');
        DB::unprepared('CREATE TRIGGER trg_lrn_profiles_bi_projection BEFORE INSERT ON core_learning_mastery_profiles FOR EACH ROW
BEGIN
    IF NOT EXISTS (
       SELECT 1 FROM core_learning_mastery_calculations calculation_row
        WHERE calculation_row.id = NEW.current_calculation_id
          AND calculation_row.customer_id = NEW.customer_id AND calculation_row.user_id = NEW.user_id
          AND calculation_row.framework_id = NEW.framework_id
          AND calculation_row.node_definition_id = NEW.node_definition_id
          AND calculation_row.basis_framework_version_id = NEW.basis_framework_version_id
          AND calculation_row.mastery_level_key = NEW.mastery_level_key
          AND calculation_row.mastery_score <=> NEW.mastery_score
          AND calculation_row.mastery_status_result = NEW.mastery_status
          AND calculation_row.calculated_at = NEW.calculated_at
          AND calculation_row.reassessment_due_at <=> NEW.reassessment_due_at
    ) THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_PROFILE_MISMATCH\';
    END IF;
END;');
        DB::unprepared('CREATE TRIGGER trg_lrn_profiles_bu_projection BEFORE UPDATE ON core_learning_mastery_profiles FOR EACH ROW
BEGIN
    DECLARE old_calculated_at DATETIME(6);
    DECLARE parent_found BOOLEAN DEFAULT TRUE;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET parent_found = FALSE;
    IF NOT (NEW.customer_id <=> OLD.customer_id) OR NOT (NEW.user_id <=> OLD.user_id)
       OR NOT (NEW.framework_id <=> OLD.framework_id)
       OR NOT (NEW.node_definition_id <=> OLD.node_definition_id)
       OR NOT (NEW.basis_framework_version_id <=> OLD.basis_framework_version_id) THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_PROFILE_MISMATCH\';
    END IF;
    IF NOT EXISTS (
       SELECT 1 FROM core_learning_mastery_calculations calculation_row
        WHERE calculation_row.id = NEW.current_calculation_id
          AND calculation_row.customer_id = NEW.customer_id AND calculation_row.user_id = NEW.user_id
          AND calculation_row.framework_id = NEW.framework_id
          AND calculation_row.node_definition_id = NEW.node_definition_id
          AND calculation_row.basis_framework_version_id = NEW.basis_framework_version_id
          AND calculation_row.mastery_level_key = NEW.mastery_level_key
          AND calculation_row.mastery_score <=> NEW.mastery_score
          AND calculation_row.mastery_status_result = NEW.mastery_status
          AND calculation_row.calculated_at = NEW.calculated_at
          AND calculation_row.reassessment_due_at <=> NEW.reassessment_due_at
    ) THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_PROFILE_MISMATCH\';
    END IF;
    SELECT calculated_at INTO old_calculated_at FROM core_learning_mastery_calculations
     WHERE id = OLD.current_calculation_id AND customer_id = OLD.customer_id
       AND user_id = OLD.user_id;
    IF parent_found = FALSE THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_PROFILE_MISMATCH\';
    END IF;
    IF NEW.calculated_at < old_calculated_at
       OR (NEW.calculated_at = old_calculated_at
           AND NEW.current_calculation_id < OLD.current_calculation_id) THEN
        SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'LF_PROFILE_STALE\';
    END IF;
END;');
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) { return; }
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_lrn_profiles_bu_projection`');
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_lrn_profiles_bi_projection`');
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_lrn_calc_evidence_bd_immutable`');
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_lrn_calc_evidence_bu_immutable`');
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_lrn_calc_evidence_bi_validate`');
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_lrn_calcs_bd_immutable`');
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_lrn_calcs_bu_immutable`');
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_lrn_calcs_bi_validate`');
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_lrn_evidence_bd_immutable`');
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_lrn_evidence_bu_immutable`');
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_lrn_evidence_bi_validate`');
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_lrn_mappings_bd_immutable`');
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_lrn_mappings_bu_lifecycle`');
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_lrn_relations_bd_immutable`');
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_lrn_relations_bu_immutable`');
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_lrn_relations_bi_validate`');
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_lrn_nodes_bd_immutable`');
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_lrn_nodes_bu_immutable`');
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_lrn_definitions_bu_identity`');
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_lrn_fw_versions_bd_immutable`');
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_lrn_fw_versions_bu_immutable`');
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_lrn_fw_versions_bi_validate`');
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_lrn_frameworks_bu_scale`');
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_lrn_frameworks_bi_scale`');
        Schema::dropIfExists('core_learning_mastery_profiles');
        Schema::dropIfExists('core_learning_calculation_evidence');
        Schema::dropIfExists('core_learning_mastery_calculations');
        Schema::dropIfExists('core_learning_evidence');
        Schema::dropIfExists('core_learning_node_mappings');
        Schema::dropIfExists('core_learning_node_relations');
        Schema::dropIfExists('core_learning_nodes');
        Schema::dropIfExists('core_learning_node_definitions');
        Schema::dropIfExists('core_learning_framework_versions');
        Schema::dropIfExists('core_learning_frameworks');
    }
};


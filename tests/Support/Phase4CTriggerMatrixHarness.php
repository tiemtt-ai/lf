<?php

declare(strict_types=1);

/**
 * Phase 4C disposable trigger rehearsal harness.
 *
 * Required environment:
 *   LF_PHASE4C_DB_SOCKET=/path/to/disposable-mariadb.sock
 *
 * The harness never accepts a database name. It creates a random database,
 * refuses a socket matching the application configuration and drops the
 * random database in finally.
 */
final class Phase4CTriggerMatrixHarness
{
    private PDO $pdo;
    private string $database;
    private int $passed = 0;
    private array $results = [];

    public function __construct(private readonly string $root)
    {
        $socket = getenv('LF_PHASE4C_DB_SOCKET') ?: '';
        if ($socket === '' || !file_exists($socket) || filetype($socket) !== 'socket') {
            throw new RuntimeException('LF_PHASE4C_DB_SOCKET must reference a disposable MariaDB socket.');
        }

        $appSocket = $this->readEnvValue('DB_SOCKET');
        if ($appSocket !== '' && realpath($socket) === realpath($appSocket)) {
            throw new RuntimeException('Refusing the application database socket.');
        }

        $this->database = 'lf_phase4c_matrix_' . bin2hex(random_bytes(8));
        $this->pdo = new PDO(
            "mysql:unix_socket={$socket};charset=utf8mb4",
            'root',
            '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
        );
        $datadir = (string) $this->pdo->query('SELECT @@datadir')->fetchColumn();
        $sentinel = getenv('LF_PHASE4C_DISPOSABLE_SENTINEL') ?: '';
        $resolvedDatadir = realpath($datadir) ?: '';
        $disposablePrefix = str_starts_with($resolvedDatadir, '/private/tmp/lf-mariadb-11-4-phase4c-')
            || str_starts_with($resolvedDatadir, '/private/tmp/lf-mariadb-11-4-phase4bc-');
        if ($sentinel === '' || ! $disposablePrefix) {
            throw new RuntimeException('Refusing server without disposable datadir sentinel.');
        }
        $stored = $this->pdo->query(
            "SELECT sentinel_value FROM lf_phase4c_disposable_guard.sentinel LIMIT 1",
        )->fetchColumn();
        if (!hash_equals((string) $stored, $sentinel)) {
            throw new RuntimeException('Disposable server sentinel mismatch.');
        }
    }

    public function run(): void
    {
        $created = false;
        try {
            $this->pdo->exec("CREATE DATABASE `{$this->database}` CHARACTER SET utf8mb4");
            $created = true;
            $this->pdo->exec("USE `{$this->database}`");
            echo 'engine=' . $this->pdo->query('SELECT VERSION()')->fetchColumn() . PHP_EOL;
            $this->createContractTables();
            $this->installTriggers();
            $this->verifyTriggerIdentityAndBody();
            $this->runRequiredPathMatrix();
            $this->runRelationCycleMatrix();
            $this->runLifecycleAndImmutabilityMatrix();
            $this->runCheckAndRollbackMatrix();
            echo "probes_passed={$this->passed}" . PHP_EOL;
            echo 'results_json=' . json_encode($this->results, JSON_THROW_ON_ERROR) . PHP_EOL;
            echo 'result_digest=' . hash('sha256', json_encode($this->results, JSON_THROW_ON_ERROR)) . PHP_EOL;
            echo 'verdict=PASS' . PHP_EOL;
        } finally {
            if ($created) {
                $this->pdo->exec("DROP DATABASE IF EXISTS `{$this->database}`");
            }
            $remaining = (int) $this->pdo->query(
                'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '
                . $this->pdo->quote($this->database),
            )->fetchColumn();
            echo 'cleanup=' . ($remaining === 0 ? 'PASS' : 'FAIL') . PHP_EOL;
        }
    }

    private function createContractTables(): void
    {
        $contract = json_decode(
            file_get_contents($this->root . '/docs/database/LF-SCHEMA-CONTRACT.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->pdo->exec('CREATE TABLE saas_customers (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
        $this->pdo->exec('CREATE TABLE users (id BIGINT UNSIGNED PRIMARY KEY, customer_id BIGINT UNSIGNED NOT NULL, '
            . 'UNIQUE KEY uq_users_id_customer (id,customer_id)) ENGINE=InnoDB');
        foreach ($contract['tables'] as $table) {
            if (!str_starts_with($table['name'], 'core_learning_')) {
                continue;
            }
            $columns = array_map(fn (array $column): string => $this->columnSql($column), $table['columns']);
            $columns[] = 'PRIMARY KEY (`id`)';
            foreach ($table['checks'] ?? [] as $index => $check) {
                $columns[] = "CONSTRAINT `chk_{$table['name']}_{$index}` CHECK ({$check['expression']})";
            }
            foreach ($table['indexes'] ?? [] as $index => $definition) {
                $kind = !empty($definition['unique']) ? 'UNIQUE ' : '';
                $parts = implode('`,`', $definition['columns']);
                $columns[] = "{$kind}KEY `idx_{$table['name']}_{$index}` (`{$parts}`)";
            }
            $this->pdo->exec("CREATE TABLE `{$table['name']}` (" . implode(',', $columns) . ') ENGINE=InnoDB');
        }
        foreach ($contract['tables'] as $table) {
            if (!str_starts_with($table['name'], 'core_learning_')) {
                continue;
            }
            foreach ($table['foreign_keys'] ?? [] as $index => $foreignKey) {
                $columns = implode('`,`', $foreignKey['columns']);
                $foreignColumns = implode('`,`', $foreignKey['foreign_columns']);
                $this->pdo->exec("ALTER TABLE `{$table['name']}` ADD CONSTRAINT "
                    . "`fk_{$table['name']}_{$index}` FOREIGN KEY (`{$columns}`) "
                    . "REFERENCES `{$foreignKey['foreign_table']}` (`{$foreignColumns}`) "
                    . "ON UPDATE {$foreignKey['on_update']} ON DELETE {$foreignKey['on_delete']}");
            }
        }
        $this->pdo->exec('INSERT INTO saas_customers (id) VALUES (1),(2)');
        $this->pdo->exec('INSERT INTO users (id,customer_id) VALUES (1,1),(2,1),(3,2)');
    }

    private function installTriggers(): void
    {
        $sql = file_get_contents(
            $this->root . '/docs/quality/LF-Learning-Foundation-Phase-4C-Trigger-Bodies.sql',
        );
        preg_match_all('/CREATE TRIGGER\s+.+?\nEND;/si', $sql, $matches);
        if (count($matches[0]) !== 24) {
            throw new RuntimeException('Expected exactly 24 trigger bodies.');
        }
        foreach ($matches[0] as $statement) {
            $this->pdo->exec($statement);
        }
        echo 'triggers_installed=24' . PHP_EOL;
    }

    private function runRequiredPathMatrix(): void
    {
        $scale = json_encode(['levels' => [
            ['key' => 'novice', 'threshold' => 0],
            ['key' => 'mastered', 'threshold' => 0.8],
        ]], JSON_THROW_ON_ERROR);
        $this->insert('core_learning_frameworks', [
            'id' => 1, 'customer_id' => 1, 'code' => 'framework-1',
            'name' => 'Framework 1', 'status' => 'active',
            'default_mastery_scale' => $scale,
        ]);
        $this->insert('core_learning_framework_versions', [
            'id' => 10, 'customer_id' => 1, 'framework_id' => 1,
            'mastery_scale_key' => 'scale', 'mastery_scale_version' => '1',
            'mastery_scale_snapshot' => $scale, 'status' => 'draft_snapshot',
        ]);
        $relation = [
            'customer_id' => 1, 'framework_id' => 1, 'owning_framework_version_id' => 10,
            'relation_scope' => 'version_transition', 'relation_type' => 'equivalent_to',
            'source_learning_node_id' => 1, 'target_learning_node_id' => 2,
            'source_framework_version_id' => 9, 'target_framework_version_id' => 10,
            'continuity_policy' => 'requires_review', 'continuity_policy_key' => 'policy',
            'continuity_policy_version' => '1', 'review_status' => 'pending',
        ];
        foreach (['source_framework_version_id', 'target_framework_version_id', 'policy'] as $index => $path) {
            $snapshot = ['source_framework_version_id' => 9, 'target_framework_version_id' => 10, 'policy' => 'requires_review'];
            unset($snapshot[$path]);
            $this->reject("relation_missing_{$path}", 'LF_RELATION_INVALID', fn () => $this->insert(
                'core_learning_node_relations',
                ['id' => 20 + $index, 'continuity_policy_snapshot' => json_encode($snapshot)] + $relation,
            ));
        }
        $evidence = [
            'customer_id' => 1, 'user_id' => 1, 'learning_node_id' => 1,
            'evidence_type' => 'expert_judgment', 'source_type' => 'teacher_judgment',
            'source_id' => 1, 'source_discriminator' => 'manual',
            'producer_idempotency_key' => 'evidence', 'source_occurred_at' => '2026-08-13 10:00:00',
            'evaluated_at' => '2026-08-13 10:00:00', 'qualification_rule_key' => 'teacher',
            'qualification_rule_version' => '1', 'recorded_by' => 1,
        ];
        foreach (['rule_key', 'rule_version', 'source_type'] as $index => $path) {
            $snapshot = ['rule_key' => 'teacher', 'rule_version' => '1', 'source_type' => 'teacher_judgment'];
            unset($snapshot[$path]);
            $this->reject("evidence_missing_{$path}", 'LF_EVIDENCE_INVALID', fn () => $this->insert(
                'core_learning_evidence',
                ['id' => 30 + $index, 'qualification_rule_snapshot' => json_encode($snapshot)] + $evidence,
            ));
        }
        $calculation = [
            'customer_id' => 1, 'user_id' => 1, 'framework_id' => 1, 'node_definition_id' => 1,
            'basis_framework_version_id' => 10, 'calculation_source' => 'system',
            'mastery_level_key' => 'mastered', 'mastery_scale_key' => 'scale',
            'mastery_scale_version' => '1', 'mastery_scale_snapshot' => $scale,
            'calculation_rule_key' => 'calc', 'calculation_rule_version' => '1',
        ];
        foreach (['rule_key', 'rule_version'] as $index => $path) {
            $snapshot = ['rule_key' => 'calc', 'rule_version' => '1'];
            unset($snapshot[$path]);
            $this->reject("calculation_missing_{$path}", 'LF_CALCULATION_INVALID', fn () => $this->insert(
                'core_learning_mastery_calculations',
                ['id' => 40 + $index, 'calculation_rule_snapshot' => json_encode($snapshot)] + $calculation,
            ));
        }
    }

    private function runRelationCycleMatrix(): void
    {
        foreach ([30, 31, 32] as $id) {
            $this->insert('core_learning_node_definitions', [
                'id' => $id, 'customer_id' => 1, 'framework_id' => 1,
                'code' => "definition-{$id}", 'node_type' => 'competency',
                'canonical_name' => "Definition {$id}", 'status' => 'active',
            ]);
            $this->insert('core_learning_nodes', [
                'id' => $id, 'customer_id' => 1, 'framework_id' => 1,
                'framework_version_id' => 10, 'node_definition_id' => $id,
            ]);
        }
        $relation = [
            'customer_id' => 1, 'framework_id' => 1, 'owning_framework_version_id' => 10,
            'relation_scope' => 'semantic', 'relation_type' => 'prerequisite',
            'source_framework_version_id' => 10, 'target_framework_version_id' => 10,
            'review_status' => 'not_required',
        ];
        $this->insert('core_learning_node_relations', ['id' => 50, 'source_learning_node_id' => 30, 'target_learning_node_id' => 31] + $relation);
        $this->insert('core_learning_node_relations', ['id' => 51, 'source_learning_node_id' => 31, 'target_learning_node_id' => 32] + $relation);
        $this->reject('relation_cycle', 'LF_RELATION_CYCLE', fn () => $this->insert(
            'core_learning_node_relations',
            ['id' => 52, 'source_learning_node_id' => 32, 'target_learning_node_id' => 30] + $relation,
        ));
    }

    private function verifyTriggerIdentityAndBody(): void
    {
        $contract = json_decode(
            file_get_contents($this->root . '/docs/database/LF-SCHEMA-CONTRACT.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $expected = [];
        foreach ($contract['tables'] as $table) {
            if (!str_starts_with($table['name'], 'core_learning_')) {
                continue;
            }
            foreach ($table['triggers'] ?? [] as $trigger) {
                $expected[] = $trigger['name'];
            }
        }
        sort($expected);
        $actual = $this->pdo->query(
            'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS '
            . 'WHERE TRIGGER_SCHEMA = DATABASE() AND LENGTH(ACTION_STATEMENT) > 20 ORDER BY TRIGGER_NAME',
        )->fetchAll(PDO::FETCH_COLUMN);
        sort($actual);
        if ($actual !== $expected) {
            throw new RuntimeException('Trigger identity/body drift detected: expected='
                . json_encode($expected) . ' actual=' . json_encode($actual));
        }
        $source = file_get_contents(
            $this->root . '/docs/quality/LF-Learning-Foundation-Phase-4C-Trigger-Bodies.sql',
        );
        preg_match_all('/CREATE TRIGGER\s+(\S+)\s+.+?FOR EACH ROW\s+(BEGIN.+?\nEND);/si', $source, $bodies, PREG_SET_ORDER);
        $runtime = $this->pdo->query(
            'SELECT TRIGGER_NAME,ACTION_STATEMENT FROM information_schema.TRIGGERS '
            . 'WHERE TRIGGER_SCHEMA=DATABASE()',
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($bodies as $body) {
            if (!isset($runtime[$body[1]]) || $this->normalizeSql($runtime[$body[1]]) !== $this->normalizeSql($body[2])) {
                throw new RuntimeException("Trigger body drift: {$body[1]}");
            }
        }
        $this->passed++;
        $this->results[] = ['probe' => 'trigger_identity_body_drift', 'sqlstate' => '00000', 'code' => 'PASS'];
        echo 'probe=trigger_identity_body_drift:PASS' . PHP_EOL;
    }

    private function runLifecycleAndImmutabilityMatrix(): void
    {
        $scale = json_encode(['levels' => [
            ['key' => 'novice', 'threshold' => 0],
            ['key' => 'mastered', 'threshold' => 0.8],
        ]], JSON_THROW_ON_ERROR);
        $now = '2026-08-13 10:00:00.000000';

        $this->insert('core_learning_frameworks', [
            'id' => 100, 'customer_id' => 1, 'status' => 'active',
            'default_mastery_scale' => $scale,
        ]);
        $this->reject('scale_duplicate_key', 'LF_SCALE_INVALID', fn () => $this->insert(
            'core_learning_frameworks',
            ['id' => 101, 'status' => 'active', 'default_mastery_scale' =>
                '{"levels":[{"key":"x","threshold":0},{"key":"x","threshold":0.8}]}'],
        ));
        $this->reject('scale_missing_levels', 'LF_SCALE_INVALID', fn () => $this->insert(
            'core_learning_frameworks',
            ['id' => 103, 'status' => 'active', 'default_mastery_scale' => '{}'],
        ));
        $this->reject('scale_malformed_json', 'LF_SCALE_INVALID', fn () => $this->insert(
            'core_learning_frameworks',
            ['id' => 105, 'status' => 'active', 'default_mastery_scale' => '{invalid'],
        ));
        $this->reject('scale_equal_threshold', 'LF_SCALE_INVALID', fn () => $this->insert(
            'core_learning_frameworks',
            ['id' => 104, 'status' => 'active', 'default_mastery_scale' =>
                '{"levels":[{"key":"x","threshold":0.5},{"key":"y","threshold":0.5}]}'],
        ));
        $this->reject('scale_descending_threshold', 'LF_SCALE_INVALID', fn () => $this->insert(
            'core_learning_frameworks',
            ['id' => 102, 'status' => 'active', 'default_mastery_scale' =>
                '{"levels":[{"key":"x","threshold":0.8},{"key":"y","threshold":0.2}]}'],
        ));
        $this->reject('framework_lifecycle', 'LF_FRAMEWORK_LIFECYCLE_INVALID', fn () =>
            $this->pdo->exec("UPDATE core_learning_frameworks SET status='invalid' WHERE id=100"));

        $version = [
            'customer_id' => 1, 'framework_id' => 1, 'mastery_scale_key' => 'scale',
            'mastery_scale_version' => '1', 'mastery_scale_snapshot' => $scale,
            'status' => 'draft_snapshot',
        ];
        $this->insert('core_learning_framework_versions', ['id' => 110] + $version);
        $this->insert('core_learning_framework_versions', ['id' => 111] + $version);
        $this->reject('version_skipped_lifecycle', 'LF_VERSION_LIFECYCLE_INVALID', fn () =>
            $this->pdo->exec("UPDATE core_learning_framework_versions SET status='archived' WHERE id=110"));
        $this->reject('version_missing_publish_actor', 'LF_VERSION_LIFECYCLE_INVALID', fn () =>
            $this->pdo->exec("UPDATE core_learning_framework_versions SET status='published',published_at='{$now}' WHERE id=110"));
        $this->pdo->exec(
            "UPDATE core_learning_framework_versions SET status='published',"
            . "published_at='{$now}',published_by=1 WHERE id=111",
        );
        $this->reject('version_published_payload', 'LF_VERSION_IMMUTABLE', fn () =>
            $this->pdo->exec("UPDATE core_learning_framework_versions SET title_snapshot='changed' WHERE id=111"));
        $this->reject('version_published_delete', 'LF_VERSION_DELETE_FORBIDDEN', fn () =>
            $this->pdo->exec('DELETE FROM core_learning_framework_versions WHERE id=111'));
        $this->reject('version_reversed_lifecycle', 'LF_VERSION_LIFECYCLE_INVALID', fn () =>
            $this->pdo->exec("UPDATE core_learning_framework_versions SET status='draft_snapshot' WHERE id=111"));

        $this->insert('core_learning_node_definitions', ['id' => 120, 'customer_id' => 1, 'framework_id' => 1]);
        $this->reject('definition_cross_identity', 'LF_DEFINITION_IDENTITY_IMMUTABLE', fn () =>
            $this->pdo->exec('UPDATE core_learning_node_definitions SET framework_id=2 WHERE id=120'));
        $this->insert('core_learning_nodes', [
            'id' => 121, 'customer_id' => 1, 'framework_id' => 1,
            'framework_version_id' => 111, 'node_definition_id' => 120,
        ]);
        $this->insert('core_learning_nodes', [
            'id' => 122, 'customer_id' => 1, 'framework_id' => 1,
            'framework_version_id' => 110, 'node_definition_id' => 120,
        ]);
        $this->reject('node_published_update', 'LF_NODE_IMMUTABLE', fn () =>
            $this->pdo->exec("UPDATE core_learning_nodes SET name_snapshot='changed' WHERE id=121"));
        $this->reject('node_published_delete', 'LF_NODE_DELETE_FORBIDDEN', fn () =>
            $this->pdo->exec('DELETE FROM core_learning_nodes WHERE id=121'));
        $this->reject('relation_wrong_owner', 'LF_RELATION_INVALID', fn () => $this->insert(
            'core_learning_node_relations',
            ['id' => 125, 'customer_id' => 1, 'framework_id' => 1,
                'owning_framework_version_id' => 999, 'relation_scope' => 'semantic',
                'relation_type' => 'supports', 'source_learning_node_id' => 121,
                'target_learning_node_id' => 121, 'source_framework_version_id' => 111,
                'target_framework_version_id' => 111, 'review_status' => 'not_required'],
        ));
        $this->reject('relation_cross_scope_vocabulary', 'LF_RELATION_INVALID', fn () => $this->insert(
            'core_learning_node_relations',
            ['id' => 127, 'customer_id' => 1, 'framework_id' => 1,
                'owning_framework_version_id' => 111, 'relation_scope' => 'semantic',
                'relation_type' => 'equivalent_to', 'source_learning_node_id' => 121,
                'target_learning_node_id' => 121, 'source_framework_version_id' => 111,
                'target_framework_version_id' => 111, 'review_status' => 'not_required'],
        ));
        $transitionSnapshot = json_encode([
            'source_framework_version_id' => 110,
            'target_framework_version_id' => 111,
            'policy' => 'requires_review',
        ], JSON_THROW_ON_ERROR);
        $this->insert('core_learning_node_relations', [
            'id' => 126, 'customer_id' => 1, 'framework_id' => 1,
            'owning_framework_version_id' => 111, 'relation_scope' => 'version_transition',
            'relation_type' => 'equivalent_to', 'source_learning_node_id' => 122,
            'target_learning_node_id' => 121, 'source_framework_version_id' => 110,
            'target_framework_version_id' => 111, 'continuity_policy' => 'requires_review',
            'continuity_policy_key' => 'policy', 'continuity_policy_version' => '1',
            'continuity_policy_snapshot' => $transitionSnapshot, 'review_status' => 'pending',
        ]);
        $this->pdo->exec("UPDATE core_learning_node_relations SET review_status='approved',"
            . "resolved_continuity_policy='allow_as_input',approved_by=1,approved_at='{$now}',"
            . "reviewed_by=1,reviewed_at='{$now}',review_reason='approved' WHERE id=126");
        $this->reject('relation_second_resolution', 'LF_RELATION_IMMUTABLE', fn () =>
            $this->pdo->exec("UPDATE core_learning_node_relations SET review_reason='again' WHERE id=126"));
        $this->reject('relation_published_delete', 'LF_RELATION_DELETE_FORBIDDEN', fn () =>
            $this->pdo->exec('DELETE FROM core_learning_node_relations WHERE id=126'));

        $this->insert('core_learning_node_mappings', [
            'id' => 130, 'customer_id' => 1, 'learning_node_id' => 121,
            'source_type' => 'course_version_lesson', 'source_id' => 1,
            'mapping_role' => 'teaches',
        ]);
        $this->reject('mapping_partial_invalidation', 'LF_MAPPING_IMMUTABLE', fn () =>
            $this->pdo->exec("UPDATE core_learning_node_mappings SET invalidated_at='{$now}' WHERE id=130"));
        $this->reject('mapping_semantic_edit', 'LF_MAPPING_IMMUTABLE', fn () =>
            $this->pdo->exec("UPDATE core_learning_node_mappings SET mapping_role='assesses' WHERE id=130"));
        $this->pdo->exec("UPDATE core_learning_node_mappings SET invalidated_at='{$now}',"
            . "invalidated_by=1,invalidation_reason='superseded' WHERE id=130");
        $this->reject('mapping_second_invalidation', 'LF_MAPPING_IMMUTABLE', fn () =>
            $this->pdo->exec("UPDATE core_learning_node_mappings SET invalidation_reason='again' WHERE id=130"));
        $this->reject('mapping_delete', 'LF_MAPPING_IMMUTABLE', fn () =>
            $this->pdo->exec('DELETE FROM core_learning_node_mappings WHERE id=130'));

        $rule = json_encode(
            ['rule_key' => 'teacher', 'rule_version' => '1', 'source_type' => 'teacher_judgment'],
            JSON_THROW_ON_ERROR,
        );
        $evidence = [
            'customer_id' => 1, 'user_id' => 1, 'learning_node_id' => 121,
            'evidence_type' => 'expert_judgment', 'source_type' => 'teacher_judgment',
            'source_id' => 1, 'source_discriminator' => 'manual',
            'producer_idempotency_key' => 'evidence-1', 'source_occurred_at' => $now,
            'evaluated_at' => $now, 'qualification_rule_key' => 'teacher',
            'qualification_rule_version' => '1', 'qualification_rule_snapshot' => $rule,
            'recorded_by' => 1, 'value_numeric' => 0.8,
        ];
        $this->insert('core_learning_evidence', ['id' => 140] + $evidence);
        foreach (['track_events', 'behavioral_signal'] as $index => $source) {
            $this->reject("evidence_source_{$source}", 'LF_EVIDENCE_SOURCE_CLOSED', fn () => $this->insert(
                'core_learning_evidence',
                ['id' => 141 + $index] + array_replace($evidence, ['source_type' => $source]),
            ));
        }
        $this->reject('evidence_source_other', 'LF_EVIDENCE_SOURCE_CLOSED', fn () => $this->insert(
            'core_learning_evidence',
            ['id' => 145] + array_replace($evidence, ['source_type' => 'other']),
        ));
        $this->reject('evidence_malformed_rule', 'LF_EVIDENCE_INVALID', fn () => $this->insert(
            'core_learning_evidence',
            ['id' => 148] + array_replace($evidence, [
                'qualification_rule_snapshot' => '{"rule_key":"wrong"}',
                'producer_idempotency_key' => 'evidence-malformed-rule',
            ]),
        ));
        $this->reject('evidence_update', 'LF_EVIDENCE_IMMUTABLE', fn () =>
            $this->pdo->exec("UPDATE core_learning_evidence SET value_label='changed' WHERE id=140"));
        $this->reject('evidence_delete', 'LF_EVIDENCE_IMMUTABLE', fn () =>
            $this->pdo->exec('DELETE FROM core_learning_evidence WHERE id=140'));
        $this->reject('evidence_cross_user_correction', 'LF_EVIDENCE_INVALID', fn () => $this->insert(
            'core_learning_evidence',
            ['id' => 146, 'supersedes_evidence_id' => 140]
                + array_replace($evidence, ['user_id' => 2, 'producer_idempotency_key' => 'evidence-2']),
        ));
        $this->insert('core_learning_node_definitions', [
            'id' => 123, 'customer_id' => 1, 'framework_id' => 1,
            'code' => 'other-definition', 'node_type' => 'competency',
            'canonical_name' => 'Other Definition', 'status' => 'active',
        ]);
        $this->insert('core_learning_nodes', [
            'id' => 124, 'customer_id' => 1, 'framework_id' => 1,
            'framework_version_id' => 111, 'node_definition_id' => 123,
        ]);
        $this->insert('core_learning_evidence', [
            'id' => 147, 'learning_node_id' => 124,
            'producer_idempotency_key' => 'evidence-other',
        ] + $evidence);

        $calcRule = json_encode(['rule_key' => 'calc', 'rule_version' => '1'], JSON_THROW_ON_ERROR);
        $calculation = [
            'customer_id' => 1, 'user_id' => 1, 'framework_id' => 1,
            'node_definition_id' => 120, 'basis_framework_version_id' => 111,
            'calculation_source' => 'system', 'calculation_idempotency_key' => 'calc-1',
            'mastery_level_key' => 'mastered', 'mastery_score' => 0.8,
            'calculation_rule_key' => 'calc', 'calculation_rule_version' => '1',
            'calculation_rule_snapshot' => $calcRule, 'mastery_scale_key' => 'scale',
            'mastery_scale_version' => '1', 'mastery_scale_snapshot' => $scale,
            'mastery_status_result' => 'established', 'calculated_at' => $now,
        ];
        $this->insert('core_learning_mastery_calculations', ['id' => 150] + $calculation);
        $this->reject('calculation_invalid_source_fields', 'LF_CALCULATION_INVALID', fn () => $this->insert(
            'core_learning_mastery_calculations',
            ['id' => 151] + array_replace($calculation, ['calculated_by' => 1, 'calculation_idempotency_key' => 'calc-2']),
        ));
        $this->reject('calculation_scale_mismatch', 'LF_CALCULATION_INVALID', fn () => $this->insert(
            'core_learning_mastery_calculations',
            ['id' => 152] + array_replace($calculation, [
                'mastery_scale_version' => 'wrong', 'calculation_idempotency_key' => 'calc-3',
            ]),
        ));
        $this->reject('calculation_rule_mismatch', 'LF_CALCULATION_INVALID', fn () => $this->insert(
            'core_learning_mastery_calculations',
            ['id' => 155] + array_replace($calculation, [
                'calculation_rule_snapshot' => '{"rule_key":"wrong","rule_version":"1"}',
                'calculation_idempotency_key' => 'calc-5',
            ]),
        ));
        $this->reject('calculation_wrong_transition', 'LF_CALCULATION_INVALID', fn () => $this->insert(
            'core_learning_mastery_calculations',
            ['id' => 156] + array_replace($calculation, [
                'calculation_source' => 'carry_forward', 'source_calculation_id' => 150,
                'source_node_relation_id' => 126, 'continuity_policy_snapshot' => '{"policy":"wrong"}',
                'calculation_idempotency_key' => 'calc-6',
            ]),
        ));
        $this->reject('calculation_unapproved_continuity', 'LF_CALCULATION_INVALID', fn () => $this->insert(
            'core_learning_mastery_calculations',
            ['id' => 153] + array_replace($calculation, [
                'calculation_source' => 'carry_forward', 'source_calculation_id' => 150,
                'source_node_relation_id' => 50, 'continuity_policy_snapshot' => '{}',
                'calculation_idempotency_key' => 'calc-4',
            ]),
        ));
        $this->reject('calculation_update', 'LF_CALCULATION_IMMUTABLE', fn () =>
            $this->pdo->exec('UPDATE core_learning_mastery_calculations SET mastery_score=0.7 WHERE id=150'));
        $this->reject('calculation_delete', 'LF_CALCULATION_IMMUTABLE', fn () =>
            $this->pdo->exec('DELETE FROM core_learning_mastery_calculations WHERE id=150'));

        $this->insert('core_learning_calculation_evidence', [
            'id' => 160, 'customer_id' => 1, 'user_id' => 1,
            'mastery_calculation_id' => 150, 'evidence_id' => 140,
            'evidence_role' => 'included', 'effective_weight' => 1,
        ]);
        $this->reject('calculation_evidence_cross_user', 'LF_CALC_EVIDENCE_INVALID', fn () => $this->insert(
            'core_learning_calculation_evidence',
            ['id' => 161, 'customer_id' => 1, 'user_id' => 2,
                'mastery_calculation_id' => 150, 'evidence_id' => 140],
        ));
        $this->reject('calculation_evidence_cross_definition', 'LF_CALC_EVIDENCE_INVALID', fn () => $this->insert(
            'core_learning_calculation_evidence',
            ['id' => 162, 'customer_id' => 1, 'user_id' => 1,
                'mastery_calculation_id' => 150, 'evidence_id' => 147,
                'evidence_role' => 'continuity_input', 'effective_weight' => 1],
        ));
        $pendingSnapshot = json_encode([
            'source_framework_version_id' => 110,
            'target_framework_version_id' => 111,
            'policy' => 'requires_review',
        ], JSON_THROW_ON_ERROR);
        $this->insert('core_learning_node_relations', [
            'id' => 128, 'customer_id' => 1, 'framework_id' => 1,
            'owning_framework_version_id' => 111, 'relation_scope' => 'version_transition',
            'relation_type' => 'equivalent_to', 'source_learning_node_id' => 122,
            'target_learning_node_id' => 124, 'source_framework_version_id' => 110,
            'target_framework_version_id' => 111, 'continuity_policy' => 'requires_review',
            'continuity_policy_key' => 'policy', 'continuity_policy_version' => '1',
            'continuity_policy_snapshot' => $pendingSnapshot, 'review_status' => 'pending',
        ]);
        $this->pdo->exec('DROP TRIGGER trg_lrn_calcs_bi_validate');
        $this->insert('core_learning_mastery_calculations', [
            'id' => 157,
        ] + array_replace($calculation, [
            'node_definition_id' => 123, 'calculation_source' => 'carry_forward',
            'source_calculation_id' => 150, 'source_node_relation_id' => 128,
            'continuity_policy_snapshot' => $pendingSnapshot,
            'calculation_idempotency_key' => 'calc-pending-continuity',
        ]));
        $this->installOneTrigger('trg_lrn_calcs_bi_validate');
        $this->reject('calculation_evidence_unapproved_continuity', 'LF_CALC_EVIDENCE_INVALID', fn () => $this->insert(
            'core_learning_calculation_evidence',
            ['id' => 163, 'customer_id' => 1, 'user_id' => 1,
                'mastery_calculation_id' => 157, 'evidence_id' => 140,
                'evidence_role' => 'continuity_input', 'effective_weight' => 1],
        ));
        $this->reject('calculation_evidence_update', 'LF_CALC_EVIDENCE_IMMUTABLE', fn () =>
            $this->pdo->exec("UPDATE core_learning_calculation_evidence SET reason_code='changed' WHERE id=160"));
        $this->reject('calculation_evidence_delete', 'LF_CALC_EVIDENCE_IMMUTABLE', fn () =>
            $this->pdo->exec('DELETE FROM core_learning_calculation_evidence WHERE id=160'));

        $profile = [
            'customer_id' => 1, 'user_id' => 1, 'framework_id' => 1,
            'node_definition_id' => 120, 'basis_framework_version_id' => 111,
            'current_calculation_id' => 150, 'mastery_level_key' => 'mastered',
            'mastery_score' => 0.8, 'mastery_status' => 'established', 'calculated_at' => $now,
        ];
        $this->insert('core_learning_mastery_profiles', ['id' => 170] + $profile);
        $this->reject('profile_value_mismatch', 'LF_PROFILE_MISMATCH', fn () => $this->insert(
            'core_learning_mastery_profiles',
            ['id' => 171] + array_replace($profile, ['mastery_score' => 0.2]),
        ));
        $this->reject('profile_identity_mutation', 'LF_PROFILE_MISMATCH', fn () =>
            $this->pdo->exec('UPDATE core_learning_mastery_profiles SET user_id=2 WHERE id=170'));
        $this->reject('profile_unrelated_calculation', 'LF_PROFILE_MISMATCH', fn () => $this->insert(
            'core_learning_mastery_profiles',
            ['id' => 172] + array_replace($profile, ['current_calculation_id' => 999]),
        ));
        $this->insert('core_learning_mastery_calculations', [
            'id' => 154,
        ] + array_replace($calculation, [
            'calculation_idempotency_key' => 'calc-stale',
            'calculated_at' => '2026-08-12 10:00:00.000000',
        ]));
        $this->reject('profile_stale_ordering', 'LF_PROFILE_STALE', fn () =>
            $this->pdo->exec("UPDATE core_learning_mastery_profiles SET current_calculation_id=154,"
                . "calculated_at='2026-08-12 10:00:00.000000' WHERE id=170"));
    }

    private function runCheckAndRollbackMatrix(): void
    {
        $this->rejectConstraint('check_constraint_enforced', fn () => $this->insert(
            'core_learning_node_mappings',
            ['id' => 180, 'source_type' => 'course_version_lesson',
                'mapping_role' => 'teaches', 'weight' => -1],
        ));
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM core_learning_evidence')->fetchColumn();
        $this->pdo->beginTransaction();
        try {
            $this->insert('core_learning_evidence', [
                'id' => 181, 'source_type' => 'behavioral_signal',
            ]);
        } catch (PDOException $exception) {
            if (!str_contains($exception->getMessage(), 'LF_EVIDENCE_SOURCE_CLOSED')) {
                throw $exception;
            }
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }
        $after = (int) $this->pdo->query('SELECT COUNT(*) FROM core_learning_evidence')->fetchColumn();
        if ($before !== $after) {
            throw new RuntimeException('Failed trigger changed transaction state.');
        }
        $this->passed++;
        $this->results[] = ['probe' => 'transaction_rollback', 'sqlstate' => '00000', 'code' => 'PASS'];
        echo 'probe=transaction_rollback:PASS' . PHP_EOL;
    }

    private function rejectConstraint(string $name, callable $operation): void
    {
        try {
            $operation();
            throw new RuntimeException("{$name} unexpectedly accepted.");
        } catch (PDOException $exception) {
            if (($exception->errorInfo[0] ?? '') !== '23000'
                || !str_contains($exception->getMessage(), 'CONSTRAINT')) {
                throw $exception;
            }
            $this->passed++;
            $this->results[] = ['probe' => $name, 'sqlstate' => '23000', 'code' => 'CHECK_CONSTRAINT'];
            echo "probe={$name}:PASS" . PHP_EOL;
        }
    }

    private function reject(string $name, string $code, callable $operation): void
    {
        try {
            $operation();
            throw new RuntimeException("{$name} unexpectedly accepted.");
        } catch (PDOException $exception) {
            if (($exception->errorInfo[0] ?? '') !== '45000'
                || !preg_match('/\b' . preg_quote($code, '/') . '$/', $exception->getMessage())) {
                throw $exception;
            }
            $this->passed++;
            $this->results[] = ['probe' => $name, 'sqlstate' => '45000', 'code' => $code];
            echo "probe={$name}:PASS" . PHP_EOL;
        }
    }

    private function normalizeSql(string $sql): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', str_replace('`', '', $sql))));
    }

    private function installOneTrigger(string $name): void
    {
        $source = file_get_contents(
            $this->root . '/docs/quality/LF-Learning-Foundation-Phase-4C-Trigger-Bodies.sql',
        );
        if (!preg_match('/CREATE TRIGGER\s+' . preg_quote($name, '/') . '\s+.+?\nEND;/si', $source, $match)) {
            throw new RuntimeException("Missing trigger source: {$name}");
        }
        $this->pdo->exec($match[0]);
    }

    private function insert(string $table, array $values): void
    {
        $columns = array_keys($values);
        $sql = "INSERT INTO `{$table}` (`" . implode('`,`', $columns) . '`) VALUES ('
            . implode(',', array_fill(0, count($columns), '?')) . ')';
        $this->pdo->prepare($sql)->execute(array_values($values));
    }

    private function columnSql(array $column): string
    {
        $type = match ($column['type']) {
            'bigint' => 'BIGINT' . (!empty($column['unsigned']) ? ' UNSIGNED' : ''),
            'int' => 'INT', 'decimal' => 'DECIMAL(18,6)', 'varchar' => 'VARCHAR(255)',
            'text' => 'TEXT', 'json' => 'JSON', 'datetime' => 'DATETIME(6)',
            'timestamp' => 'TIMESTAMP(6) NULL',
        };
        return "`{$column['name']}` {$type}" . (!empty($column['auto_increment']) ? ' AUTO_INCREMENT' : '');
    }

    private function readEnvValue(string $key): string
    {
        foreach (file($this->root . '/.env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_starts_with($line, "{$key}=")) {
                return trim(trim(substr($line, strlen($key) + 1)), "\"'");
            }
        }
        return '';
    }
}

(new Phase4CTriggerMatrixHarness(dirname(__DIR__, 2)))->run();

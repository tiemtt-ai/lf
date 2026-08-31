<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

if (array_diff(array_slice($argv, 1), ['--feature-only', '--queue-only', '--crop-only', '--schema-only']) !== []) {
    throw new RuntimeException('Only --feature-only, --queue-only --crop-only or --schema-only is supported.');
}

// Rehearse only in a newly-created disposable database, never DB_DATABASE.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if ($app->environment('production')) {
    throw new RuntimeException('Disposable review is prohibited in production.');
}
$base = config('database.connections.'.config('database.default'));
if (! in_array($base['driver'] ?? null, ['mysql', 'mariadb'], true)) {
    throw new RuntimeException('Configure local MariaDB before this rehearsal.');
}
$name = 'lf_document_review_'.bin2hex(random_bytes(8));
if (! preg_match('/^lf_document_review_[a-f0-9]{16}$/D', $name)) {
    throw new RuntimeException('Unsafe disposable database name.');
}
$admin = array_replace($base, ['url' => null, 'database' => null]);
config(['database.connections.document_review_admin' => $admin]);
$connection = DB::connection('document_review_admin');
$serverVersion = $connection->selectOne('SELECT VERSION() AS version')->version;
$floor = str_contains($serverVersion, 'MariaDB') ? '10.5.0' : '8.0.16';
if (version_compare(explode('-', $serverVersion)[0], $floor, '<')) {
    throw new RuntimeException('Local database is below the supported version floor: '.$serverVersion);
}
$created = false;
$exit = 1;
try {
    $connection->statement("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created = true;
    echo 'Disposable database: '.$name.PHP_EOL;
    echo 'Server: '.$serverVersion.PHP_EOL;
    $environment = [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $name,
        'DB_URL' => '', 'DB_HOST' => $base['host'], 'DB_PORT' => (string) $base['port'],
        'DB_USERNAME' => $base['username'], 'DB_PASSWORD' => $base['password'],
        'DB_SOCKET' => $base['unix_socket'] ?? '',
    ];
    $tests = ['tests/Feature/DocumentProcessingLocalReviewTest.php'];
    if (in_array('--schema-only', $argv, true)) {
        $tests = ['tests/Integration/MediaStructuredExtractionMariaDbTest.php'];
    } elseif (in_array('--crop-only', $argv, true)) {
        $tests = [...$tests, '--filter=test_late_crop_writer_and_cleanup'];
    } elseif (in_array('--queue-only', $argv, true)) {
        $tests = ['tests/Integration/DocumentQueueRecoveryMariaDbTest.php'];
    } elseif (! in_array('--feature-only', $argv, true)) {
        $tests = [...$tests,
            'tests/Integration/MediaProcessingSubstrateMariaDbTest.php',
            'tests/Integration/MediaStructuredExtractionMariaDbTest.php',
            'tests/Integration/MediaProcessingJobKeyWidthMariaDbTest.php',
        ];
    }
    $process = new Process([PHP_BINARY, 'vendor/bin/phpunit', '--stop-on-error', ...$tests], base_path(), $environment);
    $process->setTimeout(1800);
    $exit = $process->run(static function ($type, $buffer): void {
        echo $buffer;
    });
    $checks = $connection->select('SELECT TABLE_NAME, CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME IN (\'media_extracted_texts\',\'media_extracted_tables\',\'media_table_cells\') ORDER BY TABLE_NAME, CONSTRAINT_NAME', [$name]);
    $timestamp = $connection->select('SELECT COLUMN_DEFAULT, IS_NULLABLE, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$name, 'media_access_logs', 'accessed_at']);
    echo "Timestamp default evidence:\n".json_encode($timestamp).PHP_EOL;
    echo "Timestamp server setting:\n".json_encode($connection->select("SHOW VARIABLES LIKE 'explicit_defaults_for_timestamp'")).PHP_EOL;
    echo "Physical CHECK evidence:\n".json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Disposable review failed: '.$exception::class.PHP_EOL);
    $exit = 1;
} finally {
    if ($created) {
        $connection->statement("DROP DATABASE `{$name}`");
        echo 'Dropped disposable database: '.$name.PHP_EOL;
    }
    DB::disconnect('document_review_admin');
}
exit($exit);

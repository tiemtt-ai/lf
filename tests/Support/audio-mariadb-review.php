<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Chay lai bo test Audio local tren MariaDB that, trong mot database dung mot
 * lan. Khong cham DB_DATABASE, khong sua server dang chay cua developer.
 */
if (array_diff(array_slice($argv, 1), ['--feature-only', '--schema-only', '--queue-only']) !== []) {
    throw new RuntimeException('Only --feature-only, --schema-only or --queue-only is supported.');
}

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
$name = 'lf_audio_review_'.bin2hex(random_bytes(8));
if (! preg_match('/^lf_audio_review_[a-f0-9]{16}$/D', $name)) {
    throw new RuntimeException('Unsafe disposable database name.');
}
$admin = array_replace($base, ['url' => null, 'database' => null]);
config(['database.connections.audio_review_admin' => $admin]);
$connection = DB::connection('audio_review_admin');
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
    // Harness nay bootstrap Laravel de doc cau hinh database, nen Dotenv da nap
    // `.env` VAO ENVIRONMENT cua tien trinh. Tien trinh PHPUnit con thua ke no, va
    // `<env>` trong phpunit.xml KHONG ghi de mot bien da ton tai vi khong khai
    // `force="true"`. Bien quyet dinh la `QUEUE_CONNECTION`: `.env` dat `redis`,
    // nen job di vao Redis thay vi chay sync va KHONG output nao duoc tao.
    //
    // Go DUNG tap bien ma phpunit.xml dinh nghia, doc tu chinh file do thay vi mot
    // danh sach chep tay. `false` xoa bien khoi child environment.
    $suiteEnvironment = simplexml_load_file(base_path('phpunit.xml'));
    foreach ($suiteEnvironment?->php?->env ?? [] as $declared) {
        $key = (string) $declared['name'];
        if ($key !== '' && ! array_key_exists($key, $environment)) {
            $environment[$key] = false;
        }
    }
    $tests = ['tests/Feature/AudioProcessingLocalReviewTest.php'];
    if (in_array('--schema-only', $argv, true)) {
        $tests = ['tests/Integration/MediaProcessingSubstrateMariaDbTest.php'];
    } elseif (in_array('--queue-only', $argv, true)) {
        $tests = ['tests/Integration/AudioQueueRecoveryMariaDbTest.php'];
    } elseif (! in_array('--feature-only', $argv, true)) {
        // MediaProcessingSubstrateTest CO Y khong nam o day: fixture cua no chen
        // truc tiep cac row `media_processing_jobs` vi pham chinh CHECK that
        // (`chk_mpj_ready`, `chk_mpj_output_pair`). SQLite bo qua CHECK nen chung
        // xanh o suite mac dinh. Day la no cua fixture Document/Video/Caption,
        // ngoai pham vi Audio local; xem review Audio § Findings ngoai pham vi.
        // AudioQueueRecoveryMariaDbTest nam o `--queue-only`, dung precedent cua
        // document-mariadb-review.php: no dieu phoi bon tien trinh that cong engine
        // that nen chiem nhieu phut va nhay voi tai may. Giu no ngoai luot mac dinh
        // de mot lan review binh thuong khong phu thuoc vao dieu do.
        $tests = [...$tests,
            'tests/Integration/MediaProcessingSubstrateMariaDbTest.php',
            'tests/Integration/MediaCaptionProvenanceMariaDbTest.php',
            'tests/Integration/MediaProcessingJobKeyWidthMariaDbTest.php',
        ];
    }
    $process = new Process([PHP_BINARY, 'vendor/bin/phpunit', '--stop-on-error', ...$tests], base_path(), $environment);
    $process->setTimeout(3600);
    $exit = $process->run(static function ($type, $buffer): void {
        echo $buffer;
    });

    // Bang chung vat ly cho phan Database/provenance cua review Audio.
    $checks = $connection->select(
        'SELECT TABLE_NAME, CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS '
        ."WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME IN ('media_transcripts','media_processing_jobs','media_access_logs') "
        .'ORDER BY TABLE_NAME, CONSTRAINT_NAME', [$name]);
    echo "Physical CHECK evidence:\n".json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    $keys = $connection->select(
        'SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME '
        .'FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? '
        .'AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION',
        [$name, 'media_transcripts']);
    echo "media_transcripts tenant foreign keys:\n".json_encode($keys, JSON_PRETTY_PRINT).PHP_EOL;
    $indexes = $connection->select(
        'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME FROM information_schema.STATISTICS '
        .'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$name, 'media_transcripts']);
    echo "media_transcripts indexes:\n".json_encode($indexes, JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Disposable review failed: '.$exception::class.PHP_EOL);
    $exit = 1;
} finally {
    if ($created) {
        $connection->statement("DROP DATABASE `{$name}`");
        echo 'Dropped disposable database: '.$name.PHP_EOL;
    }
    DB::disconnect('audio_review_admin');
}
exit($exit);

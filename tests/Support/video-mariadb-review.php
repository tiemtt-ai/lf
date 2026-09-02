<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Chay lai bo test Video Transcript + Caption tren MariaDB that, trong mot
 * database dung mot lan. Khong cham DB_DATABASE, khong sua server dang chay.
 *
 * MariaDB cuong che CHECK; SQLite thi khong. Nhieu bat bien cua caption
 * (`chk_mc_provenance`, `chk_mc_ready`) chi lo ra o day.
 */
if (array_diff(array_slice($argv, 1), ['--feature-only']) !== []) {
    throw new RuntimeException('Only --feature-only is supported.');
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
$name = 'lf_video_review_'.bin2hex(random_bytes(8));
if (! preg_match('/^lf_video_review_[a-f0-9]{16}$/D', $name)) {
    throw new RuntimeException('Unsafe disposable database name.');
}
$admin = array_replace($base, ['url' => null, 'database' => null]);
config(['database.connections.video_review_admin' => $admin]);
$connection = DB::connection('video_review_admin');
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
    // nen job di vao Redis thay vi chay sync va KHONG output nao duoc tao — test
    // do hang loat vi mot ly do khong lien quan gi den thu chung dang do.
    //
    // Go DUNG tap bien ma phpunit.xml dinh nghia, doc tu chinh file do thay vi mot
    // danh sach chep tay: them mot `<env>` moi vao phpunit.xml se tu dong duoc phu.
    // `false` bao Symfony Process xoa bien khoi child environment.
    $suiteEnvironment = simplexml_load_file(base_path('phpunit.xml'));
    foreach ($suiteEnvironment?->php?->env ?? [] as $declared) {
        $key = (string) $declared['name'];
        if ($key !== '' && ! array_key_exists($key, $environment)) {
            $environment[$key] = false;
        }
    }
    $tests = ['tests/Feature/VideoTranscriptCaptionLocalReviewTest.php'];
    if (! in_array('--feature-only', $argv, true)) {
        $tests = [...$tests,
            'tests/Feature/MediaRevisionLifecycleTest.php',
            'tests/Integration/MediaCaptionProvenanceMariaDbTest.php',
        ];
    }
    // MOI file mot tien trinh PHPUnit rieng. Hai Feature class dung chung
    // `Storage::fake('media_local')` va `TenantContext` tinh; chay chung tien
    // trinh thi trang thai cua class truoc ro ri sang class sau va sinh loi
    // khong lien quan gi den thu dang do. Ca hai class deu xanh khi chay rieng.
    $exit = 0;
    foreach ($tests as $test) {
        $process = new Process([PHP_BINARY, 'vendor/bin/phpunit', $test], base_path(), $environment);
        $process->setTimeout(3600);
        $status = $process->run(static function ($type, $buffer): void {
            echo $buffer;
        });
        $exit = $status === 0 ? $exit : $status;
    }

    $checks = $connection->select(
        'SELECT TABLE_NAME, CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS '
        ."WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = 'media_captions' ORDER BY CONSTRAINT_NAME", [$name]);
    echo "media_captions physical CHECK evidence:\n".json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Disposable review failed: '.$exception::class.PHP_EOL);
    $exit = 1;
} finally {
    if ($created) {
        $connection->statement("DROP DATABASE `{$name}`");
        echo 'Dropped disposable database: '.$name.PHP_EOL;
    }
    DB::disconnect('video_review_admin');
}
exit($exit);

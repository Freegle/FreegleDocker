<?php

// Clear config cache before loading Laravel.
// When running tests, we need phpunit.xml env vars to take effect.
// If config is cached, Laravel ignores env vars and uses the cached values.
// The cached config has MAIL_MAILER=smtp (from docker-compose), which breaks
// tests that expect the array driver from phpunit.xml.
$configCachePath = __DIR__.'/../bootstrap/cache/config.php';
if (file_exists($configCachePath)) {
    @unlink($configCachePath);
}

// Validate bootstrap cache files before loading Laravel.
// If services.php is empty or corrupted (returns non-array), delete it.
// Laravel will regenerate it on bootstrap.
// This fixes issues where services.php becomes empty and require returns 1 (integer).
$servicesPath = __DIR__.'/../bootstrap/cache/services.php';
if (file_exists($servicesPath)) {
    $content = file_get_contents($servicesPath);
    // If file is empty, very small, or doesn't contain 'return array', it's corrupt.
    if (strlen($content) < 100 || strpos($content, 'return array') === false) {
        echo "WARNING: Clearing corrupt services.php (size: ".strlen($content)." bytes)\n";
        @unlink($servicesPath);
        @unlink(__DIR__.'/../bootstrap/cache/packages.php');
    }
}

// ParaTest support: each worker gets its own bootstrap cache directory to prevent corruption.
// When running parallel tests, multiple workers writing to the same services.php causes race conditions.
$uniqueToken = getenv('UNIQUE_TEST_TOKEN') ?: getenv('TEST_TOKEN') ?: '';
if ($uniqueToken) {
    $workerCacheDir = "/tmp/laravel-worker-cache-{$uniqueToken}";
    // Must create both the bootstrap path and its cache subdirectory
    $cacheSubdir = "{$workerCacheDir}/cache";
    if (!is_dir($cacheSubdir)) {
        mkdir($cacheSubdir, 0777, true);
    }
    // Set environment variable that bootstrap/app.php reads
    putenv("PARATEST_BOOTSTRAP_CACHE={$workerCacheDir}");
}

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Force config to use test database
config(['database.connections.mysql.database' => 'iznik_batch_test']);

// Note: Mail driver is set via phpunit.xml <env name="MAIL_MAILER" value="array" force="true"/>
// This works because we clear the config cache above, so Laravel reads from env vars.

// Ensure test database exists
$pdo = new PDO('mysql:host='.env('DB_HOST'), env('DB_USERNAME'), env('DB_PASSWORD'));
$pdo->exec('CREATE DATABASE IF NOT EXISTS iznik_batch_test');

// Run migrations (not fresh - preserves existing data for speed)
echo "Running migrate on iznik_batch_test...\n";
$exitCode = \Illuminate\Support\Facades\Artisan::call('migrate', [
    '--database' => 'mysql',
    '--force' => true,
]);

if ($exitCode !== 0) {
    echo \Illuminate\Support\Facades\Artisan::output();
    die("Migration failed with exit code $exitCode\n");
}
echo "Migrations complete.\n";

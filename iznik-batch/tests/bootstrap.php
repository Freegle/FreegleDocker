<?php

// Set test database BEFORE loading anything
$_ENV['DB_DATABASE'] = 'iznik_batch_test';
$_SERVER['DB_DATABASE'] = 'iznik_batch_test';
putenv('DB_DATABASE=iznik_batch_test');

// For ParaTest: each worker gets its own bootstrap cache directory to prevent corruption.
// When running parallel tests, multiple workers writing to the same services.php causes race conditions.
$uniqueToken = getenv('UNIQUE_TEST_TOKEN') ?: getenv('TEST_TOKEN') ?: '';
if ($uniqueToken) {
    $workerCacheDir = "/tmp/laravel-worker-cache-{$uniqueToken}";
    // Must create both the bootstrap path and its cache subdirectory
    $cacheSubdir = "{$workerCacheDir}/cache";
    if (!is_dir($cacheSubdir)) {
        mkdir($cacheSubdir, 0777, true);
    }

    // Copy pre-generated bootstrap cache files to worker directory.
    // This prevents workers from regenerating these files which can cause race conditions
    // and interfere with the view cache that was pre-compiled by view:cache.
    $mainCacheDir = __DIR__ . '/../bootstrap/cache';
    foreach (['services.php', 'packages.php'] as $cacheFile) {
        $src = "{$mainCacheDir}/{$cacheFile}";
        $dst = "{$cacheSubdir}/{$cacheFile}";
        if (file_exists($src) && !file_exists($dst)) {
            copy($src, $dst);
        }
    }

    // Set environment variable that bootstrap/app.php reads
    putenv("PARATEST_BOOTSTRAP_CACHE={$workerCacheDir}");
}

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Force config to use test database
config(['database.connections.mysql.database' => 'iznik_batch_test']);

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

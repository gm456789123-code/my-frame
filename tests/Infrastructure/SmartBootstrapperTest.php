<?php

declare(strict_types=1);

use App\Infrastructure\Installer\SmartBootstrapper;

it('detects environment and directory structure accurately', function (): void {
    $bootstrapper = new SmartBootstrapper();
    $env = $bootstrapper->detectEnvironment();

    expect($env['projectRoot'])->not->toBeEmpty()
        ->and(is_dir($env['projectRoot']))->toBeTrue()
        ->and($env['siteUrl'])->toContain('http');
});

it('generates secure wp-config with dynamic salts', function (): void {
    $bootstrapper = new SmartBootstrapper();
    $tmpDir = sys_get_temp_dir() . '/test_wp_config_' . bin2hex(random_bytes(4));
    mkdir($tmpDir, 0755, true);

    $logs = [];
    $success = $bootstrapper->generateWpConfig(
        $tmpDir,
        ['host' => 'localhost', 'db' => 'test_wp', 'user' => 'root', 'pass' => 'secret'],
        function (string $msg) use (&$logs): void {
            $logs[] = $msg;
        }
    );

    expect($success)->toBeTrue()
        ->and(is_file($tmpDir . '/wp-config.php'))->toBeTrue();

    $content = (string) file_get_contents($tmpDir . '/wp-config.php');
    expect($content)->toContain("define('DB_NAME', 'test_wp');")
        ->and($content)->toContain("define('AUTH_KEY',")
        ->and($content)->toContain("define('NONCE_SALT',");

    @unlink($tmpDir . '/wp-config.php');
    @rmdir($tmpDir);
});

it('activates sovereign theme in options table', function (): void {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE wp_options (
        option_id INTEGER PRIMARY KEY AUTOINCREMENT,
        option_name VARCHAR(191) NOT NULL UNIQUE,
        option_value TEXT NOT NULL,
        autoload VARCHAR(20) NOT NULL DEFAULT \'yes\'
    )');

    $bootstrapper = new SmartBootstrapper($pdo);
    $logs = [];

    $success = $bootstrapper->activateSovereignTheme(function (string $msg) use (&$logs): void {
        $logs[] = $msg;
    });

    expect($success)->toBeTrue();

    $stmt = $pdo->query("SELECT option_value FROM wp_options WHERE option_name = 'stylesheet'");
    $val = $stmt !== false ? $stmt->fetchColumn() : false;

    expect($val)->toBe('sovereign');
});

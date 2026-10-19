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

it('generates a fresh random APP_KEY per install instead of a hardcoded default', function (): void {
    $bootstrapper = new SmartBootstrapper();
    $tmpDirA = sys_get_temp_dir() . '/test_wp_config_' . bin2hex(random_bytes(4));
    $tmpDirB = sys_get_temp_dir() . '/test_wp_config_' . bin2hex(random_bytes(4));
    mkdir($tmpDirA, 0755, true);
    mkdir($tmpDirB, 0755, true);

    $noop = function (string $msg): void {
    };
    $dbConfig = ['host' => 'localhost', 'db' => 'test_wp', 'user' => 'root', 'pass' => 'secret'];
    $bootstrapper->generateWpConfig($tmpDirA, $dbConfig, $noop);
    $bootstrapper->generateWpConfig($tmpDirB, $dbConfig, $noop);

    $contentA = (string) file_get_contents($tmpDirA . '/wp-config.php');
    $contentB = (string) file_get_contents($tmpDirB . '/wp-config.php');

    preg_match("/define\\('APP_KEY', '([0-9a-f]+)'\\);/", $contentA, $matchA);
    preg_match("/define\\('APP_KEY', '([0-9a-f]+)'\\);/", $contentB, $matchB);

    $keyA = $matchA[1] ?? null;
    $keyB = $matchB[1] ?? null;

    expect($keyA)->not->toBeNull()
        ->and($keyB)->not->toBeNull()
        ->and(strlen((string) $keyA))->toBe(64) // 32 random bytes, hex-encoded
        ->and($keyA)->not->toBe($keyB) // every install gets its own key
        ->and($keyA)->not->toBe('default-32-byte-secret-key-1234')
        ->and($keyA)->not->toBe('sovereign-default-secret-key-32b');

    @unlink($tmpDirA . '/wp-config.php');
    @unlink($tmpDirB . '/wp-config.php');
    @rmdir($tmpDirA);
    @rmdir($tmpDirB);
});

it('escapes credentials so a quote in the password cannot inject PHP into wp-config', function (): void {
    $bootstrapper = new SmartBootstrapper();
    $tmpDir = sys_get_temp_dir() . '/test_wp_config_' . bin2hex(random_bytes(4));
    mkdir($tmpDir, 0755, true);

    $evil = "x'); echo 'pwned'; //\\";
    $bootstrapper->generateWpConfig(
        $tmpDir,
        ['host' => 'localhost', 'db' => 'test_wp', 'user' => 'root', 'pass' => $evil],
        function (string $msg): void {
        }
    );

    $content = (string) file_get_contents($tmpDir . '/wp-config.php');
    // Evaluate only the DB_PASSWORD line in isolation and prove the value
    // round-trips exactly instead of terminating the string literal.
    preg_match("/define\\('DB_PASSWORD', (.*)\\);/", $content, $m);
    $literal = $m[1] ?? '';
    $roundTripped = eval('return ' . $literal . ';');

    expect($roundTripped)->toBe($evil);

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

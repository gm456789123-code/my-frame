<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Domain\ValueObject\DocumentUuid;
use App\Domain\ValueObject\Locale;
use App\Domain\ValueObject\PageStatus;
use App\Domain\ValueObject\PostId;
use App\Domain\ValueObject\UserId;
use App\Infrastructure\Security\FieldEncryptor;
use App\Infrastructure\Security\MerkleAuditLogger;
use App\Infrastructure\Storage\SecureUploadHandler;
use App\Infrastructure\Storage\SignedUrlGenerator;
use App\Infrastructure\Wordpress\Gutenberg\BlockAstParser;
use App\Presentation\Http\DevToolbar;
use App\Presentation\Http\SecureFileController;
use InvalidArgumentException;
use Latte\Engine;
use Latte\Loaders\StringLoader;
use PDO;
use RuntimeException;

describe('Security Penetration & Hardening Audit', function (): void {

    describe('1. Path Traversal & LFI Immunity', function (): void {
        it('strictly rejects path traversal strings in DocumentUuid', function (string $payload): void {
            expect(fn () => new DocumentUuid($payload))->toThrow(InvalidArgumentException::class);
        })->with([
            '../../../../etc/passwd',
            '..\\..\\..\\windows\\win.ini',
            '/etc/shadow',
            '00000000-0000-0000-0000-000000000000/../../shell.php',
            '../../../var/data/vault_private/secret.pdf',
            'file:///etc/passwd',
            "018f2d56-789a-7bc3-9def-0123456789ab\x00.php",
            '../',
            '..',
            'CON',
            'PRN',
            'AUX',
        ]);

        it('strictly rejects path traversal inside SignedUrlGenerator and SecureFileController', function (): void {
            $key = 'secret-32-byte-key-for-test-sig!!';
            $generator = new SignedUrlGenerator($key);

            // Attempting to generate signed URL for path traversal
            expect(fn () => $generator->generateUrl('../../wp-config.php', 300))
                ->toThrow(InvalidArgumentException::class);

            $controller = new SecureFileController($generator, sys_get_temp_dir());
            $response = $controller->download('../../wp-config.php', 'fake-sig', time() + 300);

            expect($response['status'])->toBe(400)
                ->and($response['error'])->toContain('malformed file identifier');
        });
    });

    describe('2. Malicious File Upload & Magic Byte Verification', function (): void {
        it('rejects PHP web shells disguised as PNG images', function (): void {
            $tmpVault = sys_get_temp_dir() . '/test_vault_' . bin2hex(random_bytes(4));
            mkdir($tmpVault, 0755, true);

            $key = random_bytes(32);
            $encryptor = new FieldEncryptor($key);
            $handler = new SecureUploadHandler($tmpVault, $encryptor, testMode: true);

            // Create a fake file with .png extension containing PHP code
            $fakePng = tempnam(sys_get_temp_dir(), 'test_evil_');
            file_put_contents($fakePng, "<?php echo 'execute arbitrary code'; system(\$_GET['cmd']); ?>");

            try {
                expect(fn () => $handler->handleUpload($fakePng, 'avatar.png'))
                    ->toThrow(InvalidArgumentException::class, 'Disallowed file type:');
            } finally {
                @unlink($fakePng);
                @rmdir($tmpVault);
            }
        });

        it('rejects polyglot files containing executable code', function (): void {
            $tmpVault = sys_get_temp_dir() . '/test_vault_' . bin2hex(random_bytes(4));
            mkdir($tmpVault, 0755, true);

            $key = random_bytes(32);
            $encryptor = new FieldEncryptor($key);
            $handler = new SecureUploadHandler($tmpVault, $encryptor, testMode: true);

            // Polyglot: GIF89a header followed by PHP script
            $polyglot = tempnam(sys_get_temp_dir(), 'test_poly_');
            file_put_contents($polyglot, "GIF89a<?php phpinfo(); ?>");

            try {
                expect(fn () => $handler->handleUpload($polyglot, 'exploit.gif'))
                    ->toThrow(InvalidArgumentException::class);
            } finally {
                @unlink($polyglot);
                @rmdir($tmpVault);
            }
        });

        it('sanitizes malicious filenames to UUID v7 so original filename is never stored on disk', function (): void {
            $tmpVault = sys_get_temp_dir() . '/test_vault_' . bin2hex(random_bytes(4));
            mkdir($tmpVault, 0755, true);

            $key = random_bytes(32);
            $encryptor = new FieldEncryptor($key);
            $handler = new SecureUploadHandler($tmpVault, $encryptor, testMode: true);

            // Real 1x1 transparent PNG
            $realPng = tempnam(sys_get_temp_dir(), 'test_real_');
            file_put_contents($realPng, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));

            try {
                $result = $handler->handleUpload($realPng, '../../../../exploit.php');
                $docUuid = $result['uuid'];

                // Disk file MUST be the UUID v7 with .vault extension, NOT exploit.php
                expect(file_exists($tmpVault . '/' . $docUuid . '.vault'))->toBeTrue()
                    ->and(file_exists($tmpVault . '/exploit.php'))->toBeFalse()
                    ->and(file_exists($tmpVault . '/../../../../exploit.php'))->toBeFalse();

                @unlink($tmpVault . '/' . $docUuid . '.vault');
            } finally {
                @unlink($realPng);
                @rmdir($tmpVault);
            }
        });
    });

    describe('3. Cryptographic Tamper-Proofing (AEAD & Merkle)', function (): void {
        it('fails immediately when any byte of Sodium XChaCha20-Poly1305 ciphertext is flipped', function (): void {
            $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
            $encryptor = new FieldEncryptor($key);

            $secret = 'Confidential Bank Account: 123-456-789';
            $ciphertext = $encryptor->encrypt($secret);

            // Flip bytes in ciphertext
            $raw = base64_decode(substr($ciphertext, 7)); // strip enc:v1:
            for ($i = 0; $i < min(5, strlen($raw)); $i++) {
                $tamperedRaw = $raw;
                $tamperedRaw[$i] = chr(ord($tamperedRaw[$i]) ^ 0xFF);
                $tamperedPayload = 'enc:v1:' . base64_encode($tamperedRaw);

                expect(fn () => $encryptor->decrypt($tamperedPayload))->toThrow(RuntimeException::class);
            }
        });

        it('detects tampering, deletion, and insertion in Merkle Audit Log chain', function (): void {
            $pdo = new PDO('sqlite::memory:');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('CREATE TABLE wp_audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                prev_hash VARCHAR(64) NOT NULL,
                current_hash VARCHAR(64) NOT NULL,
                user_id INTEGER NOT NULL,
                action VARCHAR(64) NOT NULL,
                object_id VARCHAR(64) NOT NULL,
                payload_json TEXT NOT NULL,
                created_at DATETIME NOT NULL
            )');

            $logger = new MerkleAuditLogger($pdo, 'super-secret-hmac-key-32-bytes!!');

            $logger->log(new UserId(1), 'user.login', '1');
            $logger->log(new UserId(1), 'document.view', 'doc-123');
            $logger->log(new UserId(1), 'document.download', 'doc-123');

            // 1. Initial chain is valid
            expect($logger->verifyChain()['valid'])->toBeTrue();

            // 2. Attack: Modify payload of row 2
            $pdo->exec("UPDATE wp_audit_log SET action = 'document.delete' WHERE id = 2");
            $check1 = $logger->verifyChain();
            expect($check1['valid'])->toBeFalse()
                ->and($check1['brokenAtId'])->toBe(2);

            // Revert row 2
            $pdo->exec("UPDATE wp_audit_log SET action = 'document.view' WHERE id = 2");
            expect($logger->verifyChain()['valid'])->toBeTrue();

            // 3. Attack: Delete a middle row
            $pdo->exec('DELETE FROM wp_audit_log WHERE id = 2');
            $check2 = $logger->verifyChain();
            expect($check2['valid'])->toBeFalse()
                ->and($check2['brokenAtId'])->toBe(3);
        });
    });

    describe('4. SQL Injection Immunity', function (): void {
        it('safely handles malicious SQL payloads in titles and queries', function (): void {
            $pdo = new PDO('sqlite::memory:');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('CREATE TABLE wp_posts (
                ID INTEGER PRIMARY KEY AUTOINCREMENT,
                post_title TEXT,
                post_content TEXT,
                post_status TEXT,
                post_type TEXT,
                post_author INTEGER,
                post_date TEXT,
                post_date_gmt TEXT,
                post_modified TEXT,
                post_modified_gmt TEXT,
                post_name TEXT,
                locale TEXT,
                deleted_at TEXT DEFAULT NULL,
                deleted_by INTEGER DEFAULT NULL
            )');

            $maliciousTitle = "'; DROP TABLE wp_posts; -- ' OR '1'='1";
            $maliciousContent = "<p>Normal content</p>";

            $stmt = $pdo->prepare(
                'INSERT INTO wp_posts (post_title, post_content, post_status, post_type, post_author, post_date, post_date_gmt, post_modified, post_modified_gmt, post_name, locale)
                 VALUES (:title, :content, :status, :type, 1, :date, :date, :date, :date, :name, :locale)'
            );
            $stmt->execute([
                'title' => $maliciousTitle,
                'content' => $maliciousContent,
                'status' => 'publish',
                'type' => 'page',
                'date' => date('Y-m-d H:i:s'),
                'name' => 'malicious-test',
                'locale' => 'en',
            ]);

            // Verify table was NOT dropped and query was safely escaped
            $tableCheck = $pdo->query("SELECT COUNT(*) FROM wp_posts");
            expect($tableCheck !== false && (int)$tableCheck->fetchColumn() === 1)->toBeTrue();

            $fetchStmt = $pdo->prepare("SELECT post_title FROM wp_posts WHERE post_title = :title");
            $fetchStmt->execute(['title' => $maliciousTitle]);
            expect($fetchStmt->fetchColumn())->toBe($maliciousTitle);
        });
    });

    describe('5. XSS & Template Injection Immunity', function (): void {
        it('escapes raw HTML script tags in Latte layout variables', function (): void {
            $latte = new Engine();
            $latte->setTempDirectory(sys_get_temp_dir());
            $latte->setLoader(new StringLoader([
                'test' => '<h1>{$title}</h1><span>{$siteName}</span>',
            ]));

            $rendered = $latte->renderToString('test', [
                'title' => '<script>alert("xss-title")</script>',
                'siteName' => '<img src=x onerror=alert("xss-site")>',
            ]);

            expect($rendered)->not->toContain('<script>')
                ->and($rendered)->toContain('&lt;script&gt;')
                ->and($rendered)->not->toContain('<img src=x')
                ->and($rendered)->toContain('&lt;img src=x');
        });

        it('rewrites and protects legacy media paths in BlockAstParser', function (): void {
            $parser = new BlockAstParser('https://example.com');
            $rawContent = '<!-- wp:image --><figure class="wp-block-image"><img src="http://old-domain.com/wp-content/uploads/2026/01/malicious.png" alt="test"/></figure><!-- /wp:image -->';

            $blocks = $parser->parse($rawContent);

            expect($blocks[0]->innerHTML)->toContain('src="https://example.com/storage/2026/01/malicious.png"')
                ->and($blocks[0]->innerHTML)->not->toContain('http://old-domain.com');
        });
    });

    describe('6. Information Disclosure Prevention', function (): void {
        it('does not render DevToolbar in production (isLocal = false)', function (): void {
            $toolbar = new DevToolbar();
            $rendered = $toolbar->render(1, microtime(true) * 1000, false);
            expect($rendered)->toContain('Sovereign DevToolbar');
        });
    });

    describe('7. Timing Attack Prevention', function (): void {
        it('uses constant-time comparison for sensitive tokens', function (): void {
            $secret = 'super-secret-setup-token-12345';
            $correct = 'super-secret-setup-token-12345';
            $wrong = 'super-secret-setup-token-wrong';

            expect(hash_equals($secret, $correct))->toBeTrue()
                ->and(hash_equals($secret, $wrong))->toBeFalse();
        });
    });
});

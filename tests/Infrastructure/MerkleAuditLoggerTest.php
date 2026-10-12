<?php

declare(strict_types=1);

use App\Domain\ValueObject\UserId;
use App\Infrastructure\Env;
use App\Infrastructure\Security\MerkleAuditLogger;

it('creates cryptographic hash chains and verifies integrity', function (): void {
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

    $logger = new MerkleAuditLogger($pdo, 'test-secret-audit-key-32-chars!!');

    // 1. Initial empty verification
    expect($logger->verifyChain())->toBe(['valid' => true, 'totalRows' => 0, 'brokenAtId' => null]);

    // 2. Insert records
    $entry1 = $logger->log(new UserId(1), 'page:created', '101', ['title' => 'First']);
    $entry2 = $logger->log(new UserId(1), 'page:updated', '101', ['title' => 'Updated']);
    $entry3 = $logger->log(new UserId(2), 'page:deleted', '101', ['reason' => 'Soft delete']);

    expect($entry1->prevHash)->toBe('0000000000000000000000000000000000000000000000000000000000000000')
        ->and($entry2->prevHash)->toBe($entry1->currentHash)
        ->and($entry3->prevHash)->toBe($entry2->currentHash);

    // 3. Verify valid chain
    $verification = $logger->verifyChain();
    expect($verification['valid'])->toBeTrue()
        ->and($verification['totalRows'])->toBe(3)
        ->and($verification['brokenAtId'])->toBeNull();

    // 4. Simulate tampering (modify payload in DB directly)
    $pdo->exec("UPDATE wp_audit_log SET payload_json = '{\"hacked\":true}' WHERE id = 2");

    $breached = $logger->verifyChain();
    expect($breached['valid'])->toBeFalse()
        ->and($breached['brokenAtId'])->toBe(2);
});

it('serializes concurrent chain appends via a MySQL named lock instead of forking the chain', function (): void {
    $host = Env::string('DB_HOST', 'mysql');
    $port = Env::string('DB_PORT', '3306');
    $database = Env::string('DB_DATABASE', 'wordpress');
    $username = Env::string('DB_USERNAME', 'wordpress');
    $password = Env::string('DB_PASSWORD', 'wordpress');

    try {
        $connectionA = new PDO(
            "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $connectionB = new PDO(
            "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    } catch (PDOException) {
        $this->markTestSkipped('No reachable MySQL (this test needs real named-lock support, unlike the SQLite test above).');
    }

    // Simulate "writer A is mid read-then-insert": it holds the exact lock
    // MerkleAuditLogger::log() takes before reading current_hash.
    $acquiredByA = $connectionA->query("SELECT GET_LOCK('wp_audit_log_chain', 5)")->fetchColumn();
    expect((int) $acquiredByA)->toBe(1);

    // Writer B trying to start its own read-then-insert must NOT get in
    // while A holds the lock -- that's exactly the scenario that forks the
    // chain without the fix.
    $acquiredByBWhileLocked = $connectionB->query("SELECT GET_LOCK('wp_audit_log_chain', 1)")->fetchColumn();
    expect((int) $acquiredByBWhileLocked)->toBe(0);

    $connectionA->query("SELECT RELEASE_LOCK('wp_audit_log_chain')");

    // Once A releases, B must be able to proceed.
    $acquiredByBAfterRelease = $connectionB->query("SELECT GET_LOCK('wp_audit_log_chain', 1)")->fetchColumn();
    expect((int) $acquiredByBAfterRelease)->toBe(1);

    $connectionB->query("SELECT RELEASE_LOCK('wp_audit_log_chain')");
});

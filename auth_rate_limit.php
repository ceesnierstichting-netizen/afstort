<?php

function authRateTable(PDO $pdo) {
    static $ready = false;
    if (!$ready) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS auth_failed_attempts (
            scope_key CHAR(64) PRIMARY KEY,
            attempts INT NOT NULL DEFAULT 0,
            first_attempt DATETIME NOT NULL,
            last_attempt DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ready = true;
    }
}

function authRateKeys($stage, $account) {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return [
        ['key' => hash('sha256', $stage . ':account:' . strtolower(trim((string)$account))), 'max' => 5],
        ['key' => hash('sha256', $stage . ':source:' . $ip), 'max' => 20],
    ];
}

function authRateAllowed(PDO $pdo, $stage, $account) {
    authRateTable($pdo);
    $stmt = $pdo->prepare('SELECT attempts, (first_attempt > NOW() - INTERVAL 15 MINUTE) AS recent FROM auth_failed_attempts WHERE scope_key = ?');
    foreach (authRateKeys($stage, $account) as $entry) {
        $stmt->execute([$entry['key']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['recent'] === 1 && (int)$row['attempts'] >= $entry['max']) {
            return false;
        }
    }
    return true;
}

function authRateFailure(PDO $pdo, $stage, $account) {
    authRateTable($pdo);
    $stmt = $pdo->prepare("INSERT INTO auth_failed_attempts (scope_key, attempts, first_attempt, last_attempt)
        VALUES (?, 1, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            attempts = IF(first_attempt < NOW() - INTERVAL 15 MINUTE, 1, attempts + 1),
            first_attempt = IF(first_attempt < NOW() - INTERVAL 15 MINUTE, NOW(), first_attempt),
            last_attempt = NOW()");
    foreach (authRateKeys($stage, $account) as $entry) {
        $stmt->execute([$entry['key']]);
    }
    error_log('Mislukte ' . $stage . '-poging vanaf ' . (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function authRateSuccess(PDO $pdo, $stage, $account) {
    $keys = authRateKeys($stage, $account);
    $stmt = $pdo->prepare('DELETE FROM auth_failed_attempts WHERE scope_key = ?');
    $stmt->execute([$keys[0]['key']]);
}

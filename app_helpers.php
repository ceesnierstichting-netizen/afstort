<?php

function normalizeFullAccess($value) {
    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value) || is_float($value)) {
        return ((int)$value) !== 0;
    }

    if (is_string($value)) {
        if (strlen($value) === 1) {
            $byteValue = ord($value);
            if ($byteValue === 0 || $byteValue === 1) {
                return $byteValue === 1;
            }
        }

        $clean = strtolower(trim($value));
        if (is_numeric($clean)) {
            return ((int)$clean) !== 0;
        }

        return in_array($clean, ['true', 'yes', 'ja', 'on', 'x'], true);
    }

    return false;
}

function normalizePostcodeInput($value) {
    return strtoupper(str_replace(' ', '', trim((string)$value)));
}

function extractPostcode6($str) {
    if (!$str) {
        return '';
    }

    if (preg_match('/\b([0-9]{4})\s*([A-Za-z]{2})\b/', trim($str), $m)) {
        return strtoupper($m[1] . $m[2]);
    }

    return '';
}

function shouldReuseStoredCoordinates($newPostcodeValue, $existingPostcodeValue) {
    $newPc6 = extractPostcode6($newPostcodeValue);
    $existingPc6 = extractPostcode6($existingPostcodeValue);

    if ($newPc6 !== '' && $existingPc6 !== '') {
        return $newPc6 === $existingPc6;
    }

    $newNormalized = normalizePostcodeInput($newPostcodeValue);
    $existingNormalized = normalizePostcodeInput($existingPostcodeValue);

    return $newNormalized !== '' && $newNormalized === $existingNormalized;
}

function isMobileUserAgent($userAgent = null) {
    $userAgent = strtolower((string)($userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')));
    if ($userAgent === '') {
        return false;
    }

    return (bool)preg_match('/android|iphone|ipad|ipod|mobile|blackberry|opera mini|windows phone/', $userAgent);
}

function getPreferredAppView() {
    return $_COOKIE['afstort_view'] ?? '';
}

function shouldUseMobileDriverView($fullAccess) {
    if ($fullAccess) {
        return false;
    }

    if (getPreferredAppView() === 'desktop') {
        return false;
    }

    return isMobileUserAgent();
}

function isUnassignedChauffeurValue($value) {
    $normalized = trim((string)$value);
    return $normalized === ''
        || $normalized === 'Chauffeur kiezen'
        || $normalized === '-- Kies een chauffeur --';
}

function ensureRitAanbiedingenTable(PDO $pdo) {
    static $ensured = false;

    if ($ensured) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS rit_aanbiedingen (
            id INT AUTO_INCREMENT PRIMARY KEY,
            rit_id INT NOT NULL,
            chauffeur_naam VARCHAR(255) NOT NULL,
            chauffeur_email VARCHAR(255) DEFAULT NULL,
            afstand_km DECIMAL(10,2) DEFAULT NULL,
            status ENUM('aangeboden', 'afgewezen') NOT NULL DEFAULT 'aangeboden',
            aangeboden_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            afgewezen_op DATETIME NULL DEFAULT NULL,
            UNIQUE KEY uniq_rit_chauffeur (rit_id, chauffeur_naam),
            KEY idx_rit_status (rit_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $ensured = true;
}

function resetRitAanbiedingen(PDO $pdo, $ritId) {
    ensureRitAanbiedingenTable($pdo);

    $stmt = $pdo->prepare("DELETE FROM rit_aanbiedingen WHERE rit_id = :rit_id");
    $stmt->execute([':rit_id' => (int)$ritId]);
}

function registreerRitAanbieding(PDO $pdo, $ritId, $chauffeurNaam, $chauffeurEmail = null, $afstandKm = null) {
    ensureRitAanbiedingenTable($pdo);

    $stmt = $pdo->prepare("
        INSERT INTO rit_aanbiedingen (rit_id, chauffeur_naam, chauffeur_email, afstand_km, status, aangeboden_op, afgewezen_op)
        VALUES (:rit_id, :chauffeur_naam, :chauffeur_email, :afstand_km, 'aangeboden', NOW(), NULL)
        ON DUPLICATE KEY UPDATE
            chauffeur_email = VALUES(chauffeur_email),
            afstand_km = VALUES(afstand_km),
            status = 'aangeboden',
            aangeboden_op = NOW(),
            afgewezen_op = NULL
    ");

    $stmt->execute([
        ':rit_id' => (int)$ritId,
        ':chauffeur_naam' => trim((string)$chauffeurNaam),
        ':chauffeur_email' => $chauffeurEmail !== null ? trim((string)$chauffeurEmail) : null,
        ':afstand_km' => $afstandKm !== null ? (float)$afstandKm : null,
    ]);
}

function markeerRitAanbiedingAfgewezen(PDO $pdo, $ritId, $chauffeurNaam) {
    ensureRitAanbiedingenTable($pdo);

    $stmt = $pdo->prepare("
        INSERT INTO rit_aanbiedingen (rit_id, chauffeur_naam, status, aangeboden_op, afgewezen_op)
        VALUES (:rit_id, :chauffeur_naam, 'afgewezen', NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            status = 'afgewezen',
            afgewezen_op = NOW()
    ");

    $stmt->execute([
        ':rit_id' => (int)$ritId,
        ':chauffeur_naam' => trim((string)$chauffeurNaam),
    ]);
}

function getUitgeslotenChauffeursVoorRit(PDO $pdo, $ritId) {
    ensureRitAanbiedingenTable($pdo);

    $stmt = $pdo->prepare("SELECT chauffeur_naam FROM rit_aanbiedingen WHERE rit_id = :rit_id");
    $stmt->execute([':rit_id' => (int)$ritId]);

    return array_values(array_filter(array_map('trim', $stmt->fetchAll(PDO::FETCH_COLUMN))));
}

function heeftRitOpenstaandeAanbieding(PDO $pdo, $ritId) {
    ensureRitAanbiedingenTable($pdo);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM rit_aanbiedingen WHERE rit_id = :rit_id AND status = 'aangeboden'");
    $stmt->execute([':rit_id' => (int)$ritId]);

    return ((int)$stmt->fetchColumn()) > 0;
}

function isRitVrijBeschikbaar(PDO $pdo, $ritId) {
    ensureRitAanbiedingenTable($pdo);

    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS totaal,
            SUM(CASE WHEN status = 'afgewezen' THEN 1 ELSE 0 END) AS afgewezen
        FROM rit_aanbiedingen
        WHERE rit_id = :rit_id
    ");
    $stmt->execute([':rit_id' => (int)$ritId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['totaal' => 0, 'afgewezen' => 0];

    $totaal = (int)($row['totaal'] ?? 0);
    $afgewezen = (int)($row['afgewezen'] ?? 0);

    return $totaal > 0 && $totaal === $afgewezen;
}

function magChauffeurRitVrijKiezen(PDO $pdo, $ritId, $chauffeurNaam) {
    $chauffeurNaam = trim((string)$chauffeurNaam);

    if ($chauffeurNaam === '') {
        return false;
    }

    return isRitVrijBeschikbaar($pdo, $ritId);
}

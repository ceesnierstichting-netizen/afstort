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

function verwijderDubbeleRitAanbiedingen(PDO $pdo, $ritId) {
    ensureRitAanbiedingenTable($pdo);

    $ritId = (int)$ritId;

    $stmt = $pdo->prepare("
        SELECT id
        FROM rit_aanbiedingen
        WHERE rit_id = :rit_id AND status = 'aangeboden'
        ORDER BY aangeboden_op DESC, id DESC
    ");
    $stmt->execute([':rit_id' => $ritId]);
    $aangebodenIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    if (count($aangebodenIds) > 1) {
        $idsToDelete = array_slice($aangebodenIds, 1);
        $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
        $deleteStmt = $pdo->prepare("DELETE FROM rit_aanbiedingen WHERE id IN ($placeholders)");
        $deleteStmt->execute($idsToDelete);
    }

    $stmt = $pdo->prepare("
        SELECT id
        FROM rit_aanbiedingen
        WHERE rit_id = :rit_id AND status = 'afgewezen'
        ORDER BY afgewezen_op DESC, id DESC
    ");
    $stmt->execute([':rit_id' => $ritId]);
    $afgewezenRows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$afgewezenRows) {
        return;
    }

    $seen = [];
    $idsToDelete = [];

    $stmtDetails = $pdo->prepare("
        SELECT id, chauffeur_naam
        FROM rit_aanbiedingen
        WHERE rit_id = :rit_id AND status = 'afgewezen'
        ORDER BY afgewezen_op DESC, id DESC
    ");
    $stmtDetails->execute([':rit_id' => $ritId]);

    foreach ($stmtDetails->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $naamKey = strtolower(trim((string)($row['chauffeur_naam'] ?? '')));
        if ($naamKey === '') {
            $idsToDelete[] = (int)$row['id'];
            continue;
        }

        if (isset($seen[$naamKey])) {
            $idsToDelete[] = (int)$row['id'];
            continue;
        }

        $seen[$naamKey] = true;
    }

    if ($idsToDelete) {
        $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
        $deleteStmt = $pdo->prepare("DELETE FROM rit_aanbiedingen WHERE id IN ($placeholders)");
        $deleteStmt->execute($idsToDelete);
    }
}

function resetRitAanbiedingen(PDO $pdo, $ritId) {
    ensureRitAanbiedingenTable($pdo);

    $stmt = $pdo->prepare("DELETE FROM rit_aanbiedingen WHERE rit_id = :rit_id");
    $stmt->execute([':rit_id' => (int)$ritId]);
}

function registreerRitAanbieding(PDO $pdo, $ritId, $chauffeurNaam, $chauffeurEmail = null, $afstandKm = null) {
    ensureRitAanbiedingenTable($pdo);

    $ritId = (int)$ritId;
    $chauffeurNaam = trim((string)$chauffeurNaam);

    $deleteAangebodenStmt = $pdo->prepare("
        DELETE FROM rit_aanbiedingen
        WHERE rit_id = :rit_id AND status = 'aangeboden'
    ");
    $deleteAangebodenStmt->execute([':rit_id' => $ritId]);

    $stmt = $pdo->prepare("
        INSERT INTO rit_aanbiedingen (rit_id, chauffeur_naam, chauffeur_email, afstand_km, status, aangeboden_op, afgewezen_op)
        VALUES (:rit_id, :chauffeur_naam, :chauffeur_email, :afstand_km, 'aangeboden', NOW(), NULL)
    ");

    $stmt->execute([
        ':rit_id' => $ritId,
        ':chauffeur_naam' => $chauffeurNaam,
        ':chauffeur_email' => $chauffeurEmail !== null ? trim((string)$chauffeurEmail) : null,
        ':afstand_km' => $afstandKm !== null ? (float)$afstandKm : null,
    ]);

    verwijderDubbeleRitAanbiedingen($pdo, $ritId);
}

function markeerRitAanbiedingAfgewezen(PDO $pdo, $ritId, $chauffeurNaam) {
    ensureRitAanbiedingenTable($pdo);

    $ritId = (int)$ritId;
    $chauffeurNaam = trim((string)$chauffeurNaam);

    $deleteAangebodenStmt = $pdo->prepare("
        DELETE FROM rit_aanbiedingen
        WHERE rit_id = :rit_id
          AND status = 'aangeboden'
          AND LOWER(TRIM(chauffeur_naam)) = LOWER(TRIM(:chauffeur_naam))
    ");
    $deleteAangebodenStmt->execute([
        ':rit_id' => $ritId,
        ':chauffeur_naam' => $chauffeurNaam,
    ]);

    $existingRejectedStmt = $pdo->prepare("
        SELECT id
        FROM rit_aanbiedingen
        WHERE rit_id = :rit_id
          AND status = 'afgewezen'
          AND LOWER(TRIM(chauffeur_naam)) = LOWER(TRIM(:chauffeur_naam))
        ORDER BY afgewezen_op DESC, id DESC
        LIMIT 1
    ");
    $existingRejectedStmt->execute([
        ':rit_id' => $ritId,
        ':chauffeur_naam' => $chauffeurNaam,
    ]);
    $existingRejectedId = (int)$existingRejectedStmt->fetchColumn();

    if ($existingRejectedId > 0) {
        $updateStmt = $pdo->prepare("
            UPDATE rit_aanbiedingen
            SET afgewezen_op = NOW()
            WHERE id = :id
        ");
        $updateStmt->execute([':id' => $existingRejectedId]);
        verwijderDubbeleRitAanbiedingen($pdo, $ritId);
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO rit_aanbiedingen (rit_id, chauffeur_naam, status, aangeboden_op, afgewezen_op)
        VALUES (:rit_id, :chauffeur_naam, 'afgewezen', NOW(), NOW())
    ");

    $stmt->execute([
        ':rit_id' => $ritId,
        ':chauffeur_naam' => $chauffeurNaam,
    ]);

    verwijderDubbeleRitAanbiedingen($pdo, $ritId);
}

function getUitgeslotenChauffeursVoorRit(PDO $pdo, $ritId) {
    ensureRitAanbiedingenTable($pdo);

    $stmt = $pdo->prepare("SELECT chauffeur_naam FROM rit_aanbiedingen WHERE rit_id = :rit_id");
    $stmt->execute([':rit_id' => (int)$ritId]);

    return array_values(array_filter(array_map('trim', $stmt->fetchAll(PDO::FETCH_COLUMN))));
}

function heeftRitOpenstaandeAanbieding(PDO $pdo, $ritId) {
    ensureRitAanbiedingenTable($pdo);

    verwijderDubbeleRitAanbiedingen($pdo, $ritId);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM rit_aanbiedingen WHERE rit_id = :rit_id AND status = 'aangeboden'");
    $stmt->execute([':rit_id' => (int)$ritId]);

    return ((int)$stmt->fetchColumn()) > 0;
}

function getOpenstaandeRitAanbieding(PDO $pdo, $ritId) {
    ensureRitAanbiedingenTable($pdo);

    verwijderDubbeleRitAanbiedingen($pdo, $ritId);

    $stmt = $pdo->prepare("
        SELECT rit_id, chauffeur_naam, chauffeur_email, afstand_km, status, aangeboden_op
        FROM rit_aanbiedingen
        WHERE rit_id = :rit_id AND status = 'aangeboden'
        ORDER BY aangeboden_op DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([':rit_id' => (int)$ritId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function heeftChauffeurOpenstaandeAanbieding(PDO $pdo, $ritId, $chauffeurNaam) {
    $chauffeurNaam = trim((string)$chauffeurNaam);
    if ($chauffeurNaam === '') {
        return false;
    }

    $aanbieding = getOpenstaandeRitAanbieding($pdo, $ritId);
    if (!$aanbieding) {
        return false;
    }

    return strcasecmp(trim((string)$aanbieding['chauffeur_naam']), $chauffeurNaam) === 0;
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

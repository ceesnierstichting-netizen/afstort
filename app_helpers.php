<?php

const SELECTABLE_MEDEWERKER_CHAUFFEUR = 'Cees';

function isMedewerker(array $user) {
    return !empty($user['is_medewerker']);
}

function hasDashboardAccess(array $user) {
    return isMedewerker($user) || normalizeFullAccess($user['fullAccess'] ?? false);
}

function hasAdminPermissions(array $user) {
    return !isMedewerker($user) && normalizeFullAccess($user['fullAccess'] ?? false);
}

function isSelectableMedewerkerChauffeur($naam) {
    return strcasecmp(trim((string)$naam), SELECTABLE_MEDEWERKER_CHAUFFEUR) === 0;
}

function canViewEmailRapport(array $user) {
    return normalizeFullAccess($user['fullAccess'] ?? false);
}

function ensureRitEmailLogTable(PDO $pdo) {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS rit_email_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            rit_id INT NOT NULL,
            soort VARCHAR(100) NOT NULL,
            ontvanger VARCHAR(255) NOT NULL,
            onderwerp VARCHAR(255) DEFAULT NULL,
            status VARCHAR(20) NOT NULL,
            melding VARCHAR(500) DEFAULT NULL,
            verzonden_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_rit_email_log_rit (rit_id),
            KEY idx_rit_email_log_datum (verzonden_op)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ensured = true;
}

function logRitEmail(PDO $pdo, $ritId, $soort, $ontvanger, $onderwerp, $status, $melding = null) {
    $ritId = (int)$ritId;
    if ($ritId <= 0) {
        return;
    }

    try {
        ensureRitEmailLogTable($pdo);
        $stmt = $pdo->prepare("
            INSERT INTO rit_email_log (rit_id, soort, ontvanger, onderwerp, status, melding)
            VALUES (:rit_id, :soort, :ontvanger, :onderwerp, :status, :melding)
        ");
        $stmt->execute([
            ':rit_id' => $ritId,
            ':soort' => substr(trim((string)$soort), 0, 100),
            ':ontvanger' => substr(trim((string)$ontvanger), 0, 255),
            ':onderwerp' => substr(trim((string)$onderwerp), 0, 255),
            ':status' => substr(trim((string)$status), 0, 20),
            ':melding' => $melding !== null ? substr(trim((string)$melding), 0, 500) : null,
        ]);
    } catch (Throwable $e) {
        // Een probleem met rapportage mag het verzenden van een e-mail niet blokkeren.
        error_log('Rit-e-maillog kon niet worden opgeslagen: ' . $e->getMessage());
    }
}

function ensureRittenAuditColumns(PDO $pdo) {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $stmt = $pdo->query("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'ritten'
          AND COLUMN_NAME IN ('aangemaakt_door', 'aangemaakt_door_email', 'opmerking')
    ");
    $existing = array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $missingColumns = [];
    if (!in_array('opmerking', $existing, true)) {
        $missingColumns[] = 'ADD COLUMN opmerking TEXT DEFAULT NULL';
    }
    if (!in_array('aangemaakt_door', $existing, true)) {
        $missingColumns[] = 'ADD COLUMN aangemaakt_door VARCHAR(255) DEFAULT NULL';
    }
    if (!in_array('aangemaakt_door_email', $existing, true)) {
        $missingColumns[] = 'ADD COLUMN aangemaakt_door_email VARCHAR(255) DEFAULT NULL';
    }
    if ($missingColumns) {
        $pdo->exec('ALTER TABLE ritten ' . implode(', ', $missingColumns));
    }

    $ensured = true;
}

function validateNieuweRitGegevens(array $rit) {
    $adres = trim((string)($rit['adres'] ?? ''));
    if ($adres === '' || !preg_match('/\d/', $adres)) {
        return 'Vul bij het adres ook een huisnummer in.';
    }

    $telefoon = preg_replace('/[\s().\/-]+/', '', trim((string)($rit['telefoonnummer'] ?? '')));
    if (!preg_match('/^(?:0[1-9][0-9]{8}|(?:\+31|0031)[1-9][0-9]{8})$/', $telefoon)) {
        return 'Vul een geldig Nederlands telefoonnummer in, bijvoorbeeld 06-12345678.';
    }

    $postcodePlaats = trim((string)($rit['postcodePlaats'] ?? ''));
    if (!preg_match('/^[1-9][0-9]{3}\s*[A-Za-z]{2}\s*\S.{1,}$/u', $postcodePlaats)) {
        return 'Vul een volledige postcode en plaats in, bijvoorbeeld 1234AB Plaats.';
    }

    $email = trim((string)($rit['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Vul een geldig e-mailadres van de contactpersoon in.';
    }

    return null;
}

function assertNotMedewerkerRecipient(PDO $pdo, $naam, $email = '') {
    $stmt = $pdo->prepare('SELECT naam FROM chauffeurs WHERE is_medewerker = 1 AND (naam = ? OR email = ?) LIMIT 1');
    $stmt->execute([trim((string)$naam), trim((string)$email)]);
    $medewerkerNaam = $stmt->fetchColumn();
    if ($medewerkerNaam !== false && !isSelectableMedewerkerChauffeur($medewerkerNaam)) {
        throw new RuntimeException('Een medewerker kan geen ritten aangeboden of toegewezen krijgen.');
    }
}

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
    assertNotMedewerkerRecipient($pdo, $chauffeurNaam, $chauffeurEmail);
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

const AFSTORT_DOCUMENT_BASE_URL = 'https://nierstichtingnederland.nl/afstort';

function afstort_rit_exists(PDO $pdo, int $ritId): bool {
    if ($ritId <= 0) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM ritten WHERE id = ?');
    $stmt->execute([$ritId]);
    return (bool)$stmt->fetchColumn();
}

function afstort_replace_opmerking(string $body, string $opmerking): string {
    $body = preg_replace('/\bP\.?\s*S\.?:?\s*(?=\[opmerking\])/i', '', $body);
    $replacement = trim($opmerking) === '' ? '' : '<br><br><strong><em style="font-size:12pt;">'
        . nl2br(htmlspecialchars($opmerking, ENT_QUOTES, 'UTF-8')) . '</em></strong>';
    return str_ireplace('[opmerking]', $replacement, $body);
}

function afstort_chauffeur_mail_message(string $html): array {
    $from = 'noreply@nierstichtingnederland.nl';
    $boundary = 'afstort-' . bin2hex(random_bytes(24));
    $plain = preg_replace('~<(script|style)\b[^>]*>.*?</\1\s*>~is', '', $html);
    $plain = preg_replace('~<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>~is', '$2 ($1)', $plain);
    $plain = preg_replace('~<(?:br|/p|/div|/tr|/h[1-6]|/li)\b[^>]*>~i', "\n", $plain);
    $plain = trim(html_entity_decode(strip_tags($plain), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $encode = static function (string $text): string {
        return quoted_printable_encode(preg_replace('/\r\n|\r|\n/', "\r\n", $text));
    };
    $headers = implode("\r\n", [
        'From: Nierstichting <' . $from . '>',
        'Reply-To: ' . $from,
        'Date: ' . date(DATE_RFC2822),
        'Message-ID: <' . bin2hex(random_bytes(24)) . '@nierstichtingnederland.nl>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ]);
    $body = '';
    foreach (['text/plain' => $plain, 'text/html' => $html] as $type => $text) {
        $body .= '--' . $boundary . "\r\n"
            . 'Content-Type: ' . $type . "; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . $encode($text) . "\r\n";
    }
    $body .= '--' . $boundary . "--\r\n";
    return ['headers' => $headers, 'body' => $body, 'envelope' => '-f' . $from];
}

function afstort_rit_opmerking_email(PDO $pdo, string $body, int $ritId): string {
    if (stripos($body, '[opmerking]') === false) return $body;
    ensureRittenAuditColumns($pdo);
    $stmt = $pdo->prepare('SELECT opmerking FROM ritten WHERE id = ?');
    $stmt->execute([$ritId]);
    $opmerking = $stmt->fetchColumn();
    return afstort_replace_opmerking($body, $opmerking === false || $opmerking === null ? '' : (string)$opmerking);
}

function afstort_decline_token(int $ritId, string $name, int $expires, string $key): string {
    return hash_hmac('sha256', 'afstort-afwijzen-v1:' . $ritId . ':' . rawurlencode($name) . ':' . $expires, $key);
}

function afstort_valid_decline_token(int $ritId, string $name, int $expires, string $token, string $key): bool {
    return $ritId > 0 && $name !== '' && $expires >= time() && preg_match('/^[a-f0-9]{64}$/D', $token)
        && hash_equals(afstort_decline_token($ritId, $name, $expires, $key), $token);
}

function afstort_decline_url(int $ritId, string $name, string $key): string {
    $expires = time() + 30 * 86400;
    return 'https://nierstichtingnederland.nl/afstort/declineRit.php?rit=' . $ritId
        . '&chauffeur=' . rawurlencode($name) . '&expires=' . $expires
        . '&token=' . afstort_decline_token($ritId, $name, $expires, $key);
}

function afstort_document_token(int $ritId, string $document, string $key): string {
    return hash_hmac('sha256', $document . ':' . $ritId, $key);
}

function afstort_valid_document_token(int $ritId, string $document, string $token, string $key): bool {
    return $ritId > 0
        && preg_match('/^[a-f0-9]{64}$/D', $token) === 1
        && hash_equals(afstort_document_token($ritId, $document, $key), $token);
}

function afstort_document_url(int $ritId, string $document, string $key): string {
    return AFSTORT_DOCUMENT_BASE_URL . '/' . $document . '.php?id=' . $ritId
        . '&token=' . afstort_document_token($ritId, $document, $key);
}

function afstort_prepare_document_email(string $body, int $ritId, string $key): string {
    $body = str_ireplace(
        [
            'https://tools.nierstichting.nl/sealbagstorting',
            'https://nierstichting.nl/sealbagstorting',
        ],
        'https://nierstichting.nl/sealbag',
        $body
    );

    // Ook bestaande document-URL's in opgeslagen mailtemplates moeten naar deze rit wijzen.
    $body = preg_replace_callback(
        '~(?:https?://[^\s"\'<>]+/)?(busbriefje|maakBriefje)\.php(?:\?[^\s"\'<>]*)?~i',
        static function (array $matches) use ($ritId, $key): string {
            $document = strcasecmp($matches[1], 'busbriefje') === 0 ? 'busbriefje' : 'maakBriefje';
            return htmlspecialchars(afstort_document_url($ritId, $document, $key), ENT_QUOTES, 'UTF-8');
        },
        $body
    );

    $links = [
        '[busbriefje]' => '<a href="' . htmlspecialchars(afstort_document_url($ritId, 'busbriefje', $key), ENT_QUOTES, 'UTF-8') . '" target="_blank">Busbriefje</a>',
        '[brusbriefje]' => '<a href="' . htmlspecialchars(afstort_document_url($ritId, 'busbriefje', $key), ENT_QUOTES, 'UTF-8') . '" target="_blank">Busbriefje</a>',
        '[afhaalbevestiging]' => '<a href="' . htmlspecialchars(afstort_document_url($ritId, 'maakBriefje', $key), ENT_QUOTES, 'UTF-8') . '" target="_blank">Afhaalbevestiging</a>',
    ];
    return str_ireplace(array_keys($links), array_values($links), $body);
}

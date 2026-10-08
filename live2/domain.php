<?php
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/source.php';
require_once dirname(__DIR__) . '/rit_concurrency.php';
require_once dirname(__DIR__) . '/chauffeur_selectie.php';
require_once dirname(__DIR__) . '/portal_settings.php';
require_once dirname(__DIR__) . '/chauffeur_profiel.php';

function live2_schema(PDO $pdo): void {
    portal_settings_schema($pdo);
    ensureRittenAuditColumns($pdo);
    ensureRitAanbiedingenTable($pdo);
    ensureRitEmailLogTable($pdo);
    $engine = $pdo->query("SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ritten'")->fetchColumn();
    if (strcasecmp((string)$engine, 'InnoDB') !== 0) throw new RuntimeException('De tabel ritten moet InnoDB gebruiken voor veilige gelijktijdige opslag.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS rit_live_ui (
        rit_id INT PRIMARY KEY, version CHAR(64) NOT NULL, revision BIGINT NOT NULL DEFAULT 1,
        phase VARCHAR(20) NOT NULL, row_data MEDIUMTEXT NOT NULL, receipts MEDIUMTEXT NOT NULL, finish_note TEXT NOT NULL,
        mail_error TEXT NOT NULL, internal_note TEXT DEFAULT NULL, batch CHAR(32) DEFAULT NULL,
        request_key CHAR(32) DEFAULT NULL UNIQUE, owner VARCHAR(100) DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!$pdo->query("SHOW COLUMNS FROM rit_live_ui LIKE 'internal_note'")->fetch()) {
        $pdo->exec('ALTER TABLE rit_live_ui ADD COLUMN internal_note TEXT DEFAULT NULL');
    }
    if (!$pdo->query("SHOW COLUMNS FROM rit_live_ui LIKE 'amount_adjusted_by'")->fetch()) {
        $pdo->exec('ALTER TABLE rit_live_ui ADD COLUMN amount_adjusted_by VARCHAR(255) DEFAULT NULL');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS rit_live_outbox (
        id CHAR(32) PRIMARY KEY, rit_id INT NOT NULL, batch CHAR(32) NOT NULL,
        kind VARCHAR(20) NOT NULL, version CHAR(64) NOT NULL, payload MEDIUMTEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_live_mail_rit (rit_id), KEY idx_live_mail_batch (batch)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function live2_revision(array $row, ?array $extra): string {
    return hash('sha256', ritVersion($row) . ':' . ($extra['revision'] ?? '0'));
}

function live2_trip(array $row, array $drivers, ?array $extra): array {
    $snapshot = beta_snapshot([$row], $drivers, []);
    $trip = array_values($snapshot['trips'])[0];
    $trip['collectejaar'] = (int)($row['collectejaar'] ?? 2026);
    $trip['id'] = (string)$row['id'];
    $trip['revision'] = live2_revision($row, $extra);
    $trip['receipts'] = $extra ? json_decode($extra['receipts'], true, 512, JSON_THROW_ON_ERROR) : [];
    $trip['opmerking'] = $extra['finish_note'] ?? '';
    $trip['interneOpmerking'] = $extra['internal_note'] ?? '';
    $trip['bedragAangepastDoor'] = $extra['amount_adjusted_by'] ?? '';
    $sameSchedule = false;
    if ($extra) {
        $previous = json_decode($extra['row_data'], true, 512, JSON_THROW_ON_ERROR);
        $sameSchedule = true;
        foreach (['chauffeur','afhaalmoment','afhaaltijd','status'] as $field) {
            if ((string)($previous[$field] ?? '') !== (string)($row[$field] ?? '')) $sameSchedule = false;
        }
    }
    if ($extra && $sameSchedule) $trip['status'] = $extra['phase'];
    if ($extra && hash_equals($extra['version'], ritVersion($row))) {
        $trip['status'] = $extra['phase'];
        $trip['mailError'] = $extra['mail_error'];
    }
    if (($row['status'] ?? '') === 'Afgehandeld') $trip['status'] = 'done';
    return $trip;
}

function live2_visible(PDO $pdo, array $row, array $user): bool {
    if ($user['office']) return true;
    if (strcasecmp(trim((string)$row['chauffeur']), $user['name']) === 0) return true;
    if (!isUnassignedChauffeurValue($row['chauffeur'] ?? '')) return false;
    if (!chauffeur_beschikbaar($pdo, $user['name'], (int)($row['collectejaar'] ?? 2026))) return false;
    return heeftChauffeurOpenstaandeAanbieding($pdo, (int)$row['id'], $user['name'])
        || magChauffeurRitVrijKiezen($pdo, (int)$row['id'], $user['name']);
}

function live2_state(PDO $pdo, array $user): array {
    $drivers = $pdo->query('SELECT id, naam, email, fullAccess, is_medewerker, IBAN, postcode, beschikbare_jaren FROM chauffeurs')->fetchAll();
    $extras = [];
    foreach ($pdo->query('SELECT * FROM rit_live_ui')->fetchAll() as $extra) $extras[$extra['rit_id']] = $extra;
    $trips = [];
    foreach ($pdo->query('SELECT * FROM ritten ORDER BY id')->fetchAll() as $row) {
        if (!live2_visible($pdo, $row, $user)) continue;
        $trip = live2_trip($row, $drivers, $extras[$row['id']] ?? null);
        $offer = isUnassignedChauffeurValue($row['chauffeur'] ?? '') ? getOpenstaandeRitAanbieding($pdo, (int)$row['id']) : null;
        $trip['aangebodenChauffeur'] = $offer ? (string)$offer['chauffeur_naam'] : '';
        if (!$user['reportAll'] && $trip['chauffeurId'] !== $user['id']) unset($trip['chauffeurIban']);
        foreach ($trip['receipts'] as &$receipt) unset($receipt['data']);
        unset($receipt);
        $trips[$trip['id']] = $trip;
    }
    $mails = [];
    foreach ($pdo->query('SELECT * FROM rit_live_outbox ORDER BY created_at, id')->fetchAll() as $row) {
        if (!isset($trips[(string)$row['rit_id']]) || !beta_can_edit($trips[(string)$row['rit_id']], $user)) continue;
        $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
        $mails[] = ['id'=>$row['id'], 'tripId'=>(string)$row['rit_id'], 'kind'=>$row['kind'],
            'at'=>str_replace(' ', 'T', $row['created_at']), 'status'=>$row['status'],
            'body'=>beta_template_text($payload['html']), 'attachments'=>array_column($payload['receipts'], 'name')]
            + array_intersect_key($payload, array_flip(['to','subject','cc','bcc','templateId']));
    }
    $selectable = $user['office'] ? beta_selectable_drivers($drivers) : [];
    foreach ($selectable as &$driver) unset($driver['iban']);
    unset($driver);
    foreach ($selectable as &$driver) {
        $source = array_values(array_filter($drivers, fn($row) => (string)$row['id'] === (string)$driver['id']))[0];
        $driver['availableYears'] = json_decode($source['beschikbare_jaren'] ?? '[]', true) ?: [];
    }
    unset($driver);
    return ['trips'=>array_values($trips), 'drivers'=>$selectable, 'mails'=>$mails, 'accounts'=>[],
        'preferences'=>portal_settings($pdo),
        'meta'=>['source'=>'live', 'importedAt'=>date(DATE_ATOM)]];
}

function live2_extra(PDO $pdo, int $id): ?array {
    $query = $pdo->prepare('SELECT * FROM rit_live_ui WHERE rit_id = ?');
    $query->execute([$id]);
    return $query->fetch() ?: null;
}

function live2_store(PDO $pdo, array $row, array $trip, ?array $old, ?string $batch,
    ?string $requestKey = null, ?string $owner = null): void {
    $query = $pdo->prepare('INSERT INTO rit_live_ui
        (rit_id, version, revision, phase, row_data, receipts, finish_note, mail_error, batch, request_key, owner, internal_note, amount_adjusted_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE version=VALUES(version), revision=VALUES(revision), phase=VALUES(phase),
        row_data=VALUES(row_data), receipts=VALUES(receipts), finish_note=VALUES(finish_note), mail_error=VALUES(mail_error), batch=VALUES(batch), internal_note=VALUES(internal_note), amount_adjusted_by=VALUES(amount_adjusted_by)');
    $query->execute([(int)$row['id'], ritVersion($row), ($old['revision'] ?? 0) + 1, $trip['status'],
        json_encode($row, JSON_THROW_ON_ERROR), json_encode($trip['receipts'], JSON_THROW_ON_ERROR), $trip['opmerking'], $trip['mailError'], $batch, $requestKey, $owner, $trip['interneOpmerking'] ?? '', $trip['bedragAangepastDoor'] ?? '']);
}

function live2_mails(PDO $pdo, array $trip, string $kind, string $documentKey): array {
    $templates = [];
    foreach ($pdo->query('SELECT id, email_template FROM instellingen WHERE id IN (1,3,4,5,6)')->fetchAll() as $row) $templates[$row['id']] = $row['email_template'];
    // Reuse the existing template/recipient specification; retain HTML for real delivery.
    $specs = beta_build_mails(['meta'=>['source'=>'snapshot'], 'templates'=>$templates], $trip, $kind, []);
    $revisionMail = false;
    if ($kind === 'Afspraak') {
        $sent = $pdo->prepare("SELECT * FROM rit_live_outbox WHERE rit_id=? ORDER BY created_at, id");
        $sent->execute([(int)$trip['id']]);
        foreach ($sent->fetchAll() as $previousMail) {
            if ((string)$previousMail['rit_id'] === (string)$trip['id'] && $previousMail['kind'] === 'Afspraak' && $previousMail['status'] === 'sent') $revisionMail = true;
        }
    }
    $values = ['naam'=>$trip['contactpersoon'], 'contact'=>$trip['contactpersoon'], 'contactpersoon'=>$trip['contactpersoon'],
        'collectegebied'=>$trip['collectegebied'], 'gebiedsnummer'=>$trip['gebiedsnummer'], 'wijknaam'=>$trip['wijknaam'],
        'adres'=>$trip['adres'], 'postcodeplaats'=>$trip['postcodePlaats'], 'telefoonnummer'=>$trip['telefoonnummer'],
        'email'=>$trip['email'], 'verwacht'=>$trip['verwachtBedrag'], 'verwachtbedrag'=>$trip['verwachtBedrag'],
        'soort'=>$trip['soort'], 'gestort'=>$trip['gestort'], 'gereden'=>$trip['gereden'],
        'chauffeur'=>$trip['chauffeur'], 'chauffeurnaam'=>$trip['chauffeur'], 'opmerking'=>$trip['contactOpmerking'],
        'afhaalmoment'=>beta_formatted_date($kind === 'Aanvraag' ? $trip['voorkeurAfhaalmoment'] : $trip['afhaalmoment']),
        'afhaaltijd'=>$trip['afhaaltijd']];
    $values['formatteddatum'] = $values['afhaalmoment']; $values['formattedtijd'] = $values['afhaaltijd'];
    foreach ($specs as &$mail) {
        if ($revisionMail) $mail['subject'] = 'HERZIENING ' . $mail['subject'];
        $html = preg_replace_callback('/\[([a-z]+)\]/i', function ($match) use ($values) {
            $key = strtolower($match[1]);
            return array_key_exists($key, $values) ? htmlspecialchars($values[$key], ENT_QUOTES, 'UTF-8') : $match[0];
        }, $templates[$mail['templateId']]);
        $html = afstort_prepare_document_email($html, (int)$trip['id'], $documentKey);
        if ($kind === 'Afronding' && $trip['opmerking'] !== '') $html .= '<p>' . nl2br(htmlspecialchars($trip['opmerking'], ENT_QUOTES, 'UTF-8')) . '</p>';
        $mail['html'] = $html;
        $mail['receipts'] = $kind === 'Afronding' ? $trip['receipts'] : [];
        unset($mail['body'], $mail['copy']);
    }
    unset($mail);
    return $specs;
}

function live2_message(array $mail): array {
    $from = 'noreply@nierstichtingnederland.nl';
    foreach (['to','cc','bcc'] as $field) {
        if (($mail[$field] ?? '') !== '' && !filter_var($mail[$field], FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Ongeldig mailadres.');
    }
    if (preg_match('/[\r\n]/', $mail['subject'])) throw new InvalidArgumentException('Ongeldig mailonderwerp.');
    $boundary = 'afstort-' . bin2hex(random_bytes(16));
    $headers = "From: $from\r\nReply-To: $from\r\nMIME-Version: 1.0\r\n";
    foreach (['cc'=>'Cc', 'bcc'=>'Bcc'] as $field=>$header) if (!empty($mail[$field])) $headers .= "$header: " . $mail[$field] . "\r\n";
    $headers .= 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
    $body = '--' . $boundary . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($mail['html']));
    foreach ($mail['receipts'] as $i=>$receipt) {
        $ext = ['image/png'=>'png', 'image/webp'=>'webp', 'image/jpeg'=>'jpg'][$receipt['type']];
        $name = 'bon-' . ($i + 1) . '.' . $ext;
        $body .= '--' . $boundary . "\r\nContent-Type: " . $receipt['type'] . "; name=\"$name\"\r\n"
            . "Content-Disposition: attachment; filename=\"$name\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split($receipt['data']);
    }
    $body .= '--' . $boundary . "--\r\n";
    return ['headers'=>$headers, 'body'=>$body, 'envelope'=>'-f' . $from];
}

function live2_read_row(PDO $pdo, int $id, bool $lock = false): array {
    $query = $pdo->prepare('SELECT * FROM ritten WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $query->execute([$id]);
    $row = $query->fetch();
    if (!$row) throw new DomainException('Deze rit bestaat niet meer.');
    return $row;
}

function live2_dispatch(PDO $pdo, int $id, string $batch, ?callable $sendMail = null): void {
    $sendMail = $sendMail ?? 'mail';
    $query = $pdo->prepare('SELECT * FROM rit_live_outbox WHERE rit_id = ? AND batch = ? ORDER BY id');
    $query->execute([$id, $batch]);
    $mails = $query->fetchAll();
    foreach ($mails as $item) {
        if (!in_array($item['status'], ['pending','failed'], true)) continue;
        // Persist the claim BEFORE sending: a crashed request must not resend an uncertain message.
        $claim = $pdo->prepare("UPDATE rit_live_outbox SET status='sending' WHERE id=? AND status IN ('pending','failed')");
        $claim->execute([$item['id']]);
        if ($claim->rowCount() !== 1) continue;
        try {
            $pdo->beginTransaction();
            $row = live2_read_row($pdo, $id, true);
            if (!hash_equals($item['version'], ritVersion($row))) {
                $pdo->prepare("UPDATE rit_live_outbox SET status='cancelled' WHERE id=?")->execute([$item['id']]);
                $pdo->commit();
                continue;
            }
            $payload = json_decode($item['payload'], true, 512, JSON_THROW_ON_ERROR);
            if ($item['kind'] === 'Chauffeurvoorstel' && (!isUnassignedChauffeurValue($row['chauffeur']) || getOpenstaandeRitAanbieding($pdo, $id))) {
                $pdo->prepare("UPDATE rit_live_outbox SET status='cancelled' WHERE id=?")->execute([$item['id']]);
                $pdo->commit(); continue;
            }
            $message = live2_message($payload);
            $sent = $sendMail($payload['to'], $payload['subject'], $message['body'], $message['headers'], $message['envelope']);
            $pdo->prepare('UPDATE rit_live_outbox SET status=? WHERE id=?')->execute([$sent ? 'sent' : 'failed', $item['id']]);
            if ($sent && $item['kind'] === 'Chauffeurvoorstel') {
                registreerRitAanbieding($pdo, $id, $payload['offer']['name'], $payload['to'], $payload['offer']['distance']);
            }
            logRitEmail($pdo, $id, $item['kind'] . ' (nieuw portaal)', $payload['to'], $payload['subject'], $sent ? 'verzonden' : 'mislukt');
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Nieuw portaal mail: ' . $error->getMessage());
            // Leave 'sending' intact. Delivery may already have happened.
        }
    }
    $pdo->beginTransaction();
    try {
        $row = live2_read_row($pdo, $id, true);
        $extra = live2_extra($pdo, $id);
        if (!$extra || $extra['batch'] !== $batch || !hash_equals($extra['version'], ritVersion($row))) { $pdo->commit(); return; }
        $query->execute([$id, $batch]);
        $results = $query->fetchAll();
        $statuses = array_column($results, 'status');
        $error = '';
        if (in_array('sending', $statuses, true)) $error = 'De verzendstatus is nog niet bevestigd. Vernieuw later; blijft deze melding staan, laat de beheerder de verzending controleren. Het bericht wordt niet automatisch opnieuw verstuurd.';
        elseif (count(array_filter($statuses, function ($status) { return $status !== 'sent'; }))) $error = 'Niet alle mails zijn verstuurd. De invoer is bewaard. Probeer opnieuw; al verstuurde berichten worden overgeslagen.';
        if ($error === '' && $results) {
            $kind = $results[0]['kind'];
            if ($kind === 'Afronding') {
                $pdo->prepare("UPDATE ritten SET status='Afgehandeld' WHERE id=?")->execute([$id]);
                $row = live2_read_row($pdo, $id);
            }
            $extra['phase'] = $kind === 'Afspraak' ? 'planned' : ($kind === 'Afronding' ? 'done' : 'available');
        }
        $pdo->prepare('UPDATE rit_live_ui SET phase=?, mail_error=?, version=?, row_data=?, revision=revision+1 WHERE rit_id=?')
            ->execute([$extra['phase'], $error, ritVersion($row), json_encode($row, JSON_THROW_ON_ERROR), $id]);
        $pdo->commit();
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

function live2_offer(PDO $pdo, int $id, ?callable $sendMail = null): string {
    $batch = null;
    $pdo->beginTransaction();
    try {
        $row = live2_read_row($pdo, $id, true);
        $extra = live2_extra($pdo, $id);
        if (!isUnassignedChauffeurValue($row['chauffeur'])) { $pdo->commit(); return 'De rit heeft al een gekozen chauffeur.'; }
        $active = getOpenstaandeRitAanbieding($pdo, $id);
        if ($active) { $pdo->commit(); return 'De rit is uitgezet bij chauffeur ' . $active['chauffeur_naam'] . '. Het bestaande voorstel blijft actief.'; }
        if (!$extra || !$extra['batch'] || !hash_equals($extra['version'], ritVersion($row))) { $pdo->commit(); return ''; }
        $query = $pdo->prepare('SELECT * FROM rit_live_outbox WHERE rit_id = ? AND batch = ? ORDER BY id');
        $query->execute([$id, $extra['batch']]);
        $mails = $query->fetchAll();
        $contactSent = false; $proposal = null;
        foreach ($mails as $mail) {
            if ($mail['kind'] === 'Aanvraag' && $mail['status'] === 'sent') $contactSent = true;
            if ($mail['kind'] === 'Chauffeurvoorstel') $proposal = $mail;
        }
        if (!$contactSent) { $pdo->commit(); return ''; }
        if (!$proposal) {
            $selected = selectNearestChauffeur($pdo, $id);
            if (($selected['status'] ?? '') !== 'ok') {
                $error = 'De rit is opgeslagen, maar er is nog geen chauffeur aangeschreven: ' . ($selected['message'] ?? 'Chauffeurselectie mislukt.');
                $pdo->prepare('UPDATE rit_live_ui SET mail_error=?, revision=revision+1 WHERE rit_id=?')->execute([$error, $id]);
                logRitEmail($pdo, $id, 'Chauffeurvoorstel', '-', 'Automatische chauffeurselectie', 'mislukt', $error);
                $pdo->commit(); return $error;
            }
            $name = $selected['chauffeurNaam'];
            assertNotMedewerkerRecipient($pdo, $name, $selected['chauffeurEmail']);
            $escape = function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
            $decline = 'https://nierstichtingnederland.nl/afstort/declineRit.php?rit=' . $id . '&chauffeur=' . rawurlencode($name);
            $html = 'Beste ' . $escape($name) . ',<br><br>Je bent geselecteerd als <b>dichtstbijzijnde chauffeur</b> voor een afhaalopdracht.'
                . '<br>Collectegebied: <b>' . $escape($row['collectegebied']) . '</b><br>Postcode/plaats: <b>' . $escape($row['postcodePlaats']) . '</b>'
                . '<br><br>Als je deze rit gaat uitvoeren, log dan in op het <a href="https://nierstichtingnederland.nl/afstort">afstortportaal</a> om de rit op jouw naam te zetten.'
                . '<br><br>Kun je deze rit niet uitvoeren? <a href="' . $escape($decline) . '">Ik kan deze rit niet uitvoeren</a>.'
                . '<br><br>Met vriendelijke groet,<br>Nierstichting collectieteam';
            $mail = ['to'=>$selected['chauffeurEmail'], 'subject'=>'Afhaalopdracht collecte-opbrengst (chauffeur)',
                'cc'=>'','bcc'=>'','html'=>$html,'receipts'=>[], 'offer'=>['name'=>$name,'distance'=>$selected['afstandKm']]];
            $batch = $extra['batch'];
            $pdo->prepare('INSERT INTO rit_live_outbox (id, rit_id, batch, kind, version, payload) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([bin2hex(random_bytes(16)), $id, $batch, 'Chauffeurvoorstel', ritVersion($row), json_encode($mail, JSON_THROW_ON_ERROR)]);
        }
        $pdo->commit();
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
    if ($batch) live2_dispatch($pdo, $id, $batch, $sendMail);
    $active = getOpenstaandeRitAanbieding($pdo, $id);
    if ($active) return 'De rit is uitgezet bij chauffeur ' . $active['chauffeur_naam'] . '. Deze chauffeur is aangeschreven.';
    return 'De rit is opgeslagen, maar het chauffeursvoorstel is nog niet succesvol verstuurd. Controleer de verzendstatus bij de rit.';
}

function live2_mutate(PDO $pdo, array $input, array $user, array $uploads, string $documentKey, ?callable $sendMail = null): string {
    $action = beta_text($input, 'action', 30);
    if (!in_array($action, ['create','assign','claim','save_schedule','confirm','save_finish','finish','retry_mail','correct_done'], true)) throw new InvalidArgumentException('Gebruik het bestaande portaal voor beheer.');
    $drivers = $pdo->query('SELECT id, naam, email, fullAccess, is_medewerker, IBAN, postcode FROM chauffeurs')->fetchAll();
    $batch = null;
    $pdo->beginTransaction();
    try {
        $extra = null; $requestKey = null;
        if ($action === 'create') {
            if (!$user['office']) throw new DomainException('Alleen kantoor kan ritten aanmaken.');
            $requestKey = beta_text($input, 'requestKey', 32);
            if (!preg_match('/^[a-f0-9]{32}$/D', $requestKey)) throw new InvalidArgumentException('Ongeldig verzoek.');
            $existing = $pdo->prepare('SELECT * FROM rit_live_ui WHERE request_key=? FOR UPDATE');
            $existing->execute([$requestKey]);
            $previous = $existing->fetch();
            if ($previous) {
                if ($previous['owner'] !== $user['id']) throw new DomainException('Ongeldig verzoek.');
                $pdo->commit();
                if ($previous['batch']) live2_dispatch($pdo, (int)$previous['rit_id'], $previous['batch'], $sendMail);
                return 'Deze rit was al aangemaakt. ' . live2_offer($pdo, (int)$previous['rit_id'], $sendMail);
            }
            $state = beta_initial_state(); $state['preferences'] = portal_settings($pdo); $state['trips'] = [];
            beta_apply($state, $input, $user);
            $trip = array_values($state['trips'])[0];
            $row = $trip; $row['opmerking'] = $trip['contactOpmerking'];
            $row['chauffeur'] = ''; $row['status'] = '-';
            $row['soort'] = ['Munten'=>'alleen muntgeld', 'Biljetten'=>'alleen briefgeld', 'Munten en biljetten'=>'munt- en briefgeld'][$trip['soort']];
            $row['gereden'] = 0;
            $row['aangemaakt_door'] = $user['name']; $row['aangemaakt_door_email'] = $user['email'];
            $row['lat'] = null; $row['lon'] = null;
            if (function_exists('geocodePostcode')) [$row['lat'], $row['lon']] = geocodePostcode(extractPostcode6($row['postcodePlaats']));
            $fields = ['collectegebied','gebiedsnummer','wijknaam','contactpersoon','adres','postcodePlaats',
                'telefoonnummer','email','opmerking','voorkeurAfhaalmoment','verwachtBedrag','soort',
                'chauffeur','afhaalmoment','afhaaltijd','gestort','gereden','status','lat','lon','aangemaakt_door','aangemaakt_door_email','collectejaar'];
            $insert = $pdo->prepare('INSERT INTO ritten (' . implode(',', $fields) . ') VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ')');
            $insert->execute(array_map(function ($key) use ($row) { return $row[$key]; }, $fields));
            $id = (int)$pdo->lastInsertId(); $row = live2_read_row($pdo, $id); $trip['id'] = (string)$id;
            $trip['soort'] = $row['soort'];
            $kind = 'Aanvraag'; $message = 'Rit aangemaakt.';
        } else {
            $id = (int)beta_text($input, 'id', 20);
            $row = live2_read_row($pdo, $id, true);
            if (!live2_visible($pdo, $row, $user)) throw new DomainException('Geen toegang tot deze rit.');
            $extra = live2_extra($pdo, $id);
            if (!hash_equals(live2_revision($row, $extra), (string)($input['revision'] ?? ''))) throw new RitConflict('Deze rit is ondertussen gewijzigd. Je invoer blijft in beeld. Kopieer je wijzigingen, sluit het formulier en vernieuw het overzicht.');
            $trip = live2_trip($row, $drivers, $extra);
            if ($action === 'retry_mail') {
                if (!beta_can_edit($trip, $user) || !$extra || !$extra['batch'] || !hash_equals($extra['version'], ritVersion($row))) throw new DomainException('Deze verzending kan niet meer worden herhaald.');
                $pdo->commit(); live2_dispatch($pdo, $id, $extra['batch'], $sendMail);
                $offerMessage = live2_offer($pdo, $id, $sendMail);
                return $offerMessage !== '' ? $offerMessage : 'Verzendstatus gecontroleerd. Alleen eerder mislukte berichten worden opnieuw aangeboden.';
            }
            if ($action === 'correct_done') {
                if (!$user['office'] || $trip['status'] !== 'done') throw new DomainException('Alleen medewerkers mogen afgeronde ritten aanpassen.');
                $kilometers = beta_number($input, 'gereden', 10000);
                if ((float)$kilometers !== floor((float)$kilometers)) throw new InvalidArgumentException('Vul een geheel aantal kilometers in.');
                $amount = beta_number($input, 'gestort', 1000000);
                $kmChanged = (float)$kilometers !== (float)$trip['gereden'];
                $amountChanged = (float)$amount !== (float)$trip['gestort'];
                if (($kmChanged && ($input['confirmKilometers'] ?? '') !== $kilometers)
                    || ($amountChanged && ($input['confirmAmount'] ?? '') !== $amount)) throw new InvalidArgumentException('Bevestig eerst de aangepaste kilometers en het aangepaste bedrag.');
                $remove = $input['removeReceipts'] ?? [];
                if (!is_array($remove)) throw new InvalidArgumentException('Ongeldige bijlagen.');
                $previousReceipts = $trip['receipts'];
                $trip['receipts'] = array_values(array_filter($trip['receipts'], fn($receipt) => !in_array($receipt['id'], $remove, true)));
                $trip['receipts'] = array_merge($trip['receipts'], $uploads);
                if (count($trip['receipts']) > 3) throw new InvalidArgumentException('Voeg maximaal drie bonfoto’s toe.');
                $receiptsChanged = $trip['receipts'] !== $previousReceipts;
                if (!$kmChanged && !$amountChanged && !$receiptsChanged) { $pdo->commit(); return 'Er zijn geen wijzigingen.'; }
                $trip['gereden'] = (string)(int)$kilometers;
                $trip['gestort'] = $amount;
                if ($amountChanged) $trip['bedragAangepastDoor'] = $user['name'];
                $pdo->prepare('UPDATE ritten SET gereden=?, gestort=? WHERE id=?')->execute([$trip['gereden'], $amount, $id]);
                $row = live2_read_row($pdo, $id);
                live2_store($pdo, $row, $trip, $extra, $extra['batch'] ?? null);
                $pdo->commit();
                return 'Aanpassing opgeslagen.';
            }
            if ($trip['status'] === 'done') throw new DomainException('Deze rit is al afgerond.');
            if ($action === 'claim' && (!isUnassignedChauffeurValue($row['chauffeur']) ||
                (!heeftChauffeurOpenstaandeAanbieding($pdo, $id, $user['name']) && !magChauffeurRitVrijKiezen($pdo, $id, $user['name'])))) throw new DomainException('Deze rit is niet voor jou beschikbaar.');
            if ($action === 'assign' && !isUnassignedChauffeurValue($row['chauffeur'])) throw new DomainException('Deze rit is al toegewezen.');
            if (in_array($action, ['assign', 'claim'], true)) {
                $name = $user['name'];
                if ($action === 'assign') {
                    $selected = array_values(array_filter($drivers, fn($d) => (string)$d['id'] === (string)($input['chauffeurId'] ?? '')));
                    $name = $selected[0]['naam'] ?? '';
                }
                if (!chauffeur_beschikbaar($pdo, $name, (int)($row['collectejaar'] ?? 2026))) throw new DomainException('Deze chauffeur is niet beschikbaar voor het collectejaar van deze rit.');
            }
            if ($extra && $extra['batch'] && hash_equals($extra['version'], ritVersion($row))) {
                $pending = $pdo->prepare("SELECT status FROM rit_live_outbox WHERE batch=? AND status <> 'sent'");
                $pending->execute([$extra['batch']]);
                if ($pending->fetchColumn() !== false) {
                    $kindQuery = $pdo->prepare('SELECT kind FROM rit_live_outbox WHERE batch=? LIMIT 1');
                    $kindQuery->execute([$extra['batch']]);
                    $previousKind = $kindQuery->fetchColumn();
                    if (($action === 'confirm' && $previousKind === 'Afspraak') || ($action === 'finish' && $previousKind === 'Afronding')) {
                        $pdo->commit(); live2_dispatch($pdo, $id, $extra['batch'], $sendMail);
                        return 'Verzendstatus gecontroleerd. Een herhaling gebruikt de eerder opgeslagen gegevens.';
                    }
                    throw new DomainException('Er staat nog een verzending open. Rond die eerst af voordat je de rit wijzigt.');
                }
            }
            $state = ['trips'=>[(string)$id=>$trip], 'mails'=>[], 'drivers'=>beta_selectable_drivers($drivers), 'meta'=>['source'=>'snapshot']];
            // Use the beta validation and workflow for non-mail actions only.
            $validation = $input;
            // The beta validator uses an integer counter internally; the live hash has already been checked.
            $state['trips'][(string)$id]['revision'] = 1;
            $validation['revision'] = 1;
            $revisingSchedule = $user['office'] && $trip['status'] === 'planned' && in_array($action, ['save_schedule', 'confirm'], true);
            if ($revisingSchedule) $state['trips'][(string)$id]['status'] = 'planning';
            if ($action === 'confirm') $validation['action'] = 'save_schedule';
            if ($action === 'finish') $validation['action'] = 'save_finish';
            if ($action === 'confirm') {
                beta_date(beta_text($input, 'afhaalmoment', 10)); beta_text($input, 'afhaaltijd', 5);
            }
            if ($action === 'finish') { beta_number($input, 'gestort', 1000000); beta_number($input, 'gereden', 10000); }
            beta_apply($state, $validation, $user, $uploads);
            $trip = $state['trips'][(string)$id];
            if ($revisingSchedule && $action === 'save_schedule') $trip['status'] = 'planned';
            $kind = $action === 'confirm' ? 'Afspraak' : ($action === 'finish' ? 'Afronding' : '');
            if (in_array($action, ['assign','claim'], true)) {
                assertNotMedewerkerRecipient($pdo, $trip['chauffeur'], $trip['chauffeurEmail']);
                $pdo->prepare('UPDATE ritten SET chauffeur=?, status=?, verwachtBedrag=?, voorkeurAfhaalmoment=?, afhaalmoment=?, afhaaltijd=? WHERE id=?')->execute([$trip['chauffeur'], '-', $trip['verwachtBedrag'], $trip['voorkeurAfhaalmoment'], $trip['afhaalmoment'], $trip['afhaaltijd'], $id]);
                resetRitAanbiedingen($pdo, $id);
                $message = 'Rit toegewezen aan ' . $trip['chauffeur'] . '. Er is nog geen mail verstuurd.';
            } elseif (in_array($action, ['save_schedule','confirm'], true)) {
                $pdo->prepare('UPDATE ritten SET afhaalmoment=?, afhaaltijd=?, verwachtBedrag=?, voorkeurAfhaalmoment=? WHERE id=?')->execute([$trip['afhaalmoment'], $trip['afhaaltijd'], $trip['verwachtBedrag'], $trip['voorkeurAfhaalmoment'], $id]);
                $message = 'Afspraakgegevens opgeslagen.';
            } else {
                $trip['interneOpmerking'] = beta_text($input, 'interneOpmerking', 2000, false);
                $trip['gereden'] = $trip['gereden'] === '' ? '0' : (string)(int)round((float)$trip['gereden']);
                $pdo->prepare('UPDATE ritten SET gereden=?, gestort=? WHERE id=?')->execute([$trip['gereden'], $trip['gestort'], $id]);
                $message = 'Afrondingsgegevens en bonfoto’s opgeslagen.';
            }
            $row = live2_read_row($pdo, $id);
        }
        $trip['mailError'] = '';
        if ($kind !== '') {
            $batch = bin2hex(random_bytes(16));
            foreach (live2_mails($pdo, $trip, $kind, $documentKey) as $mail) {
                $pdo->prepare('INSERT INTO rit_live_outbox (id, rit_id, batch, kind, version, payload) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([bin2hex(random_bytes(16)), $id, $batch, $kind, ritVersion($row), json_encode($mail, JSON_THROW_ON_ERROR)]);
            }
            $trip['mailError'] = 'De verzending wordt verwerkt.';
        }
        live2_store($pdo, $row, $trip, $extra, $batch, $requestKey, $user['id']);
        $pdo->commit();
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
    if ($batch) { live2_dispatch($pdo, $id, $batch, $sendMail); $message .= ' Controleer de verzendstatus bij de rit.'; }
    if ($action === 'create') $message .= ' ' . live2_offer($pdo, $id, $sendMail);
    return $message;
}

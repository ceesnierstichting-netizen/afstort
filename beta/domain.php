<?php
// Pure beta workflow. Deliberately has no database or mail dependencies.
require_once __DIR__ . '/mail.php';

function beta_initial_state(): array {
    $trip = [
        'id' => 'voorbeeld-1', 'revision' => 1, 'collectegebied' => 'Voorbeeldstad Centrum',
        'gebiedsnummer' => 'TEST-001', 'wijknaam' => '', 'contactpersoon' => 'Voorbeeldcontact',
        'adres' => 'Voorbeeldstraat 12', 'postcodePlaats' => '1234 AB Voorbeeldstad',
        'telefoonnummer' => '0612345678', 'email' => 'contact@example.test', 'contactOpmerking' => '',
        'voorkeurAfhaalmoment' => '', 'verwachtBedrag' => '850.00', 'soort' => 'Munten',
        'status' => 'available', 'chauffeurId' => '', 'chauffeur' => '',
        'afhaalmoment' => '', 'afhaaltijd' => '', 'gereden' => '', 'gestort' => '',
        'opmerking' => '', 'receipts' => [], 'mailError' => '', 'createdAt' => date(DATE_ATOM),
    ];
    return ['trips' => [$trip['id'] => $trip], 'mails' => []];
}

function beta_text(array $input, string $key, int $max = 200, bool $required = true): string {
    if (isset($input[$key]) && !is_scalar($input[$key])) {
        throw new InvalidArgumentException('Ongeldige invoer voor ' . $key . '.');
    }
    $value = trim((string)($input[$key] ?? ''));
    if (($required && $value === '') || strlen($value) > $max) {
        throw new InvalidArgumentException('Vul ' . $key . ' in (maximaal ' . $max . ' tekens).');
    }
    return $value;
}

function beta_number(array $input, string $key, float $max, bool $required = true): string {
    $value = str_replace(',', '.', beta_text($input, $key, 20, $required));
    if ($value === '' && !$required) return '';
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/D', $value) || (float)$value > $max) {
        throw new InvalidArgumentException('Vul een geldig, niet-negatief getal in bij ' . $key . ' (maximaal twee decimalen).');
    }
    return number_format((float)$value, 2, '.', '');
}

function beta_date(string $value, bool $required = true): string {
    if ($value === '' && !$required) return '';
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$parsed || $parsed->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException('Kies een geldige afhaaldatum.');
    }
    return $value;
}

function beta_can_edit(array $trip, array $user): bool {
    return $user['office'] || ($trip['chauffeurId'] !== '' && $trip['chauffeurId'] === $user['id']);
}

function beta_drivers(array $state): array {
    $drivers = ($state['meta']['source'] ?? '') === 'snapshot' ? ($state['drivers'] ?? []) : [
        ['id'=>'test-chauffeur-1', 'name'=>'Testchauffeur Anna', 'email'=>'anna@example.test'],
        ['id'=>'test-chauffeur-2', 'name'=>'Testchauffeur Bram', 'email'=>'bram@example.test'],
    ];
    $byId = [];
    foreach ($drivers as $driver) $byId[$driver['id']] = $driver;
    foreach (($state['accounts'] ?? []) as $account) {
        unset($byId[$account['id']]);
        if ($account['role'] === 'driver' && $account['active']) $byId[$account['id']] = ['id'=>$account['id'], 'name'=>$account['name'], 'email'=>$account['email'], 'iban'=>$account['iban']];
    }
    return array_values($byId);
}

function beta_accounts(array $state): array {
    $accounts = $state['sourceAccounts'] ?? [];
    if (($state['meta']['source'] ?? '') !== 'snapshot') {
        foreach (beta_drivers([]) as $driver) $accounts[$driver['id']] = $driver + ['role'=>'driver', 'active'=>true, 'iban'=>'', 'postcode'=>'', 'revision'=>1];
    }
    foreach (($state['accounts'] ?? []) as $id => $account) $accounts[$id] = $account;
    return $accounts;
}

function beta_save_account(array &$state, array $input, array $user): string {
    if (empty($user['admin'])) throw new DomainException('Alleen Admin mag chauffeurs en medewerkers beheren.');
    $accounts = beta_accounts($state);
    $id = beta_text($input, 'accountId', 100, false);
    $existing = $id !== '' ? ($accounts[$id] ?? null) : null;
    if ($id !== '' && !$existing) throw new DomainException('Deze gebruiker bestaat niet meer.');
    if ($existing && (string)$existing['revision'] !== (string)($input['accountRevision'] ?? '')) throw new DomainException('Deze gebruiker is ondertussen gewijzigd. Open Beheer opnieuw.');
    $role = beta_text($input, 'role', 20);
    if (!in_array($role, ['driver','employee'], true)) throw new InvalidArgumentException('Kies chauffeur of medewerker.');
    $name = beta_text($input, 'name', 200);
    $email = beta_text($input, 'email', 200);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Vul een geldig e-mailadres in.');
    foreach ($accounts as $other) {
        if ($other['id'] !== $id && (strcasecmp($other['name'], $name) === 0 || strcasecmp($other['email'], $email) === 0)) throw new InvalidArgumentException('Deze naam of dit e-mailadres bestaat al.');
    }
    $postcode = $role === 'driver' ? strtoupper(preg_replace('/\s+/', '', beta_text($input, 'postcode', 10, false))) : '';
    if ($postcode !== '' && !preg_match('/^[1-9]\d{3}[A-Z]{2}$/D', $postcode)) throw new InvalidArgumentException('Vul een geldige Nederlandse postcode in.');
    $iban = $role === 'driver' ? strtoupper(preg_replace('/\s+/', '', beta_text($input, 'iban', 40, false))) : '';
    if ($iban !== '') {
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/D', $iban)) throw new InvalidArgumentException('Vul een geldig IBAN in.');
        $reordered = substr($iban, 4) . substr($iban, 0, 4); $remainder = 0;
        foreach (str_split($reordered) as $char) foreach (str_split(ctype_alpha($char) ? (string)(ord($char) - 55) : $char) as $digit) $remainder = ($remainder * 10 + (int)$digit) % 97;
        if ($remainder !== 1) throw new InvalidArgumentException('Vul een geldig IBAN in.');
    }
    $id = $id ?: 'beta-account-' . bin2hex(random_bytes(12));
    $state['accounts'][$id] = ['id'=>$id, 'name'=>$name, 'email'=>$email, 'role'=>$role, 'active'=>($input['active'] ?? '') === '1', 'postcode'=>$role === 'driver' ? $postcode : '', 'iban'=>$role === 'driver' ? $iban : '', 'revision'=>($existing['revision'] ?? 0) + 1];
    return 'Testgebruiker opgeslagen. Er wordt geen echt account of uitnodiging aangemaakt.';
}

function beta_mail(array &$state, array &$trip, string $kind, array $user, bool $fail): bool {
    if ($fail) {
        $trip['mailError'] = 'Gesimuleerde mailfout. Je gegevens zijn bewaard. Probeer opnieuw zonder de foutsimulatie.';
        return false;
    }
    try { $mails = beta_build_mails($state, $trip, $kind, $user); }
    catch (DomainException $e) { $trip['mailError'] = $e->getMessage(); return false; }
    foreach ($mails as $mail) $state['mails'][] = $mail + [
        'id' => bin2hex(random_bytes(8)), 'tripId' => $trip['id'], 'kind' => $kind,
        'at' => date(DATE_ATOM), 'attachments' => $kind === 'Afronding' ? array_column($trip['receipts'], 'name') : [],
    ];
    $trip['mailError'] = '';
    return true;
}

function beta_apply(array &$state, array $input, array $user, array $uploads = []): string {
    $action = beta_text($input, 'action', 30);
    if ($action === 'save_account') return beta_save_account($state, $input, $user);
    if ($action === 'preferences') {
        if (empty($user['admin'])) throw new DomainException('Alleen de admin mag instellingen wijzigen.');
        $year = beta_text($input, 'collectejaar', 4);
        if (!preg_match('/^20\d{2}$/D', $year)) throw new InvalidArgumentException('Kies een collectejaar tussen 2000 en 2099.');
        $state['preferences']['years'][(int)$year] = ['kilometervergoeding'=>beta_number($input, 'kilometervergoeding', 10)];
        return 'Collectejaar en kilometervergoeding opgeslagen. Bestaande ritten blijven bewaard.';
    }
    $fail = ($input['simulateFailure'] ?? '') === '1';
    if ($action === 'create') {
        if (!$user['office']) throw new DomainException('Alleen kantoor kan testritten aanmaken.');
        if (count($state['trips']) >= 10000) throw new DomainException('De testomgeving bevat al 10.000 ritten.');
        $trip = array_values(beta_initial_state()['trips'])[0];
        $year = beta_text($input, 'collectejaar', 4, false) ?: '2026';
        if (!isset(beta_preferences($state)['years'][$year])) throw new InvalidArgumentException('Maak eerst dit collectejaar aan in Beheer.');
        $trip['collectejaar'] = (int)$year;
        foreach (['collectegebied', 'contactpersoon', 'adres', 'postcodePlaats', 'telefoonnummer', 'email'] as $field) {
            $trip[$field] = beta_text($input, $field);
        }
        foreach (['gebiedsnummer', 'wijknaam'] as $field) $trip[$field] = beta_text($input, $field, 100, false);
        $trip['contactOpmerking'] = beta_text($input, 'contactOpmerking', 2000, false);
        if (!filter_var($trip['email'], FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Vul een geldig e-mailadres in.');
        if (!preg_match('/\d/', $trip['adres'])) throw new InvalidArgumentException('Vul ook een huisnummer in.');
        if (!preg_match('/^[1-9]\d{3}\s*[A-Za-z]{2}\s+\S.+$/u', $trip['postcodePlaats'])) throw new InvalidArgumentException('Vul postcode én plaats in.');
        if (!preg_match('/^(?:0[1-9]\d{8}|(?:\+31|0031)[1-9]\d{8})$/D', preg_replace('/[\s().\/-]+/', '', $trip['telefoonnummer']))) throw new InvalidArgumentException('Vul een geldig Nederlands telefoonnummer in.');
        $trip['verwachtBedrag'] = beta_number($input, 'verwachtBedrag', 1000000);
        $trip['soort'] = beta_text($input, 'soort', 40);
        if (!in_array($trip['soort'], ['Munten', 'Biljetten', 'Munten en biljetten'], true)) throw new InvalidArgumentException('Kies een soort opbrengst.');
        $trip['voorkeurAfhaalmoment'] = beta_date(beta_text($input, 'voorkeurAfhaalmoment', 10, false), false);
        $trip['id'] = bin2hex(random_bytes(12));
        if (!beta_mail($state, $trip, 'Aanvraag', $user, false)) throw new DomainException($trip['mailError']);
        $state['trips'][$trip['id']] = $trip;
        return 'Testrit aangemaakt. De bevestiging staat in de testmailbox.';
    }
    $id = beta_text($input, 'id', 80);
    if (!isset($state['trips'][$id])) throw new DomainException('Deze testrit bestaat niet meer.');
    $trip = $state['trips'][$id];
    if ((string)$trip['revision'] !== (string)($input['revision'] ?? '')) throw new DomainException('Deze rit is ondertussen gewijzigd. Sluit het formulier en vernieuw het overzicht voordat je verdergaat.');
    if ($user['office'] && in_array($action, ['assign', 'save_schedule', 'confirm'], true)) {
        if (array_key_exists('verwachtBedrag', $input)) $trip['verwachtBedrag'] = beta_number($input, 'verwachtBedrag', 1000000);
        if (array_key_exists('voorkeurAfhaalmoment', $input)) $trip['voorkeurAfhaalmoment'] = beta_date(beta_text($input, 'voorkeurAfhaalmoment', 10, false), false);
    }
    if ($action === 'assign') {
        if (!$user['office']) throw new DomainException('Alleen medewerkers en de admin mogen een chauffeur toewijzen.');
        if ($trip['status'] !== 'available') throw new DomainException('Deze rit is al toegewezen. Vernieuw het overzicht.');
        if (array_key_exists('afhaalmoment', $input)) $trip['afhaalmoment'] = beta_date(beta_text($input, 'afhaalmoment', 10, false), false);
        if (array_key_exists('afhaaltijd', $input)) {
            $time = beta_text($input, 'afhaaltijd', 5, false);
            if ($time !== '' && $time !== $trip['afhaaltijd'] && !preg_match('/^(?:[01]\d|2[0-3]):(?:00|15|30|45)$/D', $time)) throw new InvalidArgumentException('Kies een geldige tijd op een kwartier: :00, :15, :30 of :45.');
            $trip['afhaaltijd'] = $time;
        }
        $driverId = beta_text($input, 'chauffeurId', 100);
        $selected = null;
        foreach (beta_drivers($state) as $driver) if ((string)$driver['id'] === $driverId) $selected = $driver;
        if (!$selected) throw new InvalidArgumentException('Kies een beschikbare chauffeur uit de lijst.');
        $trip['chauffeurId'] = (string)$selected['id'];
        $trip['chauffeur'] = $selected['name'];
        $trip['chauffeurEmail'] = $selected['email'];
        $trip['chauffeurIban'] = $selected['iban'] ?? '';
        $trip['status'] = 'planning';
        $message = 'Testrit toegewezen aan ' . $selected['name'] . '. De afspraak kan nu worden vastgelegd. Er is nog geen mail verstuurd.';
    } elseif ($action === 'claim') {
        if ($user['office'] && ($input['testView'] ?? '') !== 'driver') throw new DomainException('Kies als medewerker of admin eerst een chauffeur om de rit toe te wijzen.');
        if ($trip['status'] !== 'available') throw new DomainException('Deze rit is al opgepakt.');
        $trip['chauffeurId'] = $user['id'];
        $trip['chauffeur'] = $user['name'];
        $trip['chauffeurEmail'] = $user['email'];
        $trip['chauffeurIban'] = $user['iban'] ?? '';
        $trip['status'] = 'planning';
        $message = 'De testrit staat op jouw naam. Leg nu de afspraak vast, of ga later verder.';
    } else {
        if (!beta_can_edit($trip, $user)) throw new DomainException('Deze rit is van een andere chauffeur.');
        if (in_array($action, ['save_schedule', 'confirm'], true)) {
            if ($trip['status'] !== 'planning') throw new DomainException('Deze rit kan nu niet gepland worden.');
            $required = $action === 'confirm';
            $trip['afhaalmoment'] = beta_date(beta_text($input, 'afhaalmoment', 10, $required), $required);
            $previousTime = $trip['afhaaltijd'];
            $trip['afhaaltijd'] = beta_text($input, 'afhaaltijd', 5, $required);
            if ($trip['afhaaltijd'] !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $trip['afhaaltijd'])) throw new InvalidArgumentException('Vul een geldige tijd in.');
            if ($trip['afhaaltijd'] !== '' && $trip['afhaaltijd'] !== $previousTime && !preg_match('/:(?:00|15|30|45)$/D', $trip['afhaaltijd'])) throw new InvalidArgumentException('Kies een tijd op een kwartier: :00, :15, :30 of :45.');
            $message = 'Afspraakgegevens bewaard. Er is nog geen bevestiging verstuurd.';
            if ($action === 'confirm') {
                if (beta_mail($state, $trip, 'Afspraak', $user, $fail)) {
                    $trip['status'] = 'planned';
                    $message = 'Afspraak bevestigd. De mails staan in de testmailbox.';
                } else $message = $trip['mailError'];
            }
        } elseif (in_array($action, ['save_finish', 'finish'], true)) {
            if ($trip['status'] !== 'planned') throw new DomainException('Alleen een geplande rit kan worden afgerond.');
            $required = $action === 'finish';
            $trip['gereden'] = beta_number($input, 'gereden', 10000, $required);
            $trip['gestort'] = beta_number($input, 'gestort', 1000000, $required);
            $trip['opmerking'] = beta_text($input, 'opmerking', 2000, false);
            $remove = $input['removeReceipts'] ?? [];
            if (!is_array($remove)) throw new InvalidArgumentException('Ongeldige bijlagen.');
            $trip['receipts'] = array_values(array_filter($trip['receipts'], function ($receipt) use ($remove) { return !in_array($receipt['id'], $remove, true); }));
            $trip['receipts'] = array_merge($trip['receipts'], $uploads);
            if (count($trip['receipts']) > 3) throw new InvalidArgumentException('Voeg maximaal drie bonfoto’s toe.');
            $storedBytes = 0;
            foreach ($state['trips'] as $storedTrip) {
                foreach (($storedTrip['id'] === $id ? $trip : $storedTrip)['receipts'] as $receipt) $storedBytes += strlen($receipt['data']);
            }
            if ($storedBytes > 12 * 1024 * 1024) throw new InvalidArgumentException('De testopslag voor foto’s is vol. Kies kleinere foto’s of laat de beheerder de testomgeving leegmaken.');
            $message = 'Afrondingsgegevens en bonfoto’s bewaard. Je kunt later verdergaan.';
            if ($action === 'finish') {
                if (beta_mail($state, $trip, 'Afronding', $user, $fail)) {
                    $trip['status'] = 'done';
                    $message = 'Testrit afgerond. De afrondingsmail staat in de testmailbox.';
                } else $message = $trip['mailError'];
            }
        } else throw new InvalidArgumentException('Onbekende actie.');
    }
    $trip['revision']++;
    $state['trips'][$id] = $trip;
    return $message;
}

function beta_preferences(array $state): array {
    $preferences = $state['preferences'] ?? [];
    $preferences['years'][2026] = $preferences['years'][2026] ?? ['kilometervergoeding'=>'0.30'];
    ksort($preferences['years']);
    return $preferences;
}

function beta_public_state(array $state, array $user): array {
    $trips = array_filter($state['trips'], function ($trip) use ($user) {
        return $trip['status'] === 'available' || beta_can_edit($trip, $user);
    });
    $mails = array_values(array_filter($state['mails'], function ($mail) use ($trips) { return isset($trips[$mail['tripId']]); }));
    foreach ($trips as &$trip) {
        if (empty($user['reportAll']) && $trip['chauffeurId'] !== $user['id']) unset($trip['chauffeurIban']);
        foreach ($trip['receipts'] as &$receipt) unset($receipt['data']);
        unset($receipt);
    }
    unset($trip);
    $drivers = $user['office'] ? beta_drivers($state) : [];
    foreach ($drivers as &$driver) unset($driver['iban']);
    unset($driver);
    return ['trips' => array_values($trips), 'mails' => $mails, 'drivers' => $drivers, 'accounts'=>!empty($user['admin']) ? array_values(beta_accounts($state)) : [], 'preferences'=>beta_preferences($state), 'meta' => $state['meta'] ?? ['source'=>'practice', 'importedAt'=>null]];
}

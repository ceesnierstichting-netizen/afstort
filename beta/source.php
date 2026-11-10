<?php
require_once __DIR__ . '/domain.php';
require_once dirname(__DIR__) . '/app_helpers.php';

function beta_selectable_drivers(array $rows): array {
    $drivers = [];
    foreach ($rows as $row) {
        if (isMedewerker($row) && !isSelectableMedewerkerChauffeur($row['naam'])) continue;
        if (trim((string)$row['naam']) === '') continue;
        $drivers[] = ['id'=>(string)$row['id'], 'name'=>(string)$row['naam'], 'email'=>(string)$row['email'], 'iban'=>(string)($row['IBAN'] ?? '')];
    }
    usort($drivers, function ($a, $b) { return strnatcasecmp($a['name'], $b['name']); });
    return $drivers;
}

/** Only SELECT statements run against production, inside a READ ONLY transaction. */
function beta_read_source(array $user, bool $import = false, bool $withAccounts = false) {
    if (!$user['office']) throw new DomainException('Alleen kantoor mag een testkopie van echte gegevens bekijken.');
    $config = [];
    $path = dirname(__DIR__, 2) . '/afstort-db-config.php';
    if (is_file($path)) $config = require $path;
    if (!is_array($config)) $config = [];
    $get = function ($env, $key) use ($config) { return getenv($env) ?: ($_SERVER[$env] ?? $config[$key] ?? null); };
    $host = $get('AFSTORT_DB_HOST', 'host');
    $database = $get('AFSTORT_DB_NAME', 'database');
    $username = getenv('AFSTORT_BETA_DB_USER') ?: $get('AFSTORT_DB_USER', 'user');
    $password = getenv('AFSTORT_BETA_DB_PASSWORD') ?: $get('AFSTORT_DB_PASSWORD', 'password');
    if (!$host || !$database || !$username || !$password) throw new RuntimeException('De databaseconfiguratie voor het uitlezen ontbreekt.');
    $pdo = new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, naam, email, fullAccess, is_medewerker FROM chauffeurs WHERE id = ?');
        $stmt->execute([$user['id']]);
        $current = $stmt->fetch();
        if (!$current || !hasDashboardAccess($current)) throw new DomainException('Je account heeft geen kantoorrechten meer. Log opnieuw in.');
        $drivers = $pdo->query('SELECT id, naam, email, fullAccess, is_medewerker, IBAN, postcode FROM chauffeurs')->fetchAll();
        if (!$import) return $withAccounts ? ['drivers'=>beta_selectable_drivers($drivers), 'accounts'=>beta_source_accounts($drivers)] : beta_selectable_drivers($drivers);
        $hasOpmerking = $pdo->query("SHOW COLUMNS FROM ritten LIKE 'opmerking'")->fetch();
        $opmerkingColumn = $hasOpmerking ? ', opmerking' : '';
        $rows = $pdo->query('SELECT id, collectegebied, gebiedsnummer, wijknaam, contactpersoon, adres, postcodePlaats, telefoonnummer, email, voorkeurAfhaalmoment, verwachtBedrag, soort, chauffeur, afhaalmoment, afhaaltijd, gestort, gereden, status' . $opmerkingColumn . ' FROM ritten ORDER BY id LIMIT 10001')->fetchAll();
        if (count($rows) > 10000) throw new DomainException('Er zijn meer dan 10.000 ritten. De testkopie is niet geladen.');
        $templates = $pdo->query('SELECT id, email_template FROM instellingen WHERE id IN (1, 3, 4, 5, 6)')->fetchAll();
        return beta_snapshot($rows, $drivers, $templates);
    } finally {
        // No commit is needed: even the read transaction is rolled back.
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}

function beta_source_accounts(array $rows): array {
    $accounts = [];
    foreach ($rows as $row) {
        if (hasAdminPermissions($row)) continue;
        $id = (string)$row['id'];
        $accounts[$id] = ['id'=>$id, 'name'=>(string)$row['naam'], 'email'=>(string)$row['email'], 'role'=>isMedewerker($row) ? 'employee' : 'driver', 'active'=>true, 'iban'=>(string)($row['IBAN'] ?? ''), 'postcode'=>(string)($row['postcode'] ?? ''), 'revision'=>1];
    }
    return $accounts;
}

function beta_snapshot(array $rows, array $drivers, array $templates): array {
    $state = ['trips' => [], 'mails' => [], 'drivers'=>beta_selectable_drivers($drivers), 'templates' => [], 'meta' => ['source' => 'snapshot', 'importedAt' => date(DATE_ATOM)]];
    $state['sourceAccounts'] = beta_source_accounts($drivers);
    $byName = [];
    foreach ($drivers as $driver) $byName[strtolower(trim($driver['naam']))] = $driver;
    foreach ($templates as $template) $state['templates'][(int)$template['id']] = (string)$template['email_template'];
    $base = array_values(beta_initial_state()['trips'])[0];
    foreach ($rows as $row) {
        $trip = $base;
        foreach (['collectegebied', 'gebiedsnummer', 'wijknaam', 'contactpersoon', 'adres', 'postcodePlaats', 'telefoonnummer', 'email', 'soort', 'verwachtBedrag', 'gereden', 'gestort'] as $key) $trip[$key] = trim((string)($row[$key] ?? ''));
        $trip['id'] = 'bron-' . (int)$row['id'];
        $trip['sourceId'] = (int)$row['id'];
        $trip['contactOpmerking'] = trim((string)($row['opmerking'] ?? ''));
        $trip['collectejaar'] = 2026;
        $trip['sourceStatus'] = (string)($row['status'] ?? '');
        $trip['createdAt'] = $state['meta']['importedAt'];
        foreach (['afhaalmoment', 'voorkeurAfhaalmoment'] as $key) {
            try { $trip[$key] = beta_date(substr((string)($row[$key] ?? ''), 0, 10), false); }
            catch (InvalidArgumentException $e) { $trip[$key] = ''; }
        }
        $time = substr((string)($row['afhaaltijd'] ?? ''), 0, 5);
        $trip['afhaaltijd'] = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time) ? $time : '';
        $name = trim((string)($row['chauffeur'] ?? ''));
        $assigned = !in_array(strtolower($name), ['', '-', 'chauffeur kiezen', '-- kies een chauffeur --'], true);
        $driver = $byName[strtolower($name)] ?? null;
        $trip['chauffeur'] = $assigned ? $name : '';
        $trip['chauffeurId'] = !$assigned ? '' : ($driver ? (string)$driver['id'] : 'onbekend-' . hash('sha256', $name));
        $trip['chauffeurEmail'] = $driver['email'] ?? '';
        $trip['chauffeurIban'] = $driver['IBAN'] ?? '';
        $trip['status'] = trim((string)($row['status'] ?? '')) === 'Afgehandeld' ? 'done' : (!$assigned ? 'available' : ($trip['afhaalmoment'] !== '' && $trip['afhaaltijd'] !== '' ? 'planned' : 'planning'));
        $state['trips'][$trip['id']] = $trip;
    }
    return $state;
}

<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/app_helpers.php';
require_once __DIR__ . '/beta/storage.php';
require_once __DIR__ . '/beta/source.php';

header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' blob:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'");

$api = isset($_GET['action']);
if (empty($_SESSION['username']) || empty($_SESSION['twofa_verified'])) {
    if ($api) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Je sessie is verlopen. Log in via het bestaande portaal en open daarna beta.php opnieuw.']);
    } else {
        header('Location: login.php');
    }
    exit;
}
$user = [
    'id' => (string)($_SESSION['user_id'] ?? $_SESSION['username']),
    'name' => (string)$_SESSION['username'], 'email' => (string)($_SESSION['user_email'] ?? ''),
    'office' => hasDashboardAccess($_SESSION),
    'admin' => hasAdminPermissions($_SESSION),
    'reportAll' => normalizeFullAccess($_SESSION['fullAccess'] ?? false),
    'iban' => (string)($_SESSION['IBAN'] ?? ''),
];

if ($api) {
    try {
        $dataset = (string)($_GET['dataset'] ?? 'practice');
        if (!in_array($dataset, ['practice', 'snapshot'], true)) throw new InvalidArgumentException('Onbekende testomgeving.');
        $sourceDrivers = $dataset === 'snapshot' ? beta_read_source($user, false, true) : null;
        $store = function ($callback) use ($dataset, $user, $sourceDrivers) {
            return beta_with_store(function (&$state) use ($callback, $sourceDrivers) {
                if ($sourceDrivers !== null) { $state['drivers'] = $sourceDrivers['drivers']; $state['sourceAccounts'] = $sourceDrivers['accounts']; }
                return $callback($state);
            }, $dataset, $user['id']);
        };
        if ($_GET['action'] === 'receipt' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            $receipt = $store(function (&$state) use ($user) {
                $trip = $state['trips'][(string)($_GET['id'] ?? '')] ?? null;
                if (!$trip || !beta_can_edit($trip, $user)) throw new DomainException('Geen toegang tot deze bon.');
                foreach ($trip['receipts'] as $receipt) if ($receipt['id'] === ($_GET['receipt'] ?? '')) return $receipt;
                throw new DomainException('Deze bon bestaat niet.');
            });
            header('Content-Type: ' . $receipt['type']);
            header('Content-Disposition: inline; filename="bon.' . ($receipt['type'] === 'image/png' ? 'png' : ($receipt['type'] === 'image/webp' ? 'webp' : 'jpg')) . '"');
            echo base64_decode($receipt['data'], true);
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $_GET['action'] === 'state') {
            echo json_encode($store(function (&$state) use ($user) { return beta_public_state($state, $user); }), JSON_THROW_ON_ERROR);
            exit;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($_GET['action'], ['mutate', 'import', 'preview'], true)) {
            http_response_code(405);
            echo json_encode(['error' => 'Ongeldig verzoek.']);
            exit;
        }
        if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) throw new InvalidArgumentException('De upload is te groot voor deze server. Kies kleinere foto’s.');
        afstort_require_csrf();
        if ($_GET['action'] === 'import') {
            if ($dataset !== 'snapshot') throw new InvalidArgumentException('Kies eerst de testkopie van echte ritten.');
            $snapshot = beta_read_source($user, true);
            $result = $store(function (&$state) use ($snapshot, $user) {
                $preferences = beta_preferences($state);
                $accounts = $state['accounts'] ?? [];
                $archive = array_filter($state['trips'], function ($trip) { return ($trip['collectejaar'] ?? 2026) !== 2026; });
                $archiveMails = array_values(array_filter($state['mails'], function ($mail) use ($archive) { return isset($archive[$mail['tripId']]); }));
                $state = $snapshot;
                $state['preferences'] = $preferences;
                $state['accounts'] = $accounts;
                $state['trips'] += $archive;
                $state['mails'] = array_merge($state['mails'], $archiveMails);
                return ['message'=>count($state['trips']) . ' ritten en de mailsjablonen zijn gekopieerd. Het huidige portaal is niet gewijzigd.'] + beta_public_state($state, $user);
            });
            echo json_encode($result, JSON_THROW_ON_ERROR);
            exit;
        }
        if ($_GET['action'] === 'preview') {
            $result = $store(function (&$state) use ($user) {
                $id = beta_text($_POST, 'id', 80);
                $trip = $state['trips'][$id] ?? null;
                if (!$trip || !beta_can_edit($trip, $user)) throw new DomainException('Geen toegang tot deze rit.');
                $kind = $trip['status'] === 'planning' ? 'Afspraak' : 'Afronding';
                foreach (['afhaalmoment','afhaaltijd','gestort','gereden','opmerking'] as $key) if (isset($_POST[$key])) $trip[$key] = beta_text($_POST, $key, 2000, false);
                return ['mails'=>beta_build_mails($state, $trip, $kind, $user)];
            });
            echo json_encode($result, JSON_THROW_ON_ERROR);
            exit;
        }
        $uploads = [];
        if (!empty($_FILES['receipts'])) {
            $files = $_FILES['receipts'];
            if (!is_array($files['name']) || count($files['name']) > 3) throw new InvalidArgumentException('Kies maximaal drie bonfoto’s.');
            foreach ($files['name'] as $i => $name) {
                if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                if ($files['error'][$i] !== UPLOAD_ERR_OK || $files['size'][$i] > 2 * 1024 * 1024) throw new InvalidArgumentException('Elke foto mag maximaal 2 MB zijn en moet volledig worden geüpload.');
                if (!is_uploaded_file($files['tmp_name'][$i])) throw new InvalidArgumentException('Ongeldige upload.');
                $info = @getimagesize($files['tmp_name'][$i]);
                if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) throw new InvalidArgumentException('Gebruik JPG-, PNG- of WebP-foto’s.');
                $uploads[] = ['id' => bin2hex(random_bytes(12)), 'name' => substr(basename((string)$name), 0, 150), 'type' => $info['mime'], 'data' => base64_encode(file_get_contents($files['tmp_name'][$i]))];
            }
        }
        $result = $store(function (&$state) use ($user, $uploads) {
            if (!in_array($_POST['action'] ?? '', ['preferences','save_account'], true) && ($state['meta']['source'] ?? '') === 'snapshot' && empty($state['meta']['importedAt'])) throw new DomainException('Laad eerst een testkopie van de echte ritten.');
            $message = beta_apply($state, $_POST, $user, $uploads);
            return ['message' => $message] + beta_public_state($state, $user);
        });
        echo json_encode($result, JSON_THROW_ON_ERROR);
    } catch (InvalidArgumentException $e) {
        http_response_code(422);
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
    } catch (DomainException $e) {
        http_response_code(409);
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log('Afstort beta: ' . $e->getMessage());
        http_response_code(503);
        header('Content-Type: application/json');
        echo json_encode(['error' => ($dataset ?? '') === 'snapshot' ? 'De echte gegevens konden niet veilig worden uitgelezen. Controleer de databaseverbinding, pdo_mysql en de schrijfbaarheid van beta/data. De bestaande testkopie is niet vervangen.' : 'Testgegevens zijn tijdelijk niet beschikbaar. Controleer of beta/data schrijfbaar is voor PHP.']);
    }
    exit;
}
function beta_escape($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow"><meta name="csrf-token" content="<?= beta_escape(afstort_csrf_token()) ?>">
  <title>Afstort · Testomgeving</title>
  <link rel="stylesheet" href="beta/beta.css?v=<?= filemtime(__DIR__ . '/beta/beta.css') ?>">
  <script src="beta/beta.js?v=<?= filemtime(__DIR__ . '/beta/beta.js') ?>" defer></script>
</head>
<body data-user-id="<?= beta_escape($user['id']) ?>" data-user-name="<?= beta_escape($user['name']) ?>" data-office="<?= $user['office'] ? '1' : '0' ?>" data-admin="<?= $user['admin'] ? '1' : '0' ?>" data-report-all="<?= $user['reportAll'] ? '1' : '0' ?>">
  <div class="test-banner"><strong>BÈTA · TESTOMGEVING</strong><span>Wijzigingen blijven in de bèta. Mails worden alleen gesimuleerd.</span><a href="index2.php">Naar het huidige portaal ↗</a></div>
  <header class="site-header"><a class="brand" href="beta.php"><img src="logohome.png" alt="Nierstichting" width="87" height="87"> afstort<span class="beta-tag">bèta</span></a><div class="header-tools"><div class="collection-controls"><label class="view-label">Collectejaar<select id="collection-year"<?= $user['admin'] ? '' : ' disabled' ?>><option value="2026">2026</option></select></label><button id="report" type="button">Rapport</button><?php if ($user['admin']): ?><button id="preferences" type="button">Beheer</button><?php endif; ?></div><div class="account"><span><?= beta_escape($user['name']) ?></span><span class="avatar" aria-hidden="true"><?= beta_escape(strtoupper(substr($user['name'], 0, 1))) ?></span></div></div></header>
  <main>
    <div class="page-heading"><div><p class="eyebrow">SAMEN GOED GEREGELD</p><h1 id="page-title">Mijn ritten</h1><p class="intro">Eén duidelijke volgende stap, voor iedere rit.</p></div><div class="heading-actions">
    <?php if ($user['office']): ?><label class="view-label">Testweergave<select id="view"><option value="office">Kantoor</option><option value="driver">Chauffeur (ikzelf)</option></select></label><button class="primary" id="create">＋ Nieuwe testrit</button><?php endif; ?>
    </div></div>
    <?php if ($user['office']): ?><section class="source-panel" aria-label="Testgegevens"><label class="view-label">Gegevens<select id="dataset"><option value="practice">Fictieve oefenritten</option><option value="snapshot">Testkopie van echte ritten</option></select></label><p id="source-status">Gedeelde oefenritten met voorbeeldmails.</p><button class="secondary" id="import" hidden>Testkopie van echte ritten laden</button></section><?php endif; ?>
    <div id="notice" role="status" aria-live="polite" hidden></div>
    <nav class="filters" aria-label="Ritten filteren" id="filters"></nav>
    <div class="list-toolbar"><h2 id="list-title">Beschikbare ritten</h2><div><button class="text-button" id="mailbox">Testmailbox <span id="mail-count">0</span></button><button class="text-button" id="refresh">↻ Vernieuwen</button></div></div>
    <p class="muted" id="list-hint"></p>
    <section id="trips" class="trip-grid" aria-label="Ritten"><p>Testgegevens laden…</p></section>
    <footer id="footer-note">Dit is een aparte oefenomgeving. Gebruik fictieve gegevens en testbonnen.<br>De oefenritten worden gedeeld met andere ingelogde testers en blijven na uitloggen bewaard.</footer>
  </main>
  <dialog id="editor" aria-labelledby="dialog-title"><div class="dialog-top"><div><p class="eyebrow" id="dialog-step"></p><h2 id="dialog-title"></h2></div><button class="icon-button" id="close-dialog" aria-label="Venster sluiten">×</button></div><div id="dialog-content"></div></dialog>
  <noscript><p>Schakel JavaScript in om de bèta te gebruiken.</p></noscript>
</body>
</html>

<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
require_once dirname(__DIR__) . '/session.php';
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/domain.php';
require_once __DIR__ . '/templates.php';

header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' blob:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'");
$api = isset($_GET['action']);
if (empty($_SESSION['username']) || empty($_SESSION['twofa_verified'])) {
    if ($api) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['error'=>'Je sessie is verlopen. Log opnieuw in via het huidige portaal.']); }
    else header('Location: login.php');
    exit;
}
refreshCurrentUserAccess($pdo);
if (empty($_SESSION['user_id'])) {
    if ($api) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['error'=>'Log opnieuw in via het huidige portaal.']); }
    else header('Location: login.php');
    exit;
}
// The session also grants dashboard access to employees; read the actual full-access flag separately.
$fullAccessQuery = $pdo->prepare('SELECT fullAccess FROM chauffeurs WHERE id = ?');
$fullAccessQuery->execute([(int)$_SESSION['user_id']]);
$hasFullAccess = normalizeFullAccess($fullAccessQuery->fetchColumn());
$_SESSION['medewerker_csrf'] = $_SESSION['medewerker_csrf'] ?? bin2hex(random_bytes(32));
$user = ['id'=>(string)$_SESSION['user_id'], 'name'=>(string)$_SESSION['username'], 'fullAccess'=>$hasFullAccess,
    'email'=>(string)($_SESSION['user_email'] ?? ''), 'office'=>hasDashboardAccess($_SESSION),
    'admin'=>$hasFullAccess, 'reportAll'=>normalizeFullAccess($_SESSION['fullAccess'] ?? false),
    'iban'=>(string)($_SESSION['IBAN'] ?? '')];
if ($api) {
    try {
        live2_schema($pdo);
        $action = (string)$_GET['action'];
        if ($action === 'driverProfile') {
            header('Content-Type: application/json; charset=UTF-8');
            if (!hasAdminPermissions($_SESSION)) throw new DomainException('Alleen Admin mag chauffeurgegevens en beschikbaarheid beheren.');
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new InvalidArgumentException('Ongeldig verzoek.');
            afstort_require_csrf();
            $input = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($input) || (!array_key_exists('years', $input) && (!isset($input['active']) || !is_bool($input['active'])))) throw new InvalidArgumentException('Ongeldige chauffeurgegevens.');
            $mobiel = chauffeur_mobiel($input['mobiel'] ?? '');
            $preferences = portal_settings($pdo);
            $year = (int)$preferences['currentYear'];
            $selectedYears = array_key_exists('years', $input) ? chauffeur_jaren($input['years']) : null;
            $managedYears = array_map('intval', array_keys($preferences['years']));
            if ($selectedYears !== null && array_diff($selectedYears, $managedYears)) throw new InvalidArgumentException('Maak het collectejaar eerst aan bij Collectejaren.');
            if ($selectedYears === null && (int)($input['year'] ?? 0) !== $year) throw new DomainException('Het actieve collectejaar is gewijzigd. Herlaad Beheer.');
            $pdo->beginTransaction();
            try {
                $q = $pdo->prepare('SELECT beschikbare_jaren FROM chauffeurs WHERE id = ? AND (is_medewerker = 0 OR naam = ?) AND naam <> ? FOR UPDATE');
                $q->execute([(int)($input['id'] ?? 0), SELECTABLE_MEDEWERKER_CHAUFFEUR, 'Admin']);
                $row = $q->fetch();
                if (!$row) throw new DomainException('Chauffeur niet gevonden.');
                $years = array_values(array_filter(json_decode($row['beschikbare_jaren'] ?? '[]', true) ?: [], fn($y) => $selectedYears !== null ? !in_array((int)$y, $managedYears, true) : (int)$y !== $year));
                if ($selectedYears !== null) $years = array_merge($years, $selectedYears);
                elseif (!empty($input['active'])) $years[] = $year;
                $pdo->prepare('UPDATE chauffeurs SET mobiel = ?, beschikbare_jaren = ? WHERE id = ?')->execute([$mobiel, json_encode(chauffeur_jaren($years)), (int)$input['id']]);
                $pdo->commit();
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
            echo json_encode(['message'=>'Chauffeurgegevens opgeslagen.'], JSON_THROW_ON_ERROR); exit;
        }
        if ($action === 'templates') {
            header('Content-Type: application/json; charset=UTF-8');
            if (!$hasFullAccess) throw new DomainException('Alleen Administrators mogen e-mailteksten beheren.');
            if ($_SERVER['REQUEST_METHOD']==='GET') { echo json_encode(['templates'=>live2_templates($pdo)],JSON_THROW_ON_ERROR); exit; }
            if ($_SERVER['REQUEST_METHOD']!=='POST') throw new InvalidArgumentException('Ongeldig verzoek.');
            afstort_require_csrf();
            $input=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);
            if (!is_array($input)) throw new InvalidArgumentException('Ongeldig verzoek.');
            echo json_encode(['template'=>live2_template_save($pdo,$input)],JSON_THROW_ON_ERROR); exit;
        }
        if ($action === 'management' || $action === 'settings') {
            header('Content-Type: application/json; charset=UTF-8');
            if (!$hasFullAccess) throw new DomainException('Alleen Administrators mogen deze instellingen beheren.');
            if ($action === 'settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                afstort_require_csrf();
                $input=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);
                echo json_encode(['preferences'=>portal_settings_save($pdo,$input)],JSON_THROW_ON_ERROR); exit;
            }
            if ($action === 'management' && $_SERVER['REQUEST_METHOD'] === 'GET') {
                $rows=$pdo->query('SELECT id, naam, email, fullAccess FROM chauffeurs ORDER BY naam')->fetchAll();
                $admins=array_values(array_filter($rows,function ($r) { return normalizeFullAccess($r['fullAccess']); }));
                foreach ($admins as &$person) unset($person['fullAccess']); unset($person);
                echo json_encode(['administrators'=>$admins,'preferences'=>portal_settings($pdo)],JSON_THROW_ON_ERROR); exit;
            }
            throw new InvalidArgumentException('Ongeldig verzoek.');
        }
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'state') {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(live2_state($pdo, $user, $documentLinkKey), JSON_THROW_ON_ERROR); exit;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'receipt') {
            $id = (int)($_GET['id'] ?? 0); $row = live2_read_row($pdo, $id);
            $drivers = $pdo->query('SELECT id, naam, email, fullAccess, is_medewerker, IBAN FROM chauffeurs')->fetchAll();
            $trip = live2_trip($row, $drivers, live2_extra($pdo, $id));
            if (!beta_can_edit($trip, $user)) throw new DomainException('Geen toegang tot deze bon.');
            foreach ($trip['receipts'] as $receipt) {
                if ($receipt['id'] !== ($_GET['receipt'] ?? '')) continue;
                header('Content-Type: ' . $receipt['type']);
                header('Content-Disposition: inline; filename="bon.' . ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$receipt['type']] . '"');
                echo base64_decode($receipt['data'], true); exit;
            }
            throw new DomainException('Deze bon bestaat niet meer.');
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($action, ['mutate','preview'], true)) {
            http_response_code(405); header('Content-Type: application/json'); echo json_encode(['error'=>'Ongeldig verzoek.']); exit;
        }
        afstort_require_csrf();
        header('Content-Type: application/json; charset=UTF-8');
        if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) throw new InvalidArgumentException('De upload is te groot voor deze server. Kies kleinere foto’s.');
        if ($action === 'preview') {
            $row = live2_read_row($pdo, (int)($_POST['id'] ?? 0));
            $drivers = $pdo->query('SELECT id, naam, email, fullAccess, is_medewerker, IBAN FROM chauffeurs')->fetchAll();
            $trip = live2_trip($row, $drivers, live2_extra($pdo, (int)$row['id']));
            if (!beta_can_edit($trip, $user)) throw new DomainException('Geen toegang tot deze rit.');
            $kind = $trip['status'] === 'planning' || ($user['office'] && $trip['status'] === 'planned' && ($_POST['previewKind'] ?? '') === 'schedule') ? 'Afspraak' : 'Afronding';
            if ($user['office'] && $kind === 'Afspraak' && isset($_POST['verwachtBedrag'])) $trip['verwachtBedrag'] = beta_number($_POST, 'verwachtBedrag', 1000000);
            foreach (['afhaalmoment','afhaaltijd','gestort','gereden','opmerking'] as $field) if (isset($_POST[$field])) $trip[$field] = beta_text($_POST, $field, 2000, false);
            $mails = live2_mails($pdo, $trip, $kind, $documentLinkKey);
            foreach ($mails as &$mail) { $mail['body'] = beta_template_text($mail['html']); unset($mail['html'], $mail['receipts']); }
            unset($mail);
            echo json_encode(['mails'=>$mails], JSON_THROW_ON_ERROR); exit;
        }
        $uploads = [];
        if (!empty($_FILES['receipts'])) {
            $files = $_FILES['receipts'];
            if (!is_array($files['name']) || count($files['name']) > 3) throw new InvalidArgumentException('Kies maximaal drie bonfoto’s.');
            foreach ($files['name'] as $i=>$name) {
                if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                if ($files['error'][$i] !== UPLOAD_ERR_OK || $files['size'][$i] > 2 * 1024 * 1024) throw new InvalidArgumentException('Elke foto mag maximaal 2 MB zijn.');
                if (!is_uploaded_file($files['tmp_name'][$i])) throw new InvalidArgumentException('Ongeldige upload.');
                $info = @getimagesize($files['tmp_name'][$i]);
                if (!$info || !in_array($info['mime'], ['image/jpeg','image/png','image/webp'], true)) throw new InvalidArgumentException('Gebruik JPG-, PNG- of WebP-foto’s.');
                $uploads[] = ['id'=>bin2hex(random_bytes(12)), 'name'=>substr(basename((string)$name), 0, 150),
                    'type'=>$info['mime'], 'data'=>base64_encode(file_get_contents($files['tmp_name'][$i]))];
            }
        }
        $message = live2_mutate($pdo, $_POST, $user, $uploads, $documentLinkKey);
        echo json_encode(['message'=>$message] + live2_state($pdo, $user, $documentLinkKey), JSON_THROW_ON_ERROR);
    } catch (InvalidArgumentException $error) {
        http_response_code(422); echo json_encode(['error'=>$error->getMessage()]);
    } catch (DomainException $error) {
        http_response_code(409); echo json_encode(['error'=>$error->getMessage()]);
    } catch (RitConflict $error) {
        http_response_code(409); echo json_encode(['error'=>$error->getMessage()]);
    } catch (Throwable $error) {
        error_log('Afstort index2: ' . $error->getMessage());
        http_response_code(503); echo json_encode(['error'=>'Het live portaal is tijdelijk niet beschikbaar. Vernieuw eerst het overzicht voordat je opnieuw opslaat. Laat de beheerder zo nodig het serverlog controleren.']);
    }
    exit;
}
function live2_escape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

<?php
// declineRit.php - gebruikt echte km-afstand om volgende chauffeur te kiezen

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once "session.php";
require_once "config.php";
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

function declinePage(string $title, string $content, string $step = 'CHAUFFEUR'): void {
    $heading = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $label = htmlspecialchars($step, ENT_QUOTES, 'UTF-8');
    $styleVersion = (int)filemtime(__DIR__ . '/live2/base.css');
    echo '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . noIndexMetaTag() . '<title>' . $heading . ' · Afstort</title><link rel="stylesheet" href="live2/base.css?v=' . $styleVersion . '">';
    echo '<style>
      .decline-header{padding:22px 24px;justify-content:center}
      .decline-main{max-width:640px;padding:48px 20px;margin:0 auto}
      .decline-card{background:#fff;border:1px solid var(--border);border-radius:16px;padding:32px;box-shadow:0 6px 24px #17314e08}
      .decline-card h1{font-size:30px;line-height:1.2;letter-spacing:-.7px;margin-bottom:16px}
      .decline-card p{color:var(--muted);line-height:1.7}
      .decline-card .summary p{margin:0}
      .decline-card .form-actions{justify-content:flex-start}
      .decline-card .button-link{display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--border);border-radius:9px;padding:11px 16px;text-decoration:none;font-weight:650;color:#203a55;background:#fff}
      .decline-footer{text-align:center;margin-top:24px;font-size:12px;color:var(--muted)}
      @media(max-width:480px){.decline-header{padding:18px}.decline-header .brand img{width:58px;height:58px}.decline-main{padding:28px 16px}.decline-card{padding:24px}.decline-card h1{font-size:26px}.decline-card .form-actions{flex-direction:column}.decline-card .form-actions>*{width:100%;text-align:center}}
    </style></head><body><header class="site-header decline-header"><a class="brand" href="index2.php"><img src="logohome.png" alt="Nierstichting" width="87" height="87">afstort</a></header>';
    echo '<main class="decline-main"><section class="decline-card"><p class="eyebrow">' . $label . '</p><h1>' . $heading . '</h1>' . $content
        . '</section><p class="decline-footer">Nierstichting · Afstortportaal</p></main></body></html>';
    exit;
}

require_once __DIR__ . '/chauffeur_selectie.php';
ensureRitAanbiedingenTable($pdo);
ensureRitEmailLogTable($pdo);

$ritId         = isset($_GET['rit']) ? (int)$_GET['rit'] : 0;
$chauffeurNaam = isset($_GET['chauffeur']) ? trim($_GET['chauffeur']) : '';
$retryNext = ($_GET['retry'] ?? '') === '1';
$expires = (int)($_GET['expires'] ?? 0);
$token = (string)($_GET['token'] ?? '');
$signedLink = !$retryNext && afstort_valid_decline_token($ritId, $chauffeurNaam, $expires, $token, $documentLinkKey);
if (!$signedLink) {
    if (empty($_SESSION['username']) || empty($_SESSION['twofa_verified'])) {
        http_response_code(401);
        declinePage('Dit aanbod kan niet worden verwerkt', '<p>' . 'Deze oudere of verlopen afwijslink vereist dat je eerst inlogt als de aangeschreven chauffeur. Log in via het afstortportaal en open daarna de link uit de mail opnieuw.' . '</p><div class="form-actions"><a class="button-link" href="index2.php">Naar het rittenoverzicht</a></div>');
    }
}

if (!$ritId || (!$retryNext && $chauffeurNaam === '')) {
    declinePage('Onjuiste link', '<p>Deze link bevat geen geldige rit of chauffeur.</p>');
}
if ($retryNext && !hasDashboardAccess($_SESSION)) {
    http_response_code(403);
    declinePage('Dit aanbod kan niet worden verwerkt', '<p>' . 'Alleen medewerkers en admins mogen opnieuw een chauffeur aanbieden.' . '</p><div class="form-actions"><a class="button-link" href="index2.php">Naar het rittenoverzicht</a></div>');
}
$tripCheck = $pdo->prepare('SELECT chauffeur, status FROM ritten WHERE id = ?');
$tripCheck->execute([$ritId]);
$currentTrip = $tripCheck->fetch(PDO::FETCH_ASSOC);
if (!$currentTrip || !isUnassignedChauffeurValue($currentTrip['chauffeur']) || $currentTrip['status'] === 'Afgehandeld') {
    http_response_code(409);
    declinePage('Dit aanbod kan niet worden verwerkt', '<p>' . 'Deze rit is niet meer beschikbaar om aan een chauffeur aan te bieden.' . '</p><div class="form-actions"><a class="button-link" href="index2.php">Naar het rittenoverzicht</a></div>');
}
$offerCheck = $pdo->prepare("SELECT 1 FROM rit_aanbiedingen WHERE rit_id = ? AND status = 'aangeboden' AND LOWER(TRIM(chauffeur_naam)) = LOWER(TRIM(?)) LIMIT 1");
$offerCheck->execute([$ritId, $chauffeurNaam]);
if (!$retryNext && ((!$signedLink && strcasecmp($chauffeurNaam, (string)$_SESSION['username']) !== 0) || !$offerCheck->fetchColumn())) {
    http_response_code(403);
    if (!$signedLink && strcasecmp($chauffeurNaam, (string)$_SESSION['username']) !== 0) {
        declinePage('Dit aanbod kan niet worden verwerkt', '<p>' . 'Deze mail is bestemd voor ' . htmlspecialchars($chauffeurNaam, ENT_QUOTES, 'UTF-8') . '. Je bent ingelogd met een ander account. Log eerst in als de aangeschreven chauffeur en open deze oudere link opnieuw.' . '</p><div class="form-actions"><a class="button-link" href="index2.php">Naar het rittenoverzicht</a></div>');
    }
    declinePage('Dit aanbod kan niet worden verwerkt', '<p>' . 'Dit aanbod staat niet meer open. Het is al verwerkt of de rit is inmiddels aan een andere chauffeur aangeboden.' . '</p><div class="form-actions"><a class="button-link" href="index2.php">Naar het rittenoverzicht</a></div>');
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !$retryNext) {
    $confirmationUrl = 'declineRit.php?rit=' . $ritId . '&chauffeur=' . rawurlencode($chauffeurNaam);
    if ($signedLink) $confirmationUrl .= '&expires=' . $expires . '&token=' . $token;
    $content = '<p>Kun je deze rit niet uitvoeren? Bevestig je afmelding hieronder. Daarna proberen we de rit aan een volgende chauffeur aan te bieden.</p>'
        . '<div class="summary"><h3>Rit #' . $ritId . '</h3><p>Aangeboden aan ' . htmlspecialchars($chauffeurNaam, ENT_QUOTES, 'UTF-8') . '</p></div>'
        . '<form method="post" action="' . htmlspecialchars($confirmationUrl, ENT_QUOTES, 'UTF-8') . '">'
        . '<input type="hidden" name="csrf" value="' . htmlspecialchars(afstort_csrf_token(), ENT_QUOTES, 'UTF-8') . '">'
        . '<div class="form-actions"><button type="submit" class="primary">Ja, wijs de rit af</button><a class="button-link" href="index2.php">Terug naar overzicht</a></div></form>';
    declinePage('Rit afwijzen', $content, 'AFMELDEN VOOR EEN RIT');
}
afstort_require_csrf();

$pdo->beginTransaction();
$lockedTrip = $pdo->prepare('SELECT chauffeur, status FROM ritten WHERE id = ? FOR UPDATE');
$lockedTrip->execute([$ritId]);
$currentTrip = $lockedTrip->fetch(PDO::FETCH_ASSOC);
if (!$currentTrip || !isUnassignedChauffeurValue($currentTrip['chauffeur']) || $currentTrip['status'] === 'Afgehandeld') {
    $pdo->rollBack();
    http_response_code(409);
    declinePage('Dit aanbod kan niet worden verwerkt', '<p>' . 'Deze rit is ondertussen toegewezen of afgerond. Er wordt geen extra voorstel verstuurd.' . '</p><div class="form-actions"><a class="button-link" href="index2.php">Naar het rittenoverzicht</a></div>');
}
if ($retryNext) {
    if (getOpenstaandeRitAanbieding($pdo, $ritId)) {
        $pdo->rollBack();
        http_response_code(409);
        declinePage('Dit aanbod kan niet worden verwerkt', '<p>' . 'Deze rit heeft al een openstaand chauffeursvoorstel. Er wordt geen extra mail verstuurd.' . '</p><div class="form-actions"><a class="button-link" href="index2.php">Naar het rittenoverzicht</a></div>');
    }
} else {
    $offerCheck->execute([$ritId, $chauffeurNaam]);
    if (!$offerCheck->fetchColumn()) {
        $pdo->rollBack();
        http_response_code(409);
        declinePage('Dit aanbod kan niet worden verwerkt', '<p>' . 'Deze aanbieding is ondertussen verwerkt. Er wordt geen extra voorstel verstuurd.' . '</p><div class="form-actions"><a class="button-link" href="index2.php">Naar het rittenoverzicht</a></div>');
    }
    markeerRitAanbiedingAfgewezen($pdo, $ritId, $chauffeurNaam);
}

// Use the same selection rules as the live portal, including year availability.
$selection = selectNearestChauffeur($pdo, $ritId, $chauffeurNaam);
if (($selection['status'] ?? '') !== 'ok') {
    $reason = $selection['message'] ?? 'Er kon geen volgende chauffeur worden gekozen.';
    logRitEmail($pdo, $ritId, 'Chauffeurvoorstel na afwijzing', '-', 'Volgende chauffeur kiezen', 'mislukt', $reason);
    $pdo->commit();
    $content = ($retryNext ? '' : '<p>Je afmelding voor rit #' . $ritId . ' is geregistreerd.</p>')
        . '<p>Er is geen volgende chauffeur aangeschreven: ' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . '</p><p>Een medewerker kan de rit controleren en een chauffeur kiezen.</p>'
        . '<div class="form-actions"><a class="button-link" href="index2.php">Terug naar overzicht</a></div>';
    declinePage('Geen volgend voorstel verstuurd', $content, 'STATUS VAN DE RIT');
}

// ---- 4. Nieuwe chauffeur mailen ----
$newName        = $selection['chauffeurNaam'];
$newEmail       = $selection['chauffeurEmail'];
$collectegebied = $selection['collectegebied'];
$postcodePlaats = $selection['postcodePlaats'];

// Link voor eventueel opnieuw afmelden van de nieuwe chauffeur
$declineLink = afstort_decline_url($ritId, $newName, $documentLinkKey);

$body = "Beste " . htmlspecialchars($newName) . ",<br><br>"
      . "Een collega-chauffeur heeft aangegeven deze rit niet te kunnen uitvoeren. "
      . "Jij bent nu geselecteerd als <b>dichtstbijzijnde chauffeur</b> voor deze afhaalopdracht."
      . "<br><br>Collectegebied: <b>" . htmlspecialchars($collectegebied) . "</b>"
      . "<br>Postcode/plaats: <b>" . htmlspecialchars($postcodePlaats) . "</b>"
      . "<br><br>Log in op het portal om de rit op jouw naam te zetten. Kun je deze rit niet uitvoeren? Klik dan op deze link: "
      . "<a href='" . htmlspecialchars($declineLink, ENT_QUOTES) . "'>Ik kan deze rit niet uitvoeren</a>."
      . "<br><br>Met vriendelijke groet,<br>Nierstichting collectieteam";

$subject = 'Afhaalopdracht collecte-opbrengst (nieuwe chauffeur)';
assertNotMedewerkerRecipient($pdo, $newName, $newEmail);
$message = afstort_chauffeur_mail_message($body);
$mailError = null;
try {
    $mailSent = mail($newEmail, $subject, $message['body'], $message['headers'], $message['envelope']);
    if (!$mailSent) $mailError = 'De mailserver heeft het voorstel aan de volgende chauffeur niet geaccepteerd.';
} catch (Throwable $error) {
    $mailSent = false;
    $mailError = 'Het voorstel aan de volgende chauffeur kon niet worden verstuurd.';
    error_log('Afstort volgende chauffeur: ' . $error->getMessage());
}
logRitEmail($pdo, $ritId, 'Chauffeurvoorstel na afwijzing', $newEmail, $subject, $mailSent ? 'verzonden' : 'mislukt', $mailError);
if ($mailSent) registreerRitAanbieding($pdo, $ritId, $newName, $newEmail, $selection['afstandKm']);
$pdo->commit();
$content = $retryNext ? '' : '<p>Dankjewel dat je het doorgeeft. Je afmelding voor rit #' . $ritId . ' is geregistreerd.</p>';
$content .= $mailSent
    ? '<div class="summary"><h3>Volgende chauffeur aangeschreven</h3><p>De rit is nu aangeboden aan <strong>' . htmlspecialchars($newName, ENT_QUOTES, 'UTF-8') . '</strong>.</p></div>'
    : '<p>Het voorstel aan de volgende chauffeur kon niet worden verstuurd. Laat een medewerker de rit controleren via het Emailoverzicht.</p>';
$content .= '<div class="form-actions"><a class="button-link" href="index2.php">Terug naar overzicht</a></div>';
declinePage($retryNext ? 'Volgende chauffeur aanbieden' : 'Je afmelding is opgeslagen', $content, 'STATUS VAN DE RIT');

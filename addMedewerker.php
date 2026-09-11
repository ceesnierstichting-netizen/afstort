<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/config.php';

// Houd PHP-waarschuwingen uit de JSON-respons; details horen in het serverlog.
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=UTF-8');
refreshCurrentUserAccess($pdo);
if (empty($_SESSION['twofa_verified']) || !hasDashboardAccess($_SESSION)) {
    http_response_code(403);
    echo json_encode(['message' => 'Geen toegang.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
$data = json_decode(file_get_contents('php://input'), true);
if (!hash_equals($_SESSION['medewerker_csrf'] ?? '', (string)($data['csrf'] ?? '')) || empty($_SESSION['medewerker_csrf'])) {
    http_response_code(403);
    echo json_encode(['message' => 'Ververs de pagina en probeer opnieuw.']);
    exit;
}
$naam = trim((string)($data['naam'] ?? ''));
$email = trim((string)($data['email'] ?? ''));
if ($naam === '' || strlen($naam) > 255 || strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['message' => 'Vul een naam en een geldig e-mailadres in.']);
    exit;
}
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT id FROM chauffeurs WHERE naam = ? OR email = ? LIMIT 1');
    $stmt->execute([$naam, $email]);
    if ($stmt->fetchColumn()) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['message' => 'Er bestaat al een gebruiker met deze naam of dit e-mailadres.']);
        exit;
    }
    // Een onbekend willekeurig wachtwoord: toegang ontstaat pas via de uitnodiging.
    $stmt = $pdo->prepare("INSERT INTO chauffeurs (naam, email, wachtwoord, IBAN, postcode, fullAccess, is_medewerker) VALUES (?, ?, ?, NULL, NULL, 0, 1)");
    $stmt->execute([$naam, $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('DELETE FROM wachtwoord_resets WHERE email = ?')->execute([$email]);
    $pdo->prepare('INSERT INTO wachtwoord_resets (email, token, expires_at) VALUES (?, ?, ?)')
        ->execute([$email, $token, date('Y-m-d H:i:s', time() + 86400)]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Medewerker aanmaken mislukt: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['message' => 'Medewerker aanmaken is mislukt. Controleer of de databasemigratie is uitgevoerd.']);
    exit;
}
$link = 'https://nierstichtingnederland.nl/afstort/wachtwoord_reset.php?token=' . $token;
$body = "Beste $naam,\n\nEr is een medewerkersaccount voor je aangemaakt in het afstortportaal. Wil je een wachtwoord aanmaken via deze link?\n\n$link\n\nDeze link is 24 uur geldig. Daarna kun je via 'Wachtwoord vergeten' een nieuwe link aanvragen.\n\nLog daarna in met je e-mailadres en wachtwoord. Bij je eerste aanmelding moet je tweestapsverificatie (2FA) instellen. Je kunt hiervoor een authenticator-app gebruiken of dit eventueel via e-mail afhandelen met een verificatiecode.\n\nJe kunt nieuwe ritten aanmaken en hebt toegang tot het totaaloverzicht van alle ritten.\nZorg ervoor dat je de naam en het nummer van het collectegebied juist invoert.\n\nVoer alleen de wijknaam in als je voor een losse wijk wilt laten afstorten. Anders laat je dit veld leeg.\n\nSucces!\n\nMet vriendelijke groet,\nNierstichting";
$mailReference = bin2hex(random_bytes(8));
$headers = "From: Nierstichting collecteteam <noreply@nierstichtingnederland.nl>\r\n"
    . "Reply-To: noreply@nierstichtingnederland.nl\r\n"
    . "MIME-Version: 1.0\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\n"
    . "Content-Transfer-Encoding: quoted-printable\r\n"
    . "Message-ID: <medewerker-" . $mailReference . "@nierstichtingnederland.nl>\r\n";
$mailBody = quoted_printable_encode(str_replace("\n", "\r\n", $body));
$sent = false;
try {
    // Dezelfde vaste envelope-afzender als bij de contactbevestiging.
    $sent = mail($email, 'Welkom: maak je wachtwoord aan voor het afstortportaal', $mailBody,
        $headers, '-fnoreply@nierstichtingnederland.nl');
} catch (Throwable $e) {
    error_log('Medewerkeruitnodiging ' . $mailReference . ': mailfunctie mislukt (' . get_class($e) . ').');
}
// Geen wachtwoord, resetlink of token in het log opnemen.
error_log('Medewerkeruitnodiging ' . $mailReference . ': ' . ($sent ? 'aangenomen voor verzending' : 'verzending mislukt'));
echo json_encode(['created' => true, 'mailSent' => $sent, 'message' => $sent
    ? 'Medewerker toegevoegd. De uitnodiging is aangeboden aan de mailserver. Controleer ook ongewenste e-mail. Referentie: ' . $mailReference
    : 'Medewerker toegevoegd, maar de uitnodigingsmail kon niet worden verzonden. Laat de medewerker via Wachtwoord vergeten een nieuwe link aanvragen. Referentie: ' . $mailReference]);

<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/config.php';
afstort_require_login();
if (!hasDashboardAccess($_SESSION)) { http_response_code(403); exit('Geen toegang.'); }
afstort_require_csrf();

ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

// In dit script gebruiken we 'to' als de ontvanger
$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['status' => 'error', 'message' => 'Geen gegevens ontvangen']);
    exit;
}

$email = $data['to'] ?? '';
$body  = $data['body'] ?? '';
// Stel een standaard "From:"-adres in
$from  = "noreply@nierstichtingnederland.nl";

if(empty($email) || empty($body)){
    echo json_encode(['status' => 'error', 'message' => 'Ontbrekende vereiste velden']);
    exit;
}

$ritId = (int)($data['ritId'] ?? 0);
if (!afstort_rit_exists($pdo, $ritId)) {
    echo json_encode(['status' => 'error', 'message' => 'Rit niet gevonden']);
    exit;
}
$body = afstort_prepare_document_email(str_replace('[wijknaam]', $data['wijknaam'] ?? '', $body), $ritId, $documentLinkKey);

$headers = "From: " . $from . "\r\n" .
           "Reply-To: " . $from . "\r\n" .
           "Content-Type: text/html; charset=UTF-8\r\n";

$subject = "Bevestiging afhaalopdracht";
$mailSent = mail($email, $subject, $body, $headers, "-f" . $from);
logRitEmail($pdo, $data['ritId'] ?? 0, 'Ritbevestiging contactpersoon', $email, $subject, $mailSent ? 'verzonden' : 'mislukt');

if($mailSent){
    echo json_encode(['status' => 'success']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'E-mail verzenden mislukt']);
}
?>

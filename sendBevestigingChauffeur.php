<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/config.php';
afstort_require_login();
if (!hasDashboardAccess($_SESSION)) { http_response_code(403); exit('Geen toegang.'); }
afstort_require_csrf();
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once('config.php'); // Pas aan indien nodig

// Ontvang JSON-data via POST
$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    echo json_encode(["status" => "error", "message" => "Geen data ontvangen."]);
    exit;
}

$to = trim($data['to'] ?? '');
$body = $data['body'] ?? '';

if(empty($to) || empty($body)) {
    echo json_encode(["status" => "error", "message" => "Ontbrekende parameters."]);
    exit;
}

$subject = "Bevestiging rit";
$headers = "MIME-Version: 1.0" . "\r\n";
$headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
$headers .= "From: noreply@nierstichtingnederland.nl" . "\r\n";

$ritId = (int)($data['ritId'] ?? 0);
if (!afstort_rit_exists($pdo, $ritId)) {
    echo json_encode(['status' => 'error', 'message' => 'Rit niet gevonden']);
    exit;
}
$body = afstort_prepare_document_email(str_replace('[wijknaam]', $data['wijknaam'] ?? '', $body), $ritId, $documentLinkKey);

$mailSent = mail($to, $subject, $body, $headers);
logRitEmail($pdo, $data['ritId'] ?? 0, 'Ritbevestiging chauffeur', $to, $subject, $mailSent ? 'verzonden' : 'mislukt');

if($mailSent){
    echo json_encode(["status" => "success"]);
} else {
    echo json_encode(["status" => "error", "message" => "Mail kon niet worden verstuurd."]);
}
?>

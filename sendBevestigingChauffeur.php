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

// Voeg de extra placeholder [wijknaam] toe (indien meegegeven)
$placeholders = ['[busbriefje]', '[afhaalbevestiging]', '[wijknaam]'];
$replacements = [
    $data['busbriefje_url'] ?? '',
    $data['afhaalbevestiging_url'] ?? '',
    $data['wijknaam'] ?? ''
];
$body = str_replace($placeholders, $replacements, $body);

$mailSent = mail($to, $subject, $body, $headers);
logRitEmail($pdo, $data['ritId'] ?? 0, 'Ritbevestiging chauffeur', $to, $subject, $mailSent ? 'verzonden' : 'mislukt');

if($mailSent){
    echo json_encode(["status" => "success"]);
} else {
    echo json_encode(["status" => "error", "message" => "Mail kon niet worden verstuurd."]);
}
?>

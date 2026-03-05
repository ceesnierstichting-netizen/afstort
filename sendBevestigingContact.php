<?php
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

// Voeg de placeholder [wijknaam] toe zodat deze vervangen kan worden
$placeholders = ['[busbriefje]', '[afhaalbevestiging]', '[wijknaam]'];
$replacements = [
    $data['busbriefje_url'] ?? '',
    $data['afhaalbevestiging_url'] ?? '',
    $data['wijknaam'] ?? ''
];
$body = str_replace($placeholders, $replacements, $body);

$headers = "From: " . $from . "\r\n" .
           "Reply-To: " . $from . "\r\n" .
           "Content-Type: text/html; charset=UTF-8\r\n";

if(mail($email, "Bevestiging afhaalopdracht", $body, $headers, "-f" . $from)){
    echo json_encode(['status' => 'success']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'E-mail verzenden mislukt']);
}
?>

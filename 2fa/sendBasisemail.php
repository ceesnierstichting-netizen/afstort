<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Geen gegevens ontvangen']);
    exit;
}

$email   = $data['email'] ?? '';
$subject = $data['subject'] ?? '';
$body    = $data['body'] ?? '';
$from    = $data['van'] ?? '';

if(empty($email) || empty($subject) || empty($body)){
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Ontbrekende vereiste velden']);
    exit;
}

// Zorg ervoor dat de JSON-velden 'busbriefje' en 'afhaalbevestiging' de volledige URL's bevatten.
$busBriefjeUrl = $data['busbriefje'] ?? '';
$afhaalBevestigingUrl = $data['afhaalbevestiging'] ?? '';

// Bouw de HTML-links met dubbele aanhalingstekens in de attributen
$busBriefjeLink = $busBriefjeUrl !== '' ? "<a href=\"{$busBriefjeUrl}\" target=\"_blank\">busbriefje</a>" : "";
$afhaalBevestigingLink = $afhaalBevestigingUrl !== '' ? "<a href=\"{$afhaalBevestigingUrl}\" target=\"_blank\">afhaalbevestiging</a>" : "";

// Definieer de placeholders en de vervangingswaarden
$placeholders = ['[naam]', '[soort]', '[verwacht]', '[busbriefje]', '[afhaalbevestiging]'];
$replacements = [
    $data['naam'] ?? '',
    $data['soort'] ?? '',
    $data['verwacht'] ?? '',
    $busBriefjeLink,
    $afhaalBevestigingLink
];

$body = str_replace($placeholders, $replacements, $body);

$headers = "From: " . $from . "\r\n" .
           "Reply-To: " . $from . "\r\n" .
           "MIME-Version: 1.0\r\n" .
           "Content-Type: text/html; charset=UTF-8\r\n";

if(mail($email, $subject, $body, $headers)){
    $response = ['status' => 'success'];
} else {
    $response = ['status' => 'error', 'message' => 'E-mail verzenden mislukt'];
}

ob_clean();
echo json_encode($response);
ob_end_flush();
?>

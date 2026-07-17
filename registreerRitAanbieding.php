<?php

require_once('session.php');
require_once('config.php');

header('Content-Type: application/json');

if (!isset($_SESSION['fullAccess']) || empty($_SESSION['twofa_verified'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Niet ingelogd.']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
$ritId = (int)($payload['ritId'] ?? 0);
$chauffeurNaam = trim((string)($payload['chauffeurNaam'] ?? ''));
$chauffeurEmail = trim((string)($payload['chauffeurEmail'] ?? ''));
$afstandKm = isset($payload['afstandKm']) && $payload['afstandKm'] !== '' ? (float)$payload['afstandKm'] : null;

if ($ritId <= 0 || $chauffeurNaam === '') {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Onvoldoende gegevens om de aanbieding op te slaan.']);
    exit;
}

registreerRitAanbieding($pdo, $ritId, $chauffeurNaam, $chauffeurEmail, $afstandKm);

echo json_encode(['status' => 'ok']);

<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=UTF-8');
refreshCurrentUserAccess($pdo);

if (empty($_SESSION['twofa_verified']) || empty($_SESSION['fullAccess'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Geen toegang.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$ritId = (int)($data['ritId'] ?? 0);
$message = trim((string)($data['message'] ?? 'Onbekende fout bij chauffeursvoorstel.'));
if ($ritId <= 0) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Geen geldige rit opgegeven.']);
    exit;
}

logRitEmail($pdo, $ritId, 'Chauffeurvoorstel', '-', 'Automatische chauffeurselectie', 'mislukt', $message);
echo json_encode(['status' => 'ok']);

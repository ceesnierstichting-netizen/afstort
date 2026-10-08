<?php
// getNearestChauffeur.php - selectie op basis van echte afstand (Haversine) met lat/lon

require_once "session.php";
require_once "config.php";
afstort_require_login();
if (!hasDashboardAccess($_SESSION)) {
    http_response_code(403);
    exit('Geen toegang.');
}
afstort_require_csrf();

header('Content-Type: application/json');

require_once __DIR__ . '/chauffeur_selectie.php';
$ritId = (int)($_POST['ritId'] ?? 0);
if (!$ritId) { echo json_encode(['status'=>'error','message'=>'Geen ritId meegegeven.']); exit; }
echo json_encode(selectNearestChauffeur($pdo, $ritId, trim((string)($_POST['exclude'] ?? ''))));

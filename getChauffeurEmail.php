<?php
// getChauffeurEmail.php
require_once('session.php');
require_once('config.php');
afstort_require_login();
if (!hasDashboardAccess($_SESSION)) {
    http_response_code(403);
    exit('Geen toegang.');
}
header('Content-Type: application/json');

if (isset($_GET['naam'])) {
    $naam = $_GET['naam'];
    $stmt = $pdo->prepare("
        SELECT email
        FROM chauffeurs
        WHERE naam = :naam
          AND (is_medewerker = 0 OR (is_medewerker = 1 AND LOWER(TRIM(naam)) = LOWER(:uitzondering)))
        LIMIT 1
    ");
    $stmt->execute([':naam' => $naam, ':uitzondering' => SELECTABLE_MEDEWERKER_CHAUFFEUR]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        echo json_encode(['email' => $result['email']]);
    } else {
        echo json_encode(['email' => '']);
    }
} else {
    echo json_encode(['email' => '']);
}
?>

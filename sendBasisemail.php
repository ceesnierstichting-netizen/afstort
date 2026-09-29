<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/config.php';
afstort_require_login();
if (!hasDashboardAccess($_SESSION)) {
    http_response_code(403);
    exit('Geen toegang.');
}
afstort_require_csrf();

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
$from    = 'noreply@nierstichtingnederland.nl';

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || empty($subject) || empty($body) || preg_match('/[\r\n]/', (string)$subject)) {
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Ontbrekende vereiste velden']);
    exit;
}

$ritId = (int)($data['ritId'] ?? 0);
if (!afstort_rit_exists($pdo, $ritId)) {
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Rit niet gevonden']);
    exit;
}
$body = afstort_prepare_document_email($body, $ritId, $documentLinkKey);

$headers = "From: " . $from . "\r\n" .
           "Reply-To: " . $from . "\r\n" .
           "MIME-Version: 1.0\r\n" .
           "Content-Type: text/html; charset=UTF-8\r\n";

$mailSent = mail($email, $subject, $body, $headers);
logRitEmail(
    $pdo,
    $data['ritId'] ?? 0,
    $data['emailType'] ?? 'E-mail',
    $email,
    $subject,
    $mailSent ? 'verzonden' : 'mislukt',
    $mailSent ? null : 'De mailserver heeft het bericht niet geaccepteerd.'
);

if($mailSent){
    $response = ['status' => 'success'];
} else {
    $response = ['status' => 'error', 'message' => 'E-mail verzenden mislukt'];
}

ob_clean();
echo json_encode($response);
ob_end_flush();
?>

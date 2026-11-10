<?php
// Waarschuwingen horen in het serverlog, niet in het JSON-antwoord.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=UTF-8');

try {
    require_once __DIR__ . '/session.php';
    require_once __DIR__ . '/config.php';
    afstort_require_login();
    if (!hasDashboardAccess($_SESSION)) { http_response_code(403); exit('Geen toegang.'); }
    afstort_require_csrf();

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

    if (!is_string($to) || !filter_var(trim($to), FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Ongeldig e-mailadres van de ontvanger.']);
        exit;
    }
    $to = trim($to);

    $subject = "Bevestiging rit";

    $ritId = (int)($data['ritId'] ?? 0);
    if (!afstort_rit_exists($pdo, $ritId)) {
        echo json_encode(['status' => 'error', 'message' => 'Rit niet gevonden']);
        exit;
    }
    $body = afstort_prepare_document_email(str_replace('[wijknaam]', $data['wijknaam'] ?? '', $body), $ritId, $documentLinkKey);
    $body = afstort_rit_opmerking_email($pdo, $body, $ritId);

    $message = afstort_chauffeur_mail_message($body);
    $mailSent = mail($to, $subject, $message['body'], $message['headers'], $message['envelope']);
    logRitEmail($pdo, $data['ritId'] ?? 0, 'Ritbevestiging chauffeur', $to, $subject, $mailSent ? 'verzonden' : 'mislukt',
        $mailSent ? 'Bevestiging rit — geaccepteerd voor verzending; aflevering niet bevestigd.' : 'De mailserver heeft het bericht niet geaccepteerd.');

    if($mailSent){
        echo json_encode(["status" => "success"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Mail kon niet worden verstuurd."]);
    }
} catch (Throwable $error) {
    error_log(basename(__FILE__) . ': ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Serverfout bij het versturen van de bevestiging. Laat de beheerder het serverlog controleren.']);
}

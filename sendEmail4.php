<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

// Stel de Nederlandse locale in
setlocale(LC_TIME, 'nl_NL.UTF-8');

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    echo json_encode(['status' => 'error', 'message' => 'Geen gegevens ontvangen']);
    exit;
}

$email   = $data['to'] ?? '';
$subject = $data['subject'] ?? '';
$body    = $data['body'] ?? '';
$from    = $data['van'] ?? '';

if(empty($email) || empty($subject) || empty($body)){
    echo json_encode(['status' => 'error', 'message' => 'Ontbrekende vereiste velden']);
    exit;
}

// Indien afhaalmoment en afhaaltijd aanwezig zijn, formatteer deze:
if(isset($data['afhaalmoment']) && isset($data['afhaaltijd'])){
    // Verwacht afhaalmoment in Y-m-d formaat
    $date = DateTime::createFromFormat('Y-m-d', $data['afhaalmoment']);
    if($date){
        $formattedDate = strftime("%A %d %B %Y", $date->getTimestamp());
    } else {
        $formattedDate = $data['afhaalmoment'];
    }
    // Verwacht afhaaltijd in H:i formaat
    $time = DateTime::createFromFormat('H:i', $data['afhaaltijd']);
    if($time){
        $formattedTime = $time->format("H:i") . " uur";
    } else {
        $formattedTime = $data['afhaaltijd'];
    }
    $body = str_replace(["[afhaalmoment]", "[afhaaltijd]"], [$formattedDate, $formattedTime], $body);
}

// Vervang overige placeholders: [chauffeurnaam], [collectegebied], [naam], [adres], [postcodePlaats] en [telefoonnummer]
$placeholders = ['[chauffeurnaam]', '[collectegebied]', '[naam]', '[adres]', '[postcodePlaats]', '[telefoonnummer]'];
$replacements = [
    $data['chauffeur'] ?? '',
    $data['collectegebied'] ?? '',
    $data['naam'] ?? '',
    $data['adres'] ?? '',
    $data['postcodePlaats'] ?? '',
    $data['telefoonnummer'] ?? ''
];
$body = str_replace($placeholders, $replacements, $body);

$headers = "From: " . $from . "\r\n" .
           "Reply-To: " . $from . "\r\n" .
           "Content-Type: text/html; charset=UTF-8\r\n";

if(mail($email, $subject, $body, $headers)){
    echo json_encode(['status' => 'success']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'E-mail verzenden mislukt']);
}
?>

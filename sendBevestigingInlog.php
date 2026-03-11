<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

function sendBevestigingInlogMail(array $payload): array
{
    $to = $payload['email'] ?? '';
    $naam = $payload['naam'] ?? '';
    $wachtwoord = $payload['wachtwoord'] ?? '';
    $iban = $payload['IBAN'] ?? '';

    if (!$to || !$naam || !$wachtwoord) {
        return ['status' => 'error', 'message' => 'Ontbrekende parameters.'];
    }

    $subject = "Welkom bij het Nierstichting collecteteam";

    $body = "<html><body>";
    $body .= "Beste $naam,<br><br>";
    $body .= "Wat fijn dat je voor ons als afstortvrijwilliger aan de slag gaat. Wij hebben een systeem ontwikkeld waarin je zelf de ritten kunt selecteren.<br><br>";
    $body .= "Ga naar <a href='https://nierstichtingnederland.nl/afstort'>https://nierstichtingnederland.nl/afstort</a> om in te loggen.<br>";
    $body .= "<p><b>Inlognaam:</b> $to</p>";
    $body .= "<p><b>Wachtwoord:</b> ************</p>";
    $body .= "<p>Voor de uitkering van jouw kilometerdecelaratie hebben we <b> $iban</b> genoteerd.</p><br>";
    $body .= "<b>In het rittenoverzicht zie je:</b><br>";
    $body .= "1. Witte regels: nog niet toegewezen.<br>";
    $body .= "2. Rode regels: toegewezen maar nog niet afgerond.<br>";
    $body .= "3. Groene regels: volledig afgerond.<br><br>";
    $body .= "<b>Werkwijze:</b><br>";
    $body .= "1. Kies in het overzicht je naam bij de rit(ten) die je wilt uitvoeren.<br>";
    $body .= "2. Neem contact op met de contactpersoon.<br>";
    $body .= "3. Noteer de afgesproken afhaaldag en tijd in de ritregel.<br>";
    $body .= "4. Gebruik de knop \"Bevestig deze rit\" om de afspraken per e-mail te bevestigen.</b><br><br>";
    $body .= "5. Haal het geld op en stort het af.<br>";
    $body .= "6. Vul het aantal gereden kilometers in.<br>";
    $body .= "7. Zet de status op Afgerond en voeg een foto van de transactiebonnen toe.<br>";
    $body .= "8. De knop Rapport onderaan de lijst geeft je een overzicht van de door jou gereden ritten, inclusief declaratie.<br><br>";
    $body .= "Alvast bedankt en veilige kilometers gewenst.<br><br>";
    $body .= "Met vriendelijke groet,<br>Nierstichting collecteteam";
    $body .= "</body></html>";

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: noreply@nierstichtingnederland.nl\r\n";
    $headers .= "Reply-To: noreply@nierstichtingnederland.nl\r\n";

    if (mail($to, $subject, $body, $headers)) {
        return ['status' => 'success', 'message' => 'E-mail verzonden.'];
    }

    return ['status' => 'error', 'message' => 'E-mail kon niet worden verzonden.'];
}

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $result = sendBevestigingInlogMail($_POST);
        echo json_encode($result);
        exit();
    }

    echo json_encode(['status' => 'error', 'message' => 'Ongeldig verzoek.']);
}
?>

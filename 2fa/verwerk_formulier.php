<?php
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Controleer of de vereiste velden zijn ingevuld
    if (isset($_POST['voornaam']) && isset($_POST['achternaam'])) {
        $voornaam = htmlspecialchars(trim($_POST['voornaam']));
        $achternaam = htmlspecialchars(trim($_POST['achternaam']));

        // Verwerk de gegevens (bijvoorbeeld opslaan in een database)
        // ...

        // Geef een bevestiging weer
        echo "Bedankt, $voornaam $achternaam, voor het invullen van het formulier.";
    } else {
        echo "Vul alstublieft alle vereiste velden in.";
    }
} else {
    echo "Ongeldige aanvraag.";
}
?>

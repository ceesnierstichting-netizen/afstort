<?php
require_once('config.php');

$email = trim($_POST['email']);
if (empty($email)) {
    die("Geen e-mailadres opgegeven.");
}

// Check of gebruiker bestaat
$stmt = $pdo->prepare("SELECT * FROM chauffeurs WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("Geen gebruiker gevonden met dit e-mailadres.");
}

// Genereer unieke token
$token = bin2hex(random_bytes(32));
$expires = date("Y-m-d H:i:s", strtotime("+1 hour"));

// Sla token op
$stmt = $pdo->prepare("INSERT INTO wachtwoord_resets (email, token, expires_at) VALUES (?, ?, ?)");
$stmt->execute([$email, $token, $expires]);

// Stuur e-mail
$resetLink = "https://nierstichtingnederland.nl/afstort/wachtwoord_reset.php?token=$token";

// Hier zou je eigen mailfunctie komen (bijv. PHPMailer of mail())
mail($email, "Wachtwoord resetten", 
    "Klik op de volgende link om je wachtwoord opnieuw in te stellen:

$resetLink

Deze link is 1 uur geldig.",
    "From: noreply@nierstichtingnederland.nl");

echo "Jouw mailadres is gevonden. Controleer je mailbox. Er is een e-mail verzonden met instructies.";

<?php
require_once('session.php');
require_once('config.php');
refreshCurrentUserAccess($pdo);
if (empty($_SESSION['twofa_verified']) || !hasAdminPermissions($_SESSION)) {
    http_response_code(403);
    exit('Geen toegang.');
}

// Vul deze variabelen in
$naam = 'Nieuwe Gebruiker';
$email = 'nieuw@voorbeeld.nl';
$iban = 'NL00BANK0123456789';
$wachtwoord = 'MijnSterkWachtwoord!';
$fullAccess = 1;

$hashedWachtwoord = password_hash($wachtwoord, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("INSERT INTO chauffeurs (naam, email, IBAN, wachtwoord, fullAccess) VALUES (?, ?, ?, ?, ?)");
$stmt->execute([$naam, $email, $iban, $hashedWachtwoord, $fullAccess]);

echo "Gebruiker toegevoegd!";

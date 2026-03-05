<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
require_once('config.php');

// Controleer of de gebruiker ingelogd is
if (!isset($_SESSION['fullAccess'])) {
    header("HTTP/1.1 401 Unauthorized");
    echo json_encode(["status" => "error", "message" => "Niet ingelogd."]);
    exit();
}

header('Content-Type: application/json');

// Lees de JSON-input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    echo json_encode(["status" => "error", "message" => "Geen data ontvangen."]);
    exit;
}

$ids = [];
foreach ($data as $i => $chauffeur) {
    // Verwacht dat de gegevens 'naam' en 'email' bevatten. Indien er een 'id' aanwezig is, wordt deze geüpdatet.
    if (isset($chauffeur['id']) && !empty($chauffeur['id'])) {
        $stmt = $pdo->prepare("UPDATE chauffeurs SET 
            naam = :naam,
            email = :email
            WHERE id = :id");
        $stmt->execute([
            ':naam'  => $chauffeur['naam'],
            ':email' => $chauffeur['email'],
            ':id'    => $chauffeur['id']
        ]);
        $ids[$i] = $chauffeur['id'];
    } else {
        $stmt = $pdo->prepare("INSERT INTO chauffeurs (naam, email) VALUES (:naam, :email)");
        $stmt->execute([
            ':naam'  => $chauffeur['naam'],
            ':email' => $chauffeur['email']
        ]);
        $ids[$i] = $pdo->lastInsertId();
    }
}

echo json_encode($ids);
?>

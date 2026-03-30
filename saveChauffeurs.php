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
    $postcode = trim($chauffeur['postcode'] ?? '');
    $lat = null;
    $lon = null;

    if ($postcode !== '' && function_exists('extractPostcode4') && function_exists('geocodePostcode')) {
        $pc4 = extractPostcode4($postcode);
        if ($pc4) {
            list($latTmp, $lonTmp) = geocodePostcode($pc4);
            if ($latTmp !== null && $lonTmp !== null) {
                $lat = (float)$latTmp;
                $lon = (float)$lonTmp;
            }
        }
    }

    // Verwacht dat de gegevens 'naam' en 'email' bevatten. Indien er een 'id' aanwezig is, wordt deze geüpdatet.
    if (isset($chauffeur['id']) && !empty($chauffeur['id'])) {
        $stmt = $pdo->prepare("UPDATE chauffeurs SET 
            naam = :naam,
            email = :email,
            postcode = :postcode,
            lat = :lat,
            lon = :lon
            WHERE id = :id");
        $stmt->execute([
            ':naam'  => $chauffeur['naam'],
            ':email' => $chauffeur['email'],
            ':postcode' => $postcode,
            ':lat' => $lat,
            ':lon' => $lon,
            ':id'    => $chauffeur['id']
        ]);
        $ids[$i] = $chauffeur['id'];
    } else {
        $stmt = $pdo->prepare("INSERT INTO chauffeurs (naam, email, postcode, lat, lon) VALUES (:naam, :email, :postcode, :lat, :lon)");
        $stmt->execute([
            ':naam'  => $chauffeur['naam'],
            ':email' => $chauffeur['email'],
            ':postcode' => $postcode,
            ':lat' => $lat,
            ':lon' => $lon
        ]);
        $ids[$i] = $pdo->lastInsertId();
    }
}

echo json_encode($ids);
?>

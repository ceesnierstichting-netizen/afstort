<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once('session.php');
require_once('config.php');

// Alleen Full Access-gebruikers mogen chauffeurs bulk opslaan
if (empty($_SESSION['fullAccess']) || empty($_SESSION['twofa_verified'])) {
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

function resolveCoordinatesForSavedChauffeur($postcode) {
    $postcode = trim((string)$postcode);
    if ($postcode === '' || !function_exists('extractPostcode6') || !function_exists('geocodePostcode')) {
        return [null, null];
    }

    $pc6 = extractPostcode6($postcode);
    if ($pc6 === '') {
        return [null, null];
    }

    list($latTmp, $lonTmp) = geocodePostcode($pc6);
    if ($latTmp === null || $lonTmp === null) {
        return [null, null];
    }

    return [(float)$latTmp, (float)$lonTmp];
}

$stmtExistingChauffeur = $pdo->prepare("SELECT postcode, lat, lon FROM chauffeurs WHERE id = :id");
$ids = [];
foreach ($data as $i => $chauffeur) {
    $postcode = trim($chauffeur['postcode'] ?? '');
    list($lat, $lon) = resolveCoordinatesForSavedChauffeur($postcode);

    // Verwacht dat de gegevens 'naam' en 'email' bevatten. Indien er een 'id' aanwezig is, wordt deze geüpdatet.
    if (isset($chauffeur['id']) && !empty($chauffeur['id'])) {
        if ($lat === null && $lon === null) {
            $stmtExistingChauffeur->execute([':id' => $chauffeur['id']]);
            $existingChauffeur = $stmtExistingChauffeur->fetch(PDO::FETCH_ASSOC);

            if ($existingChauffeur && shouldReuseStoredCoordinates($postcode, $existingChauffeur['postcode'] ?? '')) {
                $lat = isset($existingChauffeur['lat']) && $existingChauffeur['lat'] !== '' ? (float)$existingChauffeur['lat'] : null;
                $lon = isset($existingChauffeur['lon']) && $existingChauffeur['lon'] !== '' ? (float)$existingChauffeur['lon'] : null;
            }
        }

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

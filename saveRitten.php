<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
require_once('config.php');

// Zorg dat de gebruiker is ingelogd (indien nodig)
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
foreach ($data as $i => $rit) {
    // Zorg dat gebiedsnummer aanwezig is, anders een lege string
    $gebiedsnummer = isset($rit['gebiedsnummer']) ? trim($rit['gebiedsnummer']) : '';
    $postcodePlaats = trim($rit['postcodePlaats'] ?? '');
    // Reken het aantal gereden kilometers af
    $gereden = isset($rit['gereden']) && $rit['gereden'] !== "" ? intval(round($rit['gereden'])) : 0;
    $lat = null;
    $lon = null;

    if ($postcodePlaats !== '' && function_exists('extractPostcode4') && function_exists('geocodePostcode')) {
        $pc4 = extractPostcode4($postcodePlaats);
        if ($pc4) {
            list($latTmp, $lonTmp) = geocodePostcode($pc4);
            if ($latTmp !== null && $lonTmp !== null) {
                $lat = (float)$latTmp;
                $lon = (float)$lonTmp;
            }
        }
    }
    
    // Als er een ID is, gaat het om een update; anders een insert.
    if (isset($rit['id']) && !empty($rit['id'])) {
        $stmt = $pdo->prepare("UPDATE ritten SET 
            collectegebied       = :collectegebied,
            wijknaam             = :wijknaam,
            gebiedsnummer        = :gebiedsnummer,
            contactpersoon       = :contactpersoon,
            adres                = :adres,
            postcodePlaats       = :postcodePlaats,
            lat                  = :lat,
            lon                  = :lon,
            telefoonnummer       = :telefoonnummer,
            email                = :email,
            voorkeurAfhaalmoment = :voorkeurAfhaalmoment,
            verwachtBedrag       = :verwachtBedrag,
            soort                = :soort,
            chauffeur            = :chauffeur,
            afhaalmoment         = :afhaalmoment,
            afhaaltijd           = :afhaaltijd,
            gestort              = :gestort,
            status               = :status,
            gereden              = :gereden
            WHERE id = :id");
        $result = $stmt->execute([
            ':collectegebied'       => $rit['collectegebied'],
            ':gebiedsnummer'        => $gebiedsnummer,
            ':wijknaam'             => $rit['wijknaam'],
            ':contactpersoon'       => $rit['contactpersoon'],
            ':adres'                => $rit['adres'],
            ':postcodePlaats'       => $postcodePlaats,
            ':lat'                  => $lat,
            ':lon'                  => $lon,
            ':telefoonnummer'       => $rit['telefoonnummer'],
            ':email'                => $rit['email'],
            ':voorkeurAfhaalmoment' => $rit['voorkeurAfhaalmoment'] ?? '',
            ':verwachtBedrag'       => $rit['verwachtBedrag'] ?? '',
            ':soort'                => $rit['soort'],
            ':chauffeur'            => $rit['chauffeur'],
            ':afhaalmoment'         => $rit['afhaalmoment'],
            ':afhaaltijd'           => $rit['afhaaltijd'],
            ':gestort'              => isset($rit['gestort']) ? $rit['gestort'] : "",
            ':status'               => $rit['status'],
            ':gereden'              => $gereden,
            ':id'                   => $rit['id']
        ]);
        if (!$result) {
            error_log("Update Error: " . print_r($stmt->errorInfo(), true));
        }
        $ids[$i] = $rit['id'];
    } else {
        $stmt = $pdo->prepare("INSERT INTO ritten (
            collectegebied, gebiedsnummer, wijknaam, contactpersoon, adres, postcodePlaats, lat, lon, telefoonnummer, email,
            voorkeurAfhaalmoment, verwachtBedrag, soort, chauffeur, afhaalmoment, afhaaltijd, gestort, status, gereden
            ) VALUES (
            :collectegebied, :gebiedsnummer, :wijknaam, :contactpersoon, :adres, :postcodePlaats, :lat, :lon, :telefoonnummer, :email,
            :voorkeurAfhaalmoment, :verwachtBedrag, :soort, :chauffeur, :afhaalmoment, :afhaaltijd, :gestort, :status, :gereden
            )");
        $result = $stmt->execute([
            ':collectegebied'       => $rit['collectegebied'],
            ':gebiedsnummer'        => $gebiedsnummer,
            ':wijknaam'             => $rit['wijknaam'],  
            ':contactpersoon'       => $rit['contactpersoon'],
            ':adres'                => $rit['adres'],
            ':postcodePlaats'       => $postcodePlaats,
            ':lat'                  => $lat,
            ':lon'                  => $lon,
            ':telefoonnummer'       => $rit['telefoonnummer'],
            ':email'                => $rit['email'],
            ':voorkeurAfhaalmoment' => $rit['voorkeurAfhaalmoment'] ?? '',
            ':verwachtBedrag'       => $rit['verwachtBedrag'] ?? '',
            ':soort'                => $rit['soort'],
            ':chauffeur'            => $rit['chauffeur'],
            ':afhaalmoment'         => $rit['afhaalmoment'],
            ':afhaaltijd'           => $rit['afhaaltijd'],
            ':gestort'              => isset($rit['gestort']) ? $rit['gestort'] : "",
            ':status'               => $rit['status'],
            ':gereden'              => $gereden
        ]);
        if (!$result) {
            error_log("Insert Error: " . print_r($stmt->errorInfo(), true));
        }
        $ids[$i] = $pdo->lastInsertId();
    }
}

echo json_encode($ids);
?>

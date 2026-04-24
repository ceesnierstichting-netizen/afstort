<?php
// getNearestChauffeur.php - selectie op basis van echte afstand (Haversine) met lat/lon

require_once "config.php";

header('Content-Type: application/json');

/**
 * Bereken afstand in kilometer tussen twee coordinaten.
 */
function haversineDistanceKm($lat1, $lon1, $lat2, $lon2) {
    $earthRadiusKm = 6371.0;

    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat / 2) * sin($dLat / 2)
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
       * sin($dLon / 2) * sin($dLon / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadiusKm * $c;
}

/**
 * pc6 (1234AB) uit een string halen als fallback.
 */
function extractPostcode6_local($str) {
    if (!$str) return '';
    if (preg_match('/([0-9]{4})\s*([A-Za-z]{2})/', trim($str), $m)) {
        return strtoupper($m[1] . $m[2]);
    }
    return '';
}

function isValidCoord($lat, $lon) {
    return is_numeric($lat) && is_numeric($lon)
        && $lat >= -90 && $lat <= 90
        && $lon >= -180 && $lon <= 180;
}

// ---- 1. Rit-ID ophalen ----
$ritId = isset($_POST['ritId']) ? (int)$_POST['ritId'] : 0;
if (!$ritId) {
    echo json_encode(array('status' => 'error', 'message' => 'Geen ritId meegegeven.'));
    exit;
}

// Optioneel: chauffeur uitsluiten (bijvoorbeeld degene die de rit afwijst)
$excludeName = isset($_POST['exclude']) ? trim($_POST['exclude']) : '';

// ---- 2. Rit ophalen ----
$stmt = $pdo->prepare("\n    SELECT id, collectegebied, postcodePlaats, lat, lon\n    FROM ritten\n    WHERE id = :id\n");
$stmt->execute(array(':id' => $ritId));
$rit = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rit) {
    echo json_encode(array('status' => 'error', 'message' => 'Rit niet gevonden.'));
    exit;
}

$debug = array();

// ---- 3. Rit-locatie bepalen ----
$ritPostcodePlaats = isset($rit['postcodePlaats']) ? $rit['postcodePlaats'] : '';

if (function_exists('extractPostcode6')) {
    $pc6Rit = extractPostcode6($ritPostcodePlaats);
} else {
    $pc6Rit = extractPostcode6_local($ritPostcodePlaats);
}

$ritLat = isset($rit['lat']) ? $rit['lat'] : null;
$ritLon = isset($rit['lon']) ? $rit['lon'] : null;

if (!isValidCoord($ritLat, $ritLon) && $pc6Rit && function_exists('geocodePostcode')) {
    list($tmpLat, $tmpLon) = geocodePostcode($pc6Rit);
    $ritLat = $tmpLat;
    $ritLon = $tmpLon;
    $debug[] = 'Rit-coordinaten opnieuw bepaald via geocode op pc6.';
}

if (!isValidCoord($ritLat, $ritLon)) {
    echo json_encode(array(
        'status'  => 'error',
        'message' => 'Rit heeft geen geldige coordinaten. Controleer postcode (pc6) en geocoding.'
    ));
    exit;
}

$ritLat = (float)$ritLat;
$ritLon = (float)$ritLon;

// ---- 4. Chauffeurs ophalen ----
$stmt = $pdo->query("\n    SELECT id, naam, email, postcode, lat, lon\n    FROM chauffeurs\n    WHERE naam <> 'Admin'\n      AND email <> ''\n");
$chauffeurs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$chauffeurs) {
    echo json_encode(array('status' => 'error', 'message' => 'Geen chauffeurs gevonden.'));
    exit;
}

// ---- 5. Voor alle chauffeurs: km-afstand berekenen ----
$nearest = null;
$nearestScore = null;

foreach ($chauffeurs as $ch) {
    $naam = $ch['naam'];
    $email = $ch['email'];
    $postcode = $ch['postcode'];

    if ($excludeName !== '' && strcasecmp($naam, $excludeName) === 0) {
        $debug[] = "Chauffeur {$naam} overgeslagen: exclude-parameter.";
        continue;
    }

    if (!$postcode) {
        $debug[] = "Chauffeur {$naam} overgeslagen: geen postcode.";
        continue;
    }

    if (function_exists('extractPostcode6')) {
        $pc6Ch = extractPostcode6($postcode);
    } else {
        $pc6Ch = extractPostcode6_local($postcode);
    }

    $chLat = isset($ch['lat']) ? $ch['lat'] : null;
    $chLon = isset($ch['lon']) ? $ch['lon'] : null;

    if (!isValidCoord($chLat, $chLon) && $pc6Ch && function_exists('geocodePostcode')) {
        list($tmpLat, $tmpLon) = geocodePostcode($pc6Ch);
        $chLat = $tmpLat;
        $chLon = $tmpLon;
    }

    if (!isValidCoord($chLat, $chLon)) {
        $debug[] = "Chauffeur {$naam} overgeslagen: geen geldige coordinaten (pc6={$pc6Ch}).";
        continue;
    }

    $km = haversineDistanceKm($ritLat, $ritLon, (float)$chLat, (float)$chLon);
    $debug[] = "Chauffeur {$naam}: pc6={$pc6Ch}, afstandKm=" . round($km, 2);

    if ($nearest === null || $km < $nearestScore) {
        $nearest = array(
            'naam'  => $naam,
            'email' => $email,
            'pc6'   => $pc6Ch,
        );
        $nearestScore = $km;
    }
}

if ($nearest === null) {
    echo json_encode(array('status' => 'error', 'message' => 'Geen chauffeur met geldige coordinaten gevonden.'));
    exit;
}

// ---- 6. Resultaat teruggeven ----
echo json_encode(array(
    'status'         => 'ok',
    'chauffeurNaam'  => $nearest['naam'],
    'chauffeurEmail' => $nearest['email'],
    'afstandKm'      => round($nearestScore, 2),
    'collectegebied' => isset($rit['collectegebied']) ? $rit['collectegebied'] : '',
    'postcodePlaats' => $ritPostcodePlaats,
    'pc6Rit'         => $pc6Rit,
    'debug'          => $debug
));
exit;

<?php
// declineRit.php - gebruikt echte km-afstand om volgende chauffeur te kiezen

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once "config.php";

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

$ritId         = isset($_GET['rit']) ? (int)$_GET['rit'] : 0;
$chauffeurNaam = isset($_GET['chauffeur']) ? trim($_GET['chauffeur']) : '';

if (!$ritId || $chauffeurNaam === '') {
    echo "Onjuiste link. (Geen rit of chauffeur doorgegeven.)";
    exit;
}

// ---- 1. Rit ophalen ----
$stmt = $pdo->prepare("\n    SELECT id, collectegebied, postcodePlaats, lat, lon\n    FROM ritten\n    WHERE id = :id\n");
$stmt->execute(array(':id' => $ritId));
$rit = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rit) {
    echo "De rit waarvoor jij je afmeldt, is niet gevonden.";
    exit;
}

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
}
if (!isValidCoord($ritLat, $ritLon)) {
    echo "Je afmelding is geregistreerd, maar er kon geen geldige locatie (coordinaten) bij deze rit worden bepaald.";
    exit;
}
$ritLat = (float)$ritLat;
$ritLon = (float)$ritLon;

// ---- 2. Chauffeurs ophalen ----
$stmt = $pdo->query("\n    SELECT id, naam, email, postcode, lat, lon\n    FROM chauffeurs\n    WHERE naam <> 'Admin'\n      AND email <> ''\n");
$chauffeurs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$chauffeurs) {
    echo "Je afmelding is geregistreerd, maar er zijn geen andere chauffeurs gevonden.";
    exit;
}

// ---- 3. Dichtstbijzijnde andere chauffeur bepalen (km via Haversine) ----
$nearest      = null;
$nearestScore = null;

foreach ($chauffeurs as $ch) {
    $naam     = $ch['naam'];
    $email    = $ch['email'];
    $postcode = $ch['postcode'];

    // De chauffeur die zich nu afmeldt overslaan
    if (strcasecmp($naam, $chauffeurNaam) === 0) {
        continue;
    }

    if (!$postcode) {
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
        continue;
    }

    $score = haversineDistanceKm($ritLat, $ritLon, (float)$chLat, (float)$chLon);

    if ($nearest === null || $score < $nearestScore) {
        $nearest      = array(
            'naam'  => $naam,
            'email' => $email,
            'pc6'   => $pc6Ch,
        );
        $nearestScore = $score;
    }
}

// Geen andere chauffeur gevonden
if ($nearest === null) {
    ?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Afmelding rit</title>
</head>
<body>
    <h1>Helaas, maar dankjewel dat je het doorgeeft!</h1>
    <p>We hebben geregistreerd dat jij deze rit (<?php echo htmlspecialchars($ritId); ?>) niet kunt uitvoeren.</p>
    <p>Op dit moment kon geen andere chauffeur worden aangeschreven.</p>
</body>
</html>
<?php
    exit;
}

// ---- 4. Nieuwe chauffeur mailen ----
$newName        = $nearest['naam'];
$newEmail       = $nearest['email'];
$collectegebied = isset($rit['collectegebied']) ? $rit['collectegebied'] : '';
$postcodePlaats = $ritPostcodePlaats;

// Link voor eventueel opnieuw afmelden van de nieuwe chauffeur
$declineLink = "https://nierstichtingnederland.nl/afstort/declineRit.php?rit="
             . urlencode($ritId)
             . "&chauffeur=" . urlencode($newName);

$body = "Beste " . htmlspecialchars($newName) . ",<br><br>"
      . "Een collega-chauffeur heeft aangegeven deze rit niet te kunnen uitvoeren. "
      . "Jij bent nu geselecteerd als <b>dichtstbijzijnde chauffeur</b> voor deze afhaalopdracht."
      . "<br><br>Collectegebied: <b>" . htmlspecialchars($collectegebied) . "</b>"
      . "<br>Postcode/plaats: <b>" . htmlspecialchars($postcodePlaats) . "</b>"
      . "<br><br>Log in op het portal om de rit op jouw naam te zetten.Kun je deze rit niet uitvoeren? Klik dan op deze link: "
      . "<a href='" . htmlspecialchars($declineLink, ENT_QUOTES) . "'>Ik kan deze rit niet uitvoeren</a>."
      . "<br><br>Met vriendelijke groet,<br>Nierstichting collectieteam";

// Mail versturen via sendBasisemail.php
$mailPayload = json_encode(array(
    'email'   => $newEmail,
    'subject' => 'Afhaalopdracht collecte-opbrengst (nieuwe chauffeur)',
    'body'    => $body,
    'van'     => 'noreply@nierstichtingnederland.nl'
));

$optsMail = array(
    'http' => array(
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\n",
        'content' => $mailPayload
    )
);

$mailResponse = @file_get_contents("https://nierstichtingnederland.nl/afstort/sendBasisemail.php", false, stream_context_create($optsMail));
// Mailfout negeren voor de gebruiker; in log kun je 'm terugvinden als dat nodig is.
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Afmelding rit</title>
</head>
<body>
    <h1>Helaas, maar dankjewel dat je het doorgeeft!</h1>
    <p>We hebben geregistreerd dat jij deze rit (<?php echo htmlspecialchars($ritId); ?>) niet kunt uitvoeren.</p>
    <p>De rit is nu aangeboden aan de volgende dichtstbij wonende chauffeur <strong><?php echo htmlspecialchars($newName); ?></strong>.</p>
</body>
</html>

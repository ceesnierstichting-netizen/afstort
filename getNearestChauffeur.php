<?php
// getNearestChauffeur.php — selectie op basis van pc4-verschil (eerste 4 cijfers van de postcode)

require_once "config.php";

header('Content-Type: application/json');

/**
 * Eenvoudige "afstand" op basis van pc4-verschil.
 * Hoe kleiner het verschil, hoe dichterbij.
 */
function pc4Distance($pc4Rit, $pc4Ch) {
    $a = (int)$pc4Rit;
    $b = (int)$pc4Ch;
    return abs($a - $b);
}

/**
 * pc4 (4 cijfers) uit een string halen.
 * We gebruiken extractPostcode4() uit config.php als die bestaat,
 * anders deze fallback.
 */
function extractPostcode4_local($str) {
    if (!$str) return '';
    if (preg_match('/([0-9]{4})/', $str, $m)) {
        return $m[1];
    }
    return '';
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
$stmt = $pdo->prepare("
    SELECT id, collectegebied, postcodePlaats
    FROM ritten
    WHERE id = :id
");
$stmt->execute(array(':id' => $ritId));
$rit = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rit) {
    echo json_encode(array('status' => 'error', 'message' => 'Rit niet gevonden.'));
    exit;
}

// ---- 3. pc4 van de rit bepalen ----
$ritPostcodePlaats = isset($rit['postcodePlaats']) ? $rit['postcodePlaats'] : '';

if (function_exists('extractPostcode4')) {
    $pc4Rit = extractPostcode4($ritPostcodePlaats);
} else {
    $pc4Rit = extractPostcode4_local($ritPostcodePlaats);
}

if (!$pc4Rit) {
    echo json_encode(array('status' => 'error', 'message' => 'Geen geldige postcode (4 cijfers) gevonden bij deze rit.'));
    exit;
}

// ---- 4. Chauffeurs ophalen ----
$stmt = $pdo->query("
    SELECT id, naam, email, postcode
    FROM chauffeurs
    WHERE naam <> 'Admin'
      AND email <> ''
");
$chauffeurs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$chauffeurs) {
    echo json_encode(array('status' => 'error', 'message' => 'Geen chauffeurs gevonden.'));
    exit;
}

// ---- 5. Voor alle chauffeurs: pc4 bepalen en "afstand" berekenen ----
$nearest      = null;
$nearestScore = null;
$debug        = array();

foreach ($chauffeurs as $ch) {
    $naam     = $ch['naam'];
    $email    = $ch['email'];
    $postcode = $ch['postcode'];

    // De chauffeur die eventueel is uitgesloten, overslaan
    if ($excludeName !== '' && strcasecmp($naam, $excludeName) === 0) {
        $debug[] = "Chauffeur {$naam} overgeslagen: exclude-parameter.";
        continue;
    }

    if (!$postcode) {
        $debug[] = "Chauffeur {$naam} overgeslagen: geen postcode.";
        continue;
    }

    if (function_exists('extractPostcode4')) {
        $pc4Ch = extractPostcode4($postcode);
    } else {
        $pc4Ch = extractPostcode4_local($postcode);
    }

    if (!$pc4Ch) {
        $debug[] = "Chauffeur {$naam} overgeslagen: geen pc4 uit postcode.";
        continue;
    }

    $score = pc4Distance($pc4Rit, $pc4Ch);
    $debug[] = "Chauffeur {$naam}: pc4={$pc4Ch}, pc4-verschil=" . $score;

    if ($nearest === null || $score < $nearestScore) {
        $nearest      = array(
            'naam'  => $naam,
            'email' => $email,
            'pc4'   => $pc4Ch,
        );
        $nearestScore = $score;
    }
}

if ($nearest === null) {
    echo json_encode(array('status' => 'error', 'message' => 'Geen chauffeur met geldige pc4 gevonden.'));
    exit;
}

// ---- 6. Resultaat teruggeven ----
echo json_encode(array(
    'status'         => 'ok',
    'chauffeurNaam'  => $nearest['naam'],
    'chauffeurEmail' => $nearest['email'],
    // afstandKm is hier eigenlijk "pc4-verschil", maar we laten de sleutelnaam staan
    'afstandKm'      => $nearestScore,
    'collectegebied' => isset($rit['collectegebied']) ? $rit['collectegebied'] : '',
    'postcodePlaats' => $ritPostcodePlaats,
    'pc4Rit'         => $pc4Rit,
    'debug'          => $debug
));
exit;

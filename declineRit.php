<?php
// declineRit.php — gebruikt pc4-verschil om volgende chauffeur te kiezen

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once "config.php";

function pc4Distance($pc4Rit, $pc4Ch) {
    $a = (int)$pc4Rit;
    $b = (int)$pc4Ch;
    return abs($a - $b);
}

function extractPostcode4_local($str) {
    if (!$str) return '';
    if (preg_match('/([0-9]{4})/', $str, $m)) {
        return $m[1];
    }
    return '';
}

$ritId         = isset($_GET['rit']) ? (int)$_GET['rit'] : 0;
$chauffeurNaam = isset($_GET['chauffeur']) ? trim($_GET['chauffeur']) : '';

if (!$ritId || $chauffeurNaam === '') {
    echo "Onjuiste link. (Geen rit of chauffeur doorgegeven.)";
    exit;
}

// ---- 1. Rit ophalen ----
$stmt = $pdo->prepare("
    SELECT id, collectegebied, postcodePlaats
    FROM ritten
    WHERE id = :id
");
$stmt->execute(array(':id' => $ritId));
$rit = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rit) {
    echo "De rit waarvoor jij je afmeldt, is niet gevonden.";
    exit;
}

$ritPostcodePlaats = isset($rit['postcodePlaats']) ? $rit['postcodePlaats'] : '';

if (function_exists('extractPostcode4')) {
    $pc4Rit = extractPostcode4($ritPostcodePlaats);
} else {
    $pc4Rit = extractPostcode4_local($ritPostcodePlaats);
}

if (!$pc4Rit) {
    echo "Je afmelding is geregistreerd, maar er kon geen geldige postcode (4 cijfers) bij deze rit worden gevonden.";
    exit;
}

// ---- 2. Chauffeurs ophalen ----
$stmt = $pdo->query("
    SELECT id, naam, email, postcode
    FROM chauffeurs
    WHERE naam <> 'Admin'
      AND email <> ''
");
$chauffeurs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$chauffeurs) {
    echo "Je afmelding is geregistreerd, maar er zijn geen andere chauffeurs gevonden.";
    exit;
}

// ---- 3. Dichtstbijzijnde andere chauffeur bepalen (pc4-verschil) ----
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

    if (function_exists('extractPostcode4')) {
        $pc4Ch = extractPostcode4($postcode);
    } else {
        $pc4Ch = extractPostcode4_local($postcode);
    }

    if (!$pc4Ch) {
        continue;
    }

    $score = pc4Distance($pc4Rit, $pc4Ch);

    if ($nearest === null || $score < $nearestScore) {
        $nearest      = array(
            'naam'  => $naam,
            'email' => $email,
            'pc4'   => $pc4Ch,
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

<?php
// config.php
// Database configuratie (geen session_start hier)

$host    = 'database-5017237046.webspace-host.com';
$db      = 'dbs13838352';  // Gebruik hier exact je originele database naam
$user    = 'dbu1220177';
$pass    = 'W9woort.W9woort.';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die('Database connectie mislukt: ' . $e->getMessage());
}


/**
 * Haal lat/lon op voor een Nederlandse postcode (4 cijfers + evt. 2 letters),
 * of voor alleen de eerste 4 cijfers (pc4).
 */
function geocodePostcode($postcode) {
    $postcode = trim($postcode);
    if ($postcode === '') {
        return [null, null];
    }

    // Maak nette variant zonder spaties, hoofdletters
    $clean = strtoupper(str_replace(' ', '', $postcode));

    // Query naar Nominatim (OpenStreetMap)
    $url = "https://nominatim.openstreetmap.org/search?format=json&q="
         . urlencode($clean . " Nederland");

    $opts = [
        "http" => [
            "header" => "User-Agent: Nierstichting-Afstort/1.0\r\n"
        ]
    ];
    $context = stream_context_create($opts);

    $json = @file_get_contents($url, false, $context);
    if ($json === false) {
        return [null, null];
    }

    $data = json_decode($json, true);
    if (!is_array($data) || empty($data[0]['lat']) || empty($data[0]['lon'])) {
        return [null, null];
    }

    return [floatval($data[0]['lat']), floatval($data[0]['lon'])];
}

/**
 * Haal de eerste 4 cijfers (pc4) uit een string zoals '1234 AB Amsterdam'.
 */
function extractPostcode4($str) {
    if (!$str) return '';

    if (preg_match('/([0-9]{4})/', $str, $m)) {
        return $m[1];
    }
    return '';
}

?>

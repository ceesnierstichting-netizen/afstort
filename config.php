<?php
// config.php
// Database configuratie (geen session_start hier)

require_once __DIR__ . '/app_helpers.php';

function sendNoIndexHeaders() {
    if (!headers_sent()) {
        header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex', true);
    }
}

function noIndexMetaTag() {
    return '<meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">' . PHP_EOL
         . '    <meta name="googlebot" content="noindex, nofollow, noarchive, nosnippet, noimageindex">';
}

sendNoIndexHeaders();

$serverConfig = [];
$serverConfigPath = dirname(__DIR__) . '/afstort-db-config.php';
if (is_file($serverConfigPath)) {
    $loadedConfig = require $serverConfigPath;
    if (is_array($loadedConfig)) {
        $serverConfig = $loadedConfig;
    }
}

$host = getenv('AFSTORT_DB_HOST') ?: ($_SERVER['AFSTORT_DB_HOST'] ?? $serverConfig['host'] ?? null);
$db = getenv('AFSTORT_DB_NAME') ?: ($_SERVER['AFSTORT_DB_NAME'] ?? $serverConfig['database'] ?? null);
$user = getenv('AFSTORT_DB_USER') ?: ($_SERVER['AFSTORT_DB_USER'] ?? $serverConfig['user'] ?? null);
$pass = getenv('AFSTORT_DB_PASSWORD') ?: ($_SERVER['AFSTORT_DB_PASSWORD'] ?? $serverConfig['password'] ?? null);
if (!$host || !$db || !$user || !$pass) {
    error_log('Afstort: databaseconfiguratie ontbreekt (AFSTORT_DB_*).');
    http_response_code(503);
    exit('Databaseconfiguratie ontbreekt op de server.');
}
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
    error_log('Database connectie mislukt: ' . $e->getMessage());
    http_response_code(503);
    exit('Database tijdelijk niet beschikbaar.');
}

if (!function_exists('normalizeFullAccess')) {
    function normalizeFullAccess($value) {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return ((int)$value) !== 0;
        }

        if (is_string($value)) {
            if (strlen($value) === 1) {
                $byteValue = ord($value);
                if ($byteValue === 0 || $byteValue === 1) {
                    return $byteValue === 1;
                }
            }

            $clean = strtolower(trim($value));
            if (is_numeric($clean)) {
                return ((int)$clean) !== 0;
            }

            return in_array($clean, ['true', 'yes', 'ja', 'on', 'x'], true);
        }

        return false;
    }
}

if (!function_exists('normalizePostcodeInput')) {
    function normalizePostcodeInput($value) {
        return strtoupper(str_replace(' ', '', trim((string)$value)));
    }
}

if (!function_exists('extractPostcode6')) {
    function extractPostcode6($str) {
        if (!$str) {
            return '';
        }

        if (preg_match('/\b([0-9]{4})\s*([A-Za-z]{2})\b/', trim($str), $m)) {
            return strtoupper($m[1] . $m[2]);
        }

        return '';
    }
}

if (!function_exists('shouldReuseStoredCoordinates')) {
    function shouldReuseStoredCoordinates($newPostcodeValue, $existingPostcodeValue) {
        $newPc6 = extractPostcode6($newPostcodeValue);
        $existingPc6 = extractPostcode6($existingPostcodeValue);

        if ($newPc6 !== '' && $existingPc6 !== '') {
            return $newPc6 === $existingPc6;
        }

        $newNormalized = normalizePostcodeInput($newPostcodeValue);
        $existingNormalized = normalizePostcodeInput($existingPostcodeValue);

        return $newNormalized !== '' && $newNormalized === $existingNormalized;
    }
}

if (!function_exists('isMobileUserAgent')) {
    function isMobileUserAgent($userAgent = null) {
        $userAgent = strtolower((string)($userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')));
        if ($userAgent === '') {
            return false;
        }

        return (bool)preg_match('/android|iphone|ipad|ipod|mobile|blackberry|opera mini|windows phone/', $userAgent);
    }
}

if (!function_exists('shouldUseMobileDriverView')) {
    function shouldUseMobileDriverView($fullAccess) {
        if ($fullAccess) {
            return false;
        }

        return isMobileUserAgent();
    }
}

function refreshCurrentUserAccess(PDO $pdo) {
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['twofa_verified'])) {
        return;
    }

    $user = null;

    if (!empty($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("SELECT id, naam, email, fullAccess, is_medewerker FROM chauffeurs WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            // Een verwijderd account mag geen bestaande sessie blijven gebruiken.
            $_SESSION = [];
            session_regenerate_id(true);
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['message' => 'Je account bestaat niet meer. Log opnieuw in.']);
            exit;
        }
    }

    if (!$user && !empty($_SESSION['user_email'])) {
        $stmt = $pdo->prepare("SELECT id, naam, email, fullAccess, is_medewerker FROM chauffeurs WHERE email = ? LIMIT 1");
        $stmt->execute([$_SESSION['user_email']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$user && !empty($_SESSION['username'])) {
        $stmt = $pdo->prepare("SELECT id, naam, email, fullAccess, is_medewerker FROM chauffeurs WHERE naam = ? LIMIT 1");
        $stmt->execute([$_SESSION['username']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($user) {
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['naam'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['is_medewerker'] = isMedewerker($user);
        $_SESSION['fullAccess'] = hasDashboardAccess($user);
    }
}


/**
 * Haal lat/lon op voor een Nederlandse postcode (4 cijfers + 2 letters).
 */
function geocodePostcode($postcode) {
    $postcode = trim($postcode);
    if ($postcode === '') {
        return [null, null];
    }

    // Maak nette variant zonder spaties, hoofdletters
    $clean = strtoupper(str_replace(' ', '', $postcode));

    // 1) Probeer eerst PDOK (Nederlandse locatieserver, geschikt voor postcodes)
    $pdokUrl = "https://api.pdok.nl/bzk/locatieserver/search/v3_1/free"
             . "?q=" . urlencode($clean)
             . "&rows=1";

    $opts = [
        "http" => [
            "header" => "User-Agent: Nierstichting-Afstort/1.0\r\n",
            "timeout" => 8
        ]
    ];
    $context = stream_context_create($opts);

    $pdokJson = @file_get_contents($pdokUrl, false, $context);
    if ($pdokJson !== false) {
        $pdokData = json_decode($pdokJson, true);
        $doc = $pdokData['response']['docs'][0] ?? null;
        $point = $doc['centroide_ll'] ?? null; // bijv. "POINT(4.90092993 52.37275982)"
        if (is_string($point) && preg_match('/POINT\(([-0-9\.]+)\s+([-0-9\.]+)\)/', $point, $m)) {
            $lon = floatval($m[1]);
            $lat = floatval($m[2]);
            return [$lat, $lon];
        }
    }

    // 2) Fallback: Nominatim (OpenStreetMap)
    $url = "https://nominatim.openstreetmap.org/search?format=json&q="
         . urlencode($clean . " Nederland");

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

// Controleer ook actieve sessies bij losse API- en rapportverzoeken.
refreshCurrentUserAccess($pdo);

?>

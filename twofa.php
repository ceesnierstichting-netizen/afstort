<?php

const TWOFA_ISSUER = 'Afstortverzoeken';
const TWOFA_PERIOD = 30;
const TWOFA_DIGITS = 6;
const TWOFA_PENDING_TTL = 600;
const TWOFA_EMAIL_CODE_TTL = 600;
const TWOFA_EMAIL_RESEND_SECONDS = 60;
const TWOFA_EMAIL_MAX_ATTEMPTS = 5;

function twofa_base32_encode($data) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    $output = '';

    for ($i = 0, $len = strlen($data); $i < $len; $i++) {
        $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
    }

    for ($i = 0, $len = strlen($bits); $i < $len; $i += 5) {
        $chunk = substr($bits, $i, 5);
        if (strlen($chunk) < 5) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        }
        $output .= $alphabet[bindec($chunk)];
    }

    return $output;
}

function twofa_base32_decode($secret) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = twofa_normalize_secret($secret);
    $buffer = 0;
    $bitsLeft = 0;
    $output = '';

    for ($i = 0, $len = strlen($secret); $i < $len; $i++) {
        $value = strpos($alphabet, $secret[$i]);
        if ($value === false) {
            return false;
        }

        $buffer = ($buffer << 5) | $value;
        $bitsLeft += 5;

        while ($bitsLeft >= 8) {
            $bitsLeft -= 8;
            $output .= chr(($buffer >> $bitsLeft) & 0xff);
            $buffer &= (1 << $bitsLeft) - 1;
        }
    }

    return $output;
}

function twofa_normalize_secret($secret) {
    return preg_replace('/[^A-Z2-7]/', '', strtoupper((string)$secret));
}

function twofa_random_bytes($length) {
    if (function_exists('random_bytes')) {
        return random_bytes($length);
    }

    if (function_exists('openssl_random_pseudo_bytes')) {
        $strong = false;
        $bytes = openssl_random_pseudo_bytes($length, $strong);
        if ($bytes !== false && $strong === true) {
            return $bytes;
        }
    }

    throw new Exception('Geen veilige random generator beschikbaar voor 2FA.');
}

function twofa_generate_secret() {
    return rtrim(twofa_base32_encode(twofa_random_bytes(20)), '=');
}

function twofa_generate_email_code() {
    if (function_exists('random_int')) {
        return (string)random_int(100000, 999999);
    }

    $bytes = unpack('N', twofa_random_bytes(4));
    $value = ($bytes[1] % 900000) + 100000;
    return (string)$value;
}

function twofa_counter_to_binary($counter) {
    return pack('N2', 0, (int)$counter);
}

function twofa_generate_code($secret, $timeSlice = null) {
    $key = twofa_base32_decode($secret);
    if ($key === false || $key === '') {
        return null;
    }

    if ($timeSlice === null) {
        $timeSlice = floor(time() / TWOFA_PERIOD);
    }

    $hash = hash_hmac('sha1', twofa_counter_to_binary($timeSlice), $key, true);
    $offset = ord(substr($hash, -1)) & 0x0f;
    $value = (
        ((ord($hash[$offset]) & 0x7f) << 24) |
        ((ord($hash[$offset + 1]) & 0xff) << 16) |
        ((ord($hash[$offset + 2]) & 0xff) << 8) |
        (ord($hash[$offset + 3]) & 0xff)
    );

    $modulo = pow(10, TWOFA_DIGITS);
    return str_pad((string)($value % $modulo), TWOFA_DIGITS, '0', STR_PAD_LEFT);
}

function twofa_verify_code($secret, $code, $window = 1, &$matchedStep = null) {
    $code = preg_replace('/\s+/', '', (string)$code);
    if (!preg_match('/^\d{' . TWOFA_DIGITS . '}$/', $code)) {
        return false;
    }

    $currentStep = floor(time() / TWOFA_PERIOD);
    for ($offset = -$window; $offset <= $window; $offset++) {
        $step = $currentStep + $offset;
        $expected = twofa_generate_code($secret, $step);
        if ($expected !== null && hash_equals($expected, $code)) {
            $matchedStep = $step;
            return true;
        }
    }

    return false;
}

function twofa_otpauth_uri($accountName, $secret) {
    $label = TWOFA_ISSUER . ':' . $accountName;
    return 'otpauth://totp/' . rawurlencode($label)
        . '?secret=' . rawurlencode($secret)
        . '&issuer=' . rawurlencode(TWOFA_ISSUER)
        . '&algorithm=SHA1&digits=' . TWOFA_DIGITS
        . '&period=' . TWOFA_PERIOD;
}

function twofa_format_secret($secret) {
    return trim(chunk_split(twofa_normalize_secret($secret), 4, ' '));
}

function twofa_generate_recovery_codes($count = 10) {
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $raw = substr(twofa_base32_encode(twofa_random_bytes(10)), 0, 12);
        $codes[] = implode('-', str_split($raw, 4));
    }
    return $codes;
}

function twofa_normalize_recovery_code($code) {
    return preg_replace('/[^A-Z2-7]/', '', strtoupper((string)$code));
}

function twofa_hash_recovery_codes(array $codes) {
    $hashes = [];
    foreach ($codes as $code) {
        $hashes[] = password_hash(twofa_normalize_recovery_code($code), PASSWORD_DEFAULT);
    }
    return json_encode($hashes);
}

function twofa_verify_recovery_code($code, $storedJson, &$updatedJson = null) {
    $normalized = twofa_normalize_recovery_code($code);
    if ($normalized === '' || $storedJson === null || $storedJson === '') {
        return false;
    }

    $hashes = json_decode($storedJson, true);
    if (!is_array($hashes)) {
        return false;
    }

    foreach ($hashes as $index => $hash) {
        if (is_string($hash) && password_verify($normalized, $hash)) {
            unset($hashes[$index]);
            $updatedJson = json_encode(array_values($hashes));
            return true;
        }
    }

    return false;
}

function twofa_mask_email($email) {
    $email = (string)$email;
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) {
        return $email;
    }

    $name = $parts[0];
    $domain = $parts[1];
    $first = $name !== '' ? $name[0] : '*';
    return $first . str_repeat('*', max(2, strlen($name) - 1)) . '@' . $domain;
}

function twofa_can_send_email_code(&$waitSeconds = 0) {
    $sentAt = (int)($_SESSION['twofa_email_code_sent_at'] ?? 0);
    $elapsed = time() - $sentAt;

    if ($sentAt > 0 && $elapsed < TWOFA_EMAIL_RESEND_SECONDS) {
        $waitSeconds = TWOFA_EMAIL_RESEND_SECONDS - $elapsed;
        return false;
    }

    return true;
}

function twofa_store_email_code($code) {
    $_SESSION['twofa_email_code_hash'] = password_hash((string)$code, PASSWORD_DEFAULT);
    $_SESSION['twofa_email_code_expires_at'] = time() + TWOFA_EMAIL_CODE_TTL;
    $_SESSION['twofa_email_code_sent_at'] = time();
    $_SESSION['twofa_email_code_attempts'] = 0;
}

function twofa_clear_email_code() {
    unset(
        $_SESSION['twofa_email_code_hash'],
        $_SESSION['twofa_email_code_expires_at'],
        $_SESSION['twofa_email_code_sent_at'],
        $_SESSION['twofa_email_code_attempts']
    );
}

function twofa_verify_email_code($code, &$message = '') {
    $code = preg_replace('/\s+/', '', (string)$code);

    if (empty($_SESSION['twofa_email_code_hash']) || empty($_SESSION['twofa_email_code_expires_at'])) {
        $message = "Vraag eerst een code per mail aan.";
        return false;
    }

    if (time() > (int)$_SESSION['twofa_email_code_expires_at']) {
        twofa_clear_email_code();
        $message = "De code per mail is verlopen. Vraag een nieuwe code aan.";
        return false;
    }

    $attempts = (int)($_SESSION['twofa_email_code_attempts'] ?? 0);
    if ($attempts >= TWOFA_EMAIL_MAX_ATTEMPTS) {
        twofa_clear_email_code();
        $message = "Er zijn te veel pogingen gedaan. Vraag een nieuwe code per mail aan.";
        return false;
    }

    $_SESSION['twofa_email_code_attempts'] = $attempts + 1;

    if (preg_match('/^\d{6}$/', $code) && password_verify($code, $_SESSION['twofa_email_code_hash'])) {
        twofa_clear_email_code();
        $message = "";
        return true;
    }

    $message = "De code per mail klopt niet.";
    return false;
}

function twofa_send_email_code($email, $code, $name = '') {
    $subject = "Je inlogcode voor Afstortverzoeken";
    $safeName = trim((string)$name);
    $greeting = $safeName !== '' ? "Hallo " . $safeName . "," : "Hallo,";
    $body = $greeting . "\n\n"
        . "Je inlogcode is: " . $code . "\n\n"
        . "Deze code is 10 minuten geldig. Heb je niet geprobeerd in te loggen, dan kun je deze mail negeren.\n\n"
        . "Afstortverzoeken";

    $headers = "From: noreply@nierstichtingnederland.nl\r\n"
        . "Reply-To: noreply@nierstichtingnederland.nl\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n";

    return mail($email, $subject, $body, $headers);
}

function twofa_start_pending_login(array $user) {
    session_regenerate_id(true);
    unset(
        $_SESSION['username'],
        $_SESSION['fullAccess'],
        $_SESSION['user_id'],
        $_SESSION['twofa_verified'],
        $_SESSION['pending_2fa_secret'],
        $_SESSION['twofa_email_code_hash'],
        $_SESSION['twofa_email_code_expires_at'],
        $_SESSION['twofa_email_code_sent_at'],
        $_SESSION['twofa_email_code_attempts']
    );
    $_SESSION['pending_2fa_user_id'] = (int)$user['id'];
    $_SESSION['pending_2fa_started_at'] = time();
}

function twofa_get_pending_user(PDO $pdo) {
    if (empty($_SESSION['pending_2fa_user_id']) || empty($_SESSION['pending_2fa_started_at'])) {
        return null;
    }

    if ((time() - (int)$_SESSION['pending_2fa_started_at']) > TWOFA_PENDING_TTL) {
        twofa_clear_pending_login();
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM chauffeurs WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$_SESSION['pending_2fa_user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $user ?: null;
}

function twofa_clear_pending_login() {
    unset(
        $_SESSION['pending_2fa_user_id'],
        $_SESSION['pending_2fa_started_at'],
        $_SESSION['pending_2fa_secret'],
        $_SESSION['twofa_email_code_hash'],
        $_SESSION['twofa_email_code_expires_at'],
        $_SESSION['twofa_email_code_sent_at'],
        $_SESSION['twofa_email_code_attempts']
    );
}

function twofa_finish_login(array $user) {
    session_regenerate_id(true);
    $_SESSION['username'] = $user['naam'];
    $_SESSION['fullAccess'] = (bool)$user['fullAccess'];
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['twofa_verified'] = true;
    $_SESSION['LAST_ACTIVITY'] = time();
    twofa_clear_pending_login();
}

?>

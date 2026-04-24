<?php
require_once('session.php');
require_once('config.php');
require_once('twofa.php');
require_once('qrcode.php');

$user = twofa_get_pending_user($pdo);
if (!$user) {
    header("Location: login.php");
    exit();
}

if (!empty($user['twofa_enabled']) && !empty($user['twofa_secret'])) {
    header("Location: 2fa_verify.php");
    exit();
}

if (empty($_SESSION['pending_2fa_secret'])) {
    $_SESSION['pending_2fa_secret'] = twofa_generate_secret();
}

$secret = $_SESSION['pending_2fa_secret'];
$setupKey = twofa_format_secret($secret);
$otpauthUri = twofa_otpauth_uri($user['email'] ?: $user['naam'], $secret);
$qrSvg = "";
$error = "";

try {
    $qrSvg = qr_svg($otpauthUri);
} catch (Exception $e) {
    $qrSvg = "";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['code'] ?? '');
    $matchedStep = null;

    if (twofa_verify_code($secret, $code, 1, $matchedStep)) {
        $recoveryCodes = twofa_generate_recovery_codes();
        $recoveryHashes = twofa_hash_recovery_codes($recoveryCodes);

        try {
            $stmt = $pdo->prepare("
                UPDATE chauffeurs
                SET twofa_secret = ?,
                    twofa_enabled = 1,
                    twofa_recovery_codes = ?,
                    twofa_confirmed_at = NOW(),
                    twofa_last_used_step = ?
                WHERE id = ?
            ");
            $stmt->execute([$secret, $recoveryHashes, $matchedStep, (int)$user['id']]);

            $_SESSION['new_recovery_codes'] = $recoveryCodes;
            twofa_finish_login($user);
            header("Location: 2fa_recovery_codes.php");
            exit();
        } catch (PDOException $e) {
            $error = "De 2FA-kolommen ontbreken nog. Voer eerst database_2fa_migratie.sql uit.";
        }
    } else {
        $error = "De controlecode klopt niet. Controleer de tijd op je telefoon en probeer opnieuw.";
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo noIndexMetaTag(); ?>
    <title>2FA instellen</title>
    <link rel="icon" type="image/png" href="favicon.png">
    <style>
        :root {
            color-scheme: light;
            --primary: #c8102e;
            --primary-dark: #a00e26;
            --surface: #ffffff;
            --text: #1f2937;
            --muted: #6b7280;
            --border: #d1d5db;
            --focus: #fda4af;
            --shadow: 0 16px 45px rgba(17, 24, 39, 0.12);
        }

        * { box-sizing: border-box; }

        body {
            font-family: "Segoe UI", Arial, sans-serif;
            background: linear-gradient(145deg, #fff7f8 0%, #f6f7fb 100%);
            margin: 0;
            min-height: 100vh;
            color: var(--text);
            display: flex;
            padding: 24px;
        }

        main {
            width: 100%;
            max-width: 560px;
            margin: auto;
            background: var(--surface);
            border-radius: 16px;
            padding: 28px;
            box-shadow: var(--shadow);
        }

        .logo {
            width: 76px;
            height: 76px;
            display: block;
            margin: 0 auto 18px;
        }

        h1 {
            margin: 0;
            color: var(--primary-dark);
            text-align: center;
            font-size: 1.8rem;
        }

        p {
            color: var(--muted);
            line-height: 1.5;
        }

        label {
            display: block;
            margin-top: 18px;
            font-weight: 600;
        }

        input[type="text"] {
            width: 100%;
            padding: 12px;
            margin-top: 6px;
            border-radius: 10px;
            border: 1px solid var(--border);
            font-size: 1.1rem;
            letter-spacing: 0.08em;
        }

        input[type="text"]:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(253, 164, 175, 0.35);
        }

        button {
            margin-top: 18px;
            padding: 12px;
            width: 100%;
            background-color: var(--primary);
            color: white;
            border: none;
            cursor: pointer;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
        }

        button:hover { background-color: var(--primary-dark); }

        code,
        textarea {
            font-family: Consolas, Monaco, monospace;
        }

        .setup-key {
            display: block;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #f9fafb;
            color: var(--text);
            font-size: 1rem;
            word-break: break-word;
        }

        .qr-wrap {
            display: flex;
            justify-content: center;
            margin: 20px 0 18px;
        }

        .qr-code {
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #fff;
            padding: 12px;
        }

        .qr-code svg {
            display: block;
            width: min(220px, 68vw);
            height: auto;
        }

        textarea {
            width: 100%;
            min-height: 88px;
            resize: vertical;
            padding: 10px;
            border-radius: 10px;
            border: 1px solid var(--border);
            color: var(--muted);
            background: #f9fafb;
        }

        .error {
            color: #9f1239;
            border: 1px solid #fecdd3;
            background: #fff1f2;
            padding: 10px 12px;
            border-radius: 10px;
            margin-top: 16px;
        }

        .steps {
            margin: 18px 0;
            padding-left: 20px;
            color: var(--text);
            line-height: 1.6;
        }

        .muted { color: var(--muted); }
    </style>
</head>
<body>
    <main>
        <img src="logohome.png" alt="Logo" class="logo">
        <h1>2FA instellen</h1>
        <p>Je wachtwoord klopt. Stel nu een authenticator-app in om veilig toegang te krijgen.</p>

        <?php if (!empty($error)): ?>
            <div class="error" role="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <ol class="steps">
            <li>Open Google Authenticator, Microsoft Authenticator, 1Password of Bitwarden.</li>
            <li>Kies voor QR-code scannen.</li>
            <li>Scan onderstaande QR-code en vul daarna de 6-cijferige code in.</li>
        </ol>

        <?php if ($qrSvg !== ""): ?>
            <div class="qr-wrap">
                <div class="qr-code"><?php echo $qrSvg; ?></div>
            </div>
        <?php else: ?>
            <p class="muted">De QR-code kon niet worden gemaakt. Gebruik de setup key hieronder.</p>
        <?php endif; ?>

        <label>Setup key voor handmatige invoer</label>
        <code class="setup-key"><?php echo htmlspecialchars($setupKey); ?></code>

        <form method="post" action="" novalidate>
            <label for="code">Controlecode</label>
            <input type="text" id="code" name="code" required inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*" autofocus>
            <button type="submit">2FA activeren</button>
        </form>
    </main>
</body>
</html>

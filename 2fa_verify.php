<?php
require_once('session.php');
require_once('config.php');
require_once('twofa.php');

$user = twofa_get_pending_user($pdo);
if (!$user) {
    header("Location: login.php");
    exit();
}

if (empty($user['twofa_enabled']) || empty($user['twofa_secret'])) {
    header("Location: 2fa_setup.php");
    exit();
}

$error = "";
$notice = "";
$mode = "authenticator";

if (isset($_GET['send']) && $_GET['send'] === 'mail') {
    $mode = "mail";
    $waitSeconds = 0;

    if (empty($user['email']) || !filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
        $error = "Er is geen geldig e-mailadres bekend voor dit account.";
        $mode = "authenticator";
    } elseif (!twofa_can_send_email_code($waitSeconds)) {
        $notice = "Er is net al een code verstuurd. Wacht nog " . $waitSeconds . " seconden voor een nieuwe code.";
    } else {
        $emailCode = twofa_generate_email_code();
        twofa_store_email_code($emailCode);

        if (twofa_send_email_code($user['email'], $emailCode, $user['naam'] ?? '')) {
            $notice = "We hebben een code gestuurd naar " . twofa_mask_email($user['email']) . ".";
        } else {
            twofa_clear_email_code();
            $error = "De code kon niet per mail worden verzonden. Probeer het later opnieuw of gebruik je authenticator-app.";
            $mode = "authenticator";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = ($_POST['method'] ?? 'authenticator') === 'mail' ? 'mail' : 'authenticator';
    $code = trim($_POST['twofa_code'] ?? $_POST['code'] ?? '');
    $matchedStep = null;
    $lastUsedStep = isset($user['twofa_last_used_step']) ? (int)$user['twofa_last_used_step'] : 0;
    $emailMessage = "";
    $emailCodeChecked = false;

    if ($mode === 'mail') {
        $emailCodeChecked = true;
        if (twofa_verify_email_code($code, $emailMessage)) {
            twofa_finish_login($user);
            header("Location: index.php");
            exit();
        }
    }

    if (twofa_verify_code($user['twofa_secret'], $code, 1, $matchedStep)) {
        if ($matchedStep <= $lastUsedStep) {
            $error = "Deze controlecode is al gebruikt. Wacht op een nieuwe code en probeer opnieuw.";
        } else {
            $stmt = $pdo->prepare("UPDATE chauffeurs SET twofa_last_used_step = ? WHERE id = ?");
            $stmt->execute([$matchedStep, (int)$user['id']]);
            twofa_finish_login($user);
            header("Location: index.php");
            exit();
        }
    } else {
        $updatedRecoveryCodes = null;
        if (twofa_verify_recovery_code($code, $user['twofa_recovery_codes'] ?? null, $updatedRecoveryCodes)) {
            $stmt = $pdo->prepare("UPDATE chauffeurs SET twofa_recovery_codes = ? WHERE id = ?");
            $stmt->execute([$updatedRecoveryCodes, (int)$user['id']]);
            twofa_finish_login($user);
            header("Location: index.php");
            exit();
        }

        if (!$emailCodeChecked && preg_match('/^\D*\d\D*\d\D*\d\D*\d\D*\d\D*\d\D*$/', $code)) {
            $emailCodeChecked = true;
            if (twofa_verify_email_code($code, $emailMessage)) {
                twofa_finish_login($user);
                header("Location: index.php");
                exit();
            }
        }

        $error = ($mode === 'mail' && $emailMessage !== "")
            ? $emailMessage
            : "De controlecode klopt niet. Je kunt ook een herstelcode gebruiken.";
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo noIndexMetaTag(); ?>
    <title>2FA controle</title>
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
            max-width: 420px;
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
            text-align: center;
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
            font-size: 1.15rem;
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

        .error {
            color: #9f1239;
            border: 1px solid #fecdd3;
            background: #fff1f2;
            padding: 10px 12px;
            border-radius: 10px;
            margin-top: 16px;
        }

        .notice {
            color: #14532d;
            border: 1px solid #bbf7d0;
            background: #f0fdf4;
            padding: 10px 12px;
            border-radius: 10px;
            margin-top: 16px;
        }

        .fallback {
            margin-top: 16px;
            font-size: 0.94rem;
        }

        .fallback a {
            color: var(--primary-dark);
            font-weight: 700;
            text-decoration: none;
        }

        .fallback a:hover {
            text-decoration: underline;
        }

        .logout {
            margin-top: 16px;
            text-align: center;
        }

        .logout a {
            color: var(--primary-dark);
            font-weight: 600;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <main>
        <img src="logohome.png" alt="Logo" class="logo">
        <h1>2FA controle</h1>
        <?php if ($mode === 'mail'): ?>
            <p>Vul de 6-cijferige code in die je per mail hebt ontvangen.</p>
        <?php else: ?>
            <p>Vul de 6-cijferige code uit je authenticator-app in.</p>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="error" role="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (!empty($notice)): ?>
            <div class="notice" role="status"><?php echo htmlspecialchars($notice); ?></div>
        <?php endif; ?>

        <form method="post" action="" novalidate>
            <input type="hidden" name="method" value="<?php echo $mode === 'mail' ? 'mail' : 'authenticator'; ?>">
            <label for="twofa_code"><?php echo $mode === 'mail' ? 'Code uit e-mail' : 'Controlecode of herstelcode'; ?></label>
            <input
                type="text"
                id="twofa_code"
                name="twofa_code"
                required
                inputmode="text"
                autocomplete="off"
                autocapitalize="off"
                autocorrect="off"
                spellcheck="false"
                data-lpignore="true"
                data-1p-ignore="true"
                data-bwignore="true"
                data-form-type="other"
                autofocus>
            <button type="submit">Inloggen</button>
        </form>

        <p class="fallback">
            Heb je geen toegang tot je telefoon of de authenticator app, je kunt ook de code
            <a href="2fa_verify.php?send=mail">Per mail</a>
            ontvangen.
        </p>

        <?php if ($mode === 'mail'): ?>
            <p class="fallback"><a href="2fa_verify.php">Toch de authenticator-app gebruiken</a></p>
        <?php endif; ?>

        <div class="logout">
            <a href="logout.php">Annuleren</a>
        </div>
    </main>
</body>
</html>

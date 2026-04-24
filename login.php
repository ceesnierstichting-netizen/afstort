<?php
require_once('session.php');
require_once('config.php');
require_once('twofa.php');

if (isset($_SESSION['fullAccess']) && !empty($_SESSION['twofa_verified'])) {
    header("Location: index.php");
    exit();
}

if (isset($_SESSION['fullAccess']) && empty($_SESSION['twofa_verified'])) {
    unset($_SESSION['username'], $_SESSION['fullAccess'], $_SESSION['user_id']);
}

$error = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']);
    $password = trim($_POST['password']);

    if (empty($email) || empty($password)) {
        $error = "Vul zowel e-mail als wachtwoord in.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM chauffeurs WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['wachtwoord'])) {
            twofa_start_pending_login($user);
            if (!empty($user['twofa_enabled']) && !empty($user['twofa_secret'])) {
                header("Location: 2fa_verify.php");
            } else {
                header("Location: 2fa_setup.php");
            }
            exit();
        } else {
            $error = "Onjuist e-mail of wachtwoord.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo noIndexMetaTag(); ?>
    <title>Login</title>
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

        * {
            box-sizing: border-box;
        }

        body {
            font-family: "Segoe UI", Arial, sans-serif;
            background: linear-gradient(145deg, #fff7f8 0%, #f6f7fb 100%);
            margin: 0;
            min-height: 100vh;
            color: var(--text);
            display: flex;
            flex-direction: column;
            padding: 24px;
        }

        .logo {
            width: 90px;
            height: 90px;
            border-radius: 0;
            box-shadow: none;
            margin-bottom: 20px;
        }

        .header-title {
            text-align: center;
            margin-bottom: 24px;
        }

        .header-title h1 {
            margin: 0;
            color: var(--primary-dark);
            font-size: clamp(1.75rem, 2.4vw, 2.3rem);
        }

        .header-title h2 {
            margin: 8px 0 0;
            color: var(--muted);
            font-size: 1.1rem;
            font-weight: 500;
        }

        .page {
            width: 100%;
            max-width: 420px;
            margin: auto;
            text-align: center;
        }

        .login-container {
            width: 100%;
            text-align: left;
            background: var(--surface);
            border-radius: 16px;
            padding: 28px;
            box-shadow: var(--shadow);
        }

        label {
            display: block;
            margin-top: 16px;
            font-weight: 600;
            color: #374151;
        }

        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 11px 12px;
            margin-top: 6px;
            border-radius: 10px;
            border: 1px solid var(--border);
            font-size: 1rem;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        input[type="email"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(253, 164, 175, 0.35);
        }

        input[type="submit"] {
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
            transition: transform 0.12s ease, background-color 0.2s ease;
        }

        input[type="submit"]:hover {
            background-color: var(--primary-dark);
            transform: translateY(-1px);
        }

        .info {
            margin-top: 8px;
            font-size: 0.9em;
            color: var(--muted);
            line-height: 1.45;
        }

        .error {
            color: #9f1239;
            margin-bottom: 8px;
            border: 1px solid #fecdd3;
            background: #fff1f2;
            padding: 10px 12px;
            border-radius: 10px;
            font-size: 0.95rem;
        }

        .forgot-password {
            margin-top: 15px;
            text-align: center;
        }

        .forgot-password a {
            color: var(--primary-dark);
            text-decoration: none;
            font-weight: 600;
        }

        .forgot-password a:hover {
            text-decoration: underline;
        }

        @media (max-width: 600px) {
            body {
                padding: 16px;
            }

            .login-container {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <main class="page">
        <img src="logohome.png" alt="Logo" class="logo">
        <div class="header-title">
            <h1>Afstortverzoeken</h1>
            <h2>Inloggen op het dashboard</h2>
        </div>
        <div class="login-container">
            <?php if (!empty($error)): ?>
                <div class="error" role="alert"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <form method="post" action="" novalidate>
                <label for="email">E-mail</label>
                <input type="email" id="email" name="email" required autocomplete="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" autofocus>
                <label for="password">Wachtwoord</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
                <div class="info">Na je wachtwoord volgt een controlecode uit je authenticator-app.</div>
                <input type="submit" value="Inloggen">
            </form>
            <div class="forgot-password">
                <a href="wachtwoord_vergeten.php">Wachtwoord vergeten?</a>
            </div>
        </div>
    </main>
</body>
</html>

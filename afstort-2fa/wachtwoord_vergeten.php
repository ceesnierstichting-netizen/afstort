<?php
// wachtwoord_vergeten.php — formulier voor wachtwoordherstel
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
    <meta name="googlebot" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
    <title>Wachtwoord vergeten</title>
    <link rel="icon" type="image/png" href="favicon.png">
    <style>
        :root {
            --primary: #c8102e;
            --primary-dark: #a00e26;
            --surface: #ffffff;
            --text: #1f2937;
            --muted: #6b7280;
            --border: #d1d5db;
            --shadow: 0 16px 45px rgba(17, 24, 39, 0.12);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            padding: 24px;
            background: linear-gradient(145deg, #fff7f8 0%, #f6f7fb 100%);
            font-family: "Segoe UI", Arial, sans-serif;
            color: var(--text);
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .page {
            width: 100%;
            max-width: 420px;
            text-align: center;
        }

        .logo {
            width: 90px;
            height: 90px;
            border-radius: 14px;
            box-shadow: 0 10px 18px rgba(0, 0, 0, 0.12);
            margin-bottom: 20px;
        }

        h1 {
            margin: 0;
            color: var(--primary-dark);
            font-size: clamp(1.6rem, 2.4vw, 2.2rem);
        }

        .subtitle {
            margin: 8px 0 24px;
            color: var(--muted);
            font-size: 1rem;
        }

        .container {
            background-color: var(--surface);
            width: 100%;
            padding: 24px;
            border-radius: 16px;
            box-shadow: var(--shadow);
            text-align: left;
        }

        .container h2 {
            margin: 0 0 8px;
            color: #111827;
        }

        .helper {
            margin: 0 0 14px;
            color: var(--muted);
            line-height: 1.45;
            font-size: 0.95rem;
        }

        label {
            font-weight: 600;
            display: block;
            margin-top: 10px;
            color: #374151;
        }

        input[type="email"],
        input[type="submit"] {
            width: 100%;
            padding: 11px 12px;
            margin-top: 8px;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-size: 1rem;
        }

        input[type="email"]:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(253, 164, 175, 0.35);
        }

        input[type="submit"] {
            margin-top: 16px;
            border: none;
            background-color: var(--primary);
            color: #fff;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.12s ease, background-color 0.2s ease;
        }

        input[type="submit"]:hover {
            background-color: var(--primary-dark);
            transform: translateY(-1px);
        }

        .back-link {
            display: block;
            text-align: center;
            margin-top: 16px;
            font-weight: 600;
        }

        .back-link a {
            text-decoration: none;
            color: var(--primary-dark);
        }

        .back-link a:hover {
            text-decoration: underline;
        }

        @media (max-width: 600px) {
            body {
                padding: 16px;
            }

            .container {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <main class="page">
        <img src="logohome.png" alt="Logo" class="logo">
        <h1>Afhaalopdrachten</h1>
        <p class="subtitle">Herstel je wachtwoord</p>

        <div class="container">
            <h2>Wachtwoord vergeten</h2>
            <p class="helper">Vul je e-mailadres in. Je ontvangt daarna een link om je wachtwoord opnieuw in te stellen.</p>
            <form method="post" action="reset_link_mailen.php">
                <label for="email">E-mailadres</label>
                <input type="email" name="email" id="email" required placeholder="jouw@email.nl" autocomplete="email" autofocus>
                <input type="submit" value="Verzend resetlink">
            </form>
            <div class="back-link">
                <a href="login.php">← Terug naar inloggen</a>
            </div>
        </div>
    </main>
</body>
</html>

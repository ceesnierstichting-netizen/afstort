<?php
require_once('session.php');
require_once('config.php');

if (empty($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

$codes = $_SESSION['new_recovery_codes'] ?? null;
unset($_SESSION['new_recovery_codes']);

if (!is_array($codes) || count($codes) === 0) {
    header("Location: index.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo noIndexMetaTag(); ?>
    <title>Herstelcodes</title>
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
            max-width: 520px;
            margin: auto;
            background: var(--surface);
            border-radius: 16px;
            padding: 28px;
            box-shadow: var(--shadow);
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

        .codes {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin: 18px 0;
        }

        code {
            display: block;
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #f9fafb;
            font-family: Consolas, Monaco, monospace;
            text-align: center;
        }

        .actions {
            display: flex;
            gap: 10px;
            margin-top: 18px;
        }

        button,
        a.button {
            flex: 1;
            padding: 12px;
            background-color: var(--primary);
            color: white;
            border: none;
            cursor: pointer;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            text-align: center;
            text-decoration: none;
        }

        button:hover,
        a.button:hover {
            background-color: var(--primary-dark);
        }

        @media (max-width: 520px) {
            .codes { grid-template-columns: 1fr; }
            .actions { flex-direction: column; }
        }
    </style>
</head>
<body>
    <main>
        <h1>Bewaar je herstelcodes</h1>
        <p>Deze codes worden maar een keer getoond. Gebruik ze alleen als je authenticator-app niet beschikbaar is. Elke code werkt een keer.</p>

        <div class="codes">
            <?php foreach ($codes as $code): ?>
                <code><?php echo htmlspecialchars($code); ?></code>
            <?php endforeach; ?>
        </div>

        <div class="actions">
            <button type="button" onclick="window.print()">Printen</button>
            <a class="button" href="index.php">Naar dashboard</a>
        </div>
    </main>
</body>
</html>

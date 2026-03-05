<?php
require_once('config.php');

$token = $_GET['token'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'];
    $wachtwoord = $_POST['wachtwoord'];

    $stmt = $pdo->prepare("SELECT * FROM wachtwoord_resets WHERE token = ? AND expires_at > NOW()");
    $stmt->execute([$token]);
    $reset = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reset) {
        die("Ongeldige of verlopen resetlink.");
    }

    $hashed = password_hash($wachtwoord, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE chauffeurs SET wachtwoord = ? WHERE email = ?");
    $stmt->execute([$hashed, $reset['email']]);

    $pdo->prepare("DELETE FROM wachtwoord_resets WHERE email = ?")->execute([$reset['email']]);

    echo "Je wachtwoord is aangepast. Je kunt nu <a href='login.php'>inloggen</a>.";
    exit;
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Nieuw wachtwoord instellen</title>
    <link rel="icon" type="image/png" href="favicon.png"> 
    <style>
        body {
            background: url('logohome.png') no-repeat 25px 25px;
            background-size: 125px 125px;
            font-family: Arial, sans-serif;
            background-color: #f8f5f0;
            margin: 20px;
        }
        h1 {
            text-align: center;
            color: #c8102e;
            margin-top: 20px;
        }
        .container {
            background-color: #ffffff;
            width: 360px;
            margin: 40px auto;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        label {
            font-weight: bold;
            display: block;
            margin-top: 10px;
        }
        input[type="password"], input[type="submit"] {
            width: 100%;
            padding: 10px;
            margin-top: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        input[type="submit"] {
            background-color: #c8102e;
            color: #fff;
            font-weight: bold;
            cursor: pointer;
        }
        input[type="submit"]:hover {
            background-color: #a00e26;
        }
        small {
            display: block;
            margin-top: 5px;
            font-size: 0.9em;
            color: #555;
        }
        .back-link {
            display: block;
            text-align: center;
            margin-top: 15px;
        }
        .back-link a {
            text-decoration: none;
            color: #c8102e;
        }
    </style>
</head>
<body>
    <h1>Dashboard afhaalopdrachten 2025</h1>
    <div class="container">
        <h2>Nieuw wachtwoord instellen</h2>
        <form method="post">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
            <label for="wachtwoord">Nieuw wachtwoord:</label>
            <input type="password" name="wachtwoord" id="wachtwoord" required>
            <small>Het wachtwoord moet bestaan uit minimaal 12 karakters, waarvan minimaal 1 letterteken en 1 cijfer.</small>
            <input type="submit" value="Opslaan">
        </form>
        <div class="back-link">
            <a href="login.php">← Terug naar inloggen</a>
        </div>
    </div>
</body>
</html>

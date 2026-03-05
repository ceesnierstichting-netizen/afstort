<?php
session_start();
require_once('config.php');

if (isset($_SESSION['fullAccess'])) {
    header("Location: index.php");
    exit();
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
            $_SESSION['username']   = $user['naam'];
            $_SESSION['fullAccess'] = (bool)$user['fullAccess'];
            header("Location: index.php");
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
    <title>Login</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f8f5f0;
            margin: 20px;
        }
        .logo {
            position: absolute;
            top: 25px;
            left: 25px;
            width: 125px;
            height: 125px;
        }
        .header-title {
            text-align: center;
            margin-top: 20px;
        }
        .header-title h1 {
            margin: 0;
            color: #a00e26;
            font-size: 2em;
        }
        .header-title h2 {
            margin: 5px 0 20px 0;
            font-size: 1.5em;
        }
        .login-container {
            width: 300px;
            margin: 0 auto;
            text-align: left;
        }
        label {
            display: block;
            margin-top: 10px;
        }
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 8px;
            margin-top: 5px;
        }
        input[type="submit"] {
            margin-top: 15px;
            padding: 10px;
            width: 100%;
            background-color: #c8102e;
            color: white;
            border: none;
            cursor: pointer;
        }
        input[type="submit"]:hover {
            background-color: #a00e26;
        }
        .info {
            margin-top: 5px;
            font-size: 0.9em;
            color: #333;
        }
        .error {
            color: red;
            margin-top: 10px;
            text-align: center;
        }
        .forgot-password {
            text-align: center;
            margin-top: 15px;
        }
        .forgot-password a {
            color: #a00e26;
            text-decoration: none;
        }
        .forgot-password a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <img src="logohome.png" alt="Logo" class="logo">
    <div class="header-title">
        <h1>Afhaalopdrachten</h1>
        <h2>Login</h2>
    </div>
    <div class="login-container">
        <?php if (!empty($error)): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <form method="post" action="">
            <label for="email">E-mail</label>
            <input type="email" id="email" name="email" required>
            <label for="password">Wachtwoord</label>
            <input type="password" id="password" name="password" required>
            <div class="info">Vul hier jouw e-mailadres en wachtwoord in om in te loggen.</div>
            <input type="submit" value="Inloggen">
        </form>
        <div class="forgot-password">
            <a href="wachtwoord_vergeten.php">Wachtwoord vergeten?</a>
        </div>
    </div>
</body>
</html>

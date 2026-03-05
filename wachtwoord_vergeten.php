<?php
// wachtwoord_vergeten.php — formulier voor wachtwoordherstel
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Wachtwoord vergeten</title>
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
        input[type="email"], input[type="submit"] {
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
        <h2>Wachtwoord vergeten</h2>
        <form method="post" action="reset_link_mailen.php">
            <label for="email">Vul je e-mailadres in:</label>
            <input type="email" name="email" id="email" required placeholder="jouw@email.nl">
            <input type="submit" value="Verzend resetlink">
        </form>
        <div class="back-link">
            <a href="login.php">← Terug naar inloggen</a>
        </div>
    </div>
</body>
</html>

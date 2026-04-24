<?php
require_once('session.php');

// Maak alle sessievariabelen leeg
$_SESSION = [];

// Verwijder de sessiecookie als deze is ingesteld
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Vernietig de sessie
session_destroy();

// Doorsturen naar loginpagina
header("Location: login.php");
exit();
?>

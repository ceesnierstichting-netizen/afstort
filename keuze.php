<?php
require_once __DIR__ . '/session.php';
if (empty($_SESSION['username']) || empty($_SESSION['twofa_verified'])) {
    header('Location: login.php');
    exit;
}
header('Cache-Control: no-store');
header('Location: index2.php');
exit;

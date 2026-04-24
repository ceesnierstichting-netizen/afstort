<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$to      = $_POST['to'] ?? '';
$subject = $_POST['subject'] ?? '';
$body    = $_POST['body'] ?? '';
$from    = $_POST['from'] ?? '';

if(empty($to) || empty($subject) || empty($body)){
    echo "Ontbrekende vereiste velden.";
    exit;
}

$headers = "From: " . $from . "\r\n" .
           "Reply-To: " . $from . "\r\n" .
           "Content-Type: text/html; charset=UTF-8\r\n";

if(mail($to, $subject, $body, $headers)){
    echo "E-mail succesvol verzonden.";
} else {
    echo "E-mail verzenden mislukt.";
}
?>

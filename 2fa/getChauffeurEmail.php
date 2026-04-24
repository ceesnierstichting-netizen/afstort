<?php
// getChauffeurEmail.php
require_once('config.php');

if (isset($_GET['naam'])) {
    $naam = $_GET['naam'];
    $stmt = $pdo->prepare("SELECT email FROM chauffeurs WHERE naam = :naam LIMIT 1");
    $stmt->execute([':naam' => $naam]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        echo json_encode(['email' => $result['email']]);
    } else {
        echo json_encode(['email' => '']);
    }
} else {
    echo json_encode(['email' => '']);
}
?>

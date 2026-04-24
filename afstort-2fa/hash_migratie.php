<?php
require_once('config.php');

try {
    $stmt = $pdo->query("SELECT id, wachtwoord FROM chauffeurs");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($users as $user) {
        $id = $user['id'];
        $plainPassword = $user['wachtwoord'];

        // Forceer hashing ongeacht huidige vorm
        $hashed = password_hash($plainPassword, PASSWORD_DEFAULT);

        $update = $pdo->prepare("UPDATE chauffeurs SET wachtwoord = ? WHERE id = ?");
        $update->execute([$hashed, $id]);

        echo "Gebruiker ID $id: wachtwoord gehashed.<br>";
    }

    echo "<br>Klaar! De wachtwoorden zijn nu gehashed. Verwijder dit script direct van de server.";
} catch (Exception $e) {
    echo "Fout bij updaten: " . $e->getMessage();
}

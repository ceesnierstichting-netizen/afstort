<?php
session_start();

// Controleer of de gebruiker is ingelogd
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

$fullAccess = $_SESSION['fullAccess'] ?? false;
$username   = $_SESSION['username'];
require_once('config.php');

// Haal alleen afgeronde ritten op (status "Afgehandeld")
if ($fullAccess) {
    $query = "SELECT * FROM ritten WHERE status = 'Afgehandeld' ORDER BY chauffeur, afhaalmoment";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $ritten = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $query = "SELECT * FROM ritten WHERE status = 'Afgehandeld' AND chauffeur = :username ORDER BY afhaalmoment";
    $stmt = $pdo->prepare($query);
    $stmt->execute([':username' => $username]);
    $ritten = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <?php echo noIndexMetaTag(); ?>
  <title>Rapport</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .header { text-align: center; margin-bottom: 20px; }
    .header h1 { font-size: 2em; margin: 0; }
    .header h2 { font-size: 1.5em; margin: 5px 0 20px; }
    .section { margin-bottom: 40px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #f2f2f2; }
    .total { font-weight: bold; }
    .small { font-size: 10px; text-align: center; margin-top: 20px; }
  </style>
</head>
<body>
<?php
if (!$fullAccess) {
    // Voor een ingelogde chauffeur: toon bovenaan naam en IBAN
    $stmtIban = $pdo->prepare("SELECT IBAN FROM chauffeurs WHERE naam = :naam");
    $stmtIban->execute([':naam' => $username]);
    $resultIban = $stmtIban->fetch(PDO::FETCH_ASSOC);
    $iban = $resultIban['IBAN'] ?? '';
    echo "<div class='header'><h1>$username</h1><h2>$iban</h2></div>";
    
    if (!empty($ritten)) {
        $totalGestort   = 0;
        $totalKilometers = 0;
        $totalDeclarabel = 0;
        echo "<table>";
        echo "<tr>
                <th>Collectegebied</th>
                <th>Chauffeur</th>
                <th>Afhaalmoment</th>
                <th>Soort</th>
                <th>Gestort</th>
                <th>Gereden kilometers</th>
                <th>Declarabel</th>
              </tr>";
        foreach ($ritten as $rit) {
            $gestort    = floatval($rit['gestort']);
            $kilometers = floatval($rit['gereden']);
            $declarabel = $kilometers * 0.3;
            $totalGestort   += $gestort;
            $totalKilometers += $kilometers;
            $totalDeclarabel += $declarabel;
            echo "<tr>
                    <td>{$rit['collectegebied']}</td>
                    <td>{$rit['chauffeur']}</td>
                    <td>{$rit['afhaalmoment']}</td>
                    <td>{$rit['soort']}</td>
                    <td>$gestort</td>
                    <td>$kilometers</td>
                    <td>€" . number_format($declarabel, 2, ",", ".") . "</td>
                  </tr>";
        }
        // Totaalrij
        echo "<tr class='total'>
                <td colspan='4'>Totaal</td>
                <td>$totalGestort</td>
                <td>$totalKilometers</td>
                <td>€" . number_format($totalDeclarabel, 2, ",", ".") . "</td>
              </tr>";
        echo "</table>";
    } else {
        echo "<p>Geen afgeronde ritten gevonden.</p>";
    }
} else {
    // Voor full access: groepeer ritten per chauffeur
    if (!empty($ritten)) {
        $grouped = [];
        foreach ($ritten as $rit) {
            $chauffeur = $rit['chauffeur'] ?: 'Onbekend';
            $grouped[$chauffeur][] = $rit;
        }
        foreach ($grouped as $chauffeur => $rittenChauffeur) {
            // Haal IBAN op voor de chauffeur
            $stmtIban = $pdo->prepare("SELECT IBAN FROM chauffeurs WHERE naam = :naam");
            $stmtIban->execute([':naam' => $chauffeur]);
            $resultIban = $stmtIban->fetch(PDO::FETCH_ASSOC);
            $iban = $resultIban['IBAN'] ?? '';
            
            echo "<div class='section'>";
            echo "<div class='header'><h1>$chauffeur</h1><h2>$iban</h2></div>";
            echo "<table>";
            echo "<tr>
                    <th>Collectegebied</th>
                    <th>Chauffeur</th>
                    <th>Afhaalmoment</th>
                    <th>Soort</th>
                    <th>Gestort</th>
                    <th>Gereden kilometers</th>
                    <th>Declarabel</th>
                  </tr>";
            $totalGestort   = 0;
            $totalKilometers = 0;
            $totalDeclarabel = 0;
            foreach ($rittenChauffeur as $rit) {
                $gestort    = floatval($rit['gestort']);
                $kilometers = floatval($rit['gereden']);
                $declarabel = $kilometers * 0.3;
                $totalGestort   += $gestort;
                $totalKilometers += $kilometers;
                $totalDeclarabel += $declarabel;
                echo "<tr>
                        <td>{$rit['collectegebied']}</td>
                        <td>{$rit['chauffeur']}</td>
                        <td>{$rit['afhaalmoment']}</td>
                        <td>{$rit['soort']}</td>
                        <td>$gestort</td>
                        <td>$kilometers</td>
                        <td>€" . number_format($declarabel, 2, ",", ".") . "</td>
                      </tr>";
            }
            // Totaalrij voor deze chauffeur
            echo "<tr class='total'>
                    <td colspan='4'>Totaal voor $chauffeur</td>
                    <td>$totalGestort</td>
                    <td>$totalKilometers</td>
                    <td>€" . number_format($totalDeclarabel, 2, ",", ".") . "</td>
                  </tr>";
            echo "</table>";
            echo "</div>";
        }
    } else {
        echo "<p>Geen afgeronde ritten gevonden.</p>";
    }
}
?>
<div class="small">
  <?php
    echo "Rapport URL: " . $_SERVER['REQUEST_URI'] . "<br>";
    echo "Rapport aangemaakt op: " . date("d-m-Y H:i:s");
  ?>
</div>
</body>
</html>

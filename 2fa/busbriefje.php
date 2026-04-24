<?php
// busbriefje.php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once('config.php');

if (!isset($_GET['id'])) {
    die("Geen rit-ID opgegeven.");
}

$id = intval($_GET['id']);
$stmt = $pdo->prepare("SELECT collectegebied, wijknaam, gebiedsnummer FROM ritten WHERE id = ?");
$stmt->execute([$id]);
$ride = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ride) {
    die("Rit niet gevonden.");
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <?php echo noIndexMetaTag(); ?>
  <title>Busbriefje - 10x op A4</title>
  <style>
    /* Pagina instellen op A4 met marges */
    @page {
      size: A4;
      margin: 10mm;
    }
    body {
      margin: 0;
      padding: 0;
      font-family: Arial, sans-serif;
    }
    /* Gebruik een grid met 2 kolommen en 5 rijen voor 10 exemplaren */
    .container {
      display: grid;
      grid-template-columns: 1fr 1fr;
      grid-template-rows: repeat(5, 1fr);
      gap: 5mm;
      height: calc(297mm - 20mm);
      box-sizing: border-box;
      padding: 0 10mm;
    }
    .briefje {
      border: 1px solid #ddd;
      padding: 3mm;
      text-align: center;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
    }
    .briefje img.logo {
      width: 125px;
      height: 80px;
      object-fit: contain;
      margin-bottom: 2mm;
    }
    .briefje .title {
      font-size: 24px;
      font-weight: bold;
      margin-bottom: 1mm;
    }
    .briefje .placeholder {
      font-size: 24px;
      margin-bottom: 1mm;
    }
  </style>
</head>
<body>
  <div class="container">
    <?php for ($i = 0; $i < 10; $i++): ?>
      <div class="briefje">
        <img src="logobriefje.png" alt="Logo" class="logo">
        <div class="title">Collecte 2025</div>
        <!-- Vervang de placeholders met de waarden uit de database -->
        <div class="placeholder"><?php echo htmlspecialchars($ride['collectegebied']); ?></div>
        <div class="placeholder"><?php echo htmlspecialchars($ride['wijknaam']); ?></div>
        <div class="placeholder"><?php echo htmlspecialchars($ride['gebiedsnummer']); ?></div>
      </div>
    <?php endfor; ?>
  </div>
</body>
</html>

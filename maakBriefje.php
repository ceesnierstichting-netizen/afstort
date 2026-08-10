<?php
// maakBriefje.php

// Sessies niet starten en geen inlogcontrole uitvoeren zodat de pagina publiek toegankelijk is.
// Indien gewenst kun je hier extra controle toevoegen (bijvoorbeeld een token of een hash) om misbruik te voorkomen.

//require_once('config.php'); // Zorg ervoor dat je $pdo en andere config-variabelen nog steeds nodig hebt.
require_once('config.php');

// Helperfuncties voor datum- en tijdopmaak
function formatDatum($datum) {
    setlocale(LC_TIME, 'nl_NL.UTF-8');
    $timestamp = strtotime($datum);
    return strftime('%A %d %B %Y', $timestamp);
}

function formatTijd($tijd) {
    $formatted = str_replace(':', '.', substr($tijd, 0, 5));
    return $formatted . ' uur';
}

// Haal de rit-id op via GET
if (isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM ritten WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $data = $stmt->fetch();
    if (!$data) {
        die("Geen rit gevonden met het opgegeven ID.");
    }
} else {
    die("Geen rit ID opgegeven. Geef bijvoorbeeld in de URL: maakBriefje.php?id=123");
}

// Bepaal het gecombineerde veld voor Collectegebied en Wijknaam
$collecteEnWijk = trim($data['collectegebied'] . ' ' . ($data['wijknaam'] ?? ''));
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <?php echo noIndexMetaTag(); ?>
  <title>Briefje: Afhaal Collecte-opbrengst</title>
  <style>
    /* Zorg dat de pagina bij printen in landscape staat zonder marges */
    @page {
      size: landscape;
      margin: 0;
    }
    
    body { font-family: Arial, sans-serif; margin: 20px; }
    
    /* Printknop: gecentreerd, groter, rood met witte letters */
    .print-button {
      display: block;
      margin: 20px auto;
      padding: 15px 30px;
      background-color: red;
      color: white;
      font-size: 18px;
      border: none;
      border-radius: 5px;
      cursor: pointer;
    }
    
    .container {
      display: flex;
      gap: 20px;
    }
    .form-section { 
      flex: 1;
      position: relative;
      border: 1px solid #000; 
      padding: 10px;
      box-sizing: border-box;
      /* Achtergrondlogo rechtsboven */
      background-image: url('logobriefje.png');
      background-position: calc(100% - 10px) 0;
      background-size: 110px auto;
      background-repeat: no-repeat;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    .form-section h2 { 
      text-align: center; 
      margin-top: 0;
      margin-bottom: 12px;
      font-size: 14pt; /* 2 punten kleiner */
    }
    .form-section h2 br { display: block; }
    .field { margin-bottom: 6px; }
    .field label { display: inline-block; width: 140px; font-weight: bold; }
    .declaration { 
      font-size: 0.9em; 
      margin-top: 8px;
      text-align: center; 
      font-style: italic;
    }
    .declaration p { margin: 0; }
    .pre-declaration-space { height: 0.6em; }
    .extra-space { height: 0.7em; }
    .signature-container {
      display: flex;
      justify-content: space-around;
      margin-top: 12px;
    }
    .signature-item {
      text-align: center;
      width: 45%;
    }
    /* Handtekeningsectie: eerst "Handtekening", dan de rol, dan de naam */
    .handtekening-title { margin-bottom: 0; }
    /* Iets compactere handtekeningruimte zodat de langere tekst op A4 blijft */
    .handtekening-break { margin-bottom: 0; }
    .exemplaar {
      text-align: center;
      margin-top: 12px;
      font-style: italic;
    }
    /* Separator met verticale stippellijn en schaartje (schaartje omgedraaid met een kwartslag) */
    .separator {
      width: 1px;
      border-right: 1px dotted #000;
      position: relative;
    }
    .separator::after {
      content: "✂";
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%) rotate(-90deg);
      font-size: 20px;
    }
    @media print {
      .print-button { display: none; }
    }
  </style>
</head>
<body>
  <button class="print-button" onclick="window.print()">Print deze pagina</button>
  <div class="container">
    <!-- Kopie 1: Briefje 1 (links) -->
    <div class="form-section">
      <h2>Bevestiging afhaal<br>collecte-opbrengst</h2>
      <div class="field"><label>Collectegebied:</label> <?php echo htmlspecialchars($collecteEnWijk); ?></div>
      <div class="field"><label>Contactpersoon:</label> <?php echo htmlspecialchars($data['contactpersoon']); ?></div>
      <div class="field"><label>Adres:</label> <?php echo htmlspecialchars($data['adres']); ?></div>
      <div class="field"><label>Postcode/Plaats:</label> <?php echo htmlspecialchars($data['postcodePlaats']); ?></div>
      <div class="field"><label>Telefoon:</label> <?php echo htmlspecialchars($data['telefoonnummer']); ?></div>
      <div class="field"><label>E-mail:</label> <?php echo htmlspecialchars($data['email']); ?></div>
      <div class="field">
        <label>Verwacht bedrag:</label> <?php echo htmlspecialchars($data['verwachtBedrag']) . ' euro'; ?>
      </div>
      <div class="field"><label>Soort:</label> <?php echo htmlspecialchars($data['soort']); ?></div>
      <div class="field">
        <label>Afhaaldag/tijd:</label> 
        <?php echo formatDatum($data['afhaalmoment']) . ' / ' . formatTijd($data['afhaaltijd']); ?>
      </div>
      <div class="pre-declaration-space"></div>
      <div class="declaration">
        <p><strong>Ondergetekenden verklaren hierbij dat de bovengenoemde, collecteopbrengst door de contactpersoon is overgedragen en door de afstortvrijwilliger is ontvangen. Deze opbrengst zal nadat deze geteld is door de geldtelmachine van Geldmaat pas als officiele opbrengst worden geregistreerd.</strong></p>
      </div>
      <div class="extra-space"></div>
      <div class="signature-container">
        <div class="signature-item">
          <p class="handtekening-title">
            Handtekening<br>
            Afstortvrijwilliger<br>
            <?php echo htmlspecialchars($data['chauffeur']); ?>
          </p>
          <br><br><br>
          <p class="handtekening-break">_____________________</p>
        </div>
        <div class="signature-item">
          <p class="handtekening-title">
            Handtekening<br>
            Contactpersoon<br>
            <?php echo htmlspecialchars($data['contactpersoon']); ?>
          </p>
          <br><br><br>
          <p class="handtekening-break">_____________________</p>
        </div>
      </div>
      <div class="exemplaar">– Exemplaar voor <?php echo htmlspecialchars($data['chauffeur']); ?> –</div>
    </div>
    <!-- Separator -->
    <div class="separator"></div>
    <!-- Kopie 2: Briefje 2 (rechts) -->
    <div class="form-section">
      <h2>Bevestiging afhaal<br>collecte-opbrengst</h2>
      <div class="field"><label>Collectegebied:</label> <?php echo htmlspecialchars($collecteEnWijk); ?></div>
      <div class="field"><label>Contactpersoon:</label> <?php echo htmlspecialchars($data['contactpersoon']); ?></div>
      <div class="field"><label>Adres:</label> <?php echo htmlspecialchars($data['adres']); ?></div>
      <div class="field"><label>Postcode/Plaats:</label> <?php echo htmlspecialchars($data['postcodePlaats']); ?></div>
      <div class="field"><label>Telefoon:</label> <?php echo htmlspecialchars($data['telefoonnummer']); ?></div>
      <div class="field"><label>E-mail:</label> <?php echo htmlspecialchars($data['email']); ?></div>
      <div class="field">
        <label>Verwacht bedrag:</label> <?php echo htmlspecialchars($data['verwachtBedrag']) . ' euro'; ?>
      </div>
      <div class="field"><label>Soort:</label> <?php echo htmlspecialchars($data['soort']); ?></div>
      <div class="field">
        <label>Afhaaldag/tijd:</label>
        <?php echo formatDatum($data['afhaalmoment']) . ' / ' . formatTijd($data['afhaaltijd']); ?>
      </div>
      <div class="pre-declaration-space"></div>
      <div class="declaration">
        <p><strong>Ondergetekenden verklaren hierbij dat de bovengenoemde, collecteopbrengst door de contactpersoon is overgedragen en door de afstortvrijwilliger is ontvangen. Deze opbrengst zal nadat deze geteld is door de geldtelmachine van Geldmaat pas als officiele opbrengst worden geregistreerd.</strong></p>
      </div>
      <div class="extra-space"></div>
      <div class="signature-container">
        <div class="signature-item">
          <p class="handtekening-title">
            Handtekening<br>
            Afstortvrijwilliger<br>
            <?php echo htmlspecialchars($data['chauffeur']); ?>
          </p>
          <br><br><br>
          <p class="handtekening-break">_____________________</p>
        </div>
        <div class="signature-item">
          <p class="handtekening-title">
            Handtekening<br>
            Contactpersoon<br>
            <?php echo htmlspecialchars($data['contactpersoon']); ?>
          </p>
          <br><br><br>
          <p class="handtekening-break">_____________________</p>
        </div>
      </div>
      <div class="exemplaar">– Exemplaar voor <?php echo htmlspecialchars($data['contactpersoon']); ?> –</div>
    </div>
  </div>
</body>
</html>

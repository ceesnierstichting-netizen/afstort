<?php
// form.php
session_start();
if (!headers_sent()) {
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex', true);
}
// Hier kun je indien nodig de database connectie en data ophalen, bijvoorbeeld:
$data = [
    'collectegebied'   => 'Voorbeeldgebied',
    'contact'          => 'Jan Jansen',
    'adres'            => 'Voorbeeldstraat 1',
    'postcodePlaats'   => '1234 AB Plaatsnaam',
    'telefoonnummer'   => '012-3456789',
    'email'            => 'jan@example.com',
    'verwachtBedrag'   => '€100,00',
    'ssort'            => 'Contant',
    'afhaalmoment'     => 'Vrijdag',
    'afhaaltijd'       => '14:00',
    'chauffeur'        => 'Piet de Vries',
    'contactpersoon'   => 'Jan Jansen'
];
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
  <meta name="googlebot" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
  <title>Afhaal Collecte-opbrengst Formulier</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .print-button { margin-bottom: 20px; }
    .container { display: flex; flex-wrap: wrap; justify-content: space-between; }
    .form-section { 
      border: 1px solid #000; 
      padding: 10px; 
      width: 48%; 
      box-sizing: border-box; 
      margin-bottom: 20px;
    }
    .form-section h2 { text-align: center; margin-top: 0; }
    .field { margin-bottom: 8px; }
    .field label { display: inline-block; width: 140px; font-weight: bold; }
    .signature { margin-top: 20px; }
    .signature div { margin-bottom: 10px; }
    .declaration { font-size: 0.9em; margin-top: 15px; }
    @media print {
      .print-button { display: none; }
    }
  </style>
</head>
<body>
  <button class="print-button" onclick="window.print()">Print deze pagina</button>
  <div class="container">
    <!-- Eerste kopie -->
    <div class="form-section">
      <h2>Bevestiging afhaal collecte-opbrengst</h2>
      <div class="field"><label>Collectegebied:</label> <?php echo htmlspecialchars($data['collectegebied']); ?></div>
      <div class="field"><label>Contactpersoon:</label> <?php echo htmlspecialchars($data['contact']); ?></div>
      <div class="field"><label>Adres:</label> <?php echo htmlspecialchars($data['adres']); ?></div>
      <div class="field"><label>Postcode/Plaats:</label> <?php echo htmlspecialchars($data['postcodePlaats']); ?></div>
      <div class="field"><label>Telefoon:</label> <?php echo htmlspecialchars($data['telefoonnummer']); ?></div>
      <div class="field"><label>E-mail:</label> <?php echo htmlspecialchars($data['email']); ?></div>
      <div class="field"><label>Verwacht bedrag:</label> <?php echo htmlspecialchars($data['verwachtBedrag']); ?></div>
      <div class="field"><label>Soort:</label> <?php echo htmlspecialchars($data['ssort']); ?></div>
      <div class="field"><label>Afhaaldag/tijd:</label> <?php echo htmlspecialchars($data['afhaalmoment'] . ' / ' . $data['afhaaltijd']); ?></div>
      <div class="signature">
        <div>Handtekening afstort vrijwilliger: ___________________ <?php echo htmlspecialchars($data['chauffeur']); ?></div>
        <div>Handtekening Contactpersoon: ___________________ <?php echo htmlspecialchars($data['contactpersoon']); ?></div>
      </div>
      <div class="declaration">
        <p>Ondergetekenden verklaren hierbij dat de bovengenoemde collecteopbrengst door de contactpersoon is overgedragen en door de afstortvrijwilliger is ontvangen.</p>
        <p>–  Exemplaar voor contactpersoon  –</p>
      </div>
    </div>
    <!-- Tweede kopie -->
    <div class="form-section">
      <h2>Bevestiging afhaal collecte-opbrengst</h2>
      <div class="field"><label>Collectegebied:</label> <?php echo htmlspecialchars($data['collectegebied']); ?></div>
      <div class="field"><label>Contactpersoon:</label> <?php echo htmlspecialchars($data['contact']); ?></div>
      <div class="field"><label>Adres:</label> <?php echo htmlspecialchars($data['adres']); ?></div>
      <div class="field"><label>Postcode/Plaats:</label> <?php echo htmlspecialchars($data['postcodePlaats']); ?></div>
      <div class="field"><label>Telefoon:</label> <?php echo htmlspecialchars($data['telefoonnummer']); ?></div>
      <div class="field"><label>E-mail:</label> <?php echo htmlspecialchars($data['email']); ?></div>
      <div class="field"><label>Verwacht bedrag:</label> <?php echo htmlspecialchars($data['verwachtBedrag']); ?></div>
      <div class="field"><label>Soort:</label> <?php echo htmlspecialchars($data['ssort']); ?></div>
      <div class="field"><label>Afhaaldag/tijd:</label> <?php echo htmlspecialchars($data['afhaalmoment'] . ' / ' . $data['afhaaltijd']); ?></div>
      <div class="signature">
        <div>Handtekening afstort vrijwilliger: ___________________ <?php echo htmlspecialchars($data['chauffeur']); ?></div>
        <div>Handtekening Contactpersoon: ___________________ <?php echo htmlspecialchars($data['contactpersoon']); ?></div>
      </div>
      <div class="declaration">
        <p>Ondergetekenden verklaren hierbij dat de bovengenoemde collecteopbrengst door de contactpersoon is overgedragen en door de afstortvrijwilliger is ontvangen.</p>
        <p>–  Exemplaar voor contactpersoon  –</p>
      </div>
    </div>
  </div>
</body>
</html>

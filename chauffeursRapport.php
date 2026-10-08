<?php
require_once __DIR__ . '/session.php';
afstort_require_login();
require_once __DIR__ . '/config.php';
refreshCurrentUserAccess($pdo);

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');
if (!hasDashboardAccess($_SESSION)) {
    http_response_code(403);
    exit('Dit rapport is alleen beschikbaar voor Medewerkers en Admin.');
}

require_once __DIR__ . '/postcode_places.php';
require_once __DIR__ . '/portal_settings.php';
portal_settings_schema($pdo);
$settings = portal_settings($pdo);
$year = filter_var($_GET['year'] ?? $settings['currentYear'], FILTER_VALIDATE_INT);
if (!$year || !isset($settings['years'][$year])) { http_response_code(422); exit('Onbekend collectejaar.'); }
$chauffeurs = $pdo->query("SELECT naam, email, postcode, IBAN, mobiel, beschikbare_jaren FROM chauffeurs
    WHERE COALESCE(is_medewerker, 0) = 0 AND COALESCE(fullAccess, 0) = 0 AND naam <> 'Admin'
    ORDER BY naam ASC")->fetchAll(PDO::FETCH_ASSOC);
$chauffeurs = array_values(array_filter($chauffeurs, fn($row) => in_array($year, json_decode($row['beschikbare_jaren'] ?? '[]', true) ?: [], true)));
function chauffeursRapportEscape($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Chauffeursrapport</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 24px; color: #222; }
    h1 { margin-bottom: 8px; }
    .muted { color: #666; }
    .table-wrap { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; margin-top: 24px; }
    th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
    th { background: #f2f2f2; }
    button { padding: 10px 16px; cursor: pointer; }
    @media print { button { display: none; } body { margin: 0; } .table-wrap { overflow: visible; } thead { display: table-header-group; } tr { break-inside: avoid; } }
    @page { size: A4 landscape; margin: 12mm; }
  </style>
</head>
<body>
  <h1>Chauffeursrapport</h1>
  <p class="muted">Collectejaar <?= $year ?> · <?= count($chauffeurs) ?> actieve chauffeurs · <?= date('d-m-Y H:i') ?></p>
  <button type="button" onclick="window.print()">Afdrukken / PDF</button>
  <?php if (!$chauffeurs): ?>
    <p>Geen chauffeurs gevonden.</p>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th scope="col">Naam</th><th scope="col">E-mailadres</th><th scope="col">06-nummer</th><th scope="col">Postcode</th><th scope="col">Woonplaats</th><th scope="col">IBAN</th></tr></thead>
      <tbody>
      <?php foreach ($chauffeurs as $chauffeur): ?>
        <tr>
          <td><?= chauffeursRapportEscape($chauffeur['naam']) ?></td>
          <td><?= chauffeursRapportEscape($chauffeur['email']) ?></td>
          <td><?= chauffeursRapportEscape($chauffeur['mobiel']) ?></td>
          <td><?= chauffeursRapportEscape($chauffeur['postcode']) ?></td>
          <td><?= chauffeursRapportEscape(chauffeurWoonplaats($chauffeur['postcode'] ?? '')) ?></td>
          <td><?= chauffeursRapportEscape($chauffeur['IBAN']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</body>
</html>

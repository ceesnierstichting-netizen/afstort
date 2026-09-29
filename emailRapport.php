<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/config.php';

if (empty($_SESSION['username']) || empty($_SESSION['twofa_verified'])) {
    header('Location: login.php');
    exit;
}

refreshCurrentUserAccess($pdo);
if (!canViewEmailRapport($_SESSION)) {
    http_response_code(403);
    echo 'Dit e-mailrapport is alleen beschikbaar voor gebruikers met Full Access.';
    exit;
}

ensureRitAanbiedingenTable($pdo);
ensureRitEmailLogTable($pdo);
ensureRittenAuditColumns($pdo);

$ritten = $pdo->query("
    SELECT id, collectegebied, gebiedsnummer, contactpersoon, postcodePlaats, chauffeur, status,
           aangemaakt_door, aangemaakt_door_email
    FROM ritten
    ORDER BY id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$emailRows = $pdo->query("
    SELECT rit_id, soort, ontvanger, onderwerp, status, melding, verzonden_op
    FROM rit_email_log
    ORDER BY verzonden_op DESC, id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$aanbiedingen = $pdo->query("
    SELECT rit_id, chauffeur_naam, chauffeur_email, status, aangeboden_op, afgewezen_op
    FROM rit_aanbiedingen
    ORDER BY aangeboden_op DESC, id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$eventsPerRit = [];
foreach ($emailRows as $event) {
    $eventsPerRit[(int)$event['rit_id']][] = [
        'datum' => $event['verzonden_op'],
        'soort' => $event['soort'],
        'ontvanger' => $event['ontvanger'],
        'status' => $event['status'],
        'details' => $event['melding'] ?: $event['onderwerp'],
    ];
}
foreach ($aanbiedingen as $aanbieding) {
    $ritId = (int)$aanbieding['rit_id'];
    $eventsPerRit[$ritId][] = [
        'datum' => $aanbieding['aangeboden_op'],
        'soort' => 'Chauffeurvoorstel geregistreerd',
        'ontvanger' => trim((string)$aanbieding['chauffeur_naam'])
            . ($aanbieding['chauffeur_email'] ? ' (' . $aanbieding['chauffeur_email'] . ')' : ''),
        'status' => 'aangeboden',
        'details' => null,
    ];
    if ($aanbieding['status'] === 'afgewezen' && !empty($aanbieding['afgewezen_op'])) {
        $eventsPerRit[$ritId][] = [
            'datum' => $aanbieding['afgewezen_op'],
            'soort' => 'Chauffeurvoorstel afgewezen',
            'ontvanger' => $aanbieding['chauffeur_naam'],
            'status' => 'afgewezen',
            'details' => null,
        ];
    }
}
foreach ($eventsPerRit as &$events) {
    usort($events, static function ($a, $b) {
        return strcmp((string)$b['datum'], (string)$a['datum']);
    });
}
unset($events);

function rapportH($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function rapportDatum($value) {
    if (!$value) return '-';
    $timestamp = strtotime((string)$value);
    return $timestamp ? date('d-m-Y H:i:s', $timestamp) : (string)$value;
}
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <?php echo noIndexMetaTag(); ?>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>E-mailrapport afstortverzoeken</title>
  <style>
    body { margin: 0; padding: 24px; font-family: Arial, sans-serif; color: #18212b; background: #f4f6f8; }
    main { max-width: 1500px; margin: 0 auto; }
    h1 { color: #8b0b27; margin: 0 0 8px; }
    .intro { margin: 0 0 20px; color: #475569; }
    .actions { display: flex; gap: 10px; margin-bottom: 18px; }
    .actions a, .actions button { border: 0; border-radius: 6px; padding: 10px 14px; background: #8b0b27; color: white; text-decoration: none; cursor: pointer; font-weight: 700; }
    .table-wrap { overflow-x: auto; background: white; border-radius: 10px; box-shadow: 0 2px 12px rgba(15,23,42,.08); }
    table { width: 100%; border-collapse: collapse; font-size: 14px; }
    th, td { padding: 11px 12px; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: top; }
    th { position: sticky; top: 0; background: #f8fafc; color: #334155; }
    .rit-start td { border-top: 3px solid #cbd5e1; }
    .muted { color: #64748b; }
    .status-verzonden, .status-aangeboden { color: #166534; font-weight: 700; }
    .status-mislukt, .status-afgewezen { color: #b91c1c; font-weight: 700; }
    @media print { body { padding: 0; background: white; } .actions { display: none; } .table-wrap { box-shadow: none; } th { position: static; } }
  </style>
</head>
<body>
<main>
  <h1>E-mailrapport afstortverzoeken</h1>
  <p class="intro">Per invoerregel staan hieronder de geregistreerde e-mails en chauffeurgebeurtenissen met datum en tijd. Contactmails van vóór de ingebruikname van dit logboek zijn niet achteraf te reconstrueren.</p>
  <div class="actions">
    <a href="index.php">Terug naar overzicht</a>
    <button type="button" onclick="window.print()">Afdrukken / opslaan als PDF</button>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Invoerregel</th><th>Ingevoerd door</th><th>Collectegebied</th><th>Contactpersoon</th><th>Ritstatus</th>
          <th>Datum en tijd</th><th>Gebeurtenis / e-mail</th><th>Ontvanger</th><th>Resultaat</th><th>Details</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($ritten as $rit): ?>
        <?php $events = $eventsPerRit[(int)$rit['id']] ?? []; ?>
        <?php if (!$events): $events = [[ 'datum' => null, 'soort' => 'Nog geen e-mails geregistreerd', 'ontvanger' => '-', 'status' => '-', 'details' => null ]]; endif; ?>
        <?php foreach ($events as $index => $event): ?>
        <tr class="<?php echo $index === 0 ? 'rit-start' : ''; ?>">
          <td>#<?php echo (int)$rit['id']; ?></td>
          <td><?php echo rapportH($rit['aangemaakt_door'] ?: 'Onbekend (bestaande regel)'); ?><?php if (!empty($rit['aangemaakt_door_email'])): ?><br><span class="muted"><?php echo rapportH($rit['aangemaakt_door_email']); ?></span><?php endif; ?></td>
          <td><?php echo rapportH($rit['collectegebied']); ?><br><span class="muted"><?php echo rapportH($rit['gebiedsnummer']); ?> · <?php echo rapportH($rit['postcodePlaats']); ?></span></td>
          <td><?php echo rapportH($rit['contactpersoon']); ?></td>
          <td><?php echo rapportH($rit['status']); ?><br><span class="muted">Chauffeur: <?php echo rapportH($rit['chauffeur']); ?></span></td>
          <td><?php echo rapportH(rapportDatum($event['datum'])); ?></td>
          <td><?php echo rapportH($event['soort']); ?></td>
          <td><?php echo rapportH($event['ontvanger']); ?></td>
          <td class="status-<?php echo rapportH(strtolower((string)$event['status'])); ?>"><?php echo rapportH($event['status']); ?></td>
          <td><?php echo rapportH($event['details']); ?></td>
        </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</main>
</body>
</html>

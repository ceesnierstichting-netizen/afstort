<?php
// Report incomplete uploads before PHP stops with an empty error page.
$requiredFiles = ['session.php', 'config.php', 'app_helpers.php', 'rit_concurrency.php',
    'chauffeur_selectie.php', 'chauffeur_profiel.php', 'portal_settings.php',
    'live2/workflow.php', 'live2/mail.php', 'live2/source.php',
    'live2/controller.php', 'live2/domain.php', 'live2/templates.php'];
$missingFiles = array_values(array_filter($requiredFiles, fn($file) => !is_readable(__DIR__ . '/' . $file)));
if ($missingFiles) {
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    echo '<!doctype html><html lang="nl"><meta charset="utf-8"><title>Portaal niet compleet</title><h1>Er ontbreken bestanden voor het nieuwe portaal</h1><p>Upload deze bestanden met dezelfde mapindeling:</p><ul>';
    foreach ($missingFiles as $file) echo '<li>' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . '</li>';
    echo '</ul><p>Open daarna index2.php opnieuw.</p></html>';
    exit;
}
try {
    require_once __DIR__ . '/live2/controller.php';
} catch (Throwable $error) {
    error_log('Afstort index2 opstarten: ' . $error->getMessage());
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    // Show only the error type and source location; database details stay private.
    $diagnostic = get_class($error) . ' in ' . basename($error->getFile()) . ' op regel ' . $error->getLine();
    echo '<!doctype html><html lang="nl"><meta charset="utf-8"><title>Portaal niet beschikbaar</title><h1>Het nieuwe portaal kan niet starten</h1><p>Geef deze foutcode door: <strong>' . htmlspecialchars($diagnostic, ENT_QUOTES, 'UTF-8') . '</strong></p><p>Controleer of de bestanden voor index2 volledig en samen zijn geüpload.</p></html>';
    exit;
}
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow"><meta name="csrf-token" content="<?= live2_escape(afstort_csrf_token()) ?>">
  <meta name="medewerker-csrf" content="<?= live2_escape($_SESSION['medewerker_csrf']) ?>">
  <title>Afstort · Nieuw portaal</title>
  <link rel="stylesheet" href="live2/base.css?v=<?= filemtime(__DIR__ . '/live2/base.css') ?>">
  <link rel="stylesheet" href="live2/live.css?v=<?= filemtime(__DIR__ . '/live2/live.css') ?>">
  <script src="live2/live.js?v=<?= filemtime(__DIR__ . '/live2/live.js') ?>" defer></script>
  <script src="assets/tinymce/tinymce.min.js" defer></script>
  <script src="live2/templates.js?v=<?= filemtime(__DIR__ . '/live2/templates.js') ?>" defer></script>
  <script src="live2/users.js?v=<?= filemtime(__DIR__ . '/live2/users.js') ?>" defer></script>
</head>
<body data-user-id="<?= live2_escape($user['id']) ?>" data-user-name="<?= live2_escape($user['name']) ?>" data-office="<?= $user['office'] ? '1' : '0' ?>" data-admin="<?= $user['admin'] ? '1' : '0' ?>" data-report-all="<?= $user['reportAll'] ? '1' : '0' ?>">
  <header class="site-header"><a class="brand" href="index2.php"><img src="logohome.png" alt="Nierstichting" width="87" height="87"> afstort<span class="beta-tag">nieuw</span></a><div class="header-tools"><div class="collection-controls"><label class="view-label">Collectejaar<select id="collection-year"<?= $user['admin'] ? '' : ' disabled' ?>><option value="2026">2026</option></select></label><button id="report" type="button">Rapport</button><?php if ($user['admin']): ?><button id="preferences" type="button">Beheer</button><?php endif; ?></div><div class="account"><span><?= live2_escape($user['name']) ?></span><span class="avatar" aria-hidden="true"><?= live2_escape(strtoupper(substr($user['name'], 0, 1))) ?></span></div><form class="logout-form" action="logout.php" method="get"><button type="submit">Uitloggen</button></form></div></header>
  <main>
    <div class="page-heading"><div class="heading-copy"><p class="eyebrow">SAMEN GOED GEREGELD</p><div class="title-row"><h1 id="page-title">Mijn ritten</h1><a class="text-button mobile-mailbox" href="emailRapport.php?return=index2.php" target="_blank" rel="noopener">Emailoverzicht</a></div><p class="intro">Eén duidelijke volgende stap, voor iedere rit.</p></div><div class="heading-actions">
    <?php if ($user['office']): ?><button class="primary" id="create">＋ Nieuwe rit</button><?php endif; ?>
    </div></div>
    <div id="notice" role="status" aria-live="polite" hidden></div>
    <nav class="filters" aria-label="Ritten filteren" id="filters"></nav>
    <div class="list-toolbar"><h2 id="list-title">Beschikbaar; Chauffeur kiezen</h2><div><a class="text-button" id="mailbox" href="emailRapport.php?return=index2.php" target="_blank" rel="noopener">Emailoverzicht</a></div></div>
    <p class="muted" id="list-hint"></p>
    <section id="trips" class="trip-grid" aria-label="Ritten"><p>Actuele ritten laden…</p></section>
    <footer id="footer-note">Het rittenoverzicht vernieuwt automatisch iedere 30 seconden wanneer je geen formulier open hebt. Klik op een gekleurde knop om direct de nieuwste ritten te bekijken.</footer>
    <?php if ($user['fullAccess']): ?><div class="footer-view"><label class="view-label">Weergave<select id="view"><option value="office">Kantoor</option><option value="driver">Chauffeur (ikzelf)</option></select></label></div><?php endif; ?>
  </main>
    <?php if ($user['office']): ?>
    <dialog id="users-management" aria-labelledby="users-management-title">
      <div class="dialog-top"><h2 id="users-management-title">Beheer</h2><button type="button" class="icon-button" id="users-management-close" aria-label="Venster sluiten">×</button></div>
    <section id="users-section" class="users-section" aria-label="Chauffeurs en medewerkers">
      <div class="users-tabs" role="tablist" aria-label="Gebruikers">
        <button id="users-drivers-tab" type="button" role="tab" aria-controls="users-drivers" aria-selected="true">Chauffeurs</button>
        <button id="users-employees-tab" type="button" role="tab" aria-controls="users-employees" aria-selected="false" tabindex="-1">Medewerkers</button>
        <button id="users-admins-tab" type="button" role="tab" aria-controls="users-admins" aria-selected="false" tabindex="-1">Administrators</button>
        <button id="users-settings-tab" type="button" role="tab" aria-controls="users-settings" aria-selected="false" tabindex="-1">Instellingen</button>
        <button id="users-templates-tab" type="button" role="tab" aria-controls="users-templates" aria-selected="false" tabindex="-1">E-mailteksten</button>
      </div>
      <p id="users-notice" role="status" hidden></p>
      <div id="users-drivers" role="tabpanel" aria-labelledby="users-drivers-tab">
        <ul id="users-driver-list" class="users-list"><li>Chauffeurs laden…</li></ul>
        <?php if ($user['admin']): ?><div class="users-actions"><button type="button" class="primary" data-add-user="driver">Voeg chauffeur toe</button><button type="button" id="users-rebuild">Herbereken lat/lon (ritten + chauffeurs)</button></div><?php endif; ?>
      </div>
      <div id="users-employees" role="tabpanel" aria-labelledby="users-employees-tab" hidden>
        <ul id="users-employee-list" class="users-list"><li>Medewerkers laden…</li></ul>
        <?php if ($user['admin']): ?><div class="users-actions"><button type="button" class="primary" data-add-user="employee">Voeg medewerker toe</button></div><?php endif; ?>
      </div>
      <div id="users-admins" role="tabpanel" aria-labelledby="users-admins-tab" hidden><p>Deze accounts hebben Full Access.</p><ul id="users-admin-list" class="users-list"><li>Administrators laden…</li></ul></div>
      <div id="users-settings" role="tabpanel" aria-labelledby="users-settings-tab" hidden>
        <form id="portal-settings-form"><p>Het actuele jaar geldt voor nieuwe ritten in beide portalen. Bestaande ritten behouden hun jaar. De vergoeding geldt voor de rapporten van het gekozen jaar.</p><div class="form-grid"><label class="field">Actueel collectejaar<input name="year" type="number" min="2000" max="2099" required></label><label class="field">Kilometervergoeding (€ per km)<input name="rate" type="text" inputmode="decimal" required></label></div><button type="submit" class="primary" disabled>Instellingen opslaan</button></form>
      </div>
      <div id="users-templates" role="tabpanel" aria-labelledby="users-templates-tab" hidden>
        <p>Deze teksten worden door beide portalen gebruikt voor nieuwe mails. Invulvelden tussen vierkante haken worden automatisch ingevuld; laat ze staan. HTML-opmaak blijft behouden. Opslaan verstuurt geen mail en verandert eerder klaargezette mails niet.</p>
        <form id="mail-template-form"><label class="field">E-mail<select id="mail-template-select" disabled></select></label><label class="field">E-mailtekst<textarea id="mail-template-text" rows="16" maxlength="60000" disabled required spellcheck="true"></textarea></label><p id="mail-template-tokens" class="muted"></p><div class="form-actions"><button type="button" id="mail-template-reload">Sjablonen herladen</button><button type="submit" id="mail-template-save" class="primary" disabled>Tekst opslaan</button></div><p id="mail-template-status" role="status"></p></form>
        <h3>Voorbeeld met invulvelden</h3><iframe id="mail-template-preview" title="Voorbeeld van de e-mailtekst" sandbox=""></iframe>
      </div>
    </section>
    </dialog>
    <?php endif; ?>
  <dialog id="editor" aria-labelledby="dialog-title"><div class="dialog-top"><div><p class="eyebrow" id="dialog-step"></p><h2 id="dialog-title"></h2></div><button class="icon-button" id="close-dialog" aria-label="Venster sluiten">×</button></div><div id="dialog-content"></div></dialog>
  <?php if ($user['admin']): ?><dialog id="user-editor" aria-labelledby="user-editor-title"><div class="dialog-top"><h2 id="user-editor-title"></h2><button type="button" class="icon-button" id="user-editor-close" aria-label="Venster sluiten">×</button></div><div class="dialog-body"><form id="live-user-form"></form></div></dialog><?php endif; ?>
  <noscript><p>Schakel JavaScript in om het nieuwe portaal te gebruiken.</p></noscript>
</body>
</html>

<?php
require_once __DIR__ . '/../beta/storage.php';

function check($condition, $label) {
    if (!$condition) throw new RuntimeException('MISLUKT: ' . $label);
}
function rejects(callable $callback, $label) {
    try { $callback(); } catch (InvalidArgumentException | DomainException $e) { return; }
    throw new RuntimeException('Ten onrechte geaccepteerd: ' . $label);
}
$office = ['id' => '1', 'name' => 'Kantoor', 'email' => 'office@example.test', 'office' => true];
$driver = ['id' => '2', 'name' => 'Chauffeur', 'email' => 'driver@example.test', 'office' => false];
$other = ['id' => '3', 'name' => 'Ander', 'email' => 'other@example.test', 'office' => false];
$admin = $office + ['admin'=>true];
$yearState = beta_initial_state();
$settings = ['action'=>'preferences','collectejaar'=>'2027','kilometervergoeding'=>'0.35'];
rejects(function () use (&$yearState, $settings, $office) { beta_apply($yearState, $settings, $office); }, 'kantoor zonder admin kan instellingen niet wijzigen');
rejects(function () use (&$yearState, $settings, $driver) { beta_apply($yearState, $settings, $driver); }, 'chauffeur kan instellingen niet wijzigen');
beta_apply($yearState, $settings, $admin);
check(count($yearState['trips']) === 1 && !isset($yearState['trips']['voorbeeld-1']['collectejaar']), 'nieuw jaar behoudt oude ritten en begint leeg');
check(beta_preferences($yearState)['years'][2026]['kilometervergoeding'] === '0.30' && beta_preferences($yearState)['years'][2027]['kilometervergoeding'] === '0.35', 'vergoeding apart per jaar');
rejects(function () use (&$yearState, $settings, $admin) { beta_apply($yearState, array_merge($settings, ['collectejaar'=>'abcd']), $admin); }, 'ongeldig jaar geweigerd');
$previewClaimState = beta_initial_state();
beta_apply($previewClaimState, ['action'=>'claim','id'=>'voorbeeld-1','revision'=>1,'testView'=>'driver'], $office);
check($previewClaimState['trips']['voorbeeld-1']['chauffeurId'] === $office['id'], 'chauffeurtestweergave koppelt kantooraccount aan zichzelf');
$state = beta_initial_state();
$id = 'voorbeeld-1';
$assignmentState = beta_initial_state();
$assignment = ['action'=>'assign', 'id'=>$id, 'revision'=>1, 'chauffeurId'=>'test-chauffeur-2', 'chauffeur'=>'Vervalste naam', 'chauffeurEmail'=>'wrong@example.test'];
rejects(function () use (&$assignmentState, $assignment, $driver) { beta_apply($assignmentState, $assignment, $driver); }, 'chauffeur mag niet aan een ander toewijzen');
rejects(function () use (&$assignmentState, $assignment, $office) { beta_apply($assignmentState, array_merge($assignment, ['chauffeurId'=>'onbekend']), $office); }, 'onbekende chauffeur geweigerd');
rejects(function () use (&$assignmentState, $id, $office) { beta_apply($assignmentState, ['action'=>'claim','id'=>$id,'revision'=>1], $office); }, 'kantoor kiest een chauffeur in plaats van zelf claimen');
beta_apply($assignmentState, $assignment, $office);
check($assignmentState['trips'][$id]['chauffeur'] === 'Testchauffeur Bram' && $assignmentState['trips'][$id]['chauffeurEmail'] === 'bram@example.test', 'naam en mail komen uit vertrouwde lijst');
check($assignmentState['trips'][$id]['status'] === 'planning' && count($assignmentState['mails']) === 0, 'toewijzen plant nog geen afspraak en mailt niet');
rejects(function () use (&$assignmentState, $assignment, $office) { beta_apply($assignmentState, $assignment, $office); }, 'dubbele of gelijktijdige toewijzing geweigerd');
check(beta_public_state($assignmentState, $driver)['drivers'] === [], 'chauffeur krijgt geen volledige keuzelijst');
$claim = ['action' => 'claim', 'id' => $id, 'revision' => 1];
beta_apply($state, $claim, $driver);
check($state['trips'][$id]['chauffeurId'] === '2', 'claim koppelt ingelogde chauffeur');
rejects(function () use (&$state, $claim, $other) { beta_apply($state, $claim, $other); }, 'gelijktijdige claim');
check(count(beta_public_state($state, $other)['trips']) === 0, 'andere chauffeur ziet de rit niet');
rejects(function () use (&$state, $id, $other) { beta_apply($state, ['action'=>'confirm','id'=>$id,'revision'=>2], $other); }, 'andere chauffeur mag niet muteren');
beta_apply($state, ['action'=>'save_schedule','id'=>$id,'revision'=>2,'afhaalmoment'=>'','afhaaltijd'=>''], $driver);
check(count($state['mails']) === 0, 'concept verstuurt geen testmail');
rejects(function () use (&$state, $id, $driver) { beta_apply($state, ['action'=>'confirm','id'=>$id,'revision'=>3,'afhaalmoment'=>'2026-02-30','afhaaltijd'=>'12:30'], $driver); }, 'ongeldige datum');
$confirm = ['action'=>'confirm','id'=>$id,'revision'=>3,'afhaalmoment'=>'2026-10-01','afhaaltijd'=>'14:30','simulateFailure'=>'1'];
rejects(function () use (&$state, $confirm, $driver) { beta_apply($state, array_merge($confirm, ['afhaaltijd'=>'14:07']), $driver); }, 'nieuwe afspraak vereist een kwartier');
beta_apply($state, $confirm, $driver);
check($state['trips'][$id]['status'] === 'planning', 'mailfout bevestigt rit niet');
check($state['trips'][$id]['afhaaltijd'] === '14:30', 'mailfout bewaart afspraak');
check(count($state['mails']) === 0, 'mailfout geen succesvolle mail');
$confirm['revision'] = 4; $confirm['simulateFailure'] = '0';
beta_apply($state, $confirm, $driver);
check($state['trips'][$id]['status'] === 'planned' && count($state['mails']) === 1, 'bevestigen na fout');
rejects(function () use (&$state, $confirm, $driver) { beta_apply($state, $confirm, $driver); }, 'dubbele bevestiging');
$finish = ['action'=>'finish','id'=>$id,'revision'=>5,'gereden'=>'0','gestort'=>'842,50','opmerking'=>'Test','simulateFailure'=>'1'];
$receipt = ['id'=>'bon1','name'=>'bon.png','type'=>'image/png','data'=>'TESTDATA'];
beta_apply($state, $finish, $driver, [$receipt]);
check($state['trips'][$id]['status'] === 'planned', 'afronding wacht op mail');
check($state['trips'][$id]['gereden'] === '0.00', 'nul kilometers is geldig');
check(count($state['trips'][$id]['receipts']) === 1, 'bon bewaard bij mailfout');
$public = beta_public_state($state, $driver);
check(!isset($public['trips'][0]['receipts'][0]['data']), 'lijst bevat geen boninhoud');
check(isset($state['trips'][$id]['receipts'][0]['data']), 'publiek filter wijzigt opslag niet');
$finish['revision'] = 6; $finish['simulateFailure'] = '0';
beta_apply($state, $finish, $driver);
check($state['trips'][$id]['status'] === 'done', 'afronden na fout');
check(count($state['mails']) === 2 && count($state['trips'][$id]['receipts']) === 1, 'geen dubbele mail of bon');
check($state['mails'][1]['attachments'] === ['bon.png'], 'bijlage in testmail');
rejects(function () use (&$state, $finish, $driver) { beta_apply($state, $finish, $driver); }, 'dubbele afronding');
rejects(function () { beta_number(['gereden'=>'-1'],'gereden',10000); }, 'negatieve kilometers');
rejects(function () { beta_number(['gestort'=>'1e3'],'gestort',1000000); }, 'exponentbedrag');
rejects(function () { beta_number(['gestort'=>'12.345'],'gestort',1000000); }, 'meer dan twee decimalen');
$create = ['action'=>'create','collectegebied'=>'Testgebied','gebiedsnummer'=>'TEST','contactpersoon'=>'Test','adres'=>'Teststraat 12','postcodePlaats'=>'1234 AB Teststad','telefoonnummer'=>'0612345678','email'=>'test@example.test','verwachtBedrag'=>'100','soort'=>'Munten'];
beta_apply($yearState, $create + ['collectejaar'=>'2027'], $office);
$newYearTrip = array_values($yearState['trips'])[1];
check($newYearTrip['collectejaar'] === 2027 && isset($yearState['trips']['voorbeeld-1']), 'nieuwe rit hoort bij 2027 en 2026 blijft bewaard');
rejects(function () use (&$yearState, $create, $office) { beta_apply($yearState, $create + ['collectejaar'=>'2028'], $office); }, 'nog niet aangemaakt jaar geweigerd');
rejects(function () use (&$state, $create, $driver) { beta_apply($state, $create, $driver); }, 'chauffeur maakt geen rit aan');
beta_apply($state, $create, $office);
check(count($state['trips']) === 2 && count($state['mails']) === 3, 'kantoor maakt rit met testmail');
check(count(beta_public_state($state, $other)['mails']) === 1, 'mails volgen zichtbare ritten');

// Persistence and rollback use a disposable directory, never the actual beta data.
$dir = sys_get_temp_dir() . '/afstort-beta-check-' . bin2hex(random_bytes(6));
mkdir($dir);
putenv('AFSTORT_BETA_DATA_DIR=' . $dir);
try {
    beta_with_store(function (&$stored) use ($driver, $claim) { beta_apply($stored, $claim, $driver); });
    $persisted = beta_with_store(function (&$stored) { return $stored; });
    check($persisted['trips'][$id]['chauffeurId'] === '2', 'opslag blijft bewaard');
    try { beta_with_store(function (&$stored) { $stored = []; throw new DomainException('rollback'); }); } catch (DomainException $e) {}
    check(beta_with_store(function (&$stored) { return count($stored['trips']); }) === 1, 'fout overschrijft opslag niet');
    check(strpos(file_get_contents($dir . '/store.php'), BETA_DATA_GUARD) === 0, 'PHP-afscherming aanwezig');
} finally {
    foreach (glob($dir . '/*') as $path) unlink($path);
    rmdir($dir);
    putenv('AFSTORT_BETA_DATA_DIR');
}
echo "Beta checks passed.\n";

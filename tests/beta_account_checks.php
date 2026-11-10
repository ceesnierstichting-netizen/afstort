<?php
require_once __DIR__ . '/../session.php';
require_once __DIR__ . '/../beta/source.php';
function account_check($condition, $label) { if (!$condition) throw new RuntimeException($label); }
function account_rejects(callable $callback, $label) {
    try { $callback(); } catch (DomainException | InvalidArgumentException $e) { return; }
    throw new RuntimeException($label);
}
$admin = ['id'=>'admin','name'=>'Admin','email'=>'admin@example.test','office'=>true,'admin'=>true];
$office = array_merge($admin, ['admin'=>false]);
$driver = array_merge($office, ['office'=>false]);
$state = beta_initial_state();
$input = ['action'=>'save_account','name'=>'Nieuwe chauffeur','email'=>'nieuw@example.test','role'=>'driver','active'=>'1','postcode'=>'1234 AB','iban'=>'NL91 ABNA 0417 1643 00'];
foreach ([$office,$driver] as $actor) account_rejects(function () use (&$state,$input,$actor) { beta_apply($state,$input,$actor); }, 'Alleen admin mag toevoegen');
beta_apply($state,$input,$admin);
$account = array_values($state['accounts'])[0];
account_check($account['iban'] === 'NL91ABNA0417164300' && $account['postcode'] === '1234AB', 'IBAN en postcode normaliseren');
account_check(count(beta_drivers($state)) === 3, 'Nieuwe actieve chauffeur beschikbaar voor toewijzing');
account_check(beta_public_state($state,$office)['accounts'] === [] && beta_public_state($state,$driver)['accounts'] === [], 'Accountgegevens alleen voor admin');
account_check(count(beta_public_state($state,$admin)['accounts']) === 3, 'Admin ziet alle testchauffeurs');
account_rejects(function () use (&$state,$input,$admin) { beta_apply($state,$input,$admin); }, 'Dubbele naam of e-mail weigeren');
account_rejects(function () use (&$state,$input,$admin) { beta_apply($state,array_merge($input,['name'=>'Andere naam','email'=>'ander@example.test','iban'=>'NL91ABNA0417164301']),$admin); }, 'IBAN met ongeldige controlecijfers weigeren');
$edit = $input + ['accountId'=>$account['id'],'accountRevision'=>1];
$edit['active'] = '0';
foreach ([$office,$driver] as $actor) account_rejects(function () use (&$state,$edit,$actor) { beta_apply($state,$edit,$actor); }, 'Alleen admin mag wijzigen');
beta_apply($state,$edit,$admin);
account_check(count(beta_drivers($state)) === 2 && isset($state['trips']['voorbeeld-1']), 'Inactief maken behoudt ritten en haalt chauffeur uit keuzelijst');
account_rejects(function () use (&$state,$edit,$admin) { beta_apply($state,$edit,$admin); }, 'Verouderde wijziging weigeren');
$employee = array_merge($input,['name'=>'Medewerker','email'=>'medewerker@example.test','role'=>'employee','iban'=>'','postcode'=>'']);
beta_apply($state,$employee,$admin);
account_check(count(beta_drivers($state)) === 2, 'Medewerker niet in chauffeurkeuzelijst');
account_rejects(function () use (&$state,$employee,$admin) { beta_apply($state,array_merge($employee,['name'=>'Onbevoegd','email'=>'onbevoegd@example.test','role'=>'admin']),$admin); }, 'Adminrol niet via formulier aanmaken');
$sources = beta_source_accounts([
    ['id'=>1,'naam'=>'Admin','email'=>'admin@example.test','fullAccess'=>1,'is_medewerker'=>0],
    ['id'=>2,'naam'=>'Medewerker','email'=>'medewerker@example.test','fullAccess'=>0,'is_medewerker'=>1],
    ['id'=>3,'naam'=>'Chauffeur','email'=>'chauffeur@example.test','fullAccess'=>0,'is_medewerker'=>0],
]);
account_check(count($sources) === 2 && $sources[2]['role'] === 'employee' && $sources[3]['role'] === 'driver', 'Bronrollen correct en admin uitgesloten van bewerken');
$snapshot = ['trips'=>[],'mails'=>[],'sourceAccounts'=>$sources,'meta'=>['source'=>'snapshot'],'drivers'=>[['id'=>'3','name'=>'Chauffeur','email'=>'chauffeur@example.test']]];
$override = ['action'=>'save_account','accountId'=>'3','accountRevision'=>1,'name'=>'Aangepast','email'=>'chauffeur@example.test','role'=>'driver','active'=>'0'];
beta_apply($snapshot,$override,$admin);
$snapshot['sourceAccounts'] = $sources;
account_check(beta_accounts($snapshot)[3]['name'] === 'Aangepast' && count(beta_drivers($snapshot)) === 0, 'Bronverversing behoudt lokale bewerking en inactieve status');
echo "Beta account checks passed.\n";

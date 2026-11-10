<?php
require_once __DIR__ . '/../app_helpers.php';
require_once __DIR__ . '/../beta/source.php';
require_once __DIR__ . '/../beta/storage.php';
function verify($condition, $label) { if (!$condition) throw new RuntimeException('MISLUKT: ' . $label); }
$rows = [
    ['id'=>10,'collectegebied'=>'Test <gebied>','contactpersoon'=>'A & B','email'=>'contact@example.test','chauffeur'=>'Jan','afhaalmoment'=>'2026-10-02','afhaaltijd'=>'14:30:00','status'=>'-','wijknaam'=>'Wijk Noord','gestort'=>'100.50'],
    ['id'=>11,'chauffeur'=>'Chauffeur kiezen','status'=>'-'],
    ['id'=>12,'chauffeur'=>'Jan','status'=>'Afgehandeld'],
    ['id'=>13,'chauffeur'=>'Jan','afhaalmoment'=>'0000-00-00','status'=>'-'],
];
$drivers = [['id'=>2,'naam'=>'Jan','email'=>'jan@example.test']];
$templates = [
    ['id'=>1,'email_template'=>'Beste [naam],<br>Uw aanvraag voor [collectegebied].'],
    ['id'=>3,'email_template'=>'Beste [chauffeurNaam],<br>[formattedDatum] om [formattedTijd], [telefoonnummer]. [busbriefje]'],
    ['id'=>4,'email_template'=>'Beste [contact],<br>[chauffeurnaam] komt op [afhaalmoment] om [afhaaltijd].'],
    ['id'=>5,'email_template'=>'Gebied [collectegebied] afgerond: [gestort].'],
    ['id'=>6,'email_template'=>'Wijk [wijknaam] afgerond: [gestort].'],
];
$state = beta_snapshot($rows,$drivers,$templates);
verify(count($state['trips']) === 4 && $state['mails'] === [], 'ritten gekopieerd, geen historische mails verzonnen');
verify($state['trips']['bron-10']['status'] === 'planned', 'gepland afgeleid van datum en chauffeur');
verify($state['trips']['bron-10']['chauffeurId'] === '2', 'chauffeur gekoppeld aan echte id');
verify($state['trips']['bron-10']['afhaaltijd'] === '14:30', 'tijd voor formulier');
verify($state['trips']['bron-11']['status'] === 'available', 'beschikbare rit');
verify($state['trips']['bron-12']['status'] === 'done', 'afgehandelde rit');
verify($state['trips']['bron-13']['status'] === 'planning', 'ongeldige brondatum blijft ongepland');
$office = ['id'=>'1','name'=>'Kantoor','email'=>'kantoor@example.test','office'=>true];
beta_apply($state, ['action'=>'assign','id'=>'bron-11','revision'=>1,'chauffeurId'=>'2'], $office);
verify($state['trips']['bron-11']['chauffeur'] === 'Jan' && $state['trips']['bron-11']['chauffeurEmail'] === 'jan@example.test', 'bronchauffeur toewijzen in testkopie');
$eligible = beta_selectable_drivers([
    ['id'=>1,'naam'=>'Medewerker','email'=>'m@example.test','is_medewerker'=>1],
    ['id'=>2,'naam'=>'Cees','email'=>'c@example.test','is_medewerker'=>1],
    ['id'=>3,'naam'=>'Chauffeur','email'=>'d@example.test','is_medewerker'=>0],
]);
verify(array_column($eligible,'id') === ['2','3'], 'kiesbare chauffeurs volgen medewerkeruitzondering van huidig portaal');
$state['trips']['bron-13']['afhaaltijd'] = '14:07';
beta_apply($state, ['action'=>'save_schedule','id'=>'bron-13','revision'=>1,'afhaalmoment'=>'','afhaaltijd'=>'14:07'], $office);
verify($state['trips']['bron-13']['afhaaltijd'] === '14:07', 'bestaande brontijd wordt niet stilzwijgend afgerond');
$trip = $state['trips']['bron-10'];
$mails = beta_build_mails($state,$trip,'Afspraak',$office);
verify(count($mails) === 2, 'aparte contact- en chauffeurmail');
verify($mails[0]['to'] === 'contact@example.test' && $mails[1]['to'] === 'jan@example.test', 'juiste ontvangers');
verify(strpos($mails[0]['body'],'A & B') !== false && strpos($mails[1]['body'],'2 oktober 2026') !== false, 'tags en datumnotatie');
verify(strpos($mails[1]['body'],'link uitgeschakeld') !== false, 'documentlink niet actief');
$mails = beta_build_mails($state,$trip,'Afronding',$office);
verify($mails[0]['templateId'] === 6 && strpos($mails[0]['body'],'Wijk Noord') !== false, 'wijktemplate gekozen');
verify($mails[0]['cc'] === 'collecte@nierstichting.nl' && $mails[0]['bcc'] === 'jan@example.test', 'afrondingsontvangers');
$trip['wijknaam'] = '';
verify(beta_build_mails($state,$trip,'Afronding',$office)[0]['templateId'] === 5, 'gebiedstemplate gekozen');
verify(strpos(beta_build_mails($state,$trip,'Aanvraag',$office)[0]['body'],'Test <gebied>') !== false, 'ingevulde tekens blijven behouden als tekst');
unset($state['templates'][3]);
verify(beta_mail($state,$trip,'Afspraak',$office,false) === false && count($state['mails']) === 0, 'ontbrekend template levert geen gedeeltelijke mails');
verify(strpos($trip['mailError'],'sjabloon 3') !== false, 'duidelijke templatefout');
verify(beta_template_text('<script>alert(1)</script><img src="https://example.test/pixel">Goed<br>Dag') === "Goed\nDag", 'tekstuele veilige weergave');

$dir = sys_get_temp_dir() . '/afstort-snapshot-check-' . bin2hex(random_bytes(6)); mkdir($dir); putenv('AFSTORT_BETA_DATA_DIR=' . $dir);
try {
    beta_with_store(function (&$s) use ($state) { $s = $state; }, 'snapshot', '1');
    verify(beta_with_store(function (&$s) { return count($s['trips']); }, 'snapshot', '1') === 4, 'persoonlijke kopie opgeslagen');
    verify(beta_with_store(function (&$s) { return count($s['trips']); }, 'snapshot', '2') === 0, 'andere tester krijgt eigen kopie');
    verify(beta_with_store(function (&$s) { return count($s['trips']); }) === 1, 'oefenomgeving blijft intact');
} finally {
    foreach (glob($dir . '/*') as $file) unlink($file);
    rmdir($dir); putenv('AFSTORT_BETA_DATA_DIR');
}
echo "Beta snapshot checks passed.\n";

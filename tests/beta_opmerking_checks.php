<?php
require_once __DIR__ . '/../app_helpers.php';
require_once __DIR__ . '/../beta/source.php';
require_once __DIR__ . '/../beta/domain.php';
function checkBetaNote($condition) {
    if (!$condition) throw new RuntimeException('Contactopmerking in beta onjuist.');
}
$user = ['id'=>'1','name'=>'Kantoor','email'=>'office@example.test','office'=>true];
$state = beta_initial_state();
$note = "Bel <Jan> & Piet\nGebruik zijdeur";
$input = ['action'=>'create','collectegebied'=>'Gebied','contactpersoon'=>'Jan','adres'=>'Straat 1','postcodePlaats'=>'1234 AB Stad','telefoonnummer'=>'0612345678','email'=>'jan@example.test','verwachtBedrag'=>'10','soort'=>'Munten','contactOpmerking'=>$note];
beta_apply($state, $input, $user);
$trip = end($state['trips']);
checkBetaNote($trip['contactOpmerking'] === $note);
checkBetaNote(strpos(end($state['mails'])['body'], $note) !== false);
$trip['opmerking'] = 'Bestaande afrondingsopmerking';
$trip['chauffeurEmail'] = 'driver@example.test';
$snapshot = ['meta'=>['source'=>'snapshot'],'templates'=>array_fill_keys([1,3,4,5,6], 'Opmerking: [OPMERKING]')];
foreach (['Aanvraag','Afspraak','Afronding'] as $kind) {
    foreach (beta_build_mails($snapshot, $trip, $kind, $user) as $mail) {
        checkBetaNote(strpos($mail['body'], $note) !== false);
        checkBetaNote(stripos($mail['body'], '[opmerking]') === false);
        if ($kind === 'Afronding') checkBetaNote(strpos($mail['body'], $trip['opmerking']) !== false);
    }
}
$imported = beta_snapshot([['id'=>1,'opmerking'=>$note]], [], []);
checkBetaNote($imported['trips']['bron-1']['contactOpmerking'] === $note);
checkBetaNote($imported['trips']['bron-1']['opmerking'] === '');
$legacy = beta_snapshot([['id'=>1]], [], []);
checkBetaNote($legacy['trips']['bron-1']['contactOpmerking'] === '');
echo "Beta opmerking checks passed.\n";

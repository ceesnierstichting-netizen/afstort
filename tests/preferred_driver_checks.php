<?php
require_once __DIR__ . '/live2_checks.php';

function preferredDriverDatabase(): LiveMemoryPDO {
    $db = new LiveMemoryPDO();
    $db->drivers[] = ['id'=>3, 'naam'=>'Bram', 'email'=>'bram@example.test', 'fullAccess'=>0, 'is_medewerker'=>0, 'IBAN'=>'', 'postcode'=>'', 'lat'=>null, 'lon'=>null, 'beschikbare_jaren'=>'[2026]'];
    return $db;
}

$manual = preferredDriverDatabase();
$message = live2_mutate($manual, $create + ['preferredDriverId'=>'3'], $office, [], 'key', fn()=>true);
liveCheck($manual->offers[2]['chauffeur_naam'] === 'Bram' && $manual->trips[2]['chauffeur'] === '', 'Manual choice must offer to Bram without assigning before acceptance');
liveCheck(str_contains($message, 'Bram'), 'Creation did not name the chosen driver');
$proposal = array_values(array_filter($manual->outbox, fn($mail)=>$mail['kind'] === 'Chauffeurvoorstel'))[0];
$payload = json_decode($proposal['payload'], true);
liveCheck($payload['to'] === 'bram@example.test' && $payload['offer']['distance'] === null, 'Manual choice incorrectly required coordinates or changed recipient');
liveCheck(!str_contains($payload['html'], 'dichtstbijzijnde chauffeur') && str_contains($payload['html'], 'declineRit.php'), 'Manual proposal used automatic selection text or lost decline link');
$before = count($manual->outbox);
live2_mutate($manual, $create + ['preferredDriverId'=>'2'], $office, [], 'key', fn()=>true);
liveCheck(count($manual->outbox) === $before && $manual->offers[2]['chauffeur_naam'] === 'Bram', 'Repeated create changed driver or sent duplicate mail');

$automatic = preferredDriverDatabase();
live2_mutate($automatic, $create + ['preferredDriverId'=>''], $office, [], 'key', fn()=>true);
liveCheck($automatic->offers[2]['chauffeur_naam'] === 'Anna', 'Default choice no longer uses nearest driver');

$retry = preferredDriverDatabase();
live2_mutate($retry, $create + ['preferredDriverId'=>'3'], $office, [], 'key', fn()=>false);
liveCheck(count($retry->outbox) === 1 && !$retry->offers, 'Driver contacted before contact confirmation succeeded');
live2_mutate($retry, ['action'=>'retry_mail', 'id'=>2, 'revision'=>live2_revision($retry->trips[2], $retry->extras[2])], $office, [], 'key', fn()=>true);
liveCheck($retry->offers[2]['chauffeur_naam'] === 'Bram', 'Contact-mail retry lost manual choice');

$failedProposal = preferredDriverDatabase();
live2_mutate($failedProposal, $create + ['preferredDriverId'=>'3'], $office, [], 'key', fn($to)=>$to !== 'bram@example.test');
liveCheck(!$failedProposal->offers && count($failedProposal->outbox) === 2, 'Failed manual offer registered as sent');
$retryRecipients = [];
live2_mutate($failedProposal, ['action'=>'retry_mail', 'id'=>2, 'revision'=>live2_revision($failedProposal->trips[2], $failedProposal->extras[2])], $office, [], 'key', function ($to) use (&$retryRecipients) { $retryRecipients[] = $to; return true; });
liveCheck($retryRecipients === ['bram@example.test'] && $failedProposal->offers[2]['chauffeur_naam'] === 'Bram', 'Proposal retry used another driver or resent contact confirmation');

foreach (['missing', 'inactive', 'email', 'employee', 'admin'] as $invalid) {
    $db = preferredDriverDatabase();
    $id = '3';
    if ($invalid === 'missing') $id = '999';
    if ($invalid === 'inactive') $db->drivers[1]['beschikbare_jaren'] = '[2027]';
    if ($invalid === 'email') $db->drivers[1]['email'] = '';
    if ($invalid === 'employee') $db->drivers[1]['is_medewerker'] = 1;
    if ($invalid === 'admin') $db->drivers[1]['naam'] = 'Admin';
    liveReject(fn()=>live2_mutate($db, $create + ['preferredDriverId'=>$id], $office, [], 'key', fn()=>true), 'Invalid preferred driver accepted: ' . $invalid);
    liveCheck(!$db->trips && !$db->outbox && !$db->inTransaction(), 'Invalid preference left a partial trip: ' . $invalid);
}

$unavailable = preferredDriverDatabase();
live2_mutate($unavailable, $create + ['preferredDriverId'=>'3'], $office, [], 'key', fn()=>false);
$unavailable->drivers[1]['beschikbare_jaren'] = '[]';
live2_mutate($unavailable, ['action'=>'retry_mail', 'id'=>2, 'revision'=>live2_revision($unavailable->trips[2], $unavailable->extras[2])], $office, [], 'key', fn()=>true);
liveCheck(!$unavailable->offers && count($unavailable->outbox) === 1 && str_contains($unavailable->extras[2]['mail_error'], 'niet actief'), 'Unavailable preferred driver silently replaced by nearest driver');
echo "Preferred driver checks passed.\n";

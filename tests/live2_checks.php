<?php
require_once __DIR__ . '/../live2/domain.php';
function geocodePostcode($postcode) { return [52.1, 4.3]; }

// Exercise the service without a live database or real mail delivery.
class LiveMemoryPDO extends PDO {
    public array $trips = [], $extras = [], $outbox = [], $offers = [];
    public array $drivers = [['id'=>2,'naam'=>'Anna','email'=>'anna@example.test','fullAccess'=>0,'is_medewerker'=>0,'IBAN'=>'','postcode'=>'1234AB','lat'=>52.11,'lon'=>4.31,'beschikbare_jaren'=>'[2026]']];
    public array $settings = ['data'=>'{"currentYear":2026,"years":{"2026":{"kilometervergoeding":"0.30"}}}', 'revision'=>1];
    public array $templates = [];
    private ?array $backup = null;
    private int $lastId = 1;
    public function __construct() {
        foreach ([1,3,4,5,6] as $id) $this->templates[] = ['id'=>$id,'email_template'=>'<p>Beste [contactpersoon], [soort] [opmerking] [chauffeur] [afhaalmoment] [afhaaltijd] [gestort] [busbriefje]</p>'];
    }
    public function beginTransaction(): bool { $this->backup = [$this->trips,$this->extras,$this->outbox,$this->offers]; return true; }
    public function inTransaction(): bool { return $this->backup !== null; }
    public function commit(): bool { $this->backup = null; return true; }
    public function rollBack(): bool { [$this->trips,$this->extras,$this->outbox,$this->offers] = $this->backup; $this->backup = null; return true; }
    public function lastInsertId(?string $name = null): string|false { return (string)$this->lastId; }
    public function exec(string $statement): int|false { return 0; }
    public function prepare(string $query, array $options = []): PDOStatement|false { return new LiveMemoryStatement($this, $query); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false { $s = $this->prepare($query); $s->execute(); return $s; }
    public function run(string $sql, array $p): array {
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        if (str_starts_with($sql, 'SELECT data, revision FROM afstort_settings')) return [$this->settings];
        if (str_starts_with($sql, 'SELECT beschikbare_jaren FROM chauffeurs')) {
            $rows = array_values(array_filter($this->drivers, fn($d) => $d['naam'] === $p[0]));
            return array_map(fn($d) => ['beschikbare_jaren'=>$d['beschikbare_jaren']], $rows);
        }
        if (str_starts_with($sql, 'SELECT collectejaar FROM ritten')) return isset($this->trips[$p[0]]) ? [['collectejaar'=>$this->trips[$p[0]]['collectejaar'] ?? 2026]] : [];
        if (str_starts_with($sql, 'UPDATE afstort_settings')) {
            if ($p[1] !== $this->settings['revision']) return ['affected'=>0];
            $this->settings=['data'=>$p[0], 'revision'=>$this->settings['revision']+1]; return ['affected'=>1];
        }
        if (str_starts_with($sql, 'SELECT') && str_contains($sql, 'FROM rit_aanbiedingen')) {
            $rows=array_values(array_filter($this->offers,fn($o)=>$o['rit_id']==($p[':rit_id'] ?? 0)));
            if (str_contains($sql, "status = 'afgewezen'")) return [];
            if (str_starts_with($sql,'SELECT chauffeur_naam')) return array_map(fn($o)=>['chauffeur_naam'=>$o['chauffeur_naam']],$rows);
            if (str_starts_with($sql,'SELECT id')) return array_map(fn($o)=>['id'=>$o['rit_id']],$rows);
            return $rows;
        }
        if (str_starts_with($sql, 'SELECT id, collectegebied') && str_contains($sql,'FROM ritten')) return isset($this->trips[$p[':id']]) ? [$this->trips[$p[':id']]] : [];
        if (str_starts_with($sql, 'SELECT') && str_contains($sql, 'FROM chauffeurs')) return str_contains($sql, 'is_medewerker = 1') ? [] : $this->drivers;
        if (str_starts_with($sql, 'SELECT') && str_contains($sql, 'FROM instellingen')) return $this->templates;
        if (str_starts_with($sql, 'SELECT * FROM ritten')) return isset($p[0]) ? (isset($this->trips[$p[0]]) ? [$this->trips[$p[0]]] : []) : array_values($this->trips);
        if (str_starts_with($sql, 'SELECT * FROM rit_live_ui WHERE request_key')) return array_values(array_filter($this->extras, fn($e)=>$e['request_key'] === $p[0]));
        if (str_starts_with($sql, 'SELECT * FROM rit_live_ui')) return isset($p[0]) ? (isset($this->extras[$p[0]]) ? [$this->extras[$p[0]]] : []) : array_values($this->extras);
        if (str_starts_with($sql, 'SELECT') && str_contains($sql, 'FROM rit_live_outbox')) {
            $rows = array_values(array_filter($this->outbox, function ($e) use ($sql,$p) {
                if (str_contains($sql, 'rit_id = ?')) return $e['rit_id'] == $p[0] && $e['batch'] === $p[1];
                if (str_contains($sql, 'WHERE batch=?')) return $e['batch'] === $p[0] && (!str_contains($sql, "status <> 'sent'") || $e['status'] !== 'sent');
                return true;
            }));
            if (str_starts_with($sql, 'SELECT status')) return array_map(fn($e)=>['status'=>$e['status']], $rows);
            if (str_starts_with($sql, 'SELECT kind')) return array_map(fn($e)=>['kind'=>$e['kind']], $rows);
            return $rows;
        }
        if (str_starts_with($sql, 'INSERT INTO ritten')) {
            preg_match('/INSERT INTO ritten \(([^)]+)\)/', $sql, $m);
            $id = ++$this->lastId;
            $this->trips[$id] = array_combine(explode(',', $m[1]), $p) + ['id'=>$id]; return [];
        }
        if (str_starts_with($sql, 'INSERT INTO rit_live_ui')) {
            $old=$this->extras[$p[0]] ?? null;
            $this->extras[$p[0]] = array_combine(['rit_id','version','revision','phase','row_data','receipts','finish_note','mail_error','batch','request_key','owner','internal_note','amount_adjusted_by'], $p);
            if ($old) { $this->extras[$p[0]]['request_key']=$old['request_key']; $this->extras[$p[0]]['owner']=$old['owner']; }
            return [];
        }
        if (str_starts_with($sql, 'INSERT INTO rit_live_outbox')) {
            $this->outbox[$p[0]] = array_combine(['id','rit_id','batch','kind','version','payload'], $p) + ['status'=>'pending','created_at'=>'2026-10-02 12:00:00']; return [];
        }
        if (str_starts_with($sql, 'UPDATE ritten SET')) {
            $id = $p[count($p)-1];
            if (str_contains($sql, "status='Afgehandeld'")) $this->trips[$id]['status'] = 'Afgehandeld';
            else { preg_match('/SET (.+) WHERE/', $sql, $m); $fields = explode(',', $m[1]); foreach ($fields as $i=>$f) $this->trips[$id][trim(explode('=', $f)[0])] = $p[$i]; }
            return [];
        }
        if (str_starts_with($sql, "UPDATE rit_live_outbox SET status='sending'")) {
            if (!in_array($this->outbox[$p[0]]['status'], ['pending','failed'], true)) return ['affected'=>0];
            $this->outbox[$p[0]]['status'] = 'sending'; return ['affected'=>1];
        }
        if (str_starts_with($sql, "UPDATE rit_live_outbox SET status='cancelled'")) { $this->outbox[$p[0]]['status'] = 'cancelled'; return []; }
        if (str_starts_with($sql, 'UPDATE rit_live_outbox SET status=?')) { $this->outbox[$p[1]]['status'] = $p[0]; return []; }
        if (str_starts_with($sql, 'UPDATE rit_live_ui SET phase=')) {
            $id = $p[4]; foreach (['phase','mail_error','version','row_data'] as $i=>$field) $this->extras[$id][$field] = $p[$i]; $this->extras[$id]['revision']++; return [];
        }
        if (str_starts_with($sql, 'UPDATE rit_live_ui SET mail_error=')) { $this->extras[$p[1]]['mail_error']=$p[0]; $this->extras[$p[1]]['revision']++; return []; }
        if (str_starts_with($sql, 'INSERT INTO rit_aanbiedingen')) {
            $this->offers[$p[':rit_id']]=['rit_id'=>$p[':rit_id'],'chauffeur_naam'=>$p[':chauffeur_naam'],'chauffeur_email'=>$p[':chauffeur_email'],'afstand_km'=>$p[':afstand_km'],'status'=>'aangeboden']; return [];
        }
        if (str_starts_with($sql, 'DELETE FROM rit_aanbiedingen')) { unset($this->offers[$p[':rit_id']]); return []; }
        if (str_starts_with($sql, 'INSERT INTO rit_email_log')) return [];
        throw new RuntimeException('Unhandled test SQL: ' . $sql);
    }
}
class LiveMemoryStatement extends PDOStatement {
    private LiveMemoryPDO $db; private string $sql; private array $rows = []; private int $affected = 0;
    public function __construct(LiveMemoryPDO $db, string $sql) { $this->db=$db; $this->sql=$sql; }
    public function execute(?array $params = null): bool { $result=$this->db->run($this->sql, $params ?? []); $this->affected=$result['affected'] ?? 0; $this->rows=isset($result['affected']) ? [] : $result; return true; }
    public function fetch(int $mode=PDO::FETCH_DEFAULT, int $cursorOrientation=PDO::FETCH_ORI_NEXT, int $cursorOffset=0): mixed { return array_shift($this->rows) ?? false; }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT, mixed ...$args): array { return $mode===PDO::FETCH_COLUMN ? array_map(fn($row)=>array_values($row)[0],$this->rows) : $this->rows; }
    public function fetchColumn(int $column=0): mixed { $row=$this->fetch(); return $row ? array_values($row)[$column] : false; }
    public function rowCount(): int { return $this->affected; }
}
function liveCheck(bool $value, string $label): void { if (!$value) throw new RuntimeException($label); }
function liveReject(callable $fn, string $label): void { try { $fn(); } catch (DomainException|RitConflict|InvalidArgumentException $e) { return; } throw new RuntimeException($label); }
$db = new LiveMemoryPDO();
$office = ['id'=>'1','name'=>'Kantoor','email'=>'kantoor@example.test','office'=>true,'admin'=>true,'reportAll'=>true,'iban'=>''];
$driver = ['id'=>'2','name'=>'Anna','email'=>'anna@example.test','office'=>false,'admin'=>false,'reportAll'=>false,'iban'=>''];
$other = array_replace($driver, ['id'=>'3','name'=>'Bram']);
$row = array_values(beta_initial_state()['trips'])[0];
$row['id']=1; $row['chauffeur']=''; $row['status']='-'; $row['opmerking']='Contactnotitie';
$db->trips[1]=$row;
$version = fn()=>live2_revision($db->trips[1], $db->extras[1] ?? null);
$db->drivers[0]['beschikbare_jaren'] = '[2025]';
liveReject(fn()=>live2_mutate($db,['action'=>'assign','id'=>1,'revision'=>$version(),'chauffeurId'=>'2'],$office,[],'key'), 'Inactive driver assigned to a new trip');
liveCheck($db->trips[1]['chauffeur'] === '', 'Rejected assignment changed the trip');
liveCheck(!live2_visible($db, $db->trips[1], $driver), 'Inactive driver can see available trips');
$db->drivers[0]['beschikbare_jaren'] = '[2026]';
liveCheck(chauffeur_beschikbaar($db, 'Anna', 2026), 'Current year availability missing');
liveCheck(!chauffeur_beschikbaar($db, 'Anna', 2027), 'Availability carried over into next year');
liveCheck(chauffeur_mobiel('06-1234 5678') === '0612345678', '06 number not normalized');
liveCheck(chauffeur_mobiel('+31 6 12345678') === '0612345678', 'International mobile number not normalized');
liveCheck(chauffeur_mobiel('') === '', 'Optional mobile number rejected');
liveReject(fn()=>chauffeur_mobiel('0201234567'), 'Landline accepted as 06 number');
$sent = [];
$sender = function ($to,$subject,$body,$headers,$envelope) use (&$sent) { $sent[]=$to; return $to !== 'anna@example.test'; };
liveReject(fn()=>live2_mutate($db,['action'=>'assign','id'=>1,'revision'=>$version(),'chauffeurId'=>'2'],$driver,[],'key',$sender),'Driver assigned someone else');
live2_mutate($db,['action'=>'assign','id'=>1,'revision'=>$version(),'chauffeurId'=>'2'],$office,[],'key',$sender);
liveCheck($db->trips[1]['chauffeur']==='Anna' && !$db->outbox, 'Assignment must update production without mail');
$oldVersion=$version();
live2_mutate($db,['action'=>'save_schedule','id'=>1,'revision'=>$version(),'afhaalmoment'=>'2026-10-05','afhaaltijd'=>'10:15'],$driver,[],'key',$sender);
liveCheck($db->trips[1]['afhaaltijd']==='10:15' && live2_trip($db->trips[1],$db->drivers,$db->extras[1])['status']==='planning', 'Draft must stay planning');
liveReject(fn()=>live2_mutate($db,['action'=>'save_schedule','id'=>1,'revision'=>$oldVersion,'afhaalmoment'=>'2026-10-06','afhaaltijd'=>'11:00'],$driver,[],'key',$sender),'Stale save accepted');
liveReject(fn()=>live2_mutate($db,['action'=>'save_schedule','id'=>1,'revision'=>$version(),'afhaalmoment'=>'2026-10-06','afhaaltijd'=>'11:00'],$other,[],'key',$sender),'Other driver edited trip');
live2_mutate($db,['action'=>'confirm','id'=>1,'revision'=>$version(),'afhaalmoment'=>'2026-10-05','afhaaltijd'=>'10:15'],$driver,[],'key',$sender);
liveCheck(count($sent)===2 && $db->extras[1]['phase']==='planning' && $db->extras[1]['mail_error']!=='', 'Partial delivery must retain draft and show error');
$sender = function ($to,$subject,$body,$headers,$envelope) use (&$sent) { $sent[]=$to; return true; };
live2_mutate($db,['action'=>'retry_mail','id'=>1,'revision'=>$version()],$driver,[],'key',$sender);
liveCheck(count($sent)===3 && $sent[2]==='anna@example.test' && $db->extras[1]['phase']==='planned', 'Retry must skip already sent contact mail');
$photo=['id'=>'photo','name'=>'bon.png','type'=>'image/png','data'=>base64_encode('image-bytes')];
live2_mutate($db,['action'=>'finish','id'=>1,'revision'=>$version(),'gestort'=>'123.45','gereden'=>'12','opmerking'=>'Afrondingsnotitie','interneOpmerking'=>'ALLEEN INTERN 123'],$driver,[$photo],'key',$sender);
liveCheck($db->trips[1]['status']==='Afgehandeld' && $db->trips[1]['gestort']==='123.45' && $db->trips[1]['gereden']==='12', 'Finish must update old site fields');
liveCheck($db->trips[1]['opmerking']==='Contactnotitie', 'Finish must preserve contact note');
$state=live2_state($db,$office);
liveCheck(!isset($state['trips'][0]['receipts'][0]['data']) && count($state['mails'])===3, 'Public state must omit photo data');
$finish=array_values(array_filter($db->outbox,fn($m)=>$m['kind']==='Afronding'))[0];
$payload=json_decode($finish['payload'],true);
$message=live2_message($payload);
liveCheck($state['trips'][0]['interneOpmerking']==='ALLEEN INTERN 123', 'Internal note must persist on completed trip');
liveCheck(!str_contains(json_encode($payload),'ALLEEN INTERN 123'), 'Internal note must never enter queued email or its preview');
liveCheck(str_contains($message['body'],base64_encode('image-bytes')) && str_contains($message['headers'],'Cc: collecte@nierstichting.nl') && str_contains($message['headers'],'Bcc: anna@example.test'), 'Finish must attach receipt and preserve recipients');
liveCheck(str_contains($payload['html'],'Afrondingsnotitie') && str_contains($payload['html'],'token='), 'Real mail must retain HTML, note and document link');
liveReject(fn()=>live2_mutate($db,['action'=>'finish','id'=>1,'revision'=>$version(),'gestort'=>'999','gereden'=>'1'],$driver,[],'key',$sender),'Completed trip was edited');
$create=['action'=>'create','requestKey'=>str_repeat('a',32),'collectegebied'=>'Nieuw gebied','gebiedsnummer'=>'44','wijknaam'=>'','contactpersoon'=>'Piet',
    'adres'=>'Straat 12','postcodePlaats'=>'1234 AB Stad','telefoonnummer'=>'0612345678','email'=>'piet@example.test','verwachtBedrag'=>'50','soort'=>'Munten','contactOpmerking'=>'Vraag','voorkeurAfhaalmoment'=>''];
$createdMessage=live2_mutate($db,$create,$office,[],'key',$sender);
$sentBeforeRetry=count($sent);
$repeatedMessage=live2_mutate($db,$create,$office,[],'key',$sender);
liveCheck(count($db->trips)===2 && $db->trips[2]['soort']==='alleen muntgeld', 'Create retries must not duplicate trips and must map old money types');
liveCheck(str_contains($createdMessage,'uitgezet bij chauffeur Anna') && str_contains($repeatedMessage,'Anna') && count($sent)===$sentBeforeRetry, 'Create must name selected driver and must not send duplicate offers');
liveCheck($db->offers[2]['chauffeur_naam']==='Anna' && $db->trips[2]['chauffeur']==='', 'Offering must register in old portal without assigning prematurely');
liveCheck(live2_state($db,$office)['trips'][1]['aangebodenChauffeur']==='Anna','Reload must show active offered driver');
$db->templates=[]; $count=count($db->trips); $sentCount=count($sent);
liveReject(fn()=>live2_mutate($db,array_replace($create,['requestKey'=>str_repeat('b',32)]),$office,[],'key',$sender),'Missing live template accepted');
liveCheck(count($db->trips)===$count && count($sent)===$sentCount && !$db->inTransaction(), 'Template failure must roll back creation before mail');
$draftRow=array_replace($row,['chauffeur'=>'Anna','afhaalmoment'=>'2026-10-05','afhaaltijd'=>'10:15']);
$extra=['revision'=>5,'version'=>ritVersion($draftRow),'phase'=>'planning','row_data'=>json_encode($draftRow),'receipts'=>'[]','finish_note'=>'','mail_error'=>''];
$contactEdit=array_replace($draftRow,['telefoonnummer'=>'0622222222']);
liveCheck(live2_trip($contactEdit,$db->drivers,$extra)['status']==='planning', 'Contact change from old portal must not confirm a draft');
$scheduleEdit=array_replace($draftRow,['afhaaltijd'=>'11:00']);
liveCheck(live2_trip($scheduleEdit,$db->drivers,$extra)['status']==='planned', 'Schedule changed in old portal must become visible');
$unknownDb=new LiveMemoryPDO(); $unknownDb->trips[1]=$row;
$unknownVersion=fn()=>live2_revision($unknownDb->trips[1],$unknownDb->extras[1] ?? null);
$crashSender=function () { throw new RuntimeException('Mail transport interrupted after handoff'); };
live2_mutate($unknownDb,['action'=>'assign','id'=>1,'revision'=>$unknownVersion(),'chauffeurId'=>'2'],$office,[],'key',$crashSender);
live2_mutate($unknownDb,['action'=>'confirm','id'=>1,'revision'=>$unknownVersion(),'afhaalmoment'=>'2026-10-05','afhaaltijd'=>'10:15'],$driver,[],'key',$crashSender);
$retrySends=0;
live2_mutate($unknownDb,['action'=>'retry_mail','id'=>1,'revision'=>$unknownVersion()],$driver,[],'key',function () use (&$retrySends) { $retrySends++; return true; });
liveCheck($retrySends===0 && $unknownDb->extras[1]['phase']==='planning' && str_contains($unknownDb->extras[1]['mail_error'],'beheerder'), 'Uncertain delivery must not resend automatically');
$offerDb=new LiveMemoryPDO(); $offerSends=[];
$offerSender=function ($to) use (&$offerSends) { $offerSends[]=$to; return $to !== 'anna@example.test'; };
$offerMessage=live2_mutate($offerDb,$create,$office,[],'key',$offerSender);
liveCheck(!$offerDb->offers && str_contains($offerMessage,'nog niet succesvol verstuurd') && count($offerSends)===2, 'Failed proposal must not register an offer or report success');
$offerVersion=live2_revision($offerDb->trips[2],$offerDb->extras[2]);
$retryMessage=live2_mutate($offerDb,['action'=>'retry_mail','id'=>2,'revision'=>$offerVersion],$office,[],'key',function ($to) use (&$offerSends) { $offerSends[]=$to; return true; });
liveCheck(count($offerSends)===3 && $offerSends[2]==='anna@example.test' && str_contains($retryMessage,'Anna'), 'Retry proposal must skip contact mail and report selected driver');
$noDriverDb=new LiveMemoryPDO(); $noDriverDb->drivers=[];
$noDriverMessage=live2_mutate($noDriverDb,$create,$office,[],'key',fn()=>true);
liveCheck(count($noDriverDb->trips)===1 && !$noDriverDb->offers && str_contains($noDriverMessage,'Geen chauffeurs gevonden'), 'Selection failure must keep rit and explain why nobody was contacted');
$noDriverDb->drivers=$db->drivers;
$fixedVersion=live2_revision($noDriverDb->trips[2],$noDriverDb->extras[2]);
$fixedMessage=live2_mutate($noDriverDb,['action'=>'retry_mail','id'=>2,'revision'=>$fixedVersion],$office,[],'key',fn()=>true);
liveCheck(str_contains($fixedMessage,'Anna') && count($noDriverDb->outbox)===2, 'Retry selection after fixing drivers must create one proposal');
$contactFailDb=new LiveMemoryPDO();
live2_mutate($contactFailDb,$create,$office,[],'key',fn()=>false);
liveCheck(count($contactFailDb->outbox)===1 && !$contactFailDb->offers, 'Driver proposal must wait until contact confirmation succeeds');
echo "Live portal service checks passed (in-memory database and fake mail transport).\n";

$settings=portal_settings_save($db,['year'=>2027,'rate'=>'0,35','revision'=>1]);
liveCheck($settings['currentYear']===2027 && $settings['years'][2026]['kilometervergoeding']==='0.30' && $settings['years'][2027]['kilometervergoeding']==='0.35','Year-specific rates must persist independently');
liveReject(fn()=>portal_settings_save($db,['year'=>2028,'rate'=>'0.40','revision'=>1]),'Stale settings accepted');
liveReject(fn()=>portal_settings_save($db,['year'=>2027,'rate'=>'-1','revision'=>2]),'Invalid rate accepted');
liveCheck(live2_trip($db->trips[1],$db->drivers,$db->extras[1])['collectejaar']===2026,'Old trips changed year');
echo "Settings checks passed.\n";

$correctDb = new LiveMemoryPDO();
$correctDb->trips[1] = array_replace($row, ['status'=>'Afgehandeld', 'chauffeur'=>'Anna', 'gereden'=>10, 'gestort'=>'20.00']);
$correctVersion = fn()=>live2_revision($correctDb->trips[1], $correctDb->extras[1] ?? null);
$correction = ['action'=>'correct_done', 'id'=>1, 'revision'=>$correctVersion(), 'gereden'=>'12', 'gestort'=>'25.50', 'confirmKilometers'=>'12.00', 'confirmAmount'=>'25.50'];
liveReject(fn()=>live2_mutate($correctDb,$correction,$driver,[],'key'), 'Driver corrected a completed trip');
$unconfirmed = $correction; unset($unconfirmed['confirmAmount']);
liveReject(fn()=>live2_mutate($correctDb,$unconfirmed,$office,[],'key'), 'Unconfirmed amount accepted');
live2_mutate($correctDb,$correction,$office,[],'key');
$correctTrip = live2_trip($correctDb->trips[1],$correctDb->drivers,$correctDb->extras[1]);
liveCheck($correctTrip['status']==='done' && $correctTrip['gereden']==='12' && $correctTrip['gestort']==='25.50' && $correctTrip['bedragAangepastDoor']===$office['name'], 'Correction or attribution not persisted');
liveCheck(!$correctDb->outbox, 'Correction sent new mail');
liveReject(fn()=>live2_mutate($correctDb,$correction,$office,[],'key'), 'Stale correction accepted');
$correction['revision']=$correctVersion(); $correction['gereden']='13'; $correction['confirmKilometers']='13.00';
$otherOffice=array_replace($office,['name'=>'Andere medewerker']);
live2_mutate($correctDb,$correction,$otherOffice,[],'key');
liveCheck(live2_trip($correctDb->trips[1],$correctDb->drivers,$correctDb->extras[1])['bedragAangepastDoor']===$office['name'], 'Kilometer-only correction overwrote amount attribution');
$correction['revision']=$correctVersion(); $correction['gereden']='13.5'; $correction['confirmKilometers']='13.50';
liveReject(fn()=>live2_mutate($correctDb,$correction,$office,[],'key'), 'Fractional kilometers accepted');
echo "Completed trip correction checks passed.\n";

$planningDb = new LiveMemoryPDO();
$planningDb->trips[1] = array_replace($row, ['chauffeur'=>'', 'afhaalmoment'=>'', 'afhaaltijd'=>'', 'status'=>'-']);
$planningVersion = fn()=>live2_revision($planningDb->trips[1], $planningDb->extras[1] ?? null);
$assignment = ['action'=>'assign', 'id'=>1, 'chauffeurId'=>'2', 'revision'=>$planningVersion(),
    'verwachtBedrag'=>'125.50', 'voorkeurAfhaalmoment'=>'2026-10-12', 'afhaalmoment'=>'2026-10-13', 'afhaaltijd'=>'14:30'];
live2_mutate($planningDb, $assignment, $office, [], 'key');
liveCheck($planningDb->trips[1]['verwachtBedrag']==='125.50' && $planningDb->trips[1]['voorkeurAfhaalmoment']==='2026-10-12'
    && $planningDb->trips[1]['afhaalmoment']==='2026-10-13' && $planningDb->trips[1]['afhaaltijd']==='14:30', 'Assignment edits were not saved');
$scheduleEdit = ['action'=>'save_schedule', 'id'=>1, 'revision'=>$planningVersion(),
    'verwachtBedrag'=>'225.75', 'voorkeurAfhaalmoment'=>'2026-10-14', 'afhaalmoment'=>'2026-10-15', 'afhaaltijd'=>'15:45'];
$invalidEdit = array_replace($scheduleEdit, ['verwachtBedrag'=>'-1']);
liveReject(fn()=>live2_mutate($planningDb, $invalidEdit, $office, [], 'key'), 'Negative expected amount accepted');
live2_mutate($planningDb, $scheduleEdit, array_replace($office, ['admin'=>true]), [], 'key');
liveCheck($planningDb->trips[1]['verwachtBedrag']==='225.75' && $planningDb->trips[1]['voorkeurAfhaalmoment']==='2026-10-14'
    && $planningDb->trips[1]['afhaaltijd']==='15:45', 'Admin planning edits were not saved');
$scheduleEdit['revision']=$planningVersion();
$scheduleEdit['verwachtBedrag']='999.00';
$scheduleEdit['voorkeurAfhaalmoment']='2026-10-20';
live2_mutate($planningDb, $scheduleEdit, $driver, [], 'key');
liveCheck($planningDb->trips[1]['verwachtBedrag']==='225.75' && $planningDb->trips[1]['voorkeurAfhaalmoment']==='2026-10-14', 'Driver changed office-only expectation fields');
liveCheck(!$planningDb->outbox, 'Saving planning edits unexpectedly sent mail');
echo "Expectation editing checks passed.\n";

$scheduleEdit['action']='confirm'; $scheduleEdit['revision']=$planningVersion();
live2_mutate($planningDb, $scheduleEdit, $office, [], 'key', fn()=>true);
foreach ($planningDb->outbox as $mail) liveCheck(!str_starts_with(json_decode($mail['payload'],true)['subject'], 'HERZIENING '), 'First appointment marked as revision');
$scheduleEdit['action']='save_schedule'; $scheduleEdit['revision']=$planningVersion(); $scheduleEdit['afhaaltijd']='16:00';
live2_mutate($planningDb, $scheduleEdit, $office, [], 'key');
liveCheck(live2_trip($planningDb->trips[1],$planningDb->drivers,$planningDb->extras[1])['status']==='planned', 'Saving revision removed confirmed phase');
$previousMailCount=count($planningDb->outbox);
$scheduleEdit['action']='confirm'; $scheduleEdit['revision']=$planningVersion();
live2_mutate($planningDb, $scheduleEdit, $office, [], 'key', fn()=>true);
$revisionMails=array_slice(array_values($planningDb->outbox),$previousMailCount);
liveCheck(count($revisionMails)>0, 'Revision did not send appointment mails');
foreach ($revisionMails as $mail) liveCheck(str_starts_with(json_decode($mail['payload'],true)['subject'], 'HERZIENING '), 'Revised appointment missing subject prefix');
echo "Appointment revision checks passed.\n";

$receiptEdit = ['action'=>'correct_done', 'id'=>1, 'revision'=>$correctVersion(), 'gereden'=>'13', 'gestort'=>'25.50'];
$newReceipt = ['id'=>'office-receipt', 'name'=>'bon.jpg', 'type'=>'image/jpeg', 'data'=>base64_encode('test photo')];
liveReject(fn()=>live2_mutate($correctDb,$receiptEdit,$driver,[$newReceipt],'key'), 'Driver added a receipt after completion');
live2_mutate($correctDb,$receiptEdit,array_replace($office,['admin'=>false]),[$newReceipt],'key');
$receiptTrip=live2_trip($correctDb->trips[1],$correctDb->drivers,$correctDb->extras[1]);
liveCheck($receiptTrip['status']==='done' && count($receiptTrip['receipts'])===1 && $receiptTrip['receipts'][0]['id']==='office-receipt', 'Employee receipt-only edit was not saved');
liveCheck(!$correctDb->outbox && $receiptTrip['gereden']==='13' && $receiptTrip['gestort']==='25.50', 'Receipt edit changed totals or sent mail');
$receiptEdit['revision']=$correctVersion();
liveReject(fn()=>live2_mutate($correctDb,$receiptEdit,$office,[$newReceipt,$newReceipt,$newReceipt],'key'), 'Too many completed-trip receipts accepted');
$receiptEdit['removeReceipts']=['office-receipt'];
live2_mutate($correctDb,$receiptEdit,$office,[],'key');
liveCheck(!live2_trip($correctDb->trips[1],$correctDb->drivers,$correctDb->extras[1])['receipts'], 'Admin could not remove completed-trip receipt');
echo "Completed trip attachment checks passed.\n";

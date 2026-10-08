<?php
function live2_templates(PDO $pdo): array {
    $labels=[1=>'Eerste bevestiging aan contactpersoon',3=>'Ophaalbevestiging aan chauffeur',4=>'Ophaalbevestiging aan contactpersoon',5=>'Afronding',6=>'Afronding wijk (WCO)'];
    $result=[]; $stored=[];
    foreach ($pdo->query('SELECT id, email_template FROM instellingen WHERE id IN (1,3,4,5,6) ORDER BY id')->fetchAll() as $row) {
        $stored[(int)$row['id']]=(string)$row['email_template'];
    }
    foreach ($labels as $id=>$name) {
        $exists=array_key_exists($id,$stored); $text=$stored[$id] ?? '';
        $result[]=['id'=>$id,'name'=>$name,'text'=>$text,'missing'=>!$exists,'revision'=>$exists ? hash('sha256',$text) : null];
    }
    return $result;
}
function live2_template_save(PDO $pdo,array $input): array {
    $id=(int)($input['id'] ?? 0);
    if (!in_array($id,[1,3,4,5,6],true)) throw new InvalidArgumentException('Ongeldig mailsjabloon.');
    $text=$input['text'] ?? null;
    if (!is_string($text) || trim($text)==='' || strlen($text)>60000) throw new InvalidArgumentException('Vul een e-mailtekst in van maximaal 60.000 bytes.');
    $q=$pdo->prepare('SELECT email_template FROM instellingen WHERE id=?'); $q->execute([$id]); $old=$q->fetchColumn();
    if ($old===false && array_key_exists('revision',$input) && $input['revision']===null) {
        try {
            $q=$pdo->prepare('INSERT INTO instellingen (id,email_template) VALUES (?,?)'); $q->execute([$id,$text]);
        } catch (PDOException $error) {
            if ((string)$error->getCode()==='23000') throw new DomainException('Dit sjabloon is ondertussen aangemaakt. Herlaad de sjablonen.');
            throw $error;
        }
        return ['id'=>$id,'text'=>$text,'missing'=>false,'revision'=>hash('sha256',$text)];
    }
    if ($old===false || !hash_equals(hash('sha256',(string)$old),(string)($input['revision'] ?? ''))) throw new DomainException('Dit sjabloon is ondertussen gewijzigd. Herlaad de sjablonen voordat je opslaat.');
    preg_match_all('/\[[a-z][a-z0-9_]*\]/i',(string)$old,$matches);
    foreach (array_unique($matches[0]) as $token) if (stripos($text,$token)===false) throw new InvalidArgumentException('Behoud het invulveld '.$token.' in de tekst.');
    if ($text!==(string)$old) {
        $q=$pdo->prepare('UPDATE instellingen SET email_template=? WHERE id=? AND BINARY email_template=BINARY ?');
        $q->execute([$text,$id,$old]);
        if ($q->rowCount()!==1) throw new DomainException('Dit sjabloon is ondertussen gewijzigd. Herlaad de sjablonen voordat je opslaat.');
    }
    return ['id'=>$id,'text'=>$text,'revision'=>hash('sha256',$text)];
}

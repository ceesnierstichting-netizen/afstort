<?php
function portal_settings_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS afstort_settings (id INT PRIMARY KEY, data TEXT NOT NULL, revision INT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q=$pdo->prepare('INSERT IGNORE INTO afstort_settings (id,data) VALUES (1,?)');
    $q->execute([json_encode(['currentYear'=>2026,'years'=>[2026=>['kilometervergoeding'=>'0.30']]])]);
    if (!$pdo->query("SHOW COLUMNS FROM ritten LIKE 'collectejaar'")->fetch()) $pdo->exec('ALTER TABLE ritten ADD COLUMN collectejaar SMALLINT NOT NULL DEFAULT 2026');
}
function portal_settings(PDO $pdo): array {
    $row=$pdo->query('SELECT data, revision FROM afstort_settings WHERE id=1')->fetch();
    $settings=json_decode($row['data'],true,512,JSON_THROW_ON_ERROR);
    $settings['revision']=(int)$row['revision']; return $settings;
}
function portal_settings_save(PDO $pdo,array $input): array {
    $year=filter_var($input['year'] ?? '',FILTER_VALIDATE_INT);
    $rate=str_replace(',', '.',trim((string)($input['rate'] ?? '')));
    if (!$year || $year<2000 || $year>2099 || !preg_match('/^\d{1,2}(?:\.\d{1,2})?$/D',$rate) || (float)$rate>10) throw new InvalidArgumentException('Gebruik een jaar tussen 2000 en 2099 en een vergoeding tussen € 0,00 en € 10,00.');
    $settings=portal_settings($pdo); $settings['currentYear']=$year;
    $settings['years'][(string)$year]=['kilometervergoeding'=>number_format((float)$rate,2,'.','')]; unset($settings['revision']);
    $q=$pdo->prepare('UPDATE afstort_settings SET data=?, revision=revision+1 WHERE id=1 AND revision=?');
    $q->execute([json_encode($settings,JSON_THROW_ON_ERROR),(int)($input['revision'] ?? 0)]);
    if ($q->rowCount()!==1) throw new DomainException('Een andere beheerder heeft de instellingen gewijzigd. Herlaad Beheer en probeer opnieuw.');
    return portal_settings($pdo);
}

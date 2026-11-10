<?php
function portal_settings_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS afstort_settings (id INT PRIMARY KEY, data TEXT NOT NULL, revision INT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q=$pdo->prepare('INSERT IGNORE INTO afstort_settings (id,data) VALUES (1,?)');
    $q->execute([json_encode(['currentYear'=>2026,'years'=>[2026=>['kilometervergoeding'=>'0.30']]])]);
    if (!$pdo->query("SHOW COLUMNS FROM ritten LIKE 'collectejaar'")->fetch()) $pdo->exec('ALTER TABLE ritten ADD COLUMN collectejaar SMALLINT NOT NULL DEFAULT 2026');
    $pdo->exec("CREATE TABLE IF NOT EXISTS collectejaren (jaar SMALLINT PRIMARY KEY, kilometervergoeding DECIMAL(4,2) NOT NULL, is_huidig TINYINT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Migrate existing preferences without moving or replacing historical trips.
    $pdo->beginTransaction();
    try {
        $row = $pdo->query('SELECT data, revision FROM afstort_settings WHERE id=1 FOR UPDATE')->fetch();
        $legacy = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
        $insert = $pdo->prepare('INSERT IGNORE INTO collectejaren (jaar, kilometervergoeding, is_huidig) VALUES (?, ?, ?)');
        foreach ($legacy['years'] as $year => $details) $insert->execute([(int)$year, $details['kilometervergoeding'], (int)$year === (int)$legacy['currentYear'] ? 1 : 0]);
        $pdo->exec('INSERT IGNORE INTO collectejaren (jaar, kilometervergoeding, is_huidig) SELECT DISTINCT collectejaar, 0.30, 0 FROM ritten');
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function portal_settings(PDO $pdo): array {
    $row=$pdo->query('SELECT data, revision FROM afstort_settings WHERE id=1')->fetch();
    $settings=json_decode($row['data'],true,512,JSON_THROW_ON_ERROR);
    $settings['years'] = [];
    foreach ($pdo->query('SELECT jaar, kilometervergoeding, is_huidig FROM collectejaren ORDER BY jaar')->fetchAll() as $year) {
        $settings['years'][(int)$year['jaar']] = ['kilometervergoeding'=>number_format((float)$year['kilometervergoeding'], 2, '.', '')];
        if ((int)$year['is_huidig'] === 1) $settings['currentYear'] = (int)$year['jaar'];
    }
    $settings['revision']=(int)$row['revision']; return $settings;
}
function portal_settings_save(PDO $pdo,array $input): array {
    $year=filter_var($input['year'] ?? '',FILTER_VALIDATE_INT);
    $operation = $input['operation'] ?? 'legacy';
    if (!in_array($operation, ['legacy', 'saveYear', 'setCurrent'], true)) throw new InvalidArgumentException('Onbekende jaaractie.');
    $rate=str_replace(',', '.',trim((string)($input['rate'] ?? '')));
    if (!$year || $year<2000 || $year>2099 || ($operation !== 'setCurrent' && (!preg_match('/^\d{1,2}(?:\.\d{1,2})?$/D',$rate) || (float)$rate>10))) throw new InvalidArgumentException('Gebruik een jaar tussen 2000 en 2099 en een vergoeding tussen € 0,00 en € 10,00.');
    if (isset($input['makeCurrent']) && !is_bool($input['makeCurrent'])) throw new InvalidArgumentException('Ongeldige keuze voor het standaardjaar.');
    $pdo->beginTransaction();
    try {
        $pdo->query('SELECT data, revision FROM afstort_settings WHERE id=1 FOR UPDATE')->fetch();
        $settings=portal_settings($pdo);
        if ($settings['revision'] !== (int)($input['revision'] ?? 0)) throw new DomainException('Een andere beheerder heeft de instellingen gewijzigd. Herlaad Beheer en probeer opnieuw.');
        if ($operation === 'setCurrent' && !isset($settings['years'][$year])) throw new InvalidArgumentException('Maak dit collectejaar eerst aan.');
        $makeCurrent = $operation === 'setCurrent' || ($operation === 'legacy' && ($input['makeCurrent'] ?? true));
        if ($operation !== 'setCurrent') {
            $rate = number_format((float)$rate, 2, '.', '');
            $pdo->prepare('INSERT INTO collectejaren (jaar, kilometervergoeding, is_huidig) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE kilometervergoeding=VALUES(kilometervergoeding)')->execute([$year, $rate]);
            $settings['years'][$year] = ['kilometervergoeding'=>$rate];
        }
        if ($makeCurrent) {
            $pdo->exec('UPDATE collectejaren SET is_huidig=0 WHERE is_huidig=1');
            $pdo->prepare('UPDATE collectejaren SET is_huidig=1 WHERE jaar=?')->execute([$year]);
            $settings['currentYear'] = $year;
        }
        // Keep legacy metadata in sync for migration and compatibility.
        unset($settings['revision']);
        $q=$pdo->prepare('UPDATE afstort_settings SET data=?, revision=revision+1 WHERE id=1 AND revision=?');
        $q->execute([json_encode($settings,JSON_THROW_ON_ERROR),(int)($input['revision'] ?? 0)]);
        if ($q->rowCount()!==1) throw new DomainException('Een andere beheerder heeft de instellingen gewijzigd. Herlaad Beheer en probeer opnieuw.');
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return portal_settings($pdo);
}

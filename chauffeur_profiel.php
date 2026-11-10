<?php
function chauffeur_profiel_schema(PDO $pdo): void {
    if (!$pdo->query("SHOW COLUMNS FROM chauffeurs LIKE 'mobiel'")->fetch()) {
        $pdo->exec("ALTER TABLE chauffeurs ADD COLUMN mobiel VARCHAR(20) NOT NULL DEFAULT ''");
    }
    if (!$pdo->query("SHOW COLUMNS FROM chauffeurs LIKE 'beschikbare_jaren'")->fetch()) {
        require_once __DIR__ . '/portal_settings.php';
        portal_settings_schema($pdo);
        $year = (int)portal_settings($pdo)['currentYear'];
        $pdo->exec('ALTER TABLE chauffeurs ADD COLUMN beschikbare_jaren TEXT DEFAULT NULL');
        $pdo->prepare('UPDATE chauffeurs SET beschikbare_jaren = ? WHERE beschikbare_jaren IS NULL')->execute([json_encode([$year])]);
    }
}

function chauffeur_jaren($value): array {
    if (!is_array($value)) throw new InvalidArgumentException('Kies de beschikbare collectejaren.');
    $years = [];
    foreach ($value as $year) {
        $year = filter_var($year, FILTER_VALIDATE_INT);
        if (!$year || $year < 2000 || $year > 2099) throw new InvalidArgumentException('Gebruik collectejaren tussen 2000 en 2099.');
        $years[] = $year;
    }
    $years = array_values(array_unique($years)); sort($years);
    return $years;
}

function chauffeur_beschikbaar(PDO $pdo, string $name, int $year): bool {
    $q = $pdo->prepare('SELECT beschikbare_jaren FROM chauffeurs WHERE naam = ?');
    $q->execute([$name]);
    $years = json_decode((string)$q->fetchColumn(), true);
    return is_array($years) && in_array($year, $years, true);
}

function chauffeur_mobiel($value): string {
    if (!is_scalar($value) && $value !== null) throw new InvalidArgumentException('Vul een geldig 06-nummer in.');
    $value = preg_replace('/[\s().\/-]+/', '', trim((string)$value));
    if (preg_match('/^(?:\+31|0031)6([0-9]{8})$/D', $value, $match)) $value = '06' . $match[1];
    if ($value !== '' && !preg_match('/^06[0-9]{8}$/D', $value)) {
        throw new InvalidArgumentException('Vul een geldig 06-nummer in, bijvoorbeeld 06-12345678.');
    }
    return $value;
}

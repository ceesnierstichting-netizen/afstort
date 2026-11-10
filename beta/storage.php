<?php
require_once __DIR__ . '/domain.php';

// The PHP guard also protects the data when Apache .htaccess is unavailable.
const BETA_DATA_GUARD = "<?php http_response_code(404); exit; ?>\n";

function beta_with_store(callable $callback, string $dataset = 'practice', string $owner = '') {
    $dir = getenv('AFSTORT_BETA_DATA_DIR') ?: __DIR__ . '/data';
    if (!is_dir($dir) || !is_writable($dir)) throw new RuntimeException('De map beta/data moet schrijfbaar zijn voor PHP.');
    if (!in_array($dataset, ['practice', 'snapshot'], true)) throw new InvalidArgumentException('Onbekende testomgeving.');
    if ($dataset === 'snapshot' && $owner === '') throw new DomainException('Een eigenaar is verplicht voor de testkopie.');
    $prefix = $dataset === 'snapshot' ? 'snapshot-' . hash('sha256', $owner) : 'store';
    $lock = fopen($dir . '/' . $prefix . '.lock.php', 'c+');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('De testgegevens zijn tijdelijk niet beschikbaar.');
    try {
        $path = $dir . '/' . $prefix . '.php';
        $state = $dataset === 'snapshot' ? ['trips'=>[], 'mails'=>[], 'templates'=>[], 'meta'=>['source'=>'snapshot','importedAt'=>null]] : beta_initial_state();
        if (is_file($path)) {
            $raw = file_get_contents($path);
            if ($raw === false || substr($raw, 0, strlen(BETA_DATA_GUARD)) !== BETA_DATA_GUARD) throw new RuntimeException('De testopslag kan niet worden gelezen.');
            $state = json_decode(substr($raw, strlen(BETA_DATA_GUARD)), true, 512, JSON_THROW_ON_ERROR);
        }
        $result = $callback($state);
        $payload = BETA_DATA_GUARD . json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        // Write a complete replacement before moving it into place under the same lock.
        $temp = $dir . '/write-' . bin2hex(random_bytes(8)) . '.php';
        try {
            if (file_put_contents($temp, $payload) !== strlen($payload)) throw new RuntimeException('Testgegevens konden niet worden opgeslagen.');
            @chmod($temp, 0600);
            if (!rename($temp, $path)) throw new RuntimeException('Testgegevens konden niet worden vervangen.');
        } finally {
            if (is_file($temp)) unlink($temp);
        }
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

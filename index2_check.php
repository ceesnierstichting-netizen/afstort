<?php
// Standalone deployment check: never loads configuration, sessions or trip data.
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
echo "INDEX2 CONTROLE 1\n";
echo 'PHP-versie: ' . PHP_VERSION . "\n";
echo 'PDO MySQL: ' . (extension_loaded('pdo_mysql') ? 'OK' : 'ONTBREEKT') . "\n\n";
$files = array('index2.php', 'session.php', 'config.php', 'app_helpers.php',
    'rit_concurrency.php', 'chauffeur_selectie.php', 'chauffeur_profiel.php',
    'portal_settings.php', 'live2/workflow.php', 'live2/mail.php', 'live2/source.php',
    'live2/controller.php', 'live2/domain.php', 'live2/templates.php', 'live2/live.js');
foreach ($files as $file) {
    $path = __DIR__ . '/' . $file;
    if (!is_readable($path)) {
        echo $file . ": ONTBREEKT of niet leesbaar\n";
        continue;
    }
    $source = file_get_contents($path);
    echo $file . ': aanwezig (' . strlen($source) . ' bytes), SHA256 ' . substr(hash('sha256', $source), 0, 12);
    if (substr($file, -4) === '.php' && defined('TOKEN_PARSE')) {
        try {
            token_get_all($source, TOKEN_PARSE);
            echo ', syntax OK';
        } catch (Throwable $error) {
            echo ', SYNTAXFOUT op regel ' . $error->getLine();
        }
    }
    echo "\n";
}
echo "\nKopieer deze controle-uitvoer naar de chat. Dit bestand leest geen database of inloggegevens.\n";

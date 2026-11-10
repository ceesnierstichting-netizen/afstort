<?php
require_once __DIR__ . '/../app_helpers.php';
function checkChauffeurMail($condition) {
    if (!$condition) throw new RuntimeException('Chauffeurmail onjuist opgebouwd.');
}
$html = '<p>Beste René &amp; Jan</p><p>' . str_repeat('Lange tekst é ', 100) . '</p><a href="https://example.test/rit?id=1&amp;x=2">Bekijk rit</a>';
$mail = afstort_chauffeur_mail_message($html);
checkChauffeurMail($mail['envelope'] === '-fnoreply@nierstichtingnederland.nl');
checkChauffeurMail(strpos($mail['headers'], 'From: Nierstichting <noreply@nierstichtingnederland.nl>') !== false);
checkChauffeurMail(strpos($mail['headers'], 'Reply-To: noreply@nierstichtingnederland.nl') !== false);
checkChauffeurMail(preg_match('/boundary="([^"]+)"/', $mail['headers'], $match) === 1);
$parts = explode('--' . $match[1], $mail['body']);
checkChauffeurMail(count($parts) === 4 && trim($parts[3]) === '--');
foreach ([1, 2] as $i) {
    [$headers, $encoded] = explode("\r\n\r\n", $parts[$i], 2);
    checkChauffeurMail(strpos($headers, 'Content-Transfer-Encoding: quoted-printable') !== false);
    foreach (explode("\r\n", trim($encoded)) as $line) checkChauffeurMail(strlen($line) <= 76);
    $decoded = quoted_printable_decode(rtrim($encoded, "\r\n"));
    if ($i === 2) checkChauffeurMail($decoded === $html);
    else {
        checkChauffeurMail(strpos($decoded, 'Beste René & Jan') !== false);
        checkChauffeurMail(strpos($decoded, 'Bekijk rit (https://example.test/rit?id=1&x=2)') !== false);
    }
}
$next = afstort_chauffeur_mail_message($html);
checkChauffeurMail($next['headers'] !== $mail['headers']);
echo "Chauffeur mail checks passed.\n";

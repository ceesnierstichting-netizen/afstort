<?php
require_once __DIR__ . '/../rit_concurrency.php';
function checkRitVersion(bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException($label);
}
$rit = ['id'=>12, 'chauffeur'=>'Anna', 'afhaaltijd'=>'10:00', 'gestort'=>'100'];
checkRitVersion(ritVersion($rit) === ritVersion(array_reverse($rit, true)), 'Veldvolgorde mag de versie niet veranderen.');
checkRitVersion(ritVersion($rit) === ritVersion($rit + ['heeft_openstaande_aanbieding'=>1]), 'Weergavevlaggen zijn geen ritwijziging.');
checkRitVersion(ritVersion($rit) !== ritVersion(array_replace($rit, ['afhaaltijd'=>'11:00'])), 'Afhaaltijdwijziging moet worden gedetecteerd.');
checkRitVersion(ritVersion($rit) !== ritVersion(array_replace($rit, ['gestort'=>'200'])), 'Bedragwijziging moet worden gedetecteerd.');
checkRitVersion(ritVersion($rit) !== ritVersion(array_replace($rit, ['chauffeur'=>'Bram'])), 'Toewijzing moet worden gedetecteerd.');
echo "Ritversiecontroles geslaagd.\n";

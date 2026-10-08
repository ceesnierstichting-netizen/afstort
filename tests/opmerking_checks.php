<?php
require_once __DIR__ . '/../app_helpers.php';

function checkOpmerking($expected, $actual) {
    if ($expected !== $actual) throw new RuntimeException('Opmerking wordt niet correct verwerkt.');
}
checkOpmerking('Voor  na', afstort_replace_opmerking('Voor [opmerking] na', ''));
$prefix = '<br><br><strong><em style="font-size:12pt;">';
$suffix = '</em></strong>';
checkOpmerking($prefix . 'Bel &lt;Jan&gt; &amp; Piet' . $suffix, afstort_replace_opmerking('[OPMERKING]', 'Bel <Jan> & Piet'));
checkOpmerking($prefix . "Eerste<br />\nTweede" . $suffix, afstort_replace_opmerking('[opmerking]', "Eerste\nTweede"));
checkOpmerking($prefix . '0' . $suffix . ' / ' . $prefix . '0' . $suffix, afstort_replace_opmerking('[opmerking] / [opmerking]', '0'));
checkOpmerking($prefix . '$1 [contact]' . $suffix, afstort_replace_opmerking('[opmerking]', '$1 [contact]'));
foreach (['PS ', 'PS: ', 'P.S. '] as $ps) {
    checkOpmerking('Naam ' . $prefix . 'Bel vooraf' . $suffix, afstort_replace_opmerking('Naam ' . $ps . '[opmerking]', 'Bel vooraf'));
    checkOpmerking('Naam ', afstort_replace_opmerking('Naam ' . $ps . '[opmerking]', ''));
}
echo "Opmerking checks passed.\n";

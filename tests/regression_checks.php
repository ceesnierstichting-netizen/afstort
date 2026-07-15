<?php

require_once __DIR__ . '/../app_helpers.php';

function assertSameValue($expected, $actual, $label) {
    if ($expected !== $actual) {
        fwrite(STDERR, $label . " mislukt.\nVerwacht: " . var_export($expected, true) . "\nActueel: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

assertSameValue(true, normalizeFullAccess(1), 'full access integer');
assertSameValue(true, normalizeFullAccess('ja'), 'full access ja');
assertSameValue(false, normalizeFullAccess("\0"), 'full access nul-byte');
assertSameValue('1234AB', extractPostcode6('1234 ab Amsterdam'), 'extract pc6 met plaats');
assertSameValue('1234AB', extractPostcode6('1234AB'), 'extract pc6 compact');
assertSameValue('', extractPostcode6('1234 Amsterdam'), 'extract pc6 weigert postcode4-only');
assertSameValue(true, shouldReuseStoredCoordinates('1234 ab', '1234AB'), 'coordinaten behouden bij gelijke postcode');
assertSameValue(true, shouldReuseStoredCoordinates('1234 AB Amsterdam', '1234AB'), 'coordinaten behouden bij gelijke pc6 met plaatsnaam');
assertSameValue(false, shouldReuseStoredCoordinates('1234 ab', '1234 AC'), 'coordinaten wissen bij andere postcode');
assertSameValue(false, shouldReuseStoredCoordinates('1234', '1234 AB'), 'coordinaten wissen bij onvolledige nieuwe postcode');
assertSameValue(true, isMobileUserAgent('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)'), 'mobiele user agent iphone');
assertSameValue(false, isMobileUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64)'), 'desktop user agent windows');

fwrite(STDOUT, "Regression checks passed.\n");

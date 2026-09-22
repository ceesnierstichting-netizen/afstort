<?php

require_once __DIR__ . '/../app_helpers.php';
require_once __DIR__ . '/../twofa.php';

session_start();

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

$admin = ['id' => 1, 'naam' => 'Admin', 'email' => 'admin@example.test', 'fullAccess' => 1, 'is_medewerker' => 0];
$medewerker = ['id' => 2, 'naam' => 'Medewerker', 'email' => 'medewerker@example.test', 'fullAccess' => 0, 'is_medewerker' => 1];
$chauffeur = ['id' => 3, 'naam' => 'Chauffeur', 'email' => 'chauffeur@example.test', 'fullAccess' => 0, 'is_medewerker' => 0];
foreach ([[$admin, true, true], [$medewerker, true, false], [$chauffeur, false, false]] as [$user, $dashboard, $adminRights]) {
    assertSameValue($dashboard, hasDashboardAccess($user), $user['naam'] . ' dashboard');
    assertSameValue($adminRights, hasAdminPermissions($user), $user['naam'] . ' beheerdersacties');
    twofa_start_pending_login($user);
    assertSameValue(false, isset($_SESSION['fullAccess']), 'geen toegang voordat 2FA is afgerond');
    twofa_finish_login($user);
    assertSameValue($dashboard, $_SESSION['fullAccess'], $user['naam'] . ' sessietoegang na 2FA');
    assertSameValue($adminRights, hasAdminPermissions($_SESSION), $user['naam'] . ' sessierechten na 2FA');
}
assertSameValue(false, hasAdminPermissions(['fullAccess' => 1, 'is_medewerker' => 1]), 'medewerker blijft beperkt bij fullAccess');
assertSameValue(false, shouldUseMobileDriverView(hasDashboardAccess($medewerker)), 'medewerker krijgt geen chauffeursweergave');
assertSameValue(true, isSelectableMedewerkerChauffeur('Cees'), 'Cees is kiesbaar als chauffeur');
assertSameValue(true, isSelectableMedewerkerChauffeur(' cees '), 'chauffeursuitzondering negeert hoofdletters en spaties');
assertSameValue(false, isSelectableMedewerkerChauffeur('Nicole'), 'andere medewerkers zijn niet kiesbaar als chauffeur');
assertSameValue(true, canViewEmailRapport(['fullAccess' => 1]), 'full access ziet e-mailrapport');
assertSameValue(true, canViewEmailRapport(['fullAccess' => 'ja']), 'genormaliseerde full access ziet e-mailrapport');
assertSameValue(false, canViewEmailRapport(['fullAccess' => 0]), 'gebruiker zonder full access ziet e-mailrapport niet');

session_destroy();
fwrite(STDOUT, "Regression checks passed.\n");

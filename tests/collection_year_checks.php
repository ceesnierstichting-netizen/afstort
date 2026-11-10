<?php
require_once __DIR__ . '/live2_checks.php';
$db = new LiveMemoryPDO();
$settings = portal_settings_save($db, ['operation'=>'saveYear', 'year'=>2027, 'rate'=>'0,35', 'revision'=>1]);
liveCheck($settings['currentYear'] === 2026, 'Creating next year changed the default year');
liveCheck(isset($settings['years'][2026], $settings['years'][2027]), 'Both years must remain available');
$settings = portal_settings_save($db, ['operation'=>'setCurrent', 'year'=>2027, 'revision'=>2]);
liveCheck($settings['currentYear'] === 2027 && isset($settings['years'][2026]), 'Changing default removed the previous year');
liveReject(fn()=>portal_settings_save($db, ['year'=>2028, 'rate'=>'0.30', 'makeCurrent'=>'false', 'revision'=>3]), 'Invalid default-year choice accepted');
liveCheck(count(array_filter($db->collectionYears, fn($year)=>$year['is_huidig'] === 1)) === 1, 'More than one current year');
liveReject(fn()=>portal_settings_save($db, ['operation'=>'setCurrent', 'year'=>2028, 'revision'=>3]), 'Unknown year became current');
liveReject(fn()=>portal_settings_save($db, ['operation'=>'saveYear', 'year'=>2028, 'rate'=>'0.40', 'revision'=>2]), 'Stale revision saved a year');
liveCheck(!isset($db->collectionYears[2028]) && ! $db->inTransaction(), 'Rejected operation left partial data or a transaction');
$settings = portal_settings_save($db, ['operation'=>'saveYear', 'year'=>2026, 'rate'=>'0.00', 'revision'=>3]);
liveCheck($settings['currentYear'] === 2027 && $settings['years'][2026]['kilometervergoeding'] === '0.00', 'Updating historical rate changed current year');
$migration = new LiveMemoryPDO();
$migration->collectionYears = [];
$migration->settings['data'] = '{"currentYear":2026,"years":{"2023":{"kilometervergoeding":"0.25"},"2026":{"kilometervergoeding":"0.30"}}}';
$migration->trips[1] = ['id'=>1, 'collectejaar'=>2023];
portal_settings_schema($migration);
liveCheck($migration->collectionYears[2023]['kilometervergoeding'] === '0.25', 'Historical rate not migrated');
liveCheck($migration->collectionYears[2026]['is_huidig'] === 1 && $migration->trips[1]['collectejaar'] === 2023, 'Migration changed current year or historical trip');
portal_settings_schema($migration);
liveCheck(count($migration->collectionYears) === 2, 'Migration duplicated years');
$settings = portal_settings_save($migration, ['operation'=>'setCurrent', 'year'=>2023, 'revision'=>1]);
portal_settings_schema($migration);
liveCheck(portal_settings($migration)['currentYear'] === 2023 && $migration->collectionYears[2026]['is_huidig'] === 0, 'Repeated initialization reset current year');
class FailingYearPDO extends LiveMemoryPDO {
    public function run(string $sql, array $p): array {
        if (str_starts_with($sql, 'UPDATE afstort_settings')) throw new RuntimeException('Simulated storage failure');
        return parent::run($sql, $p);
    }
}
$failure = new FailingYearPDO();
try {
    portal_settings_save($failure, ['year'=>2027, 'rate'=>'0.35', 'revision'=>1]);
    throw new RuntimeException('Storage failure was ignored');
} catch (RuntimeException $e) {
    liveCheck($e->getMessage() === 'Simulated storage failure', 'Unexpected storage failure');
}
liveCheck(!isset($failure->collectionYears[2027]) && $failure->collectionYears[2026]['is_huidig'] === 1 && !$failure->inTransaction(), 'Partial year change survived rollback');
$db->drivers[0]['beschikbare_jaren'] = '[2027]';
liveCheck(!chauffeur_beschikbaar($db, 'Anna', 2026), 'Inactive driver available for current year');
liveCheck(chauffeur_beschikbaar($db, 'Anna', 2027), 'Driver availability for next year lost');
echo "Collection year checks passed.\n";

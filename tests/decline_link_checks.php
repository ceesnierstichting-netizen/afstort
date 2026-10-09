<?php
require_once __DIR__ . '/../app_helpers.php';
function declineCheck(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$url = afstort_decline_url(138, 'Cees Test', 'test-secret');
parse_str(parse_url($url, PHP_URL_QUERY), $query);
declineCheck(afstort_valid_decline_token(138, $query['chauffeur'], (int)$query['expires'], $query['token'], 'test-secret'), 'Generated link invalid');
declineCheck(!afstort_valid_decline_token(139, $query['chauffeur'], (int)$query['expires'], $query['token'], 'test-secret'), 'Changed trip accepted');
declineCheck(!afstort_valid_decline_token(138, 'Other driver', (int)$query['expires'], $query['token'], 'test-secret'), 'Changed driver accepted');
declineCheck(!afstort_valid_decline_token(138, 'Cees Test', (int)$query['expires'], $query['token'], 'wrong-secret'), 'Wrong secret accepted');
$expired = time() - 1;
declineCheck(!afstort_valid_decline_token(138, 'Cees Test', $expired, afstort_decline_token(138, 'Cees Test', $expired, 'test-secret'), 'test-secret'), 'Expired link accepted');
declineCheck(!afstort_valid_decline_token(138, 'Cees Test', (int)$query['expires'], '', 'test-secret'), 'Unsigned link accepted');
echo "Signed decline link checks passed.\n";

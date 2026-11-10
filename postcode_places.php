<?php

function chauffeurWoonplaats($postcode): string {
    $clean = strtoupper(preg_replace('/\s+/', '', trim((string)$postcode)));
    if (!preg_match('/^[1-9][0-9]{3}[A-Z]{2}$/D', $clean)) return '';
    $cached = $_SESSION['postcode_woonplaatsen'][$clean] ?? null;
    if (is_array($cached) && ($cached['expires'] ?? 0) > time()) return $cached['town'];

    $url = 'https://api.pdok.nl/bzk/locatieserver/search/v3_1/free?' . http_build_query([
        'q' => $clean, 'fq' => 'type:postcode', 'rows' => 1, 'fl' => 'postcode,woonplaatsnaam',
    ]);
    $context = stream_context_create(['http' => [
        'header' => "User-Agent: Nierstichting-Afstort/1.0\r\n", 'timeout' => 3,
    ]]);
    $json = @file_get_contents($url, false, $context);
    $data = $json === false ? null : json_decode($json, true);
    foreach ($data['response']['docs'] ?? [] as $doc) {
        $foundPostcode = strtoupper(preg_replace('/\s+/', '', (string)($doc['postcode'] ?? '')));
        $town = trim((string)($doc['woonplaatsnaam'] ?? ''));
        if ($foundPostcode === $clean && $town !== '') {
            $_SESSION['postcode_woonplaatsen'][$clean] = ['town' => $town, 'expires' => time() + 86400];
            return $town;
        }
    }
    return '';
}

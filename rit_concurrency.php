<?php

class RitConflict extends RuntimeException {}

function ritVersion(array $rit): string {
    // Only persisted business fields; computed access flags are not part of the version.
    $fields = ['id', 'collectegebied', 'wijknaam', 'gebiedsnummer', 'contactpersoon',
        'adres', 'postcodePlaats', 'telefoonnummer', 'email', 'opmerking',
        'voorkeurAfhaalmoment', 'verwachtBedrag', 'soort', 'chauffeur',
        'afhaalmoment', 'afhaaltijd', 'gestort', 'gereden', 'status'];
    $values = [];
    foreach ($fields as $field) $values[$field] = (string)($rit[$field] ?? '');
    return hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

function ritCheckVersions(PDO $pdo, array $ritten): void {
    if (!$pdo->inTransaction()) throw new LogicException('Ritcontrole vereist een transactie.');
    $dirty = array_filter($ritten, function ($rit) { return !empty($rit['id']) && !empty($rit['__dirty']); });
    // Stable lock order prevents two batch saves from locking rows in opposite order.
    usort($dirty, function ($a, $b) { return (int)$a['id'] <=> (int)$b['id']; });
    $query = $pdo->prepare('SELECT * FROM ritten WHERE id = ? FOR UPDATE');
    foreach ($dirty as $rit) {
        $query->execute([$rit['id']]);
        $current = $query->fetch(PDO::FETCH_ASSOC);
        $expected = (string)($rit['__version'] ?? '');
        if (!$current || $expected === '' || !hash_equals(ritVersion($current), $expected)) {
            throw new RitConflict('Rit ' . (int)$rit['id'] . ' is ondertussen gewijzigd of verwijderd, of dit scherm is verouderd. Er is niets opgeslagen. Je invoer blijft in beeld; kopieer je wijzigingen en vernieuw daarna de pagina om de actuele gegevens te bekijken.');
        }
    }
}

<?php
function beta_formatted_date(string $value): string {
    if ($value === '') return '';
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date) return $value;
    $months = ['januari','februari','maart','april','mei','juni','juli','augustus','september','oktober','november','december'];
    return $date->format('j') . ' ' . $months[(int)$date->format('n') - 1] . ' ' . $date->format('Y');
}

function beta_template_text(string $html): string {
    // Show text only. Never load remote images, tracking pixels or live action links.
    $html = preg_replace('~<(script|style|iframe)\b[^>]*>.*?</\1\s*>~is', '', $html);
    $html = preg_replace('~<(?:br|/p|/div|/tr|/h[1-6]|/li)\b[^>]*>~i', "\n", $html);
    return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function beta_build_mails(array $state, array $trip, string $kind, array $user): array {
    $actual = ($state['meta']['source'] ?? '') === 'snapshot';
    if (!$actual) {
        $body = 'Beste ' . $trip['contactpersoon'] . ",\n\n";
        if ($kind === 'Aanvraag') $body .= 'Je afhaalverzoek voor ' . $trip['collectegebied'] . ' is ontvangen. Een chauffeur neemt contact op.';
        elseif ($kind === 'Afspraak') $body .= $trip['chauffeur'] . ' komt de opbrengst ophalen op ' . $trip['afhaalmoment'] . ' om ' . $trip['afhaaltijd'] . ".\nAdres: " . $trip['adres'] . ', ' . $trip['postcodePlaats'];
        else $body .= 'De rit voor ' . $trip['collectegebied'] . ' is afgerond. Gestort bedrag aan munten: € ' . $trip['gestort'] . '.';
        if (($trip['contactOpmerking'] ?? '') !== '') $body .= "\n\nOpmerking: " . $trip['contactOpmerking'];
        if ($kind === 'Afronding' && $trip['opmerking'] !== '') $body .= "\n\n" . $trip['opmerking'];
        return [['to'=>$trip['email'], 'copy'=>$kind === 'Aanvraag' ? '' : ($trip['chauffeurEmail'] ?? $user['email']), 'cc'=>'', 'bcc'=>'', 'subject'=>$kind . ' — ' . $trip['collectegebied'], 'body'=>$body]];
    }
    $specs = $kind === 'Aanvraag' ? [[1, $trip['email'], 'Afhaalopdracht collecte-opbrengst']] : ($kind === 'Afspraak' ? [
        [4, $trip['email'], 'Bevestiging afhaalopdracht'],
        [3, $trip['chauffeurEmail'] ?? '', 'Bevestiging rit'],
    ] : [[trim($trip['wijknaam']) !== '' ? 6 : 5, $trip['email'], trim($trip['wijknaam']) !== '' ? 'Afstort wijk afgerond' : 'Afstort afgerond']]);
    $values = [
        'naam'=>$trip['contactpersoon'], 'contact'=>$trip['contactpersoon'], 'contactpersoon'=>$trip['contactpersoon'],
        'collectegebied'=>$trip['collectegebied'], 'gebiedsnummer'=>$trip['gebiedsnummer'], 'wijknaam'=>$trip['wijknaam'],
        'adres'=>$trip['adres'], 'postcodeplaats'=>$trip['postcodePlaats'], 'telefoonnummer'=>$trip['telefoonnummer'],
        'email'=>$trip['email'], 'verwacht'=>$trip['verwachtBedrag'], 'verwachtbedrag'=>$trip['verwachtBedrag'],
        'opmerking'=>$trip['contactOpmerking'] ?? '',
        'soort'=>$trip['soort'], 'gestort'=>$trip['gestort'], 'gereden'=>$trip['gereden'],
        'chauffeur'=>$trip['chauffeur'], 'chauffeurnaam'=>$trip['chauffeur'],
        'afhaalmoment'=>beta_formatted_date($kind === 'Aanvraag' ? $trip['voorkeurAfhaalmoment'] : $trip['afhaalmoment']),
        'afhaaltijd'=>$trip['afhaaltijd'],
        'busbriefje'=>'Busbriefje (link uitgeschakeld in bèta)', 'brusbriefje'=>'Busbriefje (link uitgeschakeld in bèta)',
        'afhaalbevestiging'=>'Afhaalbevestiging (link uitgeschakeld in bèta)',
    ];
    $values['formatteddatum'] = $values['afhaalmoment']; $values['formattedtijd'] = $values['afhaaltijd'];
    $mails = [];
    foreach ($specs as [$templateId, $to, $subject]) {
        $template = trim((string)($state['templates'][$templateId] ?? ''));
        if ($template === '') throw new DomainException('Mailsjabloon ' . $templateId . ' ontbreekt of is leeg in de bron. Laad na correctie een nieuwe testkopie.');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new DomainException('Het e-mailadres voor ' . $subject . ' ontbreekt of is ongeldig in deze testkopie.');
        $body = preg_replace_callback('/\[([a-z]+)\]/i', function ($match) use ($values) {
            $key = strtolower($match[1]);
            return array_key_exists($key, $values) ? htmlspecialchars($values[$key], ENT_QUOTES, 'UTF-8') : $match[0];
        }, $template);
        $body = str_ireplace(['https://tools.nierstichting.nl/sealbagstorting', 'https://nierstichting.nl/sealbagstorting'], 'https://nierstichting.nl/sealbag', $body);
        $body = beta_template_text($body);
        if ($kind === 'Afronding' && $trip['opmerking'] !== '') $body .= "\n\n" . $trip['opmerking'];
        $mails[] = ['to'=>$to, 'copy'=>'', 'cc'=>$kind === 'Afronding' ? 'collecte@nierstichting.nl' : '', 'bcc'=>$kind === 'Afronding' ? ($trip['chauffeurEmail'] ?? '') : '', 'subject'=>$subject, 'body'=>$body, 'templateId'=>$templateId];
    }
    return $mails;
}

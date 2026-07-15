<?php

function normalizeFullAccess($value) {
    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value) || is_float($value)) {
        return ((int)$value) !== 0;
    }

    if (is_string($value)) {
        if (strlen($value) === 1) {
            $byteValue = ord($value);
            if ($byteValue === 0 || $byteValue === 1) {
                return $byteValue === 1;
            }
        }

        $clean = strtolower(trim($value));
        if (is_numeric($clean)) {
            return ((int)$clean) !== 0;
        }

        return in_array($clean, ['true', 'yes', 'ja', 'on', 'x'], true);
    }

    return false;
}

function normalizePostcodeInput($value) {
    return strtoupper(str_replace(' ', '', trim((string)$value)));
}

function extractPostcode6($str) {
    if (!$str) {
        return '';
    }

    if (preg_match('/\b([0-9]{4})\s*([A-Za-z]{2})\b/', trim($str), $m)) {
        return strtoupper($m[1] . $m[2]);
    }

    return '';
}

function shouldReuseStoredCoordinates($newPostcodeValue, $existingPostcodeValue) {
    $newPc6 = extractPostcode6($newPostcodeValue);
    $existingPc6 = extractPostcode6($existingPostcodeValue);

    if ($newPc6 !== '' && $existingPc6 !== '') {
        return $newPc6 === $existingPc6;
    }

    $newNormalized = normalizePostcodeInput($newPostcodeValue);
    $existingNormalized = normalizePostcodeInput($existingPostcodeValue);

    return $newNormalized !== '' && $newNormalized === $existingNormalized;
}

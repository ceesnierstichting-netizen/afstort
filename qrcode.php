<?php

function qr_svg($text, $border = 4) {
    $version = 10;
    $size = 21 + 4 * ($version - 1);
    $dataCodewords = qr_make_data_codewords($text, 216);
    $codewords = qr_add_ecc_and_interleave($dataCodewords);

    $modules = array_fill(0, $size, array_fill(0, $size, false));
    $isFunction = array_fill(0, $size, array_fill(0, $size, false));

    qr_draw_function_patterns($modules, $isFunction, $version);
    qr_draw_codewords($modules, $isFunction, $codewords);
    qr_apply_mask($modules, $isFunction, 0);
    qr_draw_format_bits($modules, $isFunction, 0);

    return qr_modules_to_svg($modules, $border);
}

function qr_make_data_codewords($text, $capacity) {
    $bits = [];
    $byteLength = strlen($text);

    qr_append_bits($bits, 0x4, 4);
    qr_append_bits($bits, $byteLength, 16);

    for ($i = 0; $i < $byteLength; $i++) {
        qr_append_bits($bits, ord($text[$i]), 8);
    }

    $capacityBits = $capacity * 8;
    if (count($bits) > $capacityBits) {
        throw new Exception('De QR-code data is te lang.');
    }

    qr_append_bits($bits, 0, min(4, $capacityBits - count($bits)));

    while ((count($bits) % 8) !== 0) {
        $bits[] = 0;
    }

    $padBytes = [0xec, 0x11];
    for ($i = 0; (count($bits) / 8) < $capacity; $i++) {
        qr_append_bits($bits, $padBytes[$i % 2], 8);
    }

    $codewords = [];
    for ($i = 0; $i < count($bits); $i += 8) {
        $value = 0;
        for ($j = 0; $j < 8; $j++) {
            $value = ($value << 1) | $bits[$i + $j];
        }
        $codewords[] = $value;
    }

    return $codewords;
}

function qr_append_bits(&$bits, $value, $length) {
    for ($i = $length - 1; $i >= 0; $i--) {
        $bits[] = ($value >> $i) & 1;
    }
}

function qr_add_ecc_and_interleave(array $data) {
    $rawCodewords = 346;
    $numBlocks = 5;
    $eccPerBlock = 26;
    $numShortBlocks = $numBlocks - ($rawCodewords % $numBlocks);
    $shortDataLen = (int)floor($rawCodewords / $numBlocks) - $eccPerBlock;
    $divisor = qr_reed_solomon_compute_divisor($eccPerBlock);
    $blocks = [];
    $offset = 0;

    for ($i = 0; $i < $numBlocks; $i++) {
        $dataLen = $shortDataLen + ($i < $numShortBlocks ? 0 : 1);
        $dat = array_slice($data, $offset, $dataLen);
        $offset += $dataLen;
        $blocks[] = [
            'data' => $dat,
            'ecc' => qr_reed_solomon_compute_remainder($dat, $divisor)
        ];
    }

    $result = [];
    for ($i = 0; $i <= $shortDataLen; $i++) {
        foreach ($blocks as $block) {
            if ($i < count($block['data'])) {
                $result[] = $block['data'][$i];
            }
        }
    }

    for ($i = 0; $i < $eccPerBlock; $i++) {
        foreach ($blocks as $block) {
            $result[] = $block['ecc'][$i];
        }
    }

    return $result;
}

function qr_reed_solomon_compute_divisor($degree) {
    $result = array_fill(0, $degree, 0);
    $result[$degree - 1] = 1;
    $root = 1;

    for ($i = 0; $i < $degree; $i++) {
        for ($j = 0; $j < $degree; $j++) {
            $result[$j] = qr_reed_solomon_multiply($result[$j], $root);
            if ($j + 1 < $degree) {
                $result[$j] ^= $result[$j + 1];
            }
        }
        $root = qr_reed_solomon_multiply($root, 0x02);
    }

    return $result;
}

function qr_reed_solomon_compute_remainder(array $data, array $divisor) {
    $result = array_fill(0, count($divisor), 0);

    foreach ($data as $byte) {
        $factor = $byte ^ array_shift($result);
        $result[] = 0;

        foreach ($divisor as $i => $coef) {
            $result[$i] ^= qr_reed_solomon_multiply($coef, $factor);
        }
    }

    return $result;
}

function qr_reed_solomon_multiply($x, $y) {
    $z = 0;

    for ($i = 7; $i >= 0; $i--) {
        $z = (($z << 1) ^ (($z >> 7) * 0x11d)) & 0xff;
        if ((($y >> $i) & 1) !== 0) {
            $z ^= $x;
        }
    }

    return $z;
}

function qr_draw_function_patterns(&$modules, &$isFunction, $version) {
    $size = count($modules);
    qr_draw_finder_pattern($modules, $isFunction, 3, 3);
    qr_draw_finder_pattern($modules, $isFunction, $size - 4, 3);
    qr_draw_finder_pattern($modules, $isFunction, 3, $size - 4);

    for ($i = 0; $i < $size; $i++) {
        if (!$isFunction[6][$i]) {
            qr_set_function_module($modules, $isFunction, $i, 6, ($i % 2) === 0);
        }
        if (!$isFunction[$i][6]) {
            qr_set_function_module($modules, $isFunction, 6, $i, ($i % 2) === 0);
        }
    }

    $alignmentPositions = [6, 28, 50];
    foreach ($alignmentPositions as $y) {
        foreach ($alignmentPositions as $x) {
            $nearTopLeft = ($x === 6 && $y === 6);
            $nearTopRight = ($x === $size - 7 && $y === 6);
            $nearBottomLeft = ($x === 6 && $y === $size - 7);
            if (!$nearTopLeft && !$nearTopRight && !$nearBottomLeft) {
                qr_draw_alignment_pattern($modules, $isFunction, $x, $y);
            }
        }
    }

    qr_draw_format_bits($modules, $isFunction, 0);
    qr_draw_version_bits($modules, $isFunction, $version);
}

function qr_draw_finder_pattern(&$modules, &$isFunction, $centerX, $centerY) {
    $size = count($modules);

    for ($dy = -4; $dy <= 4; $dy++) {
        for ($dx = -4; $dx <= 4; $dx++) {
            $x = $centerX + $dx;
            $y = $centerY + $dy;
            if ($x < 0 || $x >= $size || $y < 0 || $y >= $size) {
                continue;
            }

            $dist = max(abs($dx), abs($dy));
            qr_set_function_module($modules, $isFunction, $x, $y, $dist !== 2 && $dist !== 4);
        }
    }
}

function qr_draw_alignment_pattern(&$modules, &$isFunction, $centerX, $centerY) {
    for ($dy = -2; $dy <= 2; $dy++) {
        for ($dx = -2; $dx <= 2; $dx++) {
            $dist = max(abs($dx), abs($dy));
            qr_set_function_module($modules, $isFunction, $centerX + $dx, $centerY + $dy, $dist !== 1);
        }
    }
}

function qr_draw_format_bits(&$modules, &$isFunction, $mask) {
    $size = count($modules);
    $bits = qr_get_format_bits($mask);

    for ($i = 0; $i <= 5; $i++) {
        qr_set_function_module($modules, $isFunction, 8, $i, qr_get_bit($bits, $i));
    }
    qr_set_function_module($modules, $isFunction, 8, 7, qr_get_bit($bits, 6));
    qr_set_function_module($modules, $isFunction, 8, 8, qr_get_bit($bits, 7));
    qr_set_function_module($modules, $isFunction, 7, 8, qr_get_bit($bits, 8));
    for ($i = 9; $i < 15; $i++) {
        qr_set_function_module($modules, $isFunction, 14 - $i, 8, qr_get_bit($bits, $i));
    }

    for ($i = 0; $i < 8; $i++) {
        qr_set_function_module($modules, $isFunction, $size - 1 - $i, 8, qr_get_bit($bits, $i));
    }
    for ($i = 8; $i < 15; $i++) {
        qr_set_function_module($modules, $isFunction, 8, $size - 15 + $i, qr_get_bit($bits, $i));
    }

    qr_set_function_module($modules, $isFunction, 8, $size - 8, true);
}

function qr_get_format_bits($mask) {
    $data = $mask;
    $rem = $data;
    for ($i = 0; $i < 10; $i++) {
        $rem = ($rem << 1) ^ (((($rem >> 9) & 1) !== 0) ? 0x537 : 0);
    }
    return (($data << 10) | $rem) ^ 0x5412;
}

function qr_draw_version_bits(&$modules, &$isFunction, $version) {
    $size = count($modules);
    $bits = qr_get_version_bits($version);

    for ($i = 0; $i < 18; $i++) {
        $bit = qr_get_bit($bits, $i);
        $a = $size - 11 + ($i % 3);
        $b = (int)floor($i / 3);
        qr_set_function_module($modules, $isFunction, $a, $b, $bit);
        qr_set_function_module($modules, $isFunction, $b, $a, $bit);
    }
}

function qr_get_version_bits($version) {
    $rem = $version;
    for ($i = 0; $i < 12; $i++) {
        $rem = ($rem << 1) ^ (((($rem >> 11) & 1) !== 0) ? 0x1f25 : 0);
    }
    return ($version << 12) | $rem;
}

function qr_draw_codewords(&$modules, &$isFunction, array $codewords) {
    $size = count($modules);
    $bits = [];

    foreach ($codewords as $codeword) {
        for ($i = 7; $i >= 0; $i--) {
            $bits[] = ($codeword >> $i) & 1;
        }
    }

    $bitIndex = 0;
    $bitCount = count($bits);

    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right === 6) {
            $right--;
        }

        for ($vert = 0; $vert < $size; $vert++) {
            for ($j = 0; $j < 2; $j++) {
                $x = $right - $j;
                $upward = ((($right + 1) & 2) === 0);
                $y = $upward ? $size - 1 - $vert : $vert;

                if (!$isFunction[$y][$x]) {
                    $modules[$y][$x] = $bitIndex < $bitCount && $bits[$bitIndex] === 1;
                    $bitIndex++;
                }
            }
        }
    }
}

function qr_apply_mask(&$modules, &$isFunction, $mask) {
    $size = count($modules);

    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            if (!$isFunction[$y][$x] && qr_mask_condition($mask, $x, $y)) {
                $modules[$y][$x] = !$modules[$y][$x];
            }
        }
    }
}

function qr_mask_condition($mask, $x, $y) {
    return (($x + $y) % 2) === 0;
}

function qr_set_function_module(&$modules, &$isFunction, $x, $y, $dark) {
    $modules[$y][$x] = (bool)$dark;
    $isFunction[$y][$x] = true;
}

function qr_get_bit($value, $index) {
    return (($value >> $index) & 1) !== 0;
}

function qr_modules_to_svg(array $modules, $border) {
    $size = count($modules);
    $dimension = $size + ($border * 2);
    $path = '';

    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            if (!empty($modules[$y][$x])) {
                $path .= 'M' . ($x + $border) . ' ' . ($y + $border) . 'h1v1h-1z';
            }
        }
    }

    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $dimension . ' ' . $dimension . '" width="220" height="220" role="img" aria-label="2FA QR-code" shape-rendering="crispEdges">'
        . '<rect width="100%" height="100%" fill="#fff"/>'
        . '<path d="' . $path . '" fill="#111827"/>'
        . '</svg>';
}

?>

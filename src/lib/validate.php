<?php

declare(strict_types=1);

/** Small input-validation helpers shared by API endpoints. Never trust the client. */

function v_int($value, ?int $min = null, ?int $max = null): ?int
{
    if (!is_numeric($value)) {
        return null;
    }
    $i = (int) $value;
    if ($min !== null && $i < $min) {
        return null;
    }
    if ($max !== null && $i > $max) {
        return null;
    }
    return $i;
}

function v_string($value, int $maxLen = 500): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $s = trim($value);
    if ($s === '' || mb_strlen($s) > $maxLen) {
        return null;
    }
    return $s;
}

function v_team($value): ?string
{
    return in_array($value, ['blue', 'red'], true) ? $value : null;
}

function v_mode($value): ?string
{
    return in_array($value, ['classic_300', 'free_rounds'], true) ? $value : null;
}

function v_enum($value, array $allowed): ?string
{
    return in_array($value, $allowed, true) ? $value : null;
}

<?php
function is_digits_or_empty(string $value): bool
{
    return $value === '' || preg_match('/^\d+$/', $value) === 1;
}

function is_non_negative_integer_or_empty(string $value): bool
{
    return $value === '' || preg_match('/^\d+$/', $value) === 1;
}

function is_non_negative_decimal_or_empty(string $value): bool
{
    return $value === '' || preg_match('/^\d+([\.,]\d+)?$/', $value) === 1;
}

function decimal_for_sql(string $value): float
{
    return (float) str_replace(',', '.', $value);
}


function is_seconds_part_or_empty(string $value): bool
{
    if ($value === '' || preg_match('/^\d+$/', $value) !== 1) {
        return $value === '';
    }

    $seconds = (int) $value;
    return $seconds >= 0 && $seconds <= 59;
}

function format_duration_seconds($value): string
{
    $total_seconds = max(0, (int) $value);
    $hours = intdiv($total_seconds, 3600);
    $minutes = intdiv($total_seconds % 3600, 60);
    $seconds = $total_seconds % 60;

    $parts = [];
    if ($hours > 0) {
        $parts[] = $hours . ' h';
    }
    if ($minutes > 0) {
        $parts[] = $minutes . ' min';
    }
    if ($seconds > 0 || empty($parts)) {
        $parts[] = $seconds . ' sec';
    }

    return implode(' ', $parts);
}


function format_minutes_remaining($value): string
{
    if ($value === null || $value === '') {
        return '-';
    }

    $total_minutes = max(0, (int) $value);
    $hours = intdiv($total_minutes, 60);
    $minutes = $total_minutes % 60;

    if ($hours > 0 && $minutes > 0) {
        return $hours . ' h ' . $minutes . ' min';
    }
    if ($hours > 0) {
        return $hours . ' h';
    }
    return $minutes . ' min';
}

function format_euro($value): string
{
    if ($value === null || $value === '') {
        return '-';
    }

    return '€ ' . number_format((float) $value, 2, ',', '.');
}

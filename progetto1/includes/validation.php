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

function is_time_minutes_or_empty(string $value): bool
{
    return $value === '' || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
}

function time_minutes_for_sql(string $value, bool $end_of_minute = false): string
{
    if ($value === '') {
        return '';
    }

    return $value . ($end_of_minute ? ':59' : ':00');
}



function is_date_or_empty(string $value): bool
{
    if ($value === '') {
        return true;
    }

    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function is_duration_part_or_empty(string $value): bool
{
    if ($value === '' || preg_match('/^\d+$/', $value) !== 1) {
        return $value === '';
    }

    $part = (int) $value;
    return $part >= 0 && $part <= 59;
}

function duration_parts_to_seconds(string $hours, string $minutes, string $seconds): int
{
    return max(0, (int) $hours) * 3600
        + max(0, (int) $minutes) * 60
        + max(0, (int) $seconds);
}

function format_duration_filter_value(int $total_seconds): string
{
    return format_duration_seconds(max(0, $total_seconds));
}

/**
 * Costruisce un messaggio di risultato vuoto che indica i criteri realmente
 * attivi. Non attribuisce arbitrariamente la causa a un solo filtro: comunica
 * in modo corretto che è la loro combinazione a non produrre corrispondenze.
 */
function build_filter_aware_no_results_message(string $subject, array $criteria, string $fallback): string
{
    $active = [];
    foreach ($criteria as $criterion) {
        $criterion = trim((string) $criterion);
        if ($criterion !== '') {
            $active[] = '“' . $criterion . '”';
        }
    }

    if (empty($active)) {
        return $fallback;
    }

    if (count($active) === 1) {
        return $subject . ' soddisfa il criterio ' . $active[0]
            . '. Modificare questo filtro oppure azzerarlo per ampliare la ricerca.';
    }

    $last = array_pop($active);
    $criteria_text = implode(', ', $active) . ' e ' . $last;

    return $subject . ' soddisfa contemporaneamente i criteri ' . $criteria_text
        . '. La combinazione è troppo restrittiva: ridurre la soglia più selettiva oppure azzerare uno dei filtri indicati.';
}

function format_date_it($value): string
{
    if ($value === null || $value === '') {
        return '-';
    }

    $parsed = DateTime::createFromFormat('Y-m-d', (string) $value);
    if ($parsed === false) {
        return (string) $value;
    }

    return $parsed->format('d/m/Y');
}

function format_time_minutes($value): string
{
    if ($value === null || $value === '') {
        return '-';
    }

    return substr((string) $value, 0, 5);
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

/**
 * Formatta una durata complessiva in modo compatto per le viste riepilogative.
 * I secondi non vengono mostrati perché, sui totali di molte chiamate, non
 * aggiungono valore operativo e rendono le card difficili da leggere.
 */
function format_total_duration_compact($value): string
{
    $total_seconds = max(0, (int) $value);
    $hours = intdiv($total_seconds, 3600);
    $minutes = intdiv($total_seconds % 3600, 60);

    if ($hours > 0) {
        return $hours . ' h' . ($minutes > 0 ? ' ' . $minutes . ' min' : '');
    }

    if ($minutes > 0) {
        return $minutes . ' min';
    }

    return $total_seconds > 0 ? 'Meno di 1 min' : '0 min';
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

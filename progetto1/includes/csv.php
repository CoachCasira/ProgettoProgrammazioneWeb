<?php
/**
 * Restituisce un identificativo come testo Excel, evitando notazione
 * scientifica e perdita di precisione per numeri telefonici e codici SIM.
 */
function csv_excel_identifier($value): string
{
    if ($value === null || $value === '' || $value === '-') {
        return $value === '-' ? '-' : '';
    }

    $identifier = (string) $value;
    return '="' . str_replace('"', '""', $identifier) . '"';
}

/**
 * Formatta un importo come numero decimale italiano, senza simbolo di valuta.
 * Il simbolo e l'unita sono indicati nell'intestazione della colonna, così
 * Excel mantiene il dato numerico ordinabile e utilizzabile nei calcoli.
 */
function csv_decimal_value($value, int $decimals = 2): string
{
    if ($value === null || $value === '') {
        return '';
    }

    return number_format((float) $value, $decimals, ',', '');
}

/**
 * Genera una singola riga CSV in UTF-8 con una firma di fputcsv compatibile
 * anche con le versioni PHP meno recenti disponibili sugli hosting condivisi.
 */
function csv_row_to_utf8(array $row): string
{
    $temporary = fopen('php://temp', 'r+');
    if ($temporary === false) {
        throw new RuntimeException('Impossibile generare il file CSV.');
    }

    if (fputcsv($temporary, $row, ';', '"', '\\') === false) {
        fclose($temporary);
        throw new RuntimeException('Impossibile generare il file CSV.');
    }

    rewind($temporary);
    $line = stream_get_contents($temporary);
    fclose($temporary);

    if ($line === false) {
        throw new RuntimeException('Impossibile generare il file CSV.');
    }

    return rtrim($line, "\r\n") . "\r\n";
}

/**
 * Converte una stringa UTF-8 nella codifica Windows-1252 usata da Excel per
 * i CSV aperti direttamente. In questa codifica il simbolo euro e' il byte 80.
 */
function csv_encode_for_excel(string $value): string
{
    if (function_exists('mb_convert_encoding')) {
        return mb_convert_encoding($value, 'Windows-1252', 'UTF-8');
    }

    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT', $value);
        if ($converted !== false) {
            return $converted;
        }
    }

    // Fallback per hosting senza mbstring/iconv: conserva il simbolo euro e
    // converte i caratteri accentati rappresentabili in ISO-8859-1.
    $placeholder = '__CSV_EURO__';
    $value = str_replace("\xE2\x82\xAC", $placeholder, $value);
    if (function_exists('utf8_decode')) {
        $value = utf8_decode($value);
    }

    return str_replace($placeholder, "\x80", $value);
}

/**
 * Produce un CSV compatibile con Excel in locale italiano.
 */
function output_csv_response(string $filename, array $headers, array $rows): void
{
    // Evita che spazi, warning o output precedenti corrompano il download.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=Windows-1252');
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');

    $out = fopen('php://output', 'wb');
    if ($out === false) {
        http_response_code(500);
        exit;
    }

    try {
        // Nessun BOM: Excel interpreta il file come ANSI/Windows-1252 e
        // visualizza correttamente il simbolo euro presente nelle intestazioni.
        fwrite($out, "sep=;\r\n");
        fwrite($out, csv_encode_for_excel(csv_row_to_utf8($headers)));

        foreach ($rows as $row) {
            fwrite($out, csv_encode_for_excel(csv_row_to_utf8($row)));
        }
    } catch (Throwable $exception) {
        fclose($out);
        exit;
    }

    fclose($out);
    exit;
}

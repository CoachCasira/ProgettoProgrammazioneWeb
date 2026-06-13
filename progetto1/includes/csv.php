<?php
/**
 * Restituisce un identificativo come testo Excel, evitando notazione
 * scientifica e perdita di precisione per numeri telefonici e codici SIM.
 * Gli identificativi gestiti dall'applicazione sono composti esclusivamente
 * da cifre, quindi la formula generata non contiene input eseguibile.
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
 * Genera una riga CSV in UTF-8 usando la firma di fputcsv compatibile anche
 * con le versioni PHP meno recenti presenti sugli hosting condivisi.
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

    return $line;
}

/**
 * Converte una stringa UTF-8 in UTF-16LE. Questa codifica viene riconosciuta
 * direttamente da Excel e mantiene corretti accenti e simbolo dell'euro.
 */
function csv_encode_for_excel(string $value): string
{
    if (function_exists('mb_convert_encoding')) {
        return mb_convert_encoding($value, 'UTF-16LE', 'UTF-8');
    }

    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'UTF-16LE//TRANSLIT', $value);
        if ($converted !== false) {
            return $converted;
        }
    }

    // Fallback manuale sufficiente per l'ASCII; normalmente Altervista rende
    // disponibile almeno una tra mbstring e iconv.
    return $value;
}

/**
 * Produce un CSV ottimizzato per Excel in locale italiano.
 */
function output_csv_response(string $filename, array $headers, array $rows): void
{
    // Evita che eventuali output precedenti corrompano header e contenuto CSV.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=UTF-16LE');
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
        // BOM UTF-16LE: Excel riconosce correttamente anche il simbolo euro.
        fwrite($out, "\xFF\xFE");
        fwrite($out, csv_encode_for_excel("sep=;\r\n"));
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

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
 * Il simbolo e l'unità sono indicati nell'intestazione della colonna, così
 * Excel mantiene il dato numerico ordinabile e allineato a destra.
 */
function csv_decimal_value($value, int $decimals = 2): string
{
    if ($value === null || $value === '') {
        return '';
    }

    return number_format((float) $value, $decimals, ',', '');
}

/**
 * Scrive una riga CSV usando esclusivamente i parametri di fputcsv disponibili
 * anche nelle versioni PHP meno recenti presenti su alcuni hosting condivisi.
 */
function csv_write_row($stream, array $row): void
{
    if (fputcsv($stream, $row, ';', '"', '\\') === false) {
        throw new RuntimeException('Impossibile generare il file CSV.');
    }
}

/**
 * Produce un CSV UTF-8 ottimizzato per Excel in locale italiano.
 */
function output_csv_response(string $filename, array $headers, array $rows): void
{
    // Evita che eventuali output precedenti corrompano header e contenuto CSV.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=UTF-8');
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
        // BOM UTF-8 per caratteri accentati e simboli corretti in Excel.
        fwrite($out, "\xEF\xBB\xBF");
        // Forza Excel a usare il punto e virgola come separatore di colonna.
        fwrite($out, "sep=;\r\n");

        csv_write_row($out, $headers);
        foreach ($rows as $row) {
            csv_write_row($out, $row);
        }
    } catch (Throwable $exception) {
        fclose($out);
        exit;
    }

    fclose($out);
    exit;
}

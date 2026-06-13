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
 * Produce un CSV UTF-8 ottimizzato per Excel in locale italiano.
 */
function output_csv_response(string $filename, array $headers, array $rows): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    if ($out === false) {
        http_response_code(500);
        exit;
    }

    // BOM UTF-8 per caratteri accentati e simboli corretti in Excel.
    fwrite($out, "\xEF\xBB\xBF");
    // Forza Excel a usare il punto e virgola come separatore di colonna.
    fwrite($out, "sep=;\r\n");

    fputcsv($out, $headers, ';', '"', '\\', "\r\n");
    foreach ($rows as $row) {
        fputcsv($out, $row, ';', '"', '\\', "\r\n");
    }

    fclose($out);
    exit;
}

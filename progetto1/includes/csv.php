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
 * Formatta un importo monetario con il simbolo euro visibile nella cella.
 * Nei CSV Excel il valore viene volutamente esportato come testo leggibile,
 * così il simbolo compare accanto a ogni importo senza dipendere dal formato
 * valuta configurato nel programma con cui viene aperto il file.
 */
function csv_currency_value($value, int $decimals = 2): string
{
    if ($value === null || $value === '') {
        return '';
    }

    return '€ ' . csv_decimal_value($value, $decimals);
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


/**
 * Esporta come numero soltanto gli identificativi che Excel puo' rappresentare
 * senza perdita di precisione. E' adatto ai numeri telefonici del progetto,
 * mentre i codici SIM molto lunghi devono restare testo.
 */
function csv_excel_safe_integer($value): string
{
    if ($value === null || $value === '' || $value === '-') {
        return $value === '-' ? '-' : '';
    }

    $digits = (string)$value;
    if (preg_match('/^[0-9]{1,15}$/', $digits) && $digits[0] !== '0') {
        return $digits;
    }

    return csv_excel_identifier($digits);
}

/**
 * Restituisce un intero puro, utile quando l'unita' di misura e' dichiarata
 * nell'intestazione della colonna. Excel lo interpreta come numero e lo
 * allinea automaticamente a destra.
 */
function csv_integer_value($value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    return (string)(int)$value;
}

/**
 * Versione streaming dell'esportazione CSV. Evita di costruire in memoria
 * l'intero file e permette di gestire in sicurezza esportazioni filtrate
 * di dimensioni rilevanti.
 */
function output_csv_stream_response(string $filename, array $headers, iterable $rows): void
{
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

    fwrite($out, "sep=;\r\n");
    fwrite($out, csv_encode_for_excel(csv_row_to_utf8($headers)));

    $written = 0;
    foreach ($rows as $row) {
        fwrite($out, csv_encode_for_excel(csv_row_to_utf8($row)));
        $written++;

        if (($written % 1000) === 0) {
            fflush($out);
        }
    }

    fclose($out);
    exit;
}

/**
 * Escaping XML per il formato SpreadsheetML 2003, apribile direttamente
 * con Microsoft Excel e capace di conservare allineamenti e identificativi
 * molto lunghi senza perdita di precisione.
 */
function excel_xml_escape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * Produce un foglio Excel XML con allineamento esplicito delle colonne.
 * Viene usato come alternativa al CSV quando e' necessario conservare sia
 * l'esattezza dei codici SIM sia l'allineamento a destra in Excel.
 */
function output_excel_xml_response(
    string $filename,
    string $sheet_name,
    array $headers,
    array $rows,
    array $alignments
): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $safe_filename = basename($filename);
    $safe_sheet_name = function_exists('mb_substr')
        ? mb_substr($sheet_name, 0, 31, 'UTF-8')
        : substr($sheet_name, 0, 31);

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $safe_filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
        . ' xmlns:o="urn:schemas-microsoft-com:office:office"'
        . ' xmlns:x="urn:schemas-microsoft-com:office:excel"'
        . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";
    echo '<Styles>';
    foreach (['Left', 'Center', 'Right'] as $alignment) {
        echo '<Style ss:ID="Header' . $alignment . '"><Alignment ss:Horizontal="' . $alignment
            . '"/><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#8B7A6E" ss:Pattern="Solid"/></Style>';
        echo '<Style ss:ID="Cell' . $alignment . '"><Alignment ss:Horizontal="' . $alignment . '"/></Style>';
    }
    echo '</Styles>' . "\n";
    echo '<Worksheet ss:Name="' . excel_xml_escape($safe_sheet_name) . '"><Table>' . "\n";

    echo '<Row>';
    foreach ($headers as $index => $header) {
        $alignment = ucfirst(strtolower((string)($alignments[$index] ?? 'left')));
        if (!in_array($alignment, ['Left', 'Center', 'Right'], true)) {
            $alignment = 'Left';
        }
        echo '<Cell ss:StyleID="Header' . $alignment . '"><Data ss:Type="String">'
            . excel_xml_escape($header) . '</Data></Cell>';
    }
    echo '</Row>' . "\n";

    foreach ($rows as $row) {
        echo '<Row>';
        foreach ($headers as $index => $_header) {
            $alignment = ucfirst(strtolower((string)($alignments[$index] ?? 'left')));
            if (!in_array($alignment, ['Left', 'Center', 'Right'], true)) {
                $alignment = 'Left';
            }
            $value = $row[$index] ?? '';
            echo '<Cell ss:StyleID="Cell' . $alignment . '"><Data ss:Type="String">'
                . excel_xml_escape($value) . '</Data></Cell>';
        }
        echo '</Row>' . "\n";
    }

    echo '</Table></Worksheet></Workbook>';
    exit;
}


/**
 * Escaping HTML minimale per i fogli Excel basati su tabella HTML.
 * Il formato e' volutamente semplice e compatibile con gli hosting condivisi:
 * non richiede estensioni PHP aggiuntive e consente di controllare allineamento
 * e trattamento degli identificativi lunghi.
 */
function excel_html_escape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Scrive l'intestazione di un file Excel compatibile con Microsoft Excel.
 * Il foglio usa una tabella HTML con metadati mso-number-format: in questo modo
 * codici SIM e numeri telefonici possono restare testo esatto pur essendo
 * allineati a destra, mentre quantita' e importi restano numerici.
 */
function excel_html_begin(string $filename, string $title): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');

    echo "\xEF\xBB\xBF";
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">';
    echo '<title>' . excel_html_escape($title) . '</title>';
    echo '<style>'
        . 'table{border-collapse:collapse;font-family:Arial,sans-serif;font-size:11pt}'
        . 'th,td{border:1px solid #cfc7c0;padding:6px 8px;white-space:nowrap}'
        . 'th{background:#8b7a6e;color:#fff;font-weight:bold}'
        . '.left{text-align:left}.center{text-align:center}.right{text-align:right}'
        . '.text{mso-number-format:"\\@"}.integer{mso-number-format:"0"}'
        . '.decimal{mso-number-format:"0.00"}.date{mso-number-format:"dd/mm/yyyy"}'
        . '</style></head><body><table><thead><tr>';
}

function excel_html_cell_class(string $alignment, string $type): string
{
    $alignment = strtolower($alignment);
    if (!in_array($alignment, ['left', 'center', 'right'], true)) {
        $alignment = 'left';
    }

    $type = strtolower($type);
    if (!in_array($type, ['text', 'integer', 'decimal', 'date'], true)) {
        $type = 'text';
    }

    return $alignment . ' ' . $type;
}

/**
 * Produce un file Excel completo da un insieme contenuto di righe.
 */
function output_excel_html_response(
    string $filename,
    string $title,
    array $headers,
    array $rows,
    array $alignments,
    array $types = []
): void {
    excel_html_begin($filename, $title);

    foreach ($headers as $index => $header) {
        $class = excel_html_cell_class($alignments[$index] ?? 'left', 'text');
        echo '<th class="' . $class . '">' . excel_html_escape($header) . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($headers as $index => $_header) {
            $value = $row[$index] ?? '';
            $type = $types[$index] ?? 'text';
            $class = excel_html_cell_class($alignments[$index] ?? 'left', $type);
            echo '<td class="' . $class . '">' . excel_html_escape($value) . '</td>';
        }
        echo '</tr>';
    }

    echo '</tbody></table></body></html>';
    exit;
}

/**
 * Versione streaming del foglio Excel: viene usata per le chiamate per non
 * costruire in memoria migliaia di righe prima del download.
 */
function output_excel_html_stream_response(
    string $filename,
    string $title,
    array $headers,
    iterable $rows,
    array $alignments,
    array $types = []
): void {
    excel_html_begin($filename, $title);

    foreach ($headers as $index => $header) {
        $class = excel_html_cell_class($alignments[$index] ?? 'left', 'text');
        echo '<th class="' . $class . '">' . excel_html_escape($header) . '</th>';
    }
    echo '</tr></thead><tbody>';

    $written = 0;
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($headers as $index => $_header) {
            $value = $row[$index] ?? '';
            $type = $types[$index] ?? 'text';
            $class = excel_html_cell_class($alignments[$index] ?? 'left', $type);
            echo '<td class="' . $class . '">' . excel_html_escape($value) . '</td>';
        }
        echo '</tr>';
        $written++;
        if (($written % 500) === 0) {
            flush();
        }
    }

    echo '</tbody></table></body></html>';
    exit;
}

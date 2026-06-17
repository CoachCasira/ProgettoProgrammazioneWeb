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
 * Escaping XML per SpreadsheetML 2003, formato XML nativo leggibile da
 * Microsoft Excel. Gli identificativi lunghi restano stringhe esatte e non
 * vengono trasformati in notazione scientifica.
 */
function excel_xml_escape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * Normalizza l'allineamento richiesto per gli stili del foglio.
 */
function excel_xml_alignment(string $alignment): string
{
    $alignment = ucfirst(strtolower($alignment));
    return in_array($alignment, ['Left', 'Center', 'Right'], true) ? $alignment : 'Left';
}

/**
 * Normalizza il tipo logico di una colonna Excel.
 */
function excel_xml_type(string $type): string
{
    $type = strtolower($type);
    return in_array($type, ['text', 'integer', 'decimal', 'currency', 'date'], true)
        ? $type
        : 'text';
}

/**
 * Converte un valore nel formato richiesto dal nodo SpreadsheetML.
 */
function excel_xml_cell_payload($value, string $type): array
{
    $type = excel_xml_type($type);

    if ($value === null || $value === '' || $value === '-') {
        return ['String', $value === '-' ? '-' : ''];
    }

    if ($type === 'integer') {
        return ['Number', (string)(int)$value];
    }

    if ($type === 'decimal' || $type === 'currency') {
        $normalized = str_replace(',', '.', (string)$value);
        return is_numeric($normalized)
            ? ['Number', number_format((float)$normalized, 2, '.', '')]
            : ['String', (string)$value];
    }

    /* Date e identificativi vengono mantenuti come testo: in questo modo
       numeri telefonici e codici SIM non perdono cifre e le date italiane
       non dipendono dalle impostazioni locali di Excel. */
    return ['String', (string)$value];
}

/**
 * Scrive intestazione, stili e apertura tabella di un foglio SpreadsheetML.
 */
function excel_xml_begin(
    string $filename,
    string $sheet_name,
    array $headers,
    array $alignments,
    array $types = [],
    string $notice = ''
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
    echo '<Style ss:ID="Default" ss:Name="Normal"><Font ss:FontName="Arial" ss:Size="11"/>'
        . '<Alignment ss:Vertical="Center"/></Style>';
    echo '<Style ss:ID="Notice"><Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:WrapText="1"/>'
        . '<Font ss:Bold="1" ss:Color="#6E5D52"/><Interior ss:Color="#EEE8E3" ss:Pattern="Solid"/></Style>';

    foreach (['Left', 'Center', 'Right'] as $alignment) {
        echo '<Style ss:ID="Header' . $alignment . '"><Alignment ss:Horizontal="' . $alignment
            . '" ss:Vertical="Center"/><Font ss:Bold="1" ss:Color="#FFFFFF"/>'
            . '<Interior ss:Color="#8B7A6E" ss:Pattern="Solid"/>'
            . '<Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D0C7C0"/>'
            . '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D0C7C0"/>'
            . '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D0C7C0"/>'
            . '<Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D0C7C0"/></Borders></Style>';

        foreach (['Text', 'Integer', 'Decimal', 'Currency', 'Date'] as $type_name) {
            $format = '';
            if ($type_name === 'Integer') {
                $format = '<NumberFormat ss:Format="0"/>';
            } elseif ($type_name === 'Decimal') {
                $format = '<NumberFormat ss:Format="0.00"/>';
            } elseif ($type_name === 'Currency') {
                $format = '<NumberFormat ss:Format="&quot;€&quot; #,##0.00"/>';
            }
            echo '<Style ss:ID="Cell' . $type_name . $alignment . '"><Alignment ss:Horizontal="' . $alignment
                . '" ss:Vertical="Center"/>' . $format
                . '<Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D8D1CB"/>'
                . '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D8D1CB"/>'
                . '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D8D1CB"/>'
                . '<Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D8D1CB"/></Borders></Style>';
        }
    }
    echo '</Styles>' . "\n";

    echo '<Worksheet ss:Name="' . excel_xml_escape($safe_sheet_name) . '"><Table>' . "\n";
    foreach ($headers as $_header) {
        echo '<Column ss:AutoFitWidth="1" ss:Width="110"/>';
    }

    if ($notice !== '') {
        $merge = max(0, count($headers) - 1);
        echo '<Row ss:Height="34"><Cell ss:StyleID="Notice" ss:MergeAcross="' . $merge . '"><Data ss:Type="String">'
            . excel_xml_escape($notice) . '</Data></Cell></Row>' . "\n";
    }

    echo '<Row ss:Height="24">';
    foreach ($headers as $index => $header) {
        $alignment = excel_xml_alignment((string)($alignments[$index] ?? 'left'));
        echo '<Cell ss:StyleID="Header' . $alignment . '"><Data ss:Type="String">'
            . excel_xml_escape($header) . '</Data></Cell>';
    }
    echo '</Row>' . "\n";
}

/**
 * Scrive una riga dati SpreadsheetML.
 */
function excel_xml_write_row(array $headers, array $row, array $alignments, array $types): void
{
    echo '<Row>';
    foreach ($headers as $index => $_header) {
        $alignment = excel_xml_alignment((string)($alignments[$index] ?? 'left'));
        $type = excel_xml_type((string)($types[$index] ?? 'text'));
        [$xml_type, $payload] = excel_xml_cell_payload($row[$index] ?? '', $type);
        $style_type = ucfirst($type);
        echo '<Cell ss:StyleID="Cell' . $style_type . $alignment . '"><Data ss:Type="' . $xml_type . '">'
            . excel_xml_escape($payload) . '</Data></Cell>';
    }
    echo '</Row>' . "\n";
}

function excel_xml_end(): void
{
    echo '</Table></Worksheet></Workbook>';
    exit;
}

/**
 * Esporta un insieme contenuto di righe come foglio Excel XML.
 */
function output_excel_xml_response(
    string $filename,
    string $sheet_name,
    array $headers,
    array $rows,
    array $alignments,
    array $types = [],
    string $notice = ''
): void {
    excel_xml_begin($filename, $sheet_name, $headers, $alignments, $types, $notice);
    foreach ($rows as $row) {
        excel_xml_write_row($headers, $row, $alignments, $types);
    }
    excel_xml_end();
}

/**
 * Versione streaming: evita di accumulare in memoria le righe delle chiamate.
 */
function output_excel_xml_stream_response(
    string $filename,
    string $sheet_name,
    array $headers,
    iterable $rows,
    array $alignments,
    array $types = [],
    string $notice = ''
): void {
    excel_xml_begin($filename, $sheet_name, $headers, $alignments, $types, $notice);
    $written = 0;
    foreach ($rows as $row) {
        excel_xml_write_row($headers, $row, $alignments, $types);
        $written++;
        if (($written % 500) === 0) {
            flush();
        }
    }
    excel_xml_end();
}

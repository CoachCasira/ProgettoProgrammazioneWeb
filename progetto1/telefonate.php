<?php
require_once 'includes/config.php';
require_once 'includes/validation.php';
require_once 'includes/csv.php';
require_once 'includes/performance.php';

function render_telefonate_cards(array $rows): string
{
    ob_start();
    foreach ($rows as $row): ?>
        <article class="data-card call-card">
            <div class="call-card-date">
                <span><?= htmlspecialchars(format_date_it($row['data'])) ?></span>
                <strong><?= htmlspecialchars(format_time_minutes($row['ora'])) ?></strong>
            </div>

            <div class="call-card-main">
                <a class="call-number-link" href="contratti.php?numero=<?= urlencode($row['effettuataDa']) ?>" title="Apri il dettaglio del numero telefonico associato" data-phone-card-modal="true" data-phone-number="<?= htmlspecialchars($row['effettuataDa']) ?>">
                    <span class="card-kicker">Numero chiamante</span>
                    <h3 class="card-title card-title-mono">
                        <?= htmlspecialchars($row['effettuataDa']) ?>
                    </h3>
                </a>
            </div>

            <dl class="card-detail-grid call-detail-grid">
                <div>
                    <dt>Durata</dt>
                    <dd><?= htmlspecialchars(format_duration_seconds($row['durata'])) ?></dd>
                </div>
                <div>
                    <dt>Piano</dt>
                    <dd><?= ucfirst(htmlspecialchars($row['tipoContratto'])) ?></dd>
                </div>
                <div>
                    <dt>Addebito</dt>
                    <dd><?= htmlspecialchars(format_euro($row['costo'])) ?></dd>
                </div>
            </dl>
        </article>
    <?php endforeach;
    return ob_get_clean();
}

function render_telefonate_table_rows(array $rows): string
{
    ob_start();
    foreach ($rows as $row): ?>
        <tr>
            <td class="identifier">
                <a href="contratti.php?numero=<?= urlencode($row['effettuataDa']) ?>" title="Apri il dettaglio del numero telefonico associato" data-phone-card-modal="true" data-phone-number="<?= htmlspecialchars($row['effettuataDa']) ?>">
                    <?= htmlspecialchars($row['effettuataDa']) ?>
                </a>
            </td>
            <td><?= htmlspecialchars(format_date_it($row['data'])) ?></td>
            <td><?= htmlspecialchars(format_time_minutes($row['ora'])) ?></td>
            <td class="numeric duration-value"><?= htmlspecialchars(format_duration_seconds($row['durata'])) ?></td>
            <td><?= ucfirst(htmlspecialchars($row['tipoContratto'])) ?></td>
            <td class="numeric"><?= htmlspecialchars(format_euro($row['costo'])) ?></td>
        </tr>
    <?php endforeach;
    return ob_get_clean();
}

function query_total_count(mysqli $conn, string $sql): int
{
    $result = $conn->query("SELECT COUNT(*) AS total_count FROM (" . $sql . ") AS filtered_results");
    if (!$result) {
        return 0;
    }
    $row = $result->fetch_assoc();
    return (int)($row['total_count'] ?? 0);
}

/**
 * Restituisce rapidamente il totale delle telefonate del popolamento corrente.
 * Nel progetto Telefonata e' in sola lettura e gli ID sono progressivi, senza
 * cancellazioni: MAX(id) coincide quindi con il numero totale di righe.
 * La query usa la chiave primaria e non scandisce milioni di record.
 */
function fast_unfiltered_call_count(mysqli $conn): ?int
{
    $result = $conn->query("SELECT MAX(id) AS max_id FROM Telefonata");
    if (!$result || !($row = $result->fetch_assoc())) {
        return null;
    }

    return $row['max_id'] === null ? 0 : (int)$row['max_id'];
}

$search_contratto = trim($_POST['contratto'] ?? $_GET['contratto'] ?? '');
$search_stato_numero = trim($_POST['stato_numero'] ?? $_GET['stato_numero'] ?? '');
$search_piano = trim($_POST['piano'] ?? $_GET['piano'] ?? '');
$search_ordine = trim($_POST['ordine'] ?? $_GET['ordine'] ?? 'recenti');
$search_data_da = trim($_POST['data_da'] ?? $_GET['data_da'] ?? '');
$search_data_a = trim($_POST['data_a'] ?? $_GET['data_a'] ?? '');
$search_ora_da = trim($_POST['ora_da'] ?? $_GET['ora_da'] ?? '');
$search_ora_a = trim($_POST['ora_a'] ?? $_GET['ora_a'] ?? '');
$search_durata_min = trim($_POST['durata_min'] ?? $_GET['durata_min'] ?? '');
$search_durata_sec = trim($_POST['durata_sec'] ?? $_GET['durata_sec'] ?? '');
$search_costo_max = trim($_POST['costo_max'] ?? $_GET['costo_max'] ?? '');
$limit = max(10, min(80, (int)($_POST['limit'] ?? $_GET['limit'] ?? 12)));
$offset = max(0, (int)($_POST['offset'] ?? $_GET['offset'] ?? 0));
$ajax_rows = (($_POST['ajax_rows'] ?? $_GET['ajax_rows'] ?? '') === '1');
$skip_count = (($_POST['skip_count'] ?? $_GET['skip_count'] ?? '') === '1');
$export_csv = (($_POST['export_csv'] ?? $_GET['export_csv'] ?? '') === '1');
$jump_last = (($_POST['jump_last'] ?? $_GET['jump_last'] ?? '') === '1');

$search_errors = [];
if (!is_digits_or_empty($search_contratto)) {
    $search_errors[] = 'Il campo “Numero chiamante” può contenere solo cifre. Inserire un numero e riprovare.';
}
if ($search_stato_numero !== '' && !in_array($search_stato_numero, ['attivo', 'disattivato'], true)) {
    $search_errors[] = 'Selezionare uno stato del numero valido.';
}
if ($search_piano !== '' && !in_array($search_piano, ['consumo', 'ricarica'], true)) {
    $search_errors[] = 'Selezionare un piano tariffario valido.';
}
if (!in_array($search_ordine, ['recenti', 'meno_recenti', 'durata_desc', 'durata_asc', 'costo_desc', 'costo_asc'], true)) {
    $search_errors[] = 'Selezionare un criterio di ordinamento valido.';
}
if (!is_time_minutes_or_empty($search_ora_da)) {
    $search_errors[] = 'Il campo “Dalle ore” deve contenere un orario valido nel formato ore:minuti.';
}
if (!is_time_minutes_or_empty($search_ora_a)) {
    $search_errors[] = 'Il campo “Alle ore” deve contenere un orario valido nel formato ore:minuti.';
}
if ($search_ora_da !== '' && $search_ora_a !== '' && is_time_minutes_or_empty($search_ora_da) && is_time_minutes_or_empty($search_ora_a) && $search_ora_da > $search_ora_a) {
    $search_errors[] = 'L’orario iniziale non può essere successivo all’orario finale.';
}
if (!is_non_negative_integer_or_empty($search_durata_min)) {
    $search_errors[] = 'Il campo “Durata minima - minuti” deve contenere un numero intero positivo o pari a zero.';
}
if (!is_seconds_part_or_empty($search_durata_sec)) {
    $search_errors[] = 'Il campo “Durata minima - secondi” deve contenere un valore tra 0 e 59.';
}
if (!is_non_negative_decimal_or_empty($search_costo_max)) {
    $search_errors[] = 'Il campo “Addebito massimo” deve contenere un valore numerico positivo o pari a zero.';
}

$rows = [];
$has_more = false;
$total_count = null;
$sql_base = '';

if (empty($search_errors)) {
    $where_clauses = ['1=1'];
    $summary_clauses = ['1=1'];

    if ($search_contratto !== '') {
        $contratto = $conn->real_escape_string($search_contratto);
        if (strlen($search_contratto) >= 10) {
            $where_clauses[] = "t.effettuataDa = '$contratto'";
            $summary_clauses[] = "c.numero = '$contratto'";
        } else {
            // La ricerca per prefisso usa l'indice del numero ed evita scansioni complete.
            $where_clauses[] = "t.effettuataDa LIKE '$contratto%'";
            $summary_clauses[] = "c.numero LIKE '$contratto%'";
        }
    }

    if ($search_stato_numero === 'attivo') {
        $active_condition = "EXISTS (SELECT 1 FROM SIMAttiva sa WHERE sa.associataA = c.numero)";
        $where_clauses[] = $active_condition;
        $summary_clauses[] = $active_condition;
    } elseif ($search_stato_numero === 'disattivato') {
        $disabled_condition = "NOT EXISTS (SELECT 1 FROM SIMAttiva sa WHERE sa.associataA = c.numero)
                               AND EXISTS (SELECT 1 FROM SIMDisattiva sd WHERE sd.eraAssociataA = c.numero)";
        $where_clauses[] = "($disabled_condition)";
        $summary_clauses[] = "($disabled_condition)";
    }

    if ($search_piano !== '') {
        $piano = $conn->real_escape_string($search_piano);
        $where_clauses[] = "c.tipo = '$piano'";
        $summary_clauses[] = "c.tipo = '$piano'";
    }

    if ($search_data_da !== '') {
        $data_da = $conn->real_escape_string($search_data_da);
        $where_clauses[] = "t.data >= '$data_da'";
    }
    if ($search_data_a !== '') {
        $data_a = $conn->real_escape_string($search_data_a);
        $where_clauses[] = "t.data <= '$data_a'";
    }
    if ($search_ora_da !== '') {
        $ora_da = $conn->real_escape_string(time_minutes_for_sql($search_ora_da, false));
        $where_clauses[] = "t.ora >= '$ora_da'";
    }
    if ($search_ora_a !== '') {
        $ora_a = $conn->real_escape_string(time_minutes_for_sql($search_ora_a, true));
        $where_clauses[] = "t.ora <= '$ora_a'";
    }
    if ($search_durata_min !== '' || $search_durata_sec !== '') {
        $durata_minuti = $search_durata_min !== '' ? (int)$search_durata_min : 0;
        $durata_secondi = $search_durata_sec !== '' ? (int)$search_durata_sec : 0;
        $durata_totale = ($durata_minuti * 60) + $durata_secondi;
        $where_clauses[] = "t.durata >= $durata_totale";
    }
    if ($search_costo_max !== '') {
        $costo_max = decimal_for_sql($search_costo_max);
        $where_clauses[] = "t.costo <= $costo_max";
    }

    $from_sql = "FROM Telefonata t
                 JOIN ContrattoTelefonico c ON c.numero = t.effettuataDa";
    $where_sql = implode(' AND ', $where_clauses);

    $order_options = [
        'recenti' => 't.data DESC, t.ora DESC, t.id DESC',
        'meno_recenti' => 't.data ASC, t.ora ASC, t.id ASC',
        'durata_desc' => 't.durata DESC, t.data DESC, t.ora DESC, t.id DESC',
        'durata_asc' => 't.durata ASC, t.data DESC, t.ora DESC, t.id DESC',
        'costo_desc' => 't.costo DESC, t.data DESC, t.ora DESC, t.id DESC',
        'costo_asc' => 't.costo ASC, t.data DESC, t.ora DESC, t.id DESC'
    ];
    $order_by = $order_options[$search_ordine] ?? $order_options['recenti'];

    $sql_base = "SELECT t.id, t.effettuataDa, t.data, t.ora, t.durata, t.costo,
                        c.tipo AS tipoContratto
                 $from_sql
                 WHERE $where_sql
                 ORDER BY $order_by";

    // Il conteggio totale viene calcolato una sola volta all'apertura o dopo un filtro.
    // Senza filtri usiamo MAX(id), che sfrutta la chiave primaria ed evita COUNT
    // e JOIN su oltre tre milioni di righe. I blocchi caricati durante lo scroll
    // continuano a non ripetere il conteggio.
    if (!$skip_count && (!$ajax_rows || $offset === 0)) {
        $has_call_detail_filters = $search_data_da !== ''
            || $search_data_a !== ''
            || $search_ora_da !== ''
            || $search_ora_a !== ''
            || $search_durata_min !== ''
            || $search_durata_sec !== ''
            || $search_costo_max !== '';
        $has_contract_filters = $search_contratto !== ''
            || $search_stato_numero !== ''
            || $search_piano !== '';

        if (!$has_call_detail_filters && !$has_contract_filters) {
            $total_count = fast_unfiltered_call_count($conn);
        }

        if ($total_count === null && !$has_call_detail_filters && performance_table_exists($conn, 'StatisticheContratto')) {
            $summary_where = implode(' AND ', $summary_clauses);
            $count_result = $conn->query("SELECT COALESCE(SUM(sc.numeroTelefonate), 0) AS total_count
                                          FROM StatisticheContratto sc
                                          JOIN ContrattoTelefonico c ON c.numero = sc.numero
                                          WHERE $summary_where");
            if ($count_result && ($count_row = $count_result->fetch_assoc())) {
                $total_count = (int)($count_row['total_count'] ?? 0);
            }
        }

        if ($total_count === null) {
            // Il JOIN con ContrattoTelefonico serve al conteggio solo quando il
            // filtro riguarda stato o piano. Negli altri casi contiamo direttamente
            // sull'indice di Telefonata.
            $count_from_sql = ($search_stato_numero === '' && $search_piano === '')
                ? 'FROM Telefonata t'
                : $from_sql;
            $count_result = $conn->query("SELECT COUNT(*) AS total_count $count_from_sql WHERE $where_sql");
            if ($count_result && ($count_row = $count_result->fetch_assoc())) {
                $total_count = (int)($count_row['total_count'] ?? 0);
            } else {
                $total_count = 0;
            }
        }
    }

    if ($export_csv) {
        $csv_rows = [];
        $export_result = $conn->query($sql_base);
        if ($export_result) {
            while ($row = $export_result->fetch_assoc()) {
                $csv_rows[] = [
                    csv_excel_identifier($row['effettuataDa']),
                    format_date_it($row['data']),
                    format_time_minutes($row['ora']),
                    format_duration_seconds($row['durata']),
                    ucfirst((string)$row['tipoContratto']),
                    csv_currency_value($row['costo'])
                ];
            }
        }
        output_csv_response('chiamate.csv', ['Numero chiamante', 'Data', 'Ora', 'Durata', 'Piano', 'Addebito'], $csv_rows);
    }

    $has_any_filter = $search_contratto !== ''
        || $search_stato_numero !== ''
        || $search_piano !== ''
        || $search_data_da !== ''
        || $search_data_a !== ''
        || $search_ora_da !== ''
        || $search_ora_a !== ''
        || $search_durata_min !== ''
        || $search_durata_sec !== ''
        || $search_costo_max !== '';

    if ($jump_last) {
        // L'ultimo blocco viene recuperato invertendo temporaneamente l'ordinamento.
        // In questo modo evitiamo un OFFSET di milioni di righe sulla tabella Telefonata.
        $reverse_order_options = [
            'recenti' => 't.data ASC, t.ora ASC, t.id ASC',
            'meno_recenti' => 't.data DESC, t.ora DESC, t.id DESC',
            'durata_desc' => 't.durata ASC, t.data ASC, t.ora ASC, t.id ASC',
            'durata_asc' => 't.durata DESC, t.data ASC, t.ora ASC, t.id ASC',
            'costo_desc' => 't.costo ASC, t.data ASC, t.ora ASC, t.id ASC',
            'costo_asc' => 't.costo DESC, t.data ASC, t.ora ASC, t.id ASC'
        ];
        $reverse_order_by = $reverse_order_options[$search_ordine] ?? $reverse_order_options['recenti'];

        if (!$has_any_filter && $search_ordine === 'recenti') {
            $page_size = $limit;
            $sql = "SELECT ultime.id, ultime.effettuataDa, ultime.data, ultime.ora,
                           ultime.durata, ultime.costo, c.tipo AS tipoContratto
                    FROM (
                        SELECT id, effettuataDa, data, ora, durata, costo
                        FROM Telefonata
                        ORDER BY data ASC, ora ASC, id ASC
                        LIMIT $page_size
                    ) AS ultime
                    JOIN ContrattoTelefonico c ON c.numero = ultime.effettuataDa
                    ORDER BY ultime.data ASC, ultime.ora ASC, ultime.id ASC";
        } else {
            $sql = "SELECT t.id, t.effettuataDa, t.data, t.ora, t.durata, t.costo,
                           c.tipo AS tipoContratto
                    $from_sql
                    WHERE $where_sql
                    ORDER BY $reverse_order_by
                    LIMIT $limit";
        }
    } elseif (!$has_any_filter && $search_ordine === 'recenti') {
        // Percorso veloce della schermata iniziale: MySQL seleziona prima solo il
        // piccolo blocco richiesto usando l'indice (data, ora), poi esegue il JOIN
        // con ContrattoTelefonico sulle sole righe effettivamente visualizzate.
        $page_size = $limit + 1;
        $sql = "SELECT recenti.id, recenti.effettuataDa, recenti.data, recenti.ora,
                       recenti.durata, recenti.costo, c.tipo AS tipoContratto
                FROM (
                    SELECT id, effettuataDa, data, ora, durata, costo
                    FROM Telefonata
                    ORDER BY data DESC, ora DESC, id DESC
                    LIMIT $page_size OFFSET $offset
                ) AS recenti
                JOIN ContrattoTelefonico c ON c.numero = recenti.effettuataDa
                ORDER BY recenti.data DESC, recenti.ora DESC, recenti.id DESC";
    } else {
        $sql = $sql_base . " LIMIT " . ($limit + 1) . " OFFSET " . $offset;
    }

    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        if ($jump_last) {
            // Ripristina l'ordinamento scelto dall'utente all'interno dell'ultimo blocco.
            $rows = array_reverse($rows);
            $has_more = false;
        } elseif (count($rows) > $limit) {
            $has_more = true;
            $rows = array_slice($rows, 0, $limit);
        }
    }
}

if ($ajax_rows) {
    header('Content-Type: application/json; charset=utf-8');
    $payload = [
        'html' => render_telefonate_cards($rows),
        'table_html' => render_telefonate_table_rows($rows),
        'has_more' => $has_more,
        'has_prev' => $offset > 0,
        'next_offset' => $offset + count($rows),
        'prev_offset' => $offset
    ];
    if ($total_count !== null) {
        $payload['total_count'] = $total_count;
    }
    echo json_encode($payload);
    exit;
}
?>
<?php include 'includes/header.php'; ?>

<h2>Ricerca chiamate effettuate</h2>

<div class="sticky-data-panel">
<div class="search-filter">
    <form id="telefonate-filter" class="compact-filter-form telefonate-filter-form" method="POST" action="telefonate.php" data-ajax-form="true" data-live-search="true" data-update-target="#telefonate-results">
        <div class="form-group call-number-filter-group">
            <label for="contratto">Numero chiamante:</label>
            <input type="text" id="contratto" name="contratto" value="<?= htmlspecialchars($search_contratto) ?>" placeholder="Es. 340" inputmode="numeric" autocomplete="off" data-clearable="true">
        </div>
        <div class="form-group call-state-filter-group">
            <label for="stato_numero">Stato del numero:</label>
            <select id="stato_numero" name="stato_numero">
                <option value="">Mostra tutti</option>
                <option value="attivo" <?= $search_stato_numero == 'attivo' ? 'selected' : '' ?>>Numeri attivi</option>
                <option value="disattivato" <?= $search_stato_numero == 'disattivato' ? 'selected' : '' ?>>Numeri disattivati</option>
            </select>
        </div>
        <div class="form-group period-filter-group range-filter-group">
            <label>Periodo:</label>
            <div class="range-pair compact-range-pair date-range-pair">
                <input type="date" id="data_da" name="data_da" value="<?= htmlspecialchars($search_data_da) ?>" aria-label="Dal giorno">
                <span class="range-separator" aria-hidden="true">–</span>
                <input type="date" id="data_a" name="data_a" value="<?= htmlspecialchars($search_data_a) ?>" aria-label="Al giorno">
            </div>
        </div>
        <div class="form-group duration-filter-group">
            <label>Durata minima chiamata:</label>
            <div class="duration-range-control">
                <div class="duration-segment">
                    <input type="text" id="durata_min" name="durata_min" value="<?= htmlspecialchars($search_durata_min) ?>" placeholder="Min" inputmode="numeric" autocomplete="off" data-clearable="true" aria-label="Durata minima in minuti">
                    <span class="duration-unit">min</span>
                </div>
                <span class="compound-control-divider" aria-hidden="true"></span>
                <div class="duration-segment">
                    <input type="text" id="durata_sec" name="durata_sec" value="<?= htmlspecialchars($search_durata_sec) ?>" placeholder="Sec" inputmode="numeric" autocomplete="off" data-clearable="true" aria-label="Durata minima in secondi">
                    <span class="duration-unit">sec</span>
                </div>
            </div>
        </div>
        <div class="form-group time-filter-group range-filter-group">
            <label>Ora della chiamata:</label>
            <div class="range-pair compact-range-pair time-range-pair">
                <input type="time" id="ora_da" name="ora_da" value="<?= htmlspecialchars($search_ora_da) ?>" aria-label="Ora iniziale della chiamata">
                <span class="compound-control-divider" aria-hidden="true"></span>
                <input type="time" id="ora_a" name="ora_a" value="<?= htmlspecialchars($search_ora_a) ?>" aria-label="Ora finale della chiamata">
            </div>
        </div>
        <div class="form-group call-plan-filter-group">
            <label for="piano">Piano tariffario:</label>
            <select id="piano" name="piano">
                <option value="">Mostra tutti</option>
                <option value="consumo" <?= $search_piano === 'consumo' ? 'selected' : '' ?>>A consumo</option>
                <option value="ricarica" <?= $search_piano === 'ricarica' ? 'selected' : '' ?>>Ricaricabile</option>
            </select>
        </div>
        <div class="form-group cost-filter-group">
            <label for="costo_max">Addebito max (€):</label>
            <input type="text" id="costo_max" name="costo_max" value="<?= htmlspecialchars($search_costo_max) ?>" placeholder="Es. 1,50" inputmode="decimal" autocomplete="off" data-clearable="true">
        </div>
        <div class="form-group call-order-filter-group">
            <label for="ordine">Mostra prima:</label>
            <select id="ordine" name="ordine">
                <option value="recenti" <?= $search_ordine === 'recenti' ? 'selected' : '' ?>>Chiamate più recenti</option>
                <option value="meno_recenti" <?= $search_ordine === 'meno_recenti' ? 'selected' : '' ?>>Chiamate meno recenti</option>
                <option value="durata_desc" <?= $search_ordine === 'durata_desc' ? 'selected' : '' ?>>Durata maggiore</option>
                <option value="durata_asc" <?= $search_ordine === 'durata_asc' ? 'selected' : '' ?>>Durata minore</option>
                <option value="costo_desc" <?= $search_ordine === 'costo_desc' ? 'selected' : '' ?>>Addebito maggiore</option>
                <option value="costo_asc" <?= $search_ordine === 'costo_asc' ? 'selected' : '' ?>>Addebito minore</option>
            </select>
        </div>
        <button type="submit" class="btn">Filtra chiamate</button>
    </form>
</div>

<div id="telefonate-results" class="results-view-root" data-results-view-root="true" data-view-key="telefonate" data-current-view="cards">
    <div class="results-actions-row" data-card-modal-exclude="true">
        <div class="results-navigation" data-results-navigation="true" data-card-modal-exclude="true" aria-label="Navigazione risultati">
            <button type="button" class="results-page-button results-boundary-button" data-results-first="true" aria-label="Vai al primo risultato" title="Vai al primo risultato">⇈</button>
            <button type="button" class="results-page-button" data-results-page-prev="true" aria-label="Scorri ai risultati precedenti" title="Risultati precedenti">↑</button>
            <span class="results-counter" data-results-counter="true">0 risultati</span>
            <button type="button" class="results-page-button" data-results-page-next="true" aria-label="Scorri ai risultati successivi" title="Risultati successivi">↓</button>
            <button type="button" class="results-page-button results-boundary-button" data-results-last="true" aria-label="Vai all'ultimo risultato" title="Vai all'ultimo risultato">⇊</button>
        </div>

        <div class="results-tools" data-card-modal-exclude="true">
            <button type="button" class="btn btn-view-toggle" data-view-toggle="true" aria-label="Cambia visualizzazione risultati">
                <span class="view-toggle-icon" aria-hidden="true">▤</span>
                <span data-view-toggle-text>Vista tabellare</span>
            </button>
            <button type="submit" form="telefonate-filter" name="export_csv" value="1" class="btn btn-export" data-export-submit="true">Esporta in .CSV</button>
        </div>
    </div>

    <div class="cards-container results-data-container" data-lazy-container="true" data-lazy-form="#telefonate-filter" data-next-offset="<?= count($rows) ?>" data-prev-offset="0" data-has-prev="0" data-limit="<?= $limit ?>" data-has-more="<?= $has_more ? '1' : '0' ?>" data-total-count="<?= $total_count ?>">
        <?php if (!empty($search_errors)): ?>
            <div class="alert alert-error"><?= htmlspecialchars(implode(' ', $search_errors)) ?></div>
        <?php elseif (!empty($rows)): ?>
            <div class="view-panel view-panel-cards" data-view-panel="cards">
                <div class="result-grid call-card-grid" data-lazy-list="cards">
                    <?= render_telefonate_cards($rows) ?>
                </div>
            </div>
            <div class="view-panel view-panel-table" data-view-panel="table">
                <div class="table-container table-container-inner">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th class="identifier"><span aria-hidden="true">📱</span> Numero chiamante</th>
                                <th><span aria-hidden="true">🗓️</span> Data</th>
                                <th><span aria-hidden="true">🕒</span> Ora</th>
                                <th class="numeric"><span aria-hidden="true">⏱️</span> Durata</th>
                                <th><span aria-hidden="true">📋</span> Piano</th>
                                <th class="numeric"><span aria-hidden="true">💳</span> Addebito</th>
                            </tr>
                        </thead>
                        <tbody data-lazy-list="table">
                            <?= render_telefonate_table_rows($rows) ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php elseif ($search_contratto !== ''): ?>
            <div class="alert alert-error">Nessuna chiamata trovata per numeri di telefono che contengono “<?= htmlspecialchars($search_contratto) ?>”. Controllare il valore digitato oppure modificare gli altri filtri.</div>
        <?php else: ?>
            <div class="alert alert-error">Non sono presenti chiamate per i criteri selezionati.</div>
        <?php endif; ?>
    </div>
</div>
</div>

<?php include 'includes/footer.php'; ?>

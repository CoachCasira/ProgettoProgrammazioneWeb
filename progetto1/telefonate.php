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
$duration_filter_active = ($search_durata_min !== '' || $search_durata_sec !== '');
$duration_threshold_seconds = (($search_durata_min !== '' ? (int)$search_durata_min : 0) * 60)
    + ($search_durata_sec !== '' ? (int)$search_durata_sec : 0);
$limit = max(10, min(80, (int)($_POST['limit'] ?? $_GET['limit'] ?? 12)));
$offset = max(0, (int)($_POST['offset'] ?? $_GET['offset'] ?? 0));
$ajax_rows = (($_POST['ajax_rows'] ?? $_GET['ajax_rows'] ?? '') === '1');
$skip_count = (($_POST['skip_count'] ?? $_GET['skip_count'] ?? '') === '1');
$export_csv = (($_POST['export_csv'] ?? $_GET['export_csv'] ?? '') === '1');
$jump_last = (($_POST['jump_last'] ?? $_GET['jump_last'] ?? '') === '1');
$count_only = (($_POST['count_only'] ?? $_GET['count_only'] ?? '') === '1');

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

    if ($search_contratto !== '') {
        $contratto = $conn->real_escape_string($search_contratto);
        if (strlen($search_contratto) >= 10) {
            $where_clauses[] = "t.effettuataDa = '$contratto'";
        } else {
            $where_clauses[] = "t.effettuataDa LIKE '$contratto%'";
        }
    }

    /* Stato e piano appartengono al contratto, che contiene poche centinaia di
       righe. Ricaviamo prima i numeri compatibili e poi filtriamo Telefonata
       tramite il suo indice su effettuataDa. In questo modo il JOIN non viene
       eseguito su milioni di righe prima del LIMIT. */
    $contract_scope_clauses = ['1=1'];
    $has_contract_scope_filter = false;

    if ($search_stato_numero === 'attivo') {
        $contract_scope_clauses[] = "EXISTS (SELECT 1 FROM SIMAttiva sa WHERE sa.associataA = c.numero)";
        $has_contract_scope_filter = true;
    } elseif ($search_stato_numero === 'disattivato') {
        $contract_scope_clauses[] = "NOT EXISTS (SELECT 1 FROM SIMAttiva sa WHERE sa.associataA = c.numero)";
        $contract_scope_clauses[] = "EXISTS (SELECT 1 FROM SIMDisattiva sd WHERE sd.eraAssociataA = c.numero)";
        $has_contract_scope_filter = true;
    }

    if ($search_piano !== '') {
        $piano = $conn->real_escape_string($search_piano);
        $contract_scope_clauses[] = "c.tipo = '$piano'";
        $has_contract_scope_filter = true;
    }

    if ($has_contract_scope_filter) {
        $eligible_numbers = [];
        $contract_scope_sql = implode(' AND ', $contract_scope_clauses);
        $eligible_result = $conn->query("SELECT c.numero FROM ContrattoTelefonico c WHERE $contract_scope_sql");
        if ($eligible_result) {
            while ($eligible_row = $eligible_result->fetch_assoc()) {
                $eligible_numbers[] = "'" . $conn->real_escape_string((string)$eligible_row['numero']) . "'";
            }
        }

        if ($eligible_numbers) {
            $where_clauses[] = 't.effettuataDa IN (' . implode(',', $eligible_numbers) . ')';
        } else {
            $where_clauses[] = '0=1';
        }
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
    if ($duration_filter_active) {
        $where_clauses[] = "t.durata >= $duration_threshold_seconds";
    }
    if ($search_costo_max !== '') {
        $costo_max = decimal_for_sql($search_costo_max);
        $where_clauses[] = "t.costo <= $costo_max";
    }

    $where_sql = implode(' AND ', $where_clauses);
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

    /* Gli ordinamenti su durata e costo usano soltanto il valore e la chiave
       primaria. Gli indici secondari InnoDB contengono già l'id: evitiamo così
       un filesort su milioni di righe quando si apre "Chiamate più costose". */
    $fast_orders = [
        'recenti' => [
            'normal' => 'data DESC, ora DESC, id DESC',
            'reverse' => 'data ASC, ora ASC, id ASC',
            'index' => 'idx_telefonata_data_ora'
        ],
        'meno_recenti' => [
            'normal' => 'data ASC, ora ASC, id ASC',
            'reverse' => 'data DESC, ora DESC, id DESC',
            'index' => 'idx_telefonata_data_ora'
        ],
        'durata_desc' => [
            'normal' => 'durata DESC, id DESC',
            'reverse' => 'durata ASC, id ASC',
            'index' => 'idx_telefonata_durata'
        ],
        'durata_asc' => [
            'normal' => 'durata ASC, id ASC',
            'reverse' => 'durata DESC, id DESC',
            'index' => 'idx_telefonata_durata'
        ],
        'costo_desc' => [
            'normal' => 'costo DESC, id DESC',
            'reverse' => 'costo ASC, id ASC',
            'index' => 'idx_telefonata_costo'
        ],
        'costo_asc' => [
            'normal' => 'costo ASC, id ASC',
            'reverse' => 'costo DESC, id DESC',
            'index' => 'idx_telefonata_costo'
        ]
    ];
    $fast_order = $fast_orders[$search_ordine] ?? $fast_orders['recenti'];

    $time_order_index = performance_index_exists($conn, 'Telefonata', 'idx_telefonata_ora_durata')
        ? 'idx_telefonata_ora_durata'
        : 'idx_telefonata_data_ora';

    /* Quando l'utente imposta un intervallo orario e mantiene il criterio
       predefinito, l'ora diventa il riferimento principale a prescindere dalla
       data. Se è presente anche una durata minima, questa viene usata soltanto
       come criterio secondario tra chiamate con lo stesso orario. */
    if (($search_ora_da !== '' || $search_ora_a !== '') && $search_ordine === 'recenti') {
        $fast_order = [
            'normal' => 'ora ASC, durata ASC, id ASC',
            'reverse' => 'ora DESC, durata DESC, id DESC',
            'index' => $time_order_index
        ];
    /* Senza filtro orario, una durata minima definisce il punto di partenza:
       mostriamo prima le chiamate più vicine alla soglia e poi quelle più lunghe. */
    } elseif ($duration_filter_active && $search_ordine === 'recenti') {
        $fast_order = [
            'normal' => 'durata ASC, id ASC',
            'reverse' => 'durata DESC, id DESC',
            'index' => 'idx_telefonata_durata'
        ];
    }

    if ($count_only) {
        $count_result = $conn->query("SELECT COUNT(*) AS total_count FROM Telefonata t WHERE $where_sql");
        $count_value = 0;
        if ($count_result && ($count_row = $count_result->fetch_assoc())) {
            $count_value = (int)($count_row['total_count'] ?? 0);
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['total_count' => $count_value]);
        exit;
    }

    /* Il totale senza filtri è immediato. Con filtri viene calcolato da una
       richiesta separata dopo aver già mostrato il primo blocco, così l'utente
       vede subito il risultato e non attende il COUNT su milioni di righe. */
    if (!$has_any_filter) {
        $total_count = fast_unfiltered_call_count($conn);
        if ($total_count === null) {
            $total_count = 0;
        }
    } else {
        $total_count = null;
    }

    $normal_order = $fast_order['normal'];
    $reverse_order = $fast_order['reverse'];
    $index_name = $fast_order['index'];

    if ($export_csv) {
        $export_order = preg_replace('/\b(id|effettuataDa|data|ora|durata|costo)\b/', 't.$1', $normal_order);
        $sql_export = "SELECT t.id, t.effettuataDa, t.data, t.ora, t.durata, t.costo,
                              c.tipo AS tipoContratto
                       FROM Telefonata t FORCE INDEX ($index_name)
                       JOIN ContrattoTelefonico c ON c.numero = t.effettuataDa
                       WHERE $where_sql
                       ORDER BY $export_order";
        $csv_rows = [];
        $export_result = $conn->query($sql_export);
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

    $inner_order = $jump_last ? $reverse_order : $normal_order;
    $outer_order = preg_replace('/\b(id|effettuataDa|data|ora|durata|costo)\b/', 'blocco.$1', $inner_order);
    $page_size = $jump_last ? $limit : ($limit + 1);
    $offset_sql = $jump_last ? '' : " OFFSET $offset";

    /* Per la ricerca di un singolo numero ordinata per data esiste un indice
       ancora più selettivo; negli altri casi usiamo l'indice dell'ordinamento. */
    if (strlen($search_contratto) >= 10 && in_array($search_ordine, ['recenti', 'meno_recenti'], true)) {
        $index_name = 'idx_telefonata_utenza_data';
    }

    $sql = "SELECT blocco.id, blocco.effettuataDa, blocco.data, blocco.ora,
                   blocco.durata, blocco.costo, c.tipo AS tipoContratto
            FROM (
                SELECT t.id, t.effettuataDa, t.data, t.ora, t.durata, t.costo
                FROM Telefonata t FORCE INDEX ($index_name)
                WHERE $where_sql
                ORDER BY $inner_order
                LIMIT $page_size$offset_sql
            ) AS blocco
            JOIN ContrattoTelefonico c ON c.numero = blocco.effettuataDa
            ORDER BY $outer_order";

    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        if ($jump_last) {
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
            <label>Periodo dal/al:</label>
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
            <label>Ora della chiamata da/a:</label>
            <div class="range-pair compact-range-pair time-range-pair">
                <input type="time" id="ora_da" name="ora_da" value="<?= htmlspecialchars($search_ora_da) ?>" aria-label="Ora iniziale della chiamata" data-clearable="true">
                <span class="compound-control-divider" aria-hidden="true"></span>
                <input type="time" id="ora_a" name="ora_a" value="<?= htmlspecialchars($search_ora_a) ?>" aria-label="Ora finale della chiamata" data-clearable="true">
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

    <div class="cards-container results-data-container" data-lazy-container="true" data-lazy-form="#telefonate-filter" data-next-offset="<?= count($rows) ?>" data-prev-offset="0" data-has-prev="0" data-limit="<?= $limit ?>" data-has-more="<?= $has_more ? '1' : '0' ?>" data-total-count="<?= $total_count === null ? '' : $total_count ?>" data-count-pending="<?= $total_count === null ? '1' : '0' ?>">
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

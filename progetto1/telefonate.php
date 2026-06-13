<?php
require_once 'includes/config.php';
require_once 'includes/validation.php';
require_once 'includes/csv.php';

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
$export_csv = (($_POST['export_csv'] ?? $_GET['export_csv'] ?? '') === '1');

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
$total_count = 0;
$sql_base = '';
if (empty($search_errors)) {
    $sql_base = "SELECT t.*, c.tipo AS tipoContratto,
                   sa.codice AS simAttivaCodice,
                   COALESCE(sdc.simDisattivaCount, 0) AS simDisattivaCount
            FROM Telefonata t
            JOIN ContrattoTelefonico c ON t.effettuataDa = c.numero
            LEFT JOIN SIMAttiva sa ON sa.associataA = t.effettuataDa
            LEFT JOIN (
                SELECT eraAssociataA, COUNT(*) AS simDisattivaCount
                FROM SIMDisattiva
                GROUP BY eraAssociataA
            ) sdc ON sdc.eraAssociataA = t.effettuataDa
            WHERE 1=1";

    if ($search_contratto !== '') {
        $contratto = $conn->real_escape_string($search_contratto);
        if (strlen($search_contratto) >= 10) {
            $sql_base .= " AND t.effettuataDa = '$contratto'";
        } else {
            $sql_base .= " AND t.effettuataDa LIKE '%$contratto%'";
        }
    }
    if ($search_stato_numero === 'attivo') {
        $sql_base .= " AND sa.codice IS NOT NULL";
    } elseif ($search_stato_numero === 'disattivato') {
        $sql_base .= " AND sa.codice IS NULL AND COALESCE(sdc.simDisattivaCount, 0) > 0";
    }
    if ($search_piano !== '') {
        $piano = $conn->real_escape_string($search_piano);
        $sql_base .= " AND c.tipo = '$piano'";
    }
    if ($search_data_da !== '') {
        $data_da = $conn->real_escape_string($search_data_da);
        $sql_base .= " AND t.data >= '$data_da'";
    }
    if ($search_data_a !== '') {
        $data_a = $conn->real_escape_string($search_data_a);
        $sql_base .= " AND t.data <= '$data_a'";
    }
    if ($search_ora_da !== '') {
        $ora_da = $conn->real_escape_string(time_minutes_for_sql($search_ora_da, false));
        $sql_base .= " AND t.ora >= '$ora_da'";
    }
    if ($search_ora_a !== '') {
        $ora_a = $conn->real_escape_string(time_minutes_for_sql($search_ora_a, true));
        $sql_base .= " AND t.ora <= '$ora_a'";
    }
    if ($search_durata_min !== '' || $search_durata_sec !== '') {
        $durata_minuti = $search_durata_min !== '' ? (int) $search_durata_min : 0;
        $durata_secondi = $search_durata_sec !== '' ? (int) $search_durata_sec : 0;
        $durata_totale = ($durata_minuti * 60) + $durata_secondi;
        $sql_base .= " AND t.durata >= $durata_totale";
    }
    if ($search_costo_max !== '') {
        $costo_max = decimal_for_sql($search_costo_max);
        $sql_base .= " AND t.costo <= $costo_max";
    }

    $order_options = [
        'recenti' => 't.data DESC, t.ora DESC, t.id DESC',
        'meno_recenti' => 't.data ASC, t.ora ASC, t.id ASC',
        'durata_desc' => 't.durata DESC, t.data DESC, t.ora DESC, t.id DESC',
        'durata_asc' => 't.durata ASC, t.data DESC, t.ora DESC, t.id DESC',
        'costo_desc' => 't.costo DESC, t.data DESC, t.ora DESC, t.id DESC',
        'costo_asc' => 't.costo ASC, t.data DESC, t.ora DESC, t.id DESC'
    ];
    $order_by = $order_options[$search_ordine] ?? $order_options['recenti'];

    $sql_base .= " ORDER BY $order_by";
    $total_count = query_total_count($conn, $sql_base);

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

    $sql = $sql_base . " LIMIT " . ($limit + 1) . " OFFSET " . $offset;
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        if (count($rows) > $limit) {
            $has_more = true;
            $rows = array_slice($rows, 0, $limit);
        }
    }
}

if ($ajax_rows) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'html' => render_telefonate_cards($rows),
        'table_html' => render_telefonate_table_rows($rows),
        'has_more' => $has_more,
        'next_offset' => $offset + count($rows),
        'total_count' => $total_count
    ]);
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
            <button type="button" class="results-page-button" data-results-page-prev="true" aria-label="Scorri ai risultati precedenti">↑</button>
            <span class="results-counter" data-results-counter="true">0 risultati</span>
            <button type="button" class="results-page-button" data-results-page-next="true" aria-label="Scorri ai risultati successivi">↓</button>
        </div>

        <div class="results-tools" data-card-modal-exclude="true">
            <button type="button" class="btn btn-view-toggle" data-view-toggle="true" aria-label="Cambia visualizzazione risultati">
                <span class="view-toggle-icon" aria-hidden="true">▤</span>
                <span data-view-toggle-text>Vista tabellare</span>
            </button>
            <button type="submit" form="telefonate-filter" name="export_csv" value="1" class="btn btn-export" data-export-submit="true">Esporta in .CSV</button>
        </div>
    </div>

    <div class="cards-container results-data-container" data-lazy-container="true" data-lazy-form="#telefonate-filter" data-next-offset="<?= count($rows) ?>" data-limit="<?= $limit ?>" data-has-more="<?= $has_more ? '1' : '0' ?>" data-total-count="<?= $total_count ?>">
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

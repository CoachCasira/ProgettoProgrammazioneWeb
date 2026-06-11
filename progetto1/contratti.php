<?php
require_once 'includes/config.php';
require_once 'includes/validation.php';

function contratto_has_active_sim(array $row): bool
{
    return !empty($row['simAttivaCodice']);
}

function contratto_has_disabled_sim(array $row): bool
{
    return !empty($row['simDisattivaCodice']);
}

function contratto_is_currently_disabled(array $row): bool
{
    return !contratto_has_active_sim($row) && contratto_has_disabled_sim($row);
}

function contratto_status_label(array $row): string
{
    return contratto_is_currently_disabled($row) ? 'Numero disattivato' : 'Numero attivo';
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

function render_disabled_sim_tile(array $row): string
{
    $disabled_count = (int)($row['simDisattivaCount'] ?? 0);
    if ($disabled_count <= 0 || !contratto_has_disabled_sim($row)) {
        return '';
    }

    $numero = (string)($row['numero'] ?? '');

    if ($disabled_count > 1) {
        $href = 'sim.php?stato=disattive&amp;numero=' . urlencode($numero);
        return '<div class="card-detail-tile card-detail-link disabled-sim-tile disabled-sim-tile-multiple">'
            . '<dt><a href="' . $href . '" class="tile-overlay-link" title="Visualizza tutte le SIM precedenti collegate a questo numero">SIM precedenti</a></dt>'
            . '<dd>' . htmlspecialchars((string)$disabled_count) . ' SIM disattivate nello storico</dd>'
            . '</div>';
    }

    $codice = htmlspecialchars($row['simDisattivaCodice']);
    $data_disattivazione = htmlspecialchars(format_date_it($row['simDisattivaDataDisattivazione']));
    $href = 'sim.php?stato=disattive&amp;codice=' . urlencode($row['simDisattivaCodice']);
    $label = contratto_has_active_sim($row) ? 'SIM precedente' : 'SIM disattivata';
    $title = contratto_has_active_sim($row)
        ? 'Apri il dettaglio della SIM precedente collegata a questo numero'
        : 'Apri il dettaglio della SIM disattivata collegata';

    return '<div class="card-detail-tile card-detail-link disabled-sim-tile">'
        . '<dt><a href="' . $href . '" class="tile-overlay-link" title="' . htmlspecialchars($title) . '" data-sim-card-modal="true" data-sim-code="' . $codice . '">' . htmlspecialchars($label) . '</a></dt>'
        . '<dd>' . $codice . '<span class="tile-subvalue">Disattivata il ' . $data_disattivazione . '</span></dd>'
        . '</div>';
}

function render_contratti_cards(array $rows): string
{
    ob_start();
    foreach ($rows as $row):
        $tipo = strtolower((string) $row['tipo']);
        $is_consumo = $tipo === 'consumo';
        $plan_label = $is_consumo ? 'A consumo' : 'Ricaricabile';
        $resource_label = $is_consumo ? 'Tempo residuo' : 'Credito residuo';
        $resource_value = $is_consumo ? format_minutes_remaining($row['minutiResidui']) : format_euro($row['creditoResiduo']);
        $num_telefonate = (int) $row['num_telefonate'];
        $is_currently_disabled = contratto_is_currently_disabled($row);
        ?>
        <article class="data-card phone-card<?= $is_currently_disabled ? ' phone-card-disabled-number' : ' phone-card-active-number' ?> expandable-card" data-expandable-card="true" tabindex="0" role="button" aria-label="Apri il dettaglio del numero <?= htmlspecialchars($row['numero']) ?>">
            <div class="data-card-header phone-card-header">
                <div>
                    <span class="card-kicker">Numero telefonico</span>
                    <h3 class="card-title card-title-mono"><?= htmlspecialchars($row['numero']) ?></h3>
                </div>
                <?php if ($is_currently_disabled): ?>
                    <span class="status-pill status-pill-number-disabled">Numero disattivato</span>
                <?php else: ?>
                    <span class="status-pill status-pill-number-active">Numero attivo</span>
                <?php endif; ?>
            </div>

            <div class="phone-plan-banner" aria-label="Piano tariffario del numero">
                <span>Piano tariffario</span>
                <strong><?= htmlspecialchars($plan_label) ?></strong>
            </div>

            <div class="card-primary-metric">
                <span><?= htmlspecialchars($resource_label) ?></span>
                <strong><?= htmlspecialchars($resource_value) ?></strong>
            </div>

            <dl class="card-detail-grid phone-detail-grid">
                <div class="card-detail-tile">
                    <dt>Data attivazione</dt>
                    <dd><?= htmlspecialchars(format_date_it($row['dataAttivazione'])) ?></dd>
                </div>
                <?php if ($num_telefonate > 0): ?>
                    <div class="card-detail-tile card-detail-link phone-calls-tile">
                        <dt>
                            <a href="telefonate.php?contratto=<?= urlencode($row['numero']) ?>" class="tile-overlay-link" title="Visualizza le chiamate di questo numero telefonico">
                                Chiamate registrate
                            </a>
                        </dt>
                        <dd><?= htmlspecialchars((string) $num_telefonate) ?></dd>
                    </div>
                <?php else: ?>
                    <div class="card-detail-tile">
                        <dt>Chiamate registrate</dt>
                        <dd>Nessun traffico</dd>
                    </div>
                <?php endif; ?>
                <?= render_disabled_sim_tile($row) ?>
            </dl>
        </article>
    <?php endforeach;
    return ob_get_clean();
}

function render_contratti_table_rows(array $rows): string
{
    ob_start();
    foreach ($rows as $row):
        $tipo = strtolower((string) $row['tipo']);
        $is_consumo = $tipo === 'consumo';
        $tempo = $is_consumo ? format_minutes_remaining($row['minutiResidui']) : '-';
        $credito = $is_consumo ? '-' : format_euro($row['creditoResiduo']);
        $num_telefonate = (int) $row['num_telefonate'];
        $is_currently_disabled = contratto_is_currently_disabled($row);
        ?>
        <tr class="<?= $is_currently_disabled ? 'number-disabled-row' : '' ?>">
            <td class="identifier"><?= htmlspecialchars($row['numero']) ?></td>
            <td>
                <?php if ($is_currently_disabled): ?>
                    <span class="status-pill status-pill-number-disabled">Numero disattivato</span>
                <?php else: ?>
                    <span class="status-pill status-pill-number-active">Numero attivo</span>
                <?php endif; ?>
            </td>
            <td><?= htmlspecialchars(format_date_it($row['dataAttivazione'])) ?></td>
            <td><?= $is_consumo ? 'A consumo' : 'Ricaricabile' ?></td>
            <td class="numeric duration-value"><?= htmlspecialchars($tempo) ?></td>
            <td class="numeric"><?= htmlspecialchars($credito) ?></td>
            <td class="numeric">
                <?php if ($num_telefonate > 0): ?>
                    <a href="telefonate.php?contratto=<?= urlencode($row['numero']) ?>" title="Visualizza le chiamate di questo numero telefonico">
                        <?= htmlspecialchars((string) $num_telefonate) ?> chiamate
                    </a>
                <?php else: ?>
                    Nessun traffico
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach;
    return ob_get_clean();
}

function output_csv_response(string $filename, array $headers, array $rows): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');

    // BOM UTF-8: permette ad Excel di riconoscere correttamente caratteri come "€".
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, $headers, ';');
    foreach ($rows as $row) {
        fputcsv($out, $row, ';');
    }
    fclose($out);
    exit;
}

$search_numero = trim($_POST['numero'] ?? $_GET['numero'] ?? '');
$search_tipo = trim($_POST['tipo'] ?? $_GET['tipo'] ?? '');
$search_stato_numero = trim($_POST['stato_numero'] ?? $_GET['stato_numero'] ?? '');
$search_data_da = trim($_POST['data_da'] ?? $_GET['data_da'] ?? '');
$search_data_a = trim($_POST['data_a'] ?? $_GET['data_a'] ?? '');
$limit = max(8, min(60, (int)($_POST['limit'] ?? $_GET['limit'] ?? 12)));
$offset = max(0, (int)($_POST['offset'] ?? $_GET['offset'] ?? 0));
$ajax_rows = (($_POST['ajax_rows'] ?? $_GET['ajax_rows'] ?? '') === '1');
$export_csv = (($_POST['export_csv'] ?? $_GET['export_csv'] ?? '') === '1');

$search_errors = [];
if (!is_digits_or_empty($search_numero)) {
    $search_errors[] = 'Il campo “Numero di telefono” può contenere solo cifre. Inserire un numero e riprovare.';
}
if ($search_stato_numero !== '' && !in_array($search_stato_numero, ['attivo', 'disattivato'], true)) {
    $search_errors[] = 'Selezionare uno stato del numero valido.';
}

$rows = [];
$has_more = false;
$total_count = 0;
$sql_base = '';

if (empty($search_errors)) {
    $sql_base = "SELECT c.*,
                   COALESCE(tf.num_telefonate, 0) AS num_telefonate,
                   sa.codice AS simAttivaCodice,
                   sd.codice AS simDisattivaCodice,
                   sd.tipoSIM AS simDisattivaTipoSIM,
                   sd.dataAttivazione AS simDisattivaDataAttivazione,
                   sd.dataDisattivazione AS simDisattivaDataDisattivazione,
                   COALESCE(sdc.simDisattivaCount, 0) AS simDisattivaCount
            FROM ContrattoTelefonico c
            LEFT JOIN (
                SELECT effettuataDa, COUNT(*) AS num_telefonate
                FROM Telefonata
                GROUP BY effettuataDa
            ) tf ON c.numero = tf.effettuataDa
            LEFT JOIN SIMAttiva sa ON sa.associataA = c.numero
            LEFT JOIN SIMDisattiva sd ON sd.codice = (
                SELECT sd2.codice
                FROM SIMDisattiva sd2
                WHERE sd2.eraAssociataA = c.numero
                ORDER BY sd2.dataDisattivazione DESC, sd2.codice ASC
                LIMIT 1
            )
            LEFT JOIN (
                SELECT eraAssociataA, COUNT(*) AS simDisattivaCount
                FROM SIMDisattiva
                GROUP BY eraAssociataA
            ) sdc ON sdc.eraAssociataA = c.numero
            WHERE 1=1";

    if ($search_numero !== '') {
        $numero = $conn->real_escape_string($search_numero);
        if (strlen($search_numero) >= 10) {
            $sql_base .= " AND c.numero = '$numero'";
        } else {
            $sql_base .= " AND c.numero LIKE '%$numero%'";
        }
    }
    if ($search_tipo !== '') {
        $tipo = $conn->real_escape_string($search_tipo);
        $sql_base .= " AND c.tipo = '$tipo'";
    }
    if ($search_stato_numero === 'attivo') {
        $sql_base .= " AND sa.codice IS NOT NULL";
    } elseif ($search_stato_numero === 'disattivato') {
        $sql_base .= " AND sa.codice IS NULL AND COALESCE(sdc.simDisattivaCount, 0) > 0";
    }
    if ($search_data_da !== '') {
        $data_da = $conn->real_escape_string($search_data_da);
        $sql_base .= " AND c.dataAttivazione >= '$data_da'";
    }
    if ($search_data_a !== '') {
        $data_a = $conn->real_escape_string($search_data_a);
        $sql_base .= " AND c.dataAttivazione <= '$data_a'";
    }

    // Mostriamo solo numeri con una SIM attiva oppure con almeno una SIM disattivata nello storico.
    // I contratti senza alcuna SIM associata non sono utili per la consultazione operativa dell'utente.
    $sql_base .= " AND (sa.codice IS NOT NULL OR COALESCE(sdc.simDisattivaCount, 0) > 0)";

    $sql_base .= " ORDER BY c.dataAttivazione DESC, c.numero ASC";
    $total_count = query_total_count($conn, $sql_base);

    if ($export_csv) {
        $csv_rows = [];
        $export_result = $conn->query($sql_base);
        if ($export_result) {
            while ($row = $export_result->fetch_assoc()) {
                $tipo = strtolower((string)$row['tipo']);
                $is_consumo = $tipo === 'consumo';
                $csv_rows[] = [
                    $row['numero'],
                    contratto_status_label($row),
                    $row['simDisattivaCodice'] ?: '',
                    $row['simDisattivaDataDisattivazione'] ? format_date_it($row['simDisattivaDataDisattivazione']) : '',
                    format_date_it($row['dataAttivazione']),
                    $is_consumo ? 'A consumo' : 'Ricaricabile',
                    $is_consumo ? format_minutes_remaining($row['minutiResidui']) : '',
                    $is_consumo ? '' : format_euro($row['creditoResiduo']),
                    (string)(int)$row['num_telefonate']
                ];
            }
        }
        output_csv_response('numeri_telefonici.csv', ['Numero di telefono', 'Stato numero', 'SIM disattivata collegata', 'Data disattivazione SIM', 'Data attivazione', 'Piano', 'Tempo residuo', 'Credito residuo', 'Chiamate registrate'], $csv_rows);
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
        'html' => render_contratti_cards($rows),
        'table_html' => render_contratti_table_rows($rows),
        'has_more' => $has_more,
        'next_offset' => $offset + count($rows),
        'total_count' => $total_count
    ]);
    exit;
}
?>
<?php include 'includes/header.php'; ?>

<h2>Ricerca numeri telefonici</h2>

<div class="sticky-data-panel">
<div class="search-filter">
    <form id="contratti-filter" method="POST" action="contratti.php" data-ajax-form="true" data-live-search="true" data-update-target="#contratti-results">
        <div class="form-group">
            <label for="numero">Numero di telefono:</label>
            <input type="text" id="numero" name="numero" value="<?= htmlspecialchars($search_numero) ?>" placeholder="Es. 340" inputmode="numeric" autocomplete="off" data-clearable="true">
        </div>
        <div class="form-group">
            <label for="tipo">Piano del numero:</label>
            <select id="tipo" name="tipo">
                <option value="">Mostra tutti</option>
                <option value="consumo" <?= $search_tipo == 'consumo' ? 'selected' : '' ?>>A consumo</option>
                <option value="ricarica" <?= $search_tipo == 'ricarica' ? 'selected' : '' ?>>Ricaricabile</option>
            </select>
        </div>
        <div class="form-group">
            <label for="stato_numero">Stato del numero:</label>
            <select id="stato_numero" name="stato_numero">
                <option value="">Mostra tutti</option>
                <option value="attivo" <?= $search_stato_numero == 'attivo' ? 'selected' : '' ?>>Numeri attivi</option>
                <option value="disattivato" <?= $search_stato_numero == 'disattivato' ? 'selected' : '' ?>>Numeri disattivati</option>
            </select>
        </div>
        <div class="form-group">
            <label for="data_da">Attivata dal:</label>
            <input type="date" id="data_da" name="data_da" value="<?= htmlspecialchars($search_data_da) ?>">
        </div>
        <div class="form-group">
            <label for="data_a">Attivata fino al:</label>
            <input type="date" id="data_a" name="data_a" value="<?= htmlspecialchars($search_data_a) ?>">
        </div>
        <button type="submit" class="btn">Cerca</button>
    </form>
</div>

<div id="contratti-results" class="results-view-root" data-results-view-root="true" data-view-key="contratti" data-current-view="cards">
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
            <button type="submit" form="contratti-filter" name="export_csv" value="1" class="btn btn-export" data-export-submit="true">Esporta in .CSV</button>
        </div>
    </div>

    <div class="cards-container results-data-container" data-lazy-container="true" data-lazy-form="#contratti-filter" data-next-offset="<?= count($rows) ?>" data-limit="<?= $limit ?>" data-has-more="<?= $has_more ? '1' : '0' ?>" data-total-count="<?= $total_count ?>">
        <?php if (!empty($search_errors)): ?>
            <div class="alert alert-error"><?= htmlspecialchars(implode(' ', $search_errors)) ?></div>
        <?php elseif (!empty($rows)): ?>
            <div class="view-panel view-panel-cards" data-view-panel="cards">
                <div class="result-grid phone-card-grid<?= count($rows) === 1 ? ' is-single-result-grid' : '' ?>" data-lazy-list="cards">
                    <?= render_contratti_cards($rows) ?>
                </div>
            </div>
            <div class="view-panel view-panel-table" data-view-panel="table">
                <div class="table-container table-container-inner">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th class="identifier"><span aria-hidden="true">📱</span> Numero di telefono</th>
                                <th><span aria-hidden="true">🚫</span> Stato</th>
                                <th><span aria-hidden="true">🗓️</span> Data attivazione</th>
                                <th><span aria-hidden="true">📋</span> Piano</th>
                                <th class="numeric"><span aria-hidden="true">⏱️</span> Tempo residuo</th>
                                <th class="numeric"><span aria-hidden="true">💳</span> Credito residuo</th>
                                <th class="numeric"><span aria-hidden="true">📞</span> Chiamate registrate</th>
                            </tr>
                        </thead>
                        <tbody data-lazy-list="table">
                            <?= render_contratti_table_rows($rows) ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php elseif ($search_numero !== ''): ?>
            <div class="alert alert-error">Nessun numero telefonico contiene le cifre “<?= htmlspecialchars($search_numero) ?>”. Controllare il valore digitato oppure modificare gli altri filtri.</div>
        <?php else: ?>
            <div class="alert alert-error">La ricerca non ha prodotto alcun risultato. Modificare i filtri impostati.</div>
        <?php endif; ?>
    </div>
</div>
</div>

<?php include 'includes/footer.php'; ?>

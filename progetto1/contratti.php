<?php
require_once 'includes/config.php';
require_once 'includes/validation.php';
require_once 'includes/csv.php';
require_once 'includes/performance.php';

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

function render_active_sim_tile(array $row): string
{
    if (!contratto_has_active_sim($row)) {
        return '';
    }

    $codice_raw = (string)($row['simAttivaCodice'] ?? '');
    $codice = htmlspecialchars($codice_raw);
    $href = 'sim.php?stato=attive&amp;codice=' . urlencode($codice_raw);
    $is_only_sim_tile = (int)($row['simDisattivaCount'] ?? 0) <= 0;
    $tile_class = 'card-detail-tile card-detail-link active-sim-tile'
        . ($is_only_sim_tile ? ' phone-sim-tile-single' : '');

    return '<div class="' . $tile_class . '">'
        . '<dt><a href="' . $href . '" class="tile-overlay-link" title="Apri il dettaglio della SIM attualmente associata" data-sim-card-modal="true" data-sim-code="' . $codice . '">SIM in uso</a></dt>'
        . '<dd>' . $codice . '</dd>'
        . '</div>';
}

function render_disabled_sim_tile(array $row): string
{
    $disabled_count = (int)($row['simDisattivaCount'] ?? 0);
    if ($disabled_count <= 0 || !contratto_has_disabled_sim($row)) {
        return '';
    }

    $numero = (string)($row['numero'] ?? '');

    $is_only_sim_tile = !contratto_has_active_sim($row);
    $single_tile_class = $is_only_sim_tile ? ' phone-sim-tile-single' : '';

    if ($disabled_count > 1) {
        $href = 'sim.php?stato=disattive&amp;numero=' . urlencode($numero);
        return '<div class="card-detail-tile card-detail-link disabled-sim-tile disabled-sim-tile-multiple' . $single_tile_class . '">'
            . '<dt><a href="' . $href . '" class="tile-overlay-link" title="Visualizza tutte le SIM precedenti collegate a questo numero" data-sim-history-modal="true" data-phone-number="' . htmlspecialchars($numero) . '">SIM precedenti</a></dt>'
            . '<dd>' . htmlspecialchars((string)$disabled_count) . ' SIM disattivate nello storico</dd>'
            . '</div>';
    }

    $codice = htmlspecialchars($row['simDisattivaCodice']);
    $href = 'sim.php?stato=disattive&amp;codice=' . urlencode($row['simDisattivaCodice']);
    $label = contratto_has_active_sim($row) ? 'SIM precedente' : 'SIM disattivata';
    $title = contratto_has_active_sim($row)
        ? 'Apri il dettaglio della SIM precedente collegata a questo numero'
        : 'Apri il dettaglio della SIM disattivata collegata';

    return '<div class="card-detail-tile card-detail-link disabled-sim-tile' . $single_tile_class . '">'
        . '<dt><a href="' . $href . '" class="tile-overlay-link" title="' . htmlspecialchars($title) . '" data-sim-card-modal="true" data-sim-code="' . $codice . '">' . htmlspecialchars($label) . '</a></dt>'
        . '<dd>' . $codice . '</dd>'
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
        $durata_totale = (int)($row['durata_totale'] ?? 0);
        $costo_totale = (float)($row['costo_totale'] ?? 0);
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
                <div class="card-detail-tile<?= $is_currently_disabled ? ' phone-disabled-date-tile' : '' ?>">
                    <dt><?= $is_currently_disabled ? 'Data disattivazione' : 'Data attivazione' ?></dt>
                    <dd><?= htmlspecialchars(format_date_it($is_currently_disabled ? ($row['simDisattivaDataDisattivazione'] ?? '') : $row['dataAttivazione'])) ?></dd>
                </div>
                <?php if ($is_currently_disabled): ?>
                    <div class="card-detail-tile phone-activation-modal-only">
                        <dt>Data attivazione</dt>
                        <dd><?= htmlspecialchars(format_date_it($row['dataAttivazione'])) ?></dd>
                    </div>
                <?php endif; ?>
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
                        <dd>0</dd>
                    </div>
                <?php endif; ?>
                <div class="card-detail-tile phone-duration-tile">
                    <dt>Durata chiamate tot</dt>
                    <dd><?= htmlspecialchars(format_total_duration_compact($durata_totale)) ?></dd>
                </div>
                <div class="card-detail-tile phone-charge-tile">
                    <dt>Addebiti totali</dt>
                    <dd><?= htmlspecialchars(format_euro($costo_totale)) ?></dd>
                </div>
                <?= render_active_sim_tile($row) ?>
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
        $durata_totale = (int)($row['durata_totale'] ?? 0);
        $costo_totale = (float)($row['costo_totale'] ?? 0);
        $is_currently_disabled = contratto_is_currently_disabled($row);
        ?>
        <tr>
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
                    0
                <?php endif; ?>
            </td>
            <td class="numeric duration-value"><?= htmlspecialchars(format_total_duration_compact($durata_totale)) ?></td>
            <td class="numeric"><?= htmlspecialchars(format_euro($costo_totale)) ?></td>
        </tr>
    <?php endforeach;
    return ob_get_clean();
}

$search_numero = trim($_POST['numero'] ?? $_GET['numero'] ?? '');
$search_tipo = trim($_POST['tipo'] ?? $_GET['tipo'] ?? '');
$search_stato_numero = trim($_POST['stato_numero'] ?? $_GET['stato_numero'] ?? '');
$search_min_chiamate = trim($_POST['min_chiamate'] ?? $_GET['min_chiamate'] ?? '');
$search_min_chiamate_custom = trim($_POST['min_chiamate_custom'] ?? $_GET['min_chiamate_custom'] ?? '');
$search_durata_preset = trim($_POST['durata_preset'] ?? $_GET['durata_preset'] ?? '');
$search_durata_ore = trim($_POST['durata_ore'] ?? $_GET['durata_ore'] ?? '');
$search_durata_min = trim($_POST['durata_min'] ?? $_GET['durata_min'] ?? '');
$search_durata_sec = trim($_POST['durata_sec'] ?? $_GET['durata_sec'] ?? '');

/* Soglie rapide essenziali per la durata complessiva dei numeri.
   I valori meno comuni restano disponibili tramite “Durata personalizzata”. */
$phone_duration_presets = [
    '30m' => [0, 30, 0],
    '1h' => [1, 0, 0],
    '5h' => [5, 0, 0],
    '24h' => [24, 0, 0]
];

/* Compatibilità con link e sessioni della v60: una durata già composta viene
   interpretata come personalizzata quando il nuovo selettore non è presente. */
if ($search_durata_preset === ''
        && ($search_durata_ore !== '' || $search_durata_min !== '' || $search_durata_sec !== '')) {
    $search_durata_preset = 'custom';
}
if (isset($phone_duration_presets[$search_durata_preset])) {
    [$preset_hours, $preset_minutes, $preset_seconds] = $phone_duration_presets[$search_durata_preset];
    $search_durata_ore = (string)$preset_hours;
    $search_durata_min = (string)$preset_minutes;
    $search_durata_sec = (string)$preset_seconds;
}

$duration_filter_active = ($search_durata_ore !== '' || $search_durata_min !== '' || $search_durata_sec !== '');
$duration_threshold_seconds = duration_parts_to_seconds($search_durata_ore, $search_durata_min, $search_durata_sec);
$search_ordine = trim($_POST['ordine'] ?? $_GET['ordine'] ?? 'recenti');
if ($search_ordine === '' || $search_ordine === 'automatico') {
    // Compatibilità con vecchi link e sessioni: il criterio base è quello recente.
    $search_ordine = 'recenti';
}

/* L'ordinamento temporale predefinito segue lo stato richiesto: per i numeri
   disattivati è più utile la data di disattivazione, mentre per quelli attivi
   resta significativa la data di attivazione. Con "Mostra tutti" entrambi i
   criteri rimangono disponibili e vengono scelti esplicitamente dall'utente. */
if ($search_stato_numero === 'disattivato' && $search_ordine === 'recenti') {
    $search_ordine = 'disattivati_recenti';
} elseif ($search_stato_numero === 'attivo' && $search_ordine === 'disattivati_recenti') {
    $search_ordine = 'recenti';
}
$search_residuo = trim($_POST['residuo'] ?? $_GET['residuo'] ?? '');
if ($search_residuo === 'quasi_esaurito') {
    // Compatibilità con il vecchio collegamento della Panoramica.
    $search_residuo = 'esaurito';
}

/*
 * Alcuni filtri sulla disponibilità identificano già in modo univoco il piano:
 * credito -> ricaricabile, minuti -> a consumo. Normalizziamo anche lato server
 * eventuali URL o richieste incoerenti, così non possono produrre artificialmente
 * zero risultati selezionando due criteri logicamente incompatibili.
 */
$residual_plan_requirements = [
    'credito_basso' => 'ricarica',
    'credito_disponibile' => 'ricarica',
    'minuti_bassi' => 'consumo',
    'minuti_disponibili' => 'consumo'
];
if (isset($residual_plan_requirements[$search_residuo])) {
    $search_tipo = $residual_plan_requirements[$search_residuo];
}

$effective_min_chiamate = 0;
$search_data_da = trim($_POST['data_da'] ?? $_GET['data_da'] ?? '');
$search_data_a = trim($_POST['data_a'] ?? $_GET['data_a'] ?? '');
$limit = max(8, min(60, (int)($_POST['limit'] ?? $_GET['limit'] ?? 12)));
$offset = max(0, (int)($_POST['offset'] ?? $_GET['offset'] ?? 0));
$ajax_rows = (($_POST['ajax_rows'] ?? $_GET['ajax_rows'] ?? '') === '1');
$skip_count = (($_POST['skip_count'] ?? $_GET['skip_count'] ?? '') === '1');
$export_csv = (($_POST['export_csv'] ?? $_GET['export_csv'] ?? '') === '1');

$search_errors = [];
if (!is_digits_or_empty($search_numero)) {
    $search_errors[] = 'Il campo “Numero di telefono” può contenere solo cifre. Inserire un numero e riprovare.';
}
if ($search_stato_numero !== '' && !in_array($search_stato_numero, ['attivo', 'disattivato'], true)) {
    $search_errors[] = 'Selezionare uno stato del numero valido.';
}
if ($search_min_chiamate !== '' && !in_array($search_min_chiamate, ['1', '50', '100', 'custom'], true)) {
    $search_errors[] = 'Selezionare una soglia di chiamate valida.';
}
if ($search_min_chiamate_custom !== '' && !is_non_negative_integer_or_empty($search_min_chiamate_custom)) {
    $search_errors[] = 'Il numero minimo di chiamate deve essere un valore intero positivo o pari a zero.';
}
if ($search_durata_preset !== ''
        && $search_durata_preset !== 'custom'
        && !isset($phone_duration_presets[$search_durata_preset])) {
    $search_errors[] = 'Selezionare una soglia di durata valida.';
}
if (!is_non_negative_integer_or_empty($search_durata_ore)) {
    $search_errors[] = 'Il campo “Durata minima chiamate totali - ore” deve contenere un numero intero positivo o pari a zero.';
}
if (!is_duration_part_or_empty($search_durata_min)) {
    $search_errors[] = 'Il campo “Durata minima chiamate totali - minuti” deve contenere un valore tra 0 e 59.';
}
if (!is_seconds_part_or_empty($search_durata_sec)) {
    $search_errors[] = 'Il campo “Durata minima chiamate totali - secondi” deve contenere un valore tra 0 e 59.';
}
if (!in_array($search_ordine, ['recenti', 'disattivati_recenti', 'chiamate_crescenti', 'piu_chiamate', 'maggiore_durata', 'maggiore_spesa'], true)) {
    $search_errors[] = 'Selezionare un ordinamento valido.';
}
if ($search_residuo !== '' && !in_array($search_residuo, ['esaurito', 'credito_basso', 'minuti_bassi', 'credito_disponibile', 'minuti_disponibili'], true)) {
    $search_errors[] = 'Selezionare un filtro di disponibilità residua valido.';
}
if (!is_date_or_empty($search_data_da)) {
    $search_errors[] = 'La data iniziale del filtro non è valida.';
}
if (!is_date_or_empty($search_data_a)) {
    $search_errors[] = 'La data finale del filtro non è valida.';
}
if ($search_data_da !== '' && $search_data_a !== '' && is_date_or_empty($search_data_da) && is_date_or_empty($search_data_a) && $search_data_da > $search_data_a) {
    $search_errors[] = 'La data iniziale non può essere successiva alla data finale.';
}
if (empty($search_errors)) {
    if ($search_min_chiamate === 'custom' && $search_min_chiamate_custom !== '') {
        $effective_min_chiamate = (int)$search_min_chiamate_custom;
    } elseif ($search_min_chiamate !== '' && $search_min_chiamate !== 'custom') {
        $effective_min_chiamate = (int)$search_min_chiamate;
    }

}

$rows = [];
$has_more = false;
$total_count = 0;
$sql_base = '';

if (empty($search_errors)) {
    $contract_stats_join = performance_contract_stats_join($conn, 'tf');

    $sql_base = "SELECT c.*,
                   COALESCE(tf.numeroTelefonate, 0) AS num_telefonate,
                   COALESCE(tf.durataTotale, 0) AS durata_totale,
                   COALESCE(tf.addebitoTotale, 0) AS costo_totale,
                   sa.codice AS simAttivaCodice,
                   sa.dataAttivazione AS simAttivaDataAttivazione,
                   sd.codice AS simDisattivaCodice,
                   sd.tipoSIM AS simDisattivaTipoSIM,
                   sd.dataAttivazione AS simDisattivaDataAttivazione,
                   sd.dataDisattivazione AS simDisattivaDataDisattivazione,
                   COALESCE(sdc.simDisattivaCount, 0) AS simDisattivaCount
            FROM ContrattoTelefonico c
            $contract_stats_join
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
    if ($search_residuo === 'esaurito') {
        $sql_base .= " AND ((c.tipo = 'ricarica' AND c.creditoResiduo = 0) OR (c.tipo = 'consumo' AND c.minutiResidui = 0))";
    } elseif ($search_residuo === 'credito_basso') {
        $sql_base .= " AND c.tipo = 'ricarica' AND c.creditoResiduo > 0 AND c.creditoResiduo < 5";
    } elseif ($search_residuo === 'minuti_bassi') {
        $sql_base .= " AND c.tipo = 'consumo' AND c.minutiResidui > 0 AND c.minutiResidui < 30";
    } elseif ($search_residuo === 'credito_disponibile') {
        $sql_base .= " AND c.tipo = 'ricarica' AND c.creditoResiduo IS NOT NULL AND c.creditoResiduo >= 5";
    } elseif ($search_residuo === 'minuti_disponibili') {
        $sql_base .= " AND c.tipo = 'consumo' AND c.minutiResidui IS NOT NULL AND c.minutiResidui >= 30";
    }
    if ($effective_min_chiamate > 0) {
        $sql_base .= " AND COALESCE(tf.numeroTelefonate, 0) >= $effective_min_chiamate";
    }
    if ($duration_filter_active) {
        $sql_base .= " AND COALESCE(tf.durataTotale, 0) >= $duration_threshold_seconds";
    }
    $date_filter_column = $search_stato_numero === 'disattivato' ? 'sd.dataDisattivazione' : 'c.dataAttivazione';
    if ($search_data_da !== '') {
        $data_da = $conn->real_escape_string($search_data_da);
        $sql_base .= " AND $date_filter_column >= '$data_da'";
    }
    if ($search_data_a !== '') {
        $data_a = $conn->real_escape_string($search_data_a);
        $sql_base .= " AND $date_filter_column <= '$data_a'";
    }

    // Mostriamo solo numeri con una SIM attiva oppure con almeno una SIM disattivata nello storico.
    // I contratti senza alcuna SIM associata non sono utili per la consultazione operativa dell'utente.
    $sql_base .= " AND (sa.codice IS NOT NULL OR COALESCE(sdc.simDisattivaCount, 0) > 0)";

    if ($effective_min_chiamate > 0 && $search_ordine === 'recenti') {
        /* Se l'utente applica soltanto una soglia minima di chiamate, il punto
           di partenza naturale è la soglia stessa. Un ordinamento scelto
           esplicitamente in "Mostra prima" mantiene invece la precedenza. */
        $sql_base .= " ORDER BY num_telefonate ASC, c.dataAttivazione DESC, c.numero ASC";
    } elseif ($search_ordine === 'disattivati_recenti') {
        /* Con tutti gli stati visibili, questo criterio porta prima i numeri
           attualmente disattivati e li ordina dalla disattivazione più recente.
           I numeri ancora attivi seguono, ordinati per attivazione recente. */
        $sql_base .= " ORDER BY
                        CASE WHEN sa.codice IS NULL AND COALESCE(sdc.simDisattivaCount, 0) > 0 THEN 0 ELSE 1 END ASC,
                        CASE WHEN sa.codice IS NULL THEN sd.dataDisattivazione ELSE NULL END DESC,
                        c.dataAttivazione DESC,
                        c.numero ASC";
    } elseif ($search_ordine === 'chiamate_crescenti') {
        $sql_base .= " ORDER BY num_telefonate ASC, c.dataAttivazione DESC, c.numero ASC";
    } elseif ($search_ordine === 'piu_chiamate') {
        $sql_base .= " ORDER BY num_telefonate DESC, c.dataAttivazione DESC, c.numero ASC";
    } elseif ($search_ordine === 'maggiore_durata') {
        $sql_base .= " ORDER BY durata_totale DESC, num_telefonate DESC, c.numero ASC";
    } elseif ($search_ordine === 'maggiore_spesa') {
        $sql_base .= " ORDER BY costo_totale DESC, num_telefonate DESC, c.numero ASC";
    } elseif ($search_residuo === 'credito_basso' || $search_residuo === 'credito_disponibile') {
        /* Quando resta selezionato l'ordinamento predefinito, il filtro sulla
           disponibilità monetaria mostra prima il valore più vicino alla soglia. */
        $sql_base .= " ORDER BY c.creditoResiduo ASC, c.dataAttivazione DESC, c.numero ASC";
    } elseif ($search_residuo === 'minuti_bassi' || $search_residuo === 'minuti_disponibili') {
        /* Analogamente, il filtro sui minuti parte dalla soglia soltanto se
           l'utente non ha richiesto un differente criterio di ordinamento. */
        $sql_base .= " ORDER BY c.minutiResidui ASC, c.dataAttivazione DESC, c.numero ASC";
    } else {
        if ($search_stato_numero === '') {
            /* Con “Mostra tutti” diamo priorità ai numeri operativamente attivi.
               All'interno dei due gruppi manteniamo l'ordinamento per attivazione
               più recente, senza interferire con gli ordinamenti scelti dall'utente. */
            $sql_base .= " ORDER BY CASE WHEN sa.codice IS NOT NULL THEN 0 ELSE 1 END ASC, c.dataAttivazione DESC, c.numero ASC";
        } else {
            $sql_base .= " ORDER BY c.dataAttivazione DESC, c.numero ASC";
        }
    }
    if (!$skip_count && (!$ajax_rows || $offset === 0)) {
        $total_count = query_total_count($conn, $sql_base);
    } else {
        $total_count = null;
    }

    if ($export_csv) {
        $csv_rows = [];
        $export_result = $conn->query($sql_base);
        if ($export_result) {
            while ($row = $export_result->fetch_assoc()) {
                $tipo = strtolower((string)$row['tipo']);
                $is_consumo = $tipo === 'consumo';
                $csv_rows[] = [
                    csv_excel_identifier($row['numero']),
                    contratto_status_label($row),
                    csv_excel_identifier($row['simDisattivaCodice'] ?: ''),
                    $row['simDisattivaDataDisattivazione'] ? format_date_it($row['simDisattivaDataDisattivazione']) : '',
                    format_date_it($row['dataAttivazione']),
                    $is_consumo ? 'A consumo' : 'Ricaricabile',
                    $is_consumo ? format_minutes_remaining($row['minutiResidui']) : '',
                    $is_consumo ? '' : csv_currency_value($row['creditoResiduo']),
                    (string)(int)$row['num_telefonate'],
                    format_duration_seconds($row['durata_totale'] ?? 0),
                    csv_currency_value($row['costo_totale'] ?? 0)
                ];
            }
        }
        output_csv_response('numeri_telefonici.csv', ['Numero', 'Stato', 'SIM precedente', 'Disattivazione SIM', 'Attivazione numero', 'Piano', 'Tempo residuo', 'Credito residuo', 'Chiamate', 'Durata totale', 'Addebiti totali'], $csv_rows);
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
    $payload = [
        'html' => render_contratti_cards($rows),
        'table_html' => render_contratti_table_rows($rows),
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
$phone_date_filter_label = $search_stato_numero === 'disattivato' ? 'Disattivato dal/al:' : 'Attivato dal/al:';
?>
<?php include 'includes/header.php'; ?>

<h2>Ricerca numeri telefonici</h2>

<div class="sticky-data-panel">
<div class="search-filter">
    <form id="contratti-filter" class="compact-filter-form contratti-filter-form" method="POST" action="contratti.php" data-ajax-form="true" data-live-search="true" data-update-target="#contratti-results" data-plan-residual-sync="true" data-filter-session-key="contratti">
        <div class="form-group phone-number-filter-group">
            <label for="numero">Numero di telefono:</label>
            <input type="text" id="numero" name="numero" value="<?= htmlspecialchars($search_numero) ?>" placeholder="Es. 340" inputmode="numeric" autocomplete="off" data-clearable="true">
        </div>
        <div class="form-group phone-status-filter-group">
            <label for="stato_numero">Stato del numero:</label>
            <select id="stato_numero" name="stato_numero">
                <option value="">Mostra tutti</option>
                <option value="attivo" <?= $search_stato_numero == 'attivo' ? 'selected' : '' ?>>Numeri attivi</option>
                <option value="disattivato" <?= $search_stato_numero == 'disattivato' ? 'selected' : '' ?>>Numeri disattivati</option>
            </select>
        </div>
        <div class="form-group phone-date-filter-group range-filter-group">
            <label data-phone-date-label><?= htmlspecialchars($phone_date_filter_label) ?></label>
            <div class="range-pair compact-range-pair date-range-pair">
                <input type="date" id="data_da" name="data_da" value="<?= htmlspecialchars($search_data_da) ?>" aria-label="Data inizio">
                <span class="range-separator" aria-hidden="true">–</span>
                <input type="date" id="data_a" name="data_a" value="<?= htmlspecialchars($search_data_a) ?>" aria-label="Data fine">
            </div>
        </div>
        <div class="form-group residual-filter-group">
            <label for="residuo">Disponibilità del piano:</label>
            <select id="residuo" name="residuo" data-scroll-select="true">
                <option value="">Tutti i piani</option>
                <option value="esaurito" <?= $search_residuo === 'esaurito' ? 'selected' : '' ?>>Piani esauriti</option>
                <option value="credito_basso" <?= $search_residuo === 'credito_basso' ? 'selected' : '' ?>>Ricaricabili: credito &lt; 5 €</option>
                <option value="minuti_bassi" <?= $search_residuo === 'minuti_bassi' ? 'selected' : '' ?>>A consumo: minuti &lt; 30</option>
                <option value="credito_disponibile" <?= $search_residuo === 'credito_disponibile' ? 'selected' : '' ?>>Ricaricabili: credito ≥ 5 €</option>
                <option value="minuti_disponibili" <?= $search_residuo === 'minuti_disponibili' ? 'selected' : '' ?>>A consumo: minuti ≥ 30</option>
            </select>
        </div>
        <div class="form-group phone-plan-filter-group">
            <label for="tipo">Piano del numero:</label>
            <select id="tipo" name="tipo">
                <option value="">Mostra tutti</option>
                <option value="consumo" <?= $search_tipo == 'consumo' ? 'selected' : '' ?>>A consumo</option>
                <option value="ricarica" <?= $search_tipo == 'ricarica' ? 'selected' : '' ?>>Ricaricabile</option>
            </select>
        </div>
        <div class="form-group order-filter-group">
            <label for="ordine">Mostra prima:</label>
            <select id="ordine" name="ordine" data-scroll-select="true">
                <option value="recenti" <?= $search_ordine == 'recenti' ? 'selected' : '' ?>>Numeri attivati più di recente</option>
                <option value="disattivati_recenti" <?= $search_ordine == 'disattivati_recenti' ? 'selected' : '' ?>>Numeri disattivati più di recente</option>
                <option value="chiamate_crescenti" <?= $search_ordine == 'chiamate_crescenti' ? 'selected' : '' ?>>Numeri con meno chiamate</option>
                <option value="piu_chiamate" <?= $search_ordine == 'piu_chiamate' ? 'selected' : '' ?>>Numeri con più chiamate</option>
                <option value="maggiore_durata" <?= $search_ordine == 'maggiore_durata' ? 'selected' : '' ?>>Numeri con più ore di chiamata</option>
                <option value="maggiore_spesa" <?= $search_ordine == 'maggiore_spesa' ? 'selected' : '' ?>>Numeri con maggior addebito</option>
            </select>
        </div>
        <div class="form-group traffic-filter-group">
            <label for="min_chiamate">Filtro chiamate:</label>
            <select id="min_chiamate" name="min_chiamate" data-scroll-select="true" data-custom-threshold-select>
                <option value="">Mostra tutti i numeri</option>
                <option value="1" <?= $search_min_chiamate == '1' ? 'selected' : '' ?>>Con almeno 1 chiamata</option>
                <option value="50" <?= $search_min_chiamate == '50' ? 'selected' : '' ?>>Con almeno 50 chiamate</option>
                <option value="100" <?= $search_min_chiamate == '100' ? 'selected' : '' ?>>Con almeno 100 chiamate</option>
                <option value="custom" <?= $search_min_chiamate == 'custom' ? 'selected' : '' ?>>Soglia personalizzata</option>
            </select>
            <div class="custom-threshold-inline <?= $search_min_chiamate == 'custom' ? '' : 'is-hidden' ?>" data-custom-threshold-container>
                <input type="text" id="min_chiamate_custom" name="min_chiamate_custom" value="<?= htmlspecialchars($search_min_chiamate == 'custom' ? $search_min_chiamate_custom : '') ?>" placeholder="Scrivi numero minimo" inputmode="numeric" autocomplete="off" data-custom-threshold-input <?= $search_min_chiamate == 'custom' ? '' : 'disabled' ?>>
            </div>
        </div>
        <div class="form-group phone-duration-filter-group">
            <label for="durata_preset">Durata minima chiamate totali:</label>
            <div class="duration-smart-control<?= $search_durata_preset === 'custom' ? ' is-custom' : '' ?>" data-duration-control>
                <select id="durata_preset" name="durata_preset" data-scroll-select="true" data-duration-preset-select>
                    <option value="" <?= $search_durata_preset === '' ? 'selected' : '' ?>>Qualsiasi durata</option>
                    <option value="30m" <?= $search_durata_preset === '30m' ? 'selected' : '' ?>>Almeno 30 minuti</option>
                    <option value="1h" <?= $search_durata_preset === '1h' ? 'selected' : '' ?>>Almeno 1 ora</option>
                    <option value="5h" <?= $search_durata_preset === '5h' ? 'selected' : '' ?>>Almeno 5 ore</option>
                    <option value="24h" <?= $search_durata_preset === '24h' ? 'selected' : '' ?>>Almeno 24 ore</option>
                    <option value="custom" <?= $search_durata_preset === 'custom' ? 'selected' : '' ?>>Durata personalizzata…</option>
                </select>
                <div class="duration-custom-editor duration-range-control duration-three-part<?= $search_durata_preset === 'custom' ? '' : ' is-hidden' ?>" data-duration-custom-panel aria-hidden="<?= $search_durata_preset === 'custom' ? 'false' : 'true' ?>">
                    <div class="duration-segment">
                        <input type="text" id="durata_ore" name="durata_ore" value="<?= htmlspecialchars($search_durata_preset === 'custom' ? $search_durata_ore : '') ?>" placeholder="Ore" inputmode="numeric" autocomplete="off" aria-label="Durata complessiva minima in ore" <?= $search_durata_preset === 'custom' ? '' : 'disabled' ?>>
                        <span class="duration-unit">h</span>
                    </div>
                    <span class="compound-control-divider" aria-hidden="true"></span>
                    <div class="duration-segment">
                        <input type="text" id="durata_min" name="durata_min" value="<?= htmlspecialchars($search_durata_preset === 'custom' ? $search_durata_min : '') ?>" placeholder="Minuti" inputmode="numeric" autocomplete="off" aria-label="Durata complessiva minima in minuti" <?= $search_durata_preset === 'custom' ? '' : 'disabled' ?>>
                        <span class="duration-unit">min</span>
                    </div>
                    <span class="compound-control-divider" aria-hidden="true"></span>
                    <div class="duration-segment">
                        <input type="text" id="durata_sec" name="durata_sec" value="<?= htmlspecialchars($search_durata_preset === 'custom' ? $search_durata_sec : '') ?>" placeholder="Secondi" inputmode="numeric" autocomplete="off" aria-label="Durata complessiva minima in secondi" <?= $search_durata_preset === 'custom' ? '' : 'disabled' ?>>
                        <span class="duration-unit">sec</span>
                    </div>
                    <button type="button" class="duration-custom-back" data-duration-custom-back aria-label="Torna alle soglie rapide" title="Torna alle soglie rapide">↩</button>
                </div>
            </div>
        </div>
        <button type="button" class="btn btn-reset-filters" data-filter-reset="true">Azzera filtri</button>
        <button type="submit" class="btn btn-filter-submit">Cerca</button>
    </form>
</div>

<div id="contratti-results" class="results-view-root" data-results-view-root="true" data-view-key="contratti" data-current-view="cards">
    <div class="results-actions-row" data-card-modal-exclude="true">
        <div class="results-navigation" data-results-navigation="true" data-card-modal-exclude="true" aria-label="Navigazione risultati">
            <button type="button" class="results-page-button results-boundary-button" data-results-first="true" aria-label="Vai al primo risultato" title="Vai al primo risultato"><span class="results-boundary-icon results-boundary-icon-up" aria-hidden="true"></span></button>
            <button type="button" class="results-page-button" data-results-page-prev="true" aria-label="Scorri ai risultati precedenti" title="Risultati precedenti">↑</button>
            <span class="results-counter" data-results-counter="true">0 risultati</span>
            <button type="button" class="results-page-button" data-results-page-next="true" aria-label="Scorri ai risultati successivi" title="Risultati successivi">↓</button>
            <button type="button" class="results-page-button results-boundary-button" data-results-last="true" aria-label="Vai all'ultimo risultato" title="Vai all'ultimo risultato"><span class="results-boundary-icon results-boundary-icon-down" aria-hidden="true"></span></button>
        </div>

        <div class="results-tools" data-card-modal-exclude="true">
            <button type="button" class="btn btn-view-toggle" data-view-toggle="true" aria-label="Cambia visualizzazione risultati">
                <span class="view-toggle-icon" aria-hidden="true">▤</span>
                <span data-view-toggle-text>Vista tabellare</span>
            </button>
            <button type="submit" form="contratti-filter" name="export_csv" value="1" class="btn btn-export" data-export-submit="true">Esporta in .CSV</button>
        </div>
    </div>

    <div class="cards-container results-data-container" data-lazy-container="true" data-lazy-form="#contratti-filter" data-next-offset="<?= count($rows) ?>" data-prev-offset="0" data-has-prev="0" data-limit="<?= $limit ?>" data-has-more="<?= $has_more ? '1' : '0' ?>" data-total-count="<?= $total_count ?>">
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
                                <th class="identifier"><span class="table-header-icon table-icon-phone" aria-hidden="true"></span>Numero di telefono</th>
                                <th><span class="table-header-icon table-icon-status" aria-hidden="true"></span>Stato</th>
                                <th><span class="table-header-icon table-icon-calendar" aria-hidden="true"></span>Data attivazione</th>
                                <th><span class="table-header-icon table-icon-plan" aria-hidden="true"></span>Piano</th>
                                <th class="numeric"><span class="table-header-icon table-icon-timer" aria-hidden="true"></span>Tempo residuo</th>
                                <th class="numeric"><span class="table-header-icon table-icon-credit" aria-hidden="true"></span>Credito residuo</th>
                                <th class="numeric"><span class="table-header-icon table-icon-calls" aria-hidden="true"></span>Chiamate registrate</th>
                                <th class="numeric"><span class="table-header-icon table-icon-timer" aria-hidden="true"></span>Durata totale</th>
                                <th class="numeric"><span class="table-header-icon table-icon-money" aria-hidden="true"></span>Addebiti totali</th>
                            </tr>
                        </thead>
                        <tbody data-lazy-list="table">
                            <?= render_contratti_table_rows($rows) ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <?php
            $contratti_zero_criteria = [];
            if ($search_numero !== '') {
                $contratti_zero_criteria[] = 'numero contenente ' . $search_numero;
            }
            if ($search_stato_numero === 'attivo') {
                $contratti_zero_criteria[] = 'stato: numeri attivi';
            } elseif ($search_stato_numero === 'disattivato') {
                $contratti_zero_criteria[] = 'stato: numeri disattivati';
            }
            if ($search_data_da !== '' || $search_data_a !== '') {
                $contratti_zero_criteria[] = strtolower(rtrim($phone_date_filter_label, ':')) . ': ' . ($search_data_da !== '' ? format_date_it($search_data_da) : 'inizio archivio') . ' - ' . ($search_data_a !== '' ? format_date_it($search_data_a) : 'oggi');
            }
            if ($search_tipo === 'consumo') {
                $contratti_zero_criteria[] = 'piano: a consumo';
            } elseif ($search_tipo === 'ricarica') {
                $contratti_zero_criteria[] = 'piano: ricaricabile';
            }
            $residual_labels = [
                'esaurito' => 'disponibilità: piani esauriti',
                'credito_basso' => 'disponibilità: credito inferiore a 5 €',
                'minuti_bassi' => 'disponibilità: meno di 30 minuti',
                'credito_disponibile' => 'disponibilità: credito di almeno 5 €',
                'minuti_disponibili' => 'disponibilità: almeno 30 minuti'
            ];
            if (isset($residual_labels[$search_residuo])) {
                $contratti_zero_criteria[] = $residual_labels[$search_residuo];
            }
            if ($effective_min_chiamate > 0) {
                $contratti_zero_criteria[] = 'almeno ' . $effective_min_chiamate . ' chiamate';
            }
            if ($duration_filter_active) {
                $contratti_zero_criteria[] = 'durata totale delle chiamate di almeno ' . format_duration_filter_value($duration_threshold_seconds);
            }
            $contratti_zero_message = build_filter_aware_no_results_message(
                'Nessun numero telefonico',
                $contratti_zero_criteria,
                'Non sono presenti numeri telefonici consultabili.'
            );
            ?>
            <div class="alert alert-error"><?= htmlspecialchars($contratti_zero_message) ?></div>
        <?php endif; ?>
    </div>
</div>
</div>

<?php include 'includes/footer.php'; ?>

<?php
require_once 'includes/config.php';
require_once 'includes/validation.php';
require_once 'includes/csv.php';
require_once 'includes/performance.php';

function normalize_sim_state(string $state): string
{
    $allowed = ['tutte', 'attive', 'disponibili', 'disattive'];
    return in_array($state, $allowed, true) ? $state : 'tutte';
}

function normalize_sim_states($states): array
{
    $allowed = ['attive', 'disponibili', 'disattive'];
    if (!is_array($states)) {
        $states = $states !== null && $states !== '' ? [$states] : [];
    }

    $normalized = [];
    foreach ($states as $state) {
        $state = normalize_sim_state((string)$state);
        if ($state === 'tutte') {
            return $allowed;
        }
        if (in_array($state, $allowed, true) && !in_array($state, $normalized, true)) {
            $normalized[] = $state;
        }
    }

    return !empty($normalized) ? $normalized : $allowed;
}

function sim_states_key(array $states): string
{
    $allowed = ['attive', 'disponibili', 'disattive'];
    $ordered = [];
    foreach ($allowed as $state) {
        if (in_array($state, $states, true)) {
            $ordered[] = $state;
        }
    }
    return count($ordered) === 1 ? $ordered[0] : 'tutte';
}

function sim_states_title(array $states): string
{
    $ordered = [];
    $labels = [
        'attive' => 'in uso',
        'disponibili' => 'disponibili',
        'disattive' => 'disattivate'
    ];

    foreach (['attive', 'disponibili', 'disattive'] as $state) {
        if (in_array($state, $states, true)) {
            $ordered[] = $labels[$state];
        }
    }

    if (count($ordered) === 3) {
        return 'Tutte le SIM';
    }
    if (count($ordered) === 1) {
        return 'SIM ' . $ordered[0];
    }
    return 'SIM ' . implode(' e ', $ordered);
}

function sim_states_filter_label(array $states): string
{
    $ordered = [];
    $labels = [
        'attive' => 'SIM in uso',
        'disponibili' => 'SIM disponibili',
        'disattive' => 'SIM disattivate'
    ];

    foreach (['attive', 'disponibili', 'disattive'] as $state) {
        if (in_array($state, $states, true)) {
            $ordered[] = $labels[$state];
        }
    }

    if (count($ordered) === 0 || count($ordered) === 3) {
        return 'Mostra tutte';
    }
    return implode(' e ', $ordered);
}

function sim_states_for_sql(mysqli $conn, array $states): string
{
    $safe_states = array_map(static function ($state) use ($conn) {
        return "'" . $conn->real_escape_string($state) . "'";
    }, $states);
    return implode(',', $safe_states);
}

function sim_state_title(string $state): string
{
    if ($state === 'attive') {
        return 'SIM in uso';
    }
    if ($state === 'disponibili') {
        return 'SIM disponibili';
    }
    if ($state === 'disattive') {
        return 'SIM disattivate';
    }
    return 'Tutte le SIM';
}

function sim_row_state(array $row, string $current_state): string
{
    if ($current_state === 'tutte') {
        return normalize_sim_state((string)($row['_sim_state'] ?? ''));
    }
    return $current_state;
}

function sim_return_state(string $current_state, string $row_state): string
{
    return $current_state === 'tutte' ? 'tutte' : $row_state;
}

function sim_state_badge_label(string $state): string
{
    if ($state === 'attive') {
        return 'In uso';
    }
    if ($state === 'disponibili') {
        return 'Disponibile';
    }
    if ($state === 'disattive') {
        return 'Storico';
    }
    return 'SIM';
}

function sim_state_badge_class(string $state): string
{
    if ($state === 'attive') {
        return 'status-pill-active';
    }
    if ($state === 'disponibili') {
        return 'status-pill-available';
    }
    if ($state === 'disattive') {
        return 'status-pill-disabled';
    }
    return '';
}

function is_valid_date_value(string $date): bool
{
    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function later_date(?string $first, ?string $second): ?string
{
    if (!$first) {
        return $second ?: null;
    }
    if (!$second) {
        return $first;
    }
    return strtotime($first) >= strtotime($second) ? $first : $second;
}

function refresh_sim_statistics(mysqli $conn, string $codice, string $state): void
{
    if (!performance_table_exists($conn, 'StatisticheSIM')) {
        return;
    }

    if (!in_array($state, ['attive', 'disattive', 'disponibili'], true) || $codice === '') {
        return;
    }

    $safe_code = $conn->real_escape_string($codice);
    $safe_state = $conn->real_escape_string($state);
    $index_name = performance_index_exists($conn, 'Telefonata', 'idx_telefonata_utenza_data')
        ? 'idx_telefonata_utenza_data'
        : (performance_index_exists($conn, 'Telefonata', 'idx_telefonata_utenza') ? 'idx_telefonata_utenza' : '');
    $index_hint = $index_name !== '' ? " FORCE INDEX (`$index_name`)" : '';

    if ($state === 'attive') {
        $sql = "INSERT INTO StatisticheSIM (codice, stato, numeroChiamate)
                SELECT s.codice, 'attive', COUNT(t.id)
                FROM SIMAttiva s
                LEFT JOIN Telefonata t$index_hint
                       ON t.effettuataDa = s.associataA
                      AND t.data >= s.dataAttivazione
                WHERE s.codice = '$safe_code'
                GROUP BY s.codice
                ON DUPLICATE KEY UPDATE numeroChiamate = VALUES(numeroChiamate)";
    } elseif ($state === 'disattive') {
        $sql = "INSERT INTO StatisticheSIM (codice, stato, numeroChiamate)
                SELECT s.codice, 'disattive', COUNT(t.id)
                FROM SIMDisattiva s
                LEFT JOIN Telefonata t$index_hint
                       ON t.effettuataDa = s.eraAssociataA
                      AND t.data BETWEEN s.dataAttivazione AND s.dataDisattivazione
                WHERE s.codice = '$safe_code'
                GROUP BY s.codice
                ON DUPLICATE KEY UPDATE numeroChiamate = VALUES(numeroChiamate)";
    } else {
        $sql = "INSERT INTO StatisticheSIM (codice, stato, numeroChiamate)
                SELECT n.codice, 'disponibili', 0
                FROM SIMNonAttiva n
                WHERE n.codice = '$safe_code'
                ON DUPLICATE KEY UPDATE numeroChiamate = 0";
    }

    $conn->query($sql);
    $conn->query("DELETE FROM StatisticheSIM WHERE codice = '$safe_code' AND stato <> '$safe_state'");
}

function delete_sim_statistics(mysqli $conn, string $codice): void
{
    if (!performance_table_exists($conn, 'StatisticheSIM') || $codice === '') {
        return;
    }
    $safe_code = $conn->real_escape_string($codice);
    $conn->query("DELETE FROM StatisticheSIM WHERE codice = '$safe_code'");
}

function get_numero_info(mysqli $conn, string $numero): array
{
    $info = [
        'exists' => false,
        'numero' => $numero,
        'dataAttivazione' => '',
        'ultimaChiamata' => '',
        'dataMinimaDisattivazione' => '',
        'dataMassimaDisattivazione' => date('Y-m-d'),
        'message' => ''
    ];

    if ($numero === '' || !ctype_digit($numero)) {
        $info['message'] = 'Inserire un numero di telefono composto solo da cifre.';
        return $info;
    }

    $numero_sql = $conn->real_escape_string($numero);
    $contratto_res = $conn->query("SELECT numero, dataAttivazione FROM ContrattoTelefonico WHERE numero='$numero_sql' LIMIT 1");
    if (!$contratto_res || $contratto_res->num_rows === 0) {
        $info['message'] = 'Il numero indicato non risulta presente tra i numeri telefonici registrati.';
        return $info;
    }

    $contratto = $contratto_res->fetch_assoc();
    $chiamata_res = $conn->query("SELECT MAX(data) AS ultimaChiamata FROM Telefonata WHERE effettuataDa='$numero_sql'");
    $ultima_chiamata = '';
    if ($chiamata_res) {
        $chiamata_row = $chiamata_res->fetch_assoc();
        $ultima_chiamata = $chiamata_row['ultimaChiamata'] ?? '';
    }

    $data_attivazione = $contratto['dataAttivazione'];
    $info['exists'] = true;
    $info['dataAttivazione'] = $data_attivazione;
    $info['ultimaChiamata'] = $ultima_chiamata ?: '';
    $info['dataMinimaDisattivazione'] = later_date($data_attivazione, $ultima_chiamata) ?: $data_attivazione;
    return $info;
}

function get_ultima_chiamata_by_numero(mysqli $conn, string $numero): string
{
    $numero_sql = $conn->real_escape_string($numero);
    $chiamata_res = $conn->query("SELECT MAX(data) AS ultimaChiamata FROM Telefonata WHERE effettuataDa='$numero_sql'");
    if (!$chiamata_res) {
        return '';
    }

    $chiamata_row = $chiamata_res->fetch_assoc();
    return $chiamata_row['ultimaChiamata'] ?? '';
}


function get_sim_status_info(mysqli $conn, string $codice): array
{
    $info = [
        'exists' => false,
        'status' => '',
        'codice' => $codice,
        'tipoSIM' => '',
        'numero' => '',
        'dataAttivazione' => '',
        'ultimaChiamata' => '',
        'dataMinimaDisattivazione' => '',
        'dataMassimaDisattivazione' => date('Y-m-d'),
        'message' => ''
    ];

    if ($codice === '' || !ctype_digit($codice)) {
        $info['message'] = 'Inserire un codice SIM composto solo da cifre.';
        return $info;
    }

    $codice_sql = $conn->real_escape_string($codice);

    $active_res = $conn->query("SELECT s.codice, s.tipoSIM, s.associataA, s.dataAttivazione AS dataSIM
                                FROM SIMAttiva s
                                JOIN ContrattoTelefonico c ON s.associataA = c.numero
                                WHERE s.codice='$codice_sql'
                                LIMIT 1");
    if ($active_res && $active_res->num_rows > 0) {
        $row = $active_res->fetch_assoc();
        $ultima_chiamata = get_ultima_chiamata_by_numero($conn, $row['associataA']);
        $info['exists'] = true;
        $info['status'] = 'attiva';
        $info['tipoSIM'] = $row['tipoSIM'];
        $info['numero'] = $row['associataA'];
        $info['dataAttivazione'] = $row['dataSIM'];
        $info['ultimaChiamata'] = $ultima_chiamata;
        $info['dataMinimaDisattivazione'] = later_date($row['dataSIM'], $ultima_chiamata) ?: $row['dataSIM'];
        $info['message'] = 'SIM in uso trovata. I dati collegati sono stati recuperati automaticamente.';
        return $info;
    }

    $available_res = $conn->query("SELECT codice FROM SIMNonAttiva WHERE codice='$codice_sql' LIMIT 1");
    if ($available_res && $available_res->num_rows > 0) {
        $info['exists'] = true;
        $info['status'] = 'disponibile';
        $info['message'] = 'La SIM indicata è disponibile per un nuovo numero e non può essere disattivata perché non risulta in uso.';
        return $info;
    }

    $disabled_res = $conn->query("SELECT codice FROM SIMDisattiva WHERE codice='$codice_sql' LIMIT 1");
    if ($disabled_res && $disabled_res->num_rows > 0) {
        $info['exists'] = true;
        $info['status'] = 'disattiva';
        $info['message'] = 'La SIM indicata è già presente nello storico delle SIM disattivate.';
        return $info;
    }

    $info['message'] = 'Il codice SIM indicato non risulta tra le SIM registrate nel sistema.';
    return $info;
}

function get_active_sim_by_numero(mysqli $conn, string $numero): array
{
    $info = get_numero_info($conn, $numero);
    $info['hasActiveSim'] = false;
    $info['codice'] = '';
    $info['tipoSIM'] = '';
    $info['status'] = '';

    if (!$info['exists']) {
        return $info;
    }

    $numero_sql = $conn->real_escape_string($numero);
    $active_res = $conn->query("SELECT codice, tipoSIM, dataAttivazione FROM SIMAttiva WHERE associataA='$numero_sql' LIMIT 1");
    if ($active_res && $active_res->num_rows > 0) {
        $row = $active_res->fetch_assoc();
        $ultima_chiamata = get_ultima_chiamata_by_numero($conn, $numero);
        $info['hasActiveSim'] = true;
        $info['status'] = 'attiva';
        $info['codice'] = $row['codice'];
        $info['tipoSIM'] = $row['tipoSIM'];
        $info['dataAttivazione'] = $row['dataAttivazione'];
        $info['ultimaChiamata'] = $ultima_chiamata;
        $info['dataMinimaDisattivazione'] = later_date($row['dataAttivazione'], $ultima_chiamata) ?: $row['dataAttivazione'];
        $info['message'] = 'SIM in uso associata al numero trovata. I dati collegati sono stati recuperati automaticamente.';
        return $info;
    }

    $info['message'] = 'Il numero indicato è registrato, ma non risulta associato a una SIM in uso da disattivare.';
    return $info;
}

function field_error(array $errors, string $field): string
{
    return htmlspecialchars($errors[$field] ?? '');
}

function render_sim_rows(array $rows, string $state): string
{
    ob_start();
    foreach ($rows as $row):
        $row_state = sim_row_state($row, $state);
        $return_state = sim_return_state($state, $row_state);
        ?>
        <?php if ($row_state === 'attive'): ?>
            <article class="data-card sim-card sim-card-active expandable-card" data-expandable-card="true" data-sim-code="<?= htmlspecialchars($row['codice']) ?>" tabindex="0" role="button" aria-label="Apri il dettaglio della SIM in uso <?= htmlspecialchars($row['codice']) ?>">
                <div class="data-card-header">
                    <div>
                        <span class="card-kicker">SIM in uso</span>
                        <h3 class="card-title card-title-mono"><?= htmlspecialchars($row['codice']) ?></h3>
                    </div>
                    <span class="status-pill status-pill-active">In uso</span>
                </div>

                <dl class="card-detail-grid">
                    <div class="card-detail-tile card-detail-link sim-phone-tile">
                        <dt>
                            <a href="contratti.php?numero=<?= urlencode($row['associataA']) ?>" class="tile-overlay-link" title="Apri il dettaglio del numero telefonico associato" data-phone-card-modal="true" data-phone-number="<?= htmlspecialchars($row['associataA']) ?>">
                                Numero associato
                            </a>
                        </dt>
                        <dd><?= htmlspecialchars($row['associataA']) ?></dd>
                    </div>
                    <div>
                        <dt>Data attivazione</dt>
                        <dd><?= htmlspecialchars(format_date_it($row['dataAttivazione'])) ?></dd>
                    </div>
                    <div>
                        <dt>Formato SIM</dt>
                        <dd><?= htmlspecialchars($row['tipoSIM']) ?></dd>
                    </div>
                    <div>
                        <dt>Piano</dt>
                        <dd><?= ucfirst(htmlspecialchars((string)$row['tipoContratto'])) ?></dd>
                    </div>
                </dl>

                <div class="card-actions">
                    <a href="sim.php?stato=disattive&amp;action=create&amp;codice=<?= urlencode($row['codice']) ?>&amp;return_stato=<?= urlencode($return_state) ?>" class="card-action-link action-disable-sim" title="Registra questa SIM nello storico delle disattivate">Disattiva SIM</a>
                </div>
            </article>
        <?php elseif ($row_state === 'disponibili'): ?>
            <article class="data-card sim-card sim-card-available expandable-card" data-expandable-card="true" data-sim-code="<?= htmlspecialchars($row['codice']) ?>" tabindex="0" role="button" aria-label="Apri il dettaglio della SIM disponibile <?= htmlspecialchars($row['codice']) ?>">
                <div class="data-card-header">
                    <div>
                        <span class="card-kicker">SIM disponibile</span>
                        <h3 class="card-title card-title-mono"><?= htmlspecialchars($row['codice']) ?></h3>
                    </div>
                    <span class="status-pill status-pill-available">Disponibile</span>
                </div>

                <dl class="card-detail-grid card-detail-grid-compact">
                    <div>
                        <dt>Formato SIM</dt>
                        <dd><?= htmlspecialchars($row['tipoSIM']) ?></dd>
                    </div>
                    <div>
                        <dt>Stato operativo</dt>
                        <dd>Non associata a un numero</dd>
                    </div>
                </dl>
            </article>
        <?php else: ?>
            <article class="data-card sim-card sim-card-disabled expandable-card" data-expandable-card="true" data-sim-code="<?= htmlspecialchars($row['codice']) ?>" tabindex="0" role="button" aria-label="Apri il dettaglio della SIM disattivata <?= htmlspecialchars($row['codice']) ?>">
                <div class="data-card-header">
                    <div>
                        <span class="card-kicker">SIM disattivata</span>
                        <h3 class="card-title card-title-mono"><?= htmlspecialchars($row['codice']) ?></h3>
                    </div>
                    <span class="status-pill status-pill-disabled">Storico</span>
                </div>

                <dl class="card-detail-grid">
                    <div class="card-detail-tile card-detail-link sim-phone-tile">
                        <dt>
                            <a href="contratti.php?numero=<?= urlencode($row['eraAssociataA']) ?>" class="tile-overlay-link" title="Apri il dettaglio del numero telefonico precedentemente associato" data-phone-card-modal="true" data-phone-number="<?= htmlspecialchars($row['eraAssociataA']) ?>">
                                Numero precedente
                            </a>
                        </dt>
                        <dd><?= htmlspecialchars($row['eraAssociataA']) ?></dd>
                    </div>
                    <div class="sim-activation-modal-only">
                        <dt>Data attivazione</dt>
                        <dd><?= htmlspecialchars(format_date_it($row['dataAttivazione'])) ?></dd>
                    </div>
                    <div>
                        <dt>Data disattivazione</dt>
                        <dd><?= htmlspecialchars(format_date_it($row['dataDisattivazione'])) ?></dd>
                    </div>
                    <div>
                        <dt>Formato SIM</dt>
                        <dd><?= htmlspecialchars($row['tipoSIM']) ?></dd>
                    </div>
                    <div>
                        <dt>Piano</dt>
                        <dd><?= $row['tipoContratto'] !== null ? ucfirst(htmlspecialchars((string)$row['tipoContratto'])) : '-' ?></dd>
                    </div>
                </dl>

                <div class="card-actions card-actions-split">
                    <a href="sim.php?stato=disattive&amp;action=edit&amp;codice=<?= urlencode($row['codice']) ?>&amp;return_stato=<?= urlencode($return_state) ?>" class="action-edit action-edit-sim">Modifica</a>
                    <a href="sim.php?stato=disattive&amp;action=confirm_delete&amp;codice=<?= urlencode($row['codice']) ?>&amp;return_stato=<?= urlencode($return_state) ?>" class="action-delete action-delete-sim">Elimina</a>
                </div>
            </article>
        <?php endif; ?>
    <?php endforeach;
    return ob_get_clean();
}



function render_sim_table_header(string $state): string
{
    if ($state === 'tutte') {
        return '<tr><th class="identifier">🔢 Codice SIM</th><th>⚙️ Stato</th><th class="identifier">📱 Numero collegato</th><th>🗓️ Data attivazione</th><th>🛑 Data disattivazione</th><th>📐 Formato SIM</th><th>📋 Piano</th><th class="sim-actions-column">⚙️ Azioni</th></tr>';
    }
    if ($state === 'attive') {
        return '<tr><th class="identifier sim-code-cell">🔢 Codice SIM</th><th class="identifier">📱 Numero associato</th><th>🗓️ Data attivazione</th><th>📐 Formato SIM</th><th>📋 Piano</th></tr>';
    }
    if ($state === 'disponibili') {
        return '<tr><th class="identifier">🔢 Codice SIM</th><th>📐 Formato SIM</th><th>⚙️ Stato operativo</th></tr>';
    }
    return '<tr><th class="identifier">🔢 Codice SIM</th><th class="identifier">📱 Numero precedente</th><th>🗓️ Data attivazione</th><th>🛑 Data disattivazione</th><th>📐 Formato SIM</th><th>📋 Piano</th><th class="sim-actions-column">⚙️ Azioni</th></tr>';
}

function render_sim_table_rows(array $rows, string $state): string
{
    ob_start();
    foreach ($rows as $row):
        $row_state = sim_row_state($row, $state);
        $return_state = sim_return_state($state, $row_state);
        ?>
        <?php if ($state === 'tutte'): ?>
            <tr data-sim-code="<?= htmlspecialchars($row['codice']) ?>">
                <td class="identifier sim-code-cell"><?= htmlspecialchars($row['codice']) ?></td>
                <td><span class="status-pill <?= htmlspecialchars(sim_state_badge_class($row_state)) ?>"><?= htmlspecialchars(sim_state_badge_label($row_state)) ?></span></td>
                <td class="identifier sim-phone-table-cell">
                    <?php if ($row_state === 'attive'): ?>
                        <a href="contratti.php?numero=<?= urlencode($row['associataA']) ?>" title="Apri il dettaglio del numero telefonico associato" data-phone-card-modal="true" data-phone-number="<?= htmlspecialchars($row['associataA']) ?>"><?= htmlspecialchars($row['associataA']) ?></a>
                    <?php elseif ($row_state === 'disattive'): ?>
                        <a href="contratti.php?numero=<?= urlencode($row['eraAssociataA']) ?>" title="Apri il dettaglio del numero telefonico precedentemente associato" data-phone-card-modal="true" data-phone-number="<?= htmlspecialchars($row['eraAssociataA']) ?>"><?= htmlspecialchars($row['eraAssociataA']) ?></a>
                    <?php else: ?>
                        Non associata
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars(format_date_it($row['dataAttivazione'] ?? '')) ?></td>
                <td><?= $row_state === 'disattive' ? htmlspecialchars(format_date_it($row['dataDisattivazione'])) : '-' ?></td>
                <td><?= htmlspecialchars($row['tipoSIM']) ?></td>
                <td><?= ($row['tipoContratto'] ?? null) !== null ? ucfirst(htmlspecialchars((string)$row['tipoContratto'])) : '-' ?></td>
                <td class="sim-actions-column">
                    <?php if ($row_state === 'attive'): ?>
                        <a href="sim.php?stato=disattive&amp;action=create&amp;codice=<?= urlencode($row['codice']) ?>&amp;return_stato=<?= urlencode($return_state) ?>" class="card-action-link action-disable-sim" title="Registra questa SIM nello storico delle disattivate">Disattiva SIM</a>
                    <?php elseif ($row_state === 'disattive'): ?>
                        <div class="table-action-group">
                            <a href="sim.php?stato=disattive&amp;action=edit&amp;codice=<?= urlencode($row['codice']) ?>&amp;return_stato=<?= urlencode($return_state) ?>" class="action-edit action-edit-sim">Modifica</a>
                            <a href="sim.php?stato=disattive&amp;action=confirm_delete&amp;codice=<?= urlencode($row['codice']) ?>&amp;return_stato=<?= urlencode($return_state) ?>" class="action-delete action-delete-sim">Elimina</a>
                        </div>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>
            </tr>
        <?php elseif ($row_state === 'attive'): ?>
            <tr data-sim-code="<?= htmlspecialchars($row['codice']) ?>">
                <td class="identifier sim-code-cell">
                    <div class="sim-code-actions">
                        <a href="sim.php?stato=disattive&amp;action=create&amp;codice=<?= urlencode($row['codice']) ?>&amp;return_stato=<?= urlencode($return_state) ?>" class="card-action-link action-disable-sim" title="Registra questa SIM nello storico delle disattivate">Disattiva SIM</a>
                        <span class="sim-code-value"><?= htmlspecialchars($row['codice']) ?></span>
                    </div>
                </td>
                <td class="identifier sim-phone-table-cell">
                    <a href="contratti.php?numero=<?= urlencode($row['associataA']) ?>" title="Apri il dettaglio del numero telefonico associato" data-phone-card-modal="true" data-phone-number="<?= htmlspecialchars($row['associataA']) ?>">
                        <?= htmlspecialchars($row['associataA']) ?>
                    </a>
                </td>
                <td><?= htmlspecialchars(format_date_it($row['dataAttivazione'])) ?></td>
                <td><?= htmlspecialchars($row['tipoSIM']) ?></td>
                <td><?= ucfirst(htmlspecialchars((string)$row['tipoContratto'])) ?></td>
            </tr>
        <?php elseif ($row_state === 'disponibili'): ?>
            <tr data-sim-code="<?= htmlspecialchars($row['codice']) ?>">
                <td class="identifier"><?= htmlspecialchars($row['codice']) ?></td>
                <td><?= htmlspecialchars($row['tipoSIM']) ?></td>
                <td>Non associata a un numero</td>
            </tr>
        <?php else: ?>
            <tr data-sim-code="<?= htmlspecialchars($row['codice']) ?>">
                <td class="identifier"><?= htmlspecialchars($row['codice']) ?></td>
                <td class="identifier sim-phone-table-cell">
                    <a href="contratti.php?numero=<?= urlencode($row['eraAssociataA']) ?>" title="Apri il dettaglio del numero telefonico precedentemente associato" data-phone-card-modal="true" data-phone-number="<?= htmlspecialchars($row['eraAssociataA']) ?>">
                        <?= htmlspecialchars($row['eraAssociataA']) ?>
                    </a>
                </td>
                <td><?= htmlspecialchars(format_date_it($row['dataAttivazione'])) ?></td>
                <td><?= htmlspecialchars(format_date_it($row['dataDisattivazione'])) ?></td>
                <td><?= htmlspecialchars($row['tipoSIM']) ?></td>
                <td><?= $row['tipoContratto'] !== null ? ucfirst(htmlspecialchars((string)$row['tipoContratto'])) : '-' ?></td>
                <td class="sim-actions-column">
                    <div class="table-action-group">
                        <a href="sim.php?stato=disattive&amp;action=edit&amp;codice=<?= urlencode($row['codice']) ?>&amp;return_stato=<?= urlencode($return_state) ?>" class="action-edit action-edit-sim">Modifica</a>
                        <a href="sim.php?stato=disattive&amp;action=confirm_delete&amp;codice=<?= urlencode($row['codice']) ?>&amp;return_stato=<?= urlencode($return_state) ?>" class="action-delete action-delete-sim">Elimina</a>
                    </div>
                </td>
            </tr>
        <?php endif; ?>
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

function sim_csv_headers(string $state): array
{
    if ($state === 'tutte') {
        return ['Codice SIM', 'Stato', 'Numero collegato', 'Data attivazione', 'Data disattivazione', 'Formato SIM', 'Piano'];
    }
    if ($state === 'attive') {
        return ['Codice SIM', 'Numero associato', 'Data attivazione', 'Formato SIM', 'Piano'];
    }
    if ($state === 'disponibili') {
        return ['Codice SIM', 'Formato SIM', 'Stato operativo'];
    }
    return ['Codice SIM', 'Numero precedente', 'Data attivazione', 'Data disattivazione', 'Formato SIM', 'Piano'];
}

function sim_csv_row(array $row, string $state): array
{
    $row_state = sim_row_state($row, $state);

    if ($state === 'tutte') {
        $numero_collegato = '-';
        if ($row_state === 'attive') {
            $numero_collegato = (string)$row['associataA'];
        } elseif ($row_state === 'disattive') {
            $numero_collegato = (string)$row['eraAssociataA'];
        }

        return [
            csv_excel_identifier($row['codice']),
            sim_state_title($row_state),
            csv_excel_identifier($numero_collegato),
            format_date_it($row['dataAttivazione'] ?? ''),
            $row_state === 'disattive' ? format_date_it($row['dataDisattivazione']) : '-',
            $row['tipoSIM'],
            ($row['tipoContratto'] ?? null) !== null ? ucfirst((string)$row['tipoContratto']) : '-'
        ];
    }

    if ($row_state === 'attive') {
        return [csv_excel_identifier($row['codice']), csv_excel_identifier($row['associataA']), format_date_it($row['dataAttivazione']), $row['tipoSIM'], ucfirst((string)$row['tipoContratto'])];
    }
    if ($row_state === 'disponibili') {
        return [csv_excel_identifier($row['codice']), $row['tipoSIM'], 'Non associata a un numero'];
    }
    return [csv_excel_identifier($row['codice']), csv_excel_identifier($row['eraAssociataA']), format_date_it($row['dataAttivazione']), format_date_it($row['dataDisattivazione']), $row['tipoSIM'], $row['tipoContratto'] !== null ? ucfirst((string)$row['tipoContratto']) : '-'];
}

$allowed_actions = ['list', 'create', 'edit', 'confirm_delete'];
$action = $_GET['action'] ?? 'list';
if (!in_array($action, $allowed_actions, true)) {
    $action = 'list';
}

$state = normalize_sim_state($_POST['stato'] ?? $_GET['stato'] ?? 'tutte');
$raw_sim_states = $_POST['sim_states'] ?? $_GET['sim_states'] ?? null;
$selected_states = normalize_sim_states($raw_sim_states ?? $state);
$state = sim_states_key($selected_states);
$has_associated_state_filter = count(array_intersect($selected_states, ['attive', 'disattive'])) > 0;
$msg = '';
$msg_type = '';
$field_errors = [];

if ((($_GET['ajax_numero_info'] ?? $_POST['ajax_numero_info'] ?? '') === '1')) {
    $numero_lookup = trim($_GET['numero'] ?? $_POST['numero'] ?? '');
    $mode_lookup = trim($_GET['mode'] ?? $_POST['mode'] ?? 'edit');
    header('Content-Type: application/json; charset=utf-8');
    if ($mode_lookup === 'create') {
        echo json_encode(get_active_sim_by_numero($conn, $numero_lookup));
    } else {
        echo json_encode(get_numero_info($conn, $numero_lookup));
    }
    exit;
}

if ((($_GET['ajax_sim_info'] ?? $_POST['ajax_sim_info'] ?? '') === '1')) {
    $codice_lookup = trim($_GET['codice'] ?? $_POST['codice'] ?? '');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(get_sim_status_info($conn, $codice_lookup));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';

    if ($post_action === 'create' || $post_action === 'edit') {
        $state = 'disattive';
        $action = $post_action;
        $codice_raw = trim($_POST['codice'] ?? '');
        $tipoSIM_raw = trim($_POST['tipoSIM'] ?? '');
        $eraAssociataA_raw = trim($_POST['eraAssociataA'] ?? '');
        $dataAttivazione_raw = trim($_POST['dataAttivazione'] ?? '');
        $dataDisattivazione_raw = trim($_POST['dataDisattivazione'] ?? '');
        $numero_info = null;
        $sim_info = null;
        $blocked_by_sim_status = false;
        $stored_edit_row = null;

        if ($post_action === 'create') {
            if ($codice_raw === '' && $eraAssociataA_raw === '') {
                $field_errors['codice'] = 'Inserire il codice della SIM oppure il numero di telefono associato.';
                $field_errors['eraAssociataA'] = 'Inserire il codice della SIM oppure il numero di telefono associato.';
            }

            if ($codice_raw !== '') {
                if (!is_digits_or_empty($codice_raw)) {
                    $field_errors['codice'] = 'Il codice SIM può contenere solo cifre.';
                } else {
                    $sim_info = get_sim_status_info($conn, $codice_raw);
                    if (!$sim_info['exists']) {
                        $field_errors['codice'] = $sim_info['message'];
                        $blocked_by_sim_status = true;
                    } elseif ($sim_info['status'] === 'disponibile') {
                        $field_errors['codice'] = 'La SIM indicata è disponibile per un nuovo numero e non può essere disattivata perché non risulta in uso.';
                        $blocked_by_sim_status = true;
                    } elseif ($sim_info['status'] === 'disattiva') {
                        $field_errors['codice'] = 'La SIM indicata è già presente nello storico delle SIM disattivate.';
                        $blocked_by_sim_status = true;
                    } elseif ($sim_info['status'] === 'attiva') {
                        $tipoSIM_raw = $sim_info['tipoSIM'];
                        if ($eraAssociataA_raw === '') {
                            $eraAssociataA_raw = $sim_info['numero'];
                        } elseif (!is_digits_or_empty($eraAssociataA_raw)) {
                            $field_errors['eraAssociataA'] = 'Il numero di telefono può contenere solo cifre.';
                        } elseif ($eraAssociataA_raw !== $sim_info['numero']) {
                            $field_errors['eraAssociataA'] = 'Il numero indicato non corrisponde al numero associato alla SIM in uso.';
                        }
                        $dataAttivazione_raw = $sim_info['dataAttivazione'];
                        $numero_info = $sim_info;
                    }
                }
            }

            if ($codice_raw === '' && $eraAssociataA_raw !== '') {
                if (!is_digits_or_empty($eraAssociataA_raw)) {
                    $field_errors['eraAssociataA'] = 'Il numero di telefono può contenere solo cifre.';
                } else {
                    $active_by_numero = get_active_sim_by_numero($conn, $eraAssociataA_raw);
                    if (!$active_by_numero['exists']) {
                        $field_errors['eraAssociataA'] = $active_by_numero['message'];
                    } elseif (!$active_by_numero['hasActiveSim']) {
                        $field_errors['eraAssociataA'] = 'Il numero indicato è registrato, ma non risulta associato a una SIM in uso da disattivare.';
                    } else {
                        $codice_raw = $active_by_numero['codice'];
                        $tipoSIM_raw = $active_by_numero['tipoSIM'];
                        $dataAttivazione_raw = $active_by_numero['dataAttivazione'];
                        $numero_info = $active_by_numero;
                    }
                }
            }
        } else {
            $old_codice_lookup = $conn->real_escape_string($_POST['old_codice'] ?? '');
            if ($old_codice_lookup !== '') {
                $stored_res = $conn->query("SELECT * FROM SIMDisattiva WHERE codice='$old_codice_lookup' LIMIT 1");
                if ($stored_res && $stored_res->num_rows > 0) {
                    $stored_edit_row = $stored_res->fetch_assoc();
                    $dataAttivazione_raw = $stored_edit_row['dataAttivazione'];
                } else {
                    $field_errors['codice'] = 'La SIM selezionata non è più disponibile nello storico.';
                }
            } else {
                $field_errors['codice'] = 'La SIM selezionata non è valida.';
            }

            if ($eraAssociataA_raw === '') {
                $field_errors['eraAssociataA'] = 'Inserire il numero di telefono precedentemente associato.';
            } elseif (!is_digits_or_empty($eraAssociataA_raw)) {
                $field_errors['eraAssociataA'] = 'Il numero di telefono può contenere solo cifre.';
            } else {
                $numero_info = get_numero_info($conn, $eraAssociataA_raw);
                if (!$numero_info['exists']) {
                    $field_errors['eraAssociataA'] = 'Il numero indicato non risulta presente tra i numeri telefonici registrati.';
                }
            }
        }

        if (!$blocked_by_sim_status) {
            if ($tipoSIM_raw === '') {
                $field_errors['tipoSIM'] = 'Selezionare il formato della SIM.';
            }

            if ($dataAttivazione_raw !== '' && !is_valid_date_value($dataAttivazione_raw)) {
                $field_errors['eraAssociataA'] = 'Non è stato possibile recuperare una data di attivazione valida dal numero indicato.';
            }

            if ($dataDisattivazione_raw === '') {
                $field_errors['dataDisattivazione'] = 'Inserire la data di disattivazione.';
            } elseif (!is_valid_date_value($dataDisattivazione_raw)) {
                $field_errors['dataDisattivazione'] = 'La data di disattivazione non è valida.';
            }
        }

        if (!$blocked_by_sim_status && empty($field_errors) && $numero_info && ($numero_info['exists'] ?? false)) {
            if ($post_action === 'create') {
                $min_disattivazione = $numero_info['dataMinimaDisattivazione'] ?: $dataAttivazione_raw;
            } else {
                $min_disattivazione = $dataAttivazione_raw;
            }
            $max_disattivazione = date('Y-m-d');

            if (strtotime($dataDisattivazione_raw) < strtotime($min_disattivazione)) {
                if ($post_action === 'create' && ($numero_info['ultimaChiamata'] ?? '') !== '') {
                    $field_errors['dataDisattivazione'] = 'La data di disattivazione non può essere precedente all’ultima chiamata registrata per questo numero (' . date('d/m/Y', strtotime($numero_info['ultimaChiamata'])) . ').';
                } else {
                    $field_errors['dataDisattivazione'] = 'La data di disattivazione non può essere precedente alla data di attivazione della SIM.';
                }
            } elseif (strtotime($dataDisattivazione_raw) > strtotime($max_disattivazione)) {
                $field_errors['dataDisattivazione'] = 'La data di disattivazione non può essere successiva alla data odierna.';
            }
        }

        if (!empty($field_errors)) {
            $msg = 'Controllare i campi evidenziati e riprovare.';
            $msg_type = 'error';
            $action = $post_action;
        } else {
            $codice = $conn->real_escape_string($codice_raw);
            $tipoSIM = $conn->real_escape_string($tipoSIM_raw);
            $eraAssociataA = $conn->real_escape_string($eraAssociataA_raw);
            $dataAttivazione = $conn->real_escape_string($dataAttivazione_raw);
            $dataDisattivazione = $conn->real_escape_string($dataDisattivazione_raw);

            if ($post_action === 'create') {
                $insert_sql = "INSERT INTO SIMDisattiva (codice, tipoSIM, eraAssociataA, dataAttivazione, dataDisattivazione)
                               VALUES ('$codice', '$tipoSIM', '$eraAssociataA', '$dataAttivazione', '$dataDisattivazione')";
                $delete_sql = "DELETE FROM SIMAttiva WHERE codice='$codice'";

                $conn->begin_transaction();
                if ($conn->query($insert_sql) && $conn->query($delete_sql)) {
                    $conn->commit();
                    refresh_sim_statistics($conn, $codice, 'disattive');
                    $msg = 'SIM disattivata registrata nello storico correttamente.';
                    $msg_type = 'success';
                    $action = 'list';
                    $state = normalize_sim_state($_POST['return_stato'] ?? 'disattive');
                } else {
                    $conn->rollback();
                    $msg = 'Operazione non riuscita. Controllare i dati inseriti e riprovare.';
                    $msg_type = 'error';
                    $action = 'create';
                }
            } else {
                $old_codice = $conn->real_escape_string($_POST['old_codice'] ?? '');
                $sql = "UPDATE SIMDisattiva SET
                            tipoSIM='$tipoSIM',
                            eraAssociataA='$eraAssociataA',
                            dataAttivazione='$dataAttivazione',
                            dataDisattivazione='$dataDisattivazione'
                        WHERE codice='$old_codice'";
                if ($conn->query($sql)) {
                    if ($old_codice !== $codice) {
                        delete_sim_statistics($conn, $old_codice);
                    }
                    refresh_sim_statistics($conn, $codice, 'disattive');
                    $msg = 'Dati della SIM disattivata aggiornati correttamente.';
                    $msg_type = 'success';
                    $action = 'list';
                    $state = normalize_sim_state($_POST['return_stato'] ?? 'disattive');
                } else {
                    $msg = 'Aggiornamento non riuscito. Controllare i dati inseriti e riprovare.';
                    $msg_type = 'error';
                    $action = 'edit';
                }
            }
        }
    } elseif ($post_action === 'delete') {
        $state = normalize_sim_state($_POST['return_stato'] ?? 'disattive');
        $codice = $conn->real_escape_string($_POST['codice'] ?? '');
        $check_res = $conn->query("SELECT codice FROM SIMDisattiva WHERE codice='$codice'");

        if ($codice === '' || !$check_res || $check_res->num_rows === 0) {
            $msg = 'La SIM selezionata non è più disponibile nello storico.';
            $msg_type = 'error';
        } elseif ($conn->query("DELETE FROM SIMDisattiva WHERE codice='$codice'")) {
            delete_sim_statistics($conn, $codice);
            $msg = 'SIM disattivata rimossa dallo storico correttamente.';
            $msg_type = 'success';
        } else {
            $msg = 'Eliminazione non riuscita. Riprovare più tardi.';
            $msg_type = 'error';
        }
        $action = 'list';
    }
}

$is_filter_request = $_SERVER['REQUEST_METHOD'] !== 'POST' || (($_POST['action'] ?? '') === '');
$search_ordine_sim = trim($is_filter_request ? ($_POST['ordine_sim'] ?? $_GET['ordine_sim'] ?? 'nessuno') : 'nessuno');
/* Compatibilità con le versioni precedenti salvate in sessionStorage. */
if ($search_ordine_sim === '' || $search_ordine_sim === 'operative') {
    $search_ordine_sim = 'nessuno';
}

$selected_states = ['attive', 'disponibili', 'disattive'];
$has_associated_state_filter = true;
if ($action === 'list') {
    $raw_sim_states = $_POST['sim_states'] ?? $_GET['sim_states'] ?? null;
    $state = normalize_sim_state($_POST['stato'] ?? $_GET['stato'] ?? $state);
    $selected_states = normalize_sim_states($raw_sim_states ?? $state);

    /* Gli ordinamenti temporali sono semanticamente legati a un solo stato.
       Il server applica comunque il vincolo, anche se la richiesta arriva senza
       JavaScript o con uno stato salvato in sessione non più coerente. */
    if ($search_ordine_sim === 'attivate_recenti') {
        $selected_states = ['attive'];
    } elseif ($search_ordine_sim === 'disattivate_recenti') {
        $selected_states = ['disattive'];
    }

    $state = sim_states_key($selected_states);
    $has_associated_state_filter = count(array_intersect($selected_states, ['attive', 'disattive'])) > 0;
}

/* Le SIM disponibili non hanno chiamate né date operative associate: in questa
   vista un ordinamento aggiuntivo non avrebbe significato. */
if (!$has_associated_state_filter) {
    $search_ordine_sim = 'nessuno';
}

$search_codice = trim($is_filter_request ? ($_POST['codice'] ?? $_GET['codice'] ?? '') : '');
$search_tipo = trim($is_filter_request ? ($_POST['tipoSIM'] ?? $_GET['tipoSIM'] ?? '') : '');
$search_piano = $has_associated_state_filter
    ? trim($is_filter_request ? ($_POST['piano'] ?? $_GET['piano'] ?? '') : '')
    : '';
$search_numero = $has_associated_state_filter ? trim($is_filter_request ? ($_POST['numero'] ?? $_GET['numero'] ?? '') : '') : '';
$search_data_da = $has_associated_state_filter ? trim($is_filter_request ? ($_POST['data_da'] ?? $_GET['data_da'] ?? '') : '') : '';
$search_data_a = $has_associated_state_filter ? trim($is_filter_request ? ($_POST['data_a'] ?? $_GET['data_a'] ?? '') : '') : '';
$limit = max(8, min(60, (int)($_POST['limit'] ?? $_GET['limit'] ?? 12)));
$offset = max(0, (int)($_POST['offset'] ?? $_GET['offset'] ?? 0));
$ajax_rows = (($_POST['ajax_rows'] ?? $_GET['ajax_rows'] ?? '') === '1');
$skip_count = (($_POST['skip_count'] ?? $_GET['skip_count'] ?? '') === '1');
$export_csv = (($_POST['export_csv'] ?? $_GET['export_csv'] ?? '') === '1');
$lazy_direction = ($_POST['direction'] ?? $_GET['direction'] ?? 'next') === 'prev' ? 'prev' : 'next';

$search_errors = [];
if (!is_digits_or_empty($search_codice)) {
    $search_errors[] = 'Il campo “Codice SIM” può contenere solo cifre. Inserire un codice e riprovare.';
}
if (!is_digits_or_empty($search_numero)) {
    $label_numero = 'Numero di telefono associato o precedente';
    $search_errors[] = 'Il campo “' . $label_numero . '” può contenere solo cifre. Inserire un numero, anche parziale, e riprovare.';
}
if ($search_tipo !== '' && !in_array($search_tipo, ['Nano', 'Micro', 'Standard', 'eSIM'], true)) {
    $search_errors[] = 'Selezionare un formato SIM valido.';
}
if ($search_piano !== '' && !in_array($search_piano, ['consumo', 'ricarica'], true)) {
    $search_errors[] = 'Selezionare un piano associato valido.';
}
if (!is_date_or_empty($search_data_da)) {
    $search_errors[] = 'La data iniziale della SIM non è valida.';
}
if (!is_date_or_empty($search_data_a)) {
    $search_errors[] = 'La data finale della SIM non è valida.';
}
if ($search_data_da !== '' && $search_data_a !== '' && is_date_or_empty($search_data_da) && is_date_or_empty($search_data_a) && $search_data_da > $search_data_a) {
    $search_errors[] = 'La data iniziale della SIM non può essere successiva alla data finale.';
}
if (!in_array($search_ordine_sim, ['nessuno', 'piu_chiamate', 'attivate_recenti', 'disattivate_recenti'], true)) {
    $search_errors[] = 'Selezionare un ordinamento delle SIM valido.';
}

$rows = [];
$has_more = false;
$has_prev = false;
$total_count = 0;
$list_start_offset = $offset;
$query_limit = $limit;

if ($action === 'list' && empty($search_errors)) {
    /* L'ordinamento per attività usa la tabella materializzata StatisticheSIM,
       quando disponibile. In questo modo non vengono ricalcolati milioni di
       record a ogni click. Il fallback mantiene la compatibilità con database
       sui quali lo script di ottimizzazione non è ancora stato importato. */
    $needs_sim_call_count = $search_ordine_sim === 'piu_chiamate';
    $use_sim_statistics = $needs_sim_call_count
        && performance_table_has_columns($conn, 'StatisticheSIM', ['codice', 'stato', 'numeroChiamate']);
    /* Compatibilità con installazioni che non hanno ancora importato la tabella
       StatisticheSIM. Se è disponibile il riepilogo per contratto lo usiamo
       come fallback rapido, evitando centinaia di COUNT correlati sulla tabella
       Telefonata. La tabella specifica per SIM rimane comunque la fonte esatta
       e preferita. */
    $use_contract_statistics = $needs_sim_call_count
        && !$use_sim_statistics
        && performance_table_has_columns($conn, 'StatisticheContratto', ['numero', 'numeroTelefonate']);
    $call_index_name = performance_index_exists($conn, 'Telefonata', 'idx_telefonata_utenza_data')
        ? 'idx_telefonata_utenza_data'
        : (performance_index_exists($conn, 'Telefonata', 'idx_telefonata_utenza') ? 'idx_telefonata_utenza' : '');
    $call_index_hint = $call_index_name !== '' ? " FORCE INDEX (`$call_index_name`)" : '';

    /* BINARY rende il confronto indipendente dalla collation con cui è stata
       creata la tabella di riepilogo. In precedenza una collation differente da
       quella delle tabelle SIM poteva far fallire soltanto l'ordinamento
       "SIM con più chiamate", lasciando correttamente disponibile il totale ma
       restituendo zero righe. */
    $active_statistics_join = $use_sim_statistics
        ? " LEFT JOIN StatisticheSIM ss ON BINARY ss.codice = BINARY s.codice AND ss.stato = 'attive'"
        : ($use_contract_statistics
            ? " LEFT JOIN StatisticheContratto sc ON BINARY sc.numero = BINARY s.associataA"
            : '');
    $disabled_statistics_join = $use_sim_statistics
        ? " LEFT JOIN StatisticheSIM ss ON BINARY ss.codice = BINARY s.codice AND ss.stato = 'disattive'"
        : ($use_contract_statistics
            ? " LEFT JOIN StatisticheContratto sc ON BINARY sc.numero = BINARY s.eraAssociataA"
            : '');

    $active_call_count_sql = !$needs_sim_call_count
        ? "0"
        : ($use_sim_statistics
            ? "COALESCE(ss.numeroChiamate, 0)"
            : ($use_contract_statistics
                ? "COALESCE(sc.numeroTelefonate, 0)"
                : "(SELECT COUNT(*) FROM Telefonata t$call_index_hint WHERE t.effettuataDa = s.associataA AND t.data >= s.dataAttivazione)"));
    $disabled_call_count_sql = !$needs_sim_call_count
        ? "0"
        : ($use_sim_statistics
            ? "COALESCE(ss.numeroChiamate, 0)"
            : ($use_contract_statistics
                ? "COALESCE(sc.numeroTelefonate, 0)"
                : "(SELECT COUNT(*) FROM Telefonata t$call_index_hint WHERE t.effettuataDa = s.eraAssociataA AND t.data BETWEEN s.dataAttivazione AND s.dataDisattivazione)"));

    $sql_union = "
                    SELECT 'attive' AS _sim_state,
                           s.codice,
                           s.tipoSIM,
                           s.associataA,
                           NULL AS eraAssociataA,
                           s.dataAttivazione,
                           NULL AS dataDisattivazione,
                           c.tipo AS tipoContratto,
                           $active_call_count_sql AS numeroChiamate
                    FROM SIMAttiva s
                    JOIN ContrattoTelefonico c ON s.associataA = c.numero
                    $active_statistics_join
                    UNION ALL
                    SELECT 'disponibili' AS _sim_state,
                           n.codice,
                           n.tipoSIM,
                           NULL AS associataA,
                           NULL AS eraAssociataA,
                           NULL AS dataAttivazione,
                           NULL AS dataDisattivazione,
                           NULL AS tipoContratto,
                           0 AS numeroChiamate
                    FROM SIMNonAttiva n
                    UNION ALL
                    SELECT 'disattive' AS _sim_state,
                           s.codice,
                           s.tipoSIM,
                           NULL AS associataA,
                           s.eraAssociataA,
                           s.dataAttivazione,
                           s.dataDisattivazione,
                           c.tipo AS tipoContratto,
                           $disabled_call_count_sql AS numeroChiamate
                    FROM SIMDisattiva s
                    LEFT JOIN ContrattoTelefonico c ON s.eraAssociataA = c.numero
                    $disabled_statistics_join
                ";

    /* La query di conteggio non include i conteggi delle chiamate né alcun
       ordinamento: il totale dei risultati rimane esatto ma viene calcolato
       senza ripetere la parte più costosa della ricerca. */
    $sql_count_union = "
                    SELECT 'attive' AS _sim_state,
                           s.codice, s.tipoSIM, s.associataA,
                           NULL AS eraAssociataA, s.dataAttivazione,
                           NULL AS dataDisattivazione, c.tipo AS tipoContratto
                    FROM SIMAttiva s
                    JOIN ContrattoTelefonico c ON s.associataA = c.numero
                    UNION ALL
                    SELECT 'disponibili' AS _sim_state,
                           n.codice, n.tipoSIM, NULL AS associataA,
                           NULL AS eraAssociataA, NULL AS dataAttivazione,
                           NULL AS dataDisattivazione, NULL AS tipoContratto
                    FROM SIMNonAttiva n
                    UNION ALL
                    SELECT 'disattive' AS _sim_state,
                           s.codice, s.tipoSIM, NULL AS associataA,
                           s.eraAssociataA, s.dataAttivazione,
                           s.dataDisattivazione, c.tipo AS tipoContratto
                    FROM SIMDisattiva s
                    LEFT JOIN ContrattoTelefonico c ON s.eraAssociataA = c.numero
                ";

    $where_filters = " WHERE _sim_state IN (" . sim_states_for_sql($conn, $selected_states) . ")";

    if ($search_codice !== '') {
        $codice_filter = $conn->real_escape_string($search_codice);
        $where_filters .= " AND codice LIKE '%$codice_filter%'";
    }
    if ($search_tipo !== '') {
        $tipo_filter = $conn->real_escape_string($search_tipo);
        $where_filters .= " AND tipoSIM = '$tipo_filter'";
    }
    if ($search_piano !== '') {
        $piano_filter = $conn->real_escape_string($search_piano);
        $where_filters .= " AND tipoContratto = '$piano_filter'";
    }
    if ($search_numero !== '') {
        $numero_filter = $conn->real_escape_string($search_numero);
        $where_filters .= " AND (associataA LIKE '%$numero_filter%' OR eraAssociataA LIKE '%$numero_filter%')";
    }
    if ($search_data_da !== '') {
        $data_da_filter = $conn->real_escape_string($search_data_da);
        $where_filters .= " AND ((_sim_state='attive' AND dataAttivazione >= '$data_da_filter') OR (_sim_state='disattive' AND dataDisattivazione >= '$data_da_filter'))";
    }
    if ($search_data_a !== '') {
        $data_a_filter = $conn->real_escape_string($search_data_a);
        $where_filters .= " AND ((_sim_state='attive' AND dataAttivazione <= '$data_a_filter') OR (_sim_state='disattive' AND dataDisattivazione <= '$data_a_filter'))";
    }

    $sql_list = "SELECT * FROM ($sql_union) AS sim_unificate" . $where_filters;
    $sql_count_list = "SELECT 1 FROM ($sql_count_union) AS sim_unificate" . $where_filters;

    if ($search_ordine_sim === 'piu_chiamate') {
        $sql_list .= " ORDER BY
                        numeroChiamate DESC,
                        CASE _sim_state
                            WHEN 'disponibili' THEN 1
                            WHEN 'attive' THEN 2
                            ELSE 3
                        END,
                        COALESCE(dataDisattivazione, dataAttivazione, '9999-12-31') DESC,
                        codice ASC";
    } elseif ($search_ordine_sim === 'attivate_recenti') {
        $sql_list .= " ORDER BY
                        CASE WHEN _sim_state = 'attive' THEN 0 ELSE 1 END,
                        dataAttivazione DESC,
                        CASE _sim_state
                            WHEN 'disponibili' THEN 1
                            WHEN 'attive' THEN 2
                            ELSE 3
                        END,
                        codice ASC";
    } elseif ($search_ordine_sim === 'disattivate_recenti') {
        $sql_list .= " ORDER BY
                        CASE WHEN _sim_state = 'disattive' THEN 0 ELSE 1 END,
                        dataDisattivazione DESC,
                        CASE _sim_state
                            WHEN 'disponibili' THEN 1
                            WHEN 'attive' THEN 2
                            ELSE 3
                        END,
                        codice ASC";
    } else {
        /* Ordinamento predefinito dell'applicazione: prima le SIM disponibili,
           poi quelle in uso e infine lo storico delle disattivate. */
        $sql_list .= " ORDER BY
                        CASE _sim_state
                            WHEN 'disponibili' THEN 1
                            WHEN 'attive' THEN 2
                            ELSE 3
                        END,
                        COALESCE(dataDisattivazione, dataAttivazione, '9999-12-31') DESC,
                        codice ASC";
    }

    $sql_list_without_limit = $sql_list;
    if (!$skip_count && (!$ajax_rows || $offset === 0)) {
        $total_count = query_total_count($conn, $sql_count_list);
    } else {
        $total_count = null;
    }


    if ($export_csv) {
        $csv_rows = [];
        $export_result = $conn->query($sql_list_without_limit);
        if ($export_result) {
            while ($row = $export_result->fetch_assoc()) {
                $csv_rows[] = sim_csv_row($row, $state);
            }
        }
        output_csv_response('sim_' . $state . '.csv', sim_csv_headers($state), $csv_rows);
    }

    $list_start_offset = $offset;
    $has_prev = $offset > 0;

    $sql_list .= " LIMIT " . ($query_limit + 1) . " OFFSET " . $offset;
    $result_list = $conn->query($sql_list);
    if ($result_list) {
        while ($row = $result_list->fetch_assoc()) {
            $rows[] = $row;
        }
        if (count($rows) > $query_limit) {
            $has_more = true;
            $rows = array_slice($rows, 0, $query_limit);
        }
    }
}

if ($ajax_rows) {
    header('Content-Type: application/json; charset=utf-8');
    $payload = [
        'html' => render_sim_rows($rows, $state),
        'table_html' => render_sim_table_rows($rows, $state),
        'has_more' => $has_more,
        'has_prev' => $has_prev,
        'next_offset' => $offset + count($rows),
        'prev_offset' => $offset
    ];
    if ($total_count !== null) {
        $payload['total_count'] = $total_count;
    }
    echo json_encode($payload);
    exit;
}

$sim_state_order_locked = in_array($search_ordine_sim, ['attivate_recenti', 'disattivate_recenti'], true);
$has_active_date_state = in_array('attive', $selected_states, true);
$has_disabled_date_state = in_array('disattive', $selected_states, true);
if ($has_active_date_state && !$has_disabled_date_state) {
    $sim_date_filter_label = 'Attivata dal/al:';
} elseif ($has_disabled_date_state && !$has_active_date_state) {
    $sim_date_filter_label = 'Disattivata dal/al:';
} elseif ($has_active_date_state && $has_disabled_date_state) {
    $sim_date_filter_label = 'Attivata/disattivata dal/al:';
} else {
    $sim_date_filter_label = 'Periodo dal/al:';
}
?>
<?php include 'includes/header.php'; ?>

<h2>Gestione SIM</h2>

<?php if ($msg): ?>
    <div class="alert alert-<?= htmlspecialchars($msg_type) ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<?php if ($action === 'list'): ?>

    <div class="sticky-data-panel">
    <div class="search-filter">
        <form id="sim-filter" class="compact-filter-form sim-filter-form" method="POST" action="sim.php" data-ajax-form="true" data-live-search="true" data-update-target="#sim-results" data-sim-state-filter="true" data-filter-session-key="sim">
            <div class="form-group sim-code-filter-group">
                <label for="codice">Codice SIM:</label>
                <input type="text" id="codice" name="codice" value="<?= htmlspecialchars($search_codice) ?>" placeholder="Es. 8939" inputmode="numeric" autocomplete="off" data-clearable="true">
            </div>
            <div class="form-group sim-phone-filter-group <?= $has_associated_state_filter ? '' : 'is-filter-unavailable' ?>" data-state-field="attive,disattive">
                <label for="numero_sim">Numero collegato:</label>
                <input type="text" id="numero_sim" name="numero" value="<?= htmlspecialchars($has_associated_state_filter ? $search_numero : '') ?>" placeholder="Es. 340" inputmode="numeric" autocomplete="off" data-clearable="true" data-state-dependent-input <?= $has_associated_state_filter ? '' : 'disabled' ?>>
            </div>
            <div class="form-group sim-state-filter-group">
                <label for="sim-state-button">Stato SIM:</label>
                <input type="hidden" name="stato" value="<?= htmlspecialchars($state) ?>" data-sim-state-hidden>
                <div class="multi-select-filter sim-state-multi-select <?= $sim_state_order_locked ? 'is-order-locked' : '' ?>" data-sim-multi-select>
                    <button type="button" id="sim-state-button" class="custom-select-button multi-select-button" aria-haspopup="listbox" aria-expanded="false" <?= $sim_state_order_locked ? 'disabled aria-disabled="true" title="Lo stato è determinato dal criterio Mostra prima"' : '' ?>>
                        <span class="custom-select-current" data-sim-multi-select-label><?= htmlspecialchars(sim_states_filter_label($selected_states)) ?></span>
                        <span class="custom-select-arrow" aria-hidden="true">⌄</span>
                    </button>
                    <div class="custom-select-menu multi-select-menu" role="listbox" aria-label="Seleziona stati SIM">
                        <label class="custom-select-option multi-select-option multi-select-all-option">
                            <input type="checkbox" value="tutte" data-sim-state-all <?= count($selected_states) === 3 ? 'checked' : '' ?>>
                            <span>Mostra tutte</span>
                        </label>
                        <label class="custom-select-option multi-select-option">
                            <input type="checkbox" name="sim_states[]" value="attive" data-sim-state-checkbox <?= in_array('attive', $selected_states, true) ? 'checked' : '' ?>>
                            <span>SIM in uso</span>
                        </label>
                        <label class="custom-select-option multi-select-option">
                            <input type="checkbox" name="sim_states[]" value="disponibili" data-sim-state-checkbox <?= in_array('disponibili', $selected_states, true) ? 'checked' : '' ?>>
                            <span>SIM disponibili</span>
                        </label>
                        <label class="custom-select-option multi-select-option">
                            <input type="checkbox" name="sim_states[]" value="disattive" data-sim-state-checkbox <?= in_array('disattive', $selected_states, true) ? 'checked' : '' ?>>
                            <span>SIM disattivate</span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="form-group sim-type-filter-group">
                <label for="tipoSIM">Formato SIM:</label>
                <select id="tipoSIM" name="tipoSIM" data-scroll-select="true">
                    <option value="">Mostra tutti</option>
                    <option value="Nano" <?= $search_tipo == 'Nano' ? 'selected' : '' ?>>Nano SIM</option>
                    <option value="Micro" <?= $search_tipo == 'Micro' ? 'selected' : '' ?>>Micro SIM</option>
                    <option value="Standard" <?= $search_tipo == 'Standard' ? 'selected' : '' ?>>Standard SIM</option>
                    <option value="eSIM" <?= $search_tipo == 'eSIM' ? 'selected' : '' ?>>Virtuale eSIM</option>
                </select>
            </div>
            <div class="form-group sim-plan-filter-group <?= $has_associated_state_filter ? '' : 'is-filter-unavailable' ?>" data-state-field="attive,disattive">
                <label for="piano_sim">Piano associato:</label>
                <select id="piano_sim" name="piano" data-scroll-select="true" <?= $has_associated_state_filter ? '' : 'disabled' ?>>
                    <option value="">Mostra tutti</option>
                    <option value="consumo" <?= $search_piano === 'consumo' ? 'selected' : '' ?>>A consumo</option>
                    <option value="ricarica" <?= $search_piano === 'ricarica' ? 'selected' : '' ?>>Ricaricabile</option>
                </select>
            </div>
            <div class="form-group sim-date-filter-group <?= $has_associated_state_filter ? '' : 'is-filter-unavailable' ?>" data-state-field="attive,disattive">
                <label data-sim-date-label><?= htmlspecialchars($sim_date_filter_label) ?></label>
                <div class="range-pair compact-range-pair date-range-pair">
                    <input type="date" id="data_da_sim" name="data_da" value="<?= htmlspecialchars($has_associated_state_filter ? $search_data_da : '') ?>" aria-label="Data dal" data-state-dependent-input <?= $has_associated_state_filter ? '' : 'disabled' ?>>
                    <span class="range-separator" aria-hidden="true">–</span>
                    <input type="date" id="data_a_sim" name="data_a" value="<?= htmlspecialchars($has_associated_state_filter ? $search_data_a : '') ?>" aria-label="Data fino al" data-state-dependent-input <?= $has_associated_state_filter ? '' : 'disabled' ?>>
                </div>
            </div>
            <div class="form-group sim-order-filter-group <?= $has_associated_state_filter ? '' : 'is-filter-unavailable' ?>" data-state-field="attive,disattive">
                <label for="ordine_sim">Mostra prima:</label>
                <select id="ordine_sim" name="ordine_sim" data-scroll-select="true" <?= $has_associated_state_filter ? '' : 'disabled' ?>>
                    <option value="nessuno" <?= $search_ordine_sim === 'nessuno' ? 'selected' : '' ?>>Nessun criterio specifico</option>
                    <option value="piu_chiamate" <?= $search_ordine_sim === 'piu_chiamate' ? 'selected' : '' ?>>SIM con più chiamate</option>
                    <option value="attivate_recenti" <?= $search_ordine_sim === 'attivate_recenti' ? 'selected' : '' ?>>SIM attivate più di recente</option>
                    <option value="disattivate_recenti" <?= $search_ordine_sim === 'disattivate_recenti' ? 'selected' : '' ?>>SIM disattivate più di recente</option>
                </select>
            </div>
            <button type="button" class="btn btn-reset-filters" data-filter-reset="true">Azzera filtri</button>
            <button type="submit" class="btn btn-filter-submit">Cerca SIM</button>
        </form>
    </div>

    <div id="sim-results" class="results-view-root" data-results-view-root="true" data-view-key="sim" data-current-view="cards">
        <div class="sim-toolbar results-actions-row" data-card-modal-exclude="true">
            <div class="sim-toolbar-left">
                <div class="results-navigation" data-results-navigation="true" aria-label="Navigazione risultati">
                    <button type="button" class="results-page-button results-boundary-button" data-results-first="true" aria-label="Vai al primo risultato" title="Vai al primo risultato"><span class="results-boundary-icon results-boundary-icon-up" aria-hidden="true"></span></button>
                    <button type="button" class="results-page-button" data-results-page-prev="true" aria-label="Scorri ai risultati precedenti" title="Risultati precedenti">↑</button>
                    <span class="results-counter" data-results-counter="true">0 risultati</span>
                    <button type="button" class="results-page-button" data-results-page-next="true" aria-label="Scorri ai risultati successivi" title="Risultati successivi">↓</button>
                    <button type="button" class="results-page-button results-boundary-button" data-results-last="true" aria-label="Vai all'ultimo risultato" title="Vai all'ultimo risultato"><span class="results-boundary-icon results-boundary-icon-down" aria-hidden="true"></span></button>
                </div>
                <div class="sim-toolbar-title"><?= htmlspecialchars(sim_states_title($selected_states)) ?></div>
            </div>
            <div class="results-tools sim-results-tools">
                <button type="button" class="btn btn-view-toggle" data-view-toggle="true" aria-label="Cambia visualizzazione risultati">
                    <span class="view-toggle-icon" aria-hidden="true">▤</span>
                    <span data-view-toggle-text>Vista tabellare</span>
                </button>
                <button type="submit" form="sim-filter" name="export_csv" value="1" class="btn btn-export" data-export-submit="true">Esporta in .CSV</button>
                <a href="sim.php?stato=disattive&amp;action=create&amp;return_stato=<?= urlencode($state) ?>" class="btn btn-secondary btn-add-disattiva">+ Disattiva SIM</a>
            </div>
        </div>

        <div class="cards-container results-data-container" data-lazy-container="true" data-lazy-form="#sim-filter" data-next-offset="<?= $list_start_offset + count($rows) ?>" data-prev-offset="<?= $list_start_offset ?>" data-limit="<?= $limit ?>" data-has-more="<?= $has_more ? '1' : '0' ?>" data-has-prev="<?= $has_prev ? '1' : '0' ?>" data-total-count="<?= $total_count ?>">
            <?php if (!empty($search_errors)): ?>
                <div class="alert alert-error"><?= htmlspecialchars(implode(' ', $search_errors)) ?></div>
            <?php elseif (!empty($rows)): ?>
                <div class="view-panel view-panel-cards" data-view-panel="cards">
                    <div class="result-grid sim-card-grid" data-lazy-list="cards">
                        <?= render_sim_rows($rows, $state) ?>
                    </div>
                </div>
                <div class="view-panel view-panel-table" data-view-panel="table">
                    <div class="table-container table-container-inner">
                        <table class="data-table sim-data-table sim-table-state-<?= htmlspecialchars($state) ?>">
                            <thead>
                                <?= render_sim_table_header($state) ?>
                            </thead>
                            <tbody data-lazy-list="table">
                                <?= render_sim_table_rows($rows, $state) ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php else: ?>
                <?php
                $sim_zero_criteria = [];
                if ($search_codice !== '') {
                    $sim_zero_criteria[] = 'codice contenente ' . $search_codice;
                }
                if ($search_numero !== '') {
                    $sim_zero_criteria[] = 'numero collegato contenente ' . $search_numero;
                }
                if (count($selected_states) < 3) {
                    $sim_zero_criteria[] = 'stato: ' . strtolower(sim_states_title($selected_states));
                }
                if ($search_tipo !== '') {
                    $sim_zero_criteria[] = 'formato: ' . $search_tipo;
                }
                if ($search_piano === 'consumo') {
                    $sim_zero_criteria[] = 'piano associato: a consumo';
                } elseif ($search_piano === 'ricarica') {
                    $sim_zero_criteria[] = 'piano associato: ricaricabile';
                }
                if ($search_data_da !== '' || $search_data_a !== '') {
                    $sim_zero_criteria[] = strtolower(rtrim($sim_date_filter_label, ':')) . ': ' . ($search_data_da !== '' ? format_date_it($search_data_da) : 'inizio archivio') . ' - ' . ($search_data_a !== '' ? format_date_it($search_data_a) : 'oggi');
                }
                $sim_zero_message = build_filter_aware_no_results_message(
                    'Nessuna SIM',
                    $sim_zero_criteria,
                    'Non sono presenti SIM consultabili.'
                );
                ?>
                <div class="alert alert-error"><?= htmlspecialchars($sim_zero_message) ?></div>
            <?php endif; ?>
        </div>
    </div>
    </div>

<?php elseif ($action === 'confirm_delete'): ?>

    <?php
    $delete_id = $conn->real_escape_string($_GET['codice'] ?? '');
    $res_delete = $conn->query("SELECT * FROM SIMDisattiva WHERE codice='$delete_id'");
    $delete_row = ($res_delete && $res_delete->num_rows > 0) ? $res_delete->fetch_assoc() : null;
    $delete_return_state = normalize_sim_state($_GET['return_stato'] ?? 'disattive');
    $delete_cancel_url = 'sim.php?stato=' . urlencode($delete_return_state);
    ?>

    <?php if ($delete_row): ?>
        <div class="confirm-box confirm-box-removal">
            <span class="confirm-kicker">Azione importante</span>
            <h3 class="crud-title">Conferma rimozione SIM disattivata</h3>
            <p class="confirm-intro">Questa operazione rimuoverà la SIM dallo storico delle SIM disattivate.</p>

            <div class="confirm-details" aria-label="Dati della SIM da rimuovere">
                <div class="confirm-detail-row">
                    <span class="confirm-detail-label">SIM da rimuovere</span>
                    <strong class="confirm-detail-value"><?= htmlspecialchars($delete_row['codice']) ?></strong>
                </div>
                <div class="confirm-detail-row">
                    <span class="confirm-detail-label">Numero di telefono precedentemente associato</span>
                    <strong class="confirm-detail-value"><?= htmlspecialchars($delete_row['eraAssociataA']) ?></strong>
                </div>
            </div>

            <p class="confirm-note">I numeri telefonici già registrati nel sistema non verranno modificati.</p>

            <form method="POST" action="sim.php?stato=<?= urlencode($delete_return_state) ?>" class="form-actions" data-ajax-content="true" data-update-target=".content">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="return_stato" value="<?= htmlspecialchars($delete_return_state) ?>">
                <input type="hidden" name="codice" value="<?= htmlspecialchars($delete_row['codice']) ?>">
                <button type="submit" class="btn btn-delete">Conferma rimozione</button>
                <a href="<?= htmlspecialchars($delete_cancel_url) ?>" class="btn btn-cancel">Annulla</a>
            </form>
        </div>
    <?php else: ?>
        <div class="alert alert-error">La SIM selezionata non è stata trovata nello storico.</div>
        <p><a href="sim.php?stato=disattive" class="btn btn-cancel">Torna alla gestione SIM</a></p>
    <?php endif; ?>

<?php elseif ($action === 'create' || $action === 'edit'): ?>

    <?php
    $row = [
        'codice' => $_POST['codice'] ?? '',
        'tipoSIM' => $_POST['tipoSIM'] ?? '',
        'eraAssociataA' => $_POST['eraAssociataA'] ?? '',
        'dataAttivazione' => $_POST['dataAttivazione'] ?? '',
        'dataDisattivazione' => $_POST['dataDisattivazione'] ?? ''
    ];
    $is_edit = ($action === 'edit');

    if (!$is_edit && empty($_POST)) {
        $prefill_codice = trim($_GET['codice'] ?? '');
        if ($prefill_codice !== '') {
            $row['codice'] = $prefill_codice;
            if (!ctype_digit($prefill_codice)) {
                $field_errors['codice'] = 'Il codice SIM può contenere solo cifre.';
            } else {
                $prefill_info = get_sim_status_info($conn, $prefill_codice);
                if (($prefill_info['status'] ?? '') === 'attiva') {
                    $row['tipoSIM'] = $prefill_info['tipoSIM'];
                    $row['eraAssociataA'] = $prefill_info['numero'];
                    $row['dataAttivazione'] = $prefill_info['dataAttivazione'];
                } else {
                    $field_errors['codice'] = $prefill_info['message'] ?: 'La SIM indicata non risulta in uso.';
                }
            }
        }
    }

    if ($is_edit && empty($_POST)) {
        $edit_id = $conn->real_escape_string($_GET['codice'] ?? '');
        $res = $conn->query("SELECT * FROM SIMDisattiva WHERE codice='$edit_id'");
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
        } else {
            $msg = "La SIM selezionata non è stata trovata nello storico.";
            $msg_type = "error";
        }
    }

    $numero_info_form = null;
    $data_min_disattivazione = '';
    $data_max_disattivazione = date('Y-m-d');
    if (($row['dataAttivazione'] ?? '') !== '' && is_valid_date_value((string)$row['dataAttivazione'])) {
        $data_min_disattivazione = $row['dataAttivazione'];
    }
    if (!$is_edit && ($row['eraAssociataA'] ?? '') !== '' && ctype_digit((string)$row['eraAssociataA'])) {
        $numero_info_form = get_active_sim_by_numero($conn, (string)$row['eraAssociataA']);
        if ($numero_info_form['exists'] && ($numero_info_form['hasActiveSim'] ?? false)) {
            $row['dataAttivazione'] = $numero_info_form['dataAttivazione'];
            $data_min_disattivazione = $numero_info_form['dataMinimaDisattivazione'] ?: $row['dataAttivazione'];
        }
    }

    $crud_blocked = false;
    $crud_disabled_attr = '';

    $return_state_raw = $_POST['return_stato'] ?? $_GET['return_stato'] ?? ($is_edit ? 'disattive' : '');
    $return_state = in_array($return_state_raw, ['tutte', 'attive', 'disponibili', 'disattive'], true) ? $return_state_raw : '';
    $cancel_url = $return_state !== '' ? 'sim.php?stato=' . urlencode($return_state) : 'sim.php?stato=disattive';
    ?>

    <?php if ($is_edit && $msg && $msg_type === 'error' && empty($row['codice'])): ?>
        <div class="alert alert-error"><?= htmlspecialchars($msg) ?></div>
        <p><a href="sim.php?stato=disattive" class="btn btn-cancel">Torna alla gestione SIM</a></p>
    <?php else: ?>
        <div class="crud-form" id="sim-crud-form" data-page-auto-focus="sim-crud">
            <h3 class="crud-title">
                <?= $is_edit ? 'Aggiorna SIM disattivata nello storico' : 'Registra SIM disattivata nello storico' ?>
            </h3>
            <p class="intro-text">
                <?= $is_edit
                    ? 'Aggiorna i dati storici della SIM disattivata. La modifica serve a correggere formato, numero di telefono associato o date; non riattiva la SIM.'
                    : 'Registra nello storico una SIM non più attiva. L’operazione non modifica i numeri telefonici già presenti nel sistema.' ?>
            </p>
            <p class="required-note"><span class="required-marker" aria-hidden="true">*</span> Campi obbligatori</p>

            <form method="POST" action="sim.php?stato=disattive<?= $is_edit ? '&amp;action=edit&amp;codice=' . urlencode($row['codice']) : '&amp;action=create' ?><?= ($return_state !== '') ? '&amp;return_stato=' . urlencode($return_state) : '' ?>" data-ajax-content="true" data-update-target=".content" data-sim-crud-form="true" data-form-mode="<?= $is_edit ? 'edit' : 'create' ?>" data-crud-blocked="<?= $crud_blocked ? 'true' : 'false' ?>" data-lookup-url="sim.php?ajax_numero_info=1" data-sim-lookup-url="sim.php?ajax_sim_info=1" novalidate>
                <input type="hidden" name="action" value="<?= $is_edit ? 'edit' : 'create' ?>">
                <?php if ($return_state !== ''): ?>
                    <input type="hidden" name="return_stato" value="<?= htmlspecialchars($return_state) ?>">
                <?php endif; ?>
                <?php if ($is_edit): ?>
                    <input type="hidden" name="old_codice" value="<?= htmlspecialchars($row['codice']) ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label for="codice">Codice SIM <span class="required-marker" aria-hidden="true">*</span></label>
                    <input type="text" id="codice" name="codice" value="<?= htmlspecialchars($row['codice']) ?>" <?= $is_edit ? 'readonly class="input-readonly"' : 'required data-clearable="true" data-validation="digits" data-sim-code-lookup="true" data-required-message="Inserire il codice della SIM." data-format-message="Il codice SIM può contenere solo cifre."' ?> placeholder="Inserire il codice SIM" inputmode="numeric" autocomplete="off" aria-describedby="codice-error<?= $is_edit ? ' codice-help' : '' ?>" aria-invalid="<?= !empty($field_errors['codice']) ? 'true' : 'false' ?>">
                    <?php if ($is_edit): ?>
                        <small class="help-text" id="codice-help">Il codice identifica la SIM e non è modificabile.</small>
                    <?php endif; ?>
                    <small class="field-error <?= !empty($field_errors['codice']) ? 'is-visible' : '' ?>" id="codice-error" data-field-error-for="codice" aria-live="polite"><?= field_error($field_errors, 'codice') ?></small>
                </div>

                <div class="form-group">
                    <label for="tipoSIMForm">Formato SIM <span class="required-marker" aria-hidden="true">*</span></label>
                    <select id="tipoSIMForm" name="tipoSIM" required data-crud-dependent="true" <?= $crud_disabled_attr ?> data-required-message="Selezionare il formato della SIM." aria-describedby="tipoSIM-error" aria-invalid="<?= !empty($field_errors['tipoSIM']) ? 'true' : 'false' ?>">
                        <option value="" disabled hidden <?= $row['tipoSIM'] === '' ? 'selected' : '' ?>>Seleziona formato</option>
                        <option value="Nano" <?= $row['tipoSIM'] == 'Nano' ? 'selected' : '' ?>>Nano SIM</option>
                        <option value="Micro" <?= $row['tipoSIM'] == 'Micro' ? 'selected' : '' ?>>Micro SIM</option>
                        <option value="Standard" <?= $row['tipoSIM'] == 'Standard' ? 'selected' : '' ?>>Standard SIM</option>
                        <option value="eSIM" <?= $row['tipoSIM'] == 'eSIM' ? 'selected' : '' ?>>Virtuale eSIM</option>
                    </select>
                    <small class="field-error <?= !empty($field_errors['tipoSIM']) ? 'is-visible' : '' ?>" id="tipoSIM-error" data-field-error-for="tipoSIM" aria-live="polite"><?= field_error($field_errors, 'tipoSIM') ?></small>
                </div>

                <div class="form-group">
                    <label for="eraAssociataA">Numero di telefono precedentemente associato <span class="required-marker" aria-hidden="true">*</span></label>
                    <input type="text" id="eraAssociataA" name="eraAssociataA" value="<?= htmlspecialchars($row['eraAssociataA']) ?>" required <?= $crud_disabled_attr ?> placeholder="Es. 3401234567" inputmode="numeric" autocomplete="off" data-clearable="true" data-crud-dependent="true" data-validation="digits" data-phone-lookup="true" data-required-message="Inserire il numero di telefono precedentemente associato." data-format-message="Il numero di telefono può contenere solo cifre." aria-describedby="eraAssociataA-help eraAssociataA-error" aria-invalid="<?= !empty($field_errors['eraAssociataA']) ? 'true' : 'false' ?>">
                    <small class="help-text" id="eraAssociataA-help">Il numero deve corrispondere a un numero telefonico già registrato.</small>
                    <small class="field-error <?= !empty($field_errors['eraAssociataA']) ? 'is-visible' : '' ?>" id="eraAssociataA-error" data-field-error-for="eraAssociataA" aria-live="polite"><?= field_error($field_errors, 'eraAssociataA') ?></small>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="dataAttivazione">Data di attivazione <span class="required-marker" aria-hidden="true">*</span></label>
                        <input type="date" id="dataAttivazione" name="dataAttivazione" value="<?= htmlspecialchars($row['dataAttivazione']) ?>" readonly <?= $crud_disabled_attr ?> class="input-readonly" data-crud-dependent="true" data-auto-activation-date="true" aria-describedby="dataAttivazione-help">
                        <small class="help-text" id="dataAttivazione-help">La data viene recuperata automaticamente dai dati della SIM.</small>
                    </div>
                    <div class="form-group">
                        <label for="dataDisattivazione">Data di disattivazione <span class="required-marker" aria-hidden="true">*</span></label>
                        <input type="date" id="dataDisattivazione" name="dataDisattivazione" value="<?= htmlspecialchars($row['dataDisattivazione']) ?>" required <?= $crud_disabled_attr ?> min="<?= htmlspecialchars($data_min_disattivazione) ?>" max="<?= htmlspecialchars($data_max_disattivazione) ?>" data-crud-dependent="true" data-deactivation-date="true" data-required-message="Inserire la data di disattivazione." aria-describedby="dataDisattivazione-help dataDisattivazione-error" aria-invalid="<?= !empty($field_errors['dataDisattivazione']) ? 'true' : 'false' ?>">
                        <small class="help-text" id="dataDisattivazione-help">La data deve essere coerente con l’attivazione della SIM<?= $is_edit ? '' : ' e con le chiamate registrate' ?>.</small>
                        <small class="field-error <?= !empty($field_errors['dataDisattivazione']) ? 'is-visible' : '' ?>" id="dataDisattivazione-error" data-field-error-for="dataDisattivazione" aria-live="polite"><?= field_error($field_errors, 'dataDisattivazione') ?></small>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn" data-crud-submit="true" <?= $crud_disabled_attr ?>>Salva SIM disattivata</button>
                    <a href="<?= htmlspecialchars($cancel_url) ?>" class="btn btn-cancel">Annulla operazione</a>
                </div>
            </form>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php include 'includes/footer.php'; ?>

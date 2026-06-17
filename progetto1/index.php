<?php
require_once 'includes/config.php';
require_once 'includes/validation.php';
require_once 'includes/performance.php';

function countRows($conn, $table) {
    $allowed = ['ContrattoTelefonico', 'Telefonata', 'SIMAttiva', 'SIMDisattiva', 'SIMNonAttiva'];
    if (!in_array($table, $allowed, true)) {
        return 0;
    }
    $result = app_db_query($conn, "SELECT COUNT(*) AS totale FROM $table", true, 'conteggio dashboard');
    if ($result && ($row = $result->fetch_assoc())) {
        return (int)$row['totale'];
    }
    return 0;
}

function singleValue($conn, $sql, $field, $default = 0) {
    $result = app_db_query($conn, $sql, true, 'indicatore dashboard');
    if ($result && ($row = $result->fetch_assoc()) && $row[$field] !== null) {
        return $row[$field];
    }
    return $default;
}

function dashboard_phone_status(array $row): string
{
    if (!empty($row['simAttivaCodice'])) {
        return 'Numero attivo';
    }
    if ((int)($row['simDisattivaCount'] ?? 0) > 0) {
        return 'Numero disattivato';
    }
    return 'Nessuna SIM associata';
}

function dashboard_sim_state_label(string $state): string
{
    $labels = [
        'attive' => 'SIM in uso',
        'disponibili' => 'SIM disponibile',
        'disattive' => 'SIM disattivata'
    ];
    return $labels[$state] ?? 'SIM';
}

$total_contratti = countRows($conn, 'ContrattoTelefonico');
$total_sim_attive = countRows($conn, 'SIMAttiva');
$total_sim_disattive = countRows($conn, 'SIMDisattiva');
$total_sim_non_attive = countRows($conn, 'SIMNonAttiva');
$total_sim_gestite = $total_sim_attive + $total_sim_non_attive + $total_sim_disattive;
$contratti_ricarica = singleValue($conn, "SELECT COUNT(*) AS totale FROM ContrattoTelefonico WHERE tipo='ricarica'", 'totale');
$contratti_consumo = singleValue($conn, "SELECT COUNT(*) AS totale FROM ContrattoTelefonico WHERE tipo='consumo'", 'totale');

// I valori globali delle telefonate arrivano dalla tabella di riepilogo.
// Restano esatti, ma non richiedono più una scansione di milioni di righe a ogni apertura.
$call_stats = performance_global_call_stats($conn);
$total_telefonate = $call_stats['totaleTelefonate'];
$durata_media = $call_stats['durataMedia'];
$costo_totale = $call_stats['addebitoTotale'];

$global_query = trim($_GET['ricerca_globale'] ?? '');
$global_error = '';
$global_phone_results = [];
$global_sim_results = [];

if ($global_query !== '') {
    if (!ctype_digit($global_query)) {
        $global_error = 'Inserire esclusivamente cifre di un numero telefonico o di un codice SIM.';
    } else {
        $escaped_query = $conn->real_escape_string($global_query);

        $phone_sql = "SELECT c.numero,
                             c.tipo,
                             c.dataAttivazione,
                             sa.codice AS simAttivaCodice,
                             COALESCE(sdc.simDisattivaCount, 0) AS simDisattivaCount
                      FROM ContrattoTelefonico c
                      LEFT JOIN SIMAttiva sa ON sa.associataA = c.numero
                      LEFT JOIN (
                          SELECT eraAssociataA, COUNT(*) AS simDisattivaCount
                          FROM SIMDisattiva
                          GROUP BY eraAssociataA
                      ) sdc ON sdc.eraAssociataA = c.numero
                      WHERE c.numero LIKE '%$escaped_query%'
                      ORDER BY CASE WHEN c.numero = '$escaped_query' THEN 0 ELSE 1 END,
                               c.numero ASC
                      LIMIT 6";
        $phone_result = app_db_query($conn, $phone_sql, true, 'ricerca rapida numeri');
        if ($phone_result) {
            while ($row = $phone_result->fetch_assoc()) {
                $global_phone_results[] = $row;
            }
        }

        $sim_sql = "SELECT * FROM (
                        SELECT 'attive' AS simState,
                               s.codice,
                               s.tipoSIM,
                               s.associataA AS numeroAssociato,
                               s.dataAttivazione,
                               NULL AS dataDisattivazione
                        FROM SIMAttiva s
                        UNION ALL
                        SELECT 'disponibili' AS simState,
                               s.codice,
                               s.tipoSIM,
                               NULL AS numeroAssociato,
                               NULL AS dataAttivazione,
                               NULL AS dataDisattivazione
                        FROM SIMNonAttiva s
                        UNION ALL
                        SELECT 'disattive' AS simState,
                               s.codice,
                               s.tipoSIM,
                               s.eraAssociataA AS numeroAssociato,
                               s.dataAttivazione,
                               s.dataDisattivazione
                        FROM SIMDisattiva s
                    ) AS simSearch
                    WHERE codice LIKE '%$escaped_query%'
                    ORDER BY CASE WHEN codice = '$escaped_query' THEN 0 ELSE 1 END,
                             CASE simState WHEN 'attive' THEN 1 WHEN 'disponibili' THEN 2 ELSE 3 END,
                             codice ASC
                    LIMIT 8";
        $sim_result = app_db_query($conn, $sim_sql, true, 'ricerca rapida SIM');
        if ($sim_result) {
            while ($row = $sim_result->fetch_assoc()) {
                $global_sim_results[] = $row;
            }
        }
    }
}

$recent_disabled_from = date('Y-m-d', strtotime('-30 days'));
?>
<?php include 'includes/header.php'; ?>

<h2>Panoramica operativa</h2>

<?php if (app_database_error_occurred()): ?>
    <div class="alert alert-error"><?= htmlspecialchars(app_database_error_message()) ?></div>
<?php endif; ?>

<div class="dashboard-search-layout">
    <section class="dashboard-search-panel" aria-labelledby="dashboard-search-title">
        <div class="dashboard-section-heading">
            <div>
                <h3 id="dashboard-search-title">Ricerca rapida</h3>
                <p>Cerca direttamente un numero telefonico o un codice SIM, senza scegliere prima la sezione.</p>
            </div>
        </div>

        <form id="dashboard-search-form" class="dashboard-global-search" method="GET" action="index.php" data-ajax-form="true" data-live-search="true" data-update-target="#dashboard-search-output">
            <div class="form-group">
                <label for="ricerca_globale">Numero telefonico o codice SIM:</label>
                <input type="text" id="ricerca_globale" name="ricerca_globale" value="<?= htmlspecialchars($global_query) ?>" placeholder="Es. 340 oppure 8939" inputmode="numeric" autocomplete="off" data-clearable="true">
            </div>
            <button type="submit" class="btn">Cerca</button>
        </form>

    </section>

    <aside id="dashboard-search-output" class="dashboard-search-output" aria-live="polite" aria-label="Risultati della ricerca rapida">
        <?php if (app_database_error_occurred()): ?>
            <div class="dashboard-search-results">
                <div class="alert alert-error dashboard-search-feedback"><?= htmlspecialchars(app_database_error_message()) ?></div>
            </div>
        <?php elseif ($global_error !== ''): ?>
            <div class="dashboard-search-results">
                <div class="alert alert-error dashboard-search-feedback"><?= htmlspecialchars($global_error) ?></div>
            </div>
        <?php elseif ($global_query !== ''): ?>
            <div class="dashboard-search-results">
                <?php if (!empty($global_phone_results)): ?>
                    <section class="dashboard-result-group" aria-labelledby="phone-results-title">
                        <div class="dashboard-result-group-header">
                            <h4 id="phone-results-title">Numeri telefonici</h4>
                            <span><?= count($global_phone_results) ?> risultat<?= count($global_phone_results) === 1 ? 'o' : 'i' ?></span>
                        </div>
                        <div class="dashboard-result-list">
                            <?php foreach ($global_phone_results as $row): ?>
                                <?php
                                $phone_status = dashboard_phone_status($row);
                                $phone_is_disabled = $phone_status === 'Numero disattivato';
                                ?>
                                <a class="dashboard-result-card" href="contratti.php?numero=<?= urlencode($row['numero']) ?>" data-phone-card-modal="true" data-phone-number="<?= htmlspecialchars($row['numero']) ?>" title="Apri il dettaglio del numero telefonico">
                                    <span class="dashboard-result-main">
                                        <strong><?= htmlspecialchars($row['numero']) ?></strong>
                                        <span><?= strtolower((string)$row['tipo']) === 'consumo' ? 'A consumo' : 'Ricaricabile' ?> · attivato il <?= htmlspecialchars(format_date_it($row['dataAttivazione'])) ?></span>
                                    </span>
                                    <span class="dashboard-result-status <?= $phone_is_disabled ? 'is-disabled' : '' ?>"><?= htmlspecialchars($phone_status) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if (!empty($global_sim_results)): ?>
                    <section class="dashboard-result-group" aria-labelledby="sim-results-title">
                        <div class="dashboard-result-group-header">
                            <h4 id="sim-results-title">SIM</h4>
                            <span><?= count($global_sim_results) ?> risultat<?= count($global_sim_results) === 1 ? 'o' : 'i' ?></span>
                        </div>
                        <div class="dashboard-result-list">
                            <?php foreach ($global_sim_results as $row): ?>
                                <?php
                                $sim_state = (string)$row['simState'];
                                $sim_subtitle = dashboard_sim_state_label($sim_state) . ' · ' . ($row['tipoSIM'] ?: 'Formato non indicato');
                                if (!empty($row['numeroAssociato'])) {
                                    $sim_subtitle .= ' · numero ' . $row['numeroAssociato'];
                                }
                                ?>
                                <a class="dashboard-result-card" href="sim.php?stato=<?= urlencode($sim_state) ?>&amp;codice=<?= urlencode($row['codice']) ?>" data-sim-card-modal="true" data-sim-code="<?= htmlspecialchars($row['codice']) ?>" title="Apri il dettaglio della SIM">
                                    <span class="dashboard-result-main">
                                        <strong><?= htmlspecialchars($row['codice']) ?></strong>
                                        <span><?= htmlspecialchars($sim_subtitle) ?></span>
                                    </span>
                                    <span class="dashboard-result-status sim-state-<?= htmlspecialchars($sim_state) ?>"><?= htmlspecialchars(dashboard_sim_state_label($sim_state)) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if (empty($global_phone_results) && empty($global_sim_results)): ?>
                    <div class="alert alert-error dashboard-search-feedback">Nessun numero telefonico o codice SIM contiene “<?= htmlspecialchars($global_query) ?>”.</div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="dashboard-search-placeholder">
                I risultati o gli eventuali suggerimenti di correzione appariranno qui durante la digitazione.
            </div>
        <?php endif; ?>
    </aside>
</div>

<?php if (!app_database_error_occurred()): ?>
<section class="stat-grid" aria-label="Indicatori principali">
    <article class="stat-card">
        <span class="stat-label">Chiamate registrate</span>
        <strong class="stat-value"><?= htmlspecialchars($total_telefonate) ?></strong>
        <span class="stat-detail">Durata media: <?= htmlspecialchars(format_duration_seconds($durata_media)) ?></span>
    </article>
    <article class="stat-card">
        <span class="stat-label">Numeri telefonici registrati</span>
        <strong class="stat-value"><?= htmlspecialchars($total_contratti) ?></strong>
        <span class="stat-detail"><?= htmlspecialchars($contratti_ricarica) ?> con credito, <?= htmlspecialchars($contratti_consumo) ?> a consumo</span>
    </article>
    <article class="stat-card">
        <span class="stat-label">Addebiti chiamate</span>
        <strong class="stat-value">&euro; <?= htmlspecialchars(number_format((float)$costo_totale, 2, ',', '.')) ?></strong>
        <span class="stat-detail">Totale dei costi registrati</span>
    </article>
    <article class="stat-card stat-card-wide">
        <span class="stat-label">SIM gestite</span>
        <strong class="stat-value"><?= htmlspecialchars($total_sim_gestite) ?></strong>
        <span class="stat-detail stat-detail-sim" aria-label="Dettaglio stato SIM"><?= htmlspecialchars($total_sim_attive) ?> in uso · <?= htmlspecialchars($total_sim_non_attive) ?> disponibili · <?= htmlspecialchars($total_sim_disattive) ?> disattivate</span>
    </article>
</section>
<?php endif; ?>

<section class="dashboard-shortcuts" aria-labelledby="dashboard-shortcuts-title">
    <div class="dashboard-section-heading">
        <div>
            <h3 id="dashboard-shortcuts-title">Ricerche frequenti</h3>
            <p>Apri direttamente alcune consultazioni operative già configurate.</p>
        </div>
    </div>
    <div class="dashboard-shortcut-grid">
        <a class="dashboard-shortcut" href="sim.php?stato=disattive&amp;data_da=<?= urlencode($recent_disabled_from) ?>">
            <strong>SIM disattivate recentemente</strong>
            <span>Mostra le disattivazioni registrate negli ultimi 30 giorni.</span>
        </a>
        <a class="dashboard-shortcut" href="contratti.php?ordine=piu_chiamate">
            <strong>Numeri con più chiamate</strong>
            <span>Ordina i numeri partendo da quelli con maggiore attività.</span>
        </a>
        <a class="dashboard-shortcut" href="telefonate.php?ordine=costo_desc">
            <strong>Chiamate più costose</strong>
            <span>Visualizza prima le telefonate con l’addebito più elevato.</span>
        </a>
        <a class="dashboard-shortcut" href="contratti.php?residuo=esaurito">
            <strong>Piani esauriti</strong>
            <span>Mostra i numeri senza credito residuo o senza minuti disponibili.</span>
        </a>
    </div>
</section>

<section class="action-grid" aria-label="Accessi rapidi">
    <a class="action-card" href="contratti.php">
        <span class="action-card-icon" aria-hidden="true">
            <img src="<?= $base_path ?>/assets/images/icons/numeri-telefonici.svg" alt="Icona numeri telefonici" title="Numeri telefonici" class="quick-card-icon">
        </span>
        <span class="action-card-text">
            <strong>Consulta numeri telefonici</strong>
            <span>Filtra numeri per telefono, piano e periodo di attivazione.</span>
        </span>
    </a>
    <a class="action-card" href="telefonate.php">
        <span class="action-card-icon" aria-hidden="true">
            <img src="<?= $base_path ?>/assets/images/icons/chiamate.svg" alt="Icona chiamate" title="Chiamate" class="quick-card-icon">
        </span>
        <span class="action-card-text">
            <strong>Consulta chiamate</strong>
            <span>Visualizza chiamate, durate e addebiti associati ai numeri telefonici.</span>
        </span>
    </a>
    <a class="action-card" href="sim.php">
        <span class="action-card-icon" aria-hidden="true">
            <img src="<?= $base_path ?>/assets/images/icons/sim.svg" alt="Icona SIM" title="SIM" class="quick-card-icon">
        </span>
        <span class="action-card-text">
            <strong>Gestisci SIM</strong>
            <span>Consulta SIM in uso, disponibili e disattivate in una sola sezione.</span>
        </span>
    </a>
</section>

<?php include 'includes/footer.php'; ?>

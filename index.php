<?php
require_once 'includes/config.php';
require_once 'includes/validation.php';

function countRows($conn, $table) {
    $allowed = ['ContrattoTelefonico', 'Telefonata', 'SIMAttiva', 'SIMDisattiva', 'SIMNonAttiva'];
    if (!in_array($table, $allowed, true)) {
        return 0;
    }
    $result = $conn->query("SELECT COUNT(*) AS totale FROM $table");
    if ($result && ($row = $result->fetch_assoc())) {
        return (int)$row['totale'];
    }
    return 0;
}

function singleValue($conn, $sql, $field, $default = 0) {
    $result = $conn->query($sql);
    if ($result && ($row = $result->fetch_assoc()) && $row[$field] !== null) {
        return $row[$field];
    }
    return $default;
}

$total_contratti = countRows($conn, 'ContrattoTelefonico');
$total_telefonate = countRows($conn, 'Telefonata');
$total_sim_attive = countRows($conn, 'SIMAttiva');
$total_sim_disattive = countRows($conn, 'SIMDisattiva');
$total_sim_non_attive = countRows($conn, 'SIMNonAttiva');
$total_sim_gestite = $total_sim_attive + $total_sim_non_attive + $total_sim_disattive;
$contratti_ricarica = singleValue($conn, "SELECT COUNT(*) AS totale FROM ContrattoTelefonico WHERE tipo='ricarica'", 'totale');
$contratti_consumo = singleValue($conn, "SELECT COUNT(*) AS totale FROM ContrattoTelefonico WHERE tipo='consumo'", 'totale');
$durata_media = singleValue($conn, "SELECT ROUND(AVG(durata), 0) AS media FROM Telefonata", 'media');
$costo_totale = singleValue($conn, "SELECT ROUND(SUM(costo), 2) AS totale FROM Telefonata", 'totale');
?>
<?php include 'includes/header.php'; ?>

<h2>Panoramica operativa</h2>


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
        <div class="sim-summary" aria-label="Dettaglio stato SIM">
            <span><?= htmlspecialchars($total_sim_attive) ?> in uso</span>
            <span><?= htmlspecialchars($total_sim_non_attive) ?> disponibili</span>
            <span><?= htmlspecialchars($total_sim_disattive) ?> disattivate</span>
        </div>
    </article>
</section>

<section class="action-grid" aria-label="Accessi rapidi">
    <a class="action-card" href="contratti.php">
        <strong>Consulta numeri telefonici</strong>
        <span>Filtra numeri per telefono, piano e periodo di attivazione.</span>
    </a>
    <a class="action-card" href="telefonate.php">
        <strong>Consulta chiamate</strong>
        <span>Visualizza chiamate, durate e addebiti associati ai numeri telefonici.</span>
    </a>
    <a class="action-card" href="sim.php">
        <strong>Gestisci SIM</strong>
        <span>Consulta SIM in uso, disponibili e disattivate in una sola sezione.</span>
    </a>
</section>

<?php include 'includes/footer.php'; ?>

<?php
$current_page = basename($_SERVER['PHP_SELF']);

/*
 * Base path calcolato rispetto alla pagina richiesta.
 * In questo modo CSS, JavaScript e immagini vengono caricati correttamente
 * sia se il progetto è nella root del sito, sia se è dentro una sottocartella.
 */
$base_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if ($base_path === '' || $base_path === '.') {
    $base_path = '';
}
$is_sim_page = in_array($current_page, ['sim.php', 'sim_attive.php', 'sim_non_attive.php', 'sim_disattive.php'], true);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Numeri Telefonici</title>
    <link rel="icon" type="image/png" href="<?= $base_path ?>/assets/images/logo.png?v=122">
    <link rel="apple-touch-icon" href="<?= $base_path ?>/assets/images/logo.png?v=122">
    <link rel="stylesheet" href="<?= $base_path ?>/assets/css/style.css?v=175">
    <script src="<?= $base_path ?>/assets/js/main.js?v=150" defer></script>
    <script src="<?= $base_path ?>/assets/js/ajax.js?v=141" defer></script>
</head>
<body>

<header class="main-header">
    <div class="header-content">
        <img src="<?= $base_path ?>/assets/images/logo.png" alt="Logo Gestione Numeri Telefonici, icona smartphone e SIM" title="Logo Gestione Numeri Telefonici" class="header-logo">
        <div class="header-titles">
            <h1>Gestione Numeri Telefonici</h1>
            <p>Consultazione operativa di numeri telefonici, SIM e chiamate</p>
        </div>
    </div>
</header>

<nav class="main-nav" aria-label="Navigazione principale">
    <ul class="main-nav-list">
        <li><a href="<?= $base_path ?>/index.php" class="<?= $current_page == 'index.php' ? 'active' : '' ?>">Panoramica</a></li>
        <li><a href="<?= $base_path ?>/contratti.php" class="<?= $current_page == 'contratti.php' ? 'active' : '' ?>">Numeri telefonici</a></li>
        <li><a href="<?= $base_path ?>/telefonate.php" class="<?= $current_page == 'telefonate.php' ? 'active' : '' ?>">Chiamate</a></li>
        <li><a href="<?= $base_path ?>/sim.php" class="<?= $is_sim_page ? 'active' : '' ?>">SIM</a></li>
    </ul>
</nav>

<main class="content">

<?php
require_once 'includes/config.php';
require_once 'includes/validation.php';

function render_contratti_rows(array $rows): string
{
    ob_start();
    foreach ($rows as $row): ?>
        <tr>
            <td class="identifier"><?= htmlspecialchars($row['numero']) ?></td>
            <td><?= htmlspecialchars(format_date_it($row['dataAttivazione'])) ?></td>
            <td><?= ucfirst(htmlspecialchars($row['tipo'])) ?></td>
            <td class="numeric"><?= htmlspecialchars(format_minutes_remaining($row['minutiResidui'])) ?></td>
            <td class="numeric"><?= htmlspecialchars(format_euro($row['creditoResiduo'])) ?></td>
            <td class="numeric">
                <?php if ((int)$row['num_telefonate'] > 0): ?>
                    <a href="telefonate.php?contratto=<?= urlencode($row['numero']) ?>" title="Visualizza le chiamate di questo numero telefonico">
                        <?= htmlspecialchars($row['num_telefonate']) ?> chiamate
                    </a>
                <?php else: ?>
                    Nessun traffico
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach;
    return ob_get_clean();
}

$search_numero = trim($_POST['numero'] ?? $_GET['numero'] ?? '');
$search_tipo = trim($_POST['tipo'] ?? $_GET['tipo'] ?? '');
$search_data_da = trim($_POST['data_da'] ?? $_GET['data_da'] ?? '');
$search_data_a = trim($_POST['data_a'] ?? $_GET['data_a'] ?? '');
$limit = max(20, min(120, (int)($_POST['limit'] ?? $_GET['limit'] ?? 50)));
$offset = max(0, (int)($_POST['offset'] ?? $_GET['offset'] ?? 0));
$ajax_rows = (($_POST['ajax_rows'] ?? $_GET['ajax_rows'] ?? '') === '1');

$search_errors = [];
if (!is_digits_or_empty($search_numero)) {
    $search_errors[] = 'Il campo “Numero di telefono” può contenere solo cifre. Inserire un numero, anche parziale, e riprovare.';
}

$rows = [];
$has_more = false;

if (empty($search_errors)) {
    $sql = "SELECT c.*, COUNT(t.id) AS num_telefonate
            FROM ContrattoTelefonico c
            LEFT JOIN Telefonata t ON c.numero = t.effettuataDa
            WHERE 1=1";

    if ($search_numero !== '') {
        $numero = $conn->real_escape_string($search_numero);
        $sql .= " AND c.numero LIKE '%$numero%'";
    }
    if ($search_tipo !== '') {
        $tipo = $conn->real_escape_string($search_tipo);
        $sql .= " AND c.tipo = '$tipo'";
    }
    if ($search_data_da !== '') {
        $data_da = $conn->real_escape_string($search_data_da);
        $sql .= " AND c.dataAttivazione >= '$data_da'";
    }
    if ($search_data_a !== '') {
        $data_a = $conn->real_escape_string($search_data_a);
        $sql .= " AND c.dataAttivazione <= '$data_a'";
    }

    $sql .= " GROUP BY c.numero, c.dataAttivazione, c.tipo, c.minutiResidui, c.creditoResiduo
              ORDER BY c.dataAttivazione DESC, c.numero ASC
              LIMIT " . ($limit + 1) . " OFFSET " . $offset;
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
        'html' => render_contratti_rows($rows),
        'has_more' => $has_more,
        'next_offset' => $offset + count($rows)
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
            <label for="numero">Numero di telefono anche parziale:</label>
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

<div class="table-container" id="contratti-results" data-lazy-container="true" data-lazy-form="#contratti-filter" data-next-offset="<?= count($rows) ?>" data-limit="<?= $limit ?>" data-has-more="<?= $has_more ? '1' : '0' ?>">
    <?php if (!empty($search_errors)): ?>
        <div class="alert alert-error"><?= htmlspecialchars(implode(' ', $search_errors)) ?></div>
    <?php elseif (!empty($rows)): ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th><span class="th-icon" aria-hidden="true">📱</span>Numero di telefono</th>
                    <th><span class="th-icon" aria-hidden="true">📅</span>Data attivazione</th>
                    <th><span class="th-icon" aria-hidden="true">🧾</span>Piano</th>
                    <th class="numeric"><span class="th-icon" aria-hidden="true">⏱️</span>Tempo residuo</th>
                    <th class="numeric"><span class="th-icon" aria-hidden="true">💳</span>Credito residuo</th>
                    <th class="numeric"><span class="th-icon" aria-hidden="true">📞</span>Chiamate registrate</th>
                </tr>
            </thead>
            <tbody>
                <?= render_contratti_rows($rows) ?>
            </tbody>
        </table>
    <?php elseif ($search_numero !== ''): ?>
        <div class="alert alert-error">Nessun numero telefonico contiene le cifre “<?= htmlspecialchars($search_numero) ?>”. Controllare il valore digitato oppure modificare gli altri filtri.</div>
    <?php else: ?>
        <div class="alert alert-error">La ricerca non ha prodotto alcun risultato. Modificare i filtri impostati.</div>
    <?php endif; ?>
</div>
<?php if ($has_more): ?>
    <p class="table-end-note">Scorri la tabella per visualizzare altri risultati.</p>
<?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>

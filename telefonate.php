<?php
require_once 'includes/config.php';
require_once 'includes/validation.php';

function render_telefonate_rows(array $rows): string
{
    ob_start();
    foreach ($rows as $row): ?>
        <tr>
            <td><?= htmlspecialchars(format_date_it($row['data'])) ?></td>
            <td><?= htmlspecialchars(format_time_minutes($row['ora'])) ?></td>
            <td class="identifier">
                <a href="contratti.php?numero=<?= urlencode($row['effettuataDa']) ?>" title="Vai al numero telefonico associato">
                    <?= htmlspecialchars($row['effettuataDa']) ?>
                </a>
            </td>
            <td><?= ucfirst(htmlspecialchars($row['tipoContratto'])) ?></td>
            <td class="numeric duration-value"><?= htmlspecialchars(format_duration_seconds($row['durata'])) ?></td>
            <td class="numeric"><?= htmlspecialchars(format_euro($row['costo'])) ?></td>
        </tr>
    <?php endforeach;
    return ob_get_clean();
}

$search_contratto = trim($_POST['contratto'] ?? $_GET['contratto'] ?? '');
$search_data_da = trim($_POST['data_da'] ?? $_GET['data_da'] ?? '');
$search_data_a = trim($_POST['data_a'] ?? $_GET['data_a'] ?? '');
$search_ora_da = trim($_POST['ora_da'] ?? $_GET['ora_da'] ?? '');
$search_ora_a = trim($_POST['ora_a'] ?? $_GET['ora_a'] ?? '');
$search_costo_max = trim($_POST['costo_max'] ?? $_GET['costo_max'] ?? '');
$limit = max(30, min(150, (int)($_POST['limit'] ?? $_GET['limit'] ?? 80)));
$offset = max(0, (int)($_POST['offset'] ?? $_GET['offset'] ?? 0));
$ajax_rows = (($_POST['ajax_rows'] ?? $_GET['ajax_rows'] ?? '') === '1');

$search_errors = [];
if (!is_digits_or_empty($search_contratto)) {
    $search_errors[] = 'Il campo “Numero chiamante” può contenere solo cifre. Inserire un numero, anche parziale, e riprovare.';
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
if (!is_non_negative_decimal_or_empty($search_costo_max)) {
    $search_errors[] = 'Il campo “Addebito massimo” deve contenere un valore numerico positivo o pari a zero.';
}

$rows = [];
$has_more = false;
if (empty($search_errors)) {
    $sql = "SELECT t.*, c.tipo AS tipoContratto
            FROM Telefonata t
            JOIN ContrattoTelefonico c ON t.effettuataDa = c.numero
            WHERE 1=1";

    if ($search_contratto !== '') {
        $contratto = $conn->real_escape_string($search_contratto);
        $sql .= " AND t.effettuataDa LIKE '%$contratto%'";
    }
    if ($search_data_da !== '') {
        $data_da = $conn->real_escape_string($search_data_da);
        $sql .= " AND t.data >= '$data_da'";
    }
    if ($search_data_a !== '') {
        $data_a = $conn->real_escape_string($search_data_a);
        $sql .= " AND t.data <= '$data_a'";
    }
    if ($search_ora_da !== '') {
        $ora_da = $conn->real_escape_string(time_minutes_for_sql($search_ora_da, false));
        $sql .= " AND t.ora >= '$ora_da'";
    }
    if ($search_ora_a !== '') {
        $ora_a = $conn->real_escape_string(time_minutes_for_sql($search_ora_a, true));
        $sql .= " AND t.ora <= '$ora_a'";
    }
    if ($search_costo_max !== '') {
        $costo_max = decimal_for_sql($search_costo_max);
        $sql .= " AND t.costo <= $costo_max";
    }

    $sql .= " ORDER BY t.data DESC, t.ora DESC LIMIT " . ($limit + 1) . " OFFSET " . $offset;
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
        'html' => render_telefonate_rows($rows),
        'has_more' => $has_more,
        'next_offset' => $offset + count($rows)
    ]);
    exit;
}
?>
<?php include 'includes/header.php'; ?>

<h2>Ricerca chiamate effettuate</h2>

<div class="sticky-data-panel">
<div class="search-filter">
    <form id="telefonate-filter" method="POST" action="telefonate.php" data-ajax-form="true" data-live-search="true" data-update-target="#telefonate-results">
        <div class="form-group">
            <label for="contratto">Numero chiamante anche parziale:</label>
            <input type="text" id="contratto" name="contratto" value="<?= htmlspecialchars($search_contratto) ?>" placeholder="Es. 340" inputmode="numeric" autocomplete="off" data-clearable="true">
        </div>
        <div class="form-group">
            <label for="data_da">Dal giorno:</label>
            <input type="date" id="data_da" name="data_da" value="<?= htmlspecialchars($search_data_da) ?>">
        </div>
        <div class="form-group">
            <label for="data_a">Al giorno:</label>
            <input type="date" id="data_a" name="data_a" value="<?= htmlspecialchars($search_data_a) ?>">
        </div>
        <div class="form-group time-filter-group">
            <label for="ora_da">Dalle ore:</label>
            <input type="time" id="ora_da" name="ora_da" value="<?= htmlspecialchars($search_ora_da) ?>">
        </div>
        <div class="form-group time-filter-group">
            <label for="ora_a">Alle ore:</label>
            <input type="time" id="ora_a" name="ora_a" value="<?= htmlspecialchars($search_ora_a) ?>">
        </div>
        <div class="form-group cost-filter-group">
            <label for="costo_max">Addebito massimo in euro:</label>
            <input type="text" id="costo_max" name="costo_max" value="<?= htmlspecialchars($search_costo_max) ?>" placeholder="Es. 1.50" inputmode="decimal" autocomplete="off" data-clearable="true">
        </div>
        <button type="submit" class="btn">Filtra chiamate</button>
    </form>
</div>

<div class="table-container" id="telefonate-results" data-lazy-container="true" data-lazy-form="#telefonate-filter" data-next-offset="<?= count($rows) ?>" data-limit="<?= $limit ?>" data-has-more="<?= $has_more ? '1' : '0' ?>">
    <?php if (!empty($search_errors)): ?>
        <div class="alert alert-error"><?= htmlspecialchars(implode(' ', $search_errors)) ?></div>
    <?php elseif (!empty($rows)): ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th><span class="th-icon" aria-hidden="true">📅</span>Data</th>
                    <th><span class="th-icon" aria-hidden="true">🕒</span>Ora</th>
                    <th><span class="th-icon" aria-hidden="true">📱</span>Numero chiamante</th>
                    <th><span class="th-icon" aria-hidden="true">🧾</span>Piano</th>
                    <th class="numeric"><span class="th-icon" aria-hidden="true">⏱️</span>Durata</th>
                    <th class="numeric"><span class="th-icon" aria-hidden="true">💳</span>Addebito</th>
                </tr>
            </thead>
            <tbody>
                <?= render_telefonate_rows($rows) ?>
            </tbody>
        </table>
    <?php elseif ($search_contratto !== ''): ?>
        <div class="alert alert-error">Nessuna chiamata trovata per numeri di telefono che contengono “<?= htmlspecialchars($search_contratto) ?>”. Controllare il valore digitato oppure modificare gli altri filtri.</div>
    <?php else: ?>
        <div class="alert alert-error">Non sono presenti chiamate per i criteri selezionati.</div>
    <?php endif; ?>
</div>
<?php if ($has_more): ?>
    <p class="table-end-note">Scorri la tabella per visualizzare altre chiamate.</p>
<?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>

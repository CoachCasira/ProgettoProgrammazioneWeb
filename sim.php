<?php
require_once 'includes/config.php';
require_once 'includes/validation.php';

function normalize_sim_state(string $state): string
{
    $allowed = ['attive', 'disponibili', 'disattive'];
    return in_array($state, $allowed, true) ? $state : 'attive';
}

function sim_state_title(string $state): string
{
    if ($state === 'disponibili') {
        return 'SIM disponibili';
    }
    if ($state === 'disattive') {
        return 'SIM disattivate';
    }
    return 'SIM in uso';
}

function render_sim_rows(array $rows, string $state): string
{
    ob_start();
    foreach ($rows as $row): ?>
        <?php if ($state === 'attive'): ?>
            <tr>
                <td class="identifier"><?= htmlspecialchars($row['codice']) ?></td>
                <td><?= htmlspecialchars($row['tipoSIM']) ?></td>
                <td><?= htmlspecialchars($row['dataAttivazione']) ?></td>
                <td class="identifier">
                    <a href="contratti.php?numero=<?= urlencode($row['associataA']) ?>" title="Visualizza il numero telefonico associato">
                        <?= htmlspecialchars($row['associataA']) ?>
                    </a>
                </td>
                <td><?= ucfirst(htmlspecialchars($row['tipoContratto'])) ?></td>
            </tr>
        <?php elseif ($state === 'disponibili'): ?>
            <tr>
                <td class="identifier"><?= htmlspecialchars($row['codice']) ?></td>
                <td><?= htmlspecialchars($row['tipoSIM']) ?></td>
            </tr>
        <?php else: ?>
            <tr>
                <td class="identifier"><?= htmlspecialchars($row['codice']) ?></td>
                <td><?= htmlspecialchars($row['tipoSIM']) ?></td>
                <td class="identifier">
                    <a href="contratti.php?numero=<?= urlencode($row['eraAssociataA']) ?>" title="Visualizza il numero telefonico precedentemente associato">
                        <?= htmlspecialchars($row['eraAssociataA']) ?>
                    </a>
                </td>
                <td><?= $row['tipoContratto'] !== null ? ucfirst(htmlspecialchars($row['tipoContratto'])) : '-' ?></td>
                <td><?= htmlspecialchars($row['dataAttivazione']) ?></td>
                <td><?= htmlspecialchars($row['dataDisattivazione']) ?></td>
                <td class="actions-cell">
                    <a href="sim.php?stato=disattive&amp;action=edit&amp;codice=<?= urlencode($row['codice']) ?>" class="action-edit">Modifica</a>
                    <a href="sim.php?stato=disattive&amp;action=confirm_delete&amp;codice=<?= urlencode($row['codice']) ?>" class="action-delete">Elimina</a>
                </td>
            </tr>
        <?php endif; ?>
    <?php endforeach;
    return ob_get_clean();
}

function render_sim_header(string $state): string
{
    ob_start(); ?>
    <thead>
        <tr>
            <?php if ($state === 'attive'): ?>
                <th><span class="th-icon" aria-hidden="true">🔢</span>Codice SIM</th>
                <th><span class="th-icon" aria-hidden="true">📐</span>Formato SIM</th>
                <th><span class="th-icon" aria-hidden="true">📅</span>Data attivazione</th>
                <th><span class="th-icon" aria-hidden="true">📱</span>Numero associato</th>
                <th><span class="th-icon" aria-hidden="true">🧾</span>Piano</th>
            <?php elseif ($state === 'disponibili'): ?>
                <th><span class="th-icon" aria-hidden="true">🔢</span>Codice SIM</th>
                <th><span class="th-icon" aria-hidden="true">📐</span>Formato SIM</th>
            <?php else: ?>
                <th><span class="th-icon" aria-hidden="true">🔢</span>Codice SIM</th>
                <th><span class="th-icon" aria-hidden="true">📐</span>Formato SIM</th>
                <th><span class="th-icon" aria-hidden="true">📱</span>Numero precedente</th>
                <th><span class="th-icon" aria-hidden="true">🧾</span>Piano</th>
                <th><span class="th-icon" aria-hidden="true">📅</span>Attivata il</th>
                <th><span class="th-icon" aria-hidden="true">🛑</span>Disattivata il</th>
                <th class="text-center"><span class="th-icon" aria-hidden="true">⚙️</span>Azioni</th>
            <?php endif; ?>
        </tr>
    </thead>
    <?php return ob_get_clean();
}

$allowed_actions = ['list', 'create', 'edit', 'confirm_delete'];
$action = $_GET['action'] ?? 'list';
if (!in_array($action, $allowed_actions, true)) {
    $action = 'list';
}

$state = normalize_sim_state($_POST['stato'] ?? $_GET['stato'] ?? 'attive');
$msg = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';

    if ($post_action === 'create' || $post_action === 'edit') {
        $state = 'disattive';
        $codice_raw = trim($_POST['codice'] ?? '');
        $tipoSIM_raw = trim($_POST['tipoSIM'] ?? '');
        $eraAssociataA_raw = trim($_POST['eraAssociataA'] ?? '');
        $dataAttivazione_raw = trim($_POST['dataAttivazione'] ?? '');
        $dataDisattivazione_raw = trim($_POST['dataDisattivazione'] ?? '');

        if ($codice_raw === '' || $tipoSIM_raw === '' || $eraAssociataA_raw === '' || $dataAttivazione_raw === '' || $dataDisattivazione_raw === '') {
            $msg = "Compilare tutti i campi richiesti.";
            $msg_type = "error";
            $action = $post_action;
        } elseif (!is_digits_or_empty($codice_raw)) {
            $msg = "Il campo Codice SIM può contenere solo cifre. Controllare il valore inserito e riprovare.";
            $msg_type = "error";
            $action = $post_action;
        } elseif (!is_digits_or_empty($eraAssociataA_raw)) {
            $msg = "Il campo Numero di telefono precedentemente associato può contenere solo cifre. Controllare il valore inserito e riprovare.";
            $msg_type = "error";
            $action = $post_action;
        } else {
            $codice = $conn->real_escape_string($codice_raw);
            $tipoSIM = $conn->real_escape_string($tipoSIM_raw);
            $eraAssociataA = $conn->real_escape_string($eraAssociataA_raw);
            $dataAttivazione = $conn->real_escape_string($dataAttivazione_raw);
            $dataDisattivazione = $conn->real_escape_string($dataDisattivazione_raw);
            $can_proceed = true;

            if ($post_action === 'create') {
                $check_sql = "
                    SELECT codice FROM SIMAttiva WHERE codice='$codice'
                    UNION
                    SELECT codice FROM SIMNonAttiva WHERE codice='$codice'
                    UNION
                    SELECT codice FROM SIMDisattiva WHERE codice='$codice'
                ";
                $check_res = $conn->query($check_sql);
                if ($check_res && $check_res->num_rows > 0) {
                    $msg = "Il codice SIM inserito è già registrato nel sistema. Ogni SIM può trovarsi in un solo stato: in uso, disponibile o disattivata.";
                    $msg_type = "error";
                    $can_proceed = false;
                    $action = 'create';
                }
            }

            if ($can_proceed) {
                $check_contratto = $conn->query("SELECT numero FROM ContrattoTelefonico WHERE numero='$eraAssociataA'");
                if ($check_contratto && $check_contratto->num_rows === 0) {
                    $msg = "Il numero indicato non risulta presente tra i numeri telefonici registrati. Inserire un numero di telefono esistente.";
                    $msg_type = "error";
                    $can_proceed = false;
                    $action = $post_action;
                }
            }

            if ($can_proceed && strtotime($dataDisattivazione) < strtotime($dataAttivazione)) {
                $msg = "La data di disattivazione non può essere precedente alla data di attivazione.";
                $msg_type = "error";
                $can_proceed = false;
                $action = $post_action;
            }

            if ($can_proceed) {
                if ($post_action === 'create') {
                    $sql = "INSERT INTO SIMDisattiva (codice, tipoSIM, eraAssociataA, dataAttivazione, dataDisattivazione)
                            VALUES ('$codice', '$tipoSIM', '$eraAssociataA', '$dataAttivazione', '$dataDisattivazione')";
                    if ($conn->query($sql)) {
                        $msg = "Nuova SIM disattivata registrata correttamente.";
                        $msg_type = "success";
                        $action = 'list';
                    } else {
                        $msg = "Operazione non riuscita. Controllare i dati inseriti e riprovare.";
                        $msg_type = "error";
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
                        $msg = "Dati della SIM disattivata aggiornati correttamente.";
                        $msg_type = "success";
                        $action = 'list';
                    } else {
                        $msg = "Aggiornamento non riuscito. Controllare i dati inseriti e riprovare.";
                        $msg_type = "error";
                        $action = 'edit';
                    }
                }
            }
        }
    } elseif ($post_action === 'delete') {
        $state = 'disattive';
        $codice = $conn->real_escape_string($_POST['codice'] ?? '');
        $check_res = $conn->query("SELECT codice FROM SIMDisattiva WHERE codice='$codice'");

        if ($codice === '' || !$check_res || $check_res->num_rows === 0) {
            $msg = "La SIM selezionata non è più disponibile nello storico.";
            $msg_type = "error";
        } elseif ($conn->query("DELETE FROM SIMDisattiva WHERE codice='$codice'")) {
            $msg = "SIM disattivata rimossa dallo storico correttamente.";
            $msg_type = "success";
        } else {
            $msg = "Eliminazione non riuscita. Riprovare più tardi.";
            $msg_type = "error";
        }
        $action = 'list';
    }
}

$is_filter_request = $_SERVER['REQUEST_METHOD'] !== 'POST' || (($_POST['action'] ?? '') === '');
$search_codice = trim($is_filter_request ? ($_POST['codice'] ?? $_GET['codice'] ?? '') : '');
$search_tipo = trim($is_filter_request ? ($_POST['tipoSIM'] ?? $_GET['tipoSIM'] ?? '') : '');
$search_numero = trim($is_filter_request ? ($_POST['numero'] ?? $_GET['numero'] ?? '') : '');
$search_data_da = trim($is_filter_request ? ($_POST['data_da'] ?? $_GET['data_da'] ?? '') : '');
$search_data_a = trim($is_filter_request ? ($_POST['data_a'] ?? $_GET['data_a'] ?? '') : '');
$limit = max(20, min(120, (int)($_POST['limit'] ?? $_GET['limit'] ?? 50)));
$offset = max(0, (int)($_POST['offset'] ?? $_GET['offset'] ?? 0));
$ajax_rows = (($_POST['ajax_rows'] ?? $_GET['ajax_rows'] ?? '') === '1');

$search_errors = [];
if (!is_digits_or_empty($search_codice)) {
    $search_errors[] = 'Il campo “Codice SIM” può contenere solo cifre. Inserire un codice, anche parziale, e riprovare.';
}
if (!is_digits_or_empty($search_numero)) {
    $label_numero = $state === 'disattive' ? 'Numero di telefono precedentemente associato' : 'Numero di telefono associato';
    $search_errors[] = 'Il campo “' . $label_numero . '” può contenere solo cifre. Inserire un numero, anche parziale, e riprovare.';
}

$rows = [];
$has_more = false;

if ($action === 'list' && empty($search_errors)) {
    if ($state === 'attive') {
        $sql_list = "SELECT s.*, c.tipo AS tipoContratto
                     FROM SIMAttiva s
                     JOIN ContrattoTelefonico c ON s.associataA = c.numero
                     WHERE 1=1";
        if ($search_codice !== '') {
            $codice_filter = $conn->real_escape_string($search_codice);
            $sql_list .= " AND s.codice LIKE '%$codice_filter%'";
        }
        if ($search_tipo !== '') {
            $tipo_filter = $conn->real_escape_string($search_tipo);
            $sql_list .= " AND s.tipoSIM = '$tipo_filter'";
        }
        if ($search_numero !== '') {
            $numero_filter = $conn->real_escape_string($search_numero);
            $sql_list .= " AND s.associataA LIKE '%$numero_filter%'";
        }
        if ($search_data_da !== '') {
            $data_da_filter = $conn->real_escape_string($search_data_da);
            $sql_list .= " AND s.dataAttivazione >= '$data_da_filter'";
        }
        if ($search_data_a !== '') {
            $data_a_filter = $conn->real_escape_string($search_data_a);
            $sql_list .= " AND s.dataAttivazione <= '$data_a_filter'";
        }
        $sql_list .= " ORDER BY s.dataAttivazione DESC, s.codice ASC";
    } elseif ($state === 'disponibili') {
        $sql_list = "SELECT * FROM SIMNonAttiva WHERE 1=1";
        if ($search_codice !== '') {
            $codice_filter = $conn->real_escape_string($search_codice);
            $sql_list .= " AND codice LIKE '%$codice_filter%'";
        }
        if ($search_tipo !== '') {
            $tipo_filter = $conn->real_escape_string($search_tipo);
            $sql_list .= " AND tipoSIM = '$tipo_filter'";
        }
        $sql_list .= " ORDER BY codice ASC";
    } else {
        $sql_list = "SELECT s.*, c.tipo AS tipoContratto
                     FROM SIMDisattiva s
                     LEFT JOIN ContrattoTelefonico c ON s.eraAssociataA = c.numero
                     WHERE 1=1";
        if ($search_codice !== '') {
            $codice_filter = $conn->real_escape_string($search_codice);
            $sql_list .= " AND s.codice LIKE '%$codice_filter%'";
        }
        if ($search_tipo !== '') {
            $tipo_filter = $conn->real_escape_string($search_tipo);
            $sql_list .= " AND s.tipoSIM = '$tipo_filter'";
        }
        if ($search_numero !== '') {
            $numero_filter = $conn->real_escape_string($search_numero);
            $sql_list .= " AND s.eraAssociataA LIKE '%$numero_filter%'";
        }
        if ($search_data_da !== '') {
            $data_da_filter = $conn->real_escape_string($search_data_da);
            $sql_list .= " AND s.dataDisattivazione >= '$data_da_filter'";
        }
        if ($search_data_a !== '') {
            $data_a_filter = $conn->real_escape_string($search_data_a);
            $sql_list .= " AND s.dataDisattivazione <= '$data_a_filter'";
        }
        $sql_list .= " ORDER BY s.dataDisattivazione DESC, s.codice ASC";
    }

    $sql_list .= " LIMIT " . ($limit + 1) . " OFFSET " . $offset;
    $result_list = $conn->query($sql_list);
    if ($result_list) {
        while ($row = $result_list->fetch_assoc()) {
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
        'html' => render_sim_rows($rows, $state),
        'has_more' => $has_more,
        'next_offset' => $offset + count($rows)
    ]);
    exit;
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
        <form id="sim-filter" method="POST" action="sim.php" data-ajax-form="true" data-live-search="true" data-update-target="#sim-results" data-sim-state-filter="true">
            <input type="hidden" id="sim-stato" name="stato" value="<?= htmlspecialchars($state) ?>">

            <div class="state-tabs" role="group" aria-label="Stato della SIM da visualizzare">
                <button type="button" class="state-tab-button <?= $state === 'attive' ? 'active' : '' ?>" data-sim-state-value="attive" aria-pressed="<?= $state === 'attive' ? 'true' : 'false' ?>">
                    <strong>SIM in uso</strong>
                    <span>Associate a un numero attivo</span>
                </button>
                <button type="button" class="state-tab-button <?= $state === 'disponibili' ? 'active' : '' ?>" data-sim-state-value="disponibili" aria-pressed="<?= $state === 'disponibili' ? 'true' : 'false' ?>">
                    <strong>SIM disponibili</strong>
                    <span>Pronte per un nuovo numero</span>
                </button>
                <button type="button" class="state-tab-button <?= $state === 'disattive' ? 'active' : '' ?>" data-sim-state-value="disattive" aria-pressed="<?= $state === 'disattive' ? 'true' : 'false' ?>">
                    <strong>SIM disattivate</strong>
                    <span>Storico delle SIM non più attive</span>
                </button>
            </div>

            <div class="form-group">
                <label for="codice">Codice SIM anche parziale:</label>
                <input type="text" id="codice" name="codice" value="<?= htmlspecialchars($search_codice) ?>" placeholder="Es. 8939" inputmode="numeric" autocomplete="off">
            </div>
            <div class="form-group">
                <label for="tipoSIM">Formato SIM:</label>
                <select id="tipoSIM" name="tipoSIM">
                    <option value="">Mostra tutti</option>
                    <option value="Nano" <?= $search_tipo == 'Nano' ? 'selected' : '' ?>>Nano SIM</option>
                    <option value="Micro" <?= $search_tipo == 'Micro' ? 'selected' : '' ?>>Micro SIM</option>
                    <option value="Standard" <?= $search_tipo == 'Standard' ? 'selected' : '' ?>>Standard SIM</option>
                    <option value="eSIM" <?= $search_tipo == 'eSIM' ? 'selected' : '' ?>>Virtuale eSIM</option>
                </select>
            </div>
            <div class="form-group <?= $state === 'attive' ? '' : 'is-hidden' ?>" data-state-field="attive">
                <label for="numero_attivo">Numero di telefono associato:</label>
                <input type="text" id="numero_attivo" name="numero" value="<?= htmlspecialchars($state === 'attive' ? $search_numero : '') ?>" placeholder="Es. 340" inputmode="numeric" autocomplete="off" data-state-dependent-input <?= $state === 'attive' ? '' : 'disabled' ?>>
            </div>
            <div class="form-group <?= $state === 'disattive' ? '' : 'is-hidden' ?>" data-state-field="disattive">
                <label for="numero_disattivo">Numero di telefono precedentemente associato:</label>
                <input type="text" id="numero_disattivo" name="numero" value="<?= htmlspecialchars($state === 'disattive' ? $search_numero : '') ?>" placeholder="Es. 340" inputmode="numeric" autocomplete="off" data-state-dependent-input <?= $state === 'disattive' ? '' : 'disabled' ?>>
            </div>
            <div class="form-group <?= $state === 'attive' ? '' : 'is-hidden' ?>" data-state-field="attive">
                <label for="data_da_attiva">Attivata dal:</label>
                <input type="date" id="data_da_attiva" name="data_da" value="<?= htmlspecialchars($state === 'attive' ? $search_data_da : '') ?>" data-state-dependent-input <?= $state === 'attive' ? '' : 'disabled' ?>>
            </div>
            <div class="form-group <?= $state === 'attive' ? '' : 'is-hidden' ?>" data-state-field="attive">
                <label for="data_a_attiva">Attivata fino al:</label>
                <input type="date" id="data_a_attiva" name="data_a" value="<?= htmlspecialchars($state === 'attive' ? $search_data_a : '') ?>" data-state-dependent-input <?= $state === 'attive' ? '' : 'disabled' ?>>
            </div>
            <div class="form-group <?= $state === 'disattive' ? '' : 'is-hidden' ?>" data-state-field="disattive">
                <label for="data_da_disattiva">Disattivata dal:</label>
                <input type="date" id="data_da_disattiva" name="data_da" value="<?= htmlspecialchars($state === 'disattive' ? $search_data_da : '') ?>" data-state-dependent-input <?= $state === 'disattive' ? '' : 'disabled' ?>>
            </div>
            <div class="form-group <?= $state === 'disattive' ? '' : 'is-hidden' ?>" data-state-field="disattive">
                <label for="data_a_disattiva">Disattivata fino al:</label>
                <input type="date" id="data_a_disattiva" name="data_a" value="<?= htmlspecialchars($state === 'disattive' ? $search_data_a : '') ?>" data-state-dependent-input <?= $state === 'disattive' ? '' : 'disabled' ?>>
            </div>
            <button type="submit" class="btn">Cerca SIM</button>
        </form>
    </div>

    <div id="sim-results">
        <div class="sim-toolbar">
            <div class="sim-toolbar-title"><?= htmlspecialchars(sim_state_title($state)) ?></div>
            <?php if ($state === 'disattive'): ?>
                <a href="sim.php?stato=disattive&amp;action=create" class="btn btn-secondary">Aggiungi SIM disattivata allo storico</a>
            <?php endif; ?>
        </div>

        <div class="table-container" data-lazy-container="true" data-lazy-form="#sim-filter" data-next-offset="<?= count($rows) ?>" data-limit="<?= $limit ?>" data-has-more="<?= $has_more ? '1' : '0' ?>">
            <?php if (!empty($search_errors)): ?>
                <div class="alert alert-error"><?= htmlspecialchars(implode(' ', $search_errors)) ?></div>
            <?php elseif (!empty($rows)): ?>
                <table class="data-table">
                    <?= render_sim_header($state) ?>
                    <tbody>
                        <?= render_sim_rows($rows, $state) ?>
                    </tbody>
                </table>
            <?php elseif ($search_numero !== ''): ?>
                <div class="alert alert-error">Nessuna <?= htmlspecialchars(strtolower(sim_state_title($state))) ?> trovata per numeri di telefono che contengono “<?= htmlspecialchars($search_numero) ?>”. Controllare il valore digitato oppure modificare gli altri filtri.</div>
            <?php elseif ($search_codice !== ''): ?>
                <div class="alert alert-error">Nessuna <?= htmlspecialchars(strtolower(sim_state_title($state))) ?> trovata con codici che contengono “<?= htmlspecialchars($search_codice) ?>”. Controllare il valore digitato oppure modificare gli altri filtri.</div>
            <?php else: ?>
                <div class="alert alert-error">Nessuna <?= htmlspecialchars(strtolower(sim_state_title($state))) ?> trovata con i criteri selezionati.</div>
            <?php endif; ?>
        </div>
        <?php if ($has_more): ?>
            <p class="table-end-note">Scorri la tabella per visualizzare altre SIM.</p>
        <?php endif; ?>
    </div>
    </div>

<?php elseif ($action === 'confirm_delete'): ?>

    <?php
    $delete_id = $conn->real_escape_string($_GET['codice'] ?? '');
    $res_delete = $conn->query("SELECT * FROM SIMDisattiva WHERE codice='$delete_id'");
    $delete_row = ($res_delete && $res_delete->num_rows > 0) ? $res_delete->fetch_assoc() : null;
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

            <form method="POST" action="sim.php?stato=disattive" class="form-actions" data-ajax-content="true" data-update-target=".content">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="codice" value="<?= htmlspecialchars($delete_row['codice']) ?>">
                <button type="submit" class="btn btn-delete">Conferma rimozione</button>
                <a href="sim.php?stato=disattive" class="btn btn-cancel">Annulla</a>
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
    ?>

    <?php if ($is_edit && $msg && $msg_type === 'error' && empty($row['codice'])): ?>
        <div class="alert alert-error"><?= htmlspecialchars($msg) ?></div>
        <p><a href="sim.php?stato=disattive" class="btn btn-cancel">Torna alla gestione SIM</a></p>
    <?php else: ?>
        <div class="crud-form">
            <h3 class="crud-title">
                <?= $is_edit ? 'Aggiorna SIM disattivata nello storico' : 'Registra SIM disattivata nello storico' ?>
            </h3>
            <p class="intro-text">Usa questa sezione per conservare nello storico una SIM non più attiva.</p>

            <form method="POST" action="sim.php?stato=disattive<?= $is_edit ? '&amp;action=edit&amp;codice=' . urlencode($row['codice']) : '&amp;action=create' ?>" data-ajax-content="true" data-update-target=".content">
                <input type="hidden" name="action" value="<?= $is_edit ? 'edit' : 'create' ?>">
                <?php if ($is_edit): ?>
                    <input type="hidden" name="old_codice" value="<?= htmlspecialchars($row['codice']) ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label for="codice">Codice SIM:</label>
                    <input type="text" id="codice" name="codice" value="<?= htmlspecialchars($row['codice']) ?>" <?= $is_edit ? 'readonly class="input-readonly"' : 'required' ?> placeholder="Inserire il codice SIM" inputmode="numeric" autocomplete="off">
                    <?php if ($is_edit): ?>
                        <small class="help-text">Il codice identifica la SIM e non è modificabile.</small>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="tipoSIMForm">Formato SIM:</label>
                    <select id="tipoSIMForm" name="tipoSIM" required>
                        <option value="">Seleziona formato</option>
                        <option value="Nano" <?= $row['tipoSIM'] == 'Nano' ? 'selected' : '' ?>>Nano SIM</option>
                        <option value="Micro" <?= $row['tipoSIM'] == 'Micro' ? 'selected' : '' ?>>Micro SIM</option>
                        <option value="Standard" <?= $row['tipoSIM'] == 'Standard' ? 'selected' : '' ?>>Standard SIM</option>
                        <option value="eSIM" <?= $row['tipoSIM'] == 'eSIM' ? 'selected' : '' ?>>Virtuale eSIM</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="eraAssociataA">Numero di telefono precedentemente associato:</label>
                    <input type="text" id="eraAssociataA" name="eraAssociataA" value="<?= htmlspecialchars($row['eraAssociataA']) ?>" required placeholder="Es. 3401234567" inputmode="numeric" autocomplete="off">
                    <small class="help-text">Il numero deve corrispondere a un numero telefonico già registrato.</small>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="dataAttivazione">Data di attivazione:</label>
                        <input type="date" id="dataAttivazione" name="dataAttivazione" value="<?= htmlspecialchars($row['dataAttivazione']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="dataDisattivazione">Data di disattivazione:</label>
                        <input type="date" id="dataDisattivazione" name="dataDisattivazione" value="<?= htmlspecialchars($row['dataDisattivazione']) ?>" required>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn">Salva SIM disattivata</button>
                    <a href="sim.php?stato=disattive" class="btn btn-cancel">Annulla operazione</a>
                </div>
            </form>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php include 'includes/footer.php'; ?>

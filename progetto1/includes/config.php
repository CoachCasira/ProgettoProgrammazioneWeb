<?php
$servername = "localhost";
$username = "";
$password = "";
$dbname = "";

/*
 * Le credenziali reali del database non sono versionate nel repository.
 * Nel deploy su Altervista vengono valorizzate tramite la configurazione
 * protetta dell'ambiente, evitando di pubblicare dati di accesso sensibili.
 */

/*
 * Disattivo la visualizzazione automatica degli errori mysqli: in questo modo,
 * se la connessione non fosse disponibile, l'utente vede solo un messaggio pulito
 * e non warning/notice tecnici del server. I dettagli vengono registrati nel log
 * del server e non sono mai mostrati nella pagina.
 */
mysqli_report(MYSQLI_REPORT_OFF);

/**
 * Registra un errore del database senza inserire query o dati dell'utente nel log.
 */
function app_log_database_error(mysqli $conn, string $context = 'query database'): void
{
    $caller = [];
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6) as $frame) {
        if (!empty($frame['file']) && realpath((string)$frame['file']) !== __FILE__) {
            $caller = $frame;
            break;
        }
    }
    $file = isset($caller['file']) ? basename((string)$caller['file']) : 'sconosciuto';
    $line = isset($caller['line']) ? (int)$caller['line'] : 0;
    $safe_context = preg_replace('/[^A-Za-z0-9 _.-]/', '', $context) ?: 'query database';

    $error_number = $conn->connect_errno ?: $conn->errno;
    $error_message = $conn->connect_error ?: $conn->error;

    error_log(sprintf(
        '[ProgWeb][DB][%s:%d][%s] Errore %d: %s',
        $file,
        $line,
        $safe_context,
        $error_number,
        $error_message
    ));
}

/**
 * Esegue una query mantenendo separati il messaggio destinato all'utente e il
 * dettaglio tecnico registrato nel log. Le query non critiche sono usate solo
 * per ottimizzazioni o controlli facoltativi e non bloccano la pagina.
 *
 * @return mysqli_result|bool
 */
function app_db_query(mysqli $conn, string $sql, bool $critical = true, string $context = 'query database')
{
    $result = $conn->query($sql);
    if ($result === false) {
        app_log_database_error($conn, $context);
        if ($critical) {
            $GLOBALS['app_database_error_occurred'] = true;
        }
    }

    return $result;
}



/**
 * Prepara una query parametrica e registra eventuali errori soltanto nel log.
 *
 * @return mysqli_stmt|false
 */
function app_db_prepare(mysqli $conn, string $sql, string $context = 'preparazione query')
{
    $statement = $conn->prepare($sql);
    if ($statement === false) {
        app_log_database_error($conn, $context);
        $GLOBALS['app_database_error_occurred'] = true;
    }

    return $statement;
}

function app_db_execute(mysqli_stmt $statement, string $context = 'esecuzione query'): bool
{
    $executed = $statement->execute();
    if (!$executed) {
        $caller = [];
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6) as $frame) {
            if (!empty($frame['file']) && realpath((string)$frame['file']) !== __FILE__) {
                $caller = $frame;
                break;
            }
        }
        $file = isset($caller['file']) ? basename((string)$caller['file']) : 'sconosciuto';
        $line = isset($caller['line']) ? (int)$caller['line'] : 0;
        $safe_context = preg_replace('/[^A-Za-z0-9 _.-]/', '', $context) ?: 'esecuzione query';
        error_log(sprintf(
            '[ProgWeb][DB][%s:%d][%s] Errore statement %d: %s',
            $file,
            $line,
            $safe_context,
            $statement->errno,
            $statement->error
        ));
        $GLOBALS['app_database_error_occurred'] = true;
    }

    return $executed;
}

function app_database_error_occurred(): bool
{
    return !empty($GLOBALS['app_database_error_occurred']);
}

function app_database_error_message(): string
{
    return 'I dati sono momentaneamente non disponibili. Riprovare tra qualche istante.';
}

function app_abort_database_request(): void
{
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit(app_database_error_message());
}

$GLOBALS['app_database_error_occurred'] = false;
$conn = @new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_errno) {
    app_log_database_error($conn, 'connessione al database');
    http_response_code(503);
    exit("<div class='system-error'>Connessione al sistema momentaneamente non disponibile. Riprovare più tardi.</div>");
}

if (!$conn->set_charset("utf8mb4")) {
    app_log_database_error($conn, 'impostazione codifica utf8mb4');
    http_response_code(503);
    exit("<div class='system-error'>Configurazione del sistema momentaneamente non disponibile. Riprovare più tardi.</div>");
}
?>

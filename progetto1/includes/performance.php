<?php
/**
 * Funzioni comuni per lavorare in modo efficiente con archivi molto grandi.
 * Le tabelle di riepilogo sono già presenti nel database Altervista: sono state
 * create una sola volta importando manualmente gli script SQL tramite phpMyAdmin.
 * L'applicazione non crea né modifica lo schema del database a runtime.
 */

function performance_table_exists(mysqli $conn, string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }

    $escaped = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '$escaped'");
    $cache[$table] = $result instanceof mysqli_result && $result->num_rows > 0;

    return $cache[$table];
}

/**
 * Verifica che una tabella di supporto contenga tutte le colonne richieste.
 * Il controllo evita di usare tabelle di riepilogo create con versioni
 * precedenti o importazioni incomplete, che altrimenti farebbero fallire la
 * query principale e mostrerebbero un elenco vuoto.
 */
function performance_table_has_columns(mysqli $conn, string $table, array $columns): bool
{
    static $cache = [];

    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }

    $normalized_columns = array_values(array_unique(array_filter(array_map(
        static function ($column) {
            $column = (string)$column;
            return preg_match('/^[A-Za-z0-9_]+$/', $column) ? $column : null;
        },
        $columns
    ))));

    if (empty($normalized_columns) || !performance_table_exists($conn, $table)) {
        return false;
    }

    sort($normalized_columns);
    $cache_key = $table . ':' . implode(',', $normalized_columns);
    if (array_key_exists($cache_key, $cache)) {
        return $cache[$cache_key];
    }

    $result = $conn->query("SHOW COLUMNS FROM `$table`");
    if (!$result) {
        $cache[$cache_key] = false;
        return false;
    }

    $available = [];
    while ($row = $result->fetch_assoc()) {
        if (isset($row['Field'])) {
            $available[(string)$row['Field']] = true;
        }
    }

    foreach ($normalized_columns as $column) {
        if (!isset($available[$column])) {
            $cache[$cache_key] = false;
            return false;
        }
    }

    $cache[$cache_key] = true;
    return true;
}


function performance_index_exists(mysqli $conn, string $table, string $index): bool
{
    static $cache = [];
    $cache_key = $table . ':' . $index;

    if (array_key_exists($cache_key, $cache)) {
        return $cache[$cache_key];
    }

    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $index)) {
        return false;
    }

    $safe_index = $conn->real_escape_string($index);
    $result = $conn->query("SHOW INDEX FROM `$table` WHERE Key_name = '$safe_index'");
    $cache[$cache_key] = $result instanceof mysqli_result && $result->num_rows > 0;

    return $cache[$cache_key];
}

function performance_contract_stats_join(mysqli $conn, string $alias = 'tf'): string
{
    if (performance_table_exists($conn, 'StatisticheContratto')) {
        return "LEFT JOIN StatisticheContratto $alias ON $alias.numero = c.numero";
    }

    // Compatibilità di emergenza prima dell'importazione dello script SQL.
    // Con milioni di telefonate questa variante è più lenta e va usata solo come fallback.
    return "LEFT JOIN (
                SELECT effettuataDa AS numero,
                       COUNT(*) AS numeroTelefonate,
                       COALESCE(SUM(durata), 0) AS durataTotale,
                       COALESCE(SUM(costo), 0) AS addebitoTotale
                FROM Telefonata
                GROUP BY effettuataDa
            ) $alias ON $alias.numero = c.numero";
}

function performance_global_call_stats(mysqli $conn): array
{
    $defaults = [
        'totaleTelefonate' => 0,
        'durataTotale' => 0,
        'durataMedia' => 0,
        'addebitoTotale' => 0.0
    ];

    if (performance_table_exists($conn, 'StatisticheTelefonate')) {
        $result = $conn->query("SELECT totaleTelefonate, durataTotale, durataMedia, addebitoTotale
                                FROM StatisticheTelefonate
                                WHERE id = 1
                                LIMIT 1");
        if ($result && ($row = $result->fetch_assoc())) {
            return [
                'totaleTelefonate' => (int)($row['totaleTelefonate'] ?? 0),
                'durataTotale' => (int)($row['durataTotale'] ?? 0),
                'durataMedia' => (int)round((float)($row['durataMedia'] ?? 0)),
                'addebitoTotale' => (float)($row['addebitoTotale'] ?? 0)
            ];
        }
    }

    // Fallback per non interrompere il sito se lo script SQL non è ancora stato importato.
    $result = $conn->query("SELECT COUNT(*) AS totaleTelefonate,
                                   COALESCE(SUM(durata), 0) AS durataTotale,
                                   COALESCE(AVG(durata), 0) AS durataMedia,
                                   COALESCE(SUM(costo), 0) AS addebitoTotale
                            FROM Telefonata");
    if (!$result || !($row = $result->fetch_assoc())) {
        return $defaults;
    }

    return [
        'totaleTelefonate' => (int)($row['totaleTelefonate'] ?? 0),
        'durataTotale' => (int)($row['durataTotale'] ?? 0),
        'durataMedia' => (int)round((float)($row['durataMedia'] ?? 0)),
        'addebitoTotale' => (float)($row['addebitoTotale'] ?? 0)
    ];
}

function performance_global_call_count(mysqli $conn): ?int
{
    if (!performance_table_exists($conn, 'StatisticheTelefonate')) {
        return null;
    }

    $result = $conn->query("SELECT totaleTelefonate FROM StatisticheTelefonate WHERE id = 1 LIMIT 1");
    if (!$result || !($row = $result->fetch_assoc())) {
        return null;
    }

    return (int)$row['totaleTelefonate'];
}

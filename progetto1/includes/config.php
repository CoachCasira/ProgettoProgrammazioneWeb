<?php
$servername = "localhost";
$username = "";
$password = "";
$dbname = "";

/*
 * Disattivo la visualizzazione automatica degli errori mysqli: in questo modo,
 * se la connessione non fosse disponibile, l'utente vede solo un messaggio pulito
 * e non warning/notice tecnici del server.
 */
mysqli_report(MYSQLI_REPORT_OFF);

$conn = @new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_errno) {
    http_response_code(503);
    exit("<div class='system-error'>Connessione al sistema momentaneamente non disponibile. Riprovare più tardi.</div>");
}

if (!$conn->set_charset("utf8mb4")) {
    http_response_code(503);
    exit("<div class='system-error'>Configurazione del sistema momentaneamente non disponibile. Riprovare più tardi.</div>");
}
?>

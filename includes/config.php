<?php
$servername = "";
$username = "";
$password = "";
$dbname = "";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("<div class='system-error'>Connessione al sistema momentaneamente non disponibile. Riprovare più tardi.</div>");
}
$conn->set_charset("utf8mb4");
?>

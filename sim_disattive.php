<?php
$query = $_SERVER['QUERY_STRING'] ?? '';
$separator = $query === '' ? '?' : '?' . $query . '&';
header('Location: sim.php' . $separator . 'stato=disattive');
exit;

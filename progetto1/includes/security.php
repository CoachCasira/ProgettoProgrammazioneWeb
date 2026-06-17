<?php
/**
 * Protezione CSRF limitata alle operazioni che modificano lo storico delle SIM.
 * I filtri di sola consultazione non richiedono token perché non alterano dati.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $is_https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/*
 * Copio il token e rilascio subito il lock della sessione. In questo modo le
 * richieste AJAX dei filtri SIM possono continuare a essere eseguite in
 * parallelo senza attendere la chiusura della richiesta precedente.
 */
$GLOBALS['app_csrf_token'] = $_SESSION['csrf_token'];
session_write_close();

function csrf_token(): string
{
    return (string)($GLOBALS['app_csrf_token'] ?? '');
}

function csrf_token_is_valid(?string $token): bool
{
    $expected = csrf_token();
    return is_string($token)
        && $token !== ''
        && $expected !== ''
        && hash_equals($expected, $token);
}

function csrf_token_field(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8')
        . '">';
}
?>

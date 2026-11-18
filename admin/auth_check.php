<?php
declare(strict_types=1);

/**
 * Verificación de sesión administrativa.
 *
 * Se incluye al inicio de cada página protegida del panel. Valida:
 *   - Que exista una sesión administrativa.
 *   - Que no haya superado el tiempo máximo de inactividad.
 *   - Que exista un token CSRF para los formularios de la página.
 */

require_once __DIR__ . '/../../config/security.php';

electric_session_start();

// Sin datos de administrador: denegar acceso.
if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_usuario'])) {
    header('Location: login.php');
    exit;
}

// Expiración por inactividad.
$ahora  = time();
$ultima = isset($_SESSION['last_activity']) ? (int) $_SESSION['last_activity'] : 0;

if ($ultima <= 0 || ($ahora - $ultima) > ELECTRIC_SESSION_IDLE_TIMEOUT) {
    electric_destroy_session();
    header('Location: login.php?expirado=1');
    exit;
}

// Renovar la marca de actividad en cada página protegida.
$_SESSION['last_activity'] = $ahora;

// Garantizar token CSRF para los formularios de la página.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

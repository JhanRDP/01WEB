<?php
declare(strict_types=1);

/**
 * Cierre de sesión del administrador.
 *
 * Requiere POST y un token CSRF válido. Las peticiones GET no cierran la
 * sesión (evita logout CSRF por enlace o imagen).
 */

require_once __DIR__ . '/../../config/security.php';

electric_session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // No cerrar sesión por GET: devolver al panel o al login.
    $destino = !empty($_SESSION['admin_id']) ? 'index.php' : 'login.php';
    header('Location: ' . $destino);
    exit;
}

$token = (string) ($_POST['csrf_token'] ?? '');

if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Solicitud inválida.');
}

electric_destroy_session();

header('Location: login.php?logout=1');
exit;

<?php
declare(strict_types=1);

/**
 * Cambia el estado (publicado/oculto) de un producto.
 * Solo POST con CSRF válido.
 */

require __DIR__ . '/auth_check.php';
require __DIR__ . '/../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: productos.php');
    exit;
}

$token = (string) ($_POST['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'], $token)) {
    $_SESSION['__flash_err'] = 'Solicitud inválida.';
    header('Location: productos.php');
    exit;
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$estado = filter_input(INPUT_POST, 'estado', FILTER_VALIDATE_INT);

if ($id === false || $id <= 0 || !in_array($estado, [0, 1], true)) {
    $_SESSION['__flash_err'] = 'Datos inválidos.';
    header('Location: productos.php');
    exit;
}

try {
    $chk = $pdo->prepare('SELECT id, nombre FROM productos WHERE id = ? LIMIT 1');
    $chk->execute([$id]);
    $prod = $chk->fetch();

    if (!$prod) {
        $_SESSION['__flash_err'] = 'El producto no existe.';
        header('Location: productos.php');
        exit;
    }

    $upd = $pdo->prepare('UPDATE productos SET estado = ?, updated_at = current_timestamp() WHERE id = ?');
    $upd->execute([$estado, $id]);

    $texto = $estado === 1 ? 'publicado' : 'ocultado';
    $_SESSION['__flash_ok'] = 'Producto "' . $prod['nombre'] . '" ' . $texto . '.';
} catch (Throwable $e) {
    error_log('electric_intranet: error cambiando estado de producto — ' . $e->getMessage());
    $_SESSION['__flash_err'] = 'Error interno al cambiar el estado del producto.';
}

header('Location: productos.php');
exit;

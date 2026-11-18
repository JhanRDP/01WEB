<?php
declare(strict_types=1);

/**
 * Cambia el estado (activa/oculta) de una categoría.
 * Solo POST con CSRF válido. Nunca mediante GET.
 */

require __DIR__ . '/auth_check.php';
require __DIR__ . '/../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: categorias.php');
    exit;
}

$token = (string) ($_POST['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'], $token)) {
    $_SESSION['__flash_err'] = 'Solicitud inválida.';
    header('Location: categorias.php');
    exit;
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$estado = filter_input(INPUT_POST, 'estado', FILTER_VALIDATE_INT);

if ($id === false || $id <= 0 || !in_array($estado, [0, 1], true)) {
    $_SESSION['__flash_err'] = 'Datos inválidos.';
    header('Location: categorias.php');
    exit;
}

try {
    $chk = $pdo->prepare('SELECT id, nombre FROM categorias WHERE id = ? LIMIT 1');
    $chk->execute([$id]);
    $cat = $chk->fetch();

    if (!$cat) {
        $_SESSION['__flash_err'] = 'La categoría no existe.';
        header('Location: categorias.php');
        exit;
    }

    $tieneProductos = false;
    if ($estado === 0) {
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM productos WHERE categoria_id = ?');
        $cnt->execute([$id]);
        $tieneProductos = (int) $cnt->fetchColumn() > 0;
    }

    $upd = $pdo->prepare('UPDATE categorias SET estado = ?, updated_at = current_timestamp() WHERE id = ?');
    $upd->execute([$estado, $id]);

    $texto = $estado === 1 ? 'publicada' : 'ocultada';
    $_SESSION['__flash_ok'] = 'Categoría "' . $cat['nombre'] . '" ' . $texto . '.';
    if ($tieneProductos) {
        $_SESSION['__flash_ok'] .= ' Tiene productos asociados (estos conservan la categoría).';
    }
} catch (Throwable $e) {
    error_log('electric_intranet: error cambiando estado de categoría — ' . $e->getMessage());
    $_SESSION['__flash_err'] = 'Error interno al cambiar el estado de la categoría.';
}

header('Location: categorias.php');
exit;

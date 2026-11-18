<?php
declare(strict_types=1);

/**
 * Listado de categorías (solo administrador autenticado).
 */

require __DIR__ . '/auth_check.php';
require __DIR__ . '/../../config/db.php';

$mensaje = '';
$tipo = '';

// Mensajes flash desde acciones (estado)
if (!empty($_SESSION['__flash_ok'])) {
    $mensaje = $_SESSION['__flash_ok'];
    $tipo = 'ok';
    unset($_SESSION['__flash_ok']);
} elseif (!empty($_SESSION['__flash_err'])) {
    $mensaje = $_SESSION['__flash_err'];
    $tipo = 'err';
    unset($_SESSION['__flash_err']);
}

$busqueda = trim((string) ($_GET['q'] ?? ''));

// Construir consulta con filtro de búsqueda
$sql = 'SELECT id, nombre, estado, created_at
        FROM categorias';
$params = [];
if ($busqueda !== '') {
    $sql .= ' WHERE nombre LIKE ?';
    $params[] = '%' . $busqueda . '%';
}
$sql .= ' ORDER BY estado DESC, nombre ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$categorias = $stmt->fetchAll();

// Procesar cambio de estado (POST + CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $mensaje = 'Solicitud inválida.';
        $tipo = 'err';
    } else {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $nuevoEstado = filter_input(INPUT_POST, 'estado', FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0 || !in_array($nuevoEstado, [0, 1], true)) {
            $mensaje = 'Datos inválidos.';
            $tipo = 'err';
        } else {
            // Verificar categoría existente
            $chk = $pdo->prepare('SELECT id, estado, nombre FROM categorias WHERE id = ? LIMIT 1');
            $chk->execute([$id]);
            $cat = $chk->fetch();
            if (!$cat) {
                $mensaje = 'La categoría no existe.';
                $tipo = 'err';
            } else {
                // Si se oculta, permitir (no elimina). Advertencia si tiene productos.
                if ($nuevoEstado === 0) {
                    $cnt = $pdo->prepare('SELECT COUNT(*) FROM productos WHERE categoria_id = ?');
                    $cnt->execute([$id]);
                    $tieneProductos = (int) $cnt->fetchColumn() > 0;
                } else {
                    $tieneProductos = false;
                }

                $upd = $pdo->prepare('UPDATE categorias SET estado = ?, updated_at = current_timestamp() WHERE id = ?');
                $upd->execute([$nuevoEstado, $id]);

                $accionTexto = $nuevoEstado === 1 ? 'publicada' : 'ocultada';
                $mensaje = 'Categoría "' . $cat['nombre'] . '" ' . $accionTexto . '.';
                if ($tieneProductos) {
                    $mensaje .= ' Tiene productos asociados (estos conservan la categoría).';
                }
                $tipo = 'ok';

                // Refrescar datos
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $categorias = $stmt->fetchAll();
            }
        }
    }
}

// Token CSRF para formularios de esta página
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categorías — ELECTRIC Panel</title>
    <link rel="stylesheet" href="../vendor/fontawesome/css/all.css">
    <link rel="stylesheet" href="../vendor/bootstrap.min.css">
    <link rel="stylesheet" href="../css/stilos.css">
    <link rel="stylesheet" href="css/admin.css">
</head>
<body>
    <header class="header">
        <div class="contenedor">
            <h1>ELECTRIC <small style="font-weight:400; font-size:14px; color:#ccc;">Panel Administrativo</small></h1>
            <nav class="admin-nav">
                <a href="index.php">Inicio</a>
                <a href="categorias.php" class="activo">Categorías</a>
                <a href="productos.php">Productos</a>
                <a href="banner.php">Banner compartido</a>
                <form method="post" action="logout.php" style="display:inline; margin-left:auto;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" style="background:none; border:none; padding:8px 14px; color:#fff; font:inherit; cursor:pointer; border-radius:6px; background:#8a1f1f;">
                        Cerrar sesión
                    </button>
                </form>
            </nav>
        </div>
    </header>

    <div class="admin-wrap">
        <div class="admin-page">
            <h1>Categorías</h1>
            <p class="admin-sub">Gestione las categorías de productos. Use el estado para ocultar/publicar sin eliminar.</p>

            <?php if ($mensaje): ?>
                <div class="msg <?= $tipo ?>"><?php echo htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form method="get" action="categorias.php" class="filtros" style="margin-bottom:0;">
                <div class="campo" style="margin-bottom:0; flex:1; min-width:220px;">
                    <label>Buscar por nombre</label>
                    <input type="text" name="q" value="<?php echo htmlspecialchars($busqueda, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Nombre de categoría">
                </div>
                <button type="submit" class="btn btn-guardar btn-sm" style="padding:6px 14px;">Buscar</button>
                <?php if ($busqueda !== ''): ?>
                    <a href="categorias.php" class="btn btn-sec btn-sm">Limpiar</a>
                <?php endif; ?>
            </form>

            <div class="botones" style="margin-bottom:18px;">
                <a href="categoria-editar.php" class="btn btn-guardar">+ Nueva categoría</a>
            </div>

            <?php if (count($categorias) === 0): ?>
                <div class="sin-datos">No se encontraron categorías.</div>
            <?php else: ?>
                <div class="tabla-wrap">
                    <table class="admin-tabla">
                        <thead>
                            <tr>
                                <th style="width:40px;">ID</th>
                                <th>Nombre</th>
                                <th style="width:140px;">Estado</th>
                                <th style="width:150px;">Creada</th>
                                <th style="width:150px;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categorias as $c): ?>
                                <tr>
                                    <td><?php echo (int) $c['id']; ?></td>
                                    <td><?php echo htmlspecialchars($c['nombre'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <span class="estado <?= $c['estado'] === 1 ? 'activa' : 'oculta' ?>">
                                            <?= $c['estado'] === 1 ? 'Activa' : 'Oculta' ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($c['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <form method="post" action="categoria-estado.php" style="display:inline;margin:0;">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>">
                                            <input type="hidden" name="estado" value="<?php echo $c['estado'] === 1 ? 0 : 1; ?>">
                                            <button type="submit" class="btn btn-sec btn-sm" title="<?php echo $c['estado'] === 1 ? 'Ocultar' : 'Publicar'; ?>">
                                                <?php echo $c['estado'] === 1 ? 'Ocultar' : 'Publicar'; ?>
                                            </button>
                                        </form>
                                        <a href="categoria-editar.php?id=<?php echo (int) $c['id']; ?>" class="btn btn-sec btn-sm">Editar</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

<?php
declare(strict_types=1);

/**
 * Crear o editar una categoría (solo administrador autenticado).
 * id=0 -> nueva. id=N -> editar existente.
 */

require __DIR__ . '/auth_check.php';
require __DIR__ . '/../../config/db.php';

$modo = 'nuevo';
$categoria = [
    'id' => 0,
    'nombre' => '',
    'estado' => 1,
];
$errores = [];
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

// Cargar categoría existente al editar
if ($id !== false && $id > 0) {
    $stmt = $pdo->prepare('SELECT id, nombre, estado FROM categorias WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $categoria = $stmt->fetch();
    if (!$categoria) {
        $_SESSION['__flash_err'] = 'La categoría no existe.';
        header('Location: categorias.php');
        exit;
    }
    $modo = 'editar';
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Procesar guardado (POST + CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $errores[] = 'Solicitud inválida.';
    } else {
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $estado = filter_input(INPUT_POST, 'estado', FILTER_VALIDATE_INT);
        $idPost = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

        // Validaciones
        if ($nombre === '') {
            $errores[] = 'El nombre es obligatorio.';
        }
        if (mb_strlen($nombre, 'UTF-8') > 150) {
            $errores[] = 'El nombre no puede superar los 150 caracteres.';
        }
        if (!in_array($estado, [0, 1], true)) {
            $errores[] = 'Estado inválido.';
        }
        if ($idPost === false || (int) $idPost !== (int) $categoria['id']) {
            $errores[] = 'Identificador de categoría inválido.';
        }

        if (empty($errores)) {
            try {
                if ($modo === 'nuevo') {
                    // No se envían descripcion ni imagen: se conservan los
                    // valores por defecto de la tabla (NULL).
                    $ins = $pdo->prepare(
                        'INSERT INTO categorias (nombre, estado)
                         VALUES (?, ?)'
                    );
                    $ins->execute([$nombre, $estado]);
                    $_SESSION['__flash_ok'] = 'Categoría creada correctamente.';
                } else {
                    // Solo se actualizan nombre y estado. Las columnas
                    // descripcion e imagen se dejan intactas en la BD.
                    $upd = $pdo->prepare(
                        'UPDATE categorias SET nombre = ?, estado = ?, updated_at = current_timestamp()
                         WHERE id = ?'
                    );
                    $upd->execute([$nombre, $estado, $idPost]);
                    $_SESSION['__flash_ok'] = 'Categoría actualizada correctamente.';
                }

                header('Location: categorias.php');
                exit;
            } catch (Throwable $e) {
                error_log('electric_intranet: error guardando categoría — ' . $e->getMessage());
                $errores[] = 'Error interno al guardar la categoría.';
            }
        }

        // Repoblar datos del formulario con lo enviado
        $categoria['nombre'] = $nombre;
        $categoria['estado'] = $estado ?? 1;
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $modo === 'nuevo' ? 'Nueva categoría' : 'Editar categoría' ?> — ELECTRIC Panel</title>
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
                <a href="categorias.php">Categorías</a>
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
            <h1><?= $modo === 'nuevo' ? 'Nueva categoría' : 'Editar categoría' ?></h1>
            <p class="admin-sub">Complete los datos de la categoría.</p>

            <?php foreach ($errores as $e): ?>
                <div class="msg err"><?php echo htmlspecialchars($e, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endforeach; ?>

            <form method="post" action="categoria-editar.php?id=<?php echo (int) $categoria['id']; ?>" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="id" value="<?php echo (int) $categoria['id']; ?>">

                <div class="form-card">
                    <h2><?= $modo === 'nuevo' ? 'Datos de la categoría' : 'Editar categoría' ?></h2>

                    <div class="campo">
                        <label for="nombre">Nombre <span style="color:#8a1f1f;">*</span></label>
                        <input type="text" name="nombre" id="nombre" required maxlength="150"
                               value="<?php echo htmlspecialchars($categoria['nombre'], ENT_QUOTES, 'UTF-8'); ?>">
                    </div>

                    <div class="campo">
                        <label>Estado</label>
                        <select name="estado" id="estado">
                            <option value="1" <?= $categoria['estado'] === 1 ? 'selected' : '' ?>>Activa (publicada)</option>
                            <option value="0" <?= $categoria['estado'] === 0 ? 'selected' : '' ?>>Oculta</option>
                        </select>
                        <div class="nota">Las categorías ocultas no se muestran ni pueden asignarse a productos nuevos.</div>
                    </div>
                </div>

                <div class="botones">
                    <button type="submit" class="btn btn-guardar"><?= $modo === 'nuevo' ? 'Crear categoría' : 'Guardar cambios' ?></button>
                    <a href="categorias.php" class="btn btn-sec">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>

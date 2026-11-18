<?php
declare(strict_types=1);

/**
 * Crear o editar un producto con gestión de imágenes (solo administrador autenticado).
 * id=0 -> nuevo. id=N -> editar.
 * Todas las escrituras usan POST + CSRF y transacciones PDO.
 */

require __DIR__ . '/auth_check.php';
require __DIR__ . '/../../config/db.php';
require __DIR__ . '/upload.php';

$modo = 'nuevo';
$producto = [
    'id' => 0,
    'categoria_id' => 0,
    'nombre' => '',
    'marca' => '',
    'descripcion_corta' => '',
    'detalle' => '',
    'estado' => 1,
];
$imagenes = []; // filas de producto_imagenes
$errores = [];
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

// Cargar producto existente al editar
if ($id !== false && $id > 0) {
    $stmt = $pdo->prepare(
        'SELECT id, categoria_id, nombre, marca, descripcion_corta, detalle, estado
         FROM productos WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    $producto = $stmt->fetch();
    if (!$producto) {
        $_SESSION['__flash_err'] = 'El producto no existe.';
        header('Location: productos.php');
        exit;
    }
    $modo = 'editar';

    // Imágenes existentes del producto
    $stmtImg = $pdo->prepare(
        'SELECT id, ruta, orden, principal FROM producto_imagenes
         WHERE producto_id = ? ORDER BY orden ASC, id ASC'
    );
    $stmtImg->execute([$id]);
    $imagenes = $stmtImg->fetchAll();
}

// Categorías disponibles para el desplegable:
//   - todas las activas
//   - más la categoría actual del producto (si está oculta, para no romper históricos)
$categorias = [];
$stmtC = $pdo->query('SELECT id, nombre, estado FROM categorias ORDER BY nombre ASC');
$categorias = $stmtC->fetchAll();
$categoriaActual = (int) ($producto['categoria_id'] ?? 0);
$categoriasDisponibles = [];
foreach ($categorias as $c) {
    if ((int) $c['estado'] === 1 || (int) $c['id'] === $categoriaActual) {
        $categoriasDisponibles[] = $c;
    }
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
        // Normalizar subida de archivos: aceptar tanto name="imagenes[]" como name="imagenes"
        if (isset($_FILES['imagenes'])
            && is_array($_FILES['imagenes'])
            && isset($_FILES['imagenes']['name'])
            && is_string($_FILES['imagenes']['name'])) {
            $_FILES['imagenes'] = [
                'name'     => [$_FILES['imagenes']['name']],
                'type'     => [$_FILES['imagenes']['type'] ?? ''],
                'tmp_name' => [$_FILES['imagenes']['tmp_name']],
                'error'    => [$_FILES['imagenes']['error'] ?? UPLOAD_ERR_OK],
                'size'     => [$_FILES['imagenes']['size'] ?? 0],
            ];
        }

        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $marca = trim((string) ($_POST['marca'] ?? ''));
        $categoriaId = filter_input(INPUT_POST, 'categoria_id', FILTER_VALIDATE_INT);
        $descripcionCorta = trim((string) ($_POST['descripcion_corta'] ?? ''));
        $detalle = trim((string) ($_POST['detalle'] ?? ''));
        $estado = filter_input(INPUT_POST, 'estado', FILTER_VALIDATE_INT);
        $idPost = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $principalSeleccionada = filter_input(INPUT_POST, 'principal_imagen', FILTER_VALIDATE_INT);
        $eliminarIds = $_POST['eliminar_imagen'] ?? [];
        if (!is_array($eliminarIds)) {
            $eliminarIds = [$eliminarIds];
        }
        $eliminarIds = array_filter(array_map('intval', $eliminarIds));

        // Validaciones
        if ($nombre === '') {
            $errores[] = 'El nombre es obligatorio.';
        }
        if (mb_strlen($nombre, 'UTF-8') > 200) {
            $errores[] = 'El nombre no puede superar los 200 caracteres.';
        }
        if ($marca === '') {
            $errores[] = 'La marca es obligatoria.';
        }
        if (mb_strlen($marca, 'UTF-8') > 150) {
            $errores[] = 'La marca no puede superar los 150 caracteres.';
        }
        if ($categoriaId === false || $categoriaId <= 0) {
            $errores[] = 'Debe seleccionar una categoría válida.';
        } else {
            // Categoría debe estar activa, salvo que sea la actual del producto (histórico)
            $catOk = false;
            foreach ($categoriasDisponibles as $c) {
                if ((int) $c['id'] === $categoriaId) {
                    $catOk = true;
                    break;
                }
            }
            if (!$catOk) {
                $errores[] = 'La categoría seleccionada no está disponible (debe estar activa).';
            }
        }
        if (mb_strlen($descripcionCorta, 'UTF-8') > 500) {
            $errores[] = 'La descripción corta no puede superar los 500 caracteres.';
        }
        if (!in_array($estado, [0, 1], true)) {
            $errores[] = 'Estado inválido.';
        }
        if ($idPost === false || (int) $idPost !== (int) $producto['id']) {
            $errores[] = 'Identificador de producto inválido.';
        }

        // Validar que la imagen principal seleccionada pertenezca al producto
        $imagenesIdsValidos = array_column($imagenes, 'id');
        if ($principalSeleccionada !== false && $principalSeleccionada > 0
            && !in_array($principalSeleccionada, $imagenesIdsValidos, true)) {
            $errores[] = 'La imagen principal seleccionada no pertenece a este producto.';
        }
        // No permitir eliminar una imagen que se quiere como principal
        if ($principalSeleccionada !== false && $principalSeleccionada > 0
            && in_array($principalSeleccionada, $eliminarIds, true)) {
            $errores[] = 'No se puede eliminar la imagen marcada como principal.';
        }

        // Subir nuevas imágenes (máximo razonable)
        $nuevasRutas = [];
        if (empty($errores) && !empty($_FILES['imagenes']['name'][0])) {
            $archivos = $_FILES['imagenes'];

            foreach (array_keys($archivos['name']) as $idx) {
                $errorUpload = (int) ($archivos['error'][$idx] ?? UPLOAD_ERR_NO_FILE);
                if ($errorUpload === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                // Nombre de campo temporal para reutilizar el helper
                $_FILES['__tmp_img'] = [
                    'name'     => $archivos['name'][$idx],
                    'type'     => $archivos['type'][$idx] ?? '',
                    'tmp_name' => $archivos['tmp_name'][$idx],
                    'error'    => $errorUpload,
                    'size'     => $archivos['size'][$idx] ?? 0,
                ];
                $subida = electric_guardar_imagen('__tmp_img', 'productos');
                unset($_FILES['__tmp_img']);
                if (!$subida['ok']) {
                    $errores[] = 'Imagen ' . ($idx + 1) . ': ' . $subida['error'];
                    break;
                }
                $nuevasRutas[] = $subida['ruta'];
            }
        }

        // Si una subida múltiple falló a medias, eliminar los archivos que
        // ya se habían guardado para no dejarlos huérfanos.
        if (!empty($errores) && $nuevasRutas !== []) {
            foreach ($nuevasRutas as $rutaNueva) {
                electric_eliminar_imagen($rutaNueva);
            }
            $nuevasRutas = [];
        }

        if (empty($errores)) {
            $rutasAEliminar = [];
            $pdo->beginTransaction();
            try {
                $productoId = (int) $producto['id'];

                // Guardar/actualizar producto
                if ($modo === 'nuevo') {
                    $ins = $pdo->prepare(
                        'INSERT INTO productos (categoria_id, nombre, marca, descripcion_corta, detalle, estado)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    $ins->execute([$categoriaId, $nombre, $marca, $descripcionCorta, $detalle, $estado]);
                    $productoId = (int) $pdo->lastInsertId();
                } else {
                    $upd = $pdo->prepare(
                        'UPDATE productos SET categoria_id = ?, nombre = ?, marca = ?,
                                         descripcion_corta = ?, detalle = ?, estado = ?,
                                         updated_at = current_timestamp()
                         WHERE id = ?'
                    );
                    $upd->execute([$categoriaId, $nombre, $marca, $descripcionCorta, $detalle, $estado, $productoId]);
                }

                // Imágenes a eliminar: primero se borran de la BD dentro de la
                // transacción y el archivo se elimina después del commit.
                foreach ($eliminarIds as $imgId) {
                    // Obtener ruta antes de borrar
                    $stmtR = $pdo->prepare('SELECT ruta FROM producto_imagenes WHERE id = ? AND producto_id = ? LIMIT 1');
                    $stmtR->execute([$imgId, $productoId]);
                    $fila = $stmtR->fetch();
                    if ($fila && is_string($fila['ruta']) && $fila['ruta'] !== '') {
                        $rutasAEliminar[] = $fila['ruta'];
                    }
                    $del = $pdo->prepare('DELETE FROM producto_imagenes WHERE id = ? AND producto_id = ?');
                    $del->execute([$imgId, $productoId]);
                }

                // Recalcular lista de imágenes tras eliminar
                $stmtImg = $pdo->prepare(
                    'SELECT id, ruta, orden, principal FROM producto_imagenes
                     WHERE producto_id = ? ORDER BY orden ASC, id ASC'
                );
                $stmtImg->execute([$productoId]);
                $imagenesRestantes = $stmtImg->fetchAll();

                // Insertar nuevas imágenes después del mayor orden existente.
                $ordenActual = 0;
                foreach ($imagenesRestantes as $imgRestante) {
                    $ordenActual = max($ordenActual, (int) $imgRestante['orden']);
                }
                foreach ($nuevasRutas as $ruta) {
                    $ordenActual++;
                    $insImg = $pdo->prepare(
                        'INSERT INTO producto_imagenes (producto_id, ruta, orden, principal)
                         VALUES (?, ?, ?, 0)'
                    );
                    $insImg->execute([$productoId, $ruta, $ordenActual]);
                    $imagenesRestantes[] = ['id' => $pdo->lastInsertId(), 'ruta' => $ruta, 'orden' => $ordenActual, 'principal' => 0];
                }

                // Manejar imagen principal: asegurar exactamente una
                if (count($imagenesRestantes) > 0) {
                    // Desmarcar todas
                    $reset = $pdo->prepare('UPDATE producto_imagenes SET principal = 0 WHERE producto_id = ?');
                    $reset->execute([$productoId]);

                    $principalId = null;
                    if ($principalSeleccionada !== false && $principalSeleccionada > 0) {
                        $principalId = $principalSeleccionada;
                    } else {
                        // Por defecto, la primera imagen restante
                        $principalId = (int) $imagenesRestantes[0]['id'];
                    }

                    $setPrin = $pdo->prepare('UPDATE producto_imagenes SET principal = 1 WHERE id = ? AND producto_id = ?');
                    $setPrin->execute([$principalId, $productoId]);
                }

                $pdo->commit();

                // Las imágenes nuevas ya están confirmadas en la BD; si algo
                // fallara después, el catch no debe borrarlas.
                $nuevasRutas = [];

                // La transacción se confirmó. Ahora es seguro borrar los
                // archivos de las imágenes eliminadas.
                foreach ($rutasAEliminar as $rutaEliminar) {
                    electric_eliminar_imagen($rutaEliminar);
                }

                $_SESSION['__flash_ok'] = $modo === 'nuevo'
                    ? 'Producto creado correctamente.'
                    : 'Producto actualizado correctamente.';
                header('Location: productos.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('electric_intranet: error guardando producto — ' . $e->getMessage());
                // Limpiar imágenes nuevas subidas si falló la transacción
                foreach ($nuevasRutas as $ruta) {
                    electric_eliminar_imagen($ruta);
                }
                $errores[] = 'Error interno al guardar el producto.';
            }
        }

        // Repoblar formulario con lo enviado
        $producto['nombre'] = $nombre;
        $producto['marca'] = $marca;
        $producto['categoria_id'] = $categoriaId ?? $producto['categoria_id'];
        $producto['descripcion_corta'] = $descripcionCorta;
        $producto['detalle'] = $detalle;
        $producto['estado'] = $estado ?? 1;
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $modo === 'nuevo' ? 'Nuevo producto' : 'Editar producto' ?> — ELECTRIC Panel</title>
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
            <h1><?= $modo === 'nuevo' ? 'Nuevo producto' : 'Editar producto' ?></h1>
            <p class="admin-sub">Complete los datos del producto y gestione su galería de imágenes.</p>

            <?php foreach ($errores as $e): ?>
                <div class="msg err"><?php echo htmlspecialchars($e, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endforeach; ?>

            <form method="post" action="producto-editar.php?id=<?php echo (int) $producto['id']; ?>" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="id" value="<?php echo (int) $producto['id']; ?>">

                <div class="form-card">
                    <h2><?= $modo === 'nuevo' ? 'Datos del producto' : 'Editar producto' ?></h2>

                    <div class="campo">
                        <label for="nombre">Nombre <span style="color:#8a1f1f;">*</span></label>
                        <input type="text" name="nombre" id="nombre" required maxlength="200"
                               value="<?php echo htmlspecialchars($producto['nombre'], ENT_QUOTES, 'UTF-8'); ?>">
                    </div>

                    <div class="campo">
                        <label for="marca">Marca <span style="color:#8a1f1f;">*</span></label>
                        <input type="text" name="marca" id="marca" required maxlength="150"
                               value="<?php echo htmlspecialchars($producto['marca'], ENT_QUOTES, 'UTF-8'); ?>">
                    </div>

                    <div class="campo">
                        <label for="categoria_id">Categoría <span style="color:#8a1f1f;">*</span></label>
                        <select name="categoria_id" id="categoria_id" required>
                            <?php foreach ($categoriasDisponibles as $c): ?>
                                <option value="<?php echo (int) $c['id']; ?>" <?= ((int) $c['id'] === (int) $producto['categoria_id']) ? 'selected' : '' ?>>
                                    <?php echo htmlspecialchars($c['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?= (int) $c['estado'] === 0 ? ' (oculta)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="nota">Solo se muestran categorías activas. Se conserva la categoría actual del producto aunque esté oculta.</div>
                    </div>

                    <div class="campo">
                        <label for="descripcion_corta">Descripción corta</label>
                        <input type="text" name="descripcion_corta" id="descripcion_corta" maxlength="500"
                               value="<?php echo htmlspecialchars($producto['descripcion_corta'], ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="nota">Máximo 500 caracteres.</div>
                    </div>

                    <div class="campo">
                        <label for="detalle">Detalle completo</label>
                        <textarea name="detalle" id="detalle"><?php echo htmlspecialchars($producto['detalle'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                        <div class="nota">Descripción completa del producto.</div>
                    </div>

                    <div class="campo">
                        <label>Estado</label>
                        <select name="estado" id="estado">
                            <option value="1" <?= $producto['estado'] === 1 ? 'selected' : '' ?>>Publicado</option>
                            <option value="0" <?= $producto['estado'] === 0 ? 'selected' : '' ?>>Oculto</option>
                        </select>
                    </div>
                </div>

                <!-- Galería de imágenes -->
                <div class="form-card">
                    <h2>Imágenes del producto</h2>
                    <div class="campo">
                        <label>Agregar imágenes</label>
                        <input type="file" name="imagenes[]" multiple accept="image/jpeg,image/png,image/webp">
                        <div class="nota">Puede seleccionar varias imágenes. JPEG, PNG o WebP. Máximo 2 MB y 1600×1600 px cada una.</div>
                    </div>

                    <?php if (count($imagenes) > 0): ?>
                        <div class="galeria">
                            <?php foreach ($imagenes as $img): ?>
                                <div class="img-item">
                                    <img src="imagen.php?ruta=<?php echo urlencode($img['ruta']); ?>" alt="">
                                    <div class="acciones">
                                        <label style="font-weight:normal;font-size:12px;">
                                            <input type="radio" name="principal_imagen" value="<?php echo (int) $img['id']; ?>" <?= $img['principal'] === 1 ? 'checked' : '' ?>>
                                            Principal
                                        </label>
                                        <?php if (count($imagenes) > 1): ?>
                                            <label style="font-weight:normal;font-size:12px;color:#8a1f1f;">
                                                <input type="checkbox" name="eliminar_imagen[]" value="<?php echo (int) $img['id']; ?>">
                                                Eliminar
                                            </label>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($img['principal'] === 1): ?>
                                        <div class="principal">Principal</div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="nota">Seleccione una imagen principal. Al menos una imagen debe quedar como principal si existen imágenes.</div>
                    <?php else: ?>
                        <div class="sin-datos" style="text-align:left;">Este producto no tiene imágenes aún.</div>
                    <?php endif; ?>
                </div>

                <div class="botones">
                    <button type="submit" class="btn btn-guardar"><?= $modo === 'nuevo' ? 'Crear producto' : 'Guardar cambios' ?></button>
                    <a href="productos.php" class="btn btn-sec">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>

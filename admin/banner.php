<?php
declare(strict_types=1);

/**
 * Gestión del banner compartido (solo administrador autenticado).
 *
 * Una única imagen global se guarda en uploads/banner/ (fuera de la webroot)
 * y su ruta se registra en configuracion.valor con la clave banner_imagen.
 *
 * Esta fase es exclusivamente administrativa: la web pública todavía no lee
 * esta configuración.
 */

require __DIR__ . '/auth_check.php';
require __DIR__ . '/../../config/db.php';
require __DIR__ . '/upload.php';

const ELECTRIC_BANNER_CLAVE = 'banner_imagen';
const ELECTRIC_BANNER_PATRON = '/^banner\/[A-Za-z0-9_-]+\.(jpg|jpeg|png|webp)$/i';

/**
 * Devuelve la ruta relativa del banner configurado, o '' si no hay.
 */
function electric_banner_leer(PDO $pdo): string
{
    $stmt = $pdo->prepare('SELECT valor FROM configuracion WHERE clave = ? LIMIT 1');
    $stmt->execute([ELECTRIC_BANNER_CLAVE]);
    $fila = $stmt->fetch();

    return $fila ? trim((string) ($fila['valor'] ?? '')) : '';
}

/**
 * Guarda el valor del banner. Con null se deja la configuración sin imagen.
 */
function electric_banner_guardar(PDO $pdo, ?string $ruta): void
{
    if ($ruta === null) {
        $stmt = $pdo->prepare(
            'INSERT INTO configuracion (clave, valor) VALUES (?, NULL)
             ON DUPLICATE KEY UPDATE valor = NULL, updated_at = current_timestamp()'
        );
        $stmt->execute([ELECTRIC_BANNER_CLAVE]);
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO configuracion (clave, valor) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE valor = ?, updated_at = current_timestamp()'
    );
    $stmt->execute([ELECTRIC_BANNER_CLAVE, $ruta, $ruta]);
}

$mensaje = '';
$tipo = '';

// Mensajes flash de la petición anterior.
if (!empty($_SESSION['__flash_ok'])) {
    $mensaje = (string) $_SESSION['__flash_ok'];
    $tipo = 'ok';
    unset($_SESSION['__flash_ok']);
} elseif (!empty($_SESSION['__flash_err'])) {
    $mensaje = (string) $_SESSION['__flash_err'];
    $tipo = 'err';
    unset($_SESSION['__flash_err']);
}

// Se lee antes del POST para conocer la ruta anterior.
try {
    $bannerActual = electric_banner_leer($pdo);
} catch (Throwable $e) {
    error_log('electric_intranet: error leyendo banner — ' . $e->getMessage());
    $bannerActual = '';
    if ($mensaje === '') {
        $mensaje = 'No se pudo leer la configuración del banner.';
        $tipo = 'err';
    }
}

// Procesar acciones (POST + CSRF).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $_SESSION['__flash_err'] = 'Solicitud inválida.';
        header('Location: banner.php');
        exit;
    }

    $accion = (string) ($_POST['accion'] ?? '');

    if ($accion === 'subir') {
        $subida = electric_guardar_imagen('banner', 'banner');
        if (!$subida['ok']) {
            $_SESSION['__flash_err'] = (string) ($subida['error'] ?? 'No se pudo subir la imagen.');
            header('Location: banner.php');
            exit;
        }

        $nuevaRuta = (string) $subida['ruta'];

        try {
            electric_banner_guardar($pdo, $nuevaRuta);
        } catch (Throwable $e) {
            // La BD no se actualizó: se elimina únicamente la imagen nueva.
            electric_eliminar_imagen($nuevaRuta);
            error_log('electric_intranet: error guardando banner — ' . $e->getMessage());
            $_SESSION['__flash_err'] = 'Error interno al guardar el banner. La imagen anterior se conserva.';
            header('Location: banner.php');
            exit;
        }

        // La BD ya apunta a la imagen nueva: es seguro borrar la anterior.
        if ($bannerActual !== '' && $bannerActual !== $nuevaRuta
            && preg_match(ELECTRIC_BANNER_PATRON, $bannerActual) === 1) {
            electric_eliminar_imagen($bannerActual);
        }

        $_SESSION['__flash_ok'] = 'Banner actualizado correctamente.';
        header('Location: banner.php');
        exit;
    }

    if ($accion === 'eliminar') {
        if ($bannerActual === '') {
            $_SESSION['__flash_err'] = 'No hay un banner personalizado configurado.';
            header('Location: banner.php');
            exit;
        }

        try {
            electric_banner_guardar($pdo, null);
        } catch (Throwable $e) {
            error_log('electric_intranet: error eliminando banner — ' . $e->getMessage());
            $_SESSION['__flash_err'] = 'Error interno al eliminar el banner. La imagen anterior se conserva.';
            header('Location: banner.php');
            exit;
        }

        // La BD ya no referencia el archivo: es seguro borrarlo.
        if (preg_match(ELECTRIC_BANNER_PATRON, $bannerActual) === 1) {
            electric_eliminar_imagen($bannerActual);
        }

        $_SESSION['__flash_ok'] = 'Banner eliminado. Se utilizará la imagen predeterminada.';
        header('Location: banner.php');
        exit;
    }

    $_SESSION['__flash_err'] = 'Acción no válida.';
    header('Location: banner.php');
    exit;
}

// Releer el estado final para renderizar la página.
try {
    $bannerActual = electric_banner_leer($pdo);
} catch (Throwable $e) {
    error_log('electric_intranet: error releyendo banner — ' . $e->getMessage());
    $bannerActual = '';
}

$bannerValido = $bannerActual !== '' && preg_match(ELECTRIC_BANNER_PATRON, $bannerActual) === 1;
$bannerExiste = false;

if ($bannerValido) {
    $rutaFisica = realpath(
        ELECTRIC_UPLOAD_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $bannerActual)
    );
    $bannerExiste = $rutaFisica !== false && is_file($rutaFisica);
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Banner compartido — ELECTRIC Panel</title>
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
                <a href="banner.php" class="activo">Banner compartido</a>
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
            <h1>Banner compartido</h1>
            <p class="admin-sub">Gestione la imagen compartida que se utilizará en las páginas de categorías.</p>

            <?php if ($mensaje !== ''): ?>
                <div class="msg <?= $tipo === 'ok' ? 'ok' : 'err' ?>">
                    <?php echo htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <div class="form-card">
                <h2>Banner actual</h2>

                <?php if ($bannerActual !== '' && $bannerExiste): ?>
                    <div style="margin-bottom:14px;">
                        <img src="imagen.php?ruta=<?php echo urlencode($bannerActual); ?>" alt="Banner compartido actual"
                             style="max-width:100%; max-height:260px; object-fit:cover; border-radius:6px; border:1px solid #ddd;">
                    </div>
                    <p class="nota" style="margin-bottom:14px;">
                        Archivo: <code><?php echo htmlspecialchars($bannerActual, ENT_QUOTES, 'UTF-8'); ?></code>
                    </p>

                    <form method="post" action="banner.php" style="margin:0;"
                          onsubmit="return confirm('¿Eliminar el banner compartido y volver a la imagen predeterminada?');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="accion" value="eliminar">
                        <button type="submit" class="btn btn-sec">Eliminar banner</button>
                    </form>
                <?php elseif ($bannerActual !== '' && !$bannerExiste): ?>
                    <div class="msg err" style="margin-bottom:14px;">
                        La configuración apunta a un archivo que no existe en el almacenamiento.
                        Puede subir un banner nuevo o eliminar la configuración actual.
                    </div>
                    <p class="nota" style="margin-bottom:14px;">
                        Ruta registrada: <code><?php echo htmlspecialchars($bannerActual, ENT_QUOTES, 'UTF-8'); ?></code>
                    </p>

                    <form method="post" action="banner.php" style="margin:0;"
                          onsubmit="return confirm('¿Eliminar la configuración del banner actual?');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="accion" value="eliminar">
                        <button type="submit" class="btn btn-sec">Eliminar configuración</button>
                    </form>
                <?php else: ?>
                    <div class="sin-datos">
                        No hay un banner personalizado configurado. Se utilizará la imagen predeterminada.
                    </div>
                <?php endif; ?>
            </div>

            <div class="form-card">
                <h2><?= $bannerActual !== '' ? 'Reemplazar banner' : 'Subir banner' ?></h2>

                <form method="post" action="banner.php" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="accion" value="subir">

                    <div class="campo">
                        <label for="banner">Imagen del banner</label>
                        <input type="file" name="banner" id="banner" accept="image/jpeg,image/png,image/webp" required>
                        <div class="nota">
                            Formatos permitidos: JPG, JPEG, PNG o WebP. Máximo 2 MB y 1600 × 1600 px.
                            El archivo se guarda con un nombre aleatorio fuera de la webroot.
                        </div>
                    </div>

                    <div class="botones">
                        <button type="submit" class="btn btn-guardar">
                            <?= $bannerActual !== '' ? 'Reemplazar banner' : 'Subir banner' ?>
                        </button>
                    </div>
                </form>

                <p class="nota" style="margin-top:14px; margin-bottom:0;">
                    La imagen se sirve mediante <code>admin/imagen.php</code>. La conexión con las páginas
                    públicas se realizará en la siguiente fase.
                </p>
            </div>
        </div>
    </div>
</body>
</html>

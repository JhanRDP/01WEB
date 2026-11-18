<?php
declare(strict_types=1);

/**
 * Listado de productos (solo administrador autenticado).
 * Búsqueda por nombre/marca, filtro por categoría y estado, paginación.
 */

require __DIR__ . '/auth_check.php';
require __DIR__ . '/../../config/db.php';

$mensaje = '';
$tipo = '';

// Filtros
$busqueda   = trim((string) ($_GET['q'] ?? ''));
$categoriaF = filter_input(INPUT_GET, 'categoria', FILTER_VALIDATE_INT);
$estadoF    = filter_input(INPUT_GET, 'estado', FILTER_VALIDATE_INT);
if ($estadoF === false || ($estadoF !== null && !in_array($estadoF, [0, 1], true))) {
    $estadoF = null;
}
$pagina     = max(1, (int) ($_GET['pagina'] ?? 1));
$porPagina  = 12;

// Cargar categorías activas (para filtro y para mostrar nombre)
$categorias = [];
$stmtC = $pdo->query('SELECT id, nombre FROM categorias ORDER BY nombre ASC');
$categorias = $stmtC->fetchAll();

// Construir consulta base
$sql = 'SELECT p.id, p.nombre, p.marca, p.descripcion_corta, p.detalle, p.categoria_id,
               p.estado, p.created_at, p.updated_at, c.nombre AS categoria_nombre
        FROM productos p
        LEFT JOIN categorias c ON c.id = p.categoria_id
        WHERE 1=1';
$params = [];

if ($busqueda !== '') {
    $sql .= ' AND (p.nombre LIKE ? OR p.marca LIKE ?)';
    $params[] = '%' . $busqueda . '%';
    $params[] = '%' . $busqueda . '%';
}
if ($categoriaF !== false && $categoriaF > 0) {
    $sql .= ' AND p.categoria_id = ?';
    $params[] = $categoriaF;
}
if ($estadoF !== false && in_array($estadoF, [0, 1], true)) {
    $sql .= ' AND p.estado = ?';
    $params[] = $estadoF;
}

$sqlCount = 'SELECT COUNT(*) FROM (' . $sql . ') AS t';
$stmtCnt = $pdo->prepare($sqlCount);
$stmtCnt->execute($params);
$total = (int) $stmtCnt->fetchColumn();
$totalPaginas = max(1, (int) ceil($total / $porPagina));
$pagina = min($pagina, $totalPaginas);
$offset = ($pagina - 1) * $porPagina;

$sql .= ' ORDER BY p.created_at DESC LIMIT ? OFFSET ?';
$params[] = $porPagina;
$params[] = $offset;

$stmt = $pdo->prepare($sql);
// Bind: los primeros son strings, los últimos dos enteros
foreach ($params as $i => $valor) {
    $tipoBind = is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR;
    $stmt->bindValue($i + 1, $valor, $tipoBind);
}
$stmt->execute();
$productos = $stmt->fetchAll();

// Cambio de estado de producto (POST + CSRF)
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
            $chk = $pdo->prepare('SELECT id, nombre FROM productos WHERE id = ? LIMIT 1');
            $chk->execute([$id]);
            $prod = $chk->fetch();
            if (!$prod) {
                $mensaje = 'El producto no existe.';
                $tipo = 'err';
            } else {
                $upd = $pdo->prepare('UPDATE productos SET estado = ?, updated_at = current_timestamp() WHERE id = ?');
                $upd->execute([$nuevoEstado, $id]);
                $texto = $nuevoEstado === 1 ? 'publicado' : 'ocultado';
                $qs = [];
                if ($busqueda !== '') {
                    $qs['q'] = $busqueda;
                }
                if ($categoriaF !== false && $categoriaF > 0) {
                    $qs['categoria'] = $categoriaF;
                }
                if ($estadoF !== false && $estadoF !== null) {
                    $qs['estado'] = $estadoF;
                }
                $qs['pagina'] = $pagina;

                $_SESSION['__flash_ok'] = 'Producto "' . $prod['nombre'] . '" ' . $texto . '.';
                header('Location: productos.php?' . http_build_query($qs));
                exit;
            }
        }
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Productos — ELECTRIC Panel</title>
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
                <a href="productos.php" class="activo">Productos</a>
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
            <h1>Productos</h1>
            <p class="admin-sub">Gestione los productos, sus imágenes y su estado de publicación.</p>

            <?php if (!empty($_SESSION['__flash_ok'])): ?>
                <div class="msg ok"><?php echo htmlspecialchars($_SESSION['__flash_ok'], ENT_QUOTES, 'UTF-8'); ?></div>
                <?php unset($_SESSION['__flash_ok']); ?>
            <?php endif; ?>
            <?php if (!empty($_SESSION['__flash_err'])): ?>
                <div class="msg err"><?php echo htmlspecialchars($_SESSION['__flash_err'], ENT_QUOTES, 'UTF-8'); ?></div>
                <?php unset($_SESSION['__flash_err']); ?>
            <?php endif; ?>
            <?php if ($mensaje): ?>
                <div class="msg <?= $tipo ?>"><?php echo htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form method="get" action="productos.php" class="filtros">
                <input type="hidden" name="pagina" value="1">
                <div class="campo" style="flex:1; min-width:240px; margin-bottom:0;">
                    <label>Buscar por nombre o marca</label>
                    <input type="text" name="q" value="<?php echo htmlspecialchars($busqueda, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Nombre o marca">
                </div>
                <div class="campo" style="min-width:170px; margin-bottom:0;">
                    <label>Categoría</label>
                    <select name="categoria">
                        <option value="">Todas</option>
                        <?php foreach ($categorias as $cat): ?>
                            <option value="<?php echo (int) $cat['id']; ?>" <?= ($categoriaF !== false && (int) $cat['id'] === $categoriaF) ? 'selected' : '' ?>>
                                <?php echo htmlspecialchars($cat['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="campo" style="min-width:130px; margin-bottom:0;">
                    <label>Estado</label>
                    <select name="estado">
                        <option value="">Todos</option>
                        <option value="1" <?= ($estadoF === 1) ? 'selected' : '' ?>>Publicado</option>
                        <option value="0" <?= ($estadoF === 0) ? 'selected' : '' ?>>Oculto</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-guardar btn-sm" style="padding:6px 14px;">Filtrar</button>
                <?php if ($busqueda !== '' || ($categoriaF !== false && $categoriaF > 0) || ($estadoF !== false && $estadoF !== null)): ?>
                    <a href="productos.php" class="btn btn-sec btn-sm">Limpiar</a>
                <?php endif; ?>
            </form>

            <div class="botones" style="margin-top:14px; margin-bottom:18px;">
                <a href="producto-editar.php" class="btn btn-guardar">+ Nuevo producto</a>
            </div>

            <?php if (count($productos) === 0): ?>
                <div class="sin-datos">No se encontraron productos.</div>
            <?php else: ?>
                <div class="tabla-wrap">
                    <table class="admin-tabla">
                        <thead>
                            <tr>
                                <th style="width:40px;">ID</th>
                                <th>Nombre</th>
                                <th style="width:150px;">Marca</th>
                                <th style="width:170px;">Categoría</th>
                                <th style="width:120px;">Estado</th>
                                <th style="width:150px;">Creado</th>
                                <th style="width:170px;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($productos as $p): ?>
                                <tr>
                                    <td><?php echo (int) $p['id']; ?></td>
                                    <td><?php echo htmlspecialchars($p['nombre'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($p['marca'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($p['categoria_nombre'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <span class="estado <?= $p['estado'] === 1 ? 'activa' : 'oculta' ?>">
                                            <?= $p['estado'] === 1 ? 'Publicado' : 'Oculto' ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($p['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <form method="post" action="producto-estado.php" style="display:inline;margin:0;">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>">
                                            <input type="hidden" name="estado" value="<?php echo $p['estado'] === 1 ? 0 : 1; ?>">
                                            <button type="submit" class="btn btn-sec btn-sm" title="<?php echo $p['estado'] === 1 ? 'Ocultar' : 'Publicar'; ?>">
                                                <?php echo $p['estado'] === 1 ? 'Ocultar' : 'Publicar'; ?>
                                            </button>
                                        </form>
                                        <a href="producto-editar.php?id=<?php echo (int) $p['id']; ?>" class="btn btn-sec btn-sm">Editar</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPaginas > 1): ?>
                    <div class="paginacion">
                        <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
                            <?php
                            $qs = [];
                            if ($busqueda !== '') {
                                $qs['q'] = $busqueda;
                            }
                            if ($categoriaF !== false && $categoriaF > 0) {
                                $qs['categoria'] = $categoriaF;
                            }
                            if ($estadoF !== false && $estadoF !== null) {
                                $qs['estado'] = $estadoF;
                            }
                            $qs['pagina'] = $i;
                            ?>
                            <a href="productos.php?<?php echo http_build_query($qs); ?>" <?= $i === $pagina ? 'class="actual"' : '' ?>>
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

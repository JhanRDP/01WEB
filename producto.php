<?php
declare(strict_types=1);

/**
 * Página pública de producto de ELECTRIC.
 *
 * Migración conservadora de producto.html a PHP (Fase 4.3).
 *
 * Recibe un producto mediante producto.php?id=ID y muestra únicamente los
 * productos publicados cuya categoría también esté activa. La estructura
 * visual (cabecera, menú, ficha, galería, compartir, WhatsApp, banda inferior
 * y pie) se mantiene igual que en la versión HTML original.
 *
 * Estados:
 *   - ok:     producto publicado con categoría activa.
 *   - 404:    ID inválido, producto inexistente/oculto o categoría inactiva.
 *   - error:  fallo de consulta; se registra en el log y se muestra un mensaje
 *             genérico (nunca se trata como producto inexistente).
 *
 * La conexión PDO es la existente en config/db.php (fuera de la webroot).
 */

/**
 * Construye la URL pública de una imagen de producto gestionada por el módulo
 * administrativo. Las imágenes residen fuera de la webroot y se sirven mediante
 * admin/imagen.php. Devuelve null si la ruta no es válida, si el archivo físico
 * no existe o si no es una imagen legible.
 *
 * La ruta procede de la base de datos, nunca del visitante, y se valida con el
 * mismo patrón que admin/imagen.php.
 */
function electric_url_imagen_producto(?string $ruta): ?string
{
    $ruta = trim((string) $ruta);
    if ($ruta === '') {
        return null;
    }
    if (!preg_match('#^productos/[A-Za-z0-9_-]+\.(?:jpe?g|png|webp)$#i', $ruta)) {
        return null;
    }

    $raizUploads = realpath(__DIR__ . '/../uploads');
    if ($raizUploads === false) {
        return null;
    }

    $rutaFisica = realpath(
        $raizUploads . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $ruta)
    );
    if ($rutaFisica === false || !is_file($rutaFisica)) {
        return null;
    }

    // Asegurar que el archivo está realmente dentro de uploads.
    $raizConSep = rtrim($raizUploads, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (strpos($rutaFisica, $raizConSep) !== 0) {
        return null;
    }

    // Evitar que el proxy devuelva un error si el archivo no es una imagen.
    if (@getimagesize($rutaFisica) === false) {
        return null;
    }

    return 'admin/imagen.php?ruta=' . rawurlencode($ruta);
}

/**
 * Convierte el detalle libre de la base de datos en líneas no vacías.
 * Cada línea se mostrará como un elemento de la lista de detalle existente.
 *
 * @return list<string>
 */
function electric_lineas_detalle_producto(?string $detalle): array
{
    $detalle = trim((string) $detalle);
    if ($detalle === '') {
        return [];
    }

    $lineas = preg_split('/\r\n|\r|\n/', $detalle);
    if ($lineas === false) {
        return [$detalle];
    }

    $resultado = [];
    foreach ($lineas as $linea) {
        $linea = trim($linea);
        if ($linea !== '') {
            $resultado[] = $linea;
        }
    }

    return $resultado;
}

$estadoPagina = '404';
$producto = null;
$imagenes = [];
$lineasDetalle = [];
$nombreProducto = '';
$marcaProducto = '';
$descripcionCorta = '';
$categoriaNombre = '';
$idCategoria = 0;
$tituloDocumento = 'ELECTRIC : Producto no encontrado';
$imagenRespaldo = 'img/prod/sin-imagen.svg';

/**
 * Valida el parámetro id de la URL.
 *
 * Se exige FILTER_VALIDATE_INT y además un formato estrictamente decimal
 * positivo, de modo que se rechacen valores ausentes, vacíos, negativos,
 * cero, no numéricos, con espacios, con signos o malformados.
 */
$idRaw = $_GET['id'] ?? null;
$idProducto = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$idValido = is_string($idRaw)
    && preg_match('/^[1-9][0-9]*$/', $idRaw) === 1
    && $idProducto !== false
    && $idProducto !== null
    && $idProducto > 0;

if (!$idValido) {
    // ID inválido: 404 sin consultar la base de datos.
    http_response_code(404);
    $estadoPagina = '404';
} else {
    $idProducto = (int) $idProducto;

    try {
        require __DIR__ . '/../config/db.php';

        // Producto publicado y categoría activa en una sola consulta.
        $stmtProducto = $pdo->prepare(
            'SELECT p.id, p.categoria_id, p.nombre, p.marca,
                    p.descripcion_corta, p.detalle, p.estado,
                    c.nombre AS categoria_nombre
             FROM productos p
             INNER JOIN categorias c ON c.id = p.categoria_id
             WHERE p.id = ? AND p.estado = 1 AND c.estado = 1
             LIMIT 1'
        );
        $stmtProducto->bindValue(1, $idProducto, PDO::PARAM_INT);
        $stmtProducto->execute();
        $producto = $stmtProducto->fetch();

        if (!$producto) {
            // Producto inexistente, oculto o con categoría inactiva: 404 sin
            // revelar información del producto.
            http_response_code(404);
            $estadoPagina = '404';
        } else {
            $idCategoria = (int) $producto['categoria_id'];
            $nombreProducto = (string) ($producto['nombre'] ?? '');
            $marcaProducto = (string) ($producto['marca'] ?? '');
            $descripcionCorta = (string) ($producto['descripcion_corta'] ?? '');
            $categoriaNombre = (string) ($producto['categoria_nombre'] ?? '');
            $lineasDetalle = electric_lineas_detalle_producto($producto['detalle'] ?? null);

            // Galería: principal primero; después por orden e id.
            $stmtImagenes = $pdo->prepare(
                'SELECT ruta
                 FROM producto_imagenes
                 WHERE producto_id = ?
                 ORDER BY principal DESC, orden ASC, id ASC'
            );
            $stmtImagenes->bindValue(1, $idProducto, PDO::PARAM_INT);
            $stmtImagenes->execute();
            $rutasImagenes = $stmtImagenes->fetchAll(PDO::FETCH_COLUMN);

            $imagenes = [];
            foreach ($rutasImagenes as $rutaImagen) {
                $urlImagen = electric_url_imagen_producto(
                    is_string($rutaImagen) ? $rutaImagen : null
                );
                if ($urlImagen !== null) {
                    $imagenes[] = $urlImagen;
                }
            }

            // Si no hay imágenes válidas, se usa un respaldo local neutro.
            if ($imagenes === []) {
                $imagenes[] = $imagenRespaldo;
            }

            $tituloDocumento = 'ELECTRIC : '
                . ($nombreProducto !== '' ? $nombreProducto : 'Producto');
            $estadoPagina = 'ok';
        }
    } catch (Throwable $e) {
        // Error de consulta: registrar sin exponer detalles y mostrar un
        // mensaje genérico. Nunca convertirlo en un producto inexistente.
        error_log('electric_intranet: error al cargar el producto — ' . $e->getMessage());
        http_response_code(500);
        $estadoPagina = 'error';
        $producto = null;
        $imagenes = [];
        $lineasDetalle = [];
        $nombreProducto = '';
        $marcaProducto = '';
        $descripcionCorta = '';
        $categoriaNombre = '';
        $idCategoria = 0;
        $tituloDocumento = 'ELECTRIC : Información no disponible';
    }
}

// Categorías activas para el menú. Si la base de datos falló antes, no se
// reintenta la conexión y el menú conserva únicamente los enlaces fijos.
$categoriasMenu = [];

if ($estadoPagina !== 'error') {
    try {
        require_once __DIR__ . '/../config/db.php';

        $stmtMenu = $pdo->prepare(
            'SELECT id, nombre
             FROM categorias
             WHERE estado = 1
             ORDER BY id ASC'
        );
        $stmtMenu->execute();
        $categoriasMenu = $stmtMenu->fetchAll();
    } catch (Throwable $e) {
        error_log('electric_intranet: error al cargar el menú de categorías — ' . $e->getMessage());
        $categoriasMenu = [];
    }
}

$categoriaMayusculas = '';
if ($categoriaNombre !== '') {
    $categoriaMayusculas = function_exists('mb_strtoupper')
        ? mb_strtoupper($categoriaNombre, 'UTF-8')
        : strtoupper($categoriaNombre);
}
$breadcrumbCategoria = 'PRODUCTOS';
if ($categoriaMayusculas !== '') {
    $breadcrumbCategoria .= ' / ' . $categoriaMayusculas;
}

$textoWhatsApp = 'Hola Electric, necesito información de este producto: '
    . ($nombreProducto !== '' ? $nombreProducto : 'producto');
$urlWhatsApp = 'https://web.whatsapp.com/send?phone=51933604617&text='
    . rawurlencode($textoWhatsApp);

$imagenPrincipal = $imagenes[0] ?? $imagenRespaldo;
$esRespaldo = $imagenPrincipal === $imagenRespaldo;
$altImagen = $esRespaldo
    ? 'Imagen no disponible' . ($nombreProducto !== '' ? ' para ' . $nombreProducto : '')
    : trim($nombreProducto . ($marcaProducto !== '' ? ' - ' . $marcaProducto : ''));
if ($altImagen === '') {
    $altImagen = 'Producto';
}
$imagenesJson = json_encode(
    $imagenes,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
if ($imagenesJson === false) {
    $imagenesJson = '[]';
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($tituloDocumento, ENT_QUOTES, 'UTF-8'); ?></title>

    <!-- Tipografías e iconos -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400;1,700&family=Nunito:wght@400;600;700;800&family=Open+Sans:ital,wght@0,400;0,700;1,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="vendor/fontawesome/css/all.css">

    <link rel="stylesheet" href="vendor/bootstrap.min.css">
    <link rel="stylesheet" href="css/stilos.css">
    <link rel="stylesheet" href="css/producto.css">
</head>
<body id="top">

    <!-- ==================== HEADER ==================== -->
    <header class="header">
        <div class="contenedor">

            <!-- Fila superior: logo y datos de contacto -->
            <div class="header-top">
                <a href="index.php"><img class="logo" src="img/logo2.png" alt="Proyelectric"></a>
                <div class="header-info">
                    <div class="info-item">
                        <span><i class="fa-solid fa-envelope"></i></span>
                        <p>conventas002@electric.com</p>
                    </div>
                    <div class="info-item">
                        <span><i class="fa-solid fa-phone-volume"></i></span>
                        <p class="tel">999999999</p>
                    </div>
                    <div class="info-item redes">
                        <a href="#"><i class="fa-brands fa-facebook-f"></i></a>
                    </div>
                    <div class="menu_bar">
                        <a href="javascript:void(0);"><i class="fa-solid fa-bars"></i></a>
                    </div>
                </div>
            </div>

            <!-- Menú tipo píldora -->
            <nav class="menu">
                <ul>
                    <li><a href="nosotros.html">NOSOTROS</a></li>
                    <li class="submenu">
                        <a href="#">SERVICIOS <i class="fa-solid fa-angle-down"></i></a>
                        <ul class="children">
                            <li><a href="#" class="">SERVICIOS RESIDENCIALES  Y  COMERCIALES</a></li>
                                <li><a href="#" class="">SERVICIOS INDUSTRIALES</a></li>
                                </ul>
                            </li>
<?php if ($categoriasMenu !== []): ?>
                    <li class="submenu active">
                        <a href="#">PRODUCTOS <i class="fa-solid fa-angle-down"></i></a>
                        <ul class="children" data-menu-categorias>
<?php foreach ($categoriasMenu as $categoriaMenu): ?>
<?php $esCategoriaActual = $estadoPagina === 'ok' && $idCategoria === (int) $categoriaMenu['id']; ?>
                            <li><a href="categoria.php?id=<?php echo (int) $categoriaMenu['id']; ?>" class="<?php echo $esCategoriaActual ? 'active' : ''; ?>"><?php echo htmlspecialchars((string) ($categoriaMenu['nombre'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></a></li>
<?php endforeach; ?>
                        </ul>
                    </li>
<?php endif; ?>
                    <li><a href="#">ALQUILER DE EQUIPOS</a></li>
                    <li><a href="#">OBRAS</a></li>
                    <li><a href="#">CLIENTES</a></li>
                    <li><a href="#">PUBLICACIONES</a></li>
                    <li><a href="contacto.html">CONTÁCTENOS</a></li>
                </ul>
            </nav>

        </div>
    </header>

    <!-- ==================== PRODUCTO ==================== -->
    <main id="producto">

        <!-- Barra de navegación -->
        <div class="prod-barra">
            <div class="contenedor prod-barra-in">
                <h4><?php echo htmlspecialchars($breadcrumbCategoria, ENT_QUOTES, 'UTF-8'); ?></h4>
                <?php if ($estadoPagina === 'ok'): ?>
                    <a href="categoria.php?id=<?php echo $idCategoria; ?>" class="prod-retor">[ RETORNAR ]</a>
                <?php else: ?>
                    <a href="index.php" class="prod-retor">[ RETORNAR ]</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($estadoPagina === 'ok'): ?>
            <div class="contenedor">

                <!-- Ficha del producto -->
                <div class="prod-card">
                    <div class="prod-row">

                        <!-- Columna izquierda: foto y compartir -->
                        <div class="prod-izq">
                            <div class="prod-foto"
                                 data-galeria
                                 data-imagenes="<?php echo htmlspecialchars($imagenesJson, ENT_QUOTES, 'UTF-8'); ?>">
                                <img src="<?php echo htmlspecialchars($imagenPrincipal, ENT_QUOTES, 'UTF-8'); ?>"
                                     alt="<?php echo htmlspecialchars($altImagen, ENT_QUOTES, 'UTF-8'); ?>"
                                     data-galeria-img>
                                <?php if (count($imagenes) > 1): ?>
                                    <button type="button" class="prod-flecha prod-flecha-prev"
                                            aria-label="Imagen anterior" data-galeria-prev>
                                        <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                                    </button>
                                    <button type="button" class="prod-flecha prod-flecha-next"
                                            aria-label="Imagen siguiente" data-galeria-next>
                                        <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                            <div class="prod-compartir">
                                <p>Compartir con:</p>
                                <div class="prod-share">
                                    <a href="#" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
                                    <a href="<?php echo htmlspecialchars($urlWhatsApp, ENT_QUOTES, 'UTF-8'); ?>" aria-label="WhatsApp" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i></a>
                                    <a href="#" aria-label="LinkedIn"><i class="fa-brands fa-linkedin-in"></i></a>
                                    <a href="#" aria-label="Telegram"><i class="fa-brands fa-telegram"></i></a>
                                </div>
                            </div>
                        </div>

                        <!-- Columna derecha: datos -->
                        <div class="prod-der">
                            <h2 class="popibb"><?php echo htmlspecialchars($nombreProducto, ENT_QUOTES, 'UTF-8'); ?></h2>

                            <?php if ($marcaProducto !== ''): ?>
                                <div class="prod-dato">
                                    <span class="prod-chip">MARCA:</span>
                                    <span class="prod-val"><?php echo htmlspecialchars($marcaProducto, ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if ($descripcionCorta !== ''): ?>
                                <p class="prod-desc"><?php echo nl2br(htmlspecialchars($descripcionCorta, ENT_QUOTES, 'UTF-8')); ?></p>
                            <?php endif; ?>

                            <?php if ($lineasDetalle !== []): ?>
                                <h5 class="prod-det-tit popib">DETALLE DE PRODUCTO</h5>
                                <ul class="prod-detalle">
                                    <?php foreach ($lineasDetalle as $lineaDetalle): ?>
                                        <?php
                                        $etiquetaDetalle = '';
                                        $valorDetalle = $lineaDetalle;
                                        if (preg_match('/^([A-Za-zÁÉÍÓÚÜÑáéíóúüñ ]{2,40}):\s*(.+)$/u', $lineaDetalle, $coincidencia)) {
                                            $etiquetaDetalle = trim($coincidencia[1]);
                                            $valorDetalle = $coincidencia[2];
                                        }
                                        ?>
                                        <li>
                                            <?php if ($etiquetaDetalle !== ''): ?>
                                                <strong><?php echo htmlspecialchars($etiquetaDetalle, ENT_QUOTES, 'UTF-8'); ?>:</strong>
                                            <?php endif; ?>
                                            <?php echo htmlspecialchars($valorDetalle, ENT_QUOTES, 'UTF-8'); ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <a class="prod-ficha" href="#">
                                <img src="img/prod/pdf-icon.png" alt="">
                                <span>DESCARGAR<br>FICHA TÉCNICA</span>
                            </a>

                            <a class="wsp-pill prod-wsp" href="<?php echo htmlspecialchars($urlWhatsApp, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><img src="img/wsp.png" alt="Estamos en línea - Consulta por WhatsApp"></a>
                        </div>

                    </div>
                </div>

            </div>

            <!-- Banda inferior -->
            <div class="prod-banda">
                <span class="prod-banda-sub">¿Necesitas producto para tu proyecto?</span>
                <h4 class="popibb prod-banda-tit">Contamos con amplio stock</h4>
                <a class="boton" href="contacto.html">CONTÁCTANOS AHORA</a>
            </div>
        <?php else: ?>
            <div class="contenedor">
                <div class="prod-card">
                    <div class="prod-estado">
                        <p>
                            <?php if ($estadoPagina === '404'): ?>
                                El producto que buscas no existe o ya no está disponible.
                            <?php else: ?>
                                No se pudo cargar la información en este momento. Inténtalo nuevamente más tarde.
                            <?php endif; ?>
                        </p>
                        <a class="boton" href="index.php">VOLVER AL INICIO</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </main>

    <!-- ==================== FOOTER ==================== -->
    <footer class="footer">
        <div class="contenedor">

            <h3 class="footer-eslogan">ENERGÍA QUE TRANSFORMA EL NORTE DEL PERÚ</h3>
            <hr>

            <div class="footer-grid">

                <!-- Contacto / dirección -->
                <div class="footer-col">
                    <a href="index.php"><img class="logo-footer" src="img/logof.png" alt="Proyelectric"></a>
                    <p class="col-tit">Almacén Principal</p>
                    <p class="direccion"><i class="fa-solid fa-location-dot"></i>Mz. 99 Lote 9a<br>A.A. Torres 9<br>Lambayeque - Chiclayo - La Victoria</p>
                </div>

                <!-- Servicio al cliente -->
                <div class="footer-col">
                    <p class="col-tit">Servicio al Cliente</p>
                    <p class="dato tel-proyectos"><i class="fa-solid fa-phone-volume"></i><i class="fa-brands fa-whatsapp dos"></i><span>Proyectos:</span><span>909090909</span></p>
                    <p class="dato tel-ventas"><i class="fa-solid fa-phone-volume"></i><i class="fa-brands fa-whatsapp dos"></i><span>Ventas:</span><span>999999999</span></p>
                    <p class="direccion"><i class="fa-solid fa-envelope"></i>conventas002@electric.com<br>a.velasquez@electric.com</p>
                </div>

                <!-- Redes y horario -->
                <div class="footer-col">
                    <p class="col-tit">Redes Sociales</p>
                    <div class="redes">
                        <a href="#"><i class="fa-brands fa-facebook-f"></i></a>
                        <a href="#"><i class="fa-brands fa-instagram"></i></a>
                        <a href="#"><i class="fa-brands fa-linkedin-in"></i></a>
                        <a href="#"><i class="fa-brands fa-tiktok"></i></a>
                    </div>
                    <p class="col-tit">Horario de Atención</p>
                    <p class="dato horario-txt">Lunes a viernes 8:00 a 18:00 hrs<br>S&aacute;bados 8:00 a 12:30 hrs</p>
                    <a class="libro" href="#"><img src="img/libro.png" alt="Libro de reclamaciones"></a>
                </div>

            </div>

            <!-- Certificaciones -->
            <div class="certificaciones">
                <img src="img/certificado1.png" alt="">
                <span class="iso">ISO 9001:2015</span><br class="cbr">
                <img src="img/certificado2.png" alt=""><br class="cbr">
                <span class="iso">ISO 45001:2018</span><br class="cbr">
                <img src="img/certificado3.png" alt="">
                <img src="img/certificado4.png" alt="">
            </div>

            <p class="copyright">&copy; <span id="anio_actual">2026</span> electric.com | Página Web desarrollada por <a href="#">Terratech</a></p>

        </div>
    </footer>

    <!-- Botón flotante de WhatsApp -->
    <div class="wsp-float">
    <div class="wsp-pop" id="wsp-pop" role="dialog" aria-label="Contacto por WhatsApp">
        <div class="wsp-pop-head">
            <p>&iquest;Necesitas ayuda?</p>
            <span>Haga clic en uno de nuestros representantes</span>
            <button type="button" class="wsp-pop-close" aria-label="Cerrar"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        <div class="wsp-pop-body">
            <a class="wsp-pop-agent" href="https://web.whatsapp.com/send?phone=51933604617&amp;text=Hola%20Electric%2C%20necesito%20informaci%C3%B3n%20sobre%20sus%20productos%20y%20servicios." target="_blank" rel="noopener">
                <div class="wsp-pop-card">
                    <div class="wsp-pop-row">
                        <img class="wsp-pop-avatar" src="img/logo2.png" alt="ELECTRIC">
                        <div class="wsp-pop-info">
                            <h3>ELECTRIC</h3>
                            <p>Asesor Web</p>
                            <span>En l&iacute;nea</span>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    </div>
    <a class="fab-wsp" href="#" role="button" aria-expanded="false" aria-controls="wsp-pop" aria-label="Contacto por WhatsApp"><svg viewBox="0 0 32 32" style="width:56px;height:56px;color:#fff;fill:currentColor"><path fill-rule="evenodd" d=" M19.11 17.205c-.372 0-1.088 1.39-1.518 1.39a.63.63 0 0 1-.315-.1c-.802-.402-1.504-.817-2.163-1.447-.545-.516-1.146-1.29-1.46-1.963a.426.426 0 0 1-.073-.215c0-.33.99-.945.99-1.49 0-.143-.73-2.09-.832-2.335-.143-.372-.214-.487-.6-.487-.187 0-.36-.043-.53-.043-.302 0-.53.115-.746.315-.688.645-1.032 1.318-1.06 2.264v.114c-.015.99.472 1.977 1.017 2.78 1.23 1.82 2.506 3.41 4.554 4.34.616.287 2.035.888 2.722.888.817 0 2.15-.515 2.478-1.318.13-.33.244-.73.244-1.088 0-.058 0-.144-.03-.215-.1-.172-2.434-1.39-2.678-1.39zm-2.908 7.593c-1.747 0-3.48-.53-4.942-1.49L7.793 24.41l1.132-3.337a8.955 8.955 0 0 1-1.72-5.272c0-4.955 4.04-8.995 8.997-8.995S25.2 10.845 25.2 15.8c0 4.958-4.04 8.998-8.998 8.998zm0-19.798c-5.96 0-10.8 4.842-10.8 10.8 0 1.964.53 3.898 1.546 5.574L5 27.176l5.974-1.92a10.8 10.8 0 0 0 16.03-9.455c0-5.958-4.842-10.8-10.802-10.8z" fill-rule="evenodd"></path></svg></a>
</div>

    <script src="vendor/swiper-bundle.min.js"></script>
    <script>
        (function () {
            var hdr = document.querySelector('.header');
            window.addEventListener('scroll', function () {
                hdr.classList.toggle('flota', window.scrollY > 70);
            }, { passive: true });
        })();
    </script>

<script src="js/wsp-float.js"></script>
<script src="js/menu.js"></script>
<script src="js/producto.js"></script>
</body>
</html>

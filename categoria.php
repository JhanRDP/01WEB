<?php
declare(strict_types=1);

/**
 * Página pública de categoría de ELECTRIC.
 *
 * Migración conservadora de categoria.html a PHP (Fase 4.2).
 *
 * Recibe una categoría mediante categoria.php?id=ID y muestra únicamente los
 * productos publicados de esa categoría. La estructura visual (cabecera, menú,
 * banner, cuadrícula, pie, WhatsApp y estilos) se mantiene igual que en la
 * versión HTML original.
 *
 * Estados:
 *   - ok:     categoría activa con productos publicados.
 *   - vacio:  categoría activa sin productos publicados.
 *   - 404:    ID inválido, categoría inexistente o categoría inactiva.
 *   - error:  fallo de consulta; se registra en el log y se muestra un mensaje
 *             genérico (nunca se trata como categoría inexistente).
 *
 * La conexión PDO es la existente en config/db.php (fuera de la webroot).
 */

$estadoPagina = '404';
$categoria = null;
$productos = [];
$tituloCategoria = 'CATEGORÍA NO ENCONTRADA';
$tituloDocumento = 'ELECTRIC : Categoría no encontrada';
$mostrarConsulta = false;

/**
 * Valida el parámetro id de la URL.
 *
 * Se exige FILTER_VALIDATE_INT y además un formato estrictamente decimal
 * positivo, de modo que se rechacen valores ausentes, vacíos, negativos,
 * cero, no numéricos, con espacios, con signos o malformados.
 */
$idRaw = $_GET['id'] ?? null;
$idCategoria = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$idValido = is_string($idRaw)
    && preg_match('/^[1-9][0-9]*$/', $idRaw) === 1
    && $idCategoria !== false
    && $idCategoria !== null
    && $idCategoria > 0;

if (!$idValido) {
    // ID inválido: 404 sin consultar la base de datos.
    http_response_code(404);
    $estadoPagina = '404';
} else {
    $idCategoria = (int) $idCategoria;

    try {
        require __DIR__ . '/../config/db.php';

        // Solo categorías activas. El ID se vincula como entero con PDO.
        $stmtCategoria = $pdo->prepare(
            'SELECT id, nombre
             FROM categorias
             WHERE id = ? AND estado = 1
             LIMIT 1'
        );
        $stmtCategoria->bindValue(1, $idCategoria, PDO::PARAM_INT);
        $stmtCategoria->execute();
        $categoria = $stmtCategoria->fetch();

        if (!$categoria) {
            // Categoría inexistente o inactiva: 404 sin datos de categoría.
            http_response_code(404);
            $estadoPagina = '404';
        } else {
            // Productos publicados de la categoría, con una sola imagen por
            // producto: primero la principal; si no hay, la de menor orden.
            // La subconsulta evita duplicar tarjetas cuando hay varias imágenes.
            $stmtProductos = $pdo->prepare(
                'SELECT p.id, p.nombre, p.marca, p.descripcion_corta, p.estado,
                        (SELECT pi.ruta
                         FROM producto_imagenes pi
                         WHERE pi.producto_id = p.id
                         ORDER BY pi.principal DESC, pi.orden ASC, pi.id ASC
                         LIMIT 1) AS imagen_ruta
                 FROM productos p
                 WHERE p.categoria_id = ? AND p.estado = 1
                 ORDER BY p.id ASC'
            );
            $stmtProductos->bindValue(1, $idCategoria, PDO::PARAM_INT);
            $stmtProductos->execute();
            $productos = $stmtProductos->fetchAll();

            $tituloCategoria = (string) $categoria['nombre'];
            $tituloDocumento = 'ELECTRIC : ' . $tituloCategoria;
            $mostrarConsulta = true;
            $estadoPagina = $productos === [] ? 'vacio' : 'ok';
        }
    } catch (Throwable $e) {
        // Error de consulta: registrar sin exponer detalles y mostrar un
        // mensaje genérico. Nunca convertirlo en una categoría inexistente.
        error_log('electric_intranet: error al cargar la categoría — ' . $e->getMessage());
        http_response_code(500);
        $estadoPagina = 'error';
        $categoria = null;
        $productos = [];
        $tituloCategoria = 'INFORMACIÓN NO DISPONIBLE';
        $tituloDocumento = 'ELECTRIC : Información no disponible';
        $mostrarConsulta = false;
    }
}

// Categorías activas para el menú y banner compartido. Se cargan una sola vez
// por página; si la base de datos falló antes, no se reintenta la conexión.
$categoriasMenu = [];
$bannerUrl = null;

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

        $stmtBanner = $pdo->prepare(
            'SELECT valor
             FROM configuracion
             WHERE clave = ?
             LIMIT 1'
        );
        $stmtBanner->execute(['banner_imagen']);
        $bannerFila = $stmtBanner->fetch();
        if ($bannerFila) {
            $bannerUrl = electric_url_imagen_banner($bannerFila['valor'] ?? null);
        }
    } catch (Throwable $e) {
        // El menú y el banner son complementarios: si fallan, la página sigue
        // mostrando su contenido con el respaldo visual estático.
        error_log('electric_intranet: error al cargar menú o banner — ' . $e->getMessage());
        $categoriasMenu = [];
        $bannerUrl = null;
    }
}

/**
 * Construye la URL pública de una imagen de producto gestionada por el módulo
 * administrativo. Las imágenes residen fuera de la webroot y se sirven mediante
 * admin/imagen.php. Devuelve null si la ruta no es válida o está vacía.
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
 * Respaldo visual coherente con el diseño actual de la página de categoría:
 * las imágenes estáticas img/cat/p1.png ... p8.png, elegidas de forma estable
 * según el ID del producto.
 */
function electric_imagen_respaldo_producto(int $idProducto): string
{
    $indice = (($idProducto - 1) % 8 + 8) % 8 + 1;
    return 'img/cat/p' . $indice . '.png';
}

/**
 * Construye la URL pública del banner compartido administrado en la intranet.
 * Devuelve null si no hay ruta, si no cumple el formato esperado o si el
 * archivo no existe dentro de uploads; en ese caso se conserva el banner
 * estático definido por el CSS original.
 */
function electric_url_imagen_banner(?string $ruta): ?string
{
    $ruta = trim((string) $ruta);
    if ($ruta === '') {
        return null;
    }
    if (!preg_match('#^banner/[A-Za-z0-9_-]+\.(?:jpe?g|png|webp)$#i', $ruta)) {
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

    $raizConSep = rtrim($raizUploads, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (strpos($rutaFisica, $raizConSep) !== 0) {
        return null;
    }

    if (@getimagesize($rutaFisica) === false) {
        return null;
    }

    return 'admin/imagen.php?ruta=' . rawurlencode($ruta);
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
    <link rel="stylesheet" href="css/categoria.css">
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

            <!-- Menú -->
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
<?php $esCategoriaActual = $estadoPagina === 'ok' && (int) $categoria['id'] === (int) $categoriaMenu['id']; ?>
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

    <!-- ==================== CATEGORÍA ==================== -->
    <main id="categoria">
        <div class="banners-fondo banner-cat"<?php if ($bannerUrl !== null): ?> style="background-image:url('<?php echo htmlspecialchars($bannerUrl, ENT_QUOTES, 'UTF-8'); ?>');"<?php endif; ?>></div>

        <div class="contenedor">

            <!-- Título de la categoría -->
            <div class="cat-titulo">
                <span class="cat-etiqueta">CATEGORÍAS</span>
                <h4 class="popibb"><?php echo htmlspecialchars($tituloCategoria, ENT_QUOTES, 'UTF-8'); ?></h4>
            </div>

            <?php if ($estadoPagina === 'ok'): ?>
                <!-- Cuadrícula de productos publicados -->
                <div class="cat-grid">
                    <?php foreach ($productos as $producto): ?>
                        <?php
                        $idProducto = (int) $producto['id'];
                        $nombreProducto = (string) ($producto['nombre'] ?? '');
                        $marcaProducto = (string) ($producto['marca'] ?? '');
                        $urlImagen = electric_url_imagen_producto($producto['imagen_ruta'] ?? null);
                        if ($urlImagen === null) {
                            $urlImagen = electric_imagen_respaldo_producto($idProducto);
                        }
                        $altProducto = $nombreProducto;
                        if ($marcaProducto !== '') {
                            $altProducto .= ' - ' . $marcaProducto;
                        }
                        ?>
                        <div class="cat-card">
                            <figure><img src="<?php echo htmlspecialchars($urlImagen, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($altProducto, ENT_QUOTES, 'UTF-8'); ?>"></figure>
                            <p><?php echo htmlspecialchars($nombreProducto, ENT_QUOTES, 'UTF-8'); ?></p>
                            <a class="cat-ver" href="producto.php?id=<?php echo $idProducto; ?>">VER DETALLES</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($estadoPagina === 'vacio'): ?>
                <!-- Categoría activa sin productos publicados -->
                <div class="cat-grid">
                    <p style="grid-column: 1 / -1; text-align: center; font-size: 18px; line-height: 1.6; color: var(--colorTexto2); padding: 0 15px;">
                        Actualmente no hay productos disponibles en esta categoría.
                    </p>
                </div>
            <?php else: ?>
                <!-- ID inválido, categoría inexistente/inactiva o error de consulta -->
                <div class="cat-grid">
                    <p style="grid-column: 1 / -1; text-align: center; font-size: 18px; line-height: 1.6; color: var(--colorTexto2); padding: 0 15px;">
                        <?php if ($estadoPagina === '404'): ?>
                            La categoría que buscas no existe o ya no está disponible.
                        <?php else: ?>
                            No se pudo cargar la información en este momento. Inténtalo nuevamente más tarde.
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($mostrarConsulta): ?>
                <!-- Consúltanos por esta categoría -->
                <div class="cat-final">
                    <span class="cat-final-txt popib">Consúltanos por productos<br> de esta categoría</span>
                    <a class="wsp-pill" href="https://web.whatsapp.com/send?phone=51933604617&amp;text=Hola%20Electric%2C%20necesito%20informaci%C3%B3n%20sobre%20sus%20productos%20y%20servicios." target="_blank" rel="noopener"><img src="img/wsp.png" alt="Estamos en línea - Consulta por WhatsApp"></a>
                </div>
            <?php else: ?>
                <!-- Salida amigable para 404 o error de consulta -->
                <div class="cat-final">
                    <span class="cat-final-txt popib">Vuelve al inicio para<br> explorar nuestras categorías</span>
                    <a class="boton" href="index.php">VOLVER AL INICIO</a>
                </div>
            <?php endif; ?>

        </div>
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
</body>
</html>

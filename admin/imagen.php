<?php
declare(strict_types=1);

/**
 * Proxy seguro para servir imágenes almacenadas fuera de la raíz web pública.
 *
 * Los archivos reales residen en Proyelectric/uploads/ (no accesibles por HTTP
 * directamente). Este script valida estrictamente la ruta solicitada, impide
 * traversal y solo sirve archivos de imagen dentro de las carpetas permitidas.
 *
 * Uso: /electric/admin/imagen.php?ruta=categorias/abc.jpg
 *      /electric/admin/imagen.php?ruta=productos/xyz.png
 *      /electric/admin/imagen.php?ruta=banner/xyz.webp
 */

require_once __DIR__ . '/../../config/security.php';

// No exige sesión de administrador: las imágenes no son secretas, la protección
// es que los archivos nunca se sirven directamente por Apache (están fuera de
// la webroot) y este proxy controla qué se entrega.

$ruta = (string) ($_GET['ruta'] ?? '');

// Validación estricta: "categorias/archivo.ext", "productos/archivo.ext" o
// "banner/archivo.ext", sin barras invertidas, sin puntos, sin traversal.
if (!preg_match('/^(categorias|productos|banner)\/[A-Za-z0-9_-]+\.(jpg|jpeg|png|webp)$/i', $ruta)) {
    http_response_code(400);
    exit('Solicitud inválida.');
}

$raiz = realpath(__DIR__ . '/../../uploads');
$archivo = realpath($raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $ruta));

if ($archivo === false || !is_file($archivo)) {
    http_response_code(404);
    exit('Imagen no encontrada.');
}

// Asegurar que el archivo está realmente dentro del directorio de uploads.
$raizConSep = rtrim($raiz, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
if (strpos($archivo, $raizConSep) !== 0) {
    http_response_code(403);
    exit('Acceso denegado.');
}

// Información de la imagen real (valida que sea una imagen legible).
$info = @getimagesize($archivo);
if ($info === false) {
    http_response_code(400);
    exit('Archivo no es una imagen válida.');
}

header('Content-Type: ' . $info['mime']);
header('Cache-Control: public, max-age=31536000');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($archivo));
readfile($archivo);
exit;

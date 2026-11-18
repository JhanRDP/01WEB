<?php
declare(strict_types=1);

/**
 * Utilidades de subida segura de imágenes para el panel administrativo.
 *
 * Los archivos se guardan FUERA de la raíz web pública:
 *   Proyelectric/uploads/<subdir>/<nombre aleatorio>.<ext>
 *
 * Nunca se confía en el nombre original del archivo ni en la extensión
 * declarada por el cliente.
 */

require_once __DIR__ . '/../../config/security.php';

const ELECTRIC_UPLOAD_ROOT = __DIR__ . '/../../uploads';

const ELECTRIC_EXTENSIONES_PERMITIDAS = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
];

// Tamaño máximo: 2 MB
const ELECTRIC_UPLOAD_MAX_SIZE = 2 * 1024 * 1024;

// Dimensiones máximas (px)
const ELECTRIC_UPLOAD_MAX_ANCHO = 1600;
const ELECTRIC_UPLOAD_MAX_ALTO = 1600;

/**
 * Valida y guarda un archivo subido como imagen.
 *
 * @param string $campo Nombre del campo <input type="file"> en $_FILES.
 * @param string $subdir Subcarpeta dentro de uploads (ej. 'productos', 'categorias', 'banner').
 * @return array{ok:bool,ruta?:string,error?:string}
 *   ruta es relativa a uploads/, ej. 'productos/a1b2.jpg'
 */
function electric_guardar_imagen(string $campo, string $subdir): array
{
    if (!isset($_FILES[$campo]) || !is_array($_FILES[$campo])) {
        return ['ok' => false, 'error' => 'No se recibió el archivo.'];
    }

    $archivo = $_FILES[$campo];

    // Errores de PHP en la subida
    $error = (int) ($archivo['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        $mensajes = [
            UPLOAD_ERR_INI_SIZE   => 'El archivo excede el tamaño permitido por el servidor.',
            UPLOAD_ERR_FORM_SIZE  => 'El archivo excede el tamaño máximo permitido.',
            UPLOAD_ERR_PARTIAL    => 'El archivo se subió parcialmente.',
            UPLOAD_ERR_NO_FILE    => 'No se seleccionó ningún archivo.',
            UPLOAD_ERR_NO_TMP_DIR => 'Falta carpeta temporal del servidor.',
            UPLOAD_ERR_CANT_WRITE => 'No se pudo escribir el archivo.',
            UPLOAD_ERR_EXTENSION  => 'La subida fue detenida por una extensión de PHP.',
        ];
        return ['ok' => false, 'error' => $mensajes[$error] ?? 'Error al subir el archivo.'];
    }

    $nombreOriginal = (string) ($archivo['name'] ?? '');
    $rutaTmp = $archivo['tmp_name'] ?? '';
    $tamaño = (int) ($archivo['size'] ?? 0);

    if ($rutaTmp === '' || !is_uploaded_file($rutaTmp)) {
        return ['ok' => false, 'error' => 'Archivo no válido.'];
    }

    if ($tamaño <= 0) {
        return ['ok' => false, 'error' => 'El archivo está vacío.'];
    }

    if ($tamaño > ELECTRIC_UPLOAD_MAX_SIZE) {
        return ['ok' => false, 'error' => 'El archivo es demasiado grande (máximo 2 MB).'];
    }

    // Extensión del nombre original (solo indicativa, no determinante).
    $extOriginal = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION) ?? '');

    // MIME real mediante finfo (lee el contenido del archivo).
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeReal = $finfo->file($rutaTmp);
    if ($mimeReal === false) {
        return ['ok' => false, 'error' => 'No se pudo analizar el archivo.'];
    }

    // Solo imágenes permitidas, nunca SVG ni PHP ni ejecutables.
    if (!array_key_exists($extOriginal, ELECTRIC_EXTENSIONES_PERMITIDAS)
        || !isset(ELECTRIC_EXTENSIONES_PERMITIDAS[$extOriginal])
        || ELECTRIC_EXTENSIONES_PERMITIDAS[$extOriginal] !== $mimeReal) {
        return ['ok' => false, 'error' => 'Formato no permitido. Use JPEG, PNG o WebP.'];
    }

    // Validar que sea una imagen legible y obtener dimensiones.
    $info = @getimagesize($rutaTmp);
    if ($info === false) {
        return ['ok' => false, 'error' => 'El archivo no es una imagen válida.'];
    }

    $ancho = (int) ($info[0] ?? 0);
    $alto  = (int) ($info[1] ?? 0);
    if ($ancho > ELECTRIC_UPLOAD_MAX_ANCHO || $alto > ELECTRIC_UPLOAD_MAX_ALTO) {
        return ['ok' => false, 'error' => 'La imagen supera las dimensiones máximas (1600x1600 px).'];
    }

    // Directorio de destino (fuera de la webroot).
    $destinoDir = realpath(ELECTRIC_UPLOAD_ROOT . DIRECTORY_SEPARATOR . $subdir);
    if ($destinoDir === false || !is_dir($destinoDir)) {
        @mkdir(ELECTRIC_UPLOAD_ROOT . DIRECTORY_SEPARATOR . $subdir, 0700, true);
        $destinoDir = realpath(ELECTRIC_UPLOAD_ROOT . DIRECTORY_SEPARATOR . $subdir);
    }
    if ($destinoDir === false || !is_dir($destinoDir)) {
        return ['ok' => false, 'error' => 'No se pudo preparar el destino de almacenamiento.'];
    }

    // Nombre aleatorio: nunca usar el nombre del cliente.
    $extension = ELECTRIC_EXTENSIONES_PERMITIDAS[$extOriginal] === 'image/jpeg' ? 'jpg' : $extOriginal;
    $nombreFinal = bin2hex(random_bytes(16)) . '.' . $extension;

    $rutaFinal = $destinoDir . DIRECTORY_SEPARATOR . $nombreFinal;

    // Mover el archivo subido (mejor esfuerzo; si falla, copiar).
    $movido = @move_uploaded_file($rutaTmp, $rutaFinal);
    if (!$movido && !@copy($rutaTmp, $rutaFinal)) {
        return ['ok' => false, 'error' => 'No se pudo guardar el archivo.'];
    }
    @unlink($rutaTmp);

    // Verificar que el archivo guardado sigue siendo una imagen válida.
    $info2 = @getimagesize($rutaFinal);
    if ($info2 === false) {
        @unlink($rutaFinal);
        return ['ok' => false, 'error' => 'El archivo guardado no es una imagen válida.'];
    }

    // Ruta relativa para guardar en BD: 'subdir/nombre.ext'
    $rutaRelativa = $subdir . '/' . $nombreFinal;

    return ['ok' => true, 'ruta' => $rutaRelativa];
}

/**
 * Elimina un archivo de imagen gestionado por este módulo (mejor esfuerzo).
 * $rutaRelativa debe ser 'subdir/nombre.ext' y se valida estrictamente.
 */
function electric_eliminar_imagen(string $rutaRelativa): bool
{
    if (!preg_match('/^(categorias|productos|banner)\/[A-Za-z0-9_-]+\.(jpg|jpeg|png|webp)$/i', $rutaRelativa)) {
        return false;
    }

    $raiz = realpath(ELECTRIC_UPLOAD_ROOT);
    $archivo = realpath($raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rutaRelativa));
    if ($archivo === false || !is_file($archivo)) {
        return false;
    }

    $raizConSep = rtrim($raiz, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (strpos($archivo, $raizConSep) !== 0) {
        return false;
    }

    return (bool) @unlink($archivo);
}

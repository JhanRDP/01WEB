<?php
declare(strict_types=1);

/**
 * Endpoint público mínimo para el menú de categorías de las páginas estáticas.
 *
 * Devuelve únicamente las categorías activas con el formato que consume
 * js/menu.js. No acepta parámetros ni expone datos internos; ante cualquier
 * fallo responde con una lista vacía para no romper la navegación.
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

$categorias = [];

try {
    require_once __DIR__ . '/../config/db.php';

    $stmt = $pdo->prepare(
        'SELECT id, nombre
         FROM categorias
         WHERE estado = 1
         ORDER BY id ASC'
    );
    $stmt->execute();

    foreach ($stmt->fetchAll() as $fila) {
        $id = (int) ($fila['id'] ?? 0);
        $nombre = trim((string) ($fila['nombre'] ?? ''));

        if ($id <= 0 || $nombre === '') {
            continue;
        }

        $categorias[] = [
            'id' => $id,
            'nombre' => $nombre,
            'url' => 'categoria.php?id=' . $id,
        ];
    }
} catch (Throwable $e) {
    error_log('electric_intranet: error al generar el menú de categorías — ' . $e->getMessage());
    $categorias = [];
}

echo json_encode($categorias, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

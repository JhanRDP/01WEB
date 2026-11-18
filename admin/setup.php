<?php
declare(strict_types=1);

/**
 * Instalador público deshabilitado.
 *
 * El alta del primer administrador se realiza exclusivamente mediante el
 * instalador CLI privado, fuera de la raíz web:
 *
 *     php ../config/install_admin.php --authorize ...
 *
 * Este archivo se conserva únicamente para responder 404 y no revelar que
 * existe un procedimiento de instalación.
 */

http_response_code(404);
header('Content-Type: text/plain; charset=UTF-8');
exit('404 Not Found');

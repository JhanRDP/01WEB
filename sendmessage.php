<?php
declare(strict_types=1);

/**
 * Endpoint del formulario CONTÁCTANOS (solo POST).
 * Valida en el servidor y delega el envío por SMTP.
 * Responde siempre con redirección (patrón PRG):
 *   contacto.html?envio=ok    → el servidor SMTP aceptó el mensaje
 *   contacto.html?envio=error → validación fallida o fallo de envío
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: contacto.html');
    exit;
}

require __DIR__ . '/../servidor/lib/mail_formulario.php';

$errores = [];
$datos   = validar_formulario($_POST, $errores);

if ($datos === null) {
    error_log('sendmessage: datos inválidos — ' . implode(' | ', $errores));
    header('Location: contacto.html?envio=error');
    exit;
}

$ok = enviar_consulta($datos);

header('Location: contacto.html?' . ($ok ? 'envio=ok' : 'envio=error'));
exit;

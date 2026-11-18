<?php
declare(strict_types=1);

/**
 * Página de inicio de sesión del administrador.
 * - CSRF: genera y valida un token por sesión en formularios POST.
 * - Contraseña: verifica con password_verify() contra el hash almacenado.
 * - Éxito: regenera ID de sesión (session_regenerate_id(true)) y rota el CSRF.
 * - Fuerza bruta: limita intentos por usuario y por IP (config/security.php).
 * - Errores: mensajes genéricos, sin revelar si el usuario existe.
 */

require __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/security.php';

electric_session_start();

// Hash ficticio para igualar el tiempo de respuesta cuando el usuario no existe.
// No corresponde a ninguna contraseña real.
const ELECTRIC_DUMMY_HASH = '$2y$10$tBTvct0fgdUN9rPau56zKeJJNZ6u/ydGwDEisDrgKFlzNQ/UgMa/a';

// Si ya está autenticado, redirigir al panel
if (!empty($_SESSION['admin_id']) && !empty($_SESSION['admin_usuario'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$aviso = '';

if (isset($_GET['expirado'])) {
    $aviso = 'Su sesión ha caducado por inactividad. Vuelva a iniciar sesión.';
} elseif (isset($_GET['logout'])) {
    $aviso = 'Ha cerrado sesión correctamente.';
}

// Generar token CSRF si no existe
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Procesar intento de login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar token CSRF
    $token_post = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token_post)) {
        $error = 'Solicitud inválida. Intente de nuevo.';
    } else {
        $usuario = trim((string) ($_POST['usuario'] ?? ''));
        $clave   = (string) ($_POST['clave'] ?? '');
        $ip      = electric_client_ip();

        // Validación básica de entrada
        if ($usuario === '' || $clave === '') {
            $error = 'Usuario y contraseña son obligatorios.';
        } elseif (strlen($usuario) > 80 || strlen($clave) > 255) {
            $error = 'Usuario o contraseña incorrectos.';
        } else {
            $estado = electric_throttle_check($usuario, $ip);

            if ($estado['blocked']) {
                $minutos = max(1, (int) ceil($estado['retry_after'] / 60));
                $error = 'Demasiados intentos fallidos. Intente de nuevo en '
                    . $minutos . ' minuto(s).';
            } else {
                try {
                    $stmt = $pdo->prepare(
                        'SELECT id, usuario, password_hash, nombre_completo
                         FROM administradores
                         WHERE usuario = ? LIMIT 1'
                    );
                    $stmt->execute([$usuario]);
                    $admin = $stmt->fetch();

                    // Si el usuario no existe se verifica igualmente contra un hash
                    // ficticio para no revelar su existencia por temporización.
                    $hashVerificar = is_array($admin)
                        ? (string) $admin['password_hash']
                        : ELECTRIC_DUMMY_HASH;

                    $claveCorrecta = password_verify($clave, $hashVerificar);

                    if (is_array($admin) && $claveCorrecta) {
                        // Login exitoso: limpiar contador y regenerar ID de sesión.
                        electric_throttle_success($usuario);
                        session_regenerate_id(true);

                        // Rotar el token CSRF tras autenticarse.
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                        $_SESSION['admin_id']        = (int) $admin['id'];
                        $_SESSION['admin_usuario']   = $admin['usuario'];
                        $_SESSION['admin_nombre']    = $admin['nombre_completo'];
                        $_SESSION['admin_logued']    = true;
                        $_SESSION['last_activity']   = time();

                        header('Location: index.php');
                        exit;
                    }

                    electric_throttle_failure($usuario, $ip);
                    $error = 'Usuario o contraseña incorrectos.';
                } catch (Throwable $e) {
                    error_log('electric_intranet: error en login — ' . $e->getMessage());
                    $error = 'Error interno. Intente de nuevo más tarde.';
                }
            }
        }
    }

    // Rotar el token CSRF tras cualquier intento no exitoso.
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ELECTRIC — Acceso administrador</title>
    <link rel="stylesheet" href="../vendor/fontawesome/css/all.css">
    <link rel="stylesheet" href="../vendor/bootstrap.min.css">
    <link rel="stylesheet" href="../css/stilos.css">
    <style>
        body {
            font-family: 'Nunito', sans-serif;
            background: #081130;
            color: #2A2A2A;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
        }
        .login-card {
            background: #fff;
            border-radius: 10px;
            padding: 40px;
            max-width: 400px;
            width: 100%;
            box-shadow: 0 16px 48px rgba(0,0,0,.25);
        }
        .login-card h2 {
            font-family: 'Poppins', sans-serif;
            color: #081130;
            text-align: center;
            margin-bottom: 24px;
        }
        .login-card label {
            display: block;
            margin-bottom: 8px;
            font-weight: 700;
            color: #081130;
        }
        .login-card input {
            width: 100%;
            padding: 10px;
            margin-bottom: 16px;
            border: 1px solid #B1B1B1;
            border-radius: 6px;
            font-size: 16px;
            box-sizing: border-box;
        }
        .login-card button {
            width: 100%;
            padding: 12px;
            background: #F8AD00;
            border: none;
            border-radius: 30px;
            color: #081130;
            font-family: 'Poppins', sans-serif;
            font-weight: 700;
            font-size: 18px;
            cursor: pointer;
        }
        .login-card button:hover {
            background: #081130;
            color: #fff;
        }
        .error-msg {
            background: #ffe0e0;
            color: #833;
            padding: 10px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-size: 14px;
            text-align: center;
        }
        .info-msg {
            background: #fff3cd;
            color: #856404;
            padding: 10px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-size: 14px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <h2>ELECTRIC</h2>
        <p style="text-align:center; color:#515151; margin-bottom:24px;">Panel administrativo — Inicio de sesión</p>

        <?php if ($aviso): ?>
            <div class="info-msg"><?php echo htmlspecialchars($aviso, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error-msg"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <form method="post" action="login.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">

            <label for="usuario">Usuario:</label>
            <input type="text" name="usuario" id="usuario" required maxlength="80" autocomplete="username">

            <label for="clave">Contraseña:</label>
            <input type="password" name="clave" id="clave" required maxlength="255" autocomplete="current-password">

            <button type="submit">Ingresar</button>
        </form>
    </div>
</body>
</html>

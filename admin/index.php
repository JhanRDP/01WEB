<?php
declare(strict_types=1);

/**
 * Página inicial del panel administrativo.
 * Requiere sesión de administrador válida (auth_check.php).
 */

require __DIR__ . '/auth_check.php';

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ELECTRIC — Panel Administrativo</title>
    <link rel="stylesheet" href="../vendor/fontawesome/css/all.css">
    <link rel="stylesheet" href="../vendor/bootstrap.min.css">
    <link rel="stylesheet" href="../css/stilos.css">
    <link rel="stylesheet" href="css/admin.css">
    <style>
        body {
            font-family: 'Nunito', sans-serif;
            background: #0C101B;
            color: #fff;
            margin: 0;
        }
        .header {
            background: #081130;
            padding: 20px 0;
            border-bottom: 1px solid #F8AD00;
        }
        .header .contenedor {
            max-width: 1230px;
            margin: 0 auto;
            padding: 0 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 {
            font-family: 'Poppins', sans-serif;
            color: #F8AD00;
            margin: 0;
            font-size: 24px;
        }
        .user-info {
            color: #fff;
            font-size: 15px;
        }
        .main {
            max-width: 1230px;
            margin: 40px auto;
            padding: 0 15px;
        }
        .card {
            background: #fff;
            color: #2A2A2A;
            border-radius: 10px;
            padding: 30px;
            margin-bottom: 24px;
        }
        .card h2 {
            font-family: 'Poppins', sans-serif;
            color: #081130;
            margin-top: 0;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #F8AD00;
            color: #081130;
            text-decoration: none;
            border-radius: 30px;
            font-family: 'Poppins', sans-serif;
            font-weight: 700;
            margin-right: 12px;
        }
        .btn:hover {
            background: #081130;
            color: #fff;
        }
        .btn-outline {
            background: transparent;
            border: 2px solid #F8AD00;
        }
        .btn-outline:hover {
            background: #F8AD00;
        }
        .notice {
            background: #fff3cd;
            border: 1px solid #ffeeba;
            color: #856404;
            padding: 12px;
            border-radius: 6px;
            font-size: 14px;
            margin-top: 24px;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="contenedor">
            <h1>ELECTRIC <small style="font-weight:400; font-size:14px; color:#ccc;">Panel Administrativo</small></h1>
            <nav class="admin-nav">
                <a href="index.php" class="activo">Inicio</a>
                <a href="categorias.php">Categorías</a>
                <a href="productos.php">Productos</a>
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

    <div class="main">
        <div class="card">
            <h2>Bienvenido, <?php echo htmlspecialchars($_SESSION['admin_nombre'] ?? $_SESSION['admin_usuario'] ?? '', ENT_QUOTES, 'UTF-8'); ?></h2>
            <p>Desde este panel podrá gestionar categorías y productos de la web ELECTRIC sin editar código.</p>

            <a class="btn" href="categorias.php">Gestionar categorías</a>
            <a class="btn btn-outline" href="productos.php">Gestionar productos</a>
        </div>

        <div class="notice">
            <strong>Panel activo:</strong> Desde aquí puede gestionar las categorías y los productos.
            Los cambios de contenido y de estado se reflejan en la web pública.
        </div>
    </div>
</body>
</html>

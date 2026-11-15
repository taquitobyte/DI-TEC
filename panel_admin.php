<?php
session_start();

// Verificar sesión y rol
if (!isset($_SESSION['NombreUsuario']) || !isset($_SESSION['RolID']) || $_SESSION['RolID'] != 1) {
    header("Location: login.html");
    exit();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Administrador - DI TEC</title>
<style>
    body {
            margin: 0;
            font-family: 'Segoe UI', sans-serif;
            background-color: #f4f6f9;
            color: #333;
    }

    /* SIDEBAR ORIGINAL */
    .sidebar {
        background-color: #003366;
        color: white;
        width: 240px;
        height: 100vh;
        position: fixed;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }
    .sidebar h2 {
        text-align: center;
        padding: 20px;
        background-color: #00264d;
        margin: 0;
    }
    .sidebar a {
        color: white;
        text-decoration: none;
        padding: 15px 20px;
        display: block;
        transition: background 0.3s;
    }
    .sidebar a:hover {
        background-color: #ff6600;
    }
    .logout {
        text-align: center;
        background-color: #ff6600;
        padding: 15px;
    }
    .logout a {
        color: white;
        text-decoration: none;
        font-weight: bold;
    }

    /* CONTENIDO */
    .content {
        margin-left: 240px;
        padding: 30px;
    }

    .pedido-box {
        background: white;
        padding: 20px;
        border-radius: 10px;
        margin-bottom: 25px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    }

    .estado {
        font-weight: bold;
        padding: 5px 10px;
        border-radius: 6px;
        color: white;
    }
    .Pendiente { background:#d35400; }
    .En\ Camino { background:#2980b9; }
    .Entregado { background:#27ae60; }
</style>
</head>
<body>
    <div class="sidebar">
        <div>
            <h2>Admin DI-TEC</h2>
        <a href="gestion_usuarios.php">🧑‍💼 Gestión de Usuarios</a>
        <a href="gestion_proveedores.php">🏬 Gestión de Proveedores</a>
        <a href="gestion_pedidos.php">🗂️ Gestión de Pedidos</a>
        <a href="estadisticas.php">📈 Estadísticas</a>
        </div>
        <div class="logout">
            <a href="logout.php">🚪 Cerrar Sesión</a>
        </div>
    </div>

    <div class="content">
        <h1>Bienvenido, <?php echo $_SESSION['NombreUsuario']; ?> 👋</h1>
        <p>Desde aquí puedes administrar usuarios, proveedores y la configuración del sistema.</p>
    </div>
</body>
</html>
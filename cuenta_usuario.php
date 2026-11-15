<?php
session_start();
include 'conexion.php';
include 'seguridad.php';

if (!isset($_SESSION['UsuarioID'])) {
    header("Location: login.html");
    exit();
}

$usuarioID = $_SESSION['UsuarioID'];

// Si se envía el formulario de actualización
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $nombre = $_POST['nombre'];
    $telefono = $_POST['telefono'];
    $direccion = $_POST['direccion'];
    $contrasena = $_POST['contrasena'];

    try {
        if (!empty($contrasena)) {
            $sql = "UPDATE Usuario 
                    SET NombreUsuario = :nombre, TelefonoUsuario = :telefono, DireccionUsuario = :direccion, ContrasenaUsuario = :contrasena
                    WHERE UsuarioID = :id";
            $stmt = $conn->prepare($sql);
            $contrasenaHash = hashear_contrasena($contrasena);
            $stmt->bindParam(':contrasena', $contrasenaHash);
        } else {
            $sql = "UPDATE Usuario 
                    SET NombreUsuario = :nombre, TelefonoUsuario = :telefono, DireccionUsuario = :direccion
                    WHERE UsuarioID = :id";
            $stmt = $conn->prepare($sql);
        }

        $stmt->bindParam(':nombre', $nombre);
        $stmt->bindParam(':telefono', $telefono);
        $stmt->bindParam(':direccion', $direccion);
        $stmt->bindParam(':id', $usuarioID);
        $stmt->execute();

        $mensaje = "✅ Información actualizada correctamente.";
    } catch (PDOException $e) {
        $mensaje = "❌ Error al actualizar: " . $e->getMessage();
    }
}

// Obtener información del usuario
try {
    $sql = "SELECT * FROM Usuario WHERE UsuarioID = :id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':id', $usuarioID);
    $stmt->execute();
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error al cargar datos: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mi Cuenta - DI-TEC</title>
<style>
    body {
        font-family: 'Segoe UI', sans-serif;
        background-color: #f4f6f9;
        margin: 0;
        color: #333;
    }
    header {
        background-color: #003366;
        color: white;
        padding: 15px 40px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    header h1 {
        margin: 0;
        font-size: 22px;
        letter-spacing: 1px;
    }
    header nav a {
        color: white;
        text-decoration: none;
        margin-left: 25px;
        font-weight: bold;
    }
    header nav a:hover {
        color: #66b2ff;
    }
    .container {
        width: 50%;
        margin: 40px auto;
        background: white;
        padding: 30px;
        border-radius: 10px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    h2 {
        color: #003366;
        border-bottom: 3px solid #007bff;
        display: inline-block;
        padding-bottom: 5px;
        margin-bottom: 20px;
    }
    label {
        display: block;
        font-weight: bold;
        margin-top: 10px;
        color: #003366;
    }
    input {
        width: 100%;
        padding: 10px;
        margin-top: 5px;
        border: 1px solid #ccc;
        border-radius: 8px;
        font-size: 15px;
    }
    button {
        margin-top: 20px;
        background-color: #007bff;
        color: white;
        border: none;
        padding: 12px 20px;
        border-radius: 8px;
        font-size: 16px;
        cursor: pointer;
        transition: background 0.3s;
    }
    button:hover {
        background-color: #005bb5;
    }
    .mensaje {
        margin-top: 20px;
        font-weight: bold;
    }
</style>
</head>
<body>

<header>
    <h1>DI-TEC</h1>
    <nav>
            <a href="panel_usuario.php">🏠 Inicio</a>
            <a href="ver_carrito.php">🛒 Ver Carrito</a>
            <a href="mis_pedidos.php">📦 Mis Pedidos</a>
            <a href="cuenta_usuario.php">⚙️ Cuenta</a>
            <a href="logout.php">🚪 Cerrar Sesión</a>
    </nav>
</header>

<div class="container">
    <h2>Mi Cuenta</h2>
    <?php if (isset($mensaje)) echo "<p class='mensaje'>$mensaje</p>"; ?>

    <form method="POST" action="">
        <label>Nombre:</label>
        <input type="text" name="nombre" value="<?php echo htmlspecialchars($usuario['NombreUsuario']); ?>" required>

        <label>Correo electrónico:</label>
        <input type="email" value="<?php echo htmlspecialchars($usuario['EmailUsuario']); ?>" disabled>

        <label>Teléfono:</label>
        <input type="text" name="telefono" value="<?php echo htmlspecialchars($usuario['TelefonoUsuario']); ?>">

        <label>Dirección:</label>
        <input type="text" name="direccion" value="<?php echo htmlspecialchars($usuario['DireccionUsuario']); ?>">

        <label>Nueva contraseña (opcional):</label>
        <input type="password" name="contrasena" placeholder="••••••••••">

        <button type="submit">Actualizar datos</button>
    </form>
</div>

</body>
</html>

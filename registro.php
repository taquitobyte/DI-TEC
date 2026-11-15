<?php
include 'conexion.php'; // Conexión a la base de datos
include 'seguridad.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nombre = $_POST['nombre'];
    $email = $_POST['email'];
    $telefono = $_POST['telefono'];
    $direccion = $_POST['direccion'];
    $contrasena = $_POST['contrasena'];

    // Rol por defecto: 2 = Usuario normal
    $rol_id = 2;

    try {
        $query = "INSERT INTO Usuario 
        (NombreUsuario, EmailUsuario, TelefonoUsuario, DireccionUsuario, ContrasenaUsuario, RolID, FechaRegistro)
        VALUES (:nombre, :email, :telefono, :direccion, :contrasena, :rol, GETDATE())";

        $stmt = $conn->prepare($query);

        // Asignar valores
        $stmt->bindParam(':nombre', $nombre);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':telefono', $telefono);
        $stmt->bindParam(':direccion', $direccion);
        $contrasenaHash = hashear_contrasena($contrasena); // se guarda cifrada
        $stmt->bindParam(':contrasena', $contrasenaHash);
        $stmt->bindParam(':rol', $rol_id);

        // Ejecutar y verificar
        if ($stmt->execute()) {
            echo "<h3 style='color:green;'>✅ Usuario registrado correctamente.</h3>";
            echo "<p><a href='login.html'>Ir a iniciar sesión</a></p>";
        } else {
            echo "<h3 style='color:red;'>❌ Error al registrar usuario.</h3>";
            print_r($stmt->errorInfo());
        }

    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            echo "<h3 style='color:red;'>⚠️ Ese correo ya está registrado.</h3>";
            echo "<p><a href='login.html'>Iniciar sesión</a> · <a href='register_usuario.html'>Intentar con otro correo</a></p>";
        } else {
            echo "<h3 style='color:red;'>❌ Error en la base de datos: " . $e->getMessage() . "</h3>";
        }
    }

} else {
    echo "Método no permitido.";
}
?>

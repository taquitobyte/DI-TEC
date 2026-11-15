<?php
include 'conexion.php';
include 'seguridad.php';

if (!isset($_GET['token'])) {
    alerta_redirigir("Enlace no válido. Solicita un nuevo correo de recuperación.", "forgot.html");
}

$token = $_GET['token'];

// Verificar si el token existe y no ha expirado
$stmt = $conn->prepare("SELECT * FROM Usuario WHERE token_recuperacion = ? AND token_expira > GETDATE()");
$stmt->execute([$token]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$usuario) {
    alerta_redirigir("El enlace es inválido o ya expiró. Solicita uno nuevo.", "forgot.html");
}

$error = "";

// Procesar nueva contraseña
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $nueva = $_POST['nueva_contrasena'] ?? '';
    $confirmar = $_POST['confirmar_contrasena'] ?? '';

    if ($nueva === '' || $confirmar === '') {
        $error = "Completa todos los campos.";
    } elseif ($nueva !== $confirmar) {
        $error = "Las contraseñas no coinciden.";
    } elseif (strlen($nueva) < 6) {
        $error = "La contraseña debe tener al menos 6 caracteres.";
    } else {
        // Actualizar la contraseña (se guarda cifrada) e invalidar el enlace
        $stmt = $conn->prepare("UPDATE Usuario SET ContrasenaUsuario = ?, token_recuperacion = NULL, token_expira = NULL WHERE token_recuperacion = ?");
        $stmt->execute([hashear_contrasena($nueva), $token]);

        alerta_redirigir("✅ Contraseña actualizada correctamente. Ya puedes iniciar sesión.", "login.html");
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Restablecer contraseña - DI-TEC</title>
  <link rel="stylesheet" href="estilos.css">

  <style>
    body {
      background-color: #f4f6f9;
      font-family: 'Segoe UI', sans-serif;
      margin: 0;
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
    }
    .login-container {
      background-color: white;
      width: 400px;
      max-width: calc(100% - 32px);
      padding: 40px;
      border-radius: 18px;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
      text-align: center;
      box-sizing: border-box;
    }
    .logo { width: 250px; max-width: 100%; margin-bottom: 10px; }
    h1 { color: #0066ff; font-size: 22px; margin-bottom: 10px; }
    p.info { color: #555; font-size: 14px; margin-bottom: 25px; }
    input[type="password"] {
      width: 100%;
      padding: 12px;
      border: 1px solid #ccc;
      border-radius: 6px;
      margin-bottom: 15px;
      font-size: 15px;
      outline: none;
      transition: border 0.3s;
      box-sizing: border-box;
    }
    input[type="password"]:focus { border-color: #0066ff; }
    .btn-login {
      background-color: #0066ff;
      color: white;
      border: none;
      padding: 12px;
      width: 100%;
      border-radius: 6px;
      cursor: pointer;
      font-weight: bold;
      transition: background 0.3s;
    }
    .btn-login:hover { background-color: #004de6; }
    .error {
      background: #ffe3e0;
      border-left: 5px solid #c0392b;
      color: #7b241c;
      padding: 10px 12px;
      border-radius: 6px;
      margin-bottom: 15px;
      font-size: 14px;
      text-align: left;
    }
    .links { margin-top: 15px; display: flex; justify-content: space-between; font-size: 14px; }
    .links a { color: #0066ff; text-decoration: none; transition: color 0.3s; }
    .links a:hover { color: #ff6600; }
  </style>
</head>

<body>
  <div class="login-container">
    <img src="logo.png" alt="DI-TEC Logo" class="logo">
    <h1>Crea tu nueva contraseña</h1>
    <p class="info">Escribe una contraseña nueva para tu cuenta (mínimo 6 caracteres).</p>

    <?php if ($error !== ""): ?>
      <div class="error">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
      <input type="password" name="nueva_contrasena" placeholder="Nueva contraseña" required autofocus>
      <input type="password" name="confirmar_contrasena" placeholder="Confirmar contraseña" required>
      <button type="submit" class="btn-login">Actualizar contraseña</button>

      <div class="links">
        <a href="login.html">Volver al inicio</a>
        <a href="forgot.html">Pedir otro enlace</a>
      </div>
    </form>
  </div>
</body>
</html>

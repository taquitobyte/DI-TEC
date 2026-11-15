<?php
include 'conexion.php';
include 'seguridad.php';
session_start();

/**
 * Muestra una alerta en el navegador y regresa al formulario de login.
 */
function volver_al_login(string $mensaje): void
{
    header('Content-Type: text/html; charset=UTF-8');
    $m = json_encode($mensaje, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    echo "<!DOCTYPE html><html lang=\"es\"><head><meta charset=\"UTF-8\"><title>DI-TEC</title></head><body>"
       . "<script>alert($m); window.location.href = 'login.html';</script>"
       . "<noscript><p>" . htmlspecialchars($mensaje) . " <a href=\"login.html\">Volver al login</a></p></noscript>"
       . "</body></html>";
    exit();
}

$email = trim($_POST['email'] ?? '');
$contrasena = $_POST['contrasena'] ?? '';

if ($email === '' || $contrasena === '') {
    volver_al_login("Por favor completa todos los campos.");
}

try {
    $stmt = $conn->prepare("SELECT * FROM Usuario WHERE EmailUsuario = ?");
    $stmt->execute([$email]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        volver_al_login("Esa cuenta no existe. Verifica el correo o regístrate.");
    }

    // Verifica la contraseña cifrada (acepta también las antiguas en texto plano)
    if (!verificar_contrasena($contrasena, $usuario['ContrasenaUsuario'])) {
        volver_al_login("Contraseña incorrecta.");
    }

    // Si la contraseña seguía guardada en texto plano, se cifra ahora
    if (necesita_rehash($usuario['ContrasenaUsuario'])) {
        $upd = $conn->prepare("UPDATE Usuario SET ContrasenaUsuario = ? WHERE UsuarioID = ?");
        $upd->execute([hashear_contrasena($contrasena), $usuario['UsuarioID']]);
    }

    $destinos = [1 => 'panel_admin.php', 2 => 'panel_usuario.php', 3 => 'panel_proveedor.php'];
    if (!isset($destinos[(int) $usuario['RolID']])) {
        volver_al_login("Tu cuenta tiene un rol no reconocido. Contacta al administrador.");
    }

    session_regenerate_id(true);
    $_SESSION['UsuarioID'] = $usuario['UsuarioID'];
    $_SESSION['NombreUsuario'] = $usuario['NombreUsuario'];
    $_SESSION['RolID'] = $usuario['RolID'];
    header("Location: " . $destinos[(int) $usuario['RolID']]);
    exit();

} catch (PDOException $e) {
    volver_al_login("No se pudo iniciar sesión por un error en la base de datos. Intenta de nuevo.");
}

<?php
include 'conexion.php';
include 'seguridad.php';
require __DIR__ . '/lib/PHPMailer/autoload.php'; // PHPMailer incluido en el proyecto

$archivoConfig = __DIR__ . '/config_correo.php';
if (!file_exists($archivoConfig)) {
    alerta_redirigir("No se pudo enviar el correo: falta configurar config_correo.php en el servidor.", "forgot.html");
}
$cfg = require $archivoConfig;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"] ?? '');

    if (empty($email)) {
        alerta_redirigir("Por favor ingresa tu correo electrónico.", "forgot.html");
    }

    // Verificar si el correo existe en la tabla Usuario
    $stmt = $conn->prepare("SELECT * FROM Usuario WHERE EmailUsuario = ?");
    $stmt->execute([$email]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        alerta_redirigir("Esa cuenta no existe. Verifica el correo o regístrate.", "forgot.html");
    }

    // Generar token único de recuperación
    $token = bin2hex(random_bytes(16));
    $stmt = $conn->prepare("UPDATE Usuario SET token_recuperacion = ?, token_expira = DATEADD(MINUTE, 30, GETDATE()) WHERE EmailUsuario = ?");
    $stmt->execute([$token, $email]);

    // Enlace para restablecer contraseña
    $link = rtrim($cfg['base_url'], '/') . "/reset_password.php?token=$token";

    // Configurar correo con PHPMailer
    $mail = new PHPMailer(true);

    try {
        // Configuración SMTP (Gmail)
                $mail->isSMTP();
                $mail->Host = $cfg['host'];
                $mail->SMTPAuth = true;
                $mail->Username = $cfg['usuario'];
                $mail->Password = $cfg['clave'];
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = (int) $cfg['port'];

            $mail->setFrom($cfg['usuario'], $cfg['nombre_remitente']);
            $mail->addAddress($email);
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = '🔐 Recupera tu contraseña - DI-TEC';
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; color: #333;'>
                <h2 style='color: #003366;'>Recuperar tu contraseña</h2>
                <p>Has solicitado restablecer tu contraseña. Da clic en el siguiente enlace:</p>
                <p><a href='$link' style='background-color:#003366; color:white; padding:10px 15px; border-radius:5px; text-decoration:none;'>Restablecer contraseña</a></p>
                <p>Este enlace expirará en <strong>30 minutos</strong>.</p>
                <hr>
                <p style='font-size:12px; color:#777;'>Si no solicitaste este cambio, puedes ignorar este mensaje.</p>
            </div>
        ";

        // Enviar correo
        $mail->send();
        alerta_redirigir("✅ Se envió un correo con las instrucciones para cambiar tu contraseña. Revisa tu bandeja (y spam).", "login.html");
    } catch (Exception $e) {
        alerta_redirigir("No se pudo enviar el correo. Inténtalo de nuevo más tarde.", "forgot.html");
    }
} else {
    header("Location: forgot.html");
    exit();
}
?>
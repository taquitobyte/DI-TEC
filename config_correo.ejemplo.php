<?php
/*
 * Configuración del correo (recuperación de contraseña).
 *
 * 1. Copia este archivo y llámalo  config_correo.php
 * 2. Llena tus datos. En Gmail se usa una "contraseña de aplicación"
 *    (Cuenta de Google > Seguridad > Verificación en 2 pasos > Contraseñas de aplicaciones).
 * 3. config_correo.php NO se sube a Git (está en .gitignore).
 */
return [
    'host'             => 'smtp.gmail.com',
    'port'             => 587,
    'usuario'          => 'tu_correo@gmail.com',
    'clave'            => 'tu contraseña de aplicación',
    'nombre_remitente' => 'DI-TEC Soporte',
    // Dirección donde corre el sitio (se usa para armar el enlace del correo)
    'base_url'         => 'http://localhost:8000',
];

<?php
/*
 * Autoload de PHPMailer incluido en el proyecto (no requiere Composer).
 * Uso:  require __DIR__ . '/lib/PHPMailer/autoload.php';
 */
spl_autoload_register(function ($clase) {
    $prefijo = 'PHPMailer\\PHPMailer\\';
    if (strncmp($clase, $prefijo, strlen($prefijo)) !== 0) {
        return;
    }
    $archivo = __DIR__ . '/src/' . substr($clase, strlen($prefijo)) . '.php';
    if (is_file($archivo)) {
        require $archivo;
    }
});

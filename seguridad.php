<?php
/*
 * Funciones para manejar las contraseñas de forma segura.
 * Las contraseñas se guardan cifradas con password_hash() (bcrypt) y se
 * comprueban con password_verify().
 *
 * Compatibilidad: si un usuario todavía tiene su contraseña guardada en
 * texto plano (de antes del cambio), el inicio de sesión la acepta una vez
 * y la reemplaza automáticamente por su versión cifrada.
 */

function hashear_contrasena(string $plano): string
{
    return password_hash($plano, PASSWORD_DEFAULT);
}

function es_hash(?string $valor): bool
{
    if ($valor === null || strlen($valor) < 60) {
        return false;
    }
    return (password_get_info($valor)['algo'] ?? null) !== null;
}

function verificar_contrasena(string $plano, ?string $guardada): bool
{
    if ($guardada === null || $guardada === '') {
        return false;
    }
    if (es_hash($guardada)) {
        return password_verify($plano, $guardada);
    }
    // Contraseña antigua en texto plano
    return hash_equals($guardada, $plano);
}

function necesita_rehash(?string $guardada): bool
{
    if (!es_hash($guardada)) {
        return true;
    }
    return password_needs_rehash($guardada, PASSWORD_DEFAULT);
}

/* ---------- Protección contra peticiones falsas (CSRF) ---------- */
function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_valido($token): bool
{
    return isset($_SESSION['csrf']) && is_string($token) && hash_equals($_SESSION['csrf'], $token);
}

/* ---------- Mensajes que se muestran una sola vez tras redirigir ---------- */
function flash_set(string $texto, string $tipo = 'ok'): void
{
    $_SESSION['flash'] = ['texto' => $texto, 'tipo' => $tipo];
}

function flash_get(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- Alerta en el navegador y redirección ---------- */
function alerta_redirigir(string $mensaje, string $destino): void
{
    header('Content-Type: text/html; charset=UTF-8');
    $m = json_encode($mensaje, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $d = json_encode($destino, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    echo "<!DOCTYPE html><html lang=\"es\"><head><meta charset=\"UTF-8\"><title>DI-TEC</title></head><body>"
       . "<script>alert($m); window.location.href = $d;</script>"
       . "<noscript><p>" . htmlspecialchars($mensaje) . " <a href=\"" . htmlspecialchars($destino) . "\">Continuar</a></p></noscript>"
       . "</body></html>";
    exit();
}

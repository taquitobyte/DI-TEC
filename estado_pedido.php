<?php
/*
 * Estados de los pedidos.
 *
 * Cada proveedor actualiza solo SUS renglones (DetallePedido.Estado); el estado
 * global (Pedido.Estado), que ven cliente, proveedores y admin, se calcula:
 *   - todos los renglones Entregado            -> Entregado
 *   - alguno Enviado/En Camino/Entregado       -> En Camino
 *   - ninguno ha avanzado                      -> Pendiente
 *
 * Cancelación:
 *   - El cliente SOLICITA la cancelación  -> 'Cancelación solicitada'
 *   - Un proveedor del pedido o el admin la cancelan (basta UNO) -> 'Cancelado'
 *     (se regresa el stock de lo que no se había entregado)
 *   - O bien rechazan la solicitud y el pedido vuelve a su estado calculado.
 */
const ESTADO_SOLICITUD = 'Cancelación solicitada';
const ESTADO_CANCELADO = 'Cancelado';

function estado_pedido_desde_detalles(array $estados): string
{
    if (empty($estados)) {
        return 'Pendiente';
    }
    $entregados = 0;
    $avanzado = false;
    foreach ($estados as $e) {
        if ($e === 'Entregado') {
            $entregados++;
            $avanzado = true;
        } elseif ($e === 'Enviado' || $e === 'En Camino') {
            $avanzado = true;
        }
    }
    if ($entregados === count($estados)) {
        return 'Entregado';
    }
    return $avanzado ? 'En Camino' : 'Pendiente';
}

function pedido_estado_actual(PDO $conn, int $pedidoID): ?string
{
    $st = $conn->prepare("SELECT Estado FROM Pedido WHERE PedidoID = ?");
    $st->execute([$pedidoID]);
    $e = $st->fetchColumn();
    return $e === false ? null : (string) $e;
}

function pedido_estado_calculado(PDO $conn, int $pedidoID): string
{
    $st = $conn->prepare("SELECT Estado FROM DetallePedido WHERE PedidoID = ?");
    $st->execute([$pedidoID]);
    return estado_pedido_desde_detalles($st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Recalcula y guarda Pedido.Estado. Devuelve el estado resultante.
 * Un pedido cancelado o con cancelación solicitada no se modifica.
 * Avisa por correo al cliente cuando el estado cambia.
 */
function sincronizar_estado_pedido(PDO $conn, int $pedidoID): string
{
    $actual = pedido_estado_actual($conn, $pedidoID);
    if ($actual === ESTADO_CANCELADO || $actual === ESTADO_SOLICITUD) {
        return $actual;
    }
    $nuevo = pedido_estado_calculado($conn, $pedidoID);
    if ($nuevo !== $actual) {
        $conn->prepare("UPDATE Pedido SET Estado = ? WHERE PedidoID = ?")->execute([$nuevo, $pedidoID]);
        notificar_pedido_cliente($conn, $pedidoID, $nuevo);
    }
    return $nuevo;
}

/** El cliente solicita cancelar su pedido. Devuelve [bool ok, string mensaje]. */
function solicitar_cancelacion(PDO $conn, int $pedidoID, int $usuarioID): array
{
    $st = $conn->prepare("SELECT Estado FROM Pedido WHERE PedidoID = ? AND UsuarioID = ?");
    $st->execute([$pedidoID, $usuarioID]);
    $estado = $st->fetchColumn();
    if ($estado === false) {
        return [false, "El pedido no existe."];
    }
    if ($estado === ESTADO_SOLICITUD) {
        return [false, "Ya habías solicitado la cancelación de este pedido."];
    }
    if ($estado === ESTADO_CANCELADO) {
        return [false, "Este pedido ya está cancelado."];
    }
    if ($estado === 'Entregado') {
        return [false, "Un pedido entregado ya no se puede cancelar."];
    }
    $conn->prepare("UPDATE Pedido SET Estado = ? WHERE PedidoID = ?")->execute([ESTADO_SOLICITUD, $pedidoID]);
    return [true, "Tu solicitud de cancelación fue enviada. El proveedor o el administrador la revisará."];
}

/**
 * Cancela el pedido completo (basta con que lo haga un proveedor del pedido o el admin).
 * Regresa al inventario lo que aún no se había entregado.
 * NO debe llamarse dentro de otra transacción.
 */
function cancelar_pedido(PDO $conn, int $pedidoID): array
{
    $estado = pedido_estado_actual($conn, $pedidoID);
    if ($estado === null) {
        return [false, "El pedido no existe."];
    }
    if ($estado === ESTADO_CANCELADO) {
        return [false, "El pedido ya estaba cancelado."];
    }
    if ($estado === 'Entregado') {
        return [false, "Un pedido entregado no se puede cancelar."];
    }
    try {
        $conn->beginTransaction();
        $st = $conn->prepare("SELECT DetalleID, ProductoID, Cantidad FROM DetallePedido
                              WHERE PedidoID = ? AND COALESCE(Estado,'Pendiente') NOT IN ('Entregado','Cancelado')");
        $st->execute([$pedidoID]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $conn->prepare("UPDATE Producto SET Stock = COALESCE(Stock,0) + ? WHERE ProductoID = ?")
                 ->execute([$d['Cantidad'], $d['ProductoID']]);
            $conn->prepare("UPDATE DetallePedido SET Estado = 'Cancelado' WHERE DetalleID = ?")
                 ->execute([$d['DetalleID']]);
        }
        $conn->prepare("UPDATE Pedido SET Estado = ? WHERE PedidoID = ?")->execute([ESTADO_CANCELADO, $pedidoID]);
        $conn->commit();
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        return [false, "No se pudo cancelar: " . $e->getMessage()];
    }
    notificar_pedido_cliente($conn, $pedidoID, ESTADO_CANCELADO);
    return [true, "Pedido #$pedidoID cancelado. El stock se regresó al inventario."];
}

/** Rechaza la solicitud del cliente: el pedido vuelve a su estado según los productos. */
function rechazar_solicitud_cancelacion(PDO $conn, int $pedidoID): array
{
    if (pedido_estado_actual($conn, $pedidoID) !== ESTADO_SOLICITUD) {
        return [false, "Ese pedido no tiene una solicitud de cancelación pendiente."];
    }
    $nuevo = pedido_estado_calculado($conn, $pedidoID);
    $conn->prepare("UPDATE Pedido SET Estado = ? WHERE PedidoID = ?")->execute([$nuevo, $pedidoID]);
    notificar_pedido_cliente($conn, $pedidoID, 'Solicitud rechazada');
    return [true, "Solicitud rechazada. El pedido #$pedidoID sigue su curso ($nuevo)."];
}

/** ¿Este proveedor tiene productos en el pedido? */
function proveedor_en_pedido(PDO $conn, int $pedidoID, int $proveedorID): bool
{
    $st = $conn->prepare("SELECT COUNT(*) FROM DetallePedido dp
                          INNER JOIN Producto pr ON pr.ProductoID = dp.ProductoID
                          WHERE dp.PedidoID = ? AND pr.ProveedorID = ?");
    $st->execute([$pedidoID, $proveedorID]);
    return (int) $st->fetchColumn() > 0;
}

/**
 * Avisa por correo al cliente. Es "mejor esfuerzo": si el correo no está
 * configurado o falla el envío, no afecta a la operación.
 */
function notificar_pedido_cliente(PDO $conn, int $pedidoID, string $evento): void
{
    try {
        if (getenv('DITEC_NO_MAIL') || !is_file(__DIR__ . '/config_correo.php')) {
            return;
        }
        $st = $conn->prepare("SELECT u.NombreUsuario, u.EmailUsuario FROM Pedido p
                              INNER JOIN Usuario u ON u.UsuarioID = p.UsuarioID WHERE p.PedidoID = ?");
        $st->execute([$pedidoID]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u || empty($u['EmailUsuario'])) {
            return;
        }
        $textos = [
            'En Camino'          => ['🚚 Tu pedido #%d va en camino', 'Tu pedido ya está en camino.'],
            'Entregado'          => ['✅ Tu pedido #%d fue entregado', 'Todos los productos de tu pedido fueron entregados. ¡Gracias por tu compra!'],
            'Cancelado'          => ['❌ Tu pedido #%d fue cancelado', 'Tu pedido fue cancelado y el importe no será procesado.'],
            'Solicitud rechazada'=> ['ℹ️ Solicitud de cancelación del pedido #%d', 'Tu solicitud de cancelación no fue aceptada; tu pedido sigue su curso.'],
        ];
        if (!isset($textos[$evento])) {
            return;
        }
        [$asunto, $mensaje] = $textos[$evento];

        require_once __DIR__ . '/lib/PHPMailer/autoload.php';
        $cfg = require __DIR__ . '/config_correo.php';
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $cfg['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $cfg['usuario'];
        $mail->Password = $cfg['clave'];
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = (int) $cfg['port'];
        $mail->Timeout = 8;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($cfg['usuario'], $cfg['nombre_remitente'] ?? 'DI-TEC');
        $mail->addAddress($u['EmailUsuario']);
        $mail->isHTML(true);
        $mail->Subject = sprintf($asunto, $pedidoID);
        $nombre = htmlspecialchars($u['NombreUsuario']);
        $mail->Body = "<p>Hola $nombre,</p><p>" . htmlspecialchars($mensaje) . "</p><p>Pedido <b>#$pedidoID</b> — DI-TEC</p>";
        $mail->send();
    } catch (Throwable $e) {
        // El correo es opcional: nunca debe romper el flujo del pedido.
    }
}

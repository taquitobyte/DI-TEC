<?php
include 'conexion.php';
include 'seguridad.php';
include 'estado_pedido.php';
session_start();

// SOLO PROVEEDORES
if (!isset($_SESSION['UsuarioID']) || $_SESSION['RolID'] != 3) {
    header("Location: login.html");
    exit();
}

$usuarioID = $_SESSION['UsuarioID'];
$mensaje   = "";

// ---------------------------------------------------------
// 1. OBTENER EL PROVEEDOR ID DE ESTE USUARIO
// ---------------------------------------------------------
try {
    $stmt = $conn->prepare("SELECT ProveedorID FROM Proveedor WHERE UsuarioID = ?");
    $stmt->execute([$usuarioID]);
    $proveedorID = $stmt->fetchColumn();

    if (!$proveedorID) {
        die("❌ Error: No existe un registro de proveedor asociado a tu cuenta.");
    }
} catch (PDOException $e) {
    die("❌ Error al obtener proveedor: " . $e->getMessage());
}

// ---------------------------------------------------------
// 2. ACCIONES (POST + token CSRF, luego se redirige)
// ---------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $tipo = 'error';
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $mensaje = "❌ La solicitud no es válida. Recarga la página e inténtalo de nuevo.";
    } elseif (isset($_POST['accion_pedido'])) {
        // Cancelar el pedido / rechazar la solicitud. Con que UN proveedor del pedido
        // cancele, el pedido completo queda cancelado (sin validación de los demás).
        $pedidoID = intval($_POST['pedido_id'] ?? 0);
        if (!proveedor_en_pedido($conn, $pedidoID, (int) $proveedorID)) {
            $mensaje = "❌ Ese pedido no incluye productos de tu cuenta.";
        } else {
            [$ok, $texto] = ($_POST['accion_pedido'] === 'cancelar')
                ? cancelar_pedido($conn, $pedidoID)
                : rechazar_solicitud_cancelacion($conn, $pedidoID);
            $mensaje = ($ok ? "✔️ " : "⚠️ ") . $texto;
            $tipo = $ok ? 'ok' : 'error';
        }
    } elseif (isset($_POST['actualizar_estado'])) {
        $detalleID   = $_POST['detalle_id'] ?? 0;
        $nuevoEstado = $_POST['estado'] ?? '';

        // Obtener el renglón, comprobando que sea de un producto de ESTE proveedor
        $stmt = $conn->prepare("SELECT dp.Estado, dp.PedidoID
                                FROM DetallePedido dp
                                INNER JOIN Producto pr ON pr.ProductoID = dp.ProductoID
                                WHERE dp.DetalleID = ? AND pr.ProveedorID = ?");
        $stmt->execute([$detalleID, $proveedorID]);
        $detalle = $stmt->fetch(PDO::FETCH_ASSOC);
        $estadoPedidoActual = $detalle ? pedido_estado_actual($conn, (int) $detalle['PedidoID']) : null;

        if (!$detalle) {
            $mensaje = "❌ Ese producto no pertenece a tu cuenta.";
        } elseif (!in_array($nuevoEstado, ['Pendiente', 'Enviado', 'Entregado'], true)) {
            $mensaje = "❌ Estado no válido.";
        } elseif ($estadoPedidoActual === ESTADO_CANCELADO) {
            $mensaje = "⚠️ El pedido está cancelado.";
        } elseif ($estadoPedidoActual === ESTADO_SOLICITUD) {
            $mensaje = "⚠️ El cliente solicitó cancelar este pedido: cancélalo o rechaza la solicitud antes de cambiar su estado.";
        } elseif ($detalle['Estado'] === 'Entregado') {
            $mensaje = "⚠️ No puedes modificar un pedido que ya fue ENTREGADO.";
        } else {
            try {
                $conn->beginTransaction();
                $stmt = $conn->prepare("UPDATE DetallePedido SET Estado = ? WHERE DetalleID = ?");
                $stmt->execute([$nuevoEstado, $detalleID]);
                $estadoPedido = sincronizar_estado_pedido($conn, (int) $detalle['PedidoID']);
                $conn->commit();
                $mensaje = "✔️ Estado actualizado correctamente. Estado del pedido: " . $estadoPedido . ".";
                $tipo = 'ok';
            } catch (PDOException $e) {
                if ($conn->inTransaction()) { $conn->rollBack(); }
                $mensaje = "❌ Error al actualizar: " . $e->getMessage();
            }
        }
    }
    if ($mensaje !== "") {
        flash_set($mensaje, $tipo);
    }
    header("Location: pedidos_proveedor.php");
    exit();
}
$flash = flash_get();
$token = csrf_token();
function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

// ---------------------------------------------------------
// 3. OBTENER PEDIDOS QUE CONTENGAN PRODUCTOS DEL PROVEEDOR
// ---------------------------------------------------------
try {
    $sql = "
        SELECT 
            dp.DetalleID,
            dp.PedidoID,
            dp.Cantidad,
            dp.PrecioUnitario,
            dp.Estado AS EstadoDetalle,
            p.Estado AS EstadoPedido,
            p.FechaPedido,
            u.NombreUsuario,
            prod.NombreProducto
        FROM DetallePedido dp
        INNER JOIN Pedido p ON dp.PedidoID = p.PedidoID
        INNER JOIN Usuario u ON p.UsuarioID = u.UsuarioID
        INNER JOIN Producto prod ON dp.ProductoID = prod.ProductoID
        WHERE prod.ProveedorID = ?
        ORDER BY p.PedidoID DESC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$proveedorID]);
    $pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("❌ Error al obtener pedidos: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Mis Pedidos - Proveedor</title>

<style>
    body {
        font-family: 'Segoe UI', sans-serif;
        margin: 0;
        background: #f4f6f9;
    }

    .sidebar {
        width: 240px;
        height: 100vh;
        background-color: #003366;
        position: fixed;
        color: white;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }

    .sidebar h2 {
        background: #00264d;
        margin: 0;
        padding: 20px;
        text-align: center;
    }

    .sidebar a {
        color: white;
        padding: 15px 20px;
        text-decoration: none;
        display: block;
        font-weight: bold;
    }

    .sidebar a:hover { background: #ff6600; }

    .logout {
        background: #ff6600;
        padding: 15px;
        text-align: center;
    }
    .logout a { color: white; text-decoration: none; font-weight: bold; }

    .content {
        margin-left: 260px;
        padding: 30px;
    }

    table {
        width: 100%;
        background: white;
        border-collapse: collapse;
        box-shadow: 0 2px 6px rgba(0,0,0,.1);
        border-radius: 10px;
        overflow: hidden;
        margin-top: 15px;
    }

    th, td {
        padding: 12px;
        border-bottom: 1px solid #ddd;
        text-align: center;
    }

    th { background: #003366; color: white; }

    tr:hover { background: #eef3f7; }

    .btn {
        background: #003366;
        color: white;
        border: none;
        padding: 6px 12px;
        border-radius: 6px;
        cursor: pointer;
    }

    .btn:hover { background: #004c99; }

    .mensaje {
        background: #e0ffe8;
        padding: 12px;
        border-radius: 8px;
        margin-bottom: 15px;
        border-left: 5px solid #28a745;
        font-weight: bold;
    }

</style>
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar">
    <div>
        <h2>Proveedor DI-TEC</h2>
        <a href="panel_proveedor.php">📦 Mis Productos</a>
        <a href="pedidos_proveedor.php" style="background:#ff6600;">🚀 Mis Pedidos</a>
        <a href="estadisticas_proveedor.php">📈 Mis Estadísticas</a>
    </div>
    <div class="logout">
        <a href="logout.php">🚪 Cerrar Sesión</a>
    </div>
</div>

<!-- CONTENIDO -->
<div class="content">

    <h1>🚚 Pedidos de mis productos</h1>

    <?php if ($flash): ?>
        <p class="mensaje" style="<?= $flash['tipo'] === 'error' ? 'background:#ffe3e0;border-left-color:#c0392b;' : '' ?>"><?= h($flash['texto']) ?></p>
    <?php endif; ?>

    <?php if (empty($pedidos)): ?>
        <p>No hay pedidos aún.</p>
    <?php else: ?>

    <table>
        <tr>
            <th>Pedido</th>
            <th>Fecha</th>
            <th>Cliente</th>
            <th>Producto</th>
            <th>Cantidad</th>
            <th>Subtotal</th>
            <th>Estado Pedido</th>
            <th>Estado Detalle</th>
            <th>Acción</th>
        </tr>

        <?php foreach ($pedidos as $p):
            $cancelado = ($p['EstadoPedido'] === ESTADO_CANCELADO);
            $solicitud = ($p['EstadoPedido'] === ESTADO_SOLICITUD);
            $final = ($p['EstadoPedido'] === 'Entregado' || $cancelado);
            $bloqueado = ($p['EstadoDetalle'] === 'Entregado' || $final || $solicitud);
        ?>
        <tr>
            <td>#<?= (int) $p['PedidoID'] ?></td>
            <td><?= h($p['FechaPedido']) ?></td>
            <td><?= h($p['NombreUsuario']) ?></td>
            <td><?= h($p['NombreProducto']) ?></td>
            <td><?= (int) $p['Cantidad'] ?></td>
            <td>$<?= number_format($p['Cantidad'] * $p['PrecioUnitario'], 2) ?></td>

            <td>
                <strong><?= h($p['EstadoPedido']) ?></strong>
                <?php if ($solicitud): ?><br><small style="color:#8e44ad;">El cliente pidió cancelar</small><?php endif; ?>
            </td>

            <td>
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="csrf" value="<?= h($token) ?>">
                    <input type="hidden" name="detalle_id" value="<?= (int) $p['DetalleID'] ?>">

                    <select name="estado" <?= $bloqueado ? "disabled" : "" ?>>
                        <option value="Pendiente" <?= ($p['EstadoDetalle']=="Pendiente")?"selected":"" ?>>Pendiente</option>
                        <option value="Enviado" <?= ($p['EstadoDetalle']=="Enviado")?"selected":"" ?>>Enviado</option>
                        <option value="Entregado" <?= ($p['EstadoDetalle']=="Entregado")?"selected":"" ?>>Entregado</option>
                        <?php if ($p['EstadoDetalle']=="Cancelado"): ?><option selected>Cancelado</option><?php endif; ?>
                    </select>
            </td>

            <td>
                    <?php if (!$bloqueado): ?>
                        <button type="submit" name="actualizar_estado" class="btn">Actualizar</button>
                    <?php elseif ($cancelado): ?>
                        ✖ Cancelado
                    <?php elseif ($solicitud): ?>
                        ⏸ Resuelve la solicitud
                    <?php else: ?>
                        ✔ Entregado
                    <?php endif; ?>
                </form>

                <?php if (!$final): ?>
                    <form method="POST" style="margin:6px 0 0;"
                          onsubmit="return confirm('¿Cancelar el pedido #<?= (int) $p['PedidoID'] ?> completo? Se cancela para todos los proveedores y se regresa el stock.')">
                        <input type="hidden" name="csrf" value="<?= h($token) ?>">
                        <input type="hidden" name="pedido_id" value="<?= (int) $p['PedidoID'] ?>">
                        <button type="submit" name="accion_pedido" value="cancelar" style="background:#c0392b;color:white;border:0;padding:7px 12px;border-radius:6px;cursor:pointer;">Cancelar pedido</button>
                        <?php if ($solicitud): ?>
                            <button type="submit" name="accion_pedido" value="rechazar" style="background:#555;color:white;border:0;padding:7px 12px;border-radius:6px;cursor:pointer;">Rechazar solicitud</button>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>

    </table>

    <?php endif; ?>

</div>

</body>
</html>

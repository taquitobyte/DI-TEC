<?php
include 'conexion.php';
include 'seguridad.php';
include 'estado_pedido.php';
session_start();

if (!isset($_SESSION['UsuarioID'])) {
    header("Location: login.html");
    exit();
}

$usuarioID = $_SESSION['UsuarioID'];

// El cliente SOLICITA cancelar (la cancelación la acepta el proveedor o el admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['solicitar_cancelacion'])) {
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        flash_set("❌ La solicitud no es válida. Recarga la página e inténtalo de nuevo.", 'error');
    } else {
        [$ok, $texto] = solicitar_cancelacion($conn, intval($_POST['pedido_id']), (int) $usuarioID);
        flash_set(($ok ? "✅ " : "⚠️ ") . $texto, $ok ? 'ok' : 'error');
    }
    header("Location: mis_pedidos.php");
    exit();
}
$flash = flash_get();
$token = csrf_token();

/* =======================================================
   OBTENER PEDIDOS + DETALLES (YA INCLUYE ESTADO)
======================================================= */
try {
    $query = "
        SELECT 
            p.PedidoID, 
            p.FechaPedido, 
            p.Total,
            p.Estado,
            d.ProductoID, 
            prod.NombreProducto AS Producto,
            d.Cantidad, 
            d.PrecioUnitario,
            d.Estado AS EstadoDetalle
        FROM Pedido p
        INNER JOIN DetallePedido d ON p.PedidoID = d.PedidoID
        INNER JOIN Producto prod ON d.ProductoID = prod.ProductoID
        WHERE p.UsuarioID = ?
        ORDER BY p.FechaPedido DESC
    ";

    $stmt = $conn->prepare($query);
    $stmt->execute([$usuarioID]);
    $pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("❌ Error al cargar pedidos: " . $e->getMessage());
}

/* =======================================================
   AGRUPAR PRODUCTOS POR PEDIDO (AGREGA ESTADO)
======================================================= */
$historial = [];

foreach ($pedidos as $pedido) {
    $id = $pedido['PedidoID'];

    if (!isset($historial[$id])) {
        $historial[$id] = [
            'FechaPedido' => $pedido['FechaPedido'],
            'Total'       => $pedido['Total'],
            'Estado'      => $pedido['Estado'], // <-- IMPORTANTE
            'Productos'   => []
        ];
    }

    $historial[$id]['Productos'][] = [
        'Nombre'   => $pedido['Producto'],
        'Cantidad' => $pedido['Cantidad'],
        'Precio'   => $pedido['PrecioUnitario'],
        'EstadoDetalle' => $pedido['EstadoDetalle']
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Mis Pedidos - DI-TEC</title>

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
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 15px 50px;
    }
    header .logo {
        font-size: 20px;
        font-weight: bold;
    }
    nav a {
        color: white;
        text-decoration: none;
        margin: 0 12px;
        font-weight: bold;
    }
    nav a:hover {
        color: #ff6600;
    }

    .container {
        width: 90%;
        margin: 30px auto;
        background: white;
        padding: 25px;
        border-radius: 10px;
        box-shadow: 0 0 10px rgba(0,0,0,0.1);
    }
    .pedido {
        border: 1px solid #ccc;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
        background-color: #f9fafc;
    }
    .pedido h3 {
        color: #003366;
    }
</style>
</head>
<body>

<header>
    <div class="logo">DI-TEC</div>
    <nav>
        <a href="panel_usuario.php">🏠 Inicio</a>
        <a href="ver_carrito.php">🛒 Ver Carrito</a>
        <a href="mis_pedidos.php">📦 Mis Pedidos</a>
        <a href="cuenta_usuario.php">⚙️ Cuenta</a>
        <a href="logout.php">🚪 Cerrar Sesión</a>
    </nav>
</header>

<div class="container">
    <h2>📦 Historial de Pedidos</h2>

    <?php if ($flash): ?>
        <p style="padding:10px 14px;border-radius:8px;font-weight:bold;border-left:5px solid <?= $flash['tipo']==='error' ? '#c0392b' : '#00a300' ?>;background:<?= $flash['tipo']==='error' ? '#ffe3e0' : '#e0ffe0' ?>;"><?= htmlspecialchars($flash['texto']) ?></p>
    <?php endif; ?>

    <?php if (count($historial) > 0): ?>
        <?php foreach ($historial as $id => $pedido): ?>
            <div class="pedido">
                <h3>Pedido #<?= $id ?></h3>

                <p><strong>Fecha:</strong> <?= $pedido['FechaPedido'] ?></p>
                <p><strong>Total:</strong> $<?= number_format($pedido['Total'], 2) ?></p>

                <!-- ESTADO -->
                <p><strong>Estado:</strong> 
                    <span style="
                        padding:6px 12px; 
                        border-radius:6px; 
                        color:white;
                        background:
                        <?= $pedido['Estado']=='Pendiente' ? '#e67e22' : 
                           ($pedido['Estado']=='En Camino' ? '#2980b9' :
                           ($pedido['Estado']=='Cancelado' ? '#c0392b' :
                           ($pedido['Estado']=='Cancelación solicitada' ? '#8e44ad' : '#27ae60'))) ?>;
                    ">
                        <?= htmlspecialchars($pedido['Estado']) ?>
                    </span>
                </p>

                <table>
                    <tr>
                        <th>Producto</th>
                        <th>Cantidad</th>
                        <th>Precio Unitario</th>
                        <th>Subtotal</th>
                        <th>Estado</th>
                    </tr>

                    <?php foreach ($pedido['Productos'] as $prod): ?>
                        <tr>
                            <td><?= htmlspecialchars($prod['Nombre']) ?></td>
                            <td><?= $prod['Cantidad'] ?></td>
                            <td>$<?= number_format($prod['Precio'], 2) ?></td>
                            <td>$<?= number_format($prod['Precio'] * $prod['Cantidad'], 2) ?></td>
                            <td><?= htmlspecialchars($prod['EstadoDetalle'] ?? 'Pendiente') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <?php if (in_array($pedido['Estado'], ['Pendiente', 'En Camino'], true)): ?>
                    <form method="POST" style="display:inline;"
                          onsubmit="return confirm('¿Solicitar la cancelación del pedido #<?= (int) $id ?>?')">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($token) ?>">
                        <input type="hidden" name="pedido_id" value="<?= (int) $id ?>">
                        <button type="submit" name="solicitar_cancelacion" value="1"
                                style="background:#c0392b;color:white;border:0;padding:10px 16px;border-radius:6px;cursor:pointer;">✖ Solicitar cancelación</button>
                    </form>
                <?php elseif ($pedido['Estado'] === 'Cancelación solicitada'): ?>
                    <p style="color:#8e44ad;"><strong>Tu solicitud está en revisión por el proveedor o el administrador.</strong></p>
                <?php elseif ($pedido['Estado'] === 'Cancelado'): ?>
                    <p style="color:#c0392b;"><strong>Este pedido fue cancelado.</strong></p>
                <?php endif; ?>

                <form action="generar_pdf.php" method="GET" target="_blank" style="display:inline;">
                    <input type="hidden" name="pedido_id" value="<?= $id ?>">
                    <button type="submit" class="btn-pdf">🧾 Ver / Descargar PDF</button>
                </form>

            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <p style="text-align:center;">No tienes pedidos todavía 🛍️</p>
    <?php endif; ?>
</div>

</body>
</html>

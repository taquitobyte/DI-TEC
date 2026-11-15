<?php
include 'conexion.php';
session_start();

// PROVEEDORES (sus propias estadísticas) y ADMIN (las de cualquier proveedor, con ?id=)
if (!isset($_SESSION['UsuarioID']) || !isset($_SESSION['RolID']) || !in_array((int) $_SESSION['RolID'], [1, 3], true)) {
    header("Location: login.html");
    exit();
}

$esAdmin = ((int) $_SESSION['RolID'] === 1);
if ($esAdmin && intval($_GET['id'] ?? 0) <= 0) {
    header("Location: estadisticas.php");
    exit();
}
$usuarioID = $_SESSION['UsuarioID'];

try {
    // ---------------------------------------------------------
    // 1. PROVEEDOR DE ESTA CUENTA
    // ---------------------------------------------------------
    if ($esAdmin) {
        $stmt = $conn->prepare("SELECT ProveedorID, NombreProveedor FROM Proveedor WHERE ProveedorID = ?");
        $stmt->execute([intval($_GET['id'])]);
    } else {
        $stmt = $conn->prepare("SELECT ProveedorID, NombreProveedor FROM Proveedor WHERE UsuarioID = ?");
        $stmt->execute([$usuarioID]);
    }
    $proveedor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$proveedor) {
        die($esAdmin ? "❌ El proveedor no existe." : "❌ Error: No existe un registro de proveedor asociado a tu cuenta.");
    }
    $proveedorID = $proveedor['ProveedorID'];

    // ---------------------------------------------------------
    // 2. ESTADÍSTICAS (solo de los productos de este proveedor)
    // ---------------------------------------------------------
    $stmt = $conn->prepare("SELECT COUNT(*) AS Productos, COALESCE(SUM(Stock), 0) AS Existencias FROM Producto WHERE ProveedorID = ?");
    $stmt->execute([$proveedorID]);
    $inventario = $stmt->fetch(PDO::FETCH_ASSOC);

    // Resumen general de ventas
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT dp.PedidoID)                    AS Pedidos,
               COALESCE(SUM(dp.Cantidad), 0)                  AS Unidades,
               COALESCE(SUM(dp.Cantidad * dp.PrecioUnitario), 0) AS Ventas
        FROM DetallePedido dp
        INNER JOIN Producto pr ON dp.ProductoID = pr.ProductoID
        WHERE pr.ProveedorID = ? AND COALESCE(dp.Estado,'Pendiente') <> 'Cancelado'
    ");
    $stmt->execute([$proveedorID]);
    $resumen = $stmt->fetch(PDO::FETCH_ASSOC);

    // Pedidos por estado
    $stmt = $conn->prepare("
        SELECT COALESCE(dp.Estado, 'Pendiente')                   AS Estado,
               COUNT(*)                                            AS Renglones,
               COALESCE(SUM(dp.Cantidad), 0)                       AS Unidades,
               COALESCE(SUM(dp.Cantidad * dp.PrecioUnitario), 0)   AS Monto
        FROM DetallePedido dp
        INNER JOIN Producto pr ON dp.ProductoID = pr.ProductoID
        WHERE pr.ProveedorID = ?
        GROUP BY COALESCE(dp.Estado, 'Pendiente')
        ORDER BY Estado
    ");
    $stmt->execute([$proveedorID]);
    $porEstado = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $ventasEntregadas = 0;
    foreach ($porEstado as $e) {
        if ($e['Estado'] === 'Entregado') {
            $ventasEntregadas = $e['Monto'];
        }
    }

    // Ventas por mes
    $stmt = $conn->prepare("
        SELECT FORMAT(p.FechaPedido, 'yyyy-MM')                    AS Mes,
               COUNT(DISTINCT dp.PedidoID)                          AS Pedidos,
               SUM(dp.Cantidad)                                     AS Unidades,
               SUM(dp.Cantidad * dp.PrecioUnitario)                 AS Ventas
        FROM DetallePedido dp
        INNER JOIN Pedido p    ON dp.PedidoID = p.PedidoID
        INNER JOIN Producto pr ON dp.ProductoID = pr.ProductoID
        WHERE pr.ProveedorID = ? AND COALESCE(dp.Estado,'Pendiente') <> 'Cancelado'
        GROUP BY FORMAT(p.FechaPedido, 'yyyy-MM')
        ORDER BY Mes
    ");
    $stmt->execute([$proveedorID]);
    $ventasMensuales = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $maxVentaMes = 0;
    foreach ($ventasMensuales as $v) {
        $maxVentaMes = max($maxVentaMes, (float) $v['Ventas']);
    }

    // Productos más vendidos (top 5)
    $stmt = $conn->prepare("
        SELECT pr.NombreProducto,
               SUM(dp.Cantidad)                         AS Unidades,
               SUM(dp.Cantidad * dp.PrecioUnitario)     AS Ventas
        FROM DetallePedido dp
        INNER JOIN Producto pr ON dp.ProductoID = pr.ProductoID
        WHERE pr.ProveedorID = ? AND COALESCE(dp.Estado,'Pendiente') <> 'Cancelado'
        GROUP BY pr.ProductoID, pr.NombreProducto
        ORDER BY Unidades DESC
    ");
    $stmt->execute([$proveedorID]);
    $topProductos = array_slice($stmt->fetchAll(PDO::FETCH_ASSOC), 0, 5);

    // Productos con pocas existencias
    $stmt = $conn->prepare("SELECT NombreProducto, Stock FROM Producto WHERE ProveedorID = ? AND Stock <= 5 ORDER BY Stock, NombreProducto");
    $stmt->execute([$proveedorID]);
    $stockBajo = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("❌ Error al obtener estadísticas: " . $e->getMessage());
}

function h($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function dinero($v) { return '$' . number_format((float) $v, 2); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $esAdmin ? 'Estadísticas de ' . h($proveedor['NombreProveedor']) . ' - Admin DI-TEC' : 'Mis Estadísticas - Proveedor DI-TEC' ?></title>

<style>
    body { margin: 0; font-family: 'Segoe UI', sans-serif; background-color: #f4f6f9; color: #333; }

    /* ===== MENÚ LATERAL ===== */
    .sidebar { background-color: #003366; color: white; width: 240px; height: 100vh; position: fixed; display: flex; flex-direction: column; justify-content: space-between; }
    .sidebar h2 { text-align: center; padding: 20px; background-color: #00264d; margin: 0; }
    .sidebar a { color: white; text-decoration: none; padding: 15px 20px; display: block; font-weight: bold; }
    .sidebar a:hover, .sidebar a.active { background-color: #ff6600; }
    .logout { text-align: center; background-color: #ff6600; padding: 15px; }
    .logout a { color: white; text-decoration: none; font-weight: bold; padding: 0; }

    /* ===== CONTENIDO ===== */
    .content { margin-left: 240px; padding: 30px; }
    h1 { color: #003366; margin-top: 0; }
    h2.seccion { color: #003366; margin-top: 35px; }
    .sub { color: #666; margin-top: -10px; }

    .cards { display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 10px; }
    .card { flex: 1; min-width: 140px; background: white; padding: 20px; border-radius: 10px; text-align: center; box-shadow: 0 0 5px rgba(0,0,0,0.1); }
    .card h3 { margin: 0; color: #003366; font-size: 15px; }
    .card p { font-size: 25px; margin: 10px 0 0; color: #ff6600; font-weight: bold; }

    table { width: 100%; background: white; border-collapse: collapse; border-radius: 10px; overflow: hidden; box-shadow: 0 0 5px rgba(0,0,0,0.1); margin-top: 10px; }
    th { background: #003366; color: white; padding: 10px; }
    td { padding: 10px; text-align: center; }
    tr:nth-child(even) { background-color: #f2f2f2; }
    .vacio { background: white; padding: 20px; border-radius: 10px; text-align: center; color: #777; box-shadow: 0 0 5px rgba(0,0,0,0.1); }

    .grafica { background: white; padding: 15px 20px; border-radius: 10px; box-shadow: 0 0 5px rgba(0,0,0,0.1); }
    .fila { display: flex; align-items: center; gap: 12px; margin: 8px 0; }
    .fila .mes { width: 80px; font-weight: bold; color: #003366; }
    .fila .barra { background: #ff6600; height: 22px; border-radius: 4px; min-width: 3px; }
    .fila .valor { font-weight: bold; }

    .estado { padding: 3px 10px; border-radius: 12px; color: white; font-size: 13px; font-weight: bold; }
    .estado.Pendiente { background: #e67e22; }
    .estado.Enviado, .estado.En-Camino { background: #2980b9; }
    .estado.Entregado { background: #27ae60; }
    .estado.Cancelado { background: #c0392b; }
    .btn-volver { display: inline-block; background: #003366; color: white; padding: 8px 14px; border-radius: 6px; text-decoration: none; margin-bottom: 15px; }
    .alerta { color: #c0392b; font-weight: bold; }
</style>
</head>

<body>

<div class="sidebar">
    <?php if ($esAdmin): ?>
    <div>
        <h2>Admin DI-TEC</h2>
        <a href="gestion_usuarios.php">🧑‍💼 Gestión de Usuarios</a>
        <a href="gestion_proveedores.php">🏬 Gestión de Proveedores</a>
        <a href="gestion_pedidos.php">🗂️ Gestión de Pedidos</a>
        <a href="estadisticas.php" class="active">📈 Estadísticas</a>
    </div>
    <div class="logout">
        <a href="logout.php">Cerrar Sesión</a>
    </div>
    <?php else: ?>
    <div>
        <h2>Proveedor DI-TEC</h2>
        <a href="panel_proveedor.php">📦 Mis Productos</a>
        <a href="pedidos_proveedor.php">🚀 Mis Pedidos</a>
        <a href="estadisticas_proveedor.php" class="active">📈 Mis Estadísticas</a>
    </div>
    <div class="logout">
        <a href="logout.php">🚪 Cerrar Sesión</a>
    </div>
    <?php endif; ?>
</div>

<div class="content">

    <?php if ($esAdmin): ?>
        <a href="estadisticas.php" class="btn-volver">⬅ Volver a la lista de proveedores</a>
        <h1>📈 Estadísticas de <?= h($proveedor['NombreProveedor']) ?></h1>
        <p class="sub">Ventas y pedidos de los productos de este proveedor (los pedidos cancelados no cuentan como venta).</p>
    <?php else: ?>
        <h1>📈 Mis Estadísticas</h1>
        <p class="sub">Resumen de las ventas y pedidos de los productos de <b><?= h($proveedor['NombreProveedor']) ?></b> (los pedidos cancelados no cuentan como venta).</p>
    <?php endif; ?>

    <!-- TARJETAS -->
    <div class="cards">
        <div class="card"><h3>Productos publicados</h3><p><?= h($inventario['Productos']) ?></p></div>
        <div class="card"><h3>Pedidos recibidos</h3><p><?= h($resumen['Pedidos']) ?></p></div>
        <div class="card"><h3>Unidades vendidas</h3><p><?= h($resumen['Unidades']) ?></p></div>
        <div class="card"><h3>Ventas totales</h3><p><?= dinero($resumen['Ventas']) ?></p></div>
        <div class="card"><h3>Ventas entregadas</h3><p><?= dinero($ventasEntregadas) ?></p></div>
    </div>

    <!-- POR ESTADO -->
    <h2 class="seccion">🚚 Mis pedidos por estado</h2>
    <?php if (count($porEstado) === 0): ?>
        <div class="vacio">Todavía no tienes pedidos.</div>
    <?php else: ?>
    <table>
        <tr><th>Estado</th><th>Productos pedidos (renglones)</th><th>Unidades</th><th>Monto</th></tr>
        <?php foreach ($porEstado as $e): ?>
        <tr>
            <td><span class="estado <?= h(str_replace(' ', '-', $e['Estado'])) ?>"><?= h($e['Estado']) ?></span></td>
            <td><?= h($e['Renglones']) ?></td>
            <td><?= h($e['Unidades']) ?></td>
            <td><?= dinero($e['Monto']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <!-- VENTAS POR MES -->
    <h2 class="seccion">💰 Ventas por mes</h2>
    <?php if (count($ventasMensuales) === 0): ?>
        <div class="vacio">Todavía no hay ventas registradas.</div>
    <?php else: ?>
    <div class="grafica">
        <?php foreach ($ventasMensuales as $v):
            $ancho = $maxVentaMes > 0 ? max(1, round(((float) $v['Ventas'] / $maxVentaMes) * 100)) : 0; ?>
        <div class="fila">
            <span class="mes"><?= h($v['Mes']) ?></span>
            <div class="barra" style="width: <?= $ancho ?>%;"></div>
            <span class="valor"><?= dinero($v['Ventas']) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <table>
        <tr><th>Mes</th><th>Pedidos</th><th>Unidades</th><th>Total vendido</th></tr>
        <?php foreach ($ventasMensuales as $v): ?>
        <tr>
            <td><?= h($v['Mes']) ?></td>
            <td><?= h($v['Pedidos']) ?></td>
            <td><?= h($v['Unidades']) ?></td>
            <td><?= dinero($v['Ventas']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <!-- MÁS VENDIDOS -->
    <h2 class="seccion">🏆 Mis productos más vendidos</h2>
    <?php if (count($topProductos) === 0): ?>
        <div class="vacio">Todavía no se ha vendido ninguno de tus productos.</div>
    <?php else: ?>
    <table>
        <tr><th>#</th><th>Producto</th><th>Unidades vendidas</th><th>Total vendido</th></tr>
        <?php foreach ($topProductos as $i => $p): ?>
        <tr>
            <td><?= $i + 1 ?></td>
            <td><?= h($p['NombreProducto']) ?></td>
            <td><?= h($p['Unidades']) ?></td>
            <td><?= dinero($p['Ventas']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <!-- INVENTARIO -->
    <h2 class="seccion">📦 Inventario</h2>
    <div class="cards">
        <div class="card"><h3>Existencias totales</h3><p><?= h($inventario['Existencias']) ?></p></div>
        <div class="card"><h3>Productos con poco stock (5 o menos)</h3><p><?= count($stockBajo) ?></p></div>
    </div>
    <?php if (count($stockBajo) > 0): ?>
    <table>
        <tr><th>Producto</th><th>Existencias</th></tr>
        <?php foreach ($stockBajo as $s): ?>
        <tr>
            <td><?= h($s['NombreProducto']) ?></td>
            <td class="alerta"><?= h($s['Stock']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

</div>

</body>
</html>

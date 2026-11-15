<?php
include 'conexion.php';
session_start();

// SOLO ADMIN: lista de proveedores; al dar clic se abren sus estadísticas
if (!isset($_SESSION['UsuarioID']) || !isset($_SESSION['RolID']) || $_SESSION['RolID'] != 1) {
    header("Location: login.html");
    exit();
}

function h($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function dinero($v) { return '$' . number_format((float) $v, 2); }

try {
    $sql = "SELECT p.ProveedorID, p.NombreProveedor,
                   COALESCE(p.EmailProveedor, u.EmailUsuario) AS Email,
                   (SELECT COUNT(*) FROM Producto pr WHERE pr.ProveedorID = p.ProveedorID) AS Productos,
                   (SELECT COUNT(DISTINCT d.PedidoID) FROM DetallePedido d JOIN Producto pr ON pr.ProductoID = d.ProductoID
                     WHERE pr.ProveedorID = p.ProveedorID AND COALESCE(d.Estado,'Pendiente') <> 'Cancelado') AS Pedidos,
                   (SELECT COALESCE(SUM(d.Cantidad),0) FROM DetallePedido d JOIN Producto pr ON pr.ProductoID = d.ProductoID
                     WHERE pr.ProveedorID = p.ProveedorID AND COALESCE(d.Estado,'Pendiente') <> 'Cancelado') AS Unidades,
                   (SELECT COALESCE(SUM(d.Cantidad * d.PrecioUnitario),0) FROM DetallePedido d JOIN Producto pr ON pr.ProductoID = d.ProductoID
                     WHERE pr.ProveedorID = p.ProveedorID AND COALESCE(d.Estado,'Pendiente') <> 'Cancelado') AS Ventas,
                   (SELECT COALESCE(SUM(d.Cantidad * d.PrecioUnitario),0) FROM DetallePedido d JOIN Producto pr ON pr.ProductoID = d.ProductoID
                     WHERE pr.ProveedorID = p.ProveedorID AND d.Estado = 'Entregado') AS Entregadas,
                   (SELECT COUNT(DISTINCT d.PedidoID) FROM DetallePedido d JOIN Producto pr ON pr.ProductoID = d.ProductoID
                     WHERE pr.ProveedorID = p.ProveedorID AND d.Estado = 'Cancelado') AS Cancelados
            FROM Proveedor p
            LEFT JOIN Usuario u ON u.UsuarioID = p.UsuarioID";
    $proveedores = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    usort($proveedores, function ($a, $b) { return (float) $b['Ventas'] <=> (float) $a['Ventas']; });

    // Pedidos de toda la plataforma por estado
    $porEstado = $conn->query("SELECT Estado, COUNT(*) AS Pedidos FROM Pedido GROUP BY Estado ORDER BY Estado")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("❌ Error al obtener estadísticas: " . h($e->getMessage()));
}

$totVentas = array_sum(array_column($proveedores, 'Ventas'));
$totUnidades = array_sum(array_column($proveedores, 'Unidades'));
$totPedidos = array_sum(array_column($porEstado, 'Pedidos'));
$mejor = (!empty($proveedores) && (float) $proveedores[0]['Ventas'] > 0) ? $proveedores[0]['NombreProveedor'] : '—';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Estadísticas - Admin DI-TEC</title>
<style>
    body { margin: 0; font-family: 'Segoe UI', sans-serif; background-color: #f4f6f9; color: #333; }
    .sidebar { background-color: #003366; color: white; width: 240px; height: 100vh; position: fixed; display: flex; flex-direction: column; justify-content: space-between; }
    .sidebar h2 { text-align: center; padding: 20px; background-color: #00264d; margin: 0; }
    .sidebar a { color: white; text-decoration: none; padding: 15px 20px; display: block; transition: background 0.3s; }
    .sidebar a:hover, .sidebar a.activo { background-color: #ff6600; }
    .logout { text-align: center; background-color: #ff6600; padding: 15px; }
    .logout a { color: white; text-decoration: none; font-weight: bold; padding: 0; display: inline; }
    .content { margin-left: 240px; padding: 30px; }
    h1 { color: #003366; margin-top: 0; }
    h2.seccion { color: #003366; margin-top: 30px; }
    .sub { color: #666; margin-top: -10px; }
    .cards { display: flex; flex-wrap: wrap; gap: 20px; }
    .card { flex: 1; min-width: 120px; background: white; padding: 20px; border-radius: 10px; text-align: center; box-shadow: 0 0 5px rgba(0,0,0,0.1); }
    .card h3 { margin: 0; color: #003366; font-size: 15px; }
    .card p { font-size: 24px; margin: 10px 0 0; color: #ff6600; font-weight: bold; }
    table { width: 100%; background: white; border-collapse: collapse; border-radius: 10px; overflow: hidden; box-shadow: 0 0 5px rgba(0,0,0,0.1); margin-top: 10px; }
    th { background: #003366; color: white; padding: 10px; }
    td { padding: 10px; text-align: center; border-bottom: 1px solid #eee; }
    tr.fila-prov { cursor: pointer; }
    tr.fila-prov:hover { background-color: #eef3f7; }
    .btn { background: #28a745; color: white; padding: 7px 12px; border-radius: 6px; text-decoration: none; white-space: nowrap; }
    .vacio { background: white; padding: 20px; border-radius: 10px; text-align: center; color: #777; box-shadow: 0 0 5px rgba(0,0,0,0.1); }
</style>
</head>
<body>

<div class="sidebar">
    <div>
        <h2>Admin DI-TEC</h2>
        <a href="gestion_usuarios.php">🧑‍💼 Gestión de Usuarios</a>
        <a href="gestion_proveedores.php">🏬 Gestión de Proveedores</a>
        <a href="gestion_pedidos.php">🗂️ Gestión de Pedidos</a>
        <a href="estadisticas.php" class="activo">📈 Estadísticas</a>
    </div>
    <div class="logout"><a href="logout.php">Cerrar Sesión</a></div>
</div>

<div class="content">
    <h1>📈 Estadísticas por proveedor</h1>
    <p class="sub">Da clic en un proveedor para ver sus estadísticas. Los pedidos cancelados no cuentan como venta.</p>

    <div class="cards">
        <div class="card"><h3>Proveedores</h3><p><?= count($proveedores) ?></p></div>
        <div class="card"><h3>Pedidos (todos)</h3><p><?= (int) $totPedidos ?></p></div>
        <div class="card"><h3>Unidades vendidas</h3><p><?= (int) $totUnidades ?></p></div>
        <div class="card"><h3>Ventas totales</h3><p><?= dinero($totVentas) ?></p></div>
        <div class="card"><h3>Proveedor con más ventas</h3><p style="font-size:18px;"><?= h($mejor) ?></p></div>
    </div>

    <h2 class="seccion">🏬 Proveedores</h2>
    <?php if (empty($proveedores)): ?>
        <div class="vacio">No hay proveedores registrados.</div>
    <?php else: ?>
    <table>
        <tr><th>Proveedor</th><th>Productos</th><th>Pedidos</th><th>Unidades</th><th>Ventas</th><th>Entregadas</th><th>Cancelados</th><th></th></tr>
        <?php foreach ($proveedores as $p): $url = 'estadisticas_proveedor.php?id=' . (int) $p['ProveedorID']; ?>
        <tr class="fila-prov" onclick="location.href='<?= $url ?>'">
            <td><b><?= h($p['NombreProveedor']) ?></b><br><small><?= h($p['Email']) ?></small></td>
            <td><?= (int) $p['Productos'] ?></td>
            <td><?= (int) $p['Pedidos'] ?></td>
            <td><?= (int) $p['Unidades'] ?></td>
            <td><?= dinero($p['Ventas']) ?></td>
            <td><?= dinero($p['Entregadas']) ?></td>
            <td><?= (int) $p['Cancelados'] ?></td>
            <td><a class="btn" href="<?= $url ?>">Ver estadísticas</a></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <h2 class="seccion">🚚 Pedidos de la plataforma por estado</h2>
    <?php if (empty($porEstado)): ?>
        <div class="vacio">Todavía no hay pedidos.</div>
    <?php else: ?>
    <table>
        <tr><th>Estado</th><th>Pedidos</th></tr>
        <?php foreach ($porEstado as $e): ?>
        <tr><td><?= h($e['Estado']) ?></td><td><?= (int) $e['Pedidos'] ?></td></tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

</body>
</html>

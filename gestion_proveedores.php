<?php
include 'conexion.php';
include 'seguridad.php';
include 'admin_helpers.php';
session_start();

// Verificar sesión y rol (solo administradores)
if (!isset($_SESSION['NombreUsuario']) || !isset($_SESSION['RolID']) || $_SESSION['RolID'] != 1) {
    header("Location: login.html");
    exit();
}

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

// 🗑️ Eliminar proveedor (solo por POST con token CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_proveedor'])) {
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        flash_set("❌ La solicitud no es válida. Recarga la página e inténtalo de nuevo.", 'error');
        header("Location: gestion_proveedores.php");
        exit();
    }
    $id = intval($_POST['eliminar_proveedor']);
    try {
        $st = $conn->prepare("SELECT UsuarioID, NombreProveedor FROM Proveedor WHERE ProveedorID = ?");
        $st->execute([$id]);
        $prov = $st->fetch(PDO::FETCH_ASSOC);
        if (!$prov) {
            flash_set("❌ El proveedor no existe.", 'error');
        } else {
            $conn->beginTransaction();
            $st = $conn->prepare("SELECT ProductoID FROM Producto WHERE ProveedorID = ?");
            $st->execute([$id]);
            $r = admin_eliminar_productos($conn, $st->fetchAll(PDO::FETCH_COLUMN));
            $conn->prepare("DELETE FROM Proveedor WHERE ProveedorID = ?")->execute([$id]);
            // También se elimina su cuenta de acceso (solo si es una cuenta de proveedor)
            $conn->prepare("DELETE FROM Usuario WHERE UsuarioID = ? AND RolID = 3")->execute([$prov['UsuarioID']]);
            $conn->commit();
            admin_borrar_imagenes($conn, $r['imagenes']);
            flash_set("🗑️ Proveedor «" . $prov['NombreProveedor'] . "» eliminado con su cuenta y sus " . $r['productos'] . " producto(s)." . admin_resumen_pedidos($r));
        }
    } catch (PDOException $e) {
        if ($conn->inTransaction()) { $conn->rollBack(); }
        flash_set("❌ Error al eliminar proveedor: " . $e->getMessage(), 'error');
    }
    header("Location: gestion_proveedores.php");
    exit();
}

$flash = flash_get();
$token = csrf_token();
$busqueda = trim($_GET['q'] ?? '');

// Obtener proveedores con sus cifras
try {
    $sql = "SELECT p.ProveedorID, p.NombreProveedor,
                   COALESCE(p.EmailProveedor, u.EmailUsuario) AS Email,
                   COALESCE(p.TelefonoProveedor, u.TelefonoUsuario) AS Telefono,
                   (SELECT COUNT(*) FROM Producto pr WHERE pr.ProveedorID = p.ProveedorID) AS Productos,
                   (SELECT COUNT(*) FROM Producto pr WHERE pr.ProveedorID = p.ProveedorID AND COALESCE(pr.Stock,0) <= 5) AS StockBajo,
                   (SELECT COUNT(DISTINCT d.PedidoID) FROM DetallePedido d JOIN Producto pr ON pr.ProductoID = d.ProductoID
                     WHERE pr.ProveedorID = p.ProveedorID) AS Pedidos,
                   (SELECT COALESCE(SUM(d.Cantidad * d.PrecioUnitario),0) FROM DetallePedido d JOIN Producto pr ON pr.ProductoID = d.ProductoID
                     WHERE pr.ProveedorID = p.ProveedorID) AS Ventas
            FROM Proveedor p
            LEFT JOIN Usuario u ON u.UsuarioID = p.UsuarioID";
    $params = [];
    if ($busqueda !== '') {
        $sql .= " WHERE p.NombreProveedor LIKE ? OR COALESCE(p.EmailProveedor, u.EmailUsuario) LIKE ?";
        $params = ["%$busqueda%", "%$busqueda%"];
    }
    $sql .= " ORDER BY p.NombreProveedor";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $proveedores = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("❌ Error al cargar proveedores: " . h($e->getMessage()));
}

$totProd = array_sum(array_column($proveedores, 'Productos'));
$totVentas = array_sum(array_column($proveedores, 'Ventas'));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestión de Proveedores - DI TEC</title>
<style>
    body { margin: 0; font-family: 'Segoe UI', sans-serif; background-color: #f4f6f9; color: #333; }
    .sidebar { background-color: #003366; color: white; width: 240px; height: 100vh; position: fixed; display: flex; flex-direction: column; justify-content: space-between; }
    .sidebar h2 { text-align: center; padding: 20px; background-color: #00264d; margin: 0; }
    .sidebar a { color: white; text-decoration: none; padding: 15px 20px; display: block; transition: background 0.3s; }
    .sidebar a:hover, .sidebar a.activo { background-color: #ff6600; }
    .logout { text-align: center; background-color: #ff6600; padding: 15px; }
    .logout a { color: white; text-decoration: none; font-weight: bold; padding: 0; display: inline; }
    .content { margin-left: 240px; padding: 30px; }
    h1 { color: #003366; margin-bottom: 20px; }
    .mensaje { background: #e0ffe0; border-left: 5px solid #00a300; padding: 10px; border-radius: 8px; margin-bottom: 15px; font-weight: bold; }
    .mensaje.error { background: #ffe3e0; border-left-color: #c0392b; }
    .resumen { display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 20px; }
    .card { background: white; border-radius: 10px; padding: 14px 20px; box-shadow: 0 2px 6px rgba(0,0,0,0.1); min-width: 150px; }
    .card h3 { margin: 0 0 6px; font-size: 13px; color: #666; font-weight: 600; }
    .card p { margin: 0; font-size: 22px; font-weight: bold; color: #003366; }
    .buscador { display: flex; gap: 8px; margin-bottom: 15px; }
    .buscador input { padding: 9px 12px; border: 1px solid #ccc; border-radius: 6px; width: 280px; }
    .buscador button, .buscador a { padding: 9px 14px; border: 0; border-radius: 6px; background: #003366; color: white; text-decoration: none; cursor: pointer; font-size: 14px; }
    table { width: 100%; border-collapse: collapse; background: white; border-radius: 10px; box-shadow: 0 2px 6px rgba(0,0,0,0.1); overflow: hidden; }
    th, td { padding: 12px; text-align: center; border-bottom: 1px solid #ddd; }
    th { background-color: #003366; color: white; }
    tr:hover { background-color: #eef3f7; }
    .alerta { color: #c0392b; font-weight: bold; }
    .btn-productos { background-color: #28a745; padding: 7px 12px; color: white; border-radius: 6px; text-decoration: none; margin-right: 5px; display: inline-block; }
    .btn-productos:hover { background-color: #1e7e34; }
    .btn-eliminar { background-color: #c0392b; padding: 7px 12px; color: white; border: 0; border-radius: 6px; cursor: pointer; font-size: 14px; }
    .btn-eliminar:hover { background-color: #922b21; }
    form.inline { display: inline; margin: 0; }
</style>
<script>
    function confirmarEliminar(nombre, productos, pedidos) {
        var msg = "❗ ¿Seguro que deseas eliminar al proveedor «" + nombre + "»?\n\n" +
                  "Se eliminarán su cuenta de acceso y sus " + productos + " producto(s).";
        if (pedidos > 0) {
            msg += "\n⚠️ Tiene " + pedidos + " pedido(s) con sus productos: se quitarán de esos pedidos y se recalcularán los totales.";
        }
        return confirm(msg);
    }
</script>
</head>
<body>

<div class="sidebar">
    <div>
        <h2>Admin DI-TEC</h2>
        <a href="gestion_usuarios.php">🧑‍💼 Gestión de Usuarios</a>
        <a href="gestion_proveedores.php" class="activo">🏬 Gestión de Proveedores</a>
        <a href="gestion_pedidos.php">🗂️ Gestión de Pedidos</a>
        <a href="estadisticas.php">📈 Estadísticas</a>
    </div>
    <div class="logout"><a href="logout.php">Cerrar sesión</a></div>
</div>

<div class="content">
    <h1>Gestión de Proveedores</h1>

    <?php if ($flash): ?>
        <div class="mensaje <?= $flash['tipo'] === 'error' ? 'error' : '' ?>"><?= h($flash['texto']) ?></div>
    <?php endif; ?>

    <div class="resumen">
        <div class="card"><h3>Proveedores<?= $busqueda !== '' ? ' (filtro)' : '' ?></h3><p><?= count($proveedores) ?></p></div>
        <div class="card"><h3>Productos publicados</h3><p><?= (int) $totProd ?></p></div>
        <div class="card"><h3>Ventas acumuladas</h3><p>$<?= number_format((float) $totVentas, 2) ?></p></div>
    </div>

    <form class="buscador" method="get">
        <input type="text" name="q" placeholder="Buscar por nombre o correo" value="<?= h($busqueda) ?>">
        <button type="submit">Buscar</button>
        <?php if ($busqueda !== ''): ?><a href="gestion_proveedores.php">Limpiar</a><?php endif; ?>
    </form>

    <?php if (empty($proveedores)) { ?>
        <p>No hay proveedores<?= $busqueda !== '' ? ' que coincidan con la búsqueda' : ' registrados' ?>.</p>
    <?php } else { ?>
        <table>
            <tr>
                <th>ID</th><th>Nombre</th><th>Email</th><th>Teléfono</th>
                <th>Productos</th><th>Pedidos</th><th>Ventas</th><th>Acciones</th>
            </tr>
            <?php foreach ($proveedores as $p) { ?>
                <tr>
                    <td><?= (int) $p['ProveedorID'] ?></td>
                    <td><?= h($p['NombreProveedor']) ?></td>
                    <td><?= h($p['Email']) ?></td>
                    <td><?= h($p['Telefono']) ?></td>
                    <td>
                        <?= (int) $p['Productos'] ?>
                        <?php if ((int) $p['StockBajo'] > 0): ?><br><small class="alerta">⚠ <?= (int) $p['StockBajo'] ?> con poco stock</small><?php endif; ?>
                    </td>
                    <td><?= (int) $p['Pedidos'] ?></td>
                    <td>$<?= number_format((float) $p['Ventas'], 2) ?></td>
                    <td>
                        <a href="ver_productos.php?id=<?= (int) $p['ProveedorID'] ?>" class="btn-productos">Ver productos</a>
                        <form class="inline" method="post"
                              onsubmit="return confirmarEliminar(<?= h(json_encode($p['NombreProveedor'], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>, <?= (int) $p['Productos'] ?>, <?= (int) $p['Pedidos'] ?>)">
                            <input type="hidden" name="csrf" value="<?= h($token) ?>">
                            <input type="hidden" name="eliminar_proveedor" value="<?= (int) $p['ProveedorID'] ?>">
                            <button type="submit" class="btn-eliminar">Eliminar</button>
                        </form>
                    </td>
                </tr>
            <?php } ?>
        </table>
    <?php } ?>
</div>

</body>
</html>

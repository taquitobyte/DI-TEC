<?php
include 'conexion.php';
include 'seguridad.php';
include 'admin_helpers.php';
session_start();

// Verificar login y rol admin
if (!isset($_SESSION['NombreUsuario']) || !isset($_SESSION['RolID']) || $_SESSION['RolID'] != 1) {
    header("Location: login.html");
    exit();
}

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

$proveedorID = intval($_GET['id'] ?? $_POST['proveedor_id'] ?? 0);
if ($proveedorID <= 0) {
    die("❌ Error: ID de proveedor no especificado.");
}

// 🗑️ Eliminar productos (uno o varios) — solo POST con token CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar'])) {
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        flash_set("❌ La solicitud no es válida. Recarga la página e inténtalo de nuevo.", 'error');
    } else {
        $ids = array_map('intval', (array) $_POST['eliminar']);
        try {
            // Solo productos que realmente pertenecen a este proveedor
            $validos = [];
            if (!empty($ids)) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $st = $conn->prepare("SELECT ProductoID FROM Producto WHERE ProveedorID = ? AND ProductoID IN ($in)");
                $st->execute(array_merge([$proveedorID], $ids));
                $validos = $st->fetchAll(PDO::FETCH_COLUMN);
            }
            if (empty($validos)) {
                flash_set("⚠️ No se seleccionó ningún producto válido.", 'error');
            } else {
                $conn->beginTransaction();
                $r = admin_eliminar_productos($conn, $validos);
                $conn->commit();
                admin_borrar_imagenes($conn, $r['imagenes']);
                flash_set("🗑️ " . $r['productos'] . " producto(s) eliminado(s)." . admin_resumen_pedidos($r));
            }
        } catch (PDOException $e) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            flash_set("❌ Error al eliminar: " . $e->getMessage(), 'error');
        }
    }
    header("Location: ver_productos.php?id=" . $proveedorID);
    exit();
}

$flash = flash_get();
$token = csrf_token();

try {
    $stmt = $conn->prepare("SELECT * FROM Proveedor WHERE ProveedorID = ?");
    $stmt->execute([$proveedorID]);
    $proveedor = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$proveedor) {
        die("❌ El proveedor no existe.");
    }

    $stmt2 = $conn->prepare("
        SELECT p.ProductoID, p.NombreProducto, p.Precio, p.Stock, p.Imagen,
               (SELECT COUNT(DISTINCT d.PedidoID) FROM DetallePedido d WHERE d.ProductoID = p.ProductoID) AS Pedidos
        FROM Producto p
        WHERE p.ProveedorID = ?
        ORDER BY p.NombreProducto
    ");
    $stmt2->execute([$proveedorID]);
    $productos = $stmt2->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("❌ Error al cargar productos: " . h($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Productos de <?= h($proveedor['NombreProveedor']) ?> - DI TEC</title>
<style>
    body { margin: 0; font-family: 'Segoe UI', sans-serif; background-color: #f4f6f9; color: #333; }
    .sidebar { background-color: #003366; color: white; width: 240px; height: 100vh; position: fixed; display: flex; flex-direction: column; justify-content: space-between; }
    .sidebar h2 { text-align: center; padding: 20px; background-color: #00264d; margin: 0; }
    .sidebar a { color: white; text-decoration: none; padding: 15px 20px; display: block; transition: background 0.3s; }
    .sidebar a:hover, .sidebar a.activo { background-color: #ff6600; }
    .logout { text-align: center; background-color: #ff6600; padding: 15px; }
    .logout a { color: white; text-decoration: none; font-weight: bold; padding: 0; display: inline; }
    .content { margin-left: 240px; padding: 30px; }
    h1 { color: #003366; margin-bottom: 5px; }
    .sub { color: #666; margin-bottom: 20px; }
    .mensaje { background: #e0ffe0; border-left: 5px solid #00a300; padding: 10px; border-radius: 8px; margin-bottom: 15px; font-weight: bold; }
    .mensaje.error { background: #ffe3e0; border-left-color: #c0392b; }
    table { width: 100%; border-collapse: collapse; background: white; border-radius: 10px; box-shadow: 0 2px 6px rgba(0,0,0,0.1); overflow: hidden; }
    th, td { padding: 12px; text-align: center; border-bottom: 1px solid #ddd; }
    th { background-color: #003366; color: white; }
    tr:hover { background-color: #eef3f7; }
    img.prod { width: 60px; height: 60px; object-fit: cover; border-radius: 6px; }
    .alerta { color: #c0392b; font-weight: bold; }
    .btn { padding: 8px 14px; border: 0; border-radius: 6px; color: white; cursor: pointer; font-size: 14px; text-decoration: none; display: inline-block; }
    .btn-volver { background: #003366; margin-bottom: 15px; }
    .btn-eliminar { background-color: #c0392b; }
    .btn-eliminar:hover { background-color: #922b21; }
    .barra { margin: 12px 0; display: flex; gap: 10px; align-items: center; }
    form.inline { display: inline; margin: 0; }
</style>
<script>
    function confirmarUno(nombre, pedidos) {
        var msg = "❗ ¿Eliminar el producto «" + nombre + "»?";
        if (pedidos > 0) {
            msg += "\n⚠️ Aparece en " + pedidos + " pedido(s): se quitará de ellos y se recalculará su total.";
        }
        return confirm(msg);
    }
    function confirmarVarios() {
        var n = document.querySelectorAll('input[name="eliminar[]"]:checked').length;
        if (n === 0) { alert("Selecciona al menos un producto."); return false; }
        return confirm("❗ ¿Eliminar los " + n + " producto(s) seleccionados?\nSi están en pedidos, se quitarán de ellos y se recalcularán los totales.");
    }
    function marcarTodos(chk) {
        document.querySelectorAll('input[name="eliminar[]"]').forEach(function (c) { c.checked = chk.checked; });
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
    <a href="gestion_proveedores.php" class="btn btn-volver">⬅ Volver a proveedores</a>
    <h1>Productos de <?= h($proveedor['NombreProveedor']) ?></h1>
    <div class="sub"><?= count($productos) ?> producto(s) publicado(s)</div>

    <?php if ($flash): ?>
        <div class="mensaje <?= $flash['tipo'] === 'error' ? 'error' : '' ?>"><?= h($flash['texto']) ?></div>
    <?php endif; ?>

    <?php if (empty($productos)) { ?>
        <p>Este proveedor no tiene productos.</p>
    <?php } else { ?>
        <form method="post" id="lote" onsubmit="return confirmarVarios()">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="proveedor_id" value="<?= $proveedorID ?>">
            <div class="barra">
                <button type="submit" class="btn btn-eliminar">🗑️ Eliminar seleccionados</button>
            </div>
            <table>
                <tr>
                    <th><input type="checkbox" onclick="marcarTodos(this)" title="Seleccionar todos"></th>
                    <th>ID</th><th>Imagen</th><th>Nombre</th><th>Precio</th><th>Stock</th><th>En pedidos</th><th>Acción</th>
                </tr>
                <?php foreach ($productos as $p) { ?>
                    <tr>
                        <td><input type="checkbox" name="eliminar[]" value="<?= (int) $p['ProductoID'] ?>"></td>
                        <td><?= (int) $p['ProductoID'] ?></td>
                        <td>
                            <?php if (!empty($p['Imagen'])): ?>
                                <img class="prod" src="<?= h($p['Imagen']) ?>" alt="">
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><?= h($p['NombreProducto']) ?></td>
                        <td>$<?= number_format((float) $p['Precio'], 2) ?></td>
                        <td class="<?= ((int) $p['Stock']) <= 5 ? 'alerta' : '' ?>"><?= (int) $p['Stock'] ?></td>
                        <td><?= (int) $p['Pedidos'] ?></td>
                        <td>
                            <button type="submit" class="btn btn-eliminar" form="f<?= (int) $p['ProductoID'] ?>">Eliminar</button>
                        </td>
                    </tr>
                <?php } ?>
            </table>
        </form>

        <?php foreach ($productos as $p) { ?>
            <form id="f<?= (int) $p['ProductoID'] ?>" class="inline" method="post"
                  onsubmit="return confirmarUno(<?= h(json_encode($p['NombreProducto'], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>, <?= (int) $p['Pedidos'] ?>)">
                <input type="hidden" name="csrf" value="<?= h($token) ?>">
                <input type="hidden" name="proveedor_id" value="<?= $proveedorID ?>">
                <input type="hidden" name="eliminar[]" value="<?= (int) $p['ProductoID'] ?>">
            </form>
        <?php } ?>
    <?php } ?>
</div>

</body>
</html>

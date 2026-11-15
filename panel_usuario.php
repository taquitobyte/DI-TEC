<?php
include 'conexion.php';
session_start();

if (!isset($_SESSION['UsuarioID'])) {
    header("Location: login.html");
    exit();
}

// Obtener productos reales registrados en la tabla Producto
try {
    // Filtros del catálogo (búsqueda, proveedor, rango de precio y orden)
    $q = trim($_GET['q'] ?? '');
    $provFiltro = intval($_GET['proveedor'] ?? 0);
    $min = ($_GET['min'] ?? '') !== '' ? floatval($_GET['min']) : null;
    $max = ($_GET['max'] ?? '') !== '' ? floatval($_GET['max']) : null;
    $orden = $_GET['orden'] ?? '';
    $ordenes = ['precio_asc' => 'p.Precio ASC', 'precio_desc' => 'p.Precio DESC', 'nombre' => 'p.NombreProducto ASC'];

    $query = "SELECT p.ProductoID, p.NombreProducto AS Nombre, p.Descripcion, p.Precio, p.Imagen, p.Stock
              FROM Producto p
              WHERE p.Stock > 0";
    $params = [];
    if ($q !== '') { $query .= " AND (p.NombreProducto LIKE ? OR p.Descripcion LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; }
    if ($provFiltro > 0) { $query .= " AND p.ProveedorID = ?"; $params[] = $provFiltro; }
    if ($min !== null) { $query .= " AND p.Precio >= ?"; $params[] = $min; }
    if ($max !== null) { $query .= " AND p.Precio <= ?"; $params[] = $max; }
    $query .= " ORDER BY " . ($ordenes[$orden] ?? 'p.ProductoID DESC');

    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $proveedoresLista = $conn->query("SELECT ProveedorID, NombreProveedor FROM Proveedor ORDER BY NombreProveedor")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Error al cargar los productos: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Usuario - DI-TEC</title>
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
        .productos {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
            gap: 20px;
            padding: 40px;
        }
        .producto {
            background-color: white;
            border-radius: 10px;
            padding: 15px;
            box-shadow: 0 0 5px rgba(0,0,0,0.1);
            text-align: center;
            transition: transform 0.3s;
        }
        .producto:hover {
            transform: translateY(-4px);
        }
        .producto img {
            width: 180px;
            height: 180px;
            object-fit: cover;
            border-radius: 10px;
            background-color: #e0e0e0;
        }
        .producto h3 {
            margin: 10px 0 5px;
            color: #003366;
        }
        .producto p {
            font-size: 14px;
            color: #555;
        }
        .precio {
            font-weight: bold;
            color: #222;
            margin: 8px 0;
            font-size: 16px;
        }
        .stock {
            color: #007bff;
            font-size: 13px;
            margin-top: 5px;
        }
        .btn {
            background-color: #003366;
            color: white;
            padding: 8px 14px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            margin-top: 8px;
            transition: background 0.3s;
        }
        .btn:hover {
            background-color: #004c99;
        }
        .agotado {
            background-color: #ccc;
            color: #666;
            padding: 8px 14px;
            border-radius: 6px;
            cursor: not-allowed;
        }
        .filtros { display:flex; gap:10px; flex-wrap:wrap; align-items:center; padding:15px 50px 0; }
        .filtros input, .filtros select { padding:9px 12px; border:1px solid #ccc; border-radius:6px; }
        .filtros button, .filtros a { padding:9px 14px; border:0; border-radius:6px; background:#003366; color:white; text-decoration:none; cursor:pointer; font-size:14px; }
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

    <form class="filtros" method="get">
        <input type="text" name="q" placeholder="Buscar producto" value="<?= htmlspecialchars($q) ?>">
        <select name="proveedor">
            <option value="0">Todos los proveedores</option>
            <?php foreach ($proveedoresLista as $pv): ?>
                <option value="<?= (int) $pv['ProveedorID'] ?>" <?= $provFiltro === (int) $pv['ProveedorID'] ? 'selected' : '' ?>><?= htmlspecialchars($pv['NombreProveedor']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="number" name="min" min="0" step="0.01" placeholder="Precio mín." value="<?= htmlspecialchars($_GET['min'] ?? '') ?>" style="width:110px;">
        <input type="number" name="max" min="0" step="0.01" placeholder="Precio máx." value="<?= htmlspecialchars($_GET['max'] ?? '') ?>" style="width:110px;">
        <select name="orden">
            <option value="">Más recientes</option>
            <option value="precio_asc" <?= $orden === 'precio_asc' ? 'selected' : '' ?>>Precio: menor a mayor</option>
            <option value="precio_desc" <?= $orden === 'precio_desc' ? 'selected' : '' ?>>Precio: mayor a menor</option>
            <option value="nombre" <?= $orden === 'nombre' ? 'selected' : '' ?>>Nombre (A-Z)</option>
        </select>
        <button type="submit">Buscar</button>
        <?php if ($q !== '' || $provFiltro > 0 || $min !== null || $max !== null || $orden !== ''): ?><a href="panel_usuario.php">Limpiar</a><?php endif; ?>
    </form>

    <div class="productos">
        <?php if (count($productos) > 0): ?>
            <?php foreach ($productos as $producto): 
                $imagenRuta = (!empty($producto['Imagen']) && file_exists($producto['Imagen']))
                    ? $producto['Imagen']
                    : 'img/sin_imagen.png';
            ?>
            <div class="producto">
                <img src="<?= htmlspecialchars($imagenRuta) ?>" alt="Producto">

                <h3><?= htmlspecialchars($producto['Nombre']) ?></h3>
                <p><?= htmlspecialchars($producto['Descripcion']) ?></p>

                <p class="precio">$<?= number_format($producto['Precio'], 2) ?></p>
                <p class="stock">Stock disponible: <?= htmlspecialchars($producto['Stock']) ?></p>

                <?php if ($producto['Stock'] > 0): ?>
                    <button class="btn" onclick="agregarAlCarrito(<?= $producto['ProductoID'] ?>)">Agregar al carrito</button>
                <?php else: ?>
                    <button class="agotado" disabled>Agotado</button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="grid-column: 1 / -1; text-align:center;">No hay productos que coincidan.</p>
        <?php endif; ?>
    </div>

    <script>
        function agregarAlCarrito(id) {
            window.location.href = "agregar_carrito.php?producto=" + id;
        }
    </script>

</body>
</html>

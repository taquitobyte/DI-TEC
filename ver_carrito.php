<?php
include 'conexion.php';
session_start();

if (!isset($_SESSION['UsuarioID'])) {
    header("Location: login.html");
    exit();
}

$usuarioID = $_SESSION['UsuarioID'];

/* =======================================================
   ACTUALIZAR CANTIDADES DEL CARRITO
======================================================= */
if (isset($_GET['accion']) && isset($_GET['producto'])) {

    $productoID = intval($_GET['producto']);

    // Obtener stock real del producto
    $stmtStock = $conn->prepare("SELECT Stock FROM Producto WHERE ProductoID = ?");
    $stmtStock->execute([$productoID]);
    $stockReal = $stmtStock->fetchColumn();

    if (!$stockReal) {
        die("Error: El producto no existe.");
    }

    if ($_GET['accion'] == 'sumar') {

        $stmt = $conn->prepare("
            UPDATE Carrito 
            SET Cantidad = Cantidad + 1
            WHERE UsuarioID = ? AND ProductoID = ? AND Cantidad < ?
        ");
        $stmt->execute([$usuarioID, $productoID, $stockReal]);

    } elseif ($_GET['accion'] == 'restar') {

        $stmt = $conn->prepare("
            UPDATE Carrito 
            SET Cantidad = Cantidad - 1
            WHERE UsuarioID = ? AND ProductoID = ? AND Cantidad > 1
        ");
        $stmt->execute([$usuarioID, $productoID]);

    } elseif ($_GET['accion'] == 'eliminar') {

        $stmt = $conn->prepare("DELETE FROM Carrito WHERE UsuarioID = ? AND ProductoID = ?");
        $stmt->execute([$usuarioID, $productoID]);
    }

    header("Location: ver_carrito.php");
    exit();
}

/* =======================================================
   OBTENER PRODUCTOS DEL CARRITO
======================================================= */
try {
    $query = "
        SELECT 
            c.ProductoID, 
            p.NombreProducto AS Nombre, 
            p.Precio, 
            p.Imagen, 
            c.Cantidad,
            (p.Precio * c.Cantidad) AS Subtotal
        FROM Carrito c
        INNER JOIN Producto p ON c.ProductoID = p.ProductoID
        WHERE c.UsuarioID = ?
    ";

    $stmt = $conn->prepare($query);
    $stmt->execute([$usuarioID]);
    $carrito = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Error al cargar el carrito: " . $e->getMessage());
}

/* =======================================================
   CALCULAR TOTAL
======================================================= */
$total = 0;
foreach ($carrito as $item) {
    $total += $item['Subtotal'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>🛒 Carrito - DI-TEC</title>
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
    table {
        width: 100%;
        border-collapse: collapse;
    }
    th {
        background-color: #003366;
        color: white;
        padding: 12px;
    }
    td {
        padding: 12px;
        border-bottom: 1px solid #ddd;
        text-align: center;
    }
    img {
        width: 80px;
        height: 80px;
        border-radius: 8px;
        object-fit: cover;
        background: #eee;
    }
    .acciones button {
        background-color: #003366;
        color: white;
        border: none;
        padding: 6px 10px;
        border-radius: 5px;
        cursor: pointer;
        transition: 0.3s;
    }
    .acciones button:hover {
        background-color: #005bb5;
    }
    .eliminar {
        background-color: #cc0000;
    }
    .eliminar:hover {
        background-color: #a00000;
    }
    .total {
        text-align: right;
        font-size: 20px;
        font-weight: bold;
        color: #003366;
        margin-top: 20px;
    }
    .btn-comprar {
        background-color: #007bff;
        color: white;
        padding: 12px 25px;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        margin-top: 20px;
        float: right;
        font-weight: bold;
    }
    .btn-comprar:hover {
        background-color: #005bb5;
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
    <h2>🛒 Tu Carrito</h2>

    <?php if (count($carrito) > 0): ?>
    <table>
        <tr>
            <th>Producto</th>
            <th>Imagen</th>
            <th>Precio</th>
            <th>Cantidad</th>
            <th>Subtotal</th>
            <th>Acciones</th>
        </tr>

        <?php foreach ($carrito as $item): ?>
        <tr>
            <td><?= htmlspecialchars($item['Nombre']) ?></td>

            <td>
                <img src="<?= file_exists($item['Imagen']) ? $item['Imagen'] : 'img/sin_imagen.png' ?>">
            </td>

            <td>$<?= number_format($item['Precio'], 2) ?></td>
            <td><?= $item['Cantidad'] ?></td>
            <td>$<?= number_format($item['Subtotal'], 2) ?></td>

            <td class="acciones">
                <button onclick="window.location='ver_carrito.php?accion=sumar&producto=<?= $item['ProductoID'] ?>'">+</button>
                <button onclick="window.location='ver_carrito.php?accion=restar&producto=<?= $item['ProductoID'] ?>'">−</button>
                <button class="eliminar" onclick="if(confirm('¿Eliminar este producto del carrito?')) window.location='ver_carrito.php?accion=eliminar&producto=<?= $item['ProductoID'] ?>'">🗑 Eliminar</button>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>

    <div class="total">
        Total: $<?= number_format($total, 2) ?>
    </div>

    <button class="btn-comprar" onclick="window.location='finalizar_compra.php'">Finalizar compra</button>

    <?php else: ?>
        <p style="text-align:center; font-size:18px;">Tu carrito está vacío 🛒</p>
    <?php endif; ?>

</div>

</body>
</html>

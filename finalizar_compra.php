<?php
include 'conexion.php';
session_start();

if (!isset($_SESSION['UsuarioID'])) {
    header("Location: login.html");
    exit();
}

$usuarioID = $_SESSION['UsuarioID'];

// Obtener productos del carrito
try {
    $query = "
        SELECT c.ProductoID, p.NombreProducto, p.Precio, c.Cantidad, p.Imagen
        FROM Carrito c
        INNER JOIN Producto p ON c.ProductoID = p.ProductoID
        WHERE c.UsuarioID = ?
    ";

    $stmt = $conn->prepare($query);
    $stmt->execute([$usuarioID]);
    $carrito = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch(PDOException $e) {
    die("❌ Error al cargar el carrito: " . $e->getMessage());
}

// Si carrito vacío, no mostrar pago
if (count($carrito) == 0) {
    echo "<script>alert('Tu carrito está vacío.'); window.location='panel_usuario.php';</script>";
    exit();
}

// Calcular total
$total = 0;
foreach ($carrito as $item) {
    $total += ($item['Precio'] * $item['Cantidad']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Finalizar Compra - DI-TEC</title>

<style>
    body {
        font-family: 'Segoe UI', sans-serif;
        background-color: #f4f6f9;
        margin: 0;
        padding: 0;
        color: #333;
    }

    .container {
        width: 90%;
        margin: 30px auto;
        padding: 25px;
        background: white;
        border-radius: 12px;
        box-shadow: 0 0 10px rgba(0,0,0,0.1);
    }

    h2 {
        color: #003366;
        margin-bottom: 20px;
        text-align: center;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 25px;
    }

    th, td {
        padding: 12px;
        text-align: center;
        border-bottom: 1px solid #ddd;
    }

    th {
        background-color: #003366;
        color: white;
    }

    .total {
        text-align: right;
        font-size: 20px;
        margin-top: 15px;
        font-weight: bold;
        color: #003366;
    }

    /* Formulario de Tarjeta */
    .card-box {
        background: #eef3f9;
        padding: 20px;
        border-radius: 12px;
        margin-top: 25px;
    }

    label {
        font-weight: bold;
        display: block;
        margin-top: 10px;
    }

    input {
        width: 100%;
        padding: 10px;
        margin-top: 5px;
        border-radius: 6px;
        border: 1px solid #ccc;
        font-size: 15px;
    }

    .btn-pagar {
        background-color: #28a745;
        color: white;
        width: 100%;
        padding: 14px;
        border: none;
        margin-top: 20px;
        border-radius: 8px;
        font-size: 17px;
        cursor: pointer;
        font-weight: bold;
    }

    .btn-pagar:hover {
        background-color: #218838;
    }
</style>

</head>
<body>

<div class="container">
    <h2>Finalizar Compra</h2>

    <!-- TABLA DE PRODUCTOS -->
    <table>
        <tr>
            <th>Producto</th>
            <th>Cantidad</th>
            <th>Precio</th>
            <th>Subtotal</th>
        </tr>

        <?php foreach ($carrito as $item): ?>
            <tr>
                <td><?= htmlspecialchars($item['NombreProducto']) ?></td>
                <td><?= $item['Cantidad'] ?></td>
                <td>$<?= number_format($item['Precio'], 2) ?></td>
                <td>$<?= number_format($item['Precio'] * $item['Cantidad'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <p class="total">TOTAL A PAGAR: $<?= number_format($total, 2) ?></p>

    <!-- FORMULARIO DE TARJETA -->
    <div class="card-box">
        <form action="procesar_pago.php" method="POST">

            <h3 style="color:#003366;">💳 Información de la Tarjeta</h3>

            <label>Número de la tarjeta</label>
            <input type="text" name="num_tarjeta" maxlength="16" required>

            <label>Nombre del titular</label>
            <input type="text" name="nombre_titular" required>

            <label>Fecha de expiración (MM/AA)</label>
            <input type="text" name="exp" maxlength="5" placeholder="MM/AA" required>

            <label>Código CVV</label>
            <input type="password" name="cvv" maxlength="3" required>

            <input type="hidden" name="total" value="<?= $total ?>">

            <button type="submit" class="btn-pagar">Confirmar Pago</button>
        </form>
    </div>
</div>

</body>
</html>

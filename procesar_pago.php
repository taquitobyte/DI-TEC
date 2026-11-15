<?php
include 'conexion.php';
session_start();

// Validar sesión
if (!isset($_SESSION['UsuarioID'])) {
    header("Location: login.html");
    exit();
}

$usuarioID = $_SESSION['UsuarioID'];

// Validar que el usuario venga desde el formulario
if (!isset($_POST['total'])) {
    die("❌ Acceso inválido.");
}

// (el total que manda el formulario se ignora: se recalcula con los precios reales)

// --- Datos de tarjeta (NO se guardan por seguridad)
$numTarjeta = $_POST['num_tarjeta'];
$nombreTitular = $_POST['nombre_titular'];
$exp = $_POST['exp'];
$cvv = $_POST['cvv'];

// Simulación de validación rápida
if (strlen($numTarjeta) < 16 || strlen($cvv) < 3) {
    echo "<script>alert('❌ Datos de tarjeta inválidos.'); window.location='finalizar_compra.php';</script>";
    exit();
}

// =======================================================
//            OBTENER PRODUCTOS DEL CARRITO
// =======================================================
try {
    $query = "
        SELECT c.ProductoID, p.NombreProducto, p.Precio, c.Cantidad, p.Stock
        FROM Carrito c
        INNER JOIN Producto p ON c.ProductoID = p.ProductoID
        WHERE c.UsuarioID = ?
    ";

    $stmt = $conn->prepare($query);
    $stmt->execute([$usuarioID]);
    $carrito = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("❌ Error al obtener el carrito: " . $e->getMessage());
}

if (count($carrito) == 0) {
    echo "<script>alert('Tu carrito está vacío.'); window.location='panel_usuario.php';</script>";
    exit();
}

// Total real y revisión de existencias (con los datos de la base, no los del formulario)
$total = 0;
$faltantes = [];
foreach ($carrito as $item) {
    $total += $item['Precio'] * $item['Cantidad'];
    if ($item['Cantidad'] > $item['Stock']) {
        $faltantes[] = $item['NombreProducto'] . " (disponibles: " . max(0, (int) $item['Stock']) . ")";
    }
}
if (!empty($faltantes)) {
    $msg = json_encode("⚠️ No hay existencias suficientes de: " . implode(", ", $faltantes) . ". Ajusta tu carrito.",
                       JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    echo "<script>alert($msg); window.location='ver_carrito.php';</script>";
    exit();
}

// =======================================================
//                INICIAR TRANSACCIÓN
// =======================================================
$conn->beginTransaction();

try {
    // Crear pedido
    $stmt = $conn->prepare("
        INSERT INTO Pedido (UsuarioID, FechaPedido, Total, Estado)
        VALUES (?, GETDATE(), ?, 'Pendiente')
    ");
    $stmt->execute([$usuarioID, $total]);

    $pedidoID = $conn->lastInsertId();

    // Insertar detalles y restar stock
    $detalleSQL = "
        INSERT INTO DetallePedido (PedidoID, ProductoID, Cantidad, PrecioUnitario)
        VALUES (?, ?, ?, ?)
    ";

    // Solo descuenta si todavía alcanza (evita que dos compras simultáneas dejen stock negativo)
    $actualizarStockSQL = "
        UPDATE Producto
        SET Stock = Stock - ?
        WHERE ProductoID = ? AND Stock >= ?
    ";

    foreach ($carrito as $item) {

        // Insertar detalle
        $stmt = $conn->prepare($detalleSQL);
        $stmt->execute([
            $pedidoID,
            $item['ProductoID'],
            $item['Cantidad'],
            $item['Precio']
        ]);

        // Restar stock
        $stmt = $conn->prepare($actualizarStockSQL);
        $stmt->execute([
            $item['Cantidad'],
            $item['ProductoID'],
            $item['Cantidad']
        ]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException("Sin existencias suficientes de " . $item['NombreProducto']);
        }
    }

    // Vaciar carrito
    $stmt = $conn->prepare("DELETE FROM Carrito WHERE UsuarioID = ?");
    $stmt->execute([$usuarioID]);

    // Confirmar transacción
    $conn->commit();

    echo "<script>
            alert('✅ ¡Pago realizado con éxito! Pedido #$pedidoID creado.');
            window.location='mis_pedidos.php';
          </script>";
    exit();

} catch (RuntimeException $e) {
    $conn->rollBack();
    $msg = json_encode("⚠️ " . $e->getMessage() . ". Ajusta tu carrito.", JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    echo "<script>alert($msg); window.location='ver_carrito.php';</script>";
    exit();
} catch (PDOException $e) {
    $conn->rollBack();
    die("❌ Error al procesar el pedido: " . htmlspecialchars($e->getMessage()));
}
?>

<?php
include 'conexion.php';
include 'seguridad.php';
session_start();

// Poner en true solo si quieren exigir números de tarjeta con dígito verificador válido (Luhn).
// Se deja en false para que sigan funcionando los números de prueba (ej. 1234567890123456).
const VALIDAR_LUHN = false;

// Validar sesión
if (!isset($_SESSION['UsuarioID'])) {
    header("Location: login.html");
    exit();
}

$usuarioID = $_SESSION['UsuarioID'];

// Solo se acepta el envío del formulario (POST) con su token CSRF
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: finalizar_compra.php");
    exit();
}
if (!csrf_valido($_POST['csrf'] ?? null)) {
    alerta_redirigir("❌ La solicitud no es válida. Vuelve a intentarlo.", "finalizar_compra.php");
}

// (el total se recalcula en el servidor con los precios reales; nunca se confía en el formulario)

/* =======================================================
   VALIDACIÓN DE LOS DATOS DE LA TARJETA
   (simulación: los datos NO se guardan por seguridad)
======================================================= */
function luhn_valido(string $numero): bool
{
    $suma = 0;
    $alternar = false;
    for ($i = strlen($numero) - 1; $i >= 0; $i--) {
        $d = (int) $numero[$i];
        if ($alternar) {
            $d *= 2;
            if ($d > 9) {
                $d -= 9;
            }
        }
        $suma += $d;
        $alternar = !$alternar;
    }
    return $suma % 10 === 0;
}

$numTarjeta    = preg_replace('/\D/', '', (string) ($_POST['num_tarjeta'] ?? ''));
$nombreTitular = trim((string) ($_POST['nombre_titular'] ?? ''));
$exp           = trim((string) ($_POST['exp'] ?? ''));
$cvv           = trim((string) ($_POST['cvv'] ?? ''));

$errorTarjeta = null;
if (!preg_match('/^\d{16}$/', $numTarjeta)) {
    $errorTarjeta = "El número de tarjeta debe tener 16 dígitos.";
} elseif (VALIDAR_LUHN && !luhn_valido($numTarjeta)) {
    $errorTarjeta = "El número de tarjeta no es válido.";
} elseif ($nombreTitular === '') {
    $errorTarjeta = "Escribe el nombre del titular.";
} elseif (!preg_match('/^(0[1-9]|1[0-2])\/(\d{2})$/', $exp, $m)) {
    $errorTarjeta = "La fecha de expiración debe tener el formato MM/AA.";
} elseif ((2000 + (int) $m[2]) * 100 + (int) $m[1] < (int) date('Ym')) {
    $errorTarjeta = "La tarjeta está vencida.";
} elseif (!preg_match('/^\d{3}$/', $cvv)) {
    $errorTarjeta = "El CVV debe tener 3 dígitos.";
}
if ($errorTarjeta !== null) {
    alerta_redirigir("❌ Datos de tarjeta inválidos. " . $errorTarjeta, "finalizar_compra.php");
}

/* =======================================================
   TRANSACCIÓN: el carrito se lee DENTRO de la transacción
   para que lo que se cobra sea exactamente lo que se guarda.
   Con UPDLOCK, si el usuario presiona dos veces "Confirmar
   Pago" (o lo hace en dos pestañas), la segunda petición
   espera a la primera y ya encuentra el carrito vacío:
   no se duplica el pedido.
======================================================= */
$conn->beginTransaction();

try {
    // 1) Leer el carrito (renglones bloqueados) en orden fijo de producto
    $stmt = $conn->prepare("
        SELECT c.ProductoID, p.NombreProducto, p.Precio, c.Cantidad, p.Stock
        FROM Carrito c WITH (UPDLOCK, ROWLOCK)
        INNER JOIN Producto p ON c.ProductoID = p.ProductoID
        WHERE c.UsuarioID = ?
        ORDER BY c.ProductoID
    ");
    $stmt->execute([$usuarioID]);
    $carrito = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($carrito) === 0) {
        $conn->rollBack();
        alerta_redirigir("Tu carrito está vacío o tu pedido ya fue procesado.", "mis_pedidos.php");
    }

    // 2) Total real y revisión de existencias con los datos de la base
    $total = 0;
    $faltantes = [];
    foreach ($carrito as $item) {
        $total += $item['Precio'] * $item['Cantidad'];
        if ($item['Cantidad'] > (int) $item['Stock']) {
            $faltantes[] = $item['NombreProducto'] . " (disponibles: " . max(0, (int) $item['Stock']) . ")";
        }
    }
    $total = round($total, 2);

    if (!empty($faltantes)) {
        $conn->rollBack();
        alerta_redirigir(
            "⚠️ No hay existencias suficientes de: " . implode(", ", $faltantes) . ". Ajusta tu carrito.",
            "ver_carrito.php"
        );
    }

    // 3) Crear pedido
    $stmt = $conn->prepare("
        INSERT INTO Pedido (UsuarioID, FechaPedido, Total, Estado)
        VALUES (?, GETDATE(), ?, 'Pendiente')
    ");
    $stmt->execute([$usuarioID, $total]);
    $pedidoID = (int) $conn->lastInsertId();

    // 4) Insertar detalles y restar stock
    $detalleStmt = $conn->prepare("
        INSERT INTO DetallePedido (PedidoID, ProductoID, Cantidad, PrecioUnitario)
        VALUES (?, ?, ?, ?)
    ");

    // Solo descuenta si todavía alcanza (evita que dos compras simultáneas dejen stock negativo)
    $stockStmt = $conn->prepare("
        UPDATE Producto
        SET Stock = Stock - ?
        WHERE ProductoID = ? AND Stock >= ?
    ");

    foreach ($carrito as $item) {
        $detalleStmt->execute([$pedidoID, $item['ProductoID'], $item['Cantidad'], $item['Precio']]);

        $stockStmt->execute([$item['Cantidad'], $item['ProductoID'], $item['Cantidad']]);
        if ($stockStmt->rowCount() === 0) {
            throw new RuntimeException("Sin existencias suficientes de " . $item['NombreProducto']);
        }
    }

    // 5) Vaciar carrito
    $stmt = $conn->prepare("DELETE FROM Carrito WHERE UsuarioID = ?");
    $stmt->execute([$usuarioID]);

    // 6) Confirmar transacción (todo o nada)
    $conn->commit();

    alerta_redirigir("✅ ¡Pago realizado con éxito! Pedido #$pedidoID creado.", "mis_pedidos.php");

} catch (RuntimeException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    alerta_redirigir("⚠️ " . $e->getMessage() . ". Ajusta tu carrito.", "ver_carrito.php");

} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("DI-TEC procesar_pago: " . $e->getMessage());
    alerta_redirigir("❌ No se pudo procesar el pago. No se realizó ningún cargo; inténtalo de nuevo.", "ver_carrito.php");
}
?>

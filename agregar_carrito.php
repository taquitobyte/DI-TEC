<?php
include 'conexion.php';
session_start();

// Verifica que el usuario haya iniciado sesión
if (!isset($_SESSION['UsuarioID'])) {
    header("Location: login.html");
    exit();
}

$usuarioID = $_SESSION['UsuarioID'];

// Verifica que el producto se haya enviado correctamente
if (!isset($_GET['producto'])) {
    die("❌ No se recibió el producto.");
}

$productoID = intval($_GET['producto']);

try {

    // =============================
    // 1) Validar que el producto exista
    // =============================
    $prodStmt = $conn->prepare("SELECT Stock FROM Producto WHERE ProductoID = ?");
    $prodStmt->execute([$productoID]);
    $stock = $prodStmt->fetchColumn();

    if ($stock === false) {
        echo "<script>alert('❌ El producto no existe.'); window.location='panel_usuario.php';</script>";
        exit();
    }

    if ($stock <= 0) {
        echo "<script>alert('❌ Producto sin stock disponible.'); window.location='panel_usuario.php';</script>";
        exit();
    }

    // =============================
    // 2) Verificar si ya está en el carrito
    // =============================
    $check = $conn->prepare("SELECT Cantidad FROM Carrito WHERE UsuarioID = ? AND ProductoID = ?");
    $check->execute([$usuarioID, $productoID]);
    $existe = $check->fetch(PDO::FETCH_ASSOC);

    // =============================
    // 3) Si ya existe → aumentar cantidad hasta el stock máximo
    // =============================
    if ($existe) {

        if ($existe['Cantidad'] < $stock) {
            $update = $conn->prepare("UPDATE Carrito 
                                      SET Cantidad = Cantidad + 1 
                                      WHERE UsuarioID = ? AND ProductoID = ?");
            $update->execute([$usuarioID, $productoID]);
        } else {
            echo "<script>alert('⚠️ No hay más stock disponible para este producto.'); 
                  window.location='panel_usuario.php';</script>";
            exit();
        }

    } else {

        // =============================
        // 4) Si no está → insertarlo con cantidad = 1
        // =============================
        $insert = $conn->prepare("INSERT INTO Carrito (UsuarioID, ProductoID, Cantidad) 
                                  VALUES (?, ?, 1)");
        $insert->execute([$usuarioID, $productoID]);
    }

    // =============================
    // 5) Redirigir con mensaje
    // =============================
    echo "<script>alert('✅ Producto agregado al carrito.'); 
          window.location='panel_usuario.php';</script>";
    exit();

} catch (PDOException $e) {
    die("❌ Error al agregar al carrito: " . $e->getMessage());
}
?>

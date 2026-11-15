<?php
include 'conexion.php';
session_start();

// SOLO PROVEEDORES (RolID = 3)
if (!isset($_SESSION['UsuarioID']) || !isset($_SESSION['RolID']) || $_SESSION['RolID'] != 3) {
    header("Location: login.html");
    exit();
}

$usuarioID = $_SESSION['UsuarioID'];
$mensaje   = "";

// -----------------------------------------------------
// OBTENER ProveedorID ASOCIADO AL USUARIO ACTUAL
// -----------------------------------------------------
try {
    $stmt = $conn->prepare("SELECT ProveedorID FROM Proveedor WHERE UsuarioID = ?");
    $stmt->execute([$usuarioID]);
    $proveedorID = $stmt->fetchColumn();

    if (!$proveedorID) {
        die("❌ Error: No existe un registro de proveedor asociado a tu cuenta.");
    }
} catch (PDOException $e) {
    die("❌ Error al obtener proveedor: " . $e->getMessage());
}

// -----------------------------------------------------
// AGREGAR PRODUCTO
// -----------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['accion']) && $_POST['accion'] === "agregar") {

    $nombre      = $_POST['nombre'] ?? '';
    $descripcion = $_POST['descripcion'] ?? '';
    $precio      = $_POST['precio'] ?? 0;
    $stock       = $_POST['stock'] ?? 0;

    $imagenRuta = null;

    // Manejo de imagen (carpeta: img/)
    if (!empty($_FILES['imagen']['name'])) {
        $carpeta = "img/";
        if (!is_dir($carpeta)) {
            mkdir($carpeta, 0777, true);
        }

        $extension   = pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION);
        $nuevoNombre = "prod_" . uniqid() . "." . $extension;
        $rutaDestino = $carpeta . $nuevoNombre;

        if (move_uploaded_file($_FILES['imagen']['tmp_name'], $rutaDestino)) {
            $imagenRuta = $rutaDestino;
        } else {
            $mensaje = "⚠ No se pudo subir la imagen. Se guardará sin imagen.";
        }
    }

    try {
        $sql = "INSERT INTO Producto (NombreProducto, Descripcion, Precio, Stock, Imagen, ProveedorID)
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$nombre, $descripcion, $precio, $stock, $imagenRuta, $proveedorID]);

        $mensaje = "✅ Producto agregado correctamente.";
    } catch (PDOException $e) {
        $mensaje = "❌ Error al agregar producto: " . $e->getMessage();
    }
}

// -----------------------------------------------------
// EDITAR PRODUCTO
// -----------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['accion']) && $_POST['accion'] === "editar") {

    $productoID  = intval($_POST['producto_id']);
    $nombre      = $_POST['nombre'] ?? '';
    $descripcion = $_POST['descripcion'] ?? '';
    $precio      = $_POST['precio'] ?? 0;
    $stock       = $_POST['stock'] ?? 0;
    $imagenActual = $_POST['imagen_actual'] ?? null;

    $imagenRuta = $imagenActual;

    // ¿Subió nueva imagen?
    if (!empty($_FILES['imagen']['name'])) {
        $carpeta = "img/";
        if (!is_dir($carpeta)) {
            mkdir($carpeta, 0777, true);
        }

        $extension   = pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION);
        $nuevoNombre = "prod_" . uniqid() . "." . $extension;
        $rutaDestino = $carpeta . $nuevoNombre;

        if (move_uploaded_file($_FILES['imagen']['tmp_name'], $rutaDestino)) {
            // Borrar imagen anterior si existe
            if (!empty($imagenActual) && file_exists($imagenActual)) {
                @unlink($imagenActual);
            }
            $imagenRuta = $rutaDestino;
        } else {
            $mensaje = "⚠ No se pudo subir la nueva imagen. Se conservará la anterior.";
        }
    }

    try {
        $sql = "UPDATE Producto
                SET NombreProducto = ?, Descripcion = ?, Precio = ?, Stock = ?, Imagen = ?
                WHERE ProductoID = ? AND ProveedorID = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$nombre, $descripcion, $precio, $stock, $imagenRuta, $productoID, $proveedorID]);

        $mensaje = "✏ Producto actualizado correctamente.";
    } catch (PDOException $e) {
        $mensaje = "❌ Error al actualizar producto: " . $e->getMessage();
    }
}

// -----------------------------------------------------
// ELIMINAR PRODUCTO  (OPCIÓN A: no borrar si tiene pedidos)
// -----------------------------------------------------
if (isset($_GET['eliminar'])) {
    $productoID = intval($_GET['eliminar']);

    try {
        // 1) Verificar si el producto tiene pedidos en DetallePedido
        $stmt = $conn->prepare("SELECT COUNT(*) FROM DetallePedido WHERE ProductoID = ?");
        $stmt->execute([$productoID]);
        $tienePedidos = $stmt->fetchColumn();

        if ($tienePedidos > 0) {
            $mensaje = "❌ No se puede eliminar el producto porque tiene pedidos asociados.";
        } else {
            // 2) Borrar registros relacionados en Carrito (por la FK ProductoID)
            $stmt = $conn->prepare("DELETE FROM Carrito WHERE ProductoID = ?");
            $stmt->execute([$productoID]);

            // 3) Obtener imagen para borrarla del disco
            $stmt = $conn->prepare("SELECT Imagen FROM Producto WHERE ProductoID = ? AND ProveedorID = ?");
            $stmt->execute([$productoID, $proveedorID]);
            $imagen = $stmt->fetchColumn();

            // 4) Borrar producto
            $stmt = $conn->prepare("DELETE FROM Producto WHERE ProductoID = ? AND ProveedorID = ?");
            $stmt->execute([$productoID, $proveedorID]);

            // 5) Borrar imagen física
            if (!empty($imagen) && file_exists($imagen)) {
                @unlink($imagen);
            }

            $mensaje = "🗑 Producto eliminado correctamente.";
        }

    } catch (PDOException $e) {
        $mensaje = "❌ Error al eliminar producto: " . $e->getMessage();
    }
}

// -----------------------------------------------------
// OBTENER PRODUCTOS DEL PROVEEDOR
// -----------------------------------------------------
try {
    $stmt = $conn->prepare("SELECT * FROM Producto WHERE ProveedorID = ? ORDER BY ProductoID DESC");
    $stmt->execute([$proveedorID]);
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("❌ Error al obtener productos: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Panel de Proveedor - DI-TEC</title>

<style>
    body {
        font-family: 'Segoe UI', sans-serif;
        background-color: #f4f6f9;
        margin: 0;
    }

    /* Sidebar */
    .sidebar {
        width: 240px;
        height: 100vh;
        background-color: #003366;
        position: fixed;
        color: white;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }

    .sidebar h2 {
        background-color: #00264d;
        padding: 20px;
        text-align: center;
        margin: 0;
    }

    .sidebar a {
        color: white;
        padding: 15px 20px;
        display: block;
        text-decoration: none;
        font-weight: bold;
        transition: 0.3s;
    }

    .sidebar a:hover {
        background-color: #ff6600;
    }

    .logout {
        background-color: #ff6600;
        text-align: center;
        padding: 15px;
    }
    .logout a {
        color: white;
        text-decoration: none;
        font-weight: bold;
    }

    /* Contenido */
    .content {
        margin-left: 260px;
        padding: 30px;
    }

    h1 {
        color: #003366;
    }

    .mensaje {
        background: #e0f7fa;
        padding: 10px;
        border-radius: 8px;
        margin-bottom: 15px;
    }

    .section-box {
        background: white;
        border-radius: 10px;
        padding: 20px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        margin-bottom: 25px;
    }

    /* Tabla */
    table {
        width: 100%;
        background: white;
        border-collapse: collapse;
        box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        border-radius: 10px;
        overflow: hidden;
    }

    th, td {
        padding: 12px;
        border-bottom: 1px solid #ddd;
        text-align: center;
    }

    th {
        background-color: #003366;
        color: white;
    }

    tr:hover {
        background-color: #eef3f7;
    }

    img.product-img {
        width: 70px;
        height: 70px;
        object-fit: cover;
        border-radius: 6px;
        background:#eee;
    }

    /* Formularios */
    input, textarea {
        width: 100%;
        padding: 8px;
        margin-top: 5px;
        margin-bottom: 10px;
        border-radius: 6px;
        border: 1px solid #ccc;
        font-family: inherit;
    }

    .btn {
        background-color: #003366;
        color: white;
        padding: 8px 14px;
        border-radius: 6px;
        border: none;
        cursor: pointer;
        margin-top: 5px;
        font-size: 14px;
    }

    .btn:hover {
        background-color: #004c99;
    }

    .btn-eliminar {
        background-color: #c0392b;
    }

    .btn-eliminar:hover {
        background-color: #a93226;
    }
</style>

<script>
function confirmarEliminar(id) {
    if (confirm("¿Seguro que quieres eliminar este producto?\nSi tiene pedidos asociados NO se eliminará.")) {
        window.location.href = "panel_proveedor.php?eliminar=" + id;
    }
}

function editarProducto(id, nombre, desc, precio, stock, imagen) {
    document.getElementById('formEditar').style.display = 'block';
    document.getElementById('producto_id').value = id;
    document.getElementById('nombre_edit').value = nombre;
    document.getElementById('descripcion_edit').value = desc;
    document.getElementById('precio_edit').value = precio;
    document.getElementById('stock_edit').value = stock;
    document.getElementById('imagen_actual').value = imagen || '';

    window.scrollTo(0, document.body.scrollHeight);
}
</script>

</head>
<body>

<div class="sidebar">
    <div>
        <h2>Proveedor DI-TEC</h2>
        <a href="panel_proveedor.php">📦 Mis Productos</a>
        <a href="pedidos_proveedor.php">🚀 Mis Pedidos</a>
        <a href="estadisticas_proveedor.php">📈 Mis Estadísticas</a>
    </div>
    <div class="logout">
        <a href="logout.php">🚪 Cerrar Sesión</a>
    </div>
</div>

<div class="content">
    <h1>Panel del Proveedor</h1>

    <?php if (!empty($mensaje)): ?>
        <p class="mensaje"><?= htmlspecialchars($mensaje) ?></p>
    <?php endif; ?>

    <!-- AGREGAR PRODUCTO -->
    <div class="section-box">
        <h2>Agregar Producto</h2>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="accion" value="agregar">

            <label>Nombre del producto:</label>
            <input type="text" name="nombre" required>

            <label>Descripción:</label>
            <textarea name="descripcion" required></textarea>

            <label>Precio:</label>
            <input type="number" name="precio" step="0.01" min="0" required>

            <label>Stock:</label>
            <input type="number" name="stock" min="0" required>

            <label>Imagen:</label>
            <input type="file" name="imagen" accept="image/*">

            <button type="submit" class="btn">Agregar Producto</button>
        </form>
    </div>

    <!-- LISTA DE PRODUCTOS -->
    <div class="section-box">
        <h2>Mis Productos</h2>

        <?php if (empty($productos)): ?>
            <p>No tienes productos registrados.</p>
        <?php else: ?>
            <table>
                <tr>
                    <th>ID</th>
                    <th>Imagen</th>
                    <th>Nombre</th>
                    <th>Precio</th>
                    <th>Stock</th>
                    <th>Acciones</th>
                </tr>

                <?php foreach ($productos as $p): ?>
                    <tr>
                        <td><?= $p['ProductoID'] ?></td>
                        <td>
                            <?php if (!empty($p['Imagen']) && file_exists($p['Imagen'])): ?>
                                <img src="<?= htmlspecialchars($p['Imagen']) ?>" class="product-img">
                            <?php else: ?>
                                <span>Sin imagen</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($p['NombreProducto']) ?></td>
                        <td>$<?= number_format($p['Precio'], 2) ?></td>
                        <td><?= $p['Stock'] ?></td>
                        <td>
                            <button class="btn"
                                onclick="editarProducto(
                                    <?= $p['ProductoID'] ?>,
                                    '<?= htmlspecialchars($p['NombreProducto'], ENT_QUOTES) ?>',
                                    '<?= htmlspecialchars($p['Descripcion'], ENT_QUOTES) ?>',
                                    '<?= $p['Precio'] ?>',
                                    '<?= $p['Stock'] ?>',
                                    '<?= htmlspecialchars($p['Imagen'] ?? '', ENT_QUOTES) ?>'
                                )">Editar</button>

                            <button class="btn btn-eliminar" onclick="confirmarEliminar(<?= $p['ProductoID'] ?>)">
                                Eliminar
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>

    <!-- FORMULARIO EDICIÓN -->
    <div class="section-box" id="formEditar" style="display:none;">
        <h2>Editar Producto</h2>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="accion" value="editar">
            <input type="hidden" id="producto_id" name="producto_id">
            <input type="hidden" id="imagen_actual" name="imagen_actual">

            <label>Nombre del producto:</label>
            <input type="text" id="nombre_edit" name="nombre" required>

            <label>Descripción:</label>
            <textarea id="descripcion_edit" name="descripcion" required></textarea>

            <label>Precio:</label>
            <input type="number" id="precio_edit" name="precio" step="0.01" min="0" required>

            <label>Stock:</label>
            <input type="number" id="stock_edit" name="stock" min="0" required>

            <label>Reemplazar imagen (opcional):</label>
            <input type="file" name="imagen" accept="image/*">

            <button type="submit" class="btn">Guardar Cambios</button>
        </form>
    </div>

</div>

</body>
</html>

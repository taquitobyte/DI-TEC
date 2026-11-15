<?php
session_start();
include 'conexion.php';
include 'seguridad.php';
include 'admin_helpers.php';

// 🔐 Verificar sesión y rol (solo admin)
if (!isset($_SESSION['NombreUsuario']) || !isset($_SESSION['RolID']) || $_SESSION['RolID'] != 1) {
    header("Location: login.html");
    exit();
}

$mensaje = "";
function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

// 🔐 Todas las acciones son POST y exigen el token CSRF
if ($_SERVER["REQUEST_METHOD"] === "POST" && !csrf_valido($_POST['csrf'] ?? null)) {
    flash_set("❌ La solicitud no es válida. Recarga la página e inténtalo de nuevo.", 'error');
    header("Location: gestion_usuarios.php");
    exit();
}

/* ======================================================
   🧾 AGREGAR USUARIO
====================================================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['accion']) && $_POST['accion'] === "agregar") {

    try {
        // 1️⃣ Insertar usuario
        $sql = "INSERT INTO Usuario (NombreUsuario, EmailUsuario, TelefonoUsuario, DireccionUsuario, ContrasenaUsuario, RolID, FechaRegistro)
                VALUES (?, ?, ?, ?, ?, ?, GETDATE())";

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $_POST['nombre'],
            $_POST['email'],
            $_POST['telefono'],
            $_POST['direccion'],
            hashear_contrasena($_POST['contrasena']),
            $_POST['rol']
        ]);

        // 2️⃣ Si es proveedor, crear registro en tabla Proveedor
        if ($_POST['rol'] == 3) {

            $stmt2 = $conn->prepare("
                INSERT INTO Proveedor (NombreProveedor, UsuarioID)
                VALUES (?, (SELECT UsuarioID FROM Usuario WHERE EmailUsuario = ?))
            ");

            $stmt2->execute([
                $_POST['nombre'],
                $_POST['email']
            ]);
        }

        $mensaje = "✅ Usuario agregado correctamente.";

    } catch (PDOException $e) {
        $mensaje = ($e->getCode() == 23000 || $e->getCode() == 23505)
            ? "⚠️ Ese correo ya está registrado."
            : "❌ Error al agregar usuario: " . $e->getMessage();
    }
}

/* ======================================================
   ✏️ EDITAR USUARIO
====================================================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['accion']) && $_POST['accion'] === "editar") {

    try {
        // 1️⃣ Actualizar datos del usuario
        // Si el campo de contraseña se deja vacío, se conserva la contraseña actual
        $datos = [
            $_POST['nombre'],
            $_POST['email'],
            $_POST['telefono'],
            $_POST['direccion'],
        ];
        $setContrasena = "";
        if (isset($_POST['contrasena']) && $_POST['contrasena'] !== "") {
            $setContrasena = ", ContrasenaUsuario=?";
            $datos[] = hashear_contrasena($_POST['contrasena']);
        }
        $datos[] = $_POST['rol'];
        $datos[] = $_POST['email_original'];

        $sql = "UPDATE Usuario 
                SET NombreUsuario=?, EmailUsuario=?, TelefonoUsuario=?, DireccionUsuario=?{$setContrasena}, RolID=?
                WHERE EmailUsuario=?";

        $stmt = $conn->prepare($sql);
        $stmt->execute($datos);

        // 2️⃣ Si ahora es proveedor → verificar si ya existe o crear
        if ($_POST['rol'] == 3) {

            // Obtener su UsuarioID
            $stmtUID = $conn->prepare("SELECT UsuarioID FROM Usuario WHERE EmailUsuario = ?");
            $stmtUID->execute([$_POST['email']]);
            $usuarioID = $stmtUID->fetchColumn();

            // Verificar si ya existe proveedor ligado
            $stmtCheck = $conn->prepare("SELECT COUNT(*) FROM Proveedor WHERE UsuarioID = ?");
            $stmtCheck->execute([$usuarioID]);
            $existe = $stmtCheck->fetchColumn();

            if ($existe == 0) {
                // Crear proveedor
                $stmtIns = $conn->prepare("
                    INSERT INTO Proveedor (NombreProveedor, UsuarioID)
                    VALUES (?, ?)
                ");
                $stmtIns->execute([$_POST['nombre'], $usuarioID]);
            } else {
                // Solo actualizar nombre
                $stmtUp = $conn->prepare("
                    UPDATE Proveedor
                    SET NombreProveedor = ?
                    WHERE UsuarioID = ?
                ");
                $stmtUp->execute([$_POST['nombre'], $usuarioID]);
            }
        }

        $mensaje = "✏️ Usuario actualizado correctamente.";

    } catch (PDOException $e) {
        $mensaje = ($e->getCode() == 23000 || $e->getCode() == 23505)
            ? "⚠️ Ese correo ya está registrado."
            : "❌ Error al actualizar usuario: " . $e->getMessage();
    }
}

/* ======================================================
   🗑 ELIMINAR USUARIO (corregido)
====================================================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['accion']) && $_POST['accion'] === "eliminar") {

    $email = $_POST['email'] ?? '';

    try {

        // Obtener info del usuario
        $stmt = $conn->prepare("SELECT UsuarioID, RolID FROM Usuario WHERE EmailUsuario = ?");
        $stmt->execute([$email]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($usuario && (int) $usuario['UsuarioID'] === (int) $_SESSION['UsuarioID']) {
            $mensaje = "⚠️ No puedes eliminar tu propia cuenta mientras estás en sesión.";

        } elseif ($usuario) {

            $usuarioID = $usuario['UsuarioID'];
            $rolID     = $usuario['RolID'];

            $conn->beginTransaction();

            // 1️⃣ DetallePedido → pedidos del usuario
            $stmt = $conn->prepare("
                DELETE DP
                FROM DetallePedido DP
                INNER JOIN Pedido P ON DP.PedidoID = P.PedidoID
                WHERE P.UsuarioID = ?
            ");
            $stmt->execute([$usuarioID]);

            // 2️⃣ Carrito del usuario
            $stmt = $conn->prepare("DELETE FROM Carrito WHERE UsuarioID = ?");
            $stmt->execute([$usuarioID]);

            // 3️⃣ Pedidos del usuario
            $stmt = $conn->prepare("DELETE FROM Pedido WHERE UsuarioID = ?");
            $stmt->execute([$usuarioID]);

            // 4️⃣ Si es proveedor → eliminar productos y proveedor
            if ($rolID == 3) {

                $stmt2 = $conn->prepare("SELECT ProveedorID FROM Proveedor WHERE UsuarioID = ?");
                $stmt2->execute([$usuarioID]);
                $proveedor = $stmt2->fetch(PDO::FETCH_ASSOC);

                if ($proveedor) {
                    $proveedorID = $proveedor['ProveedorID'];

                    // Quita sus productos de pedidos y carritos (recalcula totales) y los elimina
                    $stmtP = $conn->prepare("SELECT ProductoID FROM Producto WHERE ProveedorID = ?");
                    $stmtP->execute([$proveedorID]);
                    $resProd = admin_eliminar_productos($conn, $stmtP->fetchAll(PDO::FETCH_COLUMN));

                    $stmt = $conn->prepare("DELETE FROM Proveedor WHERE ProveedorID = ?");
                    $stmt->execute([$proveedorID]);
                }
            }

            // 5️⃣ Eliminar usuario
            $stmt = $conn->prepare("DELETE FROM Usuario WHERE EmailUsuario = ?");
            $stmt->execute([$email]);

            $conn->commit();
            if (isset($resProd)) { admin_borrar_imagenes($conn, $resProd['imagenes']); }
            $mensaje = "🗑️ Usuario eliminado correctamente.";

        } else {
            $mensaje = "⚠️ Usuario no encontrado.";
        }

    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $mensaje = "❌ Error al eliminar usuario: " . $e->getMessage();
    }
}

// Después de una acción se redirige (así no se repite al recargar) y el mensaje se muestra una vez
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $esError = (strpos($mensaje, '❌') === 0 || strpos($mensaje, '⚠️') === 0);
    flash_set($mensaje, $esError ? 'error' : 'ok');
    header("Location: gestion_usuarios.php");
    exit();
}
$flash = flash_get();
$token = csrf_token();

/* ======================================================
   📋 LISTA DE USUARIOS (con búsqueda y filtro por rol)
====================================================== */
$busqueda = trim($_GET['q'] ?? '');
$filtroRol = $_GET['rol'] ?? '';
try {
    $sqlU = "SELECT * FROM Usuario WHERE 1 = 1";
    $paramsU = [];
    if ($busqueda !== '') {
        $sqlU .= " AND (NombreUsuario LIKE ? OR EmailUsuario LIKE ?)";
        $paramsU[] = "%$busqueda%"; $paramsU[] = "%$busqueda%";
    }
    if (in_array($filtroRol, ['1', '2', '3'], true)) {
        $sqlU .= " AND RolID = ?";
        $paramsU[] = (int) $filtroRol;
    }
    $sqlU .= " ORDER BY NombreUsuario";
    $stmtU = $conn->prepare($sqlU);
    $stmtU->execute($paramsU);
    $usuarios = $stmtU->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $usuarios = [];
    $mensaje = "❌ Error al obtener usuarios: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Usuarios - DI-TEC</title>

<style>
    body { margin: 0; font-family: 'Segoe UI', sans-serif; background-color: #f4f6f9; color: #333; }
    .sidebar { background-color: #003366; color: white; width: 240px; height: 100vh; position: fixed; display: flex; flex-direction: column; justify-content: space-between; }
    .sidebar h2 { text-align: center; padding: 20px; background-color: #00264d; margin: 0; }
    .sidebar a { color: white; text-decoration: none; padding: 15px 20px; display: block; transition: background 0.3s; }
    .sidebar a:hover { background-color: #ff6600; }
    .logout { text-align: center; background-color: #ff6600; padding: 15px; }
    .logout a { color: white; text-decoration: none; font-weight: bold; }

    .content { margin-left: 240px; padding: 30px; }
    .container { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); margin-bottom: 30px; }
    table { width: 100%; border-collapse: collapse; margin-top: 15px; }
    th, td { border: 1px solid #ddd; padding: 10px; text-align: center; }
    th { background-color: #003366; color: white; }

    .btn { background-color: #ff6600; color: white; padding: 7px 10px; border-radius: 6px; border: none; cursor: pointer; }
    .btn:hover { background-color: #cc5200; }
    .btn-eliminar { background-color: #c0392b; }
    .btn-eliminar:hover { background-color: #a93226; }

    .mensaje { background: #e8f8f5; padding: 10px; border-radius: 8px; margin-bottom: 15px; }

    .form-editar { margin-top: 20px; padding: 15px; background: #f9f9f9; border-radius: 10px; display: none; }
    input, select { padding: 8px; margin: 5px; border-radius: 6px; border: 1px solid #ccc; width: calc(100% - 20px); }
</style>

<script>
function confirmarEliminacion(email) {
    return confirm("¿Seguro que quieres eliminar al usuario con correo " + email + "?\nSi es proveedor, también se eliminan sus productos.");
}

function editarUsuario(nombre, email, tel, dir, pass, rol) {
    document.getElementById("formEditar").style.display = "block";
    document.getElementById("nombre_edit").value = nombre;
    document.getElementById("email_edit").value = email;
    document.getElementById("telefono_edit").value = tel;
    document.getElementById("direccion_edit").value = dir;
    document.getElementById("contrasena_edit").value = pass;
    document.getElementById("rol_edit").value = rol;
    document.getElementById("email_original").value = email;

    window.scrollTo(0, document.body.scrollHeight);
}
</script>

</head>
<body>

<!-- ==== MENÚ LATERAL ==== -->
<div class="sidebar">
    <div>
        <h2>Admin DI-TEC</h2>
        <a href="gestion_usuarios.php">🧑‍💼 Gestión de Usuarios</a>
        <a href="gestion_proveedores.php">🏬 Gestión de Proveedores</a>
        <a href="gestion_pedidos.php">🗂️ Gestión de Pedidos</a>
        <a href="estadisticas.php">📈 Estadísticas</a>
    </div>
    <div class="logout">
        <a href="logout.php">Cerrar sesión</a>
    </div>
</div>

<!-- ==== CONTENIDO ==== -->
<div class="content">

    <div class="container">

        <h1>Gestión de Usuarios</h1>

        <?php if (!empty($flash)): ?>
            <p class="mensaje" style="<?= $flash['tipo'] === 'error' ? 'background:#ffe3e0;' : '' ?>"><?= h($flash['texto']) ?></p>
        <?php elseif ($mensaje): ?>
            <p class="mensaje"><?= h($mensaje) ?></p>
        <?php endif; ?>

        <h2>Agregar Usuario</h2>
        <form method="POST">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="accion" value="agregar">
            <input type="text" name="nombre" placeholder="Nombre" required>
            <input type="email" name="email" placeholder="Correo" required>
            <input type="text" name="telefono" placeholder="Teléfono">
            <input type="text" name="direccion" placeholder="Dirección">
            <input type="password" name="contrasena" placeholder="Contraseña" required>

            <select name="rol">
                <option value="1">Administrador</option>
                <option value="2">Usuario</option>
                <option value="3">Proveedor</option>
            </select>

            <button class="btn">Agregar</button>
        </form>

        <h2>Lista de Usuarios</h2>
        <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
            <input type="text" name="q" placeholder="Buscar por nombre o correo" value="<?= h($busqueda) ?>" style="width:280px;">
            <select name="rol" style="width:180px;">
                <option value="">Todos los roles</option>
                <option value="1" <?= $filtroRol === '1' ? 'selected' : '' ?>>Administrador</option>
                <option value="2" <?= $filtroRol === '2' ? 'selected' : '' ?>>Usuario</option>
                <option value="3" <?= $filtroRol === '3' ? 'selected' : '' ?>>Proveedor</option>
            </select>
            <button class="btn" type="submit">Filtrar</button>
            <?php if ($busqueda !== '' || $filtroRol !== ''): ?><a class="btn" href="gestion_usuarios.php" style="text-decoration:none;">Limpiar</a><?php endif; ?>
        </form>
        <table>
            <tr>
                <th>Nombre</th>
                <th>Correo</th>
                <th>Teléfono</th>
                <th>Rol</th>
                <th>Acciones</th>
            </tr>

            <?php foreach ($usuarios as $u): ?>
            <tr>
                <td><?= htmlspecialchars($u['NombreUsuario']) ?></td>
                <td><?= htmlspecialchars($u['EmailUsuario']) ?></td>
                <td><?= h($u['TelefonoUsuario']) ?></td>

                <td>
                    <?= ($u['RolID']==1 ? 'Administrador' : ($u['RolID']==2 ? 'Usuario' : 'Proveedor')) ?>
                </td>

                <td>
                    <button type="button" class="btn"
                        onclick="editarUsuario(
                            <?= h(json_encode($u['NombreUsuario'] ?? '', JSON_HEX_APOS | JSON_HEX_QUOT)) ?>,
                            <?= h(json_encode($u['EmailUsuario'] ?? '', JSON_HEX_APOS | JSON_HEX_QUOT)) ?>,
                            <?= h(json_encode($u['TelefonoUsuario'] ?? '', JSON_HEX_APOS | JSON_HEX_QUOT)) ?>,
                            <?= h(json_encode($u['DireccionUsuario'] ?? '', JSON_HEX_APOS | JSON_HEX_QUOT)) ?>,
                            '',
                            '<?= (int) $u['RolID'] ?>'
                        )">Editar</button>

                    <?php if ((int) $u['UsuarioID'] !== (int) $_SESSION['UsuarioID']): ?>
                    <form method="POST" style="display:inline;margin:0;"
                          onsubmit="return confirmarEliminacion(<?= h(json_encode($u['EmailUsuario'], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>)">
                        <input type="hidden" name="csrf" value="<?= h($token) ?>">
                        <input type="hidden" name="accion" value="eliminar">
                        <input type="hidden" name="email" value="<?= h($u['EmailUsuario']) ?>">
                        <button class="btn btn-eliminar" type="submit">Eliminar</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>

        </table>

        <!-- FORMULARIO DE EDICIÓN -->
        <div class="form-editar" id="formEditar">
            <h2>Editar Usuario</h2>

            <form method="POST">
                <input type="hidden" name="csrf" value="<?= h($token) ?>">
                <input type="hidden" name="accion" value="editar">
                <input type="hidden" id="email_original" name="email_original">

                <input type="text" id="nombre_edit" name="nombre" required>
                <input type="email" id="email_edit" name="email" required>
                <input type="text" id="telefono_edit" name="telefono">
                <input type="text" id="direccion_edit" name="direccion">
                <input type="password" id="contrasena_edit" name="contrasena" placeholder="Dejar vacío para no cambiarla">
                
                <select id="rol_edit" name="rol">
                    <option value="1">Administrador</option>
                    <option value="2">Usuario</option>
                    <option value="3">Proveedor</option>
                </select>

                <button class="btn">Guardar Cambios</button>
            </form>
        </div>

    </div>

</div>

</body>
</html>

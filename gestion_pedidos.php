<?php
include 'conexion.php';
include 'seguridad.php';
include 'estado_pedido.php';
session_start();

// SOLO ADMIN (RolID = 1)
if (!isset($_SESSION['UsuarioID']) || $_SESSION['RolID'] != 1) {
    header("Location: login.html");
    exit();
}

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

// -----------------------------------------------
// ACCIONES (POST + token CSRF, luego se redirige)
// -----------------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['pedido_id'])) {
    $pedidoID = intval($_POST['pedido_id']);
    $accion = $_POST['accion'] ?? 'estado';
    $tipo = 'ok';

    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $mensaje = "❌ La solicitud no es válida. Recarga la página e inténtalo de nuevo.";
        $tipo = 'error';
    } else {
        try {
            if ($accion === 'cancelar') {
                [$ok, $mensaje] = cancelar_pedido($conn, $pedidoID);
            } elseif ($accion === 'rechazar') {
                [$ok, $mensaje] = rechazar_solicitud_cancelacion($conn, $pedidoID);
            } else {
                $estadoNuevo = $_POST['estado'] ?? '';
                $estadoActual = pedido_estado_actual($conn, $pedidoID);
                if ($estadoActual === "Entregado") {
                    $ok = false; $mensaje = "No se puede modificar un pedido ya entregado.";
                } elseif ($estadoActual === ESTADO_CANCELADO || $estadoActual === ESTADO_SOLICITUD) {
                    $ok = false; $mensaje = "Este pedido está cancelado o tiene una cancelación pendiente: cancélalo o rechaza la solicitud.";
                } elseif (!in_array($estadoNuevo, ['Pendiente', 'En Camino', 'Entregado'], true)) {
                    $ok = false; $mensaje = "Estado no válido.";
                } else {
                    // El admin aplica el estado a los productos que aún no se entregan
                    // y después se recalcula el estado global del pedido.
                    $paraDetalle = ['Pendiente' => 'Pendiente', 'En Camino' => 'Enviado', 'Entregado' => 'Entregado'][$estadoNuevo];
                    $conn->beginTransaction();
                    $stmt = $conn->prepare("UPDATE DetallePedido SET Estado = ? WHERE PedidoID = ? AND Estado <> 'Entregado'");
                    $stmt->execute([$paraDetalle, $pedidoID]);
                    $resultado = sincronizar_estado_pedido($conn, $pedidoID);
                    $conn->commit();
                    $ok = true; $mensaje = "Estado actualizado correctamente. Estado del pedido: " . $resultado . ".";
                }
            }
            $mensaje = ($ok ? "✅ " : "⚠️ ") . $mensaje;
            $tipo = $ok ? 'ok' : 'error';
        } catch (PDOException $e) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            $mensaje = "❌ Error al actualizar estado: " . $e->getMessage();
            $tipo = 'error';
        }
    }
    flash_set($mensaje, $tipo);
    header("Location: gestion_pedidos.php" . (!empty($_POST['volver']) ? '?' . ltrim($_POST['volver'], '?') : ''));
    exit();
}

$flash = flash_get();
$token = csrf_token();
$busqueda = trim($_GET['q'] ?? '');
$filtroEstado = $_GET['estado'] ?? '';
$estadosValidos = ['Pendiente', 'En Camino', 'Entregado', ESTADO_SOLICITUD, ESTADO_CANCELADO];

// -----------------------------------------------
// CONSULTAR PEDIDOS (con filtros)
// -----------------------------------------------
try {
    $sql = "
        SELECT p.PedidoID, p.FechaPedido, p.Total, p.Estado,
               u.NombreUsuario, u.EmailUsuario,
               pr.NombreProducto, dp.Cantidad, dp.Estado AS EstadoDetalle,
               pv.NombreProveedor
        FROM Pedido p
        INNER JOIN Usuario u ON p.UsuarioID = u.UsuarioID
        INNER JOIN DetallePedido dp ON p.PedidoID = dp.PedidoID
        INNER JOIN Producto pr ON dp.ProductoID = pr.ProductoID
        LEFT JOIN Proveedor pv ON pv.ProveedorID = pr.ProveedorID
        WHERE 1 = 1";
    $params = [];
    if ($busqueda !== '') {
        $sql .= " AND (u.NombreUsuario LIKE ? OR u.EmailUsuario LIKE ? OR CAST(p.PedidoID AS VARCHAR(20)) = ?)";
        $params[] = "%$busqueda%"; $params[] = "%$busqueda%"; $params[] = ltrim($busqueda, '#');
    }
    if (in_array($filtroEstado, $estadosValidos, true)) {
        $sql .= " AND p.Estado = ?";
        $params[] = $filtroEstado;
    }
    $sql .= " ORDER BY p.PedidoID DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("❌ Error al obtener pedidos: " . h($e->getMessage()));
}

// Agrupar pedidos
$lista = [];
foreach ($pedidos as $p) {
    $id = $p['PedidoID'];

    if (!isset($lista[$id])) {
        $lista[$id] = [
            'FechaPedido' => $p['FechaPedido'],
            'Total' => $p['Total'],
            'Estado' => $p['Estado'],
            'Usuario' => $p['NombreUsuario'],
            'Email' => $p['EmailUsuario'],
            'Productos' => []
        ];
    }

    $lista[$id]['Productos'][] = [
        'Nombre' => $p['NombreProducto'],
        'Cantidad' => $p['Cantidad'],
        'EstadoDetalle' => $p['EstadoDetalle'],
        'Proveedor' => $p['NombreProveedor']
    ];
}
$volver = http_build_query(array_filter(['q' => $busqueda, 'estado' => $filtroEstado]));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Gestión de Pedidos - Admin</title>

<style>
    body {
            margin: 0;
            font-family: 'Segoe UI', sans-serif;
            background-color: #f4f6f9;
            color: #333;
    }

    /* SIDEBAR ORIGINAL */
    .sidebar {
        background-color: #003366;
        color: white;
        width: 240px;
        height: 100vh;
        position: fixed;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }
    .sidebar h2 {
        text-align: center;
        padding: 20px;
        background-color: #00264d;
        margin: 0;
    }
    .sidebar a {
        color: white;
        text-decoration: none;
        padding: 15px 20px;
        display: block;
        transition: background 0.3s;
    }
    .sidebar a:hover {
        background-color: #ff6600;
    }
    .logout {
        text-align: center;
        background-color: #ff6600;
        padding: 15px;
    }
    .logout a {
        color: white;
        text-decoration: none;
        font-weight: bold;
    }

    /* CONTENIDO */
    .content {
        margin-left: 240px;
        padding: 30px;
    }

    .pedido-box {
        background: white;
        padding: 20px;
        border-radius: 10px;
        margin-bottom: 25px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    }

    .estado {
        font-weight: bold;
        padding: 5px 10px;
        border-radius: 6px;
        color: white;
    }
    .Pendiente { background:#d35400; }
    .En-Camino { background:#2980b9; }
    .Entregado { background:#27ae60; }
    .Cancelado { background:#c0392b; }
    .Cancelación-solicitada { background:#8e44ad; }
    .mensaje { padding:10px 14px; border-radius:8px; border-left:5px solid #00a300; background:#e0ffe0; font-weight:bold; }
    .mensaje.error { border-left-color:#c0392b; background:#ffe3e0; }
    .filtros { display:flex; gap:8px; flex-wrap:wrap; margin:15px 0 20px; }
    .filtros input, .filtros select { padding:9px 12px; border:1px solid #ccc; border-radius:6px; }
    .filtros button, .filtros a { padding:9px 14px; border:0; border-radius:6px; background:#003366; color:white; text-decoration:none; cursor:pointer; font-size:14px; }
    .btn-cancelar { background:#c0392b; color:white; border:0; padding:8px 14px; border-radius:6px; cursor:pointer; }
    .btn-rechazar { background:#555; color:white; border:0; padding:8px 14px; border-radius:6px; cursor:pointer; }
    .aviso-sol { background:#f3e5f5; border-left:5px solid #8e44ad; padding:10px 14px; border-radius:8px; margin:10px 0; }
    form.inline { display:inline; margin:0; }
</style>

</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar">
    <div>
        <h2>Admin DI-TEC</h2>
        <a href="gestion_usuarios.php">🧑‍💼 Gestión de Usuarios</a>
        <a href="gestion_proveedores.php">🏬 Gestión de Proveedores</a>
        <a href="gestion_pedidos.php">🗂️ Gestión de Pedidos</a>
        <a href="estadisticas.php">📈 Estadísticas</a>
    </div>
    <div class="logout">
        <a href="logout.php">Cerrar Sesión</a>
    </div>
</div>

<!-- CONTENIDO -->
<div class="content">

    <h1>🗂️ Gestión de Pedidos</h1>

    <?php if ($flash): ?>
        <p class="mensaje <?= $flash['tipo'] === 'error' ? 'error' : '' ?>"><?= h($flash['texto']) ?></p>
    <?php endif; ?>

    <form class="filtros" method="get">
        <input type="text" name="q" placeholder="Cliente, correo o # de pedido" value="<?= h($busqueda) ?>">
        <select name="estado">
            <option value="">Todos los estados</option>
            <?php foreach ($estadosValidos as $e): ?>
                <option value="<?= h($e) ?>" <?= $filtroEstado === $e ? 'selected' : '' ?>><?= h($e) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Filtrar</button>
        <?php if ($busqueda !== '' || $filtroEstado !== ''): ?><a href="gestion_pedidos.php">Limpiar</a><?php endif; ?>
    </form>

    <?php if (empty($lista)): ?>
        <p>No hay pedidos<?= ($busqueda !== '' || $filtroEstado !== '') ? ' que coincidan con el filtro' : '' ?>.</p>
    <?php endif; ?>

    <?php foreach ($lista as $id => $p): ?>
        <div class="pedido-box">
            <h2>Pedido #<?= (int) $id ?></h2>

            <p><b>Cliente:</b> <?= h($p['Usuario']) ?> (<?= h($p['Email']) ?>)</p>
            <p><b>Fecha:</b> <?= h($p['FechaPedido']) ?></p>
            <p><b>Total:</b> $<?= number_format($p['Total'], 2) ?></p>

            <p><b>Estado actual:</b>
                <span class="estado <?= h(str_replace(' ', '-', $p['Estado'])) ?>"><?= h($p['Estado']) ?></span>
            </p>

            <h3>Productos:</h3>
            <ul>
                <?php foreach ($p['Productos'] as $prod): ?>
                    <li><?= h($prod['Nombre']) ?> (x<?= (int) $prod['Cantidad'] ?>)
                        — proveedor: <?= h($prod['Proveedor'] ?? '—') ?>
                        — <b><?= h($prod['EstadoDetalle'] ?? 'Pendiente') ?></b></li>
                <?php endforeach; ?>
            </ul>

            <?php if ($p['Estado'] === "Entregado"): ?>
                <p><b>✔ Pedido entregado. No se puede modificar.</b></p>

            <?php elseif ($p['Estado'] === ESTADO_CANCELADO): ?>
                <p><b>✖ Pedido cancelado. No se puede modificar.</b></p>

            <?php elseif ($p['Estado'] === ESTADO_SOLICITUD): ?>
                <div class="aviso-sol">🟣 El cliente solicitó cancelar este pedido.</div>
                <form class="inline" method="POST" onsubmit="return confirm('¿Cancelar el pedido #<?= (int) $id ?>? Se regresará el stock.')">
                    <input type="hidden" name="csrf" value="<?= h($token) ?>">
                    <input type="hidden" name="volver" value="<?= h($volver) ?>">
                    <input type="hidden" name="pedido_id" value="<?= (int) $id ?>">
                    <input type="hidden" name="accion" value="cancelar">
                    <button type="submit" class="btn-cancelar">Aceptar y cancelar pedido</button>
                </form>
                <form class="inline" method="POST">
                    <input type="hidden" name="csrf" value="<?= h($token) ?>">
                    <input type="hidden" name="volver" value="<?= h($volver) ?>">
                    <input type="hidden" name="pedido_id" value="<?= (int) $id ?>">
                    <input type="hidden" name="accion" value="rechazar">
                    <button type="submit" class="btn-rechazar">Rechazar solicitud</button>
                </form>

            <?php else: ?>
                <form class="inline" method="POST">
                    <input type="hidden" name="csrf" value="<?= h($token) ?>">
                    <input type="hidden" name="volver" value="<?= h($volver) ?>">
                    <input type="hidden" name="pedido_id" value="<?= (int) $id ?>">
                    <select name="estado">
                        <option value="Pendiente" <?= $p['Estado']=="Pendiente"?"selected":"" ?>>Pendiente</option>
                        <option value="En Camino" <?= $p['Estado']=="En Camino"?"selected":"" ?>>En Camino</option>
                        <option value="Entregado" <?= $p['Estado']=="Entregado"?"selected":"" ?>>Entregado</option>
                    </select>
                    <button type="submit">Actualizar</button>
                </form>
                <form class="inline" method="POST" onsubmit="return confirm('¿Cancelar el pedido #<?= (int) $id ?>? Se regresará el stock.')">
                    <input type="hidden" name="csrf" value="<?= h($token) ?>">
                    <input type="hidden" name="volver" value="<?= h($volver) ?>">
                    <input type="hidden" name="pedido_id" value="<?= (int) $id ?>">
                    <input type="hidden" name="accion" value="cancelar">
                    <button type="submit" class="btn-cancelar">Cancelar pedido</button>
                </form>
            <?php endif; ?>

        </div>
    <?php endforeach; ?>

</div>

</body>
</html>

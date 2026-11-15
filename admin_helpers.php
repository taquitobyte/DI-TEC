<?php
/*
 * Funciones compartidas por las pantallas del administrador
 * (gestión de proveedores y de sus productos).
 */

/**
 * Elimina productos y deja la base de datos consistente:
 *  - quita sus renglones de DetallePedido y de Carrito
 *  - recalcula el Total de cada pedido afectado
 *  - elimina los pedidos que se quedaron sin ningún renglón
 *
 * DEBE llamarse dentro de una transacción (beginTransaction / commit).
 * Devuelve un resumen con lo que se hizo.
 */
function admin_eliminar_productos(PDO $conn, array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $res = ['productos' => 0, 'pedidos_afectados' => 0, 'pedidos_eliminados' => 0, 'imagenes' => []];
    if (empty($ids)) {
        return $res;
    }
    $in = implode(',', array_fill(0, count($ids), '?'));

    // Pedidos que contienen estos productos
    $st = $conn->prepare("SELECT DISTINCT PedidoID FROM DetallePedido WHERE ProductoID IN ($in)");
    $st->execute($ids);
    $pedidos = $st->fetchAll(PDO::FETCH_COLUMN);

    // Imágenes que había que borrar del disco
    $st = $conn->prepare("SELECT Imagen FROM Producto WHERE ProductoID IN ($in)");
    $st->execute($ids);
    $res['imagenes'] = array_values(array_filter($st->fetchAll(PDO::FETCH_COLUMN)));

    $conn->prepare("DELETE FROM DetallePedido WHERE ProductoID IN ($in)")->execute($ids);
    $conn->prepare("DELETE FROM Carrito WHERE ProductoID IN ($in)")->execute($ids);
    $del = $conn->prepare("DELETE FROM Producto WHERE ProductoID IN ($in)");
    $del->execute($ids);
    $res['productos'] = $del->rowCount();

    // Recalcular (o eliminar) los pedidos afectados
    foreach ($pedidos as $pedidoID) {
        $st = $conn->prepare("SELECT COUNT(*) AS Renglones, COALESCE(SUM(Cantidad * PrecioUnitario), 0) AS Total
                              FROM DetallePedido WHERE PedidoID = ?");
        $st->execute([$pedidoID]);
        $info = $st->fetch(PDO::FETCH_ASSOC);

        if ((int) $info['Renglones'] === 0) {
            $conn->prepare("DELETE FROM Pedido WHERE PedidoID = ?")->execute([$pedidoID]);
            $res['pedidos_eliminados']++;
        } else {
            $conn->prepare("UPDATE Pedido SET Total = ? WHERE PedidoID = ?")->execute([$info['Total'], $pedidoID]);
            $res['pedidos_afectados']++;
        }
    }
    return $res;
}

/**
 * Borra del disco las imágenes de productos eliminados.
 * Solo toca archivos dentro de img/ y que ningún otro producto use.
 * Llamar DESPUÉS del commit.
 */
function admin_borrar_imagenes(PDO $conn, array $rutas): void
{
    $base = realpath(__DIR__ . '/img');
    if (!$base) {
        return;
    }
    foreach ($rutas as $ruta) {
        $real = realpath(__DIR__ . '/' . $ruta);
        if (!$real || strpos($real, $base . DIRECTORY_SEPARATOR) !== 0) {
            continue;
        }
        if (basename($real) === 'sin_imagen.png') {
            continue;
        }
        $st = $conn->prepare("SELECT COUNT(*) FROM Producto WHERE Imagen = ?");
        $st->execute([$ruta]);
        if ((int) $st->fetchColumn() === 0) {
            @unlink($real);
        }
    }
}

/** Texto resumen de lo que ocurrió con los pedidos al eliminar productos. */
function admin_resumen_pedidos(array $r): string
{
    $partes = [];
    if ($r['pedidos_afectados'] > 0) {
        $partes[] = $r['pedidos_afectados'] . " pedido(s) se actualizaron (sin esos productos y con el total recalculado)";
    }
    if ($r['pedidos_eliminados'] > 0) {
        $partes[] = $r['pedidos_eliminados'] . " pedido(s) se eliminaron por quedar sin productos";
    }
    return empty($partes) ? "" : " " . implode("; ", $partes) . ".";
}

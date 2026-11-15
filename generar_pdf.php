<?php
// 🔧 MOSTRAR ERRORES TEMPORALMENTE (desactivar en producción)
ini_set('display_errors', 0); // si se muestran errores se corrompe el PDF
error_reporting(E_ALL);

// ELIMINAR BUFFER PARA EVITAR ERRORES DE PDF
while (ob_get_level()) ob_end_clean();

require('fpdf/fpdf.php');
include 'conexion.php';
session_start();

// Solo usuarios con sesión iniciada
if (!isset($_SESSION['UsuarioID'])) {
    header("Location: login.html");
    exit();
}

if (!isset($_GET['pedido_id'])) {
    die("ID de pedido inválido.");
}

$pedidoID = intval($_GET['pedido_id']);

// ================= DATOS DEL PEDIDO =================
$stmt = $conn->prepare("
    SELECT p.FechaPedido, p.Total, u.NombreUsuario, u.EmailUsuario
    FROM Pedido p
    INNER JOIN Usuario u ON p.UsuarioID = u.UsuarioID
    WHERE p.PedidoID = ?
");
$stmt->execute([$pedidoID]);
$pedido = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pedido) die("Pedido no encontrado.");

// Un cliente solo puede descargar sus propios pedidos (el administrador puede ver cualquiera)
$stmt = $conn->prepare("SELECT UsuarioID FROM Pedido WHERE PedidoID = ?");
$stmt->execute([$pedidoID]);
$duenoID = $stmt->fetchColumn();
if ($_SESSION['RolID'] != 1 && $duenoID != $_SESSION['UsuarioID']) {
    http_response_code(403);
    die("No tienes permiso para ver este pedido.");
}

// ================= DETALLES =================
$stmt = $conn->prepare("
    SELECT d.Cantidad, d.PrecioUnitario, pr.NombreProducto
    FROM DetallePedido d
    INNER JOIN Producto pr ON d.ProductoID = pr.ProductoID
    WHERE d.PedidoID = ?
");
$stmt->execute([$pedidoID]);
$detalles = $stmt->fetchAll(PDO::FETCH_ASSOC);


// ================= CREACIÓN DEL PDF =================
class PDF extends FPDF {

    function Header() {

        // 🔥 LOGO ELIMINADO PARA EVITAR ERRORES

        $this->SetFont('Arial', 'B', 20);
        $this->SetTextColor(0, 51, 102);
        $this->Cell(0, 10, 'Factura DI-TEC', 0, 1, 'C');
        $this->Ln(5);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial','I',9);
        $this->SetTextColor(120,120,120);
        $this->Cell(0,10,'Gracias por su compra - DI-TEC 2025',0,0,'C');
    }
}

$pdf = new PDF();
$pdf->AddPage();
$pdf->SetFont('Arial', '', 12);

// ------------------ DATOS DEL CLIENTE ------------------
$pdf->SetFillColor(230, 240, 255);
$pdf->SetDrawColor(180, 190, 200);

$pdf->Cell(0, 10, "Datos del Cliente", 1, 1, 'C', true);
$pdf->Ln(3);

$pdf->Cell(100, 8, "Cliente: " . $pedido['NombreUsuario'], 0, 0);
$pdf->Cell(0, 8, "Fecha: " . $pedido['FechaPedido'], 0, 1);

$pdf->Cell(100, 8, "Correo: " . $pedido['EmailUsuario'], 0, 0);
$pdf->Cell(0, 8, "Pedido ID: " . $pedidoID, 0, 1);

$pdf->Ln(5);

// ------------------ TABLA ------------------
$pdf->SetFont('Arial', 'B', 12);
$pdf->SetFillColor(0, 51, 102);
$pdf->SetTextColor(255,255,255);

$pdf->Cell(90, 10, "Producto", 1, 0, 'C', true);
$pdf->Cell(25, 10, "Cantidad", 1, 0, 'C', true);
$pdf->Cell(35, 10, "Precio", 1, 0, 'C', true);
$pdf->Cell(40, 10, "Subtotal", 1, 1, 'C', true);

$pdf->SetFont('Arial', '', 12);
$pdf->SetTextColor(0,0,0);

foreach ($detalles as $item) {

    $nombre = iconv('UTF-8', 'Windows-1252', $item['NombreProducto']);
    if ($nombre === false) $nombre = $item['NombreProducto'];

    $subtotal = $item['Cantidad'] * $item['PrecioUnitario'];

    $pdf->Cell(90, 10, $nombre, 1);
    $pdf->Cell(25, 10, $item['Cantidad'], 1, 0, 'C');
    $pdf->Cell(35, 10, "$".number_format($item['PrecioUnitario'], 2), 1, 0, 'R');
    $pdf->Cell(40, 10, "$".number_format($subtotal, 2), 1, 1, 'R');
}

$pdf->Ln(5);

// ------------------ TOTAL ------------------
$pdf->SetFont('Arial', 'B', 14);
$pdf->SetTextColor(0, 51, 102);
$pdf->Cell(0, 12, "TOTAL A PAGAR: $" . number_format($pedido['Total'], 2), 0, 1, 'R');

// ------------------ NOTA ------------------
$pdf->SetFont('Arial', 'I', 10);
$pdf->SetTextColor(100,100,100);
$pdf->Cell(0, 8, "Este documento es una factura generada automáticamente por DI-TEC.", 0, 1, 'C');

// DESCARGA DEL PDF
$pdf->Output('D', "Pedido_$pedidoID.pdf");
exit;

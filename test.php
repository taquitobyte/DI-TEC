<?php
include('conexion.php');

$stmt = $conn->query("SELECT TOP 1 * FROM Usuario");
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row) {
    echo "✅ Conexión exitosa. Usuario encontrado: " . $row['NombreUsuario'];
} else {
    echo "⚠️ Conexión correcta, pero no hay datos.";
}
?>

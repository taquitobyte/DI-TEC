<?php
$serverName = "localhost\\SQLEXPRESS";
$database = "DITEC";

try {
    $conn = new PDO("sqlsrv:Server=$serverName;Database=$database;TrustServerCertificate=true;", null, null);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
} catch (PDOException $e) {
    die("❌ Error de conexión: " . $e->getMessage());
}
?>

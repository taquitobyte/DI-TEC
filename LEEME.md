# DI-TEC (Distribuidora de Tecnología DV)

Página web en PHP 8.3 + Microsoft SQL Server.

## Cómo ejecutarlo (Windows)
1. **Base de datos:** abrir `ditec_fase2.sql` en SQL Server Management Studio (instancia `localhost\SQLEXPRESS`) y ejecutarlo.
   Crea la base `DITEC`, sus 7 tablas, los 3 roles y un administrador:
   `admin@ditec.com` / `admin123` (cámbiala después de entrar).
   Requiere también el *Microsoft ODBC Driver for SQL Server* (17 o 18).
2. **Correo (recuperar contraseña):** copiar `config_correo.ejemplo.php` como `config_correo.php`
   y llenar el correo Gmail y su contraseña de aplicación. (`config_correo.php` no se sube a Git.)
3. **Servidor:** en la carpeta del proyecto, abrir una terminal y ejecutar:

       .\php\php.exe -S localhost:8000

4. Abrir <http://localhost:8000/login.html>

## Qué ve cada rol
- **Administrador:** gestión de usuarios, proveedores (con productos, pedidos y ventas de cada uno; puede eliminar proveedores y los productos de cada proveedor) y pedidos.
- **Estado de los pedidos:** cada proveedor marca el estado de SUS productos; el pedido pasa a *En Camino* en cuanto alguno avanza y a *Entregado* solo cuando todos los proveedores entregaron (lo ven igual cliente, proveedores y admin). Si el correo está configurado, el cliente recibe un aviso al cambiar el estado.
- **Cancelación de pedidos:** el cliente *solicita* la cancelación desde Mis Pedidos; cualquier proveedor del pedido o el admin puede aceptarla (basta uno, no se necesita la validación de los demás) o rechazarla. Proveedores y admin también pueden cancelar directamente. Al cancelar se regresa el stock de lo no entregado; un pedido totalmente entregado ya no se puede cancelar. Los pedidos cancelados no cuentan como venta.
- **Estadísticas del admin:** lista de proveedores; al dar clic se abren las estadísticas de ese proveedor.
- **Seguridad:** las acciones de borrar/cancelar/cambiar estado son POST con token CSRF; la compra revalida existencias y recalcula el total en el servidor.
- **Proveedor:** sus productos, los pedidos de sus productos y **Mis Estadísticas** (ventas, pedidos por estado, ventas por mes, más vendidos e inventario; solo de sus propios productos).
- **Cliente:** catálogo, carrito, compra, mis pedidos (con factura PDF) y mi cuenta.

## Notas
- PHPMailer ya viene incluido en `lib/PHPMailer` (no hace falta Composer).
- La carpeta `php/` (PHP portable con las extensiones de SQL Server) no se sube a Git; cada equipo la tiene aparte.
- Las contraseñas se guardan cifradas (bcrypt). Las cuentas antiguas con contraseña en texto plano se
  cifran solas la primera vez que inician sesión.
- Prueba de conexión a la BD: <http://localhost:8000/test.php>

-- =====================================================================
-- DI-TEC | Proyecto Final Programacion WEB | Fase 2: Base de datos
-- Script de creacion de la base de datos DITEC (Microsoft SQL Server)
-- Corresponde exactamente al modelo entidad-relacion del reporte.
-- NOTA: elimina y vuelve a crear la base DITEC. Si ya tienes datos
-- que quieres conservar, respalda antes o usa solo los ALTER TABLE
-- que necesites sobre tu base actual.
-- =====================================================================
USE [master];
GO
IF DB_ID('DITEC') IS NOT NULL
BEGIN
    ALTER DATABASE [DITEC] SET SINGLE_USER WITH ROLLBACK IMMEDIATE;
    DROP DATABASE [DITEC];
END
GO
CREATE DATABASE [DITEC];
GO
USE [DITEC];
GO

-- 1) Rol
CREATE TABLE dbo.Rol (
    RolID          INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    NombreRol      NVARCHAR(50)  NOT NULL,
    DescripcionRol NVARCHAR(255) NULL
);
GO
INSERT INTO dbo.Rol (NombreRol, DescripcionRol) VALUES
 (N'Administrador', N'Administra usuarios, proveedores, productos y pedidos; consulta estadisticas'),
 (N'Usuario',       N'Cliente: consulta productos, usa el carrito y realiza pedidos'),
 (N'Proveedor',     N'Administra sus productos y actualiza el estado de los pedidos relacionados');
GO

-- 2) Usuario
CREATE TABLE dbo.Usuario (
    UsuarioID          INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    NombreUsuario      NVARCHAR(100) NOT NULL,
    EmailUsuario       NVARCHAR(100) NOT NULL,
    TelefonoUsuario    NVARCHAR(20)  NULL,
    DireccionUsuario   NVARCHAR(255) NULL,
    ContrasenaUsuario  NVARCHAR(255) NOT NULL,
    RolID              INT NOT NULL CONSTRAINT DF_Usuario_RolID DEFAULT (2),
    FechaRegistro      DATETIME NULL CONSTRAINT DF_Usuario_Fecha DEFAULT (GETDATE()),
    token_recuperacion VARCHAR(255) NULL,
    token_expira       DATETIME NULL,
    CONSTRAINT UQ_Usuario_Email UNIQUE (EmailUsuario),
    CONSTRAINT FK_Usuario_Rol FOREIGN KEY (RolID) REFERENCES dbo.Rol(RolID)
);
GO

-- 3) Proveedor (1:1 con Usuario)
CREATE TABLE dbo.Proveedor (
    ProveedorID        INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    UsuarioID          INT NOT NULL,
    NombreProveedor    NVARCHAR(100) NOT NULL,
    DireccionProveedor NVARCHAR(255) NULL,
    TelefonoProveedor  NVARCHAR(20)  NULL,
    EmailProveedor     NVARCHAR(100) NULL,
    Calificacion       DECIMAL(2,1)  NULL CONSTRAINT DF_Proveedor_Calif DEFAULT (0),
    CONSTRAINT UQ_Proveedor_Usuario UNIQUE (UsuarioID),
    CONSTRAINT FK_Proveedor_Usuario FOREIGN KEY (UsuarioID) REFERENCES dbo.Usuario(UsuarioID)
);
GO
-- Correo unico solo cuando exista (permite varios NULL)
CREATE UNIQUE INDEX UQ_Proveedor_Email ON dbo.Proveedor(EmailProveedor) WHERE EmailProveedor IS NOT NULL;
GO

-- 4) Producto
CREATE TABLE dbo.Producto (
    ProductoID     INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    ProveedorID    INT NOT NULL,
    NombreProducto NVARCHAR(150) NOT NULL,
    Descripcion    NVARCHAR(255) NULL,
    Precio         DECIMAL(10,2) NOT NULL,
    Stock          INT NULL CONSTRAINT DF_Producto_Stock DEFAULT (0),
    Imagen         NVARCHAR(255) NULL,
    CONSTRAINT FK_Producto_Proveedor FOREIGN KEY (ProveedorID) REFERENCES dbo.Proveedor(ProveedorID)
);
GO

-- 5) Carrito (N:M Usuario - Producto)
CREATE TABLE dbo.Carrito (
    CarritoID     INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    UsuarioID     INT NOT NULL,
    ProductoID    INT NOT NULL,
    Cantidad      INT NOT NULL CONSTRAINT CK_Carrito_Cantidad CHECK (Cantidad > 0),
    FechaAgregado DATETIME NULL CONSTRAINT DF_Carrito_Fecha DEFAULT (GETDATE()),
    CONSTRAINT FK_Carrito_Usuario  FOREIGN KEY (UsuarioID)  REFERENCES dbo.Usuario(UsuarioID)   ON DELETE CASCADE,
    CONSTRAINT FK_Carrito_Producto FOREIGN KEY (ProductoID) REFERENCES dbo.Producto(ProductoID) ON DELETE CASCADE
);
GO

-- 6) Pedido
CREATE TABLE dbo.Pedido (
    PedidoID    INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    UsuarioID   INT NOT NULL,
    FechaPedido DATE NOT NULL CONSTRAINT DF_Pedido_Fecha  DEFAULT (GETDATE()),
    Estado      NVARCHAR(50) NULL CONSTRAINT DF_Pedido_Estado DEFAULT ('Pendiente'),
    Total       DECIMAL(10,2) NOT NULL CONSTRAINT DF_Pedido_Total DEFAULT (0),
    CONSTRAINT FK_Pedido_Usuario FOREIGN KEY (UsuarioID) REFERENCES dbo.Usuario(UsuarioID) ON DELETE CASCADE
);
GO

-- 7) DetallePedido (N:M Pedido - Producto)
CREATE TABLE dbo.DetallePedido (
    DetalleID      INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    PedidoID       INT NOT NULL,
    ProductoID     INT NOT NULL,
    Cantidad       INT NOT NULL,
    PrecioUnitario DECIMAL(10,2) NOT NULL,
    Subtotal       AS (Cantidad * PrecioUnitario) PERSISTED,
    Estado         NVARCHAR(50) NULL CONSTRAINT DF_Detalle_Estado DEFAULT ('Pendiente'),
    CONSTRAINT FK_Detalle_Pedido   FOREIGN KEY (PedidoID)   REFERENCES dbo.Pedido(PedidoID) ON DELETE CASCADE,
    CONSTRAINT FK_Detalle_Producto FOREIGN KEY (ProductoID) REFERENCES dbo.Producto(ProductoID)
);
GO

-- 8) Administrador inicial (correo: admin@ditec.com  |  contrasena: admin123)
--    La contrasena ya va cifrada (bcrypt). Cambiala desde "Mi cuenta" o desde Gestion de Usuarios.
INSERT INTO dbo.Usuario (NombreUsuario, EmailUsuario, ContrasenaUsuario, RolID)
VALUES (N'Administrador', N'admin@ditec.com', N'$2y$10$CJwrvHVgWWNZz9fx0VDl9ecjSZeeuExpfdMhna9bgXQfcGqypl3vK', 1);
GO

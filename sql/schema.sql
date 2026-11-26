CREATE TABLE IF NOT EXISTS grupos(id INTEGER PRIMARY KEY, nombre TEXT UNIQUE, descripcion TEXT);
INSERT OR IGNORE INTO grupos(nombre, descripcion) VALUES
('usuarios','Estudiantes con cuenta: pueden buscar y reservar'),
('recepcionista','Bibliotecarios: validan prestamos y devoluciones'),
('admins','Administradores: crean usuarios y ven reportes');
CREATE TABLE IF NOT EXISTS users(
 id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT, correo TEXT UNIQUE,
 clave TEXT, rol TEXT, grupo TEXT, activo INTEGER DEFAULT 1);
CREATE TABLE IF NOT EXISTS books(id INTEGER PRIMARY KEY AUTOINCREMENT, titulo TEXT, autor TEXT, stock INTEGER);
CREATE TABLE IF NOT EXISTS loans(id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, book_id INTEGER, estado TEXT, folio TEXT);

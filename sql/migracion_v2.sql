-- =====================================================================
-- Sistema Escuela — Migracion de la version 1 a la version 2
--
-- Usa este archivo SOLO si ya tienes datos capturados y no quieres
-- borrar la base. Si vas a empezar de cero, corre sql/schema.sql.
--
-- Haz un respaldo antes (el modulo Respaldo del sistema, o phpMyAdmin >
-- Exportar) y luego pega esto en la pestana SQL de phpMyAdmin.
-- =====================================================================

USE escuela_demo;

-- ---------- 1. Salones reales de la escuela ----------
ALTER TABLE salones
    ADD COLUMN tipo ENUM('salon','compartido','exterior') NOT NULL DEFAULT 'salon' AFTER capacidad,
    ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1;

-- Renombra los salones de ejemplo y agrega los que faltan.
UPDATE salones SET nombre = 'Salon 1', capacidad = 2, tipo = 'salon' WHERE id_salon = 1;
UPDATE salones SET nombre = 'Salon 2', capacidad = 2, tipo = 'salon' WHERE id_salon = 2;
UPDATE salones SET nombre = 'Salon 3', capacidad = 2, tipo = 'salon' WHERE id_salon = 3;
UPDATE salones SET nombre = 'Salon 4', capacidad = 4, tipo = 'salon' WHERE id_salon = 4;

INSERT INTO salones (nombre, capacidad, tipo) VALUES
('Salon 5', 4, 'salon'),
('Salon 6', 6, 'salon'),
('Cocina',  4, 'compartido'),
('Patio',  12, 'exterior');

-- ---------- 2. Horario de apertura de la escuela ----------
CREATE TABLE IF NOT EXISTS horario_escuela (
    dia_semana ENUM('Lunes','Martes','Miercoles','Jueves','Viernes','Sabado') PRIMARY KEY,
    abre_1 TIME NOT NULL,
    cierra_1 TIME NOT NULL,
    abre_2 TIME NULL,
    cierra_2 TIME NULL
);

INSERT INTO horario_escuela (dia_semana, abre_1, cierra_1, abre_2, cierra_2) VALUES
('Lunes',     '09:00:00','14:00:00','16:00:00','20:00:00'),
('Martes',    '09:00:00','14:00:00','16:00:00','20:00:00'),
('Miercoles', '09:00:00','14:00:00','16:00:00','20:00:00'),
('Jueves',    '09:00:00','14:00:00','16:00:00','20:00:00'),
('Viernes',   '09:00:00','14:00:00','16:00:00','20:00:00'),
('Sabado',    '09:00:00','14:00:00', NULL,      NULL)
ON DUPLICATE KEY UPDATE abre_1 = VALUES(abre_1);

-- ---------- 3. Bloques de 9 a 14 y de 16 a 20 ----------
ALTER TABLE bloques_horario
    ADD COLUMN turno ENUM('matutino','vespertino') NOT NULL DEFAULT 'matutino';

UPDATE bloques_horario SET turno = IF(hora_inicio >= '15:00:00', 'vespertino', 'matutino');

INSERT INTO bloques_horario (hora_inicio, hora_fin, turno) VALUES
('13:00:00','14:00:00','matutino')
ON DUPLICATE KEY UPDATE turno = VALUES(turno);

-- ---------- 4. Inscripciones sin horario asignado ----------
ALTER TABLE inscripciones
    MODIFY id_instrumento INT NULL,
    MODIFY id_salon INT NULL,
    MODIFY dia_semana ENUM('Lunes','Martes','Miercoles','Jueves','Viernes','Sabado') NULL,
    MODIFY hora_inicio TIME NULL,
    MODIFY hora_fin TIME NULL,
    MODIFY estado ENUM('activa','pendiente','baja') NOT NULL DEFAULT 'activa',
    ADD COLUMN origen ENUM('captura','importacion') NOT NULL DEFAULT 'captura',
    ADD INDEX idx_insc_estado (estado);

-- ---------- 5. Nomina historica por periodo ----------
-- Se quita la unicidad por inscripcion (impedia cobrar el mismo alumno mas de
-- un mes). La llave foranea se apoya en ese indice, asi que hay que soltarla
-- primero y volver a ponerla despues.
--
-- Si estas instrucciones te dan error, no las pelees: usa
-- backend/actualizar_base.php, que detecta los nombres reales de tu base
-- y aplica solo lo que falta.
ALTER TABLE nomina_detalle DROP FOREIGN KEY nomina_detalle_ibfk_2;
ALTER TABLE nomina_detalle DROP INDEX id_inscripcion;
ALTER TABLE nomina_detalle ADD INDEX idx_nomina_inscripcion (id_inscripcion);
ALTER TABLE nomina_detalle
    ADD CONSTRAINT fk_nomina_inscripcion
    FOREIGN KEY (id_inscripcion) REFERENCES inscripciones(id_inscripcion) ON DELETE CASCADE;
ALTER TABLE nomina_detalle
    ADD COLUMN periodo CHAR(7) NOT NULL DEFAULT '0000-00' AFTER id_alumno,
    ADD COLUMN pagado TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN fecha_pago DATE NULL;

-- Las filas que ya existian se asignan al mes en que se registraron.
UPDATE nomina_detalle SET periodo = DATE_FORMAT(fecha_registro, '%Y-%m') WHERE periodo = '0000-00';

ALTER TABLE nomina_detalle
    ALTER COLUMN periodo DROP DEFAULT,
    ADD UNIQUE KEY uq_inscripcion_periodo (id_inscripcion, periodo),
    ADD INDEX idx_nomina_periodo (periodo, id_maestro);

-- ---------- 6. Bitacora de importaciones ----------
CREATE TABLE IF NOT EXISTS importaciones (
    id_importacion INT AUTO_INCREMENT PRIMARY KEY,
    tipo ENUM('nomina','horarios','pagos','alumnos') NOT NULL,
    archivo VARCHAR(255) NOT NULL,
    periodo CHAR(7) NULL,
    maestros_nuevos INT NOT NULL DEFAULT 0,
    alumnos_nuevos INT NOT NULL DEFAULT 0,
    filas_importadas INT NOT NULL DEFAULT 0,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    usuario VARCHAR(100) NULL,
    fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ---------- 7. Instrumentos que faltaban ----------
INSERT IGNORE INTO instrumentos (nombre) VALUES
('Saxofon'),('Flauta'),('Ukulele'),('Bajo'),('Teclado'),
('Pintura'),('Dibujo'),('Solfeo');

-- ---------- 8. Indices de busqueda ----------
ALTER TABLE alumnos  ADD INDEX idx_alumno_nombre (apellido, nombre);
ALTER TABLE maestros ADD INDEX idx_maestro_nombre (apellido, nombre);
ALTER TABLE pagos    ADD INDEX idx_pago_periodo (periodo);

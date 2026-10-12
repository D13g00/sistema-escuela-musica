-- =====================================================================
-- Sistema Escuela — Datos de demostración
--
-- TODOS LOS DATOS SON FICTICIOS: nombres inventados, teléfonos 555 de
-- ejemplo y correos @example.com. No corresponden a ninguna persona real.
--
-- Uso: después de sql/schema.sql, sobre la base escuela_demo recién creada
-- (vacía, sin capturas previas: los id se escriben fijos). Se importa en
-- phpMyAdmin > escuela_demo > Importar, o con:
--   mysql -u root escuela_demo < sql/datos_demo.sql
--
-- No crea usuarios: el primer administrador se crea con
-- backend/herramientas/crear_admin.php (no hay contraseña de fábrica).
-- =====================================================================

USE escuela_demo;

START TRANSACTION;

-- ---------------------------------------------------------------------
-- Maestros (4). La primera es la dueña: da clases pero no lleva nómina.
-- ---------------------------------------------------------------------
INSERT INTO maestros (id_maestro, nombre, apellido, telefono, email,
                      tarifa_por_alumno, tarifa_por_hora, modo_pago, bono_puntualidad, lleva_nomina) VALUES
(1, 'Elena',  'Robles',  '555 010 0001', 'elena.robles@example.com',    0,   0, 'por_alumno',   0, 0),
(2, 'Hugo',   'Navarro', '555 010 0002', 'hugo.navarro@example.com',  450,   0, 'por_alumno', 200, 1),
(3, 'Paula',  'Ibarra',  '555 010 0003', 'paula.ibarra@example.com',    0, 180, 'por_hora',     0, 1),
(4, 'Ramiro', 'Cuevas',  '555 010 0004', 'ramiro.cuevas@example.com', 450,   0, 'por_alumno', 150, 1);

-- Qué materias da cada maestro (por nombre, para no depender de los id del catálogo)
INSERT INTO maestro_instrumento (id_maestro, id_instrumento)
SELECT 1, id_instrumento FROM instrumentos WHERE nombre IN ('Piano', 'Canto')
UNION ALL
SELECT 2, id_instrumento FROM instrumentos WHERE nombre IN ('Guitarra', 'Bajo', 'Ukulele')
UNION ALL
SELECT 3, id_instrumento FROM instrumentos WHERE nombre IN ('Violin', 'Solfeo', 'Dibujo', 'Pintura')
UNION ALL
SELECT 4, id_instrumento FROM instrumentos WHERE nombre IN ('Bateria');

-- ---------------------------------------------------------------------
-- Una familia con dos hermanos y descuento familiar
-- ---------------------------------------------------------------------
INSERT INTO familias (id_familia, nombre, responsable, telefono, descuento_mensual, descuento_desde, notas) VALUES
(1, 'Familia Ortega Fuentes', 'Laura Fuentes (madre)', '555 010 0101', 90, '2026-08',
 'Datos ficticios de demostración');

-- ---------------------------------------------------------------------
-- Alumnos (12)
-- ---------------------------------------------------------------------
INSERT INTO alumnos (id_alumno, nombre, apellido, telefono, email, fecha_nacimiento,
                     tutor_nombre, tutor_telefono, tutor_parentesco, direccion, notas,
                     adeudo_anterior, adeudo_anterior_nota, fecha_registro, activo, id_familia,
                     requiere_factura, rfc, razon_social, correo_factura, datos_fiscales) VALUES
(1,  'Valentina', 'Ortega Fuentes',  NULL,           NULL,                          '2014-03-12',
     'Laura Fuentes', '555 010 0101', 'Madre', 'Calle Ejemplo 123, Col. Centro', NULL,
     0, NULL, '2026-08-03', 1, 1, 0, NULL, NULL, NULL, NULL),
(2,  'Mateo',     'Ortega Fuentes',  NULL,           NULL,                          '2016-07-25',
     'Laura Fuentes', '555 010 0101', 'Madre', 'Calle Ejemplo 123, Col. Centro', NULL,
     0, NULL, '2026-08-03', 1, 1, 0, NULL, NULL, NULL, NULL),
(3,  'Camila',    'Herrera Soto',    '555 010 0103', 'camila.herrera@example.com',  '2001-11-02',
     NULL, NULL, NULL, NULL, NULL,
     0, NULL, '2026-08-03', 1, NULL, 1, 'XAXX010101000', 'Camila Herrera Soto', 'facturas@example.com',
     'Régimen de ejemplo, C.P. 00000, uso G03'),
(4,  'Santiago',  'Morales Rivas',   '555 010 0104', 'santiago.morales@example.com', '1998-05-19',
     NULL, NULL, NULL, NULL, NULL,
     0, NULL, '2026-08-03', 1, NULL, 0, NULL, NULL, NULL, NULL),
(5,  'Regina',    'Castillo Mejía',  '555 010 0105', 'regina.castillo@example.com', '2005-01-30',
     NULL, NULL, NULL, NULL, NULL,
     0, NULL, '2026-08-03', 1, NULL, 0, NULL, NULL, NULL, NULL),
(6,  'Emilio',    'Vargas Peña',     '555 010 0106', NULL,                          '2010-09-08',
     'Jorge Vargas', '555 010 0116', 'Padre', 'Av. Demostración 45', NULL,
     0, NULL, '2026-08-03', 1, NULL, 0, NULL, NULL, NULL, NULL),
(7,  'Ximena',    'Domínguez Lara',  '555 010 0107', 'ximena.dominguez@example.com', '2003-04-14',
     NULL, NULL, NULL, NULL, NULL,
     0, NULL, '2026-09-01', 1, NULL, 0, NULL, NULL, NULL, NULL),
(8,  'Leonardo',  'Aguilar Campos',  '555 010 0108', NULL,                          '1995-12-01',
     NULL, NULL, NULL, NULL, NULL,
     0, NULL, '2026-08-03', 1, NULL, 0, NULL, NULL, NULL, NULL),
(9,  'Renata',    'Salinas Ochoa',   NULL,           NULL,                          '2012-06-21',
     'Patricia Ochoa', '555 010 0119', 'Madre', NULL, NULL,
     0, NULL, '2026-08-03', 1, NULL, 0, NULL, NULL, NULL, NULL),
(10, 'Joaquín',   'Medina Rojas',    '555 010 0110', 'joaquin.medina@example.com',  '1990-02-11',
     NULL, NULL, NULL, NULL, 'Toma su clase en línea',
     0, NULL, '2026-09-01', 1, NULL, 0, NULL, NULL, NULL, NULL),
(11, 'Isabela',   'Guerrero Pineda', '555 010 0111', NULL,                          '2008-10-05',
     NULL, NULL, NULL, NULL, NULL,
     0, NULL, '2026-08-03', 1, NULL, 0, NULL, NULL, NULL, NULL),
(12, 'Daniel',    'Cortés Villa',    '555 010 0112', NULL,                          '2011-08-17',
     'Mónica Villa', '555 010 0122', 'Madre', NULL, NULL,
     450, 'Adeudo de julio, anterior al sistema', '2026-08-03', 1, NULL, 0, NULL, NULL, NULL, NULL);

-- ---------------------------------------------------------------------
-- Inscripciones (15): 13 con horario, 1 en línea y 1 pendiente de asignar
-- id_instrumento e id_salon se buscan por nombre en los catálogos de schema.sql
-- ---------------------------------------------------------------------
INSERT INTO inscripciones (id_inscripcion, id_alumno, id_maestro, id_instrumento, id_salon, modalidad,
                           dia_semana, hora_inicio, hora_fin, duracion_min, costo_mensual,
                           fecha_alta, estado, origen) VALUES
(1,  1, 1, (SELECT id_instrumento FROM instrumentos WHERE nombre = 'Piano'),    (SELECT id_salon FROM salones WHERE nombre = 'Salon 1'), 'presencial', 'Lunes',     '16:00:00', '17:00:00', 60, 900, '2026-08-03', 'activa', 'captura'),
(2,  3, 1, (SELECT id_instrumento FROM instrumentos WHERE nombre = 'Piano'),    (SELECT id_salon FROM salones WHERE nombre = 'Salon 1'), 'presencial', 'Lunes',     '17:00:00', '18:00:00', 60, 900, '2026-08-03', 'activa', 'captura'),
(3,  5, 1, (SELECT id_instrumento FROM instrumentos WHERE nombre = 'Canto'),    (SELECT id_salon FROM salones WHERE nombre = 'Salon 1'), 'presencial', 'Miercoles', '16:00:00', '17:00:00', 60, 900, '2026-08-03', 'activa', 'captura'),
(4, 11, 1, (SELECT id_instrumento FROM instrumentos WHERE nombre = 'Piano'),    (SELECT id_salon FROM salones WHERE nombre = 'Salon 1'), 'presencial', 'Sabado',    '10:00:00', '10:30:00', 30, 450, '2026-08-03', 'activa', 'captura'),
(5,  2, 2, (SELECT id_instrumento FROM instrumentos WHERE nombre = 'Guitarra'), (SELECT id_salon FROM salones WHERE nombre = 'Salon 2'), 'presencial', 'Martes',    '16:00:00', '17:00:00', 60, 900, '2026-08-03', 'activa', 'captura'),
(6,  4, 2, (SELECT id_instrumento FROM instrumentos WHERE nombre = 'Guitarra'), (SELECT id_salon FROM salones WHERE nombre = 'Salon 2'), 'presencial', 'Martes',    '17:00:00', '18:00:00', 60, 900, '2026-08-03', 'activa', 'captura'),
(7,  6, 2, (SELECT id_instrumento FROM instrumentos WHERE nombre = 'Bajo'),     (SELECT id_salon FROM salones WHERE nombre = 'Salon 2'), 'presencial', 'Jueves',    '18:00:00', '19:00:00', 60, 900, '2026-08-03', 'activa', 'captura'),
(8, 10, 2, (SELECT id_instrumento FROM instrumentos WHERE nombre = 'Ukulele'),  NULL,                                                    'online',     'Jueves',    '19:00:00', '20:00:00', 60, 900, '2026-09-01', 'activa', 'captura'),
(9,  7, 3, (SELECT id_instrumento FROM instrumentos WHERE nombre = 'Violin'),   (SELECT id_salon FROM salones WHERE nombre = 'Salon 3'), 'presencial', 'Miercoles', '17:00:00', '18:00:00', 60, 900, '2026-09-01', 'activa', 'captura'),
(10, 1, 3, (SELECT id_instrumento FROM instrumentos WHERE nombre = 'Solfeo'),   (SELECT id_salon FROM salones WHERE nombre = 'Salon 3'), 'presencial', 'Viernes',   '16:00:00', '17:00:00', 60, 900, '2026-08-03', 'activa', 'captura'),
(11, 9, 3, (SELECT id_instrumento FROM instrumentos WHERE nombre = 'Dibujo'),   (SELECT id_salon FROM salones WHERE nombre = 'Patio'),   'presencial', 'Sabado',    '10:00:00', '11:00:00', 60, 700, '2026-08-03', 'activa', 'captura'),
(12, 12, 3, (SELECT id_instrumento FROM instrumentos WHERE nombre = 'Pintura'), (SELECT id_salon FROM salones WHERE nombre = 'Patio'),   'presencial', 'Sabado',    '10:00:00', '11:00:00', 60, 700, '2026-08-03', 'activa', 'captura'),
(13, 8, 4, (SELECT id_instrumento FROM instrumentos WHERE nombre = 'Bateria'),  (SELECT id_salon FROM salones WHERE nombre = 'Salon 6'), 'presencial', 'Lunes',     '18:00:00', '19:00:00', 60, 900, '2026-08-03', 'activa', 'captura'),
(14, 6, 4, (SELECT id_instrumento FROM instrumentos WHERE nombre = 'Bateria'),  (SELECT id_salon FROM salones WHERE nombre = 'Salon 6'), 'presencial', 'Viernes',   '17:00:00', '18:00:00', 60, 900, '2026-08-03', 'activa', 'captura'),
-- Pendiente de asignar: ya está con su maestro, pero todavía sin día, hora ni salón
(15, 11, 2, (SELECT id_instrumento FROM instrumentos WHERE nombre = 'Guitarra'), NULL,                                                   'presencial', NULL,        NULL,       NULL,       60, 900, '2026-09-01', 'pendiente', 'captura');

-- Ocupación actual de salones y maestros (solo las clases activas con horario).
-- Las de Dibujo y Pintura son del taller (en_taller = 1): pueden empezar juntas.
INSERT INTO horario_ocupado (id_inscripcion, id_salon, id_maestro, dia_semana, hora_inicio, hora_fin, en_taller)
SELECT ins.id_inscripcion, ins.id_salon, ins.id_maestro, ins.dia_semana, ins.hora_inicio, ins.hora_fin,
       COALESCE(i.es_grupal, 0)
  FROM inscripciones ins
  LEFT JOIN instrumentos i ON i.id_instrumento = ins.id_instrumento
 WHERE ins.estado = 'activa' AND ins.dia_semana IS NOT NULL;

-- ---------------------------------------------------------------------
-- Pagos de agosto a octubre de 2026. Algunos alumnos quedan con adeudo a
-- propósito (Santiago debe septiembre, Isabela no ha pagado nada), para
-- que la cobranza mes por mes tenga algo que mostrar.
-- ---------------------------------------------------------------------
INSERT INTO pagos (id_pago, id_alumno, id_inscripcion, concepto, nombre_prospecto, telefono_prospecto,
                   duracion_min, id_maestro, monto, fecha_pago, recargo, metodo, monto_confirmado,
                   periodo, origen, operacion, anula_a, anulado, registrado_por) VALUES
-- Familia Ortega Fuentes: colegiatura menos su parte del descuento familiar
(1,  1,  NULL, 'colegiatura', NULL, NULL, NULL, NULL, 1740, '2026-08-05', 0,  'transferencia', 1, '2026-08', 'captura', '260805-A1B2C3', NULL, 0, 'admin'),
(2,  2,  NULL, 'colegiatura', NULL, NULL, NULL, NULL,  870, '2026-08-05', 0,  'transferencia', 1, '2026-08', 'captura', '260805-A1B2C3', NULL, 0, 'admin'),
(3,  1,  NULL, 'colegiatura', NULL, NULL, NULL, NULL, 1740, '2026-09-04', 0,  'transferencia', 1, '2026-09', 'captura', '260904-D4E5F6', NULL, 0, 'admin'),
(4,  2,  NULL, 'colegiatura', NULL, NULL, NULL, NULL,  870, '2026-09-04', 0,  'transferencia', 1, '2026-09', 'captura', '260904-D4E5F6', NULL, 0, 'admin'),
-- Camila: al corriente, incluso octubre
(5,  3,  NULL, 'colegiatura', NULL, NULL, NULL, NULL,  900, '2026-08-06', 0,  'efectivo',      1, '2026-08', 'captura', '260806-0A0B0C', NULL, 0, 'admin'),
(6,  3,  NULL, 'colegiatura', NULL, NULL, NULL, NULL,  900, '2026-09-07', 0,  'efectivo',      1, '2026-09', 'captura', '260907-0D0E0F', NULL, 0, 'admin'),
(7,  3,  NULL, 'colegiatura', NULL, NULL, NULL, NULL,  900, '2026-10-05', 0,  'tarjeta',       1, '2026-10', 'captura', '261005-101112', NULL, 0, 'admin'),
-- Santiago: pagó agosto; debe septiembre
(8,  4,  NULL, 'colegiatura', NULL, NULL, NULL, NULL,  900, '2026-08-08', 0,  'efectivo',      1, '2026-08', 'captura', '260808-131415', NULL, 0, 'admin'),
-- Regina: septiembre fuera de fecha, con recargo
(9,  5,  NULL, 'colegiatura', NULL, NULL, NULL, NULL,  900, '2026-08-09', 0,  'efectivo',      1, '2026-08', 'captura', '260809-161718', NULL, 0, 'admin'),
(10, 5,  NULL, 'colegiatura', NULL, NULL, NULL, NULL,  900, '2026-09-15', 50, 'efectivo',      1, '2026-09', 'captura', '260915-191A1B', NULL, 0, 'admin'),
-- Emilio: dos clases
(11, 6,  NULL, 'colegiatura', NULL, NULL, NULL, NULL, 1800, '2026-08-04', 0,  'transferencia', 1, '2026-08', 'captura', '260804-1C1D1E', NULL, 0, 'admin'),
(12, 6,  NULL, 'colegiatura', NULL, NULL, NULL, NULL, 1800, '2026-09-03', 0,  'transferencia', 1, '2026-09', 'captura', '260903-1F2021', NULL, 0, 'admin'),
-- Ximena: entró en septiembre
(13, 7,  NULL, 'colegiatura', NULL, NULL, NULL, NULL,  900, '2026-09-02', 0,  'tarjeta',       1, '2026-09', 'captura', '260902-222324', NULL, 0, 'admin'),
-- Leonardo: agosto y septiembre
(14, 8,  NULL, 'colegiatura', NULL, NULL, NULL, NULL,  900, '2026-08-07', 0,  'efectivo',      1, '2026-08', 'captura', '260807-252627', NULL, 0, 'admin'),
(15, 8,  NULL, 'colegiatura', NULL, NULL, NULL, NULL,  900, '2026-09-08', 0,  'efectivo',      1, '2026-09', 'captura', '260908-28292A', NULL, 0, 'admin'),
-- Renata: taller de Dibujo
(16, 9,  NULL, 'colegiatura', NULL, NULL, NULL, NULL,  700, '2026-08-10', 0,  'efectivo',      1, '2026-08', 'captura', '260810-2B2C2D', NULL, 0, 'admin'),
(17, 9,  NULL, 'colegiatura', NULL, NULL, NULL, NULL,  700, '2026-09-10', 0,  'efectivo',      1, '2026-09', 'captura', '260910-2E2F30', NULL, 0, 'admin'),
-- Joaquín: clase en línea desde septiembre
(18, 10, NULL, 'colegiatura', NULL, NULL, NULL, NULL,  900, '2026-09-01', 0,  'tarjeta',       1, '2026-09', 'captura', '260901-313233', NULL, 0, 'admin'),
-- Daniel: abonó su adeudo anterior y pagó agosto
(19, 12, NULL, 'adeudo_anterior', NULL, NULL, NULL, NULL, 450, '2026-08-03', 0, 'efectivo',    1, NULL,      'captura', '260803-343536', NULL, 0, 'admin'),
(20, 12, NULL, 'colegiatura', NULL, NULL, NULL, NULL,  700, '2026-08-03', 0,  'efectivo',      1, '2026-08', 'captura', '260803-343536', NULL, 0, 'admin'),
-- Clase muestra de alguien que todavía no es alumno (se le paga al maestro en su nómina)
(21, NULL, NULL, 'clase_muestra', 'Prospecto de Ejemplo', '555 010 0199', 60, 2, 250, '2026-09-12', 0, 'efectivo', 1, '2026-09', 'captura', '260912-373839', NULL, 0, 'admin'),
-- Un pago capturado dos veces por error: no se borra, se anula con un contramovimiento
(22, 8,  NULL, 'colegiatura', NULL, NULL, NULL, NULL,  900, '2026-09-08', 0,  'efectivo',      1, '2026-09', 'captura', '260908-3A3B3C', NULL, 1, 'admin'),
(23, 8,  NULL, 'colegiatura', NULL, NULL, NULL, NULL, -900, '2026-09-08', 0,  'efectivo',      1, '2026-09', 'captura', '260908-3D3E3F', 22,   0, 'admin');

INSERT INTO pagos_cambios (id_pago, campo, valor_anterior, valor_nuevo, motivo, usuario, fecha) VALUES
(22, 'anulado', '0', '1', 'Se capturó dos veces el mismo pago', 'admin', '2026-09-08 18:20:00');

INSERT INTO bitacora_financiera (fecha, usuario, operacion, tabla, id_registro, campo,
                                 valor_anterior, valor_nuevo, motivo, folio, origen) VALUES
('2026-09-08 18:20:00', 'admin', 'anular pago', 'pagos', 22, 'anulado', '0', '1',
 'Se capturó dos veces el mismo pago', '260908-3D3E3F', 'pantalla');

-- Una falta con su suplencia en la nómina de septiembre
INSERT INTO nomina_ajustes (id_ajuste, id_maestro, periodo, fecha, tipo, id_inscripcion, minutos, monto, motivo,
                            cubre_falta, pagado, registrado_por) VALUES
(1, 4, '2026-09', '2026-09-14', 'falta',     13, 60, -112.50, 'Avisó que no podía venir', NULL, 0, 'admin'),
(2, 2, '2026-09', '2026-09-14', 'suplencia', 13, 60,  112.50, 'Cubrió la clase de Batería', 1,  0, 'admin');

-- Una nota en la bitácora del equipo
INSERT INTO bitacora_notas (autor, mensaje, fecha_creacion, resuelto) VALUES
('admin', 'Datos de demostración cargados. Todos los nombres son ficticios.', '2026-10-01 09:00:00', 0);

COMMIT;

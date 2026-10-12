-- =====================================================================
-- Sistema Escuela — Escuela de música
-- Esquema de base de datos · MySQL (XAMPP)
-- Versión 2 (septiembre 2026)
--
-- Cambios respecto a la versión 1:
--   · salones de ejemplo (6 salones + cocina + patio)
--   · tabla horario_escuela: en qué horas abre la escuela cada día
--   · bloques horarios de 9 a 14 y de 16 a 20 (sábado solo matutino)
--   · nomina_detalle es histórica: lleva periodo (YYYY-MM) y estado de pago
--   · inscripciones admite estado 'pendiente' (importadas sin horario aún)
--   · tabla importaciones: bitácora de lo que se sube desde Excel
--
-- Solo para una instalación NUEVA. Nunca borra nada: si la base ya tiene
-- tablas, se detiene en la primera ("Table ... already exists") sin tocar
-- ningún dato. Para actualizar una base con datos usa
-- backend/actualizar_base.php, no este archivo.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS escuela_demo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE escuela_demo;

-- ---------------------------------------------------------------------
-- Catálogos base
-- ---------------------------------------------------------------------

-- Grupo operativo: materias que en la escuela son la misma clase (Dibujo y
-- Pintura). Sus alumnos forman un grupo aunque lleguen a horas distintas.
CREATE TABLE grupos_materia (
    id_grupo_materia INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(60) NOT NULL UNIQUE
);

CREATE TABLE instrumentos (
    id_instrumento INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(60) NOT NULL UNIQUE,
    costo_mensual DECIMAL(10,2) NOT NULL DEFAULT 0
        COMMENT 'Lo que paga el alumno al mes por una hora semanal de esta clase',
    es_grupal TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Varios alumnos a la vez en el mismo salon y hora (dibujo, pintura)',
    id_grupo_materia INT NULL,
    CONSTRAINT fk_instrumento_grupo FOREIGN KEY (id_grupo_materia)
        REFERENCES grupos_materia(id_grupo_materia) ON DELETE SET NULL
);

CREATE TABLE salones (
    id_salon INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(60) NOT NULL,
    capacidad INT NOT NULL DEFAULT 1,
    tipo ENUM('salon','compartido','exterior') NOT NULL DEFAULT 'salon'
        COMMENT 'compartido = la cocina, que a veces se usa como salon; exterior = el patio',
    activo TINYINT(1) NOT NULL DEFAULT 1
);

-- Horario de apertura de la escuela, por dia.
-- El turno 2 puede ser NULL (el sabado solo hay turno matutino).
CREATE TABLE horario_escuela (
    dia_semana ENUM('Lunes','Martes','Miercoles','Jueves','Viernes','Sabado') PRIMARY KEY,
    abre_1 TIME NOT NULL,
    cierra_1 TIME NOT NULL,
    abre_2 TIME NULL,
    cierra_2 TIME NULL
);

-- Bloques estandar de una hora, para llenar los selects del frontend.
CREATE TABLE bloques_horario (
    id_bloque INT AUTO_INCREMENT PRIMARY KEY,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    turno ENUM('matutino','vespertino') NOT NULL,
    UNIQUE KEY uq_bloque (hora_inicio, hora_fin)
);

-- ---------------------------------------------------------------------
-- Entidades principales
-- ---------------------------------------------------------------------

-- Hermanos que paga una misma persona. El descuento acordado para toda la
-- familia se reparte entre sus alumnos en proporcion a su colegiatura.
CREATE TABLE familias (
    id_familia INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(120) NOT NULL,
    responsable VARCHAR(120) NULL COMMENT 'Quien paga: padre, madre o tutor',
    telefono VARCHAR(20) NULL,
    descuento_mensual DECIMAL(10,2) NOT NULL DEFAULT 0,
    descuento_desde CHAR(7) NULL COMMENT 'Primer mes con descuento (AAAA-MM)',
    notas VARCHAR(500) NULL,
    fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (descuento_mensual >= 0)
);

CREATE TABLE alumnos (
    id_alumno INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(80) NOT NULL,
    apellido VARCHAR(80) NOT NULL,
    telefono VARCHAR(20),
    email VARCHAR(120),
    fecha_nacimiento DATE NULL,
    -- La hoja de inscripcion de un menor pide padre o tutor y domicilio.
    tutor_nombre VARCHAR(120) NULL,
    tutor_telefono VARCHAR(20) NULL,
    tutor_parentesco VARCHAR(40) NULL,
    direccion VARCHAR(200) NULL,
    notas TEXT NULL,
    -- Deuda que ya traía antes de entrar al sistema ("Deben $800" en la hoja
    -- de pagos). Se cobra antes que cualquier mes, y lo abonado a ella se
    -- registra en pagos con concepto 'adeudo_anterior'.
    adeudo_anterior DECIMAL(10,2) NOT NULL DEFAULT 0,
    adeudo_anterior_nota VARCHAR(200) NULL,
    fecha_registro DATE NOT NULL DEFAULT (CURRENT_DATE),
    activo TINYINT(1) NOT NULL DEFAULT 1,
    id_familia INT NULL,
    -- Si pide factura y con qué datos. Cada mes se marca en facturas.
    requiere_factura TINYINT(1) NOT NULL DEFAULT 0,
    rfc VARCHAR(13) NULL,
    razon_social VARCHAR(200) NULL,
    correo_factura VARCHAR(120) NULL,
    datos_fiscales VARCHAR(300) NULL COMMENT 'Regimen, codigo postal, uso de CFDI',
    CONSTRAINT fk_alumno_familia FOREIGN KEY (id_familia)
        REFERENCES familias(id_familia) ON DELETE SET NULL,
    INDEX idx_alumno_nombre (apellido, nombre)
);

-- Una fila por alumno y mes: si ya se le hizo la factura de ese mes o no.
CREATE TABLE facturas (
    id_factura INT AUTO_INCREMENT PRIMARY KEY,
    id_alumno INT NOT NULL,
    periodo CHAR(7) NOT NULL,
    hecha TINYINT(1) NOT NULL DEFAULT 1,
    folio VARCHAR(40) NULL,
    usuario VARCHAR(100) NULL,
    fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (id_alumno) REFERENCES alumnos(id_alumno),
    UNIQUE KEY uq_factura_alumno_mes (id_alumno, periodo)
);

CREATE TABLE maestros (
    id_maestro INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(80) NOT NULL,
    apellido VARCHAR(80) NOT NULL,
    telefono VARCHAR(20),
    email VARCHAR(120),
    tarifa_por_alumno DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Lo que se le paga por cada alumno inscrito',
    tarifa_por_hora DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Lo que se le paga por hora de clase impartida',
    modo_pago ENUM('por_alumno','por_hora') NOT NULL DEFAULT 'por_alumno'
        COMMENT 'Como se calcula su nomina. No depende de lo que pague el alumno.',
    bono_puntualidad DECIMAL(10,2) NOT NULL DEFAULT 0
        COMMENT 'Bono mensual de puntualidad y asistencia; 0 = no lleva. Se pierde si el mes tiene faltas.',
    lleva_nomina TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '0 = no se le hace nomina (la duena). Sus clases si cuentan como ingreso.',
    activo TINYINT(1) NOT NULL DEFAULT 1,
    INDEX idx_maestro_nombre (apellido, nombre)
);

CREATE TABLE maestro_instrumento (
    id_maestro INT NOT NULL,
    id_instrumento INT NOT NULL,
    PRIMARY KEY (id_maestro, id_instrumento),
    FOREIGN KEY (id_maestro) REFERENCES maestros(id_maestro) ON DELETE CASCADE,
    FOREIGN KEY (id_instrumento) REFERENCES instrumentos(id_instrumento) ON DELETE CASCADE
);

-- ---------------------------------------------------------------------
-- INSCRIPCIONES: tabla central. Nunca se borra fisicamente (es historial).
--
-- estado = 'pendiente' -> alumno que ya esta con un maestro y paga, pero
-- todavia no tiene dia, hora ni salon asignados (es el caso de todo lo que
-- se importa desde el Excel de nomina). No ocupa horario hasta que se
-- completa y pasa a 'activa'.
-- ---------------------------------------------------------------------

CREATE TABLE inscripciones (
    id_inscripcion INT AUTO_INCREMENT PRIMARY KEY,
    id_alumno INT NOT NULL,
    id_maestro INT NOT NULL,
    id_instrumento INT NULL,
    id_salon INT NULL,
    -- presencial: con salon. online: sin salon, pero ocupa al maestro.
    -- "Pendiente de asignar" no es una modalidad: es estado = 'pendiente'.
    modalidad ENUM('presencial','online') NOT NULL DEFAULT 'presencial',
    dia_semana ENUM('Lunes','Martes','Miercoles','Jueves','Viernes','Sabado') NULL,
    hora_inicio TIME NULL,
    hora_fin TIME NULL,
    duracion_min INT NOT NULL DEFAULT 60 COMMENT 'Duracion de la clase: 30, 60, 90...',
    costo_mensual DECIMAL(10,2) NOT NULL COMMENT 'Lo que paga el alumno al mes. No es lo que cobra el maestro.',
    fecha_alta DATE NOT NULL DEFAULT (CURRENT_DATE) COMMENT 'Dia en que empieza a tomar clase',
    fecha_baja DATE NULL,
    estado ENUM('activa','pendiente','baja') NOT NULL DEFAULT 'activa',
    origen ENUM('captura','importacion') NOT NULL DEFAULT 'captura',
    -- Clase compartida: dos alumnos que entran juntos con el mismo maestro en
    -- el mismo salon y hora. El que apunta aqui no ocupa horario por su cuenta;
    -- comparte el de la inscripcion titular. Cada uno paga y cuenta en nomina
    -- por separado, que es como opera la escuela en esos casos.
    comparte_con INT NULL,
    FOREIGN KEY (id_alumno) REFERENCES alumnos(id_alumno),
    FOREIGN KEY (id_maestro) REFERENCES maestros(id_maestro),
    FOREIGN KEY (id_instrumento) REFERENCES instrumentos(id_instrumento),
    FOREIGN KEY (id_salon) REFERENCES salones(id_salon),
    FOREIGN KEY (comparte_con) REFERENCES inscripciones(id_inscripcion) ON DELETE SET NULL,
    INDEX idx_insc_estado (estado),
    INDEX idx_insc_comparte (comparte_con),
    CONSTRAINT chk_modalidad_salon CHECK (
           (modalidad = 'online'     AND id_salon IS NULL)
        OR (modalidad = 'presencial' AND (id_salon IS NOT NULL OR estado <> 'activa')))
);

-- ---------------------------------------------------------------------
-- HORARIO_OCUPADO: estado ACTUAL de ocupacion de salones y maestros.
-- Las UNIQUE KEY impiden empalmar dos clases en el mismo salon, o al mismo
-- maestro, en el mismo dia y hora.
-- ---------------------------------------------------------------------

CREATE TABLE horario_ocupado (
    id_ocupado INT AUTO_INCREMENT PRIMARY KEY,
    id_inscripcion INT NOT NULL UNIQUE,
    id_salon INT NULL COMMENT 'NULL = clase en linea: ocupa al maestro, no un salon',
    id_maestro INT NOT NULL,
    dia_semana ENUM('Lunes','Martes','Miercoles','Jueves','Viernes','Sabado') NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    -- 1 = clase del taller (materia en grupo: Dibujo y Pintura). En el taller
    -- cada alumno entra y sale a su hora, y dos pueden empezar juntos.
    en_taller TINYINT(1) NOT NULL DEFAULT 0,
    -- Igual a hora_inicio, salvo en el taller (NULL): asi las claves de abajo
    -- no cuentan las clases del taller. Es una columna generada, no se escribe.
    inicio_exclusivo TIME AS (IF(en_taller = 1, NULL, hora_inicio)) STORED,
    FOREIGN KEY (id_inscripcion) REFERENCES inscripciones(id_inscripcion) ON DELETE CASCADE,
    FOREIGN KEY (id_salon) REFERENCES salones(id_salon),
    FOREIGN KEY (id_maestro) REFERENCES maestros(id_maestro),
    -- Estas claves atrapan el choque exacto de las clases que no son del taller.
    -- Los empalmes parciales (10:00-11:00 contra 10:30-11:30) los valida
    -- HorarioModel antes de insertar, porque una clave unica no puede expresar
    -- un traslape.
    UNIQUE KEY uq_salon_horario (id_salon, dia_semana, inicio_exclusivo),
    UNIQUE KEY uq_maestro_horario (id_maestro, dia_semana, inicio_exclusivo),
    INDEX idx_ocupado_salon (id_salon, dia_semana, hora_inicio, hora_fin),
    INDEX idx_ocupado_maestro (id_maestro, dia_semana, hora_inicio, hora_fin)
);

-- ---------------------------------------------------------------------
-- NOMINA_DETALLE: historica. Una fila por alumno, maestro y periodo.
-- El mismo alumno genera pago todos los meses, por eso la clave unica
-- es (inscripcion + periodo) y no solo la inscripcion.
-- ---------------------------------------------------------------------

CREATE TABLE nomina_detalle (
    id_detalle INT AUTO_INCREMENT PRIMARY KEY,
    id_maestro INT NOT NULL,
    id_inscripcion INT NOT NULL,
    id_alumno INT NOT NULL,
    periodo CHAR(7) NOT NULL COMMENT 'YYYY-MM, ej. 2026-02',
    monto DECIMAL(10,2) NOT NULL,
    -- Mes a medias: NULL = mes completo. Con numero, solo esas clases, del
    -- tramo desde..hasta (entro a medio mes, o cambio de maestro a medio mes).
    -- Un renglon con "hasta" es el tramo de un maestro que dejo la clase.
    clases TINYINT NULL,
    desde DATE NULL,
    hasta DATE NULL,
    pagado TINYINT(1) NOT NULL DEFAULT 0,
    -- Un renglon ajustado a mano no lo vuelve a calcular nadie.
    ajuste_manual TINYINT(1) NOT NULL DEFAULT 0,
    fecha_pago DATE NULL,
    fecha_registro DATE NOT NULL DEFAULT (CURRENT_DATE),
    FOREIGN KEY (id_maestro) REFERENCES maestros(id_maestro),
    FOREIGN KEY (id_inscripcion) REFERENCES inscripciones(id_inscripcion) ON DELETE CASCADE,
    FOREIGN KEY (id_alumno) REFERENCES alumnos(id_alumno),
    -- Una clase puede tener dos maestros en un mes si cambio a medio mes.
    UNIQUE KEY uq_nomina_clase_maestro (id_inscripcion, periodo, id_maestro),
    INDEX idx_nomina_periodo (periodo, id_maestro)
);

-- ---------------------------------------------------------------------
-- CARGOS_MENSUALES: lo que un alumno debio por CADA CLASE en un mes.
--
-- Sin esta tabla, el adeudo de cada mes se deducia de las clases VIVAS de
-- hoy: dar de baja a un alumno le borraba lo que debia, y subirle el precio
-- a una materia le recobraba el pasado. El cargo se congela cuando el mes
-- termina y cuando el alumno se da de baja. Los meses sin renglon aqui se
-- siguen calculando en vivo, que es lo que permite depurar el historial
-- viejo sin arrastrar adeudos fantasma.
--
-- Es el espejo de nomina_detalle: una fila por clase y mes, con las clases
-- del tramo. Asi un alumno que deja UNA de sus clases a medio mes paga esa
-- clase por lo que la tomo, igual que al maestro se le paga por lo que dio.
-- ---------------------------------------------------------------------

CREATE TABLE cargos_mensuales (
    id_cargo INT AUTO_INCREMENT PRIMARY KEY,
    id_alumno INT NOT NULL,
    -- La clase que originó el cargo. Es lo que permite explicar el importe y
    -- cuadrarlo contra la nomina del maestro de esa misma clase.
    id_inscripcion INT NULL,
    periodo CHAR(7) NOT NULL COMMENT 'YYYY-MM',
    monto DECIMAL(10,2) NOT NULL COMMENT 'Lo que debio pagar por esta clase ese mes',
    -- Con que se calculo: precio del mes, clases tomadas y de cuantas.
    precio_mensual DECIMAL(10,2) NOT NULL DEFAULT 0,
    clases TINYINT NULL COMMENT 'NULL = mes completo',
    clases_mes TINYINT NOT NULL DEFAULT 4,
    desde DATE NULL,
    hasta DATE NULL,
    motivo VARCHAR(60) NULL COMMENT 'cierre de mes, baja, depuracion...',
    fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Sin CASCADE a proposito: borrar un alumno o una clase no puede
    -- desaparecer una obligacion. La fusion mueve los cargos antes de borrar.
    FOREIGN KEY (id_alumno) REFERENCES alumnos(id_alumno),
    FOREIGN KEY (id_inscripcion) REFERENCES inscripciones(id_inscripcion) ON DELETE SET NULL,
    UNIQUE KEY uq_cargo_clase_periodo (id_inscripcion, periodo),
    INDEX idx_cargo_alumno (id_alumno, periodo),
    INDEX idx_cargo_periodo (periodo)
);

-- ---------------------------------------------------------------------
-- BORRADORES_ALUMNOS: una alta que se dejo a medias. Es una intencion, no un
-- alumno: nunca aparece como alumno activo, inscripcion, nomina ni deuda.
-- ---------------------------------------------------------------------

CREATE TABLE borradores_alumnos (
    id_borrador INT AUTO_INCREMENT PRIMARY KEY,
    -- Si el alumno ya existía y solo falta la clase. Un borrador NUNCA crea alumno.
    id_alumno INT NULL,
    nombre_mostrar VARCHAR(160) NOT NULL,
    datos_alumno LONGTEXT NULL CHECK (datos_alumno IS NULL OR JSON_VALID(datos_alumno)),
    datos_clase  LONGTEXT NULL CHECK (datos_clase IS NULL OR JSON_VALID(datos_clase)),
    motivo VARCHAR(200) NULL,
    estado ENUM('abierto','completado','descartado') NOT NULL DEFAULT 'abierto',
    id_alumno_resultante INT NULL,
    id_inscripcion_resultante INT NULL,
    creado_por VARCHAR(100) NULL,
    actualizado_por VARCHAR(100) NULL,
    fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fecha_cierre DATETIME NULL,
    FOREIGN KEY (id_alumno) REFERENCES alumnos(id_alumno),
    FOREIGN KEY (id_alumno_resultante) REFERENCES alumnos(id_alumno),
    FOREIGN KEY (id_inscripcion_resultante) REFERENCES inscripciones(id_inscripcion),
    INDEX idx_borrador_estado (estado, fecha_actualizacion)
);

-- ---------------------------------------------------------------------
-- LISTA_ESPERA: quien quiere una clase que esta llena. No es inscripcion.
-- Al liberarse un horario compatible solo se AVISA; nunca se inscribe solo.
-- ---------------------------------------------------------------------

CREATE TABLE lista_espera (
    id_lista_espera INT AUTO_INCREMENT PRIMARY KEY,
    -- Una solicitud NO es inscripción: no ocupa horario, no genera nómina ni cargos.
    id_alumno INT NULL,
    nombre VARCHAR(160) NULL,
    telefono VARCHAR(20) NULL,
    correo VARCHAR(120) NULL,
    id_instrumento INT NOT NULL,
    id_maestro INT NULL COMMENT 'NULL = cualquier maestro',
    id_salon INT NULL COMMENT 'NULL = cualquier salon',
    dia_semana ENUM('Lunes','Martes','Miercoles','Jueves','Viernes','Sabado') NULL,
    hora_inicio TIME NULL,
    duracion_min INT NOT NULL DEFAULT 60,
    modalidad ENUM('presencial','online','cualquiera') NOT NULL DEFAULT 'presencial',
    prioridad INT NOT NULL DEFAULT 0,
    estado ENUM('pendiente','contactado','inscrito','cancelado') NOT NULL DEFAULT 'pendiente',
    notas VARCHAR(500) NULL,
    fecha_solicitud DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_contacto DATETIME NULL,
    id_inscripcion_resultante INT NULL,
    registrado_por VARCHAR(100) NULL,
    CHECK (id_alumno IS NOT NULL OR (nombre IS NOT NULL AND telefono IS NOT NULL)),
    FOREIGN KEY (id_alumno) REFERENCES alumnos(id_alumno),
    FOREIGN KEY (id_instrumento) REFERENCES instrumentos(id_instrumento),
    FOREIGN KEY (id_maestro) REFERENCES maestros(id_maestro),
    FOREIGN KEY (id_salon) REFERENCES salones(id_salon),
    FOREIGN KEY (id_inscripcion_resultante) REFERENCES inscripciones(id_inscripcion),
    INDEX idx_espera_busqueda (estado, id_instrumento, dia_semana, hora_inicio)
);

-- INSCRIPCIONES_CAMBIOS: bitacora de toda edicion o baja de una clase, con
-- el valor anterior, el nuevo, quien, cuando, por que y con que evidencia.
CREATE TABLE inscripciones_cambios (
    id_cambio INT AUTO_INCREMENT PRIMARY KEY,
    id_inscripcion INT NOT NULL,
    operacion VARCHAR(24) NULL COMMENT 'Los cambios de una misma operacion comparten folio',
    campo VARCHAR(40) NOT NULL,
    valor_anterior VARCHAR(200) NULL,
    valor_nuevo VARCHAR(200) NULL,
    motivo VARCHAR(255) NULL,
    evidencia VARCHAR(255) NULL,
    usuario VARCHAR(100) NULL,
    fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_inscripcion) REFERENCES inscripciones(id_inscripcion),
    INDEX idx_cambio_inscripcion (id_inscripcion, fecha)
);

-- PRECIOS_CLASE: historial de precio, duracion y dia de cada clase, con fecha.
-- Cuando una clase cambia de duracion (y de precio) o de dia a mitad de mes, el
-- mes se cobra --y se le paga al maestro-- por tramos: las clases de antes del
-- cambio como antes (con el dia de antes), las de desde el cambio como ahora.
-- inscripciones.costo_mensual, duracion_min y dia_semana siguen siendo lo
-- vigente hoy; una clase sin filas se calcula como siempre. La primera fila de
-- una clase (1000-01-01) guarda lo de antes.
CREATE TABLE precios_clase (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_inscripcion INT NOT NULL,
    vigente_desde DATE NOT NULL COMMENT 'Desde este dia la clase cuesta, dura y se da asi (1000-01-01 = lo de antes del primer cambio)',
    precio DECIMAL(10,2) NOT NULL,
    duracion_min SMALLINT NULL,
    dia_semana ENUM('Lunes','Martes','Miercoles','Jueves','Viernes','Sabado') NULL COMMENT 'Dia en que se daba la clase en ese tramo',
    usuario VARCHAR(100) NULL,
    fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_precio_clase_fecha (id_inscripcion, vigente_desde),
    CONSTRAINT fk_precio_clase_inscripcion FOREIGN KEY (id_inscripcion)
        REFERENCES inscripciones(id_inscripcion) ON DELETE CASCADE
);

-- SEMANAS_POR_MES_HISTORIAL: un cambio de "clases al mes" rige desde el mes
-- siguiente (o desde este mes, si se pide) y no reescribe el pasado. Cada
-- cambio guarda el valor nuevo y el que habia antes.
CREATE TABLE semanas_por_mes_historial (
    vigente_desde CHAR(7) NOT NULL PRIMARY KEY COMMENT 'Mes AAAA-MM desde el que rige',
    semanas TINYINT UNSIGNED NOT NULL,
    semanas_anterior TINYINT UNSIGNED NOT NULL,
    usuario VARCHAR(100) NULL,
    fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ---------------------------------------------------------------------
-- NOMINA_AJUSTES: lo que mueve la nomina fuera de lo normal.
--   falta     -> el maestro no vino: se le descuenta esa clase (monto negativo)
--   suplencia -> otro maestro la cubrio: se le suma a el (monto positivo)
-- Una suplencia apunta a la falta que cubre, para poder anularlas juntas.
-- ---------------------------------------------------------------------

CREATE TABLE nomina_ajustes (
    id_ajuste INT AUTO_INCREMENT PRIMARY KEY,
    id_maestro INT NOT NULL,
    periodo CHAR(7) NOT NULL,
    fecha DATE NOT NULL,
    -- falta/suplencia: el maestro no vino y alguien cubrio.
    -- bono:    puntualidad y asistencia; se pone solo cada mes y se pierde
    --          si ese mes tiene una falta registrada.
    -- muestra: clase muestra que impartio, se le paga como una clase suya.
    tipo ENUM('falta','suplencia','otro','bono','muestra') NOT NULL,
    id_inscripcion INT NULL,
    minutos INT NOT NULL DEFAULT 0,
    monto DECIMAL(10,2) NOT NULL COMMENT 'Negativo descuenta, positivo suma',
    motivo VARCHAR(200) NULL,
    cubre_falta INT NULL COMMENT 'En una suplencia: la falta que cubre',
    pagado TINYINT(1) NOT NULL DEFAULT 0,
    registrado_por VARCHAR(100) NULL,
    fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_maestro) REFERENCES maestros(id_maestro),
    FOREIGN KEY (id_inscripcion) REFERENCES inscripciones(id_inscripcion) ON DELETE SET NULL,
    FOREIGN KEY (cubre_falta) REFERENCES nomina_ajustes(id_ajuste) ON DELETE CASCADE,
    -- La misma clase no puede descontarse dos veces el mismo dia.
    UNIQUE KEY uq_falta_clase_dia (tipo, id_inscripcion, fecha),
    INDEX idx_ajuste_periodo (periodo, id_maestro)
);

-- ---------------------------------------------------------------------
-- PAGOS: historial financiero del alumno. Nunca se borra.
-- ---------------------------------------------------------------------

-- id_alumno puede ir vacio: una clase muestra la paga alguien que todavia no
-- es alumno de la escuela. Para esos casos se guarda su nombre y telefono.
CREATE TABLE pagos (
    id_pago INT AUTO_INCREMENT PRIMARY KEY,
    id_alumno INT NULL,
    id_inscripcion INT NULL,
    concepto ENUM('colegiatura','inscripcion','clase_muestra','material','otro','adeudo_anterior')
        NOT NULL DEFAULT 'colegiatura',
    nombre_prospecto VARCHAR(120) NULL COMMENT 'Solo para clases muestra de quien no esta inscrito',
    telefono_prospecto VARCHAR(20) NULL,
    duracion_min INT NULL COMMENT 'Duracion de la clase muestra',
    id_maestro INT NULL COMMENT 'Quien impartio la clase muestra: se le paga en su nomina',
    monto DECIMAL(10,2) NOT NULL,
    fecha_pago DATE NOT NULL DEFAULT (CURRENT_DATE),
    recargo DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Cargo por pago fuera de fecha',
    metodo ENUM('efectivo','tarjeta','transferencia','por_confirmar') NOT NULL DEFAULT 'efectivo'
        COMMENT 'por_confirmar: se sabe que pago (viene del Excel) pero no como',
    -- 0 = se sabe que pago ese mes, pero no cuanto: la hoja de pagos solo trae
    -- el mes. El mes cuenta como pagado; el importe es una sugerencia hasta
    -- que alguien lo confirme.
    monto_confirmado TINYINT(1) NOT NULL DEFAULT 1,
    periodo VARCHAR(20) COMMENT 'Ej: 2026-09',
    origen ENUM('captura','importacion','historico') NOT NULL DEFAULT 'captura',
    -- Un solo cobro puede repartirse entre varios meses (abona lo atrasado y
    -- adelanta el siguiente). Todos sus renglones comparten este folio.
    operacion VARCHAR(24) NULL,
    -- Un pago mal capturado no se borra: se anula con un contramovimiento que
    -- apunta al original (uno solo por pago) y el original queda marcado.
    anula_a INT NULL,
    anulado TINYINT(1) NOT NULL DEFAULT 0,
    registrado_por VARCHAR(100) NULL,
    FOREIGN KEY (id_alumno) REFERENCES alumnos(id_alumno),
    FOREIGN KEY (id_inscripcion) REFERENCES inscripciones(id_inscripcion) ON DELETE SET NULL,
    FOREIGN KEY (id_maestro) REFERENCES maestros(id_maestro),
    UNIQUE KEY uq_pago_anula (anula_a),
    CONSTRAINT fk_pago_anula FOREIGN KEY (anula_a) REFERENCES pagos(id_pago),
    INDEX idx_pago_periodo (periodo),
    INDEX idx_pago_alumno (id_alumno, periodo),
    INDEX idx_pago_operacion (operacion),
    INDEX idx_pago_concepto (concepto, fecha_pago)
);

-- ---------------------------------------------------------------------
-- PAGOS_CAMBIOS: bitacora de toda correccion a un pago. Un pago no se
-- sobreescribe en silencio: cada campo que cambia deja su valor anterior,
-- el nuevo, quien, cuando y por que.
-- ---------------------------------------------------------------------

CREATE TABLE pagos_cambios (
    id_cambio INT AUTO_INCREMENT PRIMARY KEY,
    id_pago INT NOT NULL,
    campo VARCHAR(30) NOT NULL,
    valor_anterior VARCHAR(60) NULL,
    valor_nuevo VARCHAR(60) NULL,
    motivo VARCHAR(200) NULL,
    usuario VARCHAR(100) NULL,
    fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_pago) REFERENCES pagos(id_pago),
    INDEX idx_cambio_pago (id_pago)
);

-- ---------------------------------------------------------------------
-- BITACORA_FINANCIERA: todo movimiento de dinero que no es un cobro
-- normal (anulaciones, ajustes de nomina, meses confirmados a mano):
-- que valia antes, que vale ahora, quien, cuando y por que.
-- ---------------------------------------------------------------------

CREATE TABLE bitacora_financiera (
    id_bitacora INT AUTO_INCREMENT PRIMARY KEY,
    fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    usuario VARCHAR(100) NULL,
    operacion VARCHAR(60) NOT NULL,
    tabla VARCHAR(40) NOT NULL,
    id_registro INT NULL,
    campo VARCHAR(60) NOT NULL,
    valor_anterior VARCHAR(255) NULL,
    valor_nuevo VARCHAR(255) NULL,
    motivo VARCHAR(255) NULL,
    folio VARCHAR(24) NULL,
    origen VARCHAR(20) NOT NULL DEFAULT 'pantalla',
    INDEX idx_bitacora_registro (tabla, id_registro),
    INDEX idx_bitacora_fecha (fecha)
);

-- ---------------------------------------------------------------------
-- OPERACIONES_COBRO: cada cobro lleva una clave unica. Si llega dos
-- veces (doble clic, dos computadoras, la red que reintenta), el
-- segundo no se registra.
-- ---------------------------------------------------------------------

CREATE TABLE operaciones_cobro (
    clave VARCHAR(64) NOT NULL PRIMARY KEY,
    folio VARCHAR(24) NOT NULL,
    usuario VARCHAR(100) NULL,
    fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ---------------------------------------------------------------------
-- USUARIOS: el primer administrador se crea desde la Terminal con
-- backend/herramientas/crear_admin.php (no hay contrasena de fabrica)
-- ---------------------------------------------------------------------

CREATE TABLE usuarios (
    id_usuario INT AUTO_INCREMENT PRIMARY KEY,
    usuario VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    -- admin: todo · recepcion: el trabajo del día · consulta: solo ver
    rol ENUM('admin','recepcion','consulta') NOT NULL DEFAULT 'recepcion',
    activo TINYINT(1) NOT NULL DEFAULT 1,
    -- Sube al cambiar o restablecer la contraseña: las sesiones abiertas con la anterior dejan de valer.
    version_sesion INT NOT NULL DEFAULT 1,
    fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Intentos fallidos para entrar, por usuario desde un equipo y por equipo.
CREATE TABLE intentos_acceso (
    clave VARCHAR(190) NOT NULL PRIMARY KEY,
    fallos INT NOT NULL DEFAULT 0,
    desde DATETIME NOT NULL,
    bloqueado_hasta DATETIME NULL,
    INDEX idx_intentos_desde (desde)
);

CREATE TABLE bitacora_notas (
    id_nota INT AUTO_INCREMENT PRIMARY KEY,
    autor VARCHAR(100) NOT NULL,
    mensaje TEXT NOT NULL,
    fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resuelto TINYINT(1) NOT NULL DEFAULT 0,
    fecha_resuelto DATETIME NULL
);

-- Parametros de cobro que la escuela cambia desde Ajustes.
CREATE TABLE configuracion (
    clave VARCHAR(50) PRIMARY KEY,
    valor VARCHAR(255) NOT NULL,
    descripcion VARCHAR(200) NULL
);

-- Bitacora de archivos importados, para saber que se cargo y cuando.
CREATE TABLE importaciones (
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

-- =====================================================================
-- CATALOGOS DE EJEMPLO (se pueden cambiar desde Ajustes)
-- =====================================================================

-- Musica: 900 al mes por una hora semanal. Dibujo y pintura: 700.
INSERT INTO instrumentos (nombre, costo_mensual) VALUES
('Piano', 900),('Guitarra', 900),('Violin', 900),('Bateria', 900),('Canto', 900),
('Saxofon', 900),('Flauta', 900),('Ukulele', 900),('Bajo', 900),('Teclado', 900),
('Solfeo', 900),('Pintura', 700),('Dibujo', 700);
UPDATE instrumentos SET es_grupal = 1 WHERE nombre IN ('Pintura','Dibujo');
INSERT INTO grupos_materia (nombre) VALUES ('Dibujo y Pintura');
UPDATE instrumentos SET id_grupo_materia = LAST_INSERT_ID() WHERE nombre IN ('Pintura','Dibujo');

-- Recargo de 50 para quien paga despues del dia 10 del mes.
INSERT INTO configuracion (clave, valor, descripcion) VALUES
('recargo_monto', '50', 'Cargo por pagar despues de la fecha limite'),
('recargo_dia_limite', '10', 'Dia del mes hasta el que se puede pagar sin recargo'),
('costo_inscripcion', '0', 'Cuota unica que se cobra al inscribirse por primera vez'),
('muestra_hora', '250', 'Costo de una clase muestra de una hora'),
('muestra_media_hora', '125', 'Costo de una clase muestra de media hora'),
('semanas_por_mes', '4', 'Clases al mes que se cuentan para la nomina por hora'),
('cargos_congelados_desde', '', 'Primer mes (YYYY-MM) cuyo cargo se congela al cerrar'),
('cargos_cerrados_hasta', '', 'Ultimo mes cuyo cargo ya quedo congelado'),
('cupo_online', '1', 'Alumnos por clase en linea: 1 = individual; nunca mas de 2');

-- 6 salones + la cocina (que a veces se ocupa como salon) + el patio (pintura y dibujo)
INSERT INTO salones (nombre, capacidad, tipo) VALUES
('Salon 1', 2, 'salon'),
('Salon 2', 2, 'salon'),
('Salon 3', 2, 'salon'),
('Salon 4', 4, 'salon'),
('Salon 5', 4, 'salon'),
('Salon 6', 6, 'salon'),
('Cocina',  4, 'compartido'),
('Patio',  12, 'exterior');

-- Manana de 9 a 14 todos los dias. Por la tarde, lunes, miercoles y viernes
-- cierran a las 20:30; martes y jueves a las 20:00; el sabado no hay tarde.
INSERT INTO horario_escuela (dia_semana, abre_1, cierra_1, abre_2, cierra_2) VALUES
('Lunes',     '09:00:00','14:00:00','16:00:00','20:30:00'),
('Martes',    '09:00:00','14:00:00','16:00:00','20:00:00'),
('Miercoles', '09:00:00','14:00:00','16:00:00','20:30:00'),
('Jueves',    '09:00:00','14:00:00','16:00:00','20:00:00'),
('Viernes',   '09:00:00','14:00:00','16:00:00','20:30:00'),
('Sabado',    '09:00:00','14:00:00', NULL,      NULL);

-- Bloques de media hora: hay clases de 30 minutos, de una hora y mas largas.
-- La hora de fin de cada bloque es solo referencia; la duracion real de una
-- clase la define su inscripcion.
INSERT INTO bloques_horario (hora_inicio, hora_fin, turno) VALUES
('09:00:00','09:30:00','matutino'),('09:30:00','10:00:00','matutino'),
('10:00:00','10:30:00','matutino'),('10:30:00','11:00:00','matutino'),
('11:00:00','11:30:00','matutino'),('11:30:00','12:00:00','matutino'),
('12:00:00','12:30:00','matutino'),('12:30:00','13:00:00','matutino'),
('13:00:00','13:30:00','matutino'),('13:30:00','14:00:00','matutino'),
('16:00:00','16:30:00','vespertino'),('16:30:00','17:00:00','vespertino'),
('17:00:00','17:30:00','vespertino'),('17:30:00','18:00:00','vespertino'),
('18:00:00','18:30:00','vespertino'),('18:30:00','19:00:00','vespertino'),
('19:00:00','19:30:00','vespertino'),('19:30:00','20:00:00','vespertino'),
('20:00:00','20:30:00','vespertino');

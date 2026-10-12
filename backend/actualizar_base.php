<?php
/**
 * Sistema Escuela — Actualizar la base de datos
 *
 * Revisa una por una las diferencias entre tu base y la versión 2 del esquema,
 * y aplica solo las que falten. Se puede correr las veces que haga falta: lo
 * que ya está hecho lo reporta como "ya estaba" y no lo vuelve a tocar.
 *
 * Existe porque phpMyAdmin detiene todo el archivo .sql en la primera
 * instrucción que falla, y eso deja la base a medias.
 *
 * Uso: entra al sistema como administrador y abre backend/actualizar_base.php en
 * el mismo navegador. La página muestra lo que falta sin tocar nada; los
 * cambios se aplican solo con el botón. Desde la Terminal:
 *   php actualizar_base.php      (revisa)
 *   php actualizar_base.php 1    (aplica)
 */


/**
 * Encuentra la carpeta backend del proyecto, sin importar dónde quedó este
 * archivo: junto a config/, en la raíz del proyecto, o un nivel más arriba.
 * Sin esto, moverlo de carpeta rompe el require con un error de include_path.
 */
function ubicarBackend(): ?string
{
    $candidatos = [
        __DIR__,
        __DIR__ . '/backend',
        dirname(__DIR__),
        dirname(__DIR__) . '/backend',
        dirname(__DIR__, 2) . '/backend',
        __DIR__ . '/escuela-musica/backend',
    ];
    foreach ($candidatos as $ruta) {
        if (is_readable($ruta . '/config/Database.php')) {
            return rtrim(str_replace('\\', '/', $ruta), '/');
        }
    }
    return null;
}

$backend = ubicarBackend();
if ($backend === null) {
    http_response_code(500);
    exit('No encontré la carpeta backend del proyecto. Mueve este archivo dentro de escuela-musica\\backend.');
}
require_once $backend . '/config/Database.php';
require_once $backend . '/config/Mantenimiento.php';

// Solo administración (o la Terminal). Aplicar requiere el botón: POST con el
// token de la sesión. Si existe backend/.candado, queda deshabilitada.
$aplicar = Mantenimiento::puerta();

$db = Database::conexion();
$esquema = $db->query("SELECT DATABASE()")->fetchColumn();

/* ------------------------------------------------------------ Inspección */

function hayTabla(PDO $db, string $esquema, string $tabla): bool
{
    $s = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES
                        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?");
    $s->execute([$esquema, $tabla]);
    return (int) $s->fetchColumn() > 0;
}

function hayColumna(PDO $db, string $esquema, string $tabla, string $columna): bool
{
    $s = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $s->execute([$esquema, $tabla, $columna]);
    return (int) $s->fetchColumn() > 0;
}

function hayIndice(PDO $db, string $esquema, string $tabla, string $indice): bool
{
    $s = $db->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS
                        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $s->execute([$esquema, $tabla, $indice]);
    return (int) $s->fetchColumn() > 0;
}

/** Índices únicos de una sola columna que apunten a esa columna */
function indicesUnicosDe(PDO $db, string $esquema, string $tabla, string $columna): array
{
    // Se agrupa sobre TODAS las columnas del indice: asi un indice de dos
    // columnas (como uq_inscripcion_periodo) no se confunde con uno de una.
    $s = $db->prepare(
        "SELECT INDEX_NAME FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
            AND NON_UNIQUE = 0 AND INDEX_NAME <> 'PRIMARY'
          GROUP BY INDEX_NAME
         HAVING COUNT(*) = 1 AND MAX(COLUMN_NAME) = ?"
    );
    $s->execute([$esquema, $tabla, $columna]);
    return $s->fetchAll(PDO::FETCH_COLUMN);
}

/** Las columnas de un índice, en su orden */
function columnasDeIndice(PDO $db, string $esquema, string $tabla, string $indice): array
{
    $s = $db->prepare("SELECT COLUMN_NAME FROM information_schema.STATISTICS
                        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?
                        ORDER BY SEQ_IN_INDEX");
    $s->execute([$esquema, $tabla, $indice]);
    return $s->fetchAll(PDO::FETCH_COLUMN);
}

/** Llaves foráneas que salen de esa columna */
function llavesForaneasDe(PDO $db, string $esquema, string $tabla, string $columna): array
{
    $s = $db->prepare(
        "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
            AND REFERENCED_TABLE_NAME IS NOT NULL"
    );
    $s->execute([$esquema, $tabla, $columna]);
    return $s->fetchAll(PDO::FETCH_COLUMN);
}

function tipoColumna(PDO $db, string $esquema, string $tabla, string $columna): string
{
    $s = $db->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $s->execute([$esquema, $tabla, $columna]);
    return (string) $s->fetchColumn();
}

function esNula(PDO $db, string $esquema, string $tabla, string $columna): bool
{
    $s = $db->prepare("SELECT IS_NULLABLE FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $s->execute([$esquema, $tabla, $columna]);
    return $s->fetchColumn() === 'YES';
}

/* -------------------------------------------------------------- Cambios */

$pasos = [];

$paso = function (string $titulo, callable $falta, $sql) use (&$pasos) {
    $pasos[] = ['titulo' => $titulo, 'falta' => $falta, 'sql' => is_array($sql) ? $sql : [$sql]];
};

$e = $esquema;

/* 1. Salones */
$paso('Columna salones.tipo',
    fn() => !hayColumna($GLOBALS['db'], $e, 'salones', 'tipo'),
    "ALTER TABLE salones ADD COLUMN tipo ENUM('salon','compartido','exterior') NOT NULL DEFAULT 'salon' AFTER capacidad");

$paso('Columna salones.activo',
    fn() => !hayColumna($GLOBALS['db'], $e, 'salones', 'activo'),
    "ALTER TABLE salones ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1");

$paso('Los 8 espacios de la escuela (6 salones, cocina y patio)',
    function () use ($e) {
        $n = (int) $GLOBALS['db']->query("SELECT COUNT(*) FROM salones WHERE nombre IN
            ('Salon 1','Salon 2','Salon 3','Salon 4','Salon 5','Salon 6','Cocina','Patio')")->fetchColumn();
        return $n < 8;
    },
    [
        "INSERT INTO salones (nombre, capacidad, tipo)
         SELECT * FROM (SELECT 'Salon 1' n, 2 c, 'salon' t) x
          WHERE NOT EXISTS (SELECT 1 FROM salones WHERE nombre = 'Salon 1')",
        "INSERT INTO salones (nombre, capacidad, tipo)
         SELECT * FROM (SELECT 'Salon 2' n, 2 c, 'salon' t) x
          WHERE NOT EXISTS (SELECT 1 FROM salones WHERE nombre = 'Salon 2')",
        "INSERT INTO salones (nombre, capacidad, tipo)
         SELECT * FROM (SELECT 'Salon 3' n, 2 c, 'salon' t) x
          WHERE NOT EXISTS (SELECT 1 FROM salones WHERE nombre = 'Salon 3')",
        "INSERT INTO salones (nombre, capacidad, tipo)
         SELECT * FROM (SELECT 'Salon 4' n, 4 c, 'salon' t) x
          WHERE NOT EXISTS (SELECT 1 FROM salones WHERE nombre = 'Salon 4')",
        "INSERT INTO salones (nombre, capacidad, tipo)
         SELECT * FROM (SELECT 'Salon 5' n, 4 c, 'salon' t) x
          WHERE NOT EXISTS (SELECT 1 FROM salones WHERE nombre = 'Salon 5')",
        "INSERT INTO salones (nombre, capacidad, tipo)
         SELECT * FROM (SELECT 'Salon 6' n, 6 c, 'salon' t) x
          WHERE NOT EXISTS (SELECT 1 FROM salones WHERE nombre = 'Salon 6')",
        "INSERT INTO salones (nombre, capacidad, tipo)
         SELECT * FROM (SELECT 'Cocina' n, 4 c, 'compartido' t) x
          WHERE NOT EXISTS (SELECT 1 FROM salones WHERE nombre = 'Cocina')",
        "INSERT INTO salones (nombre, capacidad, tipo)
         SELECT * FROM (SELECT 'Patio' n, 12 c, 'exterior' t) x
          WHERE NOT EXISTS (SELECT 1 FROM salones WHERE nombre = 'Patio')",
    ]);

/* 2. Horario de la escuela */
$paso('Tabla horario_escuela',
    fn() => !hayTabla($GLOBALS['db'], $e, 'horario_escuela'),
    "CREATE TABLE horario_escuela (
        dia_semana ENUM('Lunes','Martes','Miercoles','Jueves','Viernes','Sabado') PRIMARY KEY,
        abre_1 TIME NOT NULL, cierra_1 TIME NOT NULL,
        abre_2 TIME NULL, cierra_2 TIME NULL)");

$paso('Horario de apertura (L-V 9-14 y 16-20, sábado 9-14)',
    function () use ($e) {
        if (!hayTabla($GLOBALS['db'], $e, 'horario_escuela')) return true;
        return (int) $GLOBALS['db']->query("SELECT COUNT(*) FROM horario_escuela")->fetchColumn() < 6;
    },
    "INSERT INTO horario_escuela (dia_semana, abre_1, cierra_1, abre_2, cierra_2) VALUES
     ('Lunes','09:00:00','14:00:00','16:00:00','20:00:00'),
     ('Martes','09:00:00','14:00:00','16:00:00','20:00:00'),
     ('Miercoles','09:00:00','14:00:00','16:00:00','20:00:00'),
     ('Jueves','09:00:00','14:00:00','16:00:00','20:00:00'),
     ('Viernes','09:00:00','14:00:00','16:00:00','20:00:00'),
     ('Sabado','09:00:00','14:00:00',NULL,NULL)
     ON DUPLICATE KEY UPDATE abre_1 = VALUES(abre_1)");

/* 3. Bloques */
$paso('Columna bloques_horario.turno',
    fn() => !hayColumna($GLOBALS['db'], $e, 'bloques_horario', 'turno'),
    [
        "ALTER TABLE bloques_horario ADD COLUMN turno ENUM('matutino','vespertino') NOT NULL DEFAULT 'matutino'",
        "UPDATE bloques_horario SET turno = IF(hora_inicio >= '15:00:00','vespertino','matutino')",
    ]);

$paso('Bloque de 13:00 a 14:00',
    function () {
        return (int) $GLOBALS['db']->query(
            "SELECT COUNT(*) FROM bloques_horario WHERE hora_inicio = '13:00:00'")->fetchColumn() === 0;
    },
    "INSERT INTO bloques_horario (hora_inicio, hora_fin, turno) VALUES ('13:00:00','14:00:00','matutino')");

/* 4. Inscripciones */
$paso('Inscripciones sin horario asignado (columnas opcionales)',
    fn() => !esNula($GLOBALS['db'], $e, 'inscripciones', 'id_salon'),
    "ALTER TABLE inscripciones
        MODIFY id_instrumento INT NULL,
        MODIFY id_salon INT NULL,
        MODIFY dia_semana ENUM('Lunes','Martes','Miercoles','Jueves','Viernes','Sabado') NULL,
        MODIFY hora_inicio TIME NULL,
        MODIFY hora_fin TIME NULL");

$paso("Estado 'pendiente' en inscripciones",
    fn() => !str_contains(tipoColumna($GLOBALS['db'], $e, 'inscripciones', 'estado'), 'pendiente'),
    "ALTER TABLE inscripciones MODIFY estado ENUM('activa','pendiente','baja') NOT NULL DEFAULT 'activa'");

$paso('Columna inscripciones.origen',
    fn() => !hayColumna($GLOBALS['db'], $e, 'inscripciones', 'origen'),
    "ALTER TABLE inscripciones ADD COLUMN origen ENUM('captura','importacion') NOT NULL DEFAULT 'captura'");

$paso('Índice inscripciones.estado',
    fn() => !hayIndice($GLOBALS['db'], $e, 'inscripciones', 'idx_insc_estado'),
    "ALTER TABLE inscripciones ADD INDEX idx_insc_estado (estado)");

/* 5. Nómina histórica  <- esto es lo que estaba fallando */
/*
 * La unicidad sobre id_inscripcion es lo que impedia cobrar al mismo alumno mas
 * de un mes. No se puede soltar el indice directamente porque la llave foranea
 * se apoya en el: hay que soltar la llave, cambiar los indices y volver a ponerla.
 */
$paso('Permitir varios meses por inscripcion en la nomina',
    fn() => count(indicesUnicosDe($GLOBALS['db'], $e, 'nomina_detalle', 'id_inscripcion')) > 0,
    function () use ($e) {
        $db = $GLOBALS['db'];
        $sqls = [];

        $llaves = llavesForaneasDe($db, $e, 'nomina_detalle', 'id_inscripcion');
        foreach ($llaves as $llave) {
            $sqls[] = "ALTER TABLE nomina_detalle DROP FOREIGN KEY `" . str_replace('`', '``', $llave) . "`";
        }

        foreach (indicesUnicosDe($db, $e, 'nomina_detalle', 'id_inscripcion') as $indice) {
            $sqls[] = "ALTER TABLE nomina_detalle DROP INDEX `" . str_replace('`', '``', $indice) . "`";
        }

        // Un indice normal deja que la llave foranea vuelva a apoyarse en algo.
        if (!hayIndice($db, $e, 'nomina_detalle', 'idx_nomina_inscripcion')) {
            $sqls[] = "ALTER TABLE nomina_detalle ADD INDEX idx_nomina_inscripcion (id_inscripcion)";
        }

        if ($llaves) {
            $sqls[] = "ALTER TABLE nomina_detalle
                       ADD CONSTRAINT fk_nomina_inscripcion
                       FOREIGN KEY (id_inscripcion) REFERENCES inscripciones(id_inscripcion) ON DELETE CASCADE";
        }
        return $sqls;
    });

$paso('Columna nomina_detalle.periodo',
    fn() => !hayColumna($GLOBALS['db'], $e, 'nomina_detalle', 'periodo'),
    [
        "ALTER TABLE nomina_detalle ADD COLUMN periodo CHAR(7) NOT NULL DEFAULT '0000-00' AFTER id_alumno",
        "UPDATE nomina_detalle SET periodo = DATE_FORMAT(fecha_registro,'%Y-%m') WHERE periodo = '0000-00'",
        "ALTER TABLE nomina_detalle ALTER COLUMN periodo DROP DEFAULT",
    ]);

$paso('Columnas nomina_detalle.pagado y fecha_pago',
    fn() => !hayColumna($GLOBALS['db'], $e, 'nomina_detalle', 'pagado'),
    "ALTER TABLE nomina_detalle
        ADD COLUMN pagado TINYINT(1) NOT NULL DEFAULT 0,
        ADD COLUMN fecha_pago DATE NULL");

$paso('Clave única (inscripción + periodo)',
    fn() => hayColumna($GLOBALS['db'], $e, 'nomina_detalle', 'periodo')
         && !hayIndice($GLOBALS['db'], $e, 'nomina_detalle', 'uq_inscripcion_periodo')
         // Desde la 5.4 la clave lleva también al maestro (paso 16).
         && !hayIndice($GLOBALS['db'], $e, 'nomina_detalle', 'uq_nomina_clase_maestro'),
    "ALTER TABLE nomina_detalle ADD UNIQUE KEY uq_inscripcion_periodo (id_inscripcion, periodo)");

$paso('Índice de nómina por periodo',
    fn() => hayColumna($GLOBALS['db'], $e, 'nomina_detalle', 'periodo')
         && !hayIndice($GLOBALS['db'], $e, 'nomina_detalle', 'idx_nomina_periodo'),
    "ALTER TABLE nomina_detalle ADD INDEX idx_nomina_periodo (periodo, id_maestro)");

/* 6. Importaciones */
$paso('Tabla importaciones',
    fn() => !hayTabla($GLOBALS['db'], $e, 'importaciones'),
    "CREATE TABLE importaciones (
        id_importacion INT AUTO_INCREMENT PRIMARY KEY,
        tipo ENUM('nomina','horarios','pagos','alumnos') NOT NULL,
        archivo VARCHAR(255) NOT NULL,
        periodo CHAR(7) NULL,
        maestros_nuevos INT NOT NULL DEFAULT 0,
        alumnos_nuevos INT NOT NULL DEFAULT 0,
        filas_importadas INT NOT NULL DEFAULT 0,
        total DECIMAL(12,2) NOT NULL DEFAULT 0,
        usuario VARCHAR(100) NULL,
        fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");

/* 7. Materias que faltaban */
$paso('Materias adicionales (saxofón, pintura, dibujo…)',
    function () {
        return (int) $GLOBALS['db']->query(
            "SELECT COUNT(*) FROM instrumentos WHERE nombre IN ('Pintura','Dibujo','Solfeo')")->fetchColumn() < 3;
    },
    "INSERT IGNORE INTO instrumentos (nombre) VALUES
     ('Saxofon'),('Flauta'),('Ukulele'),('Bajo'),('Teclado'),('Pintura'),('Dibujo'),('Solfeo')");

/* 8. Índices de búsqueda */
$paso('Índice de búsqueda en alumnos',
    fn() => !hayIndice($GLOBALS['db'], $e, 'alumnos', 'idx_alumno_nombre'),
    "ALTER TABLE alumnos ADD INDEX idx_alumno_nombre (apellido, nombre)");

$paso('Índice de búsqueda en maestros',
    fn() => !hayIndice($GLOBALS['db'], $e, 'maestros', 'idx_maestro_nombre'),
    "ALTER TABLE maestros ADD INDEX idx_maestro_nombre (apellido, nombre)");

$paso('Índice de pagos por periodo',
    fn() => !hayIndice($GLOBALS['db'], $e, 'pagos', 'idx_pago_periodo'),
    "ALTER TABLE pagos ADD INDEX idx_pago_periodo (periodo)");


/* 9. Precios y recargos (septiembre 2026) */
$paso('Columna instrumentos.costo_mensual',
    fn() => !hayColumna($GLOBALS['db'], $e, 'instrumentos', 'costo_mensual'),
    "ALTER TABLE instrumentos ADD COLUMN costo_mensual DECIMAL(10,2) NOT NULL DEFAULT 0");

$paso('Precios de la escuela (900 musica, 700 dibujo y pintura)',
    function () use ($e) {
        if (!hayColumna($GLOBALS['db'], $e, 'instrumentos', 'costo_mensual')) return true;
        return (int) $GLOBALS['db']->query(
            "SELECT COUNT(*) FROM instrumentos WHERE costo_mensual > 0")->fetchColumn() === 0;
    },
    [
        "UPDATE instrumentos SET costo_mensual = 700
          WHERE costo_mensual = 0 AND nombre IN ('Pintura','Dibujo')",
        "UPDATE instrumentos SET costo_mensual = 900 WHERE costo_mensual = 0",
    ]);

$paso('Columna pagos.recargo',
    fn() => !hayColumna($GLOBALS['db'], $e, 'pagos', 'recargo'),
    "ALTER TABLE pagos ADD COLUMN recargo DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER monto");

$paso('Tabla configuracion',
    fn() => !hayTabla($GLOBALS['db'], $e, 'configuracion'),
    "CREATE TABLE configuracion (
        clave VARCHAR(50) PRIMARY KEY,
        valor VARCHAR(255) NOT NULL,
        descripcion VARCHAR(200) NULL)");

$paso('Recargo de 50 despues del dia 10',
    function () use ($e) {
        if (!hayTabla($GLOBALS['db'], $e, 'configuracion')) return true;
        return (int) $GLOBALS['db']->query(
            "SELECT COUNT(*) FROM configuracion WHERE clave = 'recargo_monto'")->fetchColumn() === 0;
    },
    "INSERT INTO configuracion (clave, valor, descripcion) VALUES
     ('recargo_monto', '50', 'Cargo por pagar despues de la fecha limite'),
     ('recargo_dia_limite', '10', 'Dia del mes hasta el que se puede pagar sin recargo')
     ON DUPLICATE KEY UPDATE valor = VALUES(valor)");


/* 10. Duracion de clases, nomina independiente y clases muestra (v3) */
$paso('Columna maestros.modo_pago',
    fn() => !hayColumna($GLOBALS['db'], $e, 'maestros', 'modo_pago'),
    "ALTER TABLE maestros ADD COLUMN modo_pago ENUM('por_alumno','por_hora')
        NOT NULL DEFAULT 'por_alumno' AFTER tarifa_por_hora");

$paso('Columna inscripciones.duracion_min',
    fn() => !hayColumna($GLOBALS['db'], $e, 'inscripciones', 'duracion_min'),
    [
        "ALTER TABLE inscripciones ADD COLUMN duracion_min INT NOT NULL DEFAULT 60 AFTER hora_fin",
        // Las que ya tienen horario traen su duracion real en las horas.
        "UPDATE inscripciones
            SET duracion_min = GREATEST(30, TIMESTAMPDIFF(MINUTE, hora_inicio, hora_fin))
          WHERE hora_inicio IS NOT NULL AND hora_fin IS NOT NULL",
    ]);

$paso('Pagos de clases muestra (sin alumno inscrito)',
    fn() => !hayColumna($GLOBALS['db'], $e, 'pagos', 'concepto'),
    [
        "ALTER TABLE pagos MODIFY id_alumno INT NULL",
        "ALTER TABLE pagos
            ADD COLUMN concepto ENUM('colegiatura','inscripcion','clase_muestra','material','otro')
                NOT NULL DEFAULT 'colegiatura' AFTER id_inscripcion,
            ADD COLUMN nombre_prospecto VARCHAR(120) NULL AFTER concepto,
            ADD COLUMN telefono_prospecto VARCHAR(20) NULL AFTER nombre_prospecto,
            ADD COLUMN duracion_min INT NULL AFTER telefono_prospecto",
        "ALTER TABLE pagos ADD INDEX idx_pago_concepto (concepto, fecha_pago)",
    ]);

$paso('Bloques de media hora',
    function () {
        return (int) $GLOBALS['db']->query(
            "SELECT COUNT(*) FROM bloques_horario WHERE MINUTE(hora_inicio) = 30")->fetchColumn() === 0;
    },
    [
        "DELETE FROM bloques_horario",
        "INSERT INTO bloques_horario (hora_inicio, hora_fin, turno) VALUES
         ('09:00:00','09:30:00','matutino'),('09:30:00','10:00:00','matutino'),
         ('10:00:00','10:30:00','matutino'),('10:30:00','11:00:00','matutino'),
         ('11:00:00','11:30:00','matutino'),('11:30:00','12:00:00','matutino'),
         ('12:00:00','12:30:00','matutino'),('12:30:00','13:00:00','matutino'),
         ('13:00:00','13:30:00','matutino'),('13:30:00','14:00:00','matutino'),
         ('16:00:00','16:30:00','vespertino'),('16:30:00','17:00:00','vespertino'),
         ('17:00:00','17:30:00','vespertino'),('17:30:00','18:00:00','vespertino'),
         ('18:00:00','18:30:00','vespertino'),('18:30:00','19:00:00','vespertino'),
         ('19:00:00','19:30:00','vespertino'),('19:30:00','20:00:00','vespertino')",
    ]);

$paso('Indices de traslape en horario_ocupado',
    fn() => !hayIndice($GLOBALS['db'], $e, 'horario_ocupado', 'idx_ocupado_salon'),
    "ALTER TABLE horario_ocupado
        ADD INDEX idx_ocupado_salon (id_salon, dia_semana, hora_inicio, hora_fin),
        ADD INDEX idx_ocupado_maestro (id_maestro, dia_semana, hora_inicio, hora_fin)");

$paso('Precios de clase muestra (250 la hora, 125 la media)',
    function () use ($e) {
        if (!hayTabla($GLOBALS['db'], $e, 'configuracion')) return true;
        return (int) $GLOBALS['db']->query(
            "SELECT COUNT(*) FROM configuracion WHERE clave = 'muestra_hora'")->fetchColumn() === 0;
    },
    "INSERT INTO configuracion (clave, valor, descripcion) VALUES
     ('muestra_hora', '250', 'Costo de una clase muestra de una hora'),
     ('muestra_media_hora', '125', 'Costo de una clase muestra de media hora'),
     ('semanas_por_mes', '4', 'Clases al mes que se cuentan para la nomina por hora')
     ON DUPLICATE KEY UPDATE valor = VALUES(valor)");


/* 11. Datos del tutor y cuota de inscripcion (v4) */
$paso('Datos de padre o tutor en alumnos',
    fn() => !hayColumna($GLOBALS['db'], $e, 'alumnos', 'tutor_nombre'),
    "ALTER TABLE alumnos
        ADD COLUMN fecha_nacimiento DATE NULL AFTER email,
        ADD COLUMN tutor_nombre VARCHAR(120) NULL AFTER fecha_nacimiento,
        ADD COLUMN tutor_telefono VARCHAR(20) NULL AFTER tutor_nombre,
        ADD COLUMN tutor_parentesco VARCHAR(40) NULL AFTER tutor_telefono,
        ADD COLUMN direccion VARCHAR(200) NULL AFTER tutor_parentesco,
        ADD COLUMN notas TEXT NULL AFTER direccion");

$paso('Cuota unica de inscripcion',
    function () use ($e) {
        if (!hayTabla($GLOBALS['db'], $e, 'configuracion')) return true;
        return (int) $GLOBALS['db']->query(
            "SELECT COUNT(*) FROM configuracion WHERE clave = 'costo_inscripcion'")->fetchColumn() === 0;
    },
    "INSERT INTO configuracion (clave, valor, descripcion)
     VALUES ('costo_inscripcion', '0', 'Cuota unica que se cobra al inscribirse por primera vez')
     ON DUPLICATE KEY UPDATE valor = valor");


/* 12. Cierre real de lunes, miercoles y viernes (v4.2) */
$paso('Lunes, miercoles y viernes cierran a las 20:30',
    function () use ($e) {
        if (!hayTabla($GLOBALS['db'], $e, 'horario_escuela')) return false;
        return (int) $GLOBALS['db']->query(
            "SELECT COUNT(*) FROM horario_escuela
              WHERE dia_semana IN ('Lunes','Miercoles','Viernes') AND cierra_2 = '20:00:00'")->fetchColumn() > 0;
    },
    [
        // Solo se toca el que siga en el valor de fabrica, para no pisar un
        // horario que la escuela ya haya ajustado a mano.
        "UPDATE horario_escuela SET cierra_2 = '20:30:00'
          WHERE dia_semana IN ('Lunes','Miercoles','Viernes') AND cierra_2 = '20:00:00'",
        "INSERT IGNORE INTO bloques_horario (hora_inicio, hora_fin, turno)
         VALUES ('20:00:00','20:30:00','vespertino')",
    ]);


/* 13. Clases compartidas (v4.5) */
$paso('Columna inscripciones.comparte_con',
    fn() => !hayColumna($GLOBALS['db'], $e, 'inscripciones', 'comparte_con'),
    [
        "ALTER TABLE inscripciones ADD COLUMN comparte_con INT NULL AFTER origen",
        "ALTER TABLE inscripciones ADD INDEX idx_insc_comparte (comparte_con)",
        "ALTER TABLE inscripciones
            ADD CONSTRAINT fk_insc_comparte FOREIGN KEY (comparte_con)
            REFERENCES inscripciones(id_inscripcion) ON DELETE SET NULL",
    ]);


/* 14. Faltas y suplencias (v4.7) */
$paso('Tabla nomina_ajustes (faltas y suplencias)',
    fn() => !hayTabla($GLOBALS['db'], $e, 'nomina_ajustes'),
    "CREATE TABLE nomina_ajustes (
        id_ajuste INT AUTO_INCREMENT PRIMARY KEY,
        id_maestro INT NOT NULL,
        periodo CHAR(7) NOT NULL,
        fecha DATE NOT NULL,
        tipo ENUM('falta','suplencia','otro') NOT NULL,
        id_inscripcion INT NULL,
        minutos INT NOT NULL DEFAULT 0,
        monto DECIMAL(10,2) NOT NULL,
        motivo VARCHAR(200) NULL,
        cubre_falta INT NULL,
        pagado TINYINT(1) NOT NULL DEFAULT 0,
        registrado_por VARCHAR(100) NULL,
        fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (id_maestro) REFERENCES maestros(id_maestro),
        FOREIGN KEY (id_inscripcion) REFERENCES inscripciones(id_inscripcion) ON DELETE SET NULL,
        FOREIGN KEY (cubre_falta) REFERENCES nomina_ajustes(id_ajuste) ON DELETE CASCADE,
        UNIQUE KEY uq_falta_clase_dia (tipo, id_inscripcion, fecha),
        INDEX idx_ajuste_periodo (periodo, id_maestro))");


/* 15. Pagos importados, abonos y clases en grupo (v4.8) */
$paso('Método de pago "por confirmar" y origen del pago',
    fn() => !hayColumna($GLOBALS['db'], $e, 'pagos', 'origen'),
    [
        "ALTER TABLE pagos MODIFY metodo ENUM('efectivo','tarjeta','transferencia','por_confirmar')
            NOT NULL DEFAULT 'efectivo'",
        "ALTER TABLE pagos ADD COLUMN origen ENUM('captura','importacion') NOT NULL DEFAULT 'captura'",
    ]);

$paso('Materias que se dan en grupo (dibujo y pintura)',
    fn() => !hayColumna($GLOBALS['db'], $e, 'instrumentos', 'es_grupal'),
    [
        "ALTER TABLE instrumentos ADD COLUMN es_grupal TINYINT(1) NOT NULL DEFAULT 0",
        "UPDATE instrumentos SET es_grupal = 1 WHERE nombre IN ('Pintura','Dibujo')",
    ]);


/* 15. Abonos: un cobro repartido entre meses (v4.8) */
$paso('Folio de operación en pagos (abonos)',
    fn() => !hayColumna($GLOBALS['db'], $e, 'pagos', 'operacion'),
    [
        "ALTER TABLE pagos ADD COLUMN operacion VARCHAR(24) NULL",
        "ALTER TABLE pagos ADD COLUMN registrado_por VARCHAR(100) NULL",
        "ALTER TABLE pagos ADD INDEX idx_pago_alumno (id_alumno, periodo)",
        "ALTER TABLE pagos ADD INDEX idx_pago_operacion (operacion)",
    ]);


/* 16. Adeudo anterior al sistema (v4.8) */
$paso('Adeudo anterior en alumnos',
    fn() => !hayColumna($GLOBALS['db'], $e, 'alumnos', 'adeudo_anterior'),
    [
        "ALTER TABLE alumnos
            ADD COLUMN adeudo_anterior DECIMAL(10,2) NOT NULL DEFAULT 0,
            ADD COLUMN adeudo_anterior_nota VARCHAR(200) NULL",
        "ALTER TABLE pagos MODIFY concepto
            ENUM('colegiatura','inscripcion','clase_muestra','material','otro','adeudo_anterior')
            NOT NULL DEFAULT 'colegiatura'",
    ]);


/* 15. Materias grupales: dibujo y pintura (v5.2) */
$paso('Marcar dibujo y pintura como materias de grupo',
    function () use ($e) {
        // Se aplica solo si NINGUNA materia está marcada todavía: si la escuela
        // ya las configuró a mano desde Ajustes, no se le pisa su decisión.
        if (!hayColumna($GLOBALS['db'], $e, 'instrumentos', 'es_grupal')) return false;
        $marcadas = (int) $GLOBALS['db']->query(
            "SELECT COUNT(*) FROM instrumentos WHERE es_grupal = 1")->fetchColumn();
        if ($marcadas > 0) return false;
        return (int) $GLOBALS['db']->query(
            "SELECT COUNT(*) FROM instrumentos
              WHERE nombre LIKE '%ibujo%' OR nombre LIKE '%intura%'")->fetchColumn() > 0;
    },
    "UPDATE instrumentos SET es_grupal = 1
      WHERE nombre LIKE '%ibujo%' OR nombre LIKE '%intura%'");

/* 16. Meses a medias: alta a medio mes y cambio de maestro (v5.4) */
$paso('Clases del tramo en la nómina (alta o cambio de maestro a medio mes)',
    fn() => !hayColumna($GLOBALS['db'], $e, 'nomina_detalle', 'clases'),
    "ALTER TABLE nomina_detalle
        ADD COLUMN clases TINYINT NULL AFTER monto,
        ADD COLUMN desde DATE NULL AFTER clases,
        ADD COLUMN hasta DATE NULL AFTER desde");

$paso('Dos maestros para la misma clase en un mes (cambio de maestro)',
    fn() => !hayIndice($GLOBALS['db'], $e, 'nomina_detalle', 'uq_nomina_clase_maestro'),
    function () use ($e) {
        $db = $GLOBALS['db'];
        // En una sola instrucción: la llave foránea nunca se queda sin índice.
        $sql = "ALTER TABLE nomina_detalle ";
        if (hayIndice($db, $e, 'nomina_detalle', 'uq_inscripcion_periodo')) {
            $sql .= "DROP INDEX uq_inscripcion_periodo, ";
        }
        return [$sql . "ADD UNIQUE KEY uq_nomina_clase_maestro (id_inscripcion, periodo, id_maestro)"];
    });

/* 17. Bono de puntualidad, clase muestra con maestro (v5.5) */
$paso('Bono de puntualidad y asistencia en la ficha del maestro',
    fn() => !hayColumna($GLOBALS['db'], $e, 'maestros', 'bono_puntualidad'),
    "ALTER TABLE maestros ADD COLUMN bono_puntualidad DECIMAL(10,2) NOT NULL DEFAULT 0
        COMMENT 'Bono mensual de puntualidad y asistencia; 0 = no lleva'");

$paso('Movimientos de nómina por bono y por clase muestra',
    function () use ($e) {
        $t = $GLOBALS['db']->prepare(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'nomina_ajustes' AND COLUMN_NAME = 'tipo'");
        $t->execute([$e]);
        $tipo = (string) $t->fetchColumn();
        return $tipo !== '' && strpos($tipo, "'bono'") === false;
    },
    "ALTER TABLE nomina_ajustes
        MODIFY COLUMN tipo ENUM('falta','suplencia','otro','bono','muestra') NOT NULL");

$paso('Maestro que impartió la clase muestra',
    fn() => !hayColumna($GLOBALS['db'], $e, 'pagos', 'id_maestro'),
    [
        "ALTER TABLE pagos ADD COLUMN id_maestro INT NULL AFTER duracion_min",
        "ALTER TABLE pagos ADD CONSTRAINT fk_pago_maestro
            FOREIGN KEY (id_maestro) REFERENCES maestros(id_maestro)",
    ]);

/* 18. Cargos congelados: el adeudo de cada mes deja de recalcularse (v5.6) */
$paso('Tabla cargos_mensuales (lo que se debió por cada clase)',
    fn() => !hayTabla($GLOBALS['db'], $e, 'cargos_mensuales'),
    "CREATE TABLE cargos_mensuales (
        id_cargo INT AUTO_INCREMENT PRIMARY KEY,
        id_alumno INT NOT NULL,
        id_inscripcion INT NULL,
        periodo CHAR(7) NOT NULL,
        monto DECIMAL(10,2) NOT NULL,
        precio_mensual DECIMAL(10,2) NOT NULL DEFAULT 0,
        clases TINYINT NULL,
        clases_mes TINYINT NOT NULL DEFAULT 4,
        desde DATE NULL,
        hasta DATE NULL,
        motivo VARCHAR(60) NULL,
        fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (id_alumno) REFERENCES alumnos(id_alumno),
        FOREIGN KEY (id_inscripcion) REFERENCES inscripciones(id_inscripcion) ON DELETE SET NULL,
        UNIQUE KEY uq_cargo_clase_periodo (id_inscripcion, periodo),
        INDEX idx_cargo_alumno (id_alumno, periodo),
        INDEX idx_cargo_periodo (periodo)
     )");

/* 19. El cargo pasa a ser por clase, no por alumno (v5.7) */
$paso('Cargos por clase: columnas nuevas',
    fn() => hayTabla($GLOBALS['db'], $e, 'cargos_mensuales')
         && !hayColumna($GLOBALS['db'], $e, 'cargos_mensuales', 'id_inscripcion'),
    function () use ($e) {
        $db = $GLOBALS['db'];
        $sqls = [];
        // La version anterior guardaba un cargo por alumno y mes. Si quedo
        // algo capturado con ella, se conserva con la clase sin identificar.
        $sqls[] = "ALTER TABLE cargos_mensuales
                     ADD COLUMN id_inscripcion INT NULL AFTER id_alumno,
                     ADD COLUMN precio_mensual DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER monto,
                     ADD COLUMN clases TINYINT NULL AFTER precio_mensual,
                     ADD COLUMN clases_mes TINYINT NOT NULL DEFAULT 4 AFTER clases,
                     ADD COLUMN desde DATE NULL AFTER clases_mes,
                     ADD COLUMN hasta DATE NULL AFTER desde";
        $sqls[] = "ALTER TABLE cargos_mensuales
                     ADD CONSTRAINT fk_cargo_inscripcion
                     FOREIGN KEY (id_inscripcion) REFERENCES inscripciones(id_inscripcion) ON DELETE SET NULL";
        return $sqls;
    });

$paso('Un cargo por clase y mes (clave nueva)',
    fn() => hayColumna($GLOBALS['db'], $e, 'cargos_mensuales', 'id_inscripcion')
         && !hayIndice($GLOBALS['db'], $e, 'cargos_mensuales', 'uq_cargo_clase_periodo'),
    function () use ($e) {
        $db = $GLOBALS['db'];
        $sql = "ALTER TABLE cargos_mensuales ";
        if (hayIndice($db, $e, 'cargos_mensuales', 'uq_cargo_alumno_periodo')) {
            $sql .= "DROP INDEX uq_cargo_alumno_periodo, ";
        }
        return [$sql . "ADD UNIQUE KEY uq_cargo_clase_periodo (id_inscripcion, periodo),
                        ADD INDEX idx_cargo_alumno (id_alumno, periodo)"];
    });

// Borrar un alumno no puede desaparecer una obligacion: la fusion los mueve.
$paso('Los cargos dejan de borrarse en cascada con el alumno',
    function () use ($e) {
        $s = $GLOBALS['db']->prepare(
            "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = 'cargos_mensuales' AND DELETE_RULE = 'CASCADE'");
        $s->execute([$e]);
        return (int) $s->fetchColumn() > 0;
    },
    function () use ($e) {
        $db = $GLOBALS['db'];
        $sqls = [];
        foreach (llavesForaneasDe($db, $e, 'cargos_mensuales', 'id_alumno') as $llave) {
            $sqls[] = "ALTER TABLE cargos_mensuales DROP FOREIGN KEY `" . str_replace('`', '``', $llave) . "`";
        }
        $sqls[] = "ALTER TABLE cargos_mensuales
                     ADD CONSTRAINT fk_cargo_alumno FOREIGN KEY (id_alumno) REFERENCES alumnos(id_alumno)";
        return $sqls;
    });

// Desde qué mes se congela. Se fija al mes en que se instala esta versión, para
// no tocar el historial viejo: los meses anteriores se siguen calculando en
// vivo y se pueden seguir depurando.
$paso('Marcar desde cuándo se congelan los cargos',
    function () use ($e) {
        if (!hayTabla($GLOBALS['db'], $e, 'configuracion')) return false;
        $s = $GLOBALS['db']->query("SELECT valor FROM configuracion WHERE clave = 'cargos_congelados_desde'");
        $v = $s ? $s->fetchColumn() : false;
        return $v === false || $v === '';
    },
    "INSERT INTO configuracion (clave, valor, descripcion)
     VALUES ('cargos_congelados_desde', DATE_FORMAT(CURDATE(), '%Y-%m'),
             'Primer mes cuyo cargo se congela al cerrar')
     ON DUPLICATE KEY UPDATE valor = DATE_FORMAT(CURDATE(), '%Y-%m')");

/* 20. Pagos corregibles, y maestros que no llevan nómina (v5.7) */
$paso('Marca de monto por confirmar en los pagos',
    fn() => !hayColumna($GLOBALS['db'], $e, 'pagos', 'monto_confirmado'),
    [
        "ALTER TABLE pagos ADD COLUMN monto_confirmado TINYINT(1) NOT NULL DEFAULT 1 AFTER metodo",
        // La hoja de pagos solo dice el mes: el importe de lo importado nunca se vio.
        "UPDATE pagos SET monto_confirmado = 0 WHERE origen = 'importacion' AND concepto = 'colegiatura'",
    ]);

$paso('Bitácora de correcciones a pagos',
    fn() => !hayTabla($GLOBALS['db'], $e, 'pagos_cambios'),
    "CREATE TABLE pagos_cambios (
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
     )");

$paso('Maestros que no llevan nómina',
    fn() => !hayColumna($GLOBALS['db'], $e, 'maestros', 'lleva_nomina'),
    "ALTER TABLE maestros ADD COLUMN lleva_nomina TINYINT(1) NOT NULL DEFAULT 1 AFTER bono_puntualidad");

// Decisión de la escuela: la dueña da clases pero no cobra por nómina. Solo se
// aplica si la columna recién existe y ella sigue sin ninguna tarifa; después
// se cambia desde su ficha.
$paso('Elena Robles (dueña) sin nómina',
    function () use ($e) {
        if (!hayColumna($GLOBALS['db'], $e, 'maestros', 'lleva_nomina')) return false;
        return (int) $GLOBALS['db']->query(
            "SELECT COUNT(*) FROM maestros
              WHERE TRIM(CONCAT(nombre, ' ', apellido)) = 'Elena Robles'
                AND tarifa_por_hora = 0 AND tarifa_por_alumno = 0 AND lleva_nomina = 1"
        )->fetchColumn() > 0;
    },
    [
        "UPDATE maestros SET lleva_nomina = 0
          WHERE TRIM(CONCAT(nombre, ' ', apellido)) = 'Elena Robles'
            AND tarifa_por_hora = 0 AND tarifa_por_alumno = 0",
        // Sus renglones en cero del mes en curso sobran: no se le paga nómina.
        "DELETE nd FROM nomina_detalle nd JOIN maestros m ON m.id_maestro = nd.id_maestro
          WHERE m.lleva_nomina = 0 AND nd.pagado = 0 AND nd.monto = 0
            AND nd.periodo >= DATE_FORMAT(CURDATE(), '%Y-%m')",
    ]);

/* 21. Grupos, clases en línea, borradores y lista de espera (v5.8) */
$paso('Modalidad de la clase (presencial / en línea)',
    fn() => !hayColumna($GLOBALS['db'], $e, 'inscripciones', 'modalidad'),
    "ALTER TABLE inscripciones
        ADD COLUMN modalidad ENUM('presencial','online') NOT NULL DEFAULT 'presencial' AFTER id_salon");

$paso('Una clase en línea ocupa al maestro, no un salón',
    function () use ($e) {
        $s = $GLOBALS['db']->prepare(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'horario_ocupado' AND COLUMN_NAME = 'id_salon'");
        $s->execute([$e]);
        return $s->fetchColumn() === 'NO';
    },
    "ALTER TABLE horario_ocupado MODIFY id_salon INT NULL");

// Si alguna fila existente la violara, el paso falla sin aplicar nada y se ve aquí.
$paso('Regla: en línea sin salón, presencial con salón',
    function () use ($e) {
        if (!hayColumna($GLOBALS['db'], $e, 'inscripciones', 'modalidad')) return false;
        $s = $GLOBALS['db']->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = 'inscripciones'
                AND CONSTRAINT_NAME = 'chk_modalidad_salon'");
        $s->execute([$e]);
        return (int) $s->fetchColumn() === 0;
    },
    "ALTER TABLE inscripciones ADD CONSTRAINT chk_modalidad_salon CHECK (
           (modalidad = 'online'     AND id_salon IS NULL)
        OR (modalidad = 'presencial' AND (id_salon IS NOT NULL OR estado <> 'activa')))");

$paso('Borradores de alta de alumnos',
    fn() => !hayTabla($GLOBALS['db'], $e, 'borradores_alumnos'),
    "CREATE TABLE borradores_alumnos (
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
)");

$paso('Lista de espera',
    fn() => !hayTabla($GLOBALS['db'], $e, 'lista_espera'),
    "CREATE TABLE lista_espera (
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
)");

$paso('Cupo de las clases en línea (individual por omisión)',
    function () {
        return (int) $GLOBALS['db']->query(
            "SELECT COUNT(*) FROM configuracion WHERE clave = 'cupo_online'")->fetchColumn() === 0;
    },
    "INSERT INTO configuracion (clave, valor, descripcion)
     VALUES ('cupo_online', '1', 'Alumnos por clase en línea: 1 = individual; nunca más de 2')");

/* 22. Grupo operativo de materias, bitácora de clases y familias (v5.9) */

// Un grupo operativo junta materias que en la escuela son la misma clase
// (Dibujo y Pintura). Las materias NO se borran ni se renombran: cada
// inscripción, nómina e importación conserva su id_instrumento de siempre.
$paso('Grupos operativos de materias',
    fn() => !hayTabla($GLOBALS['db'], $e, 'grupos_materia'),
    "CREATE TABLE grupos_materia (
    id_grupo_materia INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(60) NOT NULL UNIQUE
)");

$paso('Columna instrumentos.id_grupo_materia',
    fn() => !hayColumna($GLOBALS['db'], $e, 'instrumentos', 'id_grupo_materia'),
    "ALTER TABLE instrumentos ADD COLUMN id_grupo_materia INT NULL,
        ADD CONSTRAINT fk_instrumento_grupo FOREIGN KEY (id_grupo_materia)
            REFERENCES grupos_materia(id_grupo_materia) ON DELETE SET NULL");

$paso('Dibujo y Pintura: un solo grupo operativo',
    function () use ($e) {
        if (!hayColumna($GLOBALS['db'], $e, 'instrumentos', 'id_grupo_materia')) return true;
        return (int) $GLOBALS['db']->query(
            "SELECT COUNT(*) FROM instrumentos WHERE nombre IN ('Dibujo','Pintura') AND id_grupo_materia IS NULL"
        )->fetchColumn() > 0;
    },
    [
        "INSERT IGNORE INTO grupos_materia (nombre) VALUES ('Dibujo y Pintura')",
        "UPDATE instrumentos SET id_grupo_materia =
            (SELECT id_grupo_materia FROM grupos_materia WHERE nombre = 'Dibujo y Pintura')
          WHERE nombre IN ('Dibujo','Pintura') AND id_grupo_materia IS NULL",
    ]);

// Toda edición o baja de una clase deja rastro: qué había, qué quedó, quién,
// cuándo, por qué y con qué evidencia. Igual que pagos_cambios.
$paso('Bitácora de cambios de las clases',
    fn() => !hayTabla($GLOBALS['db'], $e, 'inscripciones_cambios'),
    "CREATE TABLE inscripciones_cambios (
    id_cambio INT AUTO_INCREMENT PRIMARY KEY,
    id_inscripcion INT NOT NULL,
    operacion VARCHAR(24) NULL COMMENT 'Los cambios de una misma operación comparten folio',
    campo VARCHAR(40) NOT NULL,
    valor_anterior VARCHAR(200) NULL,
    valor_nuevo VARCHAR(200) NULL,
    motivo VARCHAR(255) NULL,
    evidencia VARCHAR(255) NULL,
    usuario VARCHAR(100) NULL,
    fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_inscripcion) REFERENCES inscripciones(id_inscripcion),
    INDEX idx_cambio_inscripcion (id_inscripcion, fecha)
)");

$paso('Familias',
    fn() => !hayTabla($GLOBALS['db'], $e, 'familias'),
    "CREATE TABLE familias (
    id_familia INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(120) NOT NULL,
    responsable VARCHAR(120) NULL COMMENT 'Quien paga: padre, madre o tutor',
    telefono VARCHAR(20) NULL,
    -- Descuento acordado para toda la familia, al mes. Se reparte entre sus
    -- alumnos en proporción a su colegiatura.
    descuento_mensual DECIMAL(10,2) NOT NULL DEFAULT 0,
    descuento_desde CHAR(7) NULL COMMENT 'Primer mes con descuento (AAAA-MM)',
    notas VARCHAR(500) NULL,
    fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (descuento_mensual >= 0)
)");

$paso('Columna alumnos.id_familia',
    fn() => !hayColumna($GLOBALS['db'], $e, 'alumnos', 'id_familia'),
    "ALTER TABLE alumnos ADD COLUMN id_familia INT NULL,
        ADD CONSTRAINT fk_alumno_familia FOREIGN KEY (id_familia)
            REFERENCES familias(id_familia) ON DELETE SET NULL");

/* 23. Facturas (v5.10) */

$paso('Datos de facturación del alumno',
    fn() => !hayColumna($GLOBALS['db'], $e, 'alumnos', 'requiere_factura'),
    "ALTER TABLE alumnos
        ADD COLUMN requiere_factura TINYINT(1) NOT NULL DEFAULT 0,
        ADD COLUMN rfc VARCHAR(13) NULL,
        ADD COLUMN razon_social VARCHAR(200) NULL,
        ADD COLUMN correo_factura VARCHAR(120) NULL,
        ADD COLUMN datos_fiscales VARCHAR(300) NULL COMMENT 'Régimen, código postal, uso de CFDI…'");

// Una fila por alumno y mes: si ya se le hizo la factura de ese mes o no.
$paso('Facturas hechas por mes',
    fn() => !hayTabla($GLOBALS['db'], $e, 'facturas'),
    "CREATE TABLE facturas (
    id_factura INT AUTO_INCREMENT PRIMARY KEY,
    id_alumno INT NOT NULL,
    periodo CHAR(7) NOT NULL,
    hecha TINYINT(1) NOT NULL DEFAULT 1,
    folio VARCHAR(40) NULL,
    usuario VARCHAR(100) NULL,
    fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (id_alumno) REFERENCES alumnos(id_alumno),
    UNIQUE KEY uq_factura_alumno_mes (id_alumno, periodo)
)");

/* 24. Roles, sesiones y acceso (v5.11) */

// Los usuarios que ya existen se quedan como administradores; el rol de cada
// persona lo decide un administrador desde Ajustes > Usuarios.
$paso('Roles de usuario: administración, recepción y consulta',
    fn() => !str_contains(tipoColumna($GLOBALS['db'], $e, 'usuarios', 'rol'), 'recepcion'),
    "ALTER TABLE usuarios MODIFY rol ENUM('admin','recepcion','consulta') NOT NULL DEFAULT 'recepcion'");

// Cambiar o restablecer una contraseña cierra las sesiones abiertas con la anterior.
$paso('Cerrar las sesiones abiertas al cambiar una contraseña',
    fn() => !hayColumna($GLOBALS['db'], $e, 'usuarios', 'version_sesion'),
    "ALTER TABLE usuarios ADD COLUMN version_sesion INT NOT NULL DEFAULT 1");

// Intentos fallidos por usuario y por equipo, para frenar a quien adivina contraseñas.
$paso('Límite de intentos para entrar',
    fn() => !hayTabla($GLOBALS['db'], $e, 'intentos_acceso'),
    "CREATE TABLE intentos_acceso (
    clave VARCHAR(190) NOT NULL PRIMARY KEY,
    fallos INT NOT NULL DEFAULT 0,
    desde DATETIME NOT NULL,
    bloqueado_hasta DATETIME NULL,
    INDEX idx_intentos_desde (desde)
)");

/* 25. Integridad financiera (v5.12) */

// Un pago mal capturado no se borra: se anula con un contramovimiento que
// apunta al original (uno solo por pago) y el original queda marcado.
$paso('Anular pagos sin borrarlos',
    fn() => !hayColumna($GLOBALS['db'], $e, 'pagos', 'anula_a'),
    ["ALTER TABLE pagos
        ADD COLUMN anula_a INT NULL AFTER operacion,
        ADD COLUMN anulado TINYINT(1) NOT NULL DEFAULT 0 AFTER anula_a,
        ADD UNIQUE KEY uq_pago_anula (anula_a),
        ADD CONSTRAINT fk_pago_anula FOREIGN KEY (anula_a) REFERENCES pagos(id_pago)"]);

// Meses de antes del corte que alguien confirmó a mano ("¿hasta qué mes pagó?"):
// no son dinero que entró hoy, así que no cuentan como ingreso del día.
$paso('Pagos históricos confirmados a mano',
    fn() => !str_contains(tipoColumna($GLOBALS['db'], $e, 'pagos', 'origen'), 'historico'),
    "ALTER TABLE pagos MODIFY origen ENUM('captura','importacion','historico') NOT NULL DEFAULT 'captura'");

// Un renglón de nómina ajustado a mano no lo vuelve a calcular nadie.
$paso('Ajustes manuales de nómina protegidos',
    fn() => !hayColumna($GLOBALS['db'], $e, 'nomina_detalle', 'ajuste_manual'),
    "ALTER TABLE nomina_detalle ADD COLUMN ajuste_manual TINYINT(1) NOT NULL DEFAULT 0 AFTER pagado");

$paso('Bitácora financiera',
    fn() => !hayTabla($GLOBALS['db'], $e, 'bitacora_financiera'),
    "CREATE TABLE bitacora_financiera (
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
)");

// Cada cobro lleva una clave única: si llega dos veces (doble clic, dos
// computadoras, la red que reintenta), el segundo no se registra.
$paso('Cobros sin duplicados',
    fn() => !hayTabla($GLOBALS['db'], $e, 'operaciones_cobro'),
    "CREATE TABLE operaciones_cobro (
    clave VARCHAR(64) NOT NULL PRIMARY KEY,
    folio VARCHAR(24) NOT NULL,
    usuario VARCHAR(100) NULL,
    fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
)");

/* 26. Taller de Dibujo y Pintura (v5.12) */

// Dibujo y Pintura es un taller: en el mismo salón, con el mismo maestro, cada
// alumno entra y sale a su hora, y dos pueden empezar juntos y durar distinto.
// La base solo protegía una clase por salón (y por maestro) y hora de inicio.
// Las clases del taller se marcan y dejan de contar para esa protección; las
// demás clases siguen protegidas por la base contra el choque exacto.
$paso('Clases del taller marcadas en el horario',
    fn() => !hayColumna($GLOBALS['db'], $e, 'horario_ocupado', 'en_taller'),
    "ALTER TABLE horario_ocupado ADD COLUMN en_taller TINYINT(1) NOT NULL DEFAULT 0 AFTER hora_fin");

$paso('Las clases de materias en grupo quedan marcadas como taller',
    function () use ($e) {
        if (!hayColumna($GLOBALS['db'], $e, 'horario_ocupado', 'en_taller')) return true;
        return (int) $GLOBALS['db']->query(
            "SELECT COUNT(*) FROM horario_ocupado ho
               JOIN inscripciones ins ON ins.id_inscripcion = ho.id_inscripcion
               JOIN instrumentos i ON i.id_instrumento = ins.id_instrumento
              WHERE ho.en_taller <> i.es_grupal"
        )->fetchColumn() > 0;
    },
    "UPDATE horario_ocupado ho
       JOIN inscripciones ins ON ins.id_inscripcion = ho.id_inscripcion
       JOIN instrumentos i ON i.id_instrumento = ins.id_instrumento
        SET ho.en_taller = i.es_grupal
      WHERE ho.en_taller <> i.es_grupal");

$paso('Hora de inicio exclusiva (las del taller no cuentan)',
    fn() => !hayColumna($GLOBALS['db'], $e, 'horario_ocupado', 'inicio_exclusivo'),
    "ALTER TABLE horario_ocupado
       ADD COLUMN inicio_exclusivo TIME AS (IF(en_taller = 1, NULL, hora_inicio)) STORED AFTER en_taller");

$paso('El horario ya no impide dos clases del taller a la misma hora de inicio',
    fn() => !in_array('inicio_exclusivo', columnasDeIndice($GLOBALS['db'], $e, 'horario_ocupado', 'uq_salon_horario'), true)
         || !in_array('inicio_exclusivo', columnasDeIndice($GLOBALS['db'], $e, 'horario_ocupado', 'uq_maestro_horario'), true),
    // Solo se quitan los índices que existan: una base sin alguno no debe fallar en cada pasada.
    function () use ($e) {
        $partes = [];
        foreach (['uq_salon_horario', 'uq_maestro_horario'] as $indice) {
            if (columnasDeIndice($GLOBALS['db'], $e, 'horario_ocupado', $indice)) $partes[] = "DROP INDEX $indice";
        }
        $partes[] = 'ADD UNIQUE KEY uq_salon_horario (id_salon, dia_semana, inicio_exclusivo)';
        $partes[] = 'ADD UNIQUE KEY uq_maestro_horario (id_maestro, dia_semana, inicio_exclusivo)';
        return ['ALTER TABLE horario_ocupado ' . implode(', ', $partes)];
    });

/* 27. Precio y duración de una clase con fecha; clases al mes por mes (v5.13) */

// Cuando una clase cambia de duración (y de precio) o de día a mitad de mes, el mes se cobra —y se le paga
// al maestro— por tramos: las clases de antes del cambio como antes, las de desde el cambio como ahora.
// Cada cambio deja una fila "desde esta fecha la clase cuesta X, dura Y y se da el día Z".
// inscripciones.costo_mensual, duracion_min y dia_semana siguen siendo lo vigente hoy. Una clase sin filas
// se calcula exactamente como siempre.
$paso('Historial de precio y duración de cada clase',
    fn() => !hayTabla($GLOBALS['db'], $e, 'precios_clase'),
    "CREATE TABLE precios_clase (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_inscripcion INT NOT NULL,
    vigente_desde DATE NOT NULL COMMENT 'Desde este día la clase cuesta, dura y se da así (1000-01-01 = lo de antes del primer cambio)',
    precio DECIMAL(10,2) NOT NULL,
    duracion_min SMALLINT NULL,
    dia_semana ENUM('Lunes','Martes','Miercoles','Jueves','Viernes','Sabado') NULL COMMENT 'Día en que se daba la clase en ese tramo',
    usuario VARCHAR(100) NULL,
    fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_precio_clase_fecha (id_inscripcion, vigente_desde),
    CONSTRAINT fk_precio_clase_inscripcion FOREIGN KEY (id_inscripcion)
        REFERENCES inscripciones(id_inscripcion) ON DELETE CASCADE
)");

// Por si la tabla se creó antes de que guardara el día de cada tramo (una copia de trabajo de la 5.13).
$paso('Día de cada tramo en el historial de precios',
    fn() => hayTabla($GLOBALS['db'], $e, 'precios_clase') && !hayColumna($GLOBALS['db'], $e, 'precios_clase', 'dia_semana'),
    "ALTER TABLE precios_clase ADD COLUMN dia_semana ENUM('Lunes','Martes','Miercoles','Jueves','Viernes','Sabado') NULL
        COMMENT 'Día en que se daba la clase en ese tramo' AFTER duracion_min");

// Un cambio de "clases al mes" rige desde el mes siguiente (o desde este mes, si se pide) y no
// reescribe el pasado: cada cambio guarda el valor nuevo y el que había antes.
$paso('Historial de clases al mes',
    fn() => !hayTabla($GLOBALS['db'], $e, 'semanas_por_mes_historial'),
    "CREATE TABLE semanas_por_mes_historial (
    vigente_desde CHAR(7) NOT NULL PRIMARY KEY COMMENT 'Mes AAAA-MM desde el que rige',
    semanas TINYINT UNSIGNED NOT NULL,
    semanas_anterior TINYINT UNSIGNED NOT NULL,
    usuario VARCHAR(100) NULL,
    fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
)");

/* ------------------------------------------------------------- Ejecución */

$resultados = [];
foreach ($pasos as $p) {
    try {
        $falta = ($p['falta'])();
    } catch (Throwable $ex) {
        $resultados[] = ['titulo' => $p['titulo'], 'estado' => 'error', 'detalle' => $ex->getMessage()];
        continue;
    }

    if (!$falta) {
        $resultados[] = ['titulo' => $p['titulo'], 'estado' => 'ok', 'detalle' => 'ya estaba'];
        continue;
    }
    if (!$aplicar) {
        $resultados[] = ['titulo' => $p['titulo'], 'estado' => 'pendiente', 'detalle' => 'falta aplicar'];
        continue;
    }

    try {
        $sqls = $p['sql'];
        if (count($sqls) === 1 && $sqls[0] instanceof Closure) {
            $sqls = ($sqls[0])();
        }
        foreach ($sqls as $sql) {
            $db->exec($sql);
        }
        $resultados[] = ['titulo' => $p['titulo'], 'estado' => 'aplicado', 'detalle' => 'listo'];
    } catch (Throwable $ex) {
        $resultados[] = ['titulo' => $p['titulo'], 'estado' => 'error', 'detalle' => $ex->getMessage()];
    }
}

$cuenta = array_count_values(array_column($resultados, 'estado'));
$pendientes = ($cuenta['pendiente'] ?? 0) + ($cuenta['error'] ?? 0);

function h($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sistema Escuela — Actualizar la base de datos</title>
<style>
  :root{ --linea:#D2D2D7; --suave:#6E6E73; --azul:#0071E3; --rojo:#C7362B; --verde:#1D7A4C; --ambar:#9A6700; }
  body{ margin:0; background:#F5F5F7; color:#1D1D1F; padding:50px 20px;
        font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,system-ui,sans-serif; }
  .caja{ max-width:760px; margin:0 auto; }
  h1{ font-size:28px; letter-spacing:-.022em; margin:0 0 6px; }
  p.intro{ color:var(--suave); margin:0 0 26px; }
  .tarjeta{ background:#fff; border:1px solid var(--linea); border-radius:18px; overflow:hidden; }
  ul{ list-style:none; margin:0; padding:0; }
  li{ display:flex; gap:14px; align-items:flex-start; padding:13px 20px; border-bottom:1px solid var(--linea); }
  li:last-child{ border-bottom:0; }
  .marca{ width:18px; flex-shrink:0; font-weight:600; }
  .ok .marca{ color:var(--verde); } .aplicado .marca{ color:var(--verde); }
  .pendiente .marca{ color:var(--ambar); } .error .marca{ color:var(--rojo); }
  .detalle{ color:var(--suave); font-size:13px; margin-top:3px; }
  .error .detalle{ color:var(--rojo); }
  .acciones{ margin-top:26px; display:flex; gap:12px; flex-wrap:wrap; align-items:center; }
  a.boton, button.boton{ display:inline-block; background:var(--azul); color:#fff; text-decoration:none;
           border:0; border-radius:980px; padding:11px 24px; font:inherit; font-weight:500; cursor:pointer; }
  a.boton.linea{ background:#fff; color:#1D1D1F; border:1px solid var(--linea); }
  .aviso{ background:#fff; border:1px solid var(--linea); border-left:3px solid var(--verde);
          border-radius:12px; padding:14px 18px; margin-bottom:22px; }
</style>
</head>
<body>
<div class="caja">
  <h1>Actualizar la base de datos</h1>
  <p class="intro">Base <strong><?= h($esquema) ?></strong>.
    <?= $aplicar ? 'Esto es lo que se hizo.' : 'Esto es lo que falta. Todavía no se ha tocado nada.' ?></p>

  <?php if ($aplicar && $pendientes === 0): ?>
    <div class="aviso"><strong>La base quedó al día.</strong>
      Vuelve al sistema y recarga: Inicio y Nómina ya deben funcionar.</div>
  <?php endif; ?>

  <div class="tarjeta">
    <ul>
      <?php foreach ($resultados as $r): ?>
        <li class="<?= h($r['estado']) ?>">
          <span class="marca"><?= $r['estado'] === 'error' ? '✕' : ($r['estado'] === 'pendiente' ? '·' : '✓') ?></span>
          <span>
            <?= h($r['titulo']) ?>
            <span class="detalle"><?= h($r['detalle']) ?></span>
          </span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div class="acciones">
    <?php if (!$aplicar): ?>
      <?= Mantenimiento::formulario('Aplicar los cambios que faltan') ?>
      <span class="detalle">Haz un respaldo antes si tienes datos capturados.</span>
    <?php else: ?>
      <a class="boton linea" href="?">Volver a revisar</a>
      <a class="boton" href="../frontend/">Ir al sistema</a>
    <?php endif; ?>
  </div>

  <p class="detalle" style="margin-top:26px">
    Puedes correr esta página las veces que quieras: lo que ya está hecho no se vuelve a tocar.
  </p>
</div>
</body>
</html>

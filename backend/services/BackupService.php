<?php
require_once __DIR__ . '/../config/Database.php';

/**
 * Genera un respaldo completo de la base de datos (estructura + datos)
 * en formato .sql, usando solo PDO (no depende de que el binario
 * mysqldump esté disponible en el PATH del sistema, lo cual es un
 * problema común en instalaciones de XAMPP).
 *
 * Cómo se hace (5.14):
 *   · CONSISTENTE: todo se lee dentro de una sola transacción con foto de la base
 *     (START TRANSACTION WITH CONSISTENT SNAPSHOT, REPEATABLE READ). Si alguien cobra
 *     mientras se respalda, el archivo muestra la base tal como estaba al empezar: un pago
 *     no puede quedar a medias entre dos tablas.
 *   · POR PARTES: se escribe renglón por renglón (lectura sin cargar la tabla en memoria) y
 *     el INSERT junta varios renglones por sentencia. El archivo se arma en un temporal y
 *     solo se renombra si terminó completo.
 *   · RESTAURABLE: abre con SET NAMES utf8mb4, SET time_zone, SET FOREIGN_KEY_CHECKS=0 y
 *     cierra volviendo a activar las llaves, el modo SQL y la zona horaria que tenía quien restaura. Termina con una HUELLA (conteos y sumas de lo que
 *     importa, tomada en la misma foto) y con una línea de "Fin del respaldo": el verificador
 *     (VerificadorRespaldo) restaura el archivo en una base temporal y compara.
 *
 * PORTABILIDAD MariaDB -> MySQL 8: el CREATE TABLE sale de SHOW CREATE TABLE. Se corrige
 * lo que MySQL 8 no acepta tal cual: DEFAULT curdate() pasa a DEFAULT (curdate()) (que
 * MariaDB 10.2+ también acepta). Lo demás que usa el sistema (columnas generadas STORED, CHECK,
 * current_timestamp(), utf8mb4_unicode_ci, llaves foráneas) es válido en los dos. No se ha
 * probado con un MySQL 8 real, solo contra MariaDB 10.4. No incluye vistas con DEFINER
 * ni procedimientos, triggers o eventos: el sistema no usa ninguno (si algún día existen, se avisa
 * en el encabezado del respaldo).
 *
 * LISTA BLANCA: el verificador (VerificadorRespaldo::sentenciaPermitida) solo acepta lo que este servicio escribe.
 * Si aquí se agrega un tipo de sentencia nuevo, hay que agregarlo allá también (y a su prueba).
 */
class BackupService
{
    /** Versión del formato de la huella */
    public const FORMATO = 1;

    /** Cuántos renglones, como máximo, lleva cada INSERT (y su tamaño: el max_allowed_packet de XAMPP es de 1 MB) */
    private const RENGLONES_POR_INSERT = 200;
    private const BYTES_POR_INSERT = 200000;

    /** @var array<string,array{0:string,1:string}> sumas que forman la huella: nombre => [tabla, expresión SQL] */
    private const SUMAS = [
        'pagos.monto'                => ['pagos', 'monto'],
        'pagos.recargo'              => ['pagos', 'recargo'],
        'nomina_detalle.monto'       => ['nomina_detalle', 'monto'],
        'nomina_detalle.pagados'     => ['nomina_detalle', 'pagado'],
        'nomina_ajustes.monto'       => ['nomina_ajustes', 'monto'],
        'cargos_mensuales.monto'     => ['cargos_mensuales', 'monto'],
        'alumnos.activos'            => ['alumnos', 'activo'],
        'inscripciones.costo'        => ['inscripciones', 'costo_mensual'],
        'inscripciones.activas'      => ['inscripciones', "(estado = 'activa')"],
        'horario_ocupado.contenido'  => ['horario_ocupado',
            "CRC32(CONCAT_WS('|', id_inscripcion, COALESCE(id_salon, '-'), id_maestro, dia_semana, hora_inicio, hora_fin, en_taller))"],
        'inscripciones.contenido'    => ['inscripciones',
            "CRC32(CONCAT_WS('|', id_inscripcion, id_alumno, id_maestro, COALESCE(id_instrumento, '-'), COALESCE(dia_semana, '-'), COALESCE(hora_inicio, '-'), estado, duracion_min, costo_mensual))"],
    ];

    private PDO $db;
    private bool $propia;

    /**
     * @param PDO|null $db conexión ya abierta (para pruebas); sin ella el respaldo abre la suya, aparte de la
     *                     conexión de la pantalla: así su transacción y su zona horaria no afectan a nadie más.
     */
    public function __construct(?PDO $db = null)
    {
        $this->propia = $db === null;
        $this->db = $db ?? Database::nueva();
    }

    /**
     * Nombres de las columnas generadas (calculadas por la base) de una tabla.
     * Ojo: no cuenta el "DEFAULT_GENERATED" de MySQL 8, que es un valor por
     * omisión común y corriente: esa columna sí se respalda.
     */
    private function columnasGeneradas(string $tabla): array
    {
        $s = $this->db->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                AND (EXTRA LIKE '%VIRTUAL%' OR EXTRA LIKE '%STORED%'
                     OR EXTRA LIKE '%PERSISTENT%')"
        );
        $s->execute([$tabla]);
        return $s->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Lo que MySQL 8 no acepta del CREATE TABLE de MariaDB (ver la nota de portabilidad arriba) */
    public static function portable(string $crear): string
    {
        return (string) preg_replace('/\bDEFAULT\s+(curdate|curtime|current_date|current_time)(\(\))?(?=[\s,)])/i', 'DEFAULT ($1())', $crear);
    }

    /**
     * Escribe el respaldo en un archivo. Va primero a un temporal y solo se renombra al terminar completo.
     *
     * @return array ver volcar()
     */
    public function generarEnArchivo(string $ruta): array
    {
        $parcial = $ruta . '.parcial';
        $f = @fopen($parcial, 'wb');
        if ($f === false) throw new Exception('No se pudo crear el archivo del respaldo en ' . dirname($ruta) . '.');
        @chmod($parcial, 0600);
        try {
            $escrito = 0;
            $sinEspacio = 'No hay espacio suficiente para el respaldo en ' . dirname($ruta) . ' (el disco se llenó al escribirlo). Libera espacio y vuelve a intentar.';
            $info = $this->volcar(function (string $trozo) use ($f, &$escrito, $sinEspacio) {
                $n = fwrite($f, $trozo);
                // Un disco lleno no siempre da false: puede escribir solo una parte. Se exige que se escriba TODO el trozo.
                if ($n === false || $n !== strlen($trozo)) throw new Exception($sinEspacio);
                $escrito += $n;
            });
            if (!fflush($f)) throw new Exception($sinEspacio);
            $cerrado = fclose($f);
            $f = null;
            if (!$cerrado) throw new Exception($sinEspacio);
            clearstatcache(true, $parcial);
            if ((int) @filesize($parcial) !== $escrito) throw new Exception($sinEspacio);
            if (!@rename($parcial, $ruta)) {
                if (is_file($ruta)) @unlink($ruta);
                if (!@rename($parcial, $ruta)) throw new Exception('No se pudo dejar el respaldo en ' . $ruta . '.');
            }
        } catch (Throwable $e) {
            if (is_resource($f)) fclose($f);
            @unlink($parcial);
            throw $e;
        }
        $info['bytes'] = (int) filesize($ruta);
        return $info;
    }

    /** El respaldo completo como texto (para pruebas y para respaldos chicos; el normal va a un archivo) */
    public function generarDump(): string
    {
        $sql = '';
        $this->volcar(function (string $trozo) use (&$sql) { $sql .= $trozo; });
        return $sql;
    }

    /**
     * Genera el respaldo y lo entrega por trozos a $escribir.
     *
     * @param callable(string):void $escribir
     * @return array ['base', 'tablas', 'filas', 'huella']
     */
    public function volcar(callable $escribir): array
    {
        $db = $this->db;
        if ($db->inTransaction()) throw new Exception('No se puede respaldar con una operación a medias.');

        // Una sola foto de la base para todo el respaldo. La hora en UTC: así lo que sea TIMESTAMP
        // se restaura igual en cualquier computadora.
        $db->exec("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ");
        $db->exec("SET time_zone = '+00:00'");
        $db->exec("SET NAMES utf8mb4");
        $db->exec("START TRANSACTION WITH CONSISTENT SNAPSHOT");
        try {
            $base = (string) $db->query("SELECT DATABASE()")->fetchColumn();
            $version = (string) $db->query("SELECT VERSION()")->fetchColumn();

            $tablas = []; $vistas = [];
            foreach ($db->query("SHOW FULL TABLES")->fetchAll(PDO::FETCH_NUM) as [$nombre, $tipo]) {
                if (strtoupper((string) $tipo) === 'VIEW') $vistas[] = $nombre; else $tablas[] = $nombre;
            }

            $escribir("-- Respaldo de {$base} generado el " . date('Y-m-d H:i:s') . "\n");
            $escribir("-- Servidor de origen: {$version} · formato de respaldo " . self::FORMATO . " · se restaura con el mismo usuario del sistema:\n");
            $escribir("--   mysql -u USUARIO -p NOMBRE_DE_LA_BASE < este_archivo.sql   (o con phpMyAdmin > Importar)\n");
            $escribir("-- Antes de restaurar de verdad, comprueba el archivo con backend/herramientas/verificar_respaldo.php\n\n");
            $escribir("SET NAMES utf8mb4;\n");
            $escribir("SET @ESCUELA_TZ_ANTES=@@TIME_ZONE;\n");        // la zona horaria de quien restaura, para devolvérsela al final
            $escribir("SET time_zone = '+00:00';\n");
            $escribir("SET FOREIGN_KEY_CHECKS=0;\n");
            $escribir("SET @ESCUELA_SQL_MODE_ANTES=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

            $filasTotal = 0;
            foreach ($tablas as $tabla) {
                $escribir("-- ----------------------------\n-- Tabla: {$tabla}\n-- ----------------------------\n");
                $escribir("DROP TABLE IF EXISTS `{$tabla}`;\n");
                $crear = $db->query("SHOW CREATE TABLE `{$tabla}`")->fetch(PDO::FETCH_NUM)[1];
                $escribir(self::portable($crear) . ";\n\n");
                $filasTotal += $this->volcarFilas($tabla, $escribir);
                $escribir("\n");
            }
            foreach ($vistas as $vista) {
                $crear = $db->query("SHOW CREATE VIEW `{$vista}`")->fetch(PDO::FETCH_NUM)[1];
                $escribir("-- Vista: {$vista}\nDROP VIEW IF EXISTS `{$vista}`;\n{$crear};\n\n");
            }

            // La huella se toma en la misma foto: describe exactamente lo que quedó en el archivo.
            $huella = self::huella($db);

            $escribir("SET FOREIGN_KEY_CHECKS=1;\n");
            $escribir("SET SQL_MODE=@ESCUELA_SQL_MODE_ANTES;\n");
            $escribir("SET time_zone=@ESCUELA_TZ_ANTES;\n\n");
            $escribir("-- ESCUELA-HUELLA " . json_encode($huella, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            $escribir("-- Fin del respaldo (" . count($tablas) . " tablas, {$filasTotal} renglones)\n");
            return ['base' => $base, 'tablas' => count($tablas), 'filas' => $filasTotal, 'huella' => $huella];
        } finally {
            try { $db->exec("ROLLBACK"); } catch (Throwable $e) { /* solo se leyó */ }
            $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
    }

    /** Los renglones de una tabla, en INSERT de varios renglones. Devuelve cuántos escribió. */
    private function volcarFilas(string $tabla, callable $escribir): int
    {
        $db = $this->db;
        $generadas = $this->columnasGeneradas($tabla);
        $cols = $db->prepare("SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS
                               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION");
        $cols->execute([$tabla]);
        $nombres = []; $numerica = [];
        foreach ($cols->fetchAll(PDO::FETCH_NUM) as [$nombre, $tipo]) {
            // Las columnas generadas (como horario_ocupado.inicio_exclusivo) las calcula la base:
            // un INSERT que les ponga valor se rechaza, así que no se escriben.
            if (in_array($nombre, $generadas, true)) continue;
            $nombres[] = $nombre;
            $numerica[] = in_array(strtolower((string) $tipo), ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal'], true);
        }
        if (!$nombres) return 0;

        $lista = implode(', ', array_map(fn($c) => "`{$c}`", $nombres));
        $encabezado = "INSERT INTO `{$tabla}` ({$lista}) VALUES\n";

        // Sin cargar la tabla entera en memoria: se lee renglón por renglón.
        $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $total = 0; $enLote = []; $bytes = 0; $r = null;
        try {
            $r = $db->query("SELECT {$lista} FROM `{$tabla}`");
            while (($fila = $r->fetch(PDO::FETCH_NUM)) !== false) {
                $valores = [];
                foreach ($fila as $i => $v) {
                    if ($v === null) $valores[] = 'NULL';
                    elseif ($numerica[$i] && preg_match('/^-?\d+(\.\d+)?$/', (string) $v)) $valores[] = (string) $v;
                    else $valores[] = $db->quote((string) $v);
                }
                $linea = '(' . implode(', ', $valores) . ')';
                $enLote[] = $linea; $bytes += strlen($linea); $total++;
                if (count($enLote) >= self::RENGLONES_POR_INSERT || $bytes >= self::BYTES_POR_INSERT) {
                    $escribir($encabezado . implode(",\n", $enLote) . ";\n");
                    $enLote = []; $bytes = 0;
                }
            }
        } finally {
            if ($r) $r->closeCursor();
            $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
        if ($enLote) $escribir($encabezado . implode(",\n", $enLote) . ";\n");
        return $total;
    }

    /**
     * La huella de una base: cuántos renglones tiene cada tabla y las sumas que importan (pagos, nómina,
     * cargos, alumnos, inscripciones, horario). Sirve para comprobar que una copia restaurada es la misma.
     * Va dentro de la foto del respaldo; en una base restaurada se saca con las mismas consultas.
     *
     * @return array ['formato' => int, 'tablas' => [tabla => renglones], 'sumas' => [nombre => '1234.50']]
     */
    public static function huella(PDO $db): array
    {
        $tablas = [];
        foreach ($db->query("SHOW FULL TABLES")->fetchAll(PDO::FETCH_NUM) as [$nombre, $tipo]) {
            if (strtoupper((string) $tipo) === 'VIEW') continue;
            $tablas[$nombre] = (int) $db->query("SELECT COUNT(*) FROM `{$nombre}`")->fetchColumn();
        }
        $sumas = [];
        foreach (self::SUMAS as $clave => [$tabla, $expr]) {
            if (!isset($tablas[$tabla])) continue;
            try {
                $v = $db->query("SELECT COALESCE(SUM({$expr}), 0) FROM `{$tabla}`")->fetchColumn();
                $sumas[$clave] = number_format((float) $v, 2, '.', '');
            } catch (PDOException $e) {
                // Una columna que esa base aún no tiene (versión anterior): no entra a la huella.
            }
        }
        ksort($tablas);
        return ['formato' => self::FORMATO, 'tablas' => $tablas, 'sumas' => $sumas];
    }
}

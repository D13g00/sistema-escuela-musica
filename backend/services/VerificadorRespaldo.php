<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/BackupService.php';

/**
 * Comprueba que un respaldo sirve de verdad: lo restaura en una base temporal
 * (escuela_demo_verif_<fecha>_<azar>), compara su huella (renglones por tabla y
 * sumas de pagos, nómina, cargos, alumnos, inscripciones y horario) contra la que
 * el respaldo guardó al generarse, y borra la base temporal. No toca la base viva.
 *
 * Usa el mismo usuario de MariaDB que el sistema, que debe poder crear bases
 * (el root de un XAMPP sí). Si no puede, lo dice con claridad.
 */
class VerificadorRespaldo
{
    public const PREFIJO = 'escuela_demo_verif_';

    /** Lo que se contesta cuando el respaldo trae algo que BackupService nunca escribe (ver sentenciaPermitida) */
    public const MENSAJE_INSTRUCCIONES = 'El respaldo trae instrucciones que el sistema no genera: no se restauró nada.';

    /** Cada cuántos INSERT se comprueba que la conexión sigue en la base temporal (las demás sentencias, siempre) */
    private const COMPROBAR_CADA = 25;

    /** Segundos que se espera a que el servidor de la base conteste */
    private int $espera;

    public function __construct(int $espera = 5)
    {
        $this->espera = $espera;
    }

    /**
     * @return array ['ok' => bool, 'mensaje' => string, 'problemas' => string[], 'sentencias' => int, 'sin_huella' => bool]
     */
    public function verificar(string $archivo): array
    {
        $resultado = ['ok' => false, 'mensaje' => '', 'problemas' => [], 'sentencias' => 0, 'sin_huella' => false, 'codigo' => 2];

        if (!is_file($archivo) || !is_readable($archivo)) {
            $resultado['mensaje'] = 'No encuentro el archivo del respaldo: ' . $archivo;
            return $resultado;
        }
        if (filesize($archivo) < 200) {
            $resultado['mensaje'] = 'El archivo está casi vacío: no es un respaldo del sistema.';
            $resultado['codigo'] = 1;
            return $resultado;
        }

        [$huella, $completo] = $this->leerFinal($archivo);
        if (!$completo) {
            $resultado['mensaje'] = 'El respaldo está incompleto: le falta el final del archivo (se cortó al guardarse o al copiarse).';
            $resultado['codigo'] = 1;
            return $resultado;
        }

        // Antes de crear nada: el archivo solo puede traer lo que BackupService escribe (lista blanca). Un .sql con USE,
        // CREATE o borrado de bases, GRANT, nombres con base (`otra`.`tabla`) u otra cosa se rechaza entero, sin ejecutar nada.
        try {
            $rechazo = $this->primeraNoPermitida($archivo);
        } catch (Throwable $e) {
            $resultado['mensaje'] = 'No pude leer el respaldo: ' . $this->limpio($e->getMessage());
            return $resultado;
        }
        if ($rechazo !== null) {
            $resultado['mensaje'] = self::MENSAJE_INSTRUCCIONES;
            $resultado['codigo'] = 1;
            $resultado['rechazada'] = $rechazo;
            return $resultado;
        }

        // La base temporal
        try {
            $admin = Database::nueva($this->espera, '');
        } catch (Throwable $e) {
            $resultado['mensaje'] = 'No pude conectarme al servidor de la base de datos (' . $this->limpio($e->getMessage())
                . '). Revisa que MySQL esté encendido en el panel de XAMPP.';
            return $resultado;
        }
        $temporal = self::PREFIJO . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
        try {
            $admin->exec("CREATE DATABASE `{$temporal}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (Throwable $e) {
            $resultado['mensaje'] = 'No pude crear la base temporal para probar el respaldo. El usuario de la base del sistema necesita permiso '
                . 'para crear bases de datos (' . $this->limpio($e->getMessage()) . ').';
            return $resultado;
        }

        $resultado['codigo'] = 1;   // desde aquí, si no sale bien es que el respaldo no sirve
        try {
            $base = Database::nueva($this->espera, $temporal);
            $restaurado = $this->restaurar($base, $archivo, $resultado, $temporal);
            if ($restaurado) {
                $this->comprobarBase($base, $temporal);     // también justo antes de calcular la huella
                if ($huella === null) {
                    $resultado['ok'] = true;
                    $resultado['codigo'] = 0;
                    $resultado['sin_huella'] = true;
                    $resultado['mensaje'] = 'Respaldo restaurado completo, pero el archivo no trae huella para comparar los importes (es de una versión anterior).';
                } else {
                    $problemas = $this->comparar($huella, BackupService::huella($base));
                    $resultado['problemas'] = $problemas;
                    $resultado['ok'] = !$problemas;
                    $resultado['codigo'] = $problemas ? 1 : 0;
                    $resultado['mensaje'] = $problemas
                        ? 'El respaldo se restaura, pero NO coincide con la base que se respaldó.'
                        : 'Respaldo verificado: se restaura completo y coincide.';
                }
            }
        } catch (RespaldoNoConfiable $e) {
            $resultado['ok'] = false;
            $resultado['mensaje'] = $e->getMessage();
        } catch (Throwable $e) {
            $resultado['ok'] = false;
            $resultado['mensaje'] = 'No se pudo comprobar el respaldo: ' . $this->limpio($e->getMessage());
        } finally {
            $base = null;
            self::borrarTemporal($admin, $temporal);
        }
        return $resultado;
    }

    /**
     * Borra las bases temporales de verificación que quedaron de corridas que se interrumpieron
     * (más de 3 horas de antiguas). Solo toca las que se llaman escuela_demo_verif_*.
     */
    public function limpiarTemporales(): int
    {
        $n = 0;
        try {
            $admin = Database::nueva($this->espera, '');
            $bases = $admin->query("SHOW DATABASES LIKE '" . str_replace('_', '\\_', self::PREFIJO) . "%'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($bases as $b) {
                if (!preg_match('/^' . self::PREFIJO . '(\d{8})_(\d{6})_[0-9a-f]{6}\z/', $b, $m)) continue;
                $hecha = DateTime::createFromFormat('Ymd His', $m[1] . ' ' . $m[2]);
                if ($hecha && $hecha->getTimestamp() < time() - 3 * 3600) {
                    if (self::borrarTemporal($admin, $b)) $n++;
                }
            }
        } catch (Throwable $e) { /* sin conexión: no hay nada que limpiar */ }
        return $n;
    }

    /**
     * Borra una base temporal de verificación. Es el ÚNICO lugar del sistema que borra una base, y solo acepta los nombres
     * que esta clase crea (escuela_demo_verif_AAAAMMDD_HHMMSS_xxxxxx): nunca la base del sistema ni otra.
     */
    private static function borrarTemporal(PDO $admin, string $nombre): bool
    {
        if (!preg_match('/^' . self::PREFIJO . '\d{8}_\d{6}_[0-9a-f]{6}\z/', $nombre)) return false;
        try { $admin->exec("DROP DATABASE IF EXISTS `{$nombre}`"); return true; } catch (Throwable $e) { return false; /* se limpia en la siguiente pasada */ }
    }

    /** Ejecuta el archivo en la base temporal. Si algo falla, lo anota y devuelve false. */
    private function restaurar(PDO $base, string $archivo, array &$resultado, string $temporal): bool
    {
        $n = 0; $inserts = 0;
        try {
            // Sin NO_BACKSLASH_ESCAPES ni ANSI_QUOTES: así el servidor lee las comillas igual que la lista blanca.
            $base->exec("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
            $this->comprobarBase($base, $temporal);
            foreach (self::sentencias($archivo) as $sql) {
                $sql = self::sinComentariosIniciales($sql);     // lo que se revisa es exactamente lo que se ejecuta
                if ($sql === '') continue;
                // Se vuelve a revisar al ejecutar (el archivo pudo cambiar desde la primera lectura).
                if (!$this->permitida($sql)) throw new RespaldoNoConfiable(self::MENSAJE_INSTRUCCIONES);
                $base->exec($sql);
                $n++;
                if (stripos($sql, 'INSERT') === 0) {
                    if (++$inserts % self::COMPROBAR_CADA === 0) $this->comprobarBase($base, $temporal);
                } else {
                    $this->comprobarBase($base, $temporal);
                }
            }
            $this->comprobarBase($base, $temporal);
        } catch (RespaldoNoConfiable $e) {
            $resultado['sentencias'] = $n;
            throw $e;
        } catch (Throwable $e) {
            $resultado['sentencias'] = $n;
            $resultado['mensaje'] = 'El respaldo NO se restaura: falla en la sentencia ' . ($n + 1) . ' (' . $this->limpio($e->getMessage()) . ').';
            return false;
        }
        $resultado['sentencias'] = $n;
        return true;
    }

    /** La conexión debe seguir en la base temporal: si el archivo la cambió (USE, etc.) se aborta y la verificación borra la temporal. */
    private function comprobarBase(PDO $base, string $temporal): void
    {
        $actual = (string) $base->query('SELECT DATABASE()')->fetchColumn();
        if ($actual !== $temporal) {
            throw new RespaldoNoConfiable('El respaldo cambió la base de trabajo mientras se restauraba: se canceló, se borró la base temporal y no se tocó ninguna otra.');
        }
    }

    /** Primera sentencia del archivo que no está en la lista blanca (resumida), o null si todas lo están */
    private function primeraNoPermitida(string $archivo): ?string
    {
        foreach (self::sentencias($archivo) as $sql) {
            $sql = self::sinComentariosIniciales($sql);
            if ($sql === '') continue;
            if (!$this->permitida($sql)) return mb_substr((string) preg_replace('/\s+/', ' ', $sql), 0, 80);
        }
        return null;
    }

    /**
     * Quita los renglones de comentario completos que el lector deja pegados antes de una sentencia (los títulos "-- Tabla: x" del
     * respaldo). Solo los que el servidor también lee como comentario: "-- " con espacio, "--" y fin de línea, o "#".
     */
    public static function sinComentariosIniciales(string $sql): string
    {
        $r = preg_replace('/\A(?:[ \t\r\n]*(?:--[ \t][^\n]*|--\r?(?=\n)|#[^\n]*)\n)+/', '', trim($sql) . "\n");
        return trim((string) $r);
    }

    /** Punto único de decisión; las pruebas lo sustituyen para comprobar las defensas que vienen después. */
    protected function permitida(string $sql): bool
    {
        return self::sentenciaPermitida($sql);
    }

    /**
     * Lee el final del archivo: la huella y la marca de que terminó completo.
     *
     * @return array [huella|null, completo]
     */
    private function leerFinal(string $archivo): array
    {
        $tam = (int) filesize($archivo);
        $f = fopen($archivo, 'rb');
        fseek($f, max(0, $tam - 65536));
        $cola = (string) stream_get_contents($f);
        fclose($f);
        $completo = (bool) preg_match('/^-- Fin del respaldo \(\d+ tablas, \d+ renglones\)\s*$/m', $cola);
        $huella = null;
        if (preg_match('/^-- ESCUELA-HUELLA (\{.*\})\s*$/m', $cola, $m)) {
            $h = json_decode($m[1], true);
            if (is_array($h) && isset($h['tablas'], $h['sumas'])) $huella = $h;
        }
        return [$huella, $completo];
    }

    /** @return string[] lo que no coincide, en palabras */
    private function comparar(array $esperada, array $real): array
    {
        $p = [];
        foreach ($esperada['tablas'] as $t => $n) {
            if (!array_key_exists($t, $real['tablas'])) { $p[] = "Falta la tabla {$t} en lo restaurado."; continue; }
            if ((int) $real['tablas'][$t] !== (int) $n) {
                $p[] = "La tabla {$t} tenía {$n} renglones al respaldar y la restauración trae {$real['tablas'][$t]}.";
            }
        }
        foreach ($real['tablas'] as $t => $n) {
            if (!array_key_exists($t, $esperada['tablas'])) $p[] = "La restauración trae la tabla {$t}, que el respaldo no declaraba.";
        }
        foreach ($esperada['sumas'] as $clave => $valor) {
            if (!array_key_exists($clave, $real['sumas'])) { $p[] = "No se pudo calcular {$clave} en lo restaurado."; continue; }
            if (abs((float) $real['sumas'][$clave] - (float) $valor) > 0.005) {
                $p[] = "El total de {$clave} era {$valor} al respaldar y en lo restaurado es {$real['sumas'][$clave]}.";
            }
        }
        return $p;
    }

    private function limpio(string $mensaje): string
    {
        $mensaje = preg_replace('/\s+/', ' ', $mensaje);
        return mb_substr((string) $mensaje, 0, 300);
    }

    /** Nombre de tabla o de columna tal como lo escribe BackupService: entre comillas invertidas, sin base ni punto */
    private const IDENT = '`[A-Za-z0-9_$]+`';

    /** El texto entre comillas simples tal como lo escribe PDO::quote (\' y \\ o '' dentro) */
    private const CADENA = '\'(?:[^\'\\\\]++|\\\\.|\'\')*+\'';

    /**
     * LISTA BLANCA: ¿esta sentencia es de las que BackupService escribe? Solo se aceptan, y exactamente con esta forma:
     *   SET NAMES utf8mb4 · SET time_zone · SET FOREIGN_KEY_CHECKS / UNIQUE_CHECKS = 0|1 · SET CHARACTER_SET_CLIENT
     *   SET @ESCUELA_SQL_MODE_ANTES=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' · SET SQL_MODE=@ESCUELA_SQL_MODE_ANTES
     *   SET @ESCUELA_TZ_ANTES=@@TIME_ZONE · SET time_zone=@ESCUELA_TZ_ANTES
     *   DROP TABLE IF EXISTS `x` · CREATE TABLE `x` (...) · INSERT INTO `x` (`a`, ...) VALUES (solo números, NULL y textos)
     *   LOCK TABLES `x` WRITE · UNLOCK TABLES
     * Todo lo demás (USE, CREATE de bases o SCHEMA, borrado de bases, GRANT, USER, vistas, procedimientos, disparadores, eventos,
     * LOAD DATA, INTO OUTFILE, comentarios con versión en lo suelto, nombres con base `a`.`b`, un SET de otra
     * variable...) se rechaza. Las líneas de comentario completas ya las descarta el lector de sentencias.
     */
    public static function sentenciaPermitida(string $sql): bool
    {
        $sql = trim($sql);
        if ($sql === '' || strpbrk($sql, "\0") !== false) return false;
        $i = self::IDENT;
        if (preg_match('/\ASET\s/i', $sql)) {
            foreach ([
                '/\ASET\s+NAMES\s+utf8(?:mb4)?\z/i',
                '/\ASET\s+(?:SESSION\s+)?CHARACTER_SET_CLIENT\s*=\s*utf8(?:mb4)?\z/i',
                '/\ASET\s+(?:SESSION\s+)?TIME_ZONE\s*=\s*(?:\'[+-]\d\d:\d\d\'|\'SYSTEM\'|@ESCUELA_TZ_ANTES)\z/i',
                '/\ASET\s+@ESCUELA_TZ_ANTES\s*=\s*@@(?:SESSION\.)?TIME_ZONE\z/i',
                '/\ASET\s+(?:FOREIGN_KEY_CHECKS|UNIQUE_CHECKS)\s*=\s*[01]\z/i',
                '/\ASET\s+@ESCUELA_SQL_MODE_ANTES\s*=\s*@@SQL_MODE\s*,\s*SQL_MODE\s*=\s*\'(?:NO_AUTO_VALUE_ON_ZERO)?\'\z/i',
                '/\ASET\s+SQL_MODE\s*=\s*@ESCUELA_SQL_MODE_ANTES\z/i',
            ] as $re) if (preg_match($re, $sql)) return true;
            return false;
        }
        if (preg_match('/\ADROP\s+TABLE\s+IF\s+EXISTS\s+' . $i . '\z/i', $sql)) return true;
        if (preg_match('/\AUNLOCK\s+TABLES\z/i', $sql)) return true;
        if (preg_match('/\ALOCK\s+TABLES\s+' . $i . '\s+(?:READ|WRITE)(?:\s*,\s*' . $i . '\s+(?:READ|WRITE))*\z/i', $sql)) return true;
        if (preg_match('/\AINSERT\s+INTO\s+' . $i . '\s*\(\s*' . $i . '(?:\s*,\s*' . $i . ')*\s*\)\s*VALUES\b/i', $sql, $m)) {
            return self::soloValores(substr($sql, strlen($m[0])));
        }
        if (preg_match('/\ACREATE\s+TABLE\s+' . $i . '\s*\(/i', $sql)) return self::crearTablaPermitido($sql);
        return false;
    }

    /** Lo que sigue a VALUES: solo (números, NULL, textos entre comillas), separados por comas. Ninguna función, comentario ni otra sentencia. */
    private static function soloValores(string $cola): bool
    {
        // Cada texto entre comillas pasa a ser un 0; lo que quede fuera de los textos solo puede ser número, NULL, paréntesis, comas y espacios.
        // Si una comilla no cierra, o hay un comentario (/*, --, #), una comilla invertida, etc., queda algo que no es de esa lista.
        $sinTextos = preg_replace('/' . self::CADENA . '/s', '0', $cola);
        if ($sinTextos === null) return false;
        $resto = preg_replace('/\bNULL\b|-?\d+(?:\.\d+)?|[\s,()]+/i', '', $sinTextos);
        return $resto === '';
    }

    /**
     * Un CREATE TABLE tal como lo da SHOW CREATE TABLE: sin comentarios sueltos, sin ; ni comillas dobles, sin nombres
     * calificados con base (`a`.`b`, a.b) y sin SELECT, DIRECTORY, DATABASE, SCHEMA, GRANT, USER, OUTFILE...
     * Una sola pasada de izquierda a derecha separa los nombres entre comillas invertidas y los textos, igual que el
     * servidor; lo que queda fuera de ellos es lo único que se revisa.
     */
    private static function crearTablaPermitido(string $sql): bool
    {
        if (strpbrk($sql, "\x02") !== false) return false;
        $t = preg_replace_callback('/`(?:[^`]|``)*+`|' . self::CADENA . '/s', fn($m) => $m[0][0] === '`' ? "\x02" : '0', $sql);
        if ($t === null) return false;
        // Comentarios con versión (/*!80016 DEFAULT ENCRYPTION='N' */, /*M!100301 ... */): el servidor los ejecuta como código, así que
        // su contenido se revisa como el resto. Cualquier otro comentario se rechaza.
        $t = preg_replace('~/\*M?!\d{5,6}([^*]*)\*/~', ' $1 ', $t);
        if ($t === null || preg_match('~[`\'";#\\\\]|--|/\*|\*/~', $t)) return false;
        if (preg_match('/\x02\s*\.|\.\s*\x02|\b[A-Za-z_]\w*\s*\.\s*[A-Za-z_]/', $t)) return false;     // nombres con base
        if (preg_match('/\b(?:SELECT|INSERT|DIRECTORY|DATABASE|SCHEMA|GRANT|REVOKE|USER|LOAD|OUTFILE|DUMPFILE|LOAD_FILE|SLEEP|BENCHMARK|USE|PROCEDURE|FUNCTION|TRIGGER|EVENT|INTO)\b/i', $t)) return false;
        return true;
    }

    /**
     * Las sentencias de un archivo .sql, una por una. Respeta cadenas con comillas (que pueden traer
     * punto y coma) y se salta las líneas de comentario. Pensado para el formato de respaldo del sistema
     * y para volcados parecidos a los de mysqldump.
     *
     * @return Generator<string>
     */
    public static function sentencias(string $archivo): Generator
    {
        $f = fopen($archivo, 'rb');
        if ($f === false) throw new Exception('No se pudo abrir el archivo.');
        $acum = '';
        $cadena = null;      // comilla que está abierta, o null
        try {
            while (($linea = fgets($f)) !== false) {
                if ($cadena === null && $acum === '') {
                    $t = ltrim($linea);
                    if ($t === '' || str_starts_with($t, '--') || str_starts_with($t, '#')) continue;
                }
                $n = strlen($linea);
                $i = 0; $desde = 0;
                while ($i < $n) {
                    if ($cadena === null) {
                        $i += strcspn($linea, "'\"`;", $i);
                        if ($i >= $n) break;
                        $c = $linea[$i];
                        if ($c === ';') {
                            $acum .= substr($linea, $desde, $i - $desde);
                            $sql = trim($acum);
                            if ($sql !== '') yield $sql;
                            $acum = ''; $desde = $i + 1; $i++;
                            // lo que queda de la línea después del ';' (si es otra sentencia en la misma línea)
                            continue;
                        }
                        $cadena = $c; $i++;
                    } else {
                        $i += strcspn($linea, $cadena === '`' ? '`' : $cadena . '\\', $i);
                        if ($i >= $n) break;
                        if ($linea[$i] === '\\') { $i += 2; continue; }
                        if (($linea[$i + 1] ?? '') === $cadena) { $i += 2; continue; }   // comilla doble ('')
                        $cadena = null; $i++;
                    }
                }
                $acum .= substr($linea, $desde);
            }
        } finally {
            fclose($f);
        }
        $resto = trim($acum);
        if ($resto !== '') yield $resto;
    }
}

/** El respaldo (o la restauración) no es de fiar: se cancela con este mensaje, que se muestra tal cual */
class RespaldoNoConfiable extends Exception
{
}

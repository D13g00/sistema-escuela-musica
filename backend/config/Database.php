<?php
/**
 * Conexión única (singleton) a MySQL/MariaDB vía PDO.
 *
 * Los datos de conexión de CADA computadora viven en config/credenciales.php,
 * que no viaja en las actualizaciones: así actualizar el sistema nunca pisa la
 * contraseña de la base de esa máquina. Si el archivo no existe se usan los
 * valores de un XAMPP recién instalado. Hay un modelo en
 * credenciales.ejemplo.php.
 */

class Database
{
    private static ?PDO $instancia = null;

    /** @var array<string,int> candados con nombre que esta conexión tiene tomados, y cuántas veces */
    private static array $candados = [];

    /** Lo de un XAMPP recién instalado; credenciales.php reemplaza lo que traiga */
    private const OMISION = [
        'host'          => '127.0.0.1',
        'puerto'        => '3306',
        'base'          => 'escuela_demo',
        'usuario'       => 'root',
        'password'      => '',
        // Modo estricto: un dato inválido se rechaza en lugar de guardarse
        // recortado o convertido a 0 sin avisar.
        'modo_estricto' => true,
        // La hora de la escuela. php.ini de XAMPP trae Europe/Berlin: sin esto,
        // lo que corre desde la Terminal fecharía con la hora de Europa.
        'zona_horaria'  => 'America/Mexico_City',
    ];

    /** Modo estricto de MySQL/MariaDB, igual en Windows y en Mac */
    private const SQL_MODE_ESTRICTO =
        'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    /** Los valores de un XAMPP recién instalado (lo que toma credenciales.php para lo que no diga) */
    public static function omision(): array
    {
        return self::OMISION;
    }

    /** Los datos de conexión de esta computadora */
    public static function credenciales(): array
    {
        $archivo = __DIR__ . '/credenciales.php';
        $propias = is_file($archivo) ? (require $archivo) : [];
        return (is_array($propias) ? $propias : []) + self::OMISION;
    }

    /**
     * Candado con nombre (GET_LOCK de MySQL) para lo que no debe correr dos
     * veces a la vez: el cierre de mes, una importación. Se suelta solo al
     * terminar la conexión, o con soltar().
     *
     * @param int $espera segundos que espera a que el otro termine; 0 = no espera
     */
    public static function candado(string $nombre, int $espera = 0): bool
    {
        // Reentrante a propósito: una operación que ya tiene el candado y llama a
        // otra que también lo pide (juntar -> completar, alta de grupo -> alta) no
        // se espera a sí misma ni lo suelta antes de tiempo. La cuenta de niveles
        // es de esta conexión; el servidor solo ve un GET_LOCK y un RELEASE_LOCK.
        $clave = 'escuela_' . $nombre;
        if ((self::$candados[$clave] ?? 0) > 0) {
            self::$candados[$clave]++;
            return true;
        }
        $s = self::conexion()->prepare("SELECT GET_LOCK(?, ?)");
        $s->execute([$clave, $espera]);
        $ok = (int) $s->fetchColumn() === 1;
        if ($ok) self::$candados[$clave] = 1;
        return $ok;
    }

    /** Suelta un nivel del candado; el de verdad se libera al soltar el último */
    public static function soltar(string $nombre): void
    {
        $clave = 'escuela_' . $nombre;
        $n = self::$candados[$clave] ?? 0;
        if ($n > 1) { self::$candados[$clave] = $n - 1; return; }
        unset(self::$candados[$clave]);
        self::conexion()->prepare("SELECT RELEASE_LOCK(?)")->execute([$clave]);
    }

    /** Cuántas veces esta conexión tiene tomado el candado (0 = libre para ella) */
    public static function nivelDeCandado(string $nombre): int
    {
        return self::$candados['escuela_' . $nombre] ?? 0;
    }

    /**
     * Abre una conexión nueva con la hora y el modo del sistema. Lanza PDOException si no se puede.
     *
     * @param int         $espera  segundos que espera a que el servidor conteste (0 = lo que traiga PHP).
     *                             Las herramientas de la Terminal usan pocos segundos: si MySQL está apagado
     *                             avisan enseguida en lugar de quedarse esperando.
     * @param string|null $base    otra base distinta a la de credenciales.php; '' = sin elegir ninguna
     *                             (para crear o borrar una base temporal)
     */
    public static function nueva(int $espera = 0, ?string $base = null): PDO
    {
        $c = self::credenciales();
        $nombre = $base ?? $c['base'];
        $dsn = "mysql:host={$c['host']};port={$c['puerto']};" . ($nombre !== '' ? "dbname={$nombre};" : '') . "charset=utf8mb4";
        $opciones = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        if ($espera > 0) $opciones[PDO::ATTR_TIMEOUT] = $espera;
        $pdo = new PDO($dsn, $c['usuario'], $c['password'], $opciones);

        // MySQL debe contar las fechas con la misma hora que PHP,
        // para que CURDATE() y date() nunca se contradigan.
        date_default_timezone_set($c['zona_horaria']);
        $desfase = (new DateTime('now', new DateTimeZone(date_default_timezone_get())))->format('P');
        $pdo->exec("SET time_zone = '$desfase'");

        if (!empty($c['modo_estricto'])) {
            $pdo->exec("SET SESSION sql_mode = '" . self::SQL_MODE_ESTRICTO . "'");
        }
        return $pdo;
    }

    public static function conexion(): PDO
    {
        if (self::$instancia === null) {
            try {
                self::$instancia = self::nueva();
            } catch (PDOException $e) {
                // El detalle (usuario, host, motivo) va al registro, no a la pantalla.
                error_log('Sistema Escuela: no se pudo conectar a la base de datos: ' . $e->getMessage());
                http_response_code(500);
                if (PHP_SAPI !== 'cli') header('Content-Type: application/json; charset=utf-8');
                die(json_encode(['ok' => false,
                    'error' => 'No se pudo conectar a la base de datos. Revisa que MySQL esté encendido en el panel de XAMPP.'],
                    JSON_UNESCAPED_UNICODE));
            }
        }
        return self::$instancia;
    }
}

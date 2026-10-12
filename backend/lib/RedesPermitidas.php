<?php
require_once __DIR__ . '/../config/Database.php';

/**
 * Equipos permitidos.
 *
 * En config/credenciales.php de CADA computadora puede ir la clave opcional
 *
 *     'redes_permitidas' => ['127.0.0.1', '::1', '100.64.0.0/10', 'fd7a:115c:a1e0::/48'],
 *
 * una lista de direcciones IP o rangos CIDR (IPv4 e IPv6). Si existe, toda petición a la API y a las
 * herramientas de mantenimiento que venga de una dirección que no esté en la lista recibe 403 con
 * "Este equipo no tiene acceso al sistema." (sin datos internos) y queda anotada en
 * escuela_registros/accesos_denegados.log. Si la clave no existe todo funciona como siempre.
 *
 * Si la clave existe pero NO es una lista (p. ej. 'redes_permitidas' => '127.0.0.1', escrita como texto) es una regla
 * ilegible: el sistema se CIERRA para todos y el registro explica por qué. Una lista vacía sí se ignora.
 *
 * Solo se mira REMOTE_ADDR, la dirección con la que el equipo llegó a Apache. Nunca se confía en
 * X-Forwarded-For ni en cabeceras parecidas: las manda quien quiera.
 *
 * Lo que NO cubre: los archivos de frontend/ (index.html, app.js, estilos) los sirve Apache sin pasar por
 * PHP, así que se pueden descargar desde cualquier equipo que llegue al servidor; no traen datos de la
 * escuela (todo lo que se ve sale de la API, que sí exige la lista). Para cerrarlos también, el mismo
 * rango puede ponerse en Apache (Require ip en el .htaccess).
 */
final class RedesPermitidas
{
    public const MENSAJE = 'Este equipo no tiene acceso al sistema.';

    /** Lo que se devuelve cuando redes_permitidas existe pero no es una lista: una regla que no coincide con nadie (se cierra) */
    public const REGLA_ILEGIBLE = '(redes_permitidas ilegible)';

    /** La dirección en bytes (4 para IPv4, 16 para IPv6). ::ffff:a.b.c.d se trata como la IPv4 a.b.c.d. */
    private static function empaquetar(string $ip): ?string
    {
        $ip = trim($ip);
        // Una IPv6 con zona ("fe80::1%en0") se evalúa sin la zona.
        if (($p = strpos($ip, '%')) !== false) $ip = substr($ip, 0, $p);
        $bin = @inet_pton($ip);
        if ($bin === false) return null;
        if (strlen($bin) === 16 && substr($bin, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") return substr($bin, 12);
        return $bin;
    }

    /** ¿Esa dirección cae en esa regla ("100.64.0.0/10", "::1", "192.168.1.20")? Una regla mal escrita no coincide con nada. */
    public static function coincide(string $ip, string $regla): bool
    {
        $a = self::empaquetar($ip);
        if ($a === null) return false;
        $regla = trim($regla);
        $prefijo = null;
        if (str_contains($regla, '/')) {
            [$regla, $p] = explode('/', $regla, 2);
            if ($p === '' || !ctype_digit($p)) return false;
            $prefijo = (int) $p;
        }
        $r = self::empaquetar($regla);
        if ($r === null || strlen($r) !== strlen($a)) return false;   // IPv4 contra IPv6 no se mezclan
        $max = strlen($r) * 8;
        // Una regla IPv6 escrita como ::ffff:a.b.c.d/N se cuenta sobre los 128 bits originales.
        if ($prefijo !== null && str_contains($regla, ':') && strlen($r) === 4 && $prefijo >= 96) $prefijo -= 96;
        $prefijo ??= $max;
        if ($prefijo < 0 || $prefijo > $max) return false;
        $bytes = intdiv($prefijo, 8);
        if ($bytes > 0 && substr($a, 0, $bytes) !== substr($r, 0, $bytes)) return false;
        $resto = $prefijo % 8;
        if ($resto === 0) return true;
        $mascara = (0xFF << (8 - $resto)) & 0xFF;
        return (ord($a[$bytes]) & $mascara) === (ord($r[$bytes]) & $mascara);
    }

    /** ¿Está la dirección en alguna de las reglas? */
    public static function dentro(string $ip, array $reglas): bool
    {
        foreach ($reglas as $regla) {
            if (is_string($regla) && self::coincide($ip, $regla)) return true;
        }
        return false;
    }

    /** ¿La clave redes_permitidas existe pero no es una lista (por ejemplo, escrita como texto)? Es una regla que no se puede leer. */
    public static function ilegible(?array $credenciales = null): bool
    {
        $c = $credenciales ?? Database::credenciales();
        return array_key_exists('redes_permitidas', $c) && $c['redes_permitidas'] !== null && !is_array($c['redes_permitidas']);
    }

    /**
     * La lista configurada, o null si no hay restricción. Una lista vacía se ignora (no deja a nadie afuera por un
     * error de dedo); una lista con reglas que ninguna se entiende deja afuera a todos. Si redes_permitidas existe
     * pero NO es una lista (p. ej. '127.0.0.1' escrito como texto) es una regla ilegible: se CIERRA (devuelve una sola
     * regla que no coincide con nadie) y exigir() lo anota en el registro con un mensaje claro.
     */
    public static function configuradas(?array $credenciales = null): ?array
    {
        $c = $credenciales ?? Database::credenciales();
        if (self::ilegible($c)) return [self::REGLA_ILEGIBLE];
        if (!isset($c['redes_permitidas']) || !$c['redes_permitidas']) return null;
        return array_values($c['redes_permitidas']);
    }

    /**
     * Se llama al arrancar toda petición web (Respuesta::prepararErrores). En la Terminal no hace nada.
     * Si el equipo no está permitido responde 403 y termina.
     *
     * @param bool $json true = respuesta de la API; false = página corta de las herramientas
     */
    public static function exigir(bool $json = true): void
    {
        if (PHP_SAPI === 'cli') return;
        $reglas = self::configuradas();
        if ($reglas === null) return;
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip !== '' && self::dentro($ip, $reglas)) return;

        self::anotar($ip, in_array(self::REGLA_ILEGIBLE, $reglas, true)
            ? ' (credenciales.php trae redes_permitidas que no es una lista: se cerró el acceso por seguridad; escríbela como lista, p. ej. ["127.0.0.1", "::1"])' : '');
        http_response_code(403);
        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        if ($json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => self::MENSAJE], JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><meta charset="utf-8"><title>Sin acceso</title>'
               . '<p style="font:16px system-ui;padding:40px;max-width:560px;margin:auto">' . self::MENSAJE . '</p>';
        }
        exit;
    }

    /** Una línea en escuela_registros/accesos_denegados.log (con tope de tamaño para que no crezca sin fin) */
    private static function anotar(string $ip, string $nota = ''): void
    {
        require_once __DIR__ . '/../config/Respuesta.php';
        $dir = Respuesta::carpetaPrivada('escuela_registros');
        if (!$dir) return;
        $archivo = $dir . DIRECTORY_SEPARATOR . 'accesos_denegados.log';
        if (is_file($archivo) && filesize($archivo) > 2 * 1024 * 1024) return;
        $ruta = substr(preg_replace('/[^\x20-\x7E]/', '?', (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH)), 0, 120);
        @file_put_contents($archivo, date('Y-m-d H:i:s') . ' acceso denegado desde ' . preg_replace('/[^0-9a-fA-F:.%]/', '?', $ip)
            . ' a ' . $ruta . $nota . "\n", FILE_APPEND | LOCK_EX);
    }
}

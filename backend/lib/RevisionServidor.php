<?php
/**
 * Piezas de la revisión del servidor (backend/herramientas/revisar_servidor.php) que leen texto y
 * deciden qué significa. Están aparte para poder probarlas con ejemplos sin tener el servidor delante.
 * Todo es de solo lectura: nada aquí cambia la computadora.
 */
final class RevisionServidor
{
    /**
     * Los archivos sin los cuales el sistema no arranca o no se ve completo. Si falta alguno, la carpeta se
     * copió a medias o se mezcló con otra versión.
     *
     * @return string[] rutas relativas a la carpeta del sistema
     */
    public static function archivosClave(): array
    {
        return [
            'index.html',
            'frontend/index.html', 'frontend/js/app.js', 'frontend/css/style.css',
            'backend/config/Database.php', 'backend/config/Auth.php', 'backend/config/Respuesta.php',
            'backend/config/Mantenimiento.php',
            'backend/lib/Bitacora.php', 'backend/lib/Calendario.php', 'backend/lib/LectorXlsx.php',
            'backend/lib/LectorZipSimple.php', 'backend/lib/Texto.php', 'backend/lib/Validar.php',
            'backend/actualizar_base.php', 'backend/comprobar.php',
        ];
    }

    /**
     * Archivos clave que no están en la carpeta, más los que algún archivo del sistema pide con require y
     * no existen (una carpeta mezclada de dos versiones suele fallar justo ahí).
     *
     * @return string[] rutas relativas de lo que falta
     */
    public static function faltantes(string $raiz): array
    {
        $raiz = rtrim(str_replace('\\', '/', $raiz), '/');
        $falta = [];
        foreach (self::archivosClave() as $rel) {
            if (!is_file($raiz . '/' . $rel)) $falta[$rel] = true;
        }
        $backend = $raiz . '/backend';
        if (is_dir($backend)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($backend, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') continue;
                $texto = (string) @file_get_contents($f->getPathname());
                if (!preg_match_all("/\\b(?:require|require_once|include|include_once)\\s*\\(?\\s*__DIR__\\s*\\.\\s*'([^']+)'/", $texto, $m)) continue;
                $dir = str_replace('\\', '/', $f->getPath());
                foreach ($m[1] as $destino) {
                    // Solo lo que apunta dentro del proyecto: lo demás no se puede juzgar desde aquí.
                    $ruta = self::normalizar($dir . $destino);
                    if (!str_starts_with($ruta, $raiz . '/')) continue;
                    if (!is_file($ruta)) $falta[ltrim(substr($ruta, strlen($raiz)), '/')] = true;
                }
            }
        }
        $lista = array_keys($falta);
        sort($lista);
        return $lista;
    }

    /** Resuelve los "../" de una ruta sin que el archivo tenga que existir */
    public static function normalizar(string $ruta): string
    {
        $partes = [];
        foreach (explode('/', str_replace('\\', '/', $ruta)) as $p) {
            if ($p === '' || $p === '.') { if ($partes === [] && $p === '') $partes[] = ''; continue; }
            if ($p === '..') { if (count($partes) > 1) array_pop($partes); continue; }
            $partes[] = $p;
        }
        return implode('/', $partes);
    }

    /** La versión que declara frontend/js/app.js (const VERSION = '5.14';) */
    public static function versionDeApp(string $js): ?string
    {
        return preg_match("/\\bconst\\s+VERSION\\s*=\\s*'([^']+)'/", $js, $m) ? $m[1] : null;
    }

    /** La versión que espera backend/comprobar.php (const VERSION_ESPERADA = '5.14';) */
    public static function versionEsperada(string $php): ?string
    {
        return preg_match("/\\bconst\\s+VERSION_ESPERADA\\s*=\\s*'([^']+)'/", $php, $m) ? $m[1] : null;
    }

    /** La versión con que index.html pide app.js (js/app.js?v=5.14) */
    public static function versionDeIndex(string $html): ?string
    {
        return preg_match('/js\/app\.js\?v=([0-9][0-9.]*)/', $html, $m) ? $m[1] : null;
    }

    /**
     * Minutos que tarda la Mac en suspenderse estando conectada a la corriente (0 = nunca), según la salida
     * de `pmset -g custom` (la sección "AC Power") o de `pmset -g` (lo que rige ahora). Null si no se entiende.
     */
    public static function minutosParaSuspender(string $salida): ?int
    {
        $bloque = $salida;
        if (preg_match('/AC Power:\s*(.*?)(?:\n[A-Za-z][^\n]*:\s*\n|\z)/s', $salida, $m)) $bloque = $m[1];
        return preg_match('/^\s*sleep\s+(\d+)/m', $bloque, $m) ? (int) $m[1] : null;
    }

    /**
     * El valor de bind-address en un my.cnf / my.ini (null si no lo fija). Ignora comentarios y mira
     * solo las secciones del servidor ([mysqld], [mariadbd], [server]).
     */
    public static function bindAddressDe(string $texto): ?string
    {
        $seccion = ''; $valor = null;
        foreach (preg_split('/\R/', $texto) as $linea) {
            $linea = trim($linea);
            if ($linea === '' || $linea[0] === '#' || $linea[0] === ';') continue;
            if (preg_match('/^\[([^\]]+)\]/', $linea, $m)) { $seccion = strtolower(trim($m[1])); continue; }
            if (!in_array($seccion, ['mysqld', 'mariadbd', 'server', 'mysqld_safe_no'], true)) continue;
            if (preg_match('/^bind[-_]address\s*=\s*"?([^"\s#;]+)"?/i', $linea, $m)) $valor = $m[1];
            if (preg_match('/^skip[-_]networking\s*$/i', $linea)) $valor = 'skip-networking';
        }
        return $valor;
    }

    /** ¿Escucha solo en esta computadora? */
    public static function esLocal(?string $bind): bool
    {
        return in_array(strtolower((string) $bind), ['127.0.0.1', 'localhost', '::1', 'skip-networking'], true);
    }

    /**
     * Cómo está configurado phpMyAdmin (config.inc.php): ['auth_type' => ..., 'usuario' => ..., 'sin_clave' => bool].
     * Peligroso es auth_type 'config' (entra solo, sin pedir nada) con root y sin contraseña.
     */
    public static function configPhpMyAdmin(string $texto): array
    {
        $sinComentarios = preg_replace(['#/\*.*?\*/#s', '#^\s*(//|\#).*$#m'], '', $texto);
        $auth = preg_match("/\\['auth_type'\\]\\s*=\\s*'([^']*)'/", $sinComentarios, $m) ? $m[1] : null;
        $user = preg_match("/\\['user'\\]\\s*=\\s*'([^']*)'/", $sinComentarios, $m) ? $m[1] : null;
        $sinClave = preg_match("/\\['password'\\]\\s*=\\s*''/", $sinComentarios) === 1
                 || !preg_match("/\\['password'\\]\\s*=/", $sinComentarios);
        return ['auth_type' => $auth, 'usuario' => $user, 'sin_clave' => $sinClave];
    }

    /**
     * Cuándo fue el último respaldo bueno que anotó el script de respaldo externo (línea "AAAA-MM-DD HH:MM:SS OK ..." del
     * respaldo.log), como marca de tiempo; null si no hay ninguno.
     */
    public static function ultimoRespaldoOk(string $log): ?int
    {
        $ultimo = null;
        foreach (preg_split('/\R/', $log) as $l) {
            if (preg_match('/^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d) OK /', $l, $m)) $ultimo = strtotime($m[1]) ?: $ultimo;
        }
        return $ultimo;
    }

    /** ¿phpMyAdmin deja entrar a cualquiera como root? */
    public static function phpMyAdminAbierto(array $cfg): bool
    {
        return $cfg['auth_type'] === 'config' && ($cfg['usuario'] ?? 'root') === 'root' && $cfg['sin_clave'];
    }

    /**
     * Pide solo la cabecera de una dirección con plazos cortos. Devuelve el código HTTP o null si no
     * contesta a tiempo (Apache apagado, puerto equivocado). Nunca se queda esperando.
     */
    public static function codigoHttp(string $host, int $puerto, string $ruta, int $segundos = 2): ?int
    {
        $errno = 0; $err = '';
        $f = @fsockopen($host, $puerto, $errno, $err, $segundos);
        if (!$f) return null;
        stream_set_timeout($f, $segundos);
        fwrite($f, "HEAD {$ruta} HTTP/1.0\r\nHost: {$host}\r\nConnection: close\r\n\r\n");
        $primera = (string) fgets($f, 256);
        $meta = stream_get_meta_data($f);
        fclose($f);
        if (!empty($meta['timed_out'])) return null;
        return preg_match('#^HTTP/\S+\s+(\d{3})#', $primera, $m) ? (int) $m[1] : null;
    }

    /**
     * Pasos de la actualización de la base que faltan, corriendo `actualizar_base.php` en modo revisión (no
     * aplica nada) en otro proceso y con plazo.
     *
     * @return array|null ['pendientes' => int, 'errores' => int], o null si no se pudo revisar
     */
    public static function pasosPendientes(string $actualizarBase, int $segundos = 30): ?array
    {
        if (!is_file($actualizarBase) || PHP_BINARY === '') return null;
        $p = @proc_open([PHP_BINARY, $actualizarBase], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubos, dirname($actualizarBase));
        if (!is_resource($p)) return null;
        fclose($tubos[0]);
        stream_set_blocking($tubos[1], false);
        $salida = ''; $fin = time() + $segundos; $terminado = false;
        while (time() < $fin) {
            $salida .= (string) stream_get_contents($tubos[1]);
            $estado = proc_get_status($p);
            if (!$estado['running']) { $salida .= (string) stream_get_contents($tubos[1]); $terminado = true; break; }
            usleep(100000);
        }
        if (!$terminado) { proc_terminate($p); }
        fclose($tubos[1]); fclose($tubos[2]); proc_close($p);
        if (!$terminado || !str_contains($salida, 'class="')) return null;
        return ['pendientes' => substr_count($salida, '<li class="pendiente"'), 'errores' => substr_count($salida, '<li class="error"')];
    }
}

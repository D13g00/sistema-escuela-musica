<?php
/**
 * Asegura la base de datos: crea un usuario propio del sistema con permisos SOLO sobre su base y deja
 * backend/config/credenciales.php usándolo, en lugar de entrar como root sin contraseña.
 *
 * SOLO desde la Terminal de la computadora servidor (no se abre desde el navegador):
 *
 *   Mac:      cd /Applications/XAMPP/xamppfiles/htdocs/escuela-musica/backend/herramientas
 *             /Applications/XAMPP/xamppfiles/bin/php asegurar_base.php
 *   Windows:  cd C:\xampp\htdocs\escuela-musica\backend\herramientas
 *             C:\xampp\php\php.exe asegurar_base.php
 *
 * Qué hace (y se puede correr las veces que haga falta, es idempotente):
 *   1. Entra a MariaDB como root (sin contraseña; si root ya tiene una, usa --root-clave-stdin y se
 *      escribe cuando la pide, sin que se vea). Antes de crear nada revisa que la tabla de permisos de
 *      MariaDB (mysql.db) no esté dañada; si lo está, se detiene y explica cómo repararla.
 *   2. Crea el usuario escuela@localhost y escuela@127.0.0.1 con una contraseña aleatoria de 48 caracteres
 *      y le da permisos SOLO sobre la base del sistema (leer, escribir, crear y cambiar tablas, índices,
 *      llaves foráneas, tablas temporales y bloqueos: lo que usan el sistema, el actualizador de la
 *      base y los respaldos) y sobre las bases temporales de la verificación de respaldos
 *      (escuela_demo_verif_...). Nada sobre otras bases, ni sobre mysql, ni permisos de administración.
 *   3. Escribe usuario y contraseña en credenciales.php (conserva lo demás que ya tuviera, como
 *      redes_permitidas). El archivo queda con permisos 0644, como el resto de htdocs: Apache (en la Mac
 *      corre como el usuario "daemon") tiene que poder leerlo; con 0640 el sistema dejaría de abrir.
 *   4. Prueba la conexión nueva ANTES de confirmar. Si no funciona, no deja credenciales.php cambiado.
 *   5. Comprobación real por web: pide http://localhost/<carpeta>/backend/api/auth.php?accion=estado (3 s) y
 *      exige una respuesta JSON del sistema. Si Apache no la da, vuelve a dejar el credenciales.php anterior (y, con
 *      --rotar, la contraseña anterior en MariaDB) y explica qué pasó. Si Apache está apagado, avisa que NO se
 *      pudo comprobar por web (sale con código 3: hecho, pero sin esa comprobación).
 *   La contraseña nunca se imprime: solo queda en credenciales.php.
 *   Si el usuario ya existe y credenciales.php ya funciona con él, no cambia nada ("Ya estaba").
 *
 * Opciones:
 *   --rotar              genera una contraseña nueva y la pone (el usuario y sus permisos se conservan)
 *   --root-clave-stdin   lee la contraseña de root de la entrada estándar
 *   --usuario=NOMBRE     el usuario a crear (por omisión escuela)
 *   --base=NOMBRE        la base (por omisión la de credenciales.php)
 *   --archivo=RUTA       otro credenciales.php (para pruebas; con él no hay comprobación por web, salvo que se dé --url)
 *   --root=NOMBRE        el usuario administrador de MariaDB (por omisión root)
 *   --url=DIRECCION      la dirección del sistema para la comprobación por web: la carpeta (http://localhost/escuela-musica)
 *                        o la página completa. Por omisión se deduce de dónde está esta carpeta respecto a htdocs.
 *
 * Códigos de salida: 0 todo bien · 1 no se pudo (o se deshizo) · 2 no pude entrar a MariaDB / opción mala ·
 * 3 hecho, pero sin poder comprobar por web.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config/Database.php';

/** Lo que el sistema necesita sobre su base */
const PERMISOS_BASE = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'INDEX', 'DROP', 'REFERENCES',
                       'CREATE TEMPORARY TABLES', 'LOCK TABLES'];
const HOSTS = ['localhost', '127.0.0.1'];
/** Permisos del credenciales.php: Apache (en la Mac, el usuario daemon) debe poder leerlo */
const PERMISOS_ARCHIVO = 0644;

/** Un nombre de base o de usuario escrito en un patrón de GRANT: los guiones bajos son comodines si no se escapan */
function patron(string $nombre): string { return str_replace('_', '\\_', $nombre); }

/** Una contraseña aleatoria fuerte (48 caracteres hexadecimales = 192 bits) */
function contrasenaNueva(): string { return bin2hex(random_bytes(24)); }

/** Lee una línea de la entrada estándar; sin eco si es una Terminal de Mac/Linux */
function leerOculto(string $pregunta): string
{
    fwrite(STDERR, $pregunta);
    $oculta = DIRECTORY_SEPARATOR === '/' && function_exists('stream_isatty') && stream_isatty(STDIN) && @shell_exec('stty -echo 2>/dev/null') !== null;
    $r = rtrim((string) fgets(STDIN), "\r\n");
    if ($oculta) { @shell_exec('stty echo 2>/dev/null'); fwrite(STDERR, "\n"); }
    return $r;
}

/** Escribe un arreglo como el contenido de un credenciales.php (valores simples y listas de textos) */
function exportar(array $datos): string
{
    $lineas = [];
    foreach ($datos as $k => $v) {
        if (is_array($v)) $valor = '[' . implode(', ', array_map(fn($x) => var_export($x, true), $v)) . ']';
        else $valor = var_export($v, true);
        $lineas[] = '    ' . var_export($k, true) . ' => ' . $valor . ',';
    }
    return implode("\n", $lineas);
}

function salir(int $codigo, string $mensaje): void
{
    fwrite($codigo === 0 ? STDOUT : STDERR, $mensaje . "\n");
    exit($codigo);
}

/** Abre una conexión con plazo corto */
function conectar(array $c, string $usuario, string $clave, ?string $base): PDO
{
    $dsn = "mysql:host={$c['host']};port={$c['puerto']};" . ($base ? "dbname={$base};" : '') . 'charset=utf8mb4';
    return new PDO($dsn, $usuario, $clave, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3, PDO::ATTR_EMULATE_PREPARES => false]);
}

/** Los permisos de una cuenta, como texto ordenado (para saber si algo cambió) */
function permisosDe(PDO $root, string $usuario, string $host): ?string
{
    try {
        $filas = $root->query('SHOW GRANTS FOR ' . $root->quote($usuario) . '@' . $root->quote($host))->fetchAll(PDO::FETCH_NUM);
    } catch (PDOException $e) {
        if (esTablaDanada($e)) throw $e;       // no es "la cuenta no existe": es la tabla de permisos rota
        return null;      // la cuenta no existe
    }
    $t = array_map(fn($f) => preg_replace('/IDENTIFIED BY PASSWORD \'[^\']*\'/', 'IDENTIFIED BY PASSWORD ...', (string) $f[0]), $filas);
    sort($t);
    return implode("\n", $t);
}

/** El hash de la contraseña de una cuenta (el que muestra SHOW GRANTS), para poder devolverla a como estaba; null si no se pudo leer; '' si no tenía */
function hashDe(PDO $root, string $usuario, string $host): ?string
{
    try {
        foreach ($root->query('SHOW GRANTS FOR ' . $root->quote($usuario) . '@' . $root->quote($host))->fetchAll(PDO::FETCH_NUM) as $f) {
            if (preg_match('/IDENTIFIED BY PASSWORD \'([^\']*)\'/', (string) $f[0], $m)) return $m[1];
            if (preg_match('/IDENTIFIED VIA/i', (string) $f[0])) return null;      // otro método de acceso: no se puede devolver con un hash
        }
        return '';
    } catch (PDOException $e) {
        return null;
    }
}

/** ¿El error es de una tabla de MariaDB dañada (crashed / corrupt; códigos 144, 145, 126, 1034, 1194, 1195)? */
function esTablaDanada(Throwable $e): bool
{
    $codigo = $e instanceof PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;
    if (in_array($codigo, [126, 130, 144, 145, 1034, 1194, 1195], true)) return true;
    return (bool) preg_match('/crashed|corrupt|should be repaired|Incorrect key file|Incorrect file format|marked as crashed/i', $e->getMessage());
}

/** Lo que se le dice a quien tiene la tabla de permisos de MariaDB dañada */
function mensajeTablaDanada(array $c, string $root, ?string $detalle): string
{
    $mac = '/Applications/XAMPP/xamppfiles/bin/mysqlcheck';
    $win = 'C:\\xampp\\mysql\\bin\\mysqlcheck.exe';
    $m = "✕ La tabla de permisos de MariaDB está dañada. Repárala con: $mac -u$root --repair mysql "
       . "(en Windows: $win -u$root --repair mysql) y vuelve a correr esto.\n"
       . '  No se creó ni se cambió nada.';
    if ($detalle) $m .= "\n  (MariaDB dice: " . mb_substr(preg_replace('/\s+/', ' ', $detalle), 0, 160) . ')';
    // Si credenciales.php ya trae otro usuario que funciona, el sistema no está caído
    $u = (string) ($c['usuario'] ?? '');
    if ($u !== '' && $u !== $root) {
        try {
            conectar($c, $u, (string) ($c['password'] ?? ''), (string) $c['base'])->query('SELECT 1');
            $m .= "\n  Buena noticia: credenciales.php ya trae el usuario $u y funciona con la base; el sistema sigue abriendo. La reparación puede esperar a un momento tranquilo.";
        } catch (Throwable $e) {
            $m .= "\n  credenciales.php trae el usuario $u, pero tampoco logra entrar a la base ahora.";
        }
    }
    return $m;
}

/** CHECK TABLE mysql.db: null si está bien (o no se pudo revisar por falta de permiso); si no, lo que dijo MariaDB */
function revisarTablaDePermisos(PDO $admin): ?string
{
    try {
        $filas = $admin->query('CHECK TABLE mysql.db')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return esTablaDanada($e) ? $e->getMessage() : null;
    }
    foreach ($filas as $f) {
        $tipo = strtolower((string) ($f['Msg_type'] ?? ''));
        $texto = (string) ($f['Msg_text'] ?? '');
        if ($tipo === 'error' || preg_match('/crashed|corrupt|should be repaired|Incorrect key file|Incorrect file format/i', $texto)) return $texto;
    }
    return null;
}

/** La carpeta del sistema dentro de htdocs ("escuela-musica"), o null si el proyecto no está dentro de una carpeta htdocs */
function carpetaEnHtdocs(string $raiz): ?string
{
    $ruta = str_replace('\\', '/', $raiz);
    return preg_match('#/htdocs/(.+?)/*$#i', $ruta, $m) ? $m[1] : null;
}

/** La dirección que se pide para comprobar que Apache puede usar credenciales.php */
function direccionDeComprobacion(?string $opcion, string $raiz): ?string
{
    $pagina = '/backend/api/auth.php?accion=estado';
    if ($opcion !== null && $opcion !== '') {
        if (!preg_match('#^https?://#i', $opcion)) return null;
        return str_contains($opcion, '.php') ? $opcion : rtrim($opcion, '/') . $pagina;
    }
    $carpeta = carpetaEnHtdocs($raiz);
    if ($carpeta === null) return null;
    return 'http://localhost/' . implode('/', array_map('rawurlencode', explode('/', $carpeta))) . $pagina;
}

/**
 * Pide la dirección con plazo de 3 segundos.
 *
 * @return array ['estado' => 'ok'|'mal'|'apagado'|'no_comprobable', 'detalle' => string]
 *   ok = respondió JSON del sistema · mal = respondió otra cosa (credenciales ilegibles para Apache, error de PHP, otra página)
 *   apagado = no contestó (Apache apagado, otro puerto) · no_comprobable = el sistema contestó 403 (este equipo no está en redes_permitidas)
 */
function comprobarWeb(string $url): array
{
    $texto = false; $codigo = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
        $r = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($r !== false && $codigo > 0) $texto = (string) $r;
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true, 'header' => "Accept: application/json\r\nConnection: close\r\n"]]);
        $r = @file_get_contents($url, false, $ctx);
        if ($r !== false) {
            $texto = $r;
            if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) $codigo = (int) $m[1];
        }
    }
    if ($texto === false) return ['estado' => 'apagado', 'detalle' => 'no contestó en 3 segundos'];
    $json = json_decode($texto, true);
    if ($codigo === 403 && is_array($json) && str_contains((string) ($json['error'] ?? ''), 'no tiene acceso')) {
        return ['estado' => 'no_comprobable', 'detalle' => 'El sistema contestó 403: esta computadora (localhost) no está en redes_permitidas'];
    }
    if (is_array($json) && ($json['ok'] ?? null) === true) return ['estado' => 'ok', 'detalle' => "respondió JSON (HTTP $codigo)"];
    return ['estado' => 'mal', 'detalle' => "HTTP $codigo y " . (is_array($json) ? 'JSON con error' : 'una respuesta que no es JSON')];
}

// ------------------------------------------------------------------------------------------ argumentos
$usuario = 'escuela'; $base = null; $archivo = __DIR__ . '/../config/credenciales.php'; $rotar = false; $claveStdin = false; $root = 'root';
$archivoDado = false; $urlOpcion = null;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--rotar') $rotar = true;
    elseif ($a === '--root-clave-stdin') $claveStdin = true;
    elseif (preg_match('/^--usuario=(.+)$/', $a, $m)) $usuario = $m[1];
    elseif (preg_match('/^--base=(.+)$/', $a, $m)) $base = $m[1];
    elseif (preg_match('/^--archivo=(.+)$/', $a, $m)) { $archivo = $m[1]; $archivoDado = true; }
    elseif (preg_match('/^--root=(.+)$/', $a, $m)) $root = $m[1];
    elseif (preg_match('/^--url=(.+)$/', $a, $m)) $urlOpcion = $m[1];
    else salir(2, "Opción desconocida: $a");
}
if (!preg_match('/^[A-Za-z0-9_]{1,32}$/', $usuario)) salir(2, 'El usuario lleva de 1 a 32 letras, números o guion bajo.');

$propias = is_file($archivo) ? (require $archivo) : [];
if (!is_array($propias)) $propias = [];
$c = $propias + Database::omision();
$base ??= (string) $c['base'];
if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $base)) salir(2, 'El nombre de la base solo lleva letras, números o guion bajo.');
if ($usuario === 'root') salir(2, 'La idea es dejar de usar root: elige otro nombre de usuario.');

// ¿Se comprobará por web? Con el credenciales.php del propio sistema, sí; con --archivo (pruebas) solo si se da --url.
$urlWeb = null;
if (!$archivoDado || $urlOpcion !== null) $urlWeb = direccionDeComprobacion($urlOpcion, dirname(__DIR__, 2));
if ($urlOpcion !== null && $urlWeb === null) salir(2, 'La opción --url lleva una dirección que empieza con http:// o https://');

echo "Sistema Escuela — asegurar la base de datos\n";
echo "Base: $base · Usuario del sistema: $usuario · Archivo: " . realpath($archivo) . (is_file($archivo) ? '' : ' (todavía no existe)') . "\n\n";

// ------------------------------------------------------------------------------------------ root
$claveRoot = $claveStdin ? leerOculto("Contraseña de $root (no se ve al escribir): ") : '';
try {
    $admin = conectar($c, $root, $claveRoot, null);
} catch (PDOException $e) {
    $m = $e->getMessage();
    if (preg_match('/Access denied|1045/i', $m)) {
        salir(2, "✕ No pude entrar a MariaDB como $root " . ($claveStdin ? 'con esa contraseña.' : 'sin contraseña. Si root ya tiene contraseña, corre otra vez con --root-clave-stdin.'));
    }
    if (esTablaDanada($e)) salir(1, mensajeTablaDanada($c, $root, $m));
    salir(2, '✕ No pude conectarme a MariaDB (' . mb_substr(preg_replace('/\s+/', ' ', $m), 0, 160) . '). ¿Está encendido MySQL en el panel de XAMPP?');
}
// Antes de crear nada: ¿la tabla de permisos de MariaDB está sana?
if (($danada = revisarTablaDePermisos($admin)) !== null) salir(1, mensajeTablaDanada($c, $root, $danada));
try {
    $existeBase = $admin->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $existeBase->execute([$base]);
    if (!(int) $existeBase->fetchColumn()) salir(1, "✕ La base $base no existe. No se cambió nada.");

    // -------------------------------------------------------------------------------------- ¿ya estaba?
    $permisosAntes = [];
    foreach (HOSTS as $h) $permisosAntes[$h] = permisosDe($admin, $usuario, $h);
} catch (PDOException $e) {
    if (esTablaDanada($e)) salir(1, mensajeTablaDanada($c, $root, $e->getMessage()));
    throw $e;
}
$funciona = false;
if (!$rotar && ($c['usuario'] ?? '') === $usuario && (string) ($c['password'] ?? '') !== '' && $permisosAntes['localhost'] !== null && $permisosAntes['127.0.0.1'] !== null) {
    try { conectar($c, $usuario, (string) $c['password'], $base)->query('SELECT 1'); $funciona = true; } catch (PDOException $e) { $funciona = false; }
}

$crearCuentas = function (string $clave) use ($admin, $usuario) {
    foreach (HOSTS as $h) {
        $cuenta = $admin->quote($usuario) . '@' . $admin->quote($h);
        $admin->exec("CREATE USER IF NOT EXISTS $cuenta IDENTIFIED BY " . $admin->quote($clave));
        if ($clave !== '') $admin->exec("ALTER USER $cuenta IDENTIFIED BY " . $admin->quote($clave));
    }
};
$darPermisos = function () use ($admin, $usuario, $base) {
    foreach (HOSTS as $h) {
        $cuenta = $admin->quote($usuario) . '@' . $admin->quote($h);
        $admin->exec('GRANT ' . implode(', ', PERMISOS_BASE) . ' ON `' . patron($base) . '`.* TO ' . $cuenta);
        // La verificación de respaldos crea y borra su base temporal: solo las que se llaman escuela_demo_verif_...
        $admin->exec('GRANT ALL PRIVILEGES ON `' . patron('escuela_demo_verif_') . '%`.* TO ' . $cuenta);
    }
};

if ($funciona) {
    try {
        $darPermisos();       // idempotente: si faltaba algún permiso, se completa sin tocar la contraseña
        $despues = [];
        foreach (HOSTS as $h) $despues[$h] = permisosDe($admin, $usuario, $h);
    } catch (PDOException $e) {
        if (esTablaDanada($e)) salir(1, mensajeTablaDanada($c, $root, $e->getMessage()));
        throw $e;
    }
    // Una versión anterior de esta herramienta dejaba el archivo en 0640 y Apache (otro usuario en la Mac) dejaba de poder leerlo.
    $arreglado = '';
    $modo = is_file($archivo) ? (@fileperms($archivo) & 0777) : null;
    if ($modo !== null && DIRECTORY_SEPARATOR === '/' && ($modo & 0044) !== 0044) {
        if (@chmod($archivo, PERMISOS_ARCHIVO)) $arreglado = sprintf(' Se corrigieron los permisos de credenciales.php (estaban en %04o, ahora 0644) para que Apache lo pueda leer.', $modo);
    }
    $cola = '';
    if ($urlWeb !== null) {
        $web = comprobarWeb($urlWeb);
        if ($web['estado'] === 'mal') {
            salir(1, "✕ La conexión funciona, pero Apache no pudo usar credenciales.php ({$web['detalle']} al pedir $urlWeb). Revisa que sea legible para Apache (permisos 644) y que la dirección sea la del sistema (--url=).$arreglado");
        }
        $cola = $web['estado'] === 'ok' ? " Comprobado por web: el sistema contesta." : " ! NO se pudo comprobar por web ({$web['detalle']}).";
    }
    $salida = $despues === $permisosAntes
        ? "✓ Ya estaba: $usuario existe, tiene sus permisos y credenciales.php ya funciona con él. No se cambió nada."
        : "✓ $usuario ya funcionaba; se completaron sus permisos sobre $base. La contraseña no cambió.";
    if ($arreglado) $salida = str_replace(' No se cambió nada.', '', $salida);
    salir(isset($web) && in_array($web['estado'], ['apagado', 'no_comprobable'], true) ? 3 : 0, $salida . $arreglado . $cola);
}

// ------------------------------------------------------------------------------------------ preparar credenciales.php
// Todo lo que puede fallar al ESCRIBIR se comprueba antes de tocar la base: así no queda una contraseña cambiada en MariaDB
// sin que el archivo la tenga. El archivo nuevo se deja escrito (como .nueva) antes del ALTER USER.
$clave = contrasenaNueva();
$nuevo = ['usuario' => $usuario, 'password' => $clave] + $propias;
if ($base !== ($propias['base'] ?? Database::omision()['base'])) $nuevo['base'] = $base;
$orden = ['host', 'puerto', 'base', 'usuario', 'password'];
$ordenado = [];
foreach ($orden as $k) if (array_key_exists($k, $nuevo)) $ordenado[$k] = $nuevo[$k];
foreach ($nuevo as $k => $v) if (!array_key_exists($k, $ordenado)) $ordenado[$k] = $v;
$texto = "<?php\n// Conexión de ESTA computadora a la base de datos. La escribió backend/herramientas/asegurar_base.php el "
       . date('Y-m-d H:i') . ".\n// Trae la contraseña de la base: no la compartas ni la subas a ningún lado. No viaja en las actualizaciones.\nreturn [\n"
       . exportar($ordenado) . "\n];\n";
$temporal = $archivo . '.nueva';
$carpeta = dirname($archivo);
if (!is_dir($carpeta)) @mkdir($carpeta, 0755, true);
if (is_dir($archivo)) salir(1, "✕ $archivo es una carpeta, no un archivo. No se cambió nada (ni en MariaDB ni en credenciales.php).");
if ((is_file($archivo) && !is_writable($archivo)) || !is_dir($carpeta) || !is_writable($carpeta)) {
    salir(1, "✕ No puedo escribir en $archivo (revisa los permisos de esa carpeta y archivo). No se cambió nada, ni siquiera la contraseña en MariaDB.");
}
if (@file_put_contents($temporal, $texto) === false) {
    salir(1, "✕ No pude escribir $temporal. No se cambió nada, ni siquiera la contraseña en MariaDB.");
}
@chmod($temporal, PERMISOS_ARCHIVO);
$contenidoAnterior = is_file($archivo) ? file_get_contents($archivo) : null;
$permisosArchivoAnterior = is_file($archivo) ? (@fileperms($archivo) & 0777) : null;
$hashesAnteriores = [];
foreach (HOSTS as $h) $hashesAnteriores[$h] = $permisosAntes[$h] === null ? null : hashDe($admin, $usuario, $h);

/** Vuelve todo a como estaba: el credenciales.php anterior y, si la cuenta ya existía, su contraseña anterior en MariaDB */
$deshacer = function () use ($archivo, $temporal, $contenidoAnterior, $permisosArchivoAnterior, $admin, $usuario, $hashesAnteriores): string {
    $notas = [];
    @unlink($temporal);
    if ($contenidoAnterior !== null) {
        if (@file_put_contents($archivo, $contenidoAnterior) === false) $notas[] = "No pude devolver $archivo a como estaba: restáuralo a mano";
        elseif ($permisosArchivoAnterior !== null) @chmod($archivo, $permisosArchivoAnterior);
    } elseif (is_file($archivo)) {
        @unlink($archivo);
    }
    foreach ($hashesAnteriores as $h => $hash) {
        if ($hash === null) continue;                 // la cuenta no existía antes (o no se pudo leer su contraseña)
        try {
            $cuenta = $admin->quote($usuario) . '@' . $admin->quote($h);
            $admin->exec("ALTER USER $cuenta IDENTIFIED BY PASSWORD " . $admin->quote($hash));
        } catch (Throwable $e) {
            $notas[] = "No pude devolver la contraseña anterior de $usuario@$h en MariaDB (corre otra vez con --rotar)";
        }
    }
    return $notas ? ' ¡Ojo! ' . implode('. ', $notas) . '.' : '';
};

// ------------------------------------------------------------------------------------------ crear o rotar
try {
    $crearCuentas($clave);
    $darPermisos();
    // Se prueba la conexión nueva ANTES de tocar credenciales.php.
    $prueba = conectar($c, $usuario, $clave, $base);
    $prueba->query('SELECT COUNT(*) FROM information_schema.TABLES')->fetchColumn();
    $prueba->exec('CREATE TEMPORARY TABLE _escuela_prueba_permisos (x INT)');
    $prueba->exec('DROP TEMPORARY TABLE _escuela_prueba_permisos');
} catch (Throwable $e) {
    $nota = $deshacer();
    if (esTablaDanada($e)) salir(1, mensajeTablaDanada($c, $root, $e->getMessage()));
    salir(1, '✕ No se pudo dejar el usuario funcionando (' . mb_substr(str_replace($clave, '***', preg_replace('/\s+/', ' ', $e->getMessage())), 0, 200)
        . '). credenciales.php no se cambió.' . $nota);
}

// ------------------------------------------------------------------------------------------ credenciales.php
if (is_file($archivo)) @unlink($archivo);
if (!@rename($temporal, $archivo)) {
    $nota = $deshacer();
    salir(1, "✕ No pude dejar $archivo en su lugar. Se devolvió todo a como estaba (credenciales.php y la contraseña en MariaDB)." . $nota);
}
@chmod($archivo, PERMISOS_ARCHIVO);

// Comprobación final: leyendo el archivo otra vez, ya con lo que quedó escrito
$leido = (require $archivo) + Database::omision();
try {
    conectar($leido, (string) $leido['usuario'], (string) $leido['password'], (string) $leido['base'])->query('SELECT 1');
} catch (PDOException $e) {
    $nota = $deshacer();
    salir(1, '✕ El archivo quedó escrito pero la conexión con lo que dice no funciona. Se devolvió todo a como estaba.' . $nota);
}

// Comprobación real por web: Apache tiene que poder leer y usar el archivo (en la Mac corre como otro usuario, "daemon")
$web = null;
if ($urlWeb !== null) {
    $web = comprobarWeb($urlWeb);
    if ($web['estado'] === 'mal') {
        $nota = $deshacer();
        salir(1, "✕ Apache no pudo usar el credenciales.php nuevo ({$web['detalle']} al pedir $urlWeb). Se dejó el credenciales.php anterior"
            . (array_filter($hashesAnteriores, fn($x) => $x !== null) ? ' y la contraseña anterior de ' . $usuario . ' en MariaDB' : '')
            . ". Revisa que el archivo sea legible para Apache (permisos 644) y que la dirección sea la del sistema (--url=).$nota");
    }
}

echo ($permisosAntes['localhost'] === null ? "✓ Se creó el usuario $usuario" : "✓ Se puso una contraseña nueva a $usuario") . " para localhost y 127.0.0.1.\n";
echo "✓ Permisos: " . implode(', ', PERMISOS_BASE) . " sobre $base, y todo sobre las bases temporales escuela_demo_verif_...\n";
echo "✓ Nada sobre otras bases ni sobre la administración de MariaDB.\n";
echo "✓ credenciales.php ahora usa $usuario (contraseña de 48 caracteres, solo guardada ahí; no se muestra). Permisos 644: Apache lo puede leer.\n";
echo "✓ Probado: el sistema se conecta con el usuario nuevo.\n";
if ($web === null) {
    echo "! No se comprobó por web" . ($archivoDado ? ' (se usó --archivo)' : ' (esta carpeta no está dentro de htdocs: da la dirección con --url=http://localhost/CARPETA)') . ".\n";
    echo "\nSiguiente paso recomendado: ponle contraseña a root (phpMyAdmin > Cuentas de usuario) y, si lo haces, no hace falta cambiar nada en el sistema.\n";
    exit($archivoDado ? 0 : 3);
}
if ($web['estado'] === 'ok') {
    echo "✓ Comprobado por web: Apache lee el archivo y el sistema contesta ($urlWeb).\n";
    echo "\nSiguiente paso recomendado: ponle contraseña a root (phpMyAdmin > Cuentas de usuario) y, si lo haces, no hace falta cambiar nada en el sistema.\n";
    exit(0);
}
// Apache apagado o 403: el archivo está bien escrito, pero no se pudo ver desde la web
echo "! NO se pudo comprobar por web: {$web['detalle']} ($urlWeb).\n";
echo "  El credenciales.php se dejó con el usuario nuevo. Enciende Apache y abre el sistema; si no abre, corre otra vez este programa o revisa los permisos del archivo (644).\n";
echo "\nSiguiente paso recomendado: ponle contraseña a root (phpMyAdmin > Cuentas de usuario) y, si lo haces, no hace falta cambiar nada en el sistema.\n";
exit(3);

<?php
/**
 * Revisión del servidor: SOLO LEE, no cambia nada.
 *
 * Revisa lo que no se puede ver desde otra computadora: que la carpeta del sistema esté completa y
 * sea de una sola versión, que la base esté al día, versión de PHP, configuración de errores,
 * modo de la base, usuarios de MariaDB sin contraseña, archivos que no deben estar en la carpeta
 * pública, permisos de escritura, que las carpetas protegidas de verdad estén protegidas y, en una
 * Mac, que no se suspenda mientras sirve a otras computadoras.
 *
 * Toda conexión (a la base o a Apache) tiene un plazo de pocos segundos: si algo está apagado,
 * lo dice en lugar de quedarse esperando.
 *
 *   Mac:      cd /Applications/XAMPP/xamppfiles/htdocs/escuela-musica/backend/herramientas
 *             /Applications/XAMPP/xamppfiles/bin/php revisar_servidor.php
 *   Windows:  cd C:\xampp\htdocs\escuela-musica\backend\herramientas
 *             C:\xampp\php\php.exe revisar_servidor.php
 *
 * Opción: --puerto=8080 si Apache no está en el puerto 80.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
// Plazos cortos para toda conexión de red que abra esta herramienta
ini_set('default_socket_timeout', '3');
require_once __DIR__ . '/../config/Respuesta.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../lib/RevisionServidor.php';
require_once __DIR__ . '/../lib/RedesPermitidas.php';

$bien = 0; $mal = 0; $avisos = 0;
function linea(string $estado, string $texto): void
{
    global $bien, $mal, $avisos;
    $marca = ['ok' => '  ✓ ', 'mal' => '  ✕ ', 'aviso' => '  ! '][$estado];
    if ($estado === 'ok') $bien++; elseif ($estado === 'mal') $mal++; else $avisos++;
    echo $marca . $texto . "\n";
}
function titulo(string $t): void { echo "\n$t\n"; }

$puertoWeb = 80;
foreach (array_slice($argv, 1) as $a) if (preg_match('/^--puerto=(\d{2,5})$/', $a, $m)) $puertoWeb = (int) $m[1];

$proyecto = realpath(__DIR__ . '/../..');
$publica  = dirname($proyecto);   // htdocs
$xampp    = dirname($publica);    // la carpeta de XAMPP

echo "Sistema Escuela — revisión del servidor (solo lectura)\n";
echo "Sistema: " . PHP_OS_FAMILY . " · Proyecto: $proyecto\n";

/* ------------------------------------------------- La carpeta del sistema */
titulo('Archivos del sistema');
$falta = RevisionServidor::faltantes($proyecto);
$versionApp = is_file("$proyecto/frontend/js/app.js") ? RevisionServidor::versionDeApp((string) file_get_contents("$proyecto/frontend/js/app.js")) : null;
$versionEsperada = is_file("$proyecto/backend/comprobar.php") ? RevisionServidor::versionEsperada((string) file_get_contents("$proyecto/backend/comprobar.php")) : null;
$versionIndex = is_file("$proyecto/frontend/index.html") ? RevisionServidor::versionDeIndex((string) file_get_contents("$proyecto/frontend/index.html")) : null;
if ($falta) {
    linea('mal', 'La carpeta está incompleta: falta ' . implode(', ', array_slice($falta, 0, 6)) . (count($falta) > 6 ? ' y ' . (count($falta) - 6) . ' más' : '')
        . '. Vuelve a copiar la versión completa con el comando de Terminal.');
} else {
    linea('ok', 'Están todos los archivos clave y todo lo que el sistema pide para arrancar');
}
if ($versionApp !== null && $versionEsperada !== null) {
    $mezcla = [];
    if ($versionApp !== $versionEsperada) $mezcla[] = "la pantalla (app.js) es la $versionApp y la revisión de instalación (comprobar.php) espera la $versionEsperada";
    if ($versionIndex !== null && $versionIndex !== $versionApp) $mezcla[] = "index.html pide app.js de la versión $versionIndex pero app.js es la $versionApp";
    $mezcla
        ? linea('mal', 'La carpeta mezcla versiones: ' . implode('; ', $mezcla) . '. Vuelve a copiar la versión completa con el comando de Terminal.')
        : linea('ok', "Todos los archivos son de la misma versión ($versionApp)");
} elseif (!$falta) {
    linea('aviso', 'No pude leer la versión de los archivos (app.js o comprobar.php)');
}

/* ------------------------------------------------------------------ PHP */
titulo('PHP');
version_compare(PHP_VERSION, '8.0.0', '>=')
    ? linea('ok', 'PHP ' . PHP_VERSION)
    : linea('mal', 'PHP ' . PHP_VERSION . ': el sistema necesita PHP 8.0 o más nuevo');
foreach (['pdo_mysql' => 'conexión a la base', 'mbstring' => 'acentos y eñes', 'json' => 'respuestas del sistema'] as $ext => $para) {
    extension_loaded($ext) ? linea('ok', "Extensión $ext") : linea('mal', "Falta la extensión $ext ($para)");
}
(extension_loaded('zip') || function_exists('gzinflate'))
    ? linea('ok', 'Lectura de archivos Excel (.xlsx)')
    : linea('mal', 'No se pueden leer .xlsx: falta la extensión zip o zlib');
ini_get('expose_php') ? linea('aviso', 'expose_php está encendido en php.ini (el sistema ya quita la cabecera, pero conviene apagarlo)')
                      : linea('ok', 'expose_php apagado');
$zona = (string) ini_get('date.timezone');
if ($zona === 'America/Mexico_City') {
    linea('ok', 'date.timezone de php.ini: America/Mexico_City');
} else {
    linea('aviso', 'date.timezone de php.ini dice ' . ($zona !== '' ? $zona : '(nada)')
        . ': el sistema usa America/Mexico_City por su cuenta, pero conviene ponerla en php.ini para que cualquier herramienta feche bien');
}
linea('ok', 'display_errors lo apaga el sistema en cada petición (php.ini dice: ' . (ini_get('display_errors') ?: 'Off') . ')');

/* ------------------------------------------------------------ Carpetas */
titulo('Carpetas privadas (fuera de lo público)');
foreach (['escuela_sesiones' => 'sesiones', 'escuela_registros' => 'registro de errores'] as $nombre => $para) {
    $dir = Respuesta::carpetaPrivada($nombre);
    if ($dir === null) { linea('mal', "No hay dónde guardar $para: ninguna carpeta privada se puede escribir"); continue; }
    str_starts_with(realpath($dir) ?: $dir, $publica)
        ? linea('mal', "La carpeta de $para quedó dentro de lo público: $dir")
        : linea('ok', ucfirst($para) . ": $dir");
}

/* --------------------------------------------------------------- Base */
titulo('Base de datos');
$c = Database::credenciales();
is_file(__DIR__ . '/../config/credenciales.php')
    ? linea('ok', 'Conexión propia de esta computadora (config/credenciales.php)')
    : linea('aviso', 'Sin config/credenciales.php: se usan los valores de un XAMPP recién instalado');
$c['usuario'] === 'root'
    ? linea('aviso', 'El sistema entra a la base como root; corre backend/herramientas/asegurar_base.php para que use un usuario propio con permisos solo sobre su base')
    : linea('ok', 'El sistema entra a la base con su propio usuario: ' . $c['usuario']);
empty($c['modo_estricto'])
    ? linea('aviso', 'Modo estricto apagado en credenciales.php')
    : linea('ok', 'Modo estricto encendido para el sistema');
try {
    // Con plazo: si MySQL está apagado avisa en unos segundos, no se queda esperando.
    $db = Database::nueva(3);
    linea('ok', 'Conectada: ' . $db->query("SELECT VERSION()")->fetchColumn());
    $global = (string) $db->query("SELECT @@GLOBAL.sql_mode")->fetchColumn();
    linea('ok', 'sql_mode del servidor: ' . ($global ?: '(vacío)') . ' · el sistema usa el suyo en cada conexión');
    try {
        $bind = $db->query("SHOW VARIABLES LIKE 'bind_address'")->fetch(PDO::FETCH_NUM)[1] ?? null;
        if ($bind === null || $bind === '' || $bind === '*' || $bind === '0.0.0.0' || $bind === '::') {
            linea('mal', 'MariaDB escucha en todas las interfaces de red' . ($bind ? " ($bind)" : '')
                . ': pon bind-address=127.0.0.1 en my.cnf');
        } else {
            linea('ok', "MariaDB solo escucha en $bind");
        }
    } catch (Throwable $e) { linea('aviso', 'No se pudo leer bind_address'); }
    try {
        $sin = $db->query("SELECT CONCAT(user, '@', host) FROM mysql.user
                            WHERE (authentication_string = '' OR authentication_string IS NULL)
                              AND (password = '' OR password IS NULL)")->fetchAll(PDO::FETCH_COLUMN);
        $sin ? linea('mal', 'Usuarios de MariaDB sin contraseña: ' . implode(', ', $sin))
             : linea('ok', 'Todos los usuarios de MariaDB tienen contraseña');
    } catch (Throwable $e) {
        linea('ok', 'El sistema no puede leer la lista de usuarios de MariaDB (bien: no tiene permisos de más)');
    }
    // ¿Falta algún paso de la actualización? Se le pregunta al propio actualizar_base.php (en modo revisión,
    // sin aplicar nada), así no hay un número de versión fijo que se desactualice.
    $pasos = RevisionServidor::pasosPendientes("$proyecto/backend/actualizar_base.php");
    if ($pasos === null) {
        linea('aviso', 'No pude revisar si la base está al día: abre backend/comprobar.php en el sistema como administrador');
    } elseif ($pasos['pendientes'] > 0 || $pasos['errores'] > 0) {
        linea('mal', 'Falta correr actualizar_base.php (' . $pasos['pendientes'] . ' paso(s) pendiente(s)'
            . ($pasos['errores'] ? ', ' . $pasos['errores'] . ' con error' : '') . ')');
    } else {
        linea('ok', 'Base al día' . ($versionEsperada ? " ($versionEsperada)" : ''));
    }
} catch (Throwable $e) {
    $m = $e->getMessage();
    linea('mal', 'No se pudo conectar a la base en 3 segundos'
        . (preg_match('/refused|2002|timed out|2006|gone away/i', $m) ? ': MySQL parece apagado (enciéndelo en el panel de XAMPP)' : ': ' . mb_substr(preg_replace('/\s+/', ' ', $m), 0, 160)));
}

/* -------------------------------------- Configuración de MariaDB y phpMyAdmin */
titulo('Configuración de MariaDB y phpMyAdmin (solo se lee)');
$cnf = null;
foreach ([$xampp . '/etc/my.cnf', $xampp . '/mysql/bin/my.ini', $xampp . '/mysql/my.ini', '/etc/my.cnf', '/etc/mysql/my.cnf'] as $cand) {
    if (is_readable($cand)) { $cnf = $cand; break; }
}
if ($cnf === null) {
    linea('aviso', 'No encontré el my.cnf (o my.ini) de MariaDB para leerlo');
} else {
    $bindCnf = RevisionServidor::bindAddressDe((string) file_get_contents($cnf));
    if ($bindCnf === null) {
        linea('mal', "$cnf no fija bind-address: MariaDB acepta conexiones de toda la red. Agrega bind-address=127.0.0.1 en la sección [mysqld]");
    } elseif (RevisionServidor::esLocal($bindCnf)) {
        linea('ok', "$cnf: bind-address = $bindCnf");
    } else {
        linea('mal', "$cnf: bind-address = $bindCnf deja la base abierta a la red; debe ser 127.0.0.1");
    }
}
$pma = null;
foreach ([$xampp . '/phpmyadmin/config.inc.php', $xampp . '/phpMyAdmin/config.inc.php'] as $cand) {
    if (is_readable($cand)) { $pma = $cand; break; }
}
if ($pma === null) {
    linea('ok', 'No hay phpMyAdmin en esta carpeta de XAMPP (o no se puede leer su configuración)');
} else {
    $cfg = RevisionServidor::configPhpMyAdmin((string) file_get_contents($pma));
    RevisionServidor::phpMyAdminAbierto($cfg)
        ? linea('mal', 'phpMyAdmin entra solo como root y sin contraseña (auth_type = config): cualquiera que lo abra tiene toda la base. Cámbialo a cookie y ponle contraseña a root')
        : linea('ok', 'phpMyAdmin no entra solo como root sin contraseña' . ($cfg['auth_type'] ? " (auth_type = {$cfg['auth_type']})" : ''));
}
if (PHP_OS_FAMILY === 'Darwin') {
    $pm = (string) @shell_exec('pmset -g custom 2>/dev/null');
    if (trim($pm) === '') $pm = (string) @shell_exec('pmset -g 2>/dev/null');
    $minutos = RevisionServidor::minutosParaSuspender($pm);
    if ($minutos === null) {
        linea('aviso', 'No pude leer los ajustes de energía de la Mac (pmset). Revisa a mano que no se suspenda');
    } elseif ($minutos > 0) {
        linea('mal', "La Mac se suspende a los $minutos minutos: ajústalo para que no se suspenda mientras sirve a otras computadoras "
            . '(Ajustes del Sistema > Pantalla bloqueada / Batería > Energía: evitar la suspensión automática con el adaptador)');
    } else {
        linea('ok', 'La Mac no se suspende automáticamente con el adaptador conectado');
    }
}

/* ------------------------------------------------------ Quién puede entrar y respaldos */
titulo('Quién puede entrar y respaldos (solo se lee)');
$redes = RedesPermitidas::configuradas($c);
if (RedesPermitidas::ilegible($c)) {
    linea('mal', 'redes_permitidas en credenciales.php no es una lista (está escrita como texto): el sistema quedó CERRADO para todos los equipos por seguridad. '
        . "Escríbela como lista, por ejemplo ['127.0.0.1', '::1']");
} elseif ($redes !== null) {
    linea('ok', 'Equipos permitidos (redes_permitidas): ' . implode(', ', array_map('strval', $redes)));
} else {
    PHP_OS_FAMILY === 'Darwin'
        ? linea('aviso', 'Cualquier equipo que llegue a la Mac puede intentar entrar al sistema: agrega redes_permitidas en credenciales.php (por ejemplo, localhost y la red privada)')
        : linea('aviso', 'Sin lista de equipos permitidos (redes_permitidas): entra cualquier equipo que llegue al servidor');
}
linea('aviso', 'Respaldos automáticos: programa backend/herramientas/respaldo_diario.php y comprueba cada copia con verificar_respaldo.php');

/* ------------------------------------------------ Archivos que no deben estar */
titulo('Archivos en la carpeta pública (' . $publica . ')');
$peligrosos = [];
foreach (glob($publica . '/*.{zip,rar,7z,sql,gz,bak,tar}', GLOB_BRACE) ?: [] as $f) $peligrosos[] = $f;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($proyecto, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $n = $f->getFilename();
    if (preg_match('/\.(zip|rar|7z|sql|gz|bak|tar|xlsx)$/i', $n) && !str_contains($f->getPathname(), DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR)) {
        $peligrosos[] = $f->getPathname();
    }
    if (in_array($n, ['setup_admin.php', 'diagnostico_login.php'], true)) $peligrosos[] = $f->getPathname();
}
if ($peligrosos) {
    foreach (array_unique($peligrosos) as $f) linea('mal', 'Sácalo de la carpeta pública: ' . $f);
} else {
    linea('ok', 'No hay copias, respaldos ni utilidades peligrosas a la vista');
}
is_file($proyecto . '/.htaccess') ? linea('ok', 'Protección de la carpeta del sistema (.htaccess)')
                                   : linea('mal', 'Falta el .htaccess de la carpeta del sistema');

/* ----------------------------------------- ¿Apache respeta las protecciones? */
titulo('Protecciones vistas desde el navegador (en esta misma computadora)');
$base = '/' . rawurlencode(basename($proyecto));
$codigo = fn(string $ruta): ?int => RevisionServidor::codigoHttp('127.0.0.1', $puertoWeb, $base . $ruta, 2);
$prueba = $codigo('/frontend/index.html');
if ($prueba !== 200) {
    // Solo se prueban las protecciones si el sistema de verdad responde ahí: un 404
    // de una ruta que no existe no demuestra que algo esté protegido.
    linea('aviso', 'Apache no responde bien en 127.0.0.1:' . $puertoWeb . $base
        . ($prueba === null ? ' (no contestó en 2 segundos: ¿está apagado Apache? ¿otro puerto? usa --puerto=)' : " (respondió $prueba)")
        . '; no se pudieron probar las protecciones');
} else {
    foreach (['/backend/config/Database.php' => 'Configuración', '/backend/config/credenciales.php' => 'Credenciales',
              '/sql/schema.sql' => 'Esquema SQL', '/README.md' => 'Documentación',
              '/backend/herramientas/crear_admin.php' => 'Herramientas'] as $ruta => $que) {
        $c = $codigo($ruta);
        in_array($c, [403, 404], true) ? linea('ok', "$que bloqueada ($c)")
                                       : linea('mal', "$que " . ($c === null ? 'no contestó a tiempo' : "se puede abrir desde el navegador ($c): revisa AllowOverride en httpd.conf"));
    }
    $c = $codigo('/backend/');
    in_array($c, [200, 403, 404], true) ? linea('ok', 'Sin listado de carpetas') : linea('aviso', "La carpeta backend respondió " . ($c ?? 'nada a tiempo'));
}

/* ------------------------------------------------------------ Recordatorios */
titulo('Para revisar a mano');
if (PHP_OS_FAMILY !== 'Darwin') linea('aviso', 'La computadora servidor no debe suspenderse (Mac: Ajustes > Energía)');
linea('aviso', 'Tailscale: entra por la IP de la tailnet; sin Funnel y sin "tailscale serve"; ACL solo para las máquinas de la escuela');
linea('aviso', 'phpMyAdmin: solo desde esta computadora y con contraseña');
linea('aviso', 'Respaldos: programa backend/herramientas/respaldo_diario.php cada noche (y revisa escuela_registros/respaldos.log)');

echo "\nResultado: $bien bien, $mal por corregir, $avisos para revisar.\n";
exit($mal > 0 ? 1 : 0);

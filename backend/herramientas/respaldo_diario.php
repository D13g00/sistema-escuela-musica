<?php
/**
 * Respaldo diario del sistema: genera el respaldo, lo VERIFICA (lo restaura en una base temporal y
 * compara importes y renglones), borra los respaldos viejos y deja constancia.
 *
 * SOLO desde la Terminal de la computadora servidor (no se abre desde el navegador):
 *
 *   Mac:      /Applications/XAMPP/xamppfiles/bin/php /Applications/XAMPP/xamppfiles/htdocs/escuela-musica/backend/herramientas/respaldo_diario.php
 *   Windows:  C:\xampp\php\php.exe C:\xampp\htdocs\escuela-musica\backend\herramientas\respaldo_diario.php
 *
 * Opciones (en este orden o como sea; ambas son opcionales):
 *   CARPETA_DESTINO    donde se guardan los respaldos. Sin ella: la carpeta escuela_respaldos, junto a htdocs
 *                      (fuera de lo público; en una Mac de XAMPP: /Applications/XAMPP/xamppfiles/escuela_respaldos).
 *   --conservar=30     cuántos días seguidos se guardan completos (el último respaldo de cada día).
 *                      Además se guarda el último respaldo de cada mes de los últimos 12 meses.
 *   --solo-rotar       no respalda nada: solo aplica esa misma limpieza a CARPETA_DESTINO, tanto a los escuela_*.sql
 *                      como a los escuela_*.sql.enc (cifrados). Sirve a un script externo que guarde copias cifradas fuera del servidor.
 *
 * Cada respaldo se llama escuela_AAAA-MM-DD_HHMM.sql. Una línea por corrida queda en
 * escuela_registros/respaldos.log. Termina con código 0 si todo salió bien y distinto de 0 si algo falló
 * (no se pudo respaldar, el respaldo no se restaura o no coincide): quien lo programe debe avisar en ese caso.
 * Código 3 = MySQL no responde (apagado): no es un fallo del respaldo, se reintenta más tarde.
 * Si el respaldo nuevo no pasa la verificación, NO se borra ningún respaldo viejo.
 *
 * ── PUNTO DE ENGANCHE (cifrar y sacar la copia del servidor) ──
 * Este script no cifra ni copia fuera de la computadora. Al terminar bien deja:
 *   · la última línea de lo que imprime:   RESPALDO_LISTO=/ruta/completa/escuela_AAAA-MM-DD_HHMM.sql
 *   · el mismo dato en CARPETA_DESTINO/ultimo_respaldo.txt
 *   · el código de salida 0
 * Un script aparte puede hacer, por ejemplo:
 *   php respaldo_diario.php && ARCHIVO=$(cat CARPETA_DESTINO/ultimo_respaldo.txt) && cifrar_y_copiar "$ARCHIVO"
 * y programarse con launchd (Mac) o el Programador de tareas (Windows).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config/Respuesta.php';
require_once __DIR__ . '/../services/BackupService.php';
require_once __DIR__ . '/../services/VerificadorRespaldo.php';

/** Lo que se queda y lo que se va, dada la lista de respaldos (nombres escuela_AAAA-MM-DD_HHMM.sql) */
function elegirConservados(array $nombres, int $dias, string $hoyMes): array
{
    sort($nombres);                      // de más viejo a más nuevo (el nombre lleva la fecha)
    $porDia = []; $porMes = [];
    foreach ($nombres as $n) {
        if (!preg_match('/^escuela_(\d{4}-\d{2})-(\d{2})_\d{4}\.sql(\.enc)?$/', $n, $m)) continue;
        $porDia[$m[1] . '-' . $m[2]] = $n;   // el último de cada día
        $porMes[$m[1]] = $n;                  // el último de cada mes
    }
    krsort($porDia);
    $conservar = array_slice($porDia, 0, max(1, $dias), true);
    // El último respaldo de cada uno de los últimos 12 meses (contando el mes en curso)
    $limite = date('Y-m', strtotime($hoyMes . '-01 -11 months'));
    foreach ($porMes as $mes => $n) if ($mes >= $limite) $conservar[$n] = $n;
    return array_values(array_unique(array_values($conservar)));
}

function anotar(string $linea): void
{
    $dir = Respuesta::carpetaPrivada('escuela_registros');
    if ($dir) @file_put_contents($dir . DIRECTORY_SEPARATOR . 'respaldos.log', date('Y-m-d H:i:s') . ' ' . $linea . "\n", FILE_APPEND | LOCK_EX);
}

/** $codigo 1 = falló el respaldo; 3 = MySQL no responde (un script externo puede tratarlo aparte: reintentar sin alarmar) */
function fallar(string $mensaje, int $codigo = 1): void
{
    fwrite(STDERR, "✕ $mensaje\n");
    anotar(($codigo === 3 ? 'SIN MYSQL ' : 'FALLO ') . $mensaje);
    exit($codigo);
}

/** ¿El error es que MySQL no contesta (apagado, puerto cerrado, se cortó la conexión)? */
function mysqlNoResponde(Throwable $e): bool
{
    return $e instanceof PDOException
        && (bool) preg_match('/\[(2002|2003|2006|2013)\]|Connection refused|Can\'t connect|No connection could be made|actively refused|server has gone away|Lost connection/i', $e->getMessage());
}

// Si lo corre una prueba (se incluye este archivo con ESCUELA_SOLO_FUNCIONES), solo se definen las funciones.
if (defined('ESCUELA_SOLO_FUNCIONES')) return;

$destino = null; $conservar = 30; $soloRotar = false;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--solo-rotar') $soloRotar = true;
    elseif (preg_match('/^--conservar=(\d+)$/', $a, $m)) $conservar = max(1, (int) $m[1]);
    elseif (str_starts_with($a, '-')) { fwrite(STDERR, "Opción desconocida: $a\n"); exit(2); }
    else $destino = $a;
}
if ($soloRotar) {
    if ($destino === null || !is_dir($destino)) fallar('--solo-rotar necesita la carpeta que se va a limpiar.');
    $destino = rtrim($destino, '/\\');
    $todos = array_map('basename', array_merge(glob($destino . DIRECTORY_SEPARATOR . 'escuela_*.sql') ?: [], glob($destino . DIRECTORY_SEPARATOR . 'escuela_*.sql.enc') ?: []));
    $quedan = elegirConservados($todos, $conservar, date('Y-m'));
    $borrados = 0;
    foreach ($todos as $n) {
        if (!preg_match('/^escuela_\d{4}-\d{2}-\d{2}_\d{4}\.sql(\.enc)?$/', $n) || in_array($n, $quedan, true)) continue;
        if (@unlink($destino . DIRECTORY_SEPARATOR . $n)) $borrados++;
    }
    echo "Quedan " . count($quedan) . " respaldos en $destino (se borraron $borrados viejos).\n";
    exit(0);
}
$destino ??= Respuesta::carpetaPrivada('escuela_respaldos');
if ($destino === null) fallar('No hay dónde guardar los respaldos: indica una carpeta destino.');
$destino = rtrim($destino, '/\\');
if (!is_dir($destino) && !@mkdir($destino, 0700, true) && !is_dir($destino)) fallar("No pude crear la carpeta $destino.");
if (!is_writable($destino)) fallar("No puedo escribir en la carpeta $destino.");
// Un respaldo con datos de alumnos y pagos no debe quedar a la vista de Apache.
$publica = realpath(dirname(__DIR__, 3));
if ($publica && str_starts_with((string) realpath($destino), $publica)) {
    fwrite(STDERR, "! Aviso: la carpeta $destino está dentro de la parte pública del sistema. Guarda los respaldos fuera de htdocs.\n");
}

$archivo = $destino . DIRECTORY_SEPARATOR . 'escuela_' . date('Y-m-d_Hi') . '.sql';
echo "Respaldando la base del sistema...\n";
try {
    $info = (new BackupService())->generarEnArchivo($archivo);
} catch (Throwable $e) {
    $m = $e instanceof PDOException ? 'No pude leer la base de datos (¿está encendido MySQL?): ' . substr($e->getMessage(), 0, 200) : $e->getMessage();
    fallar('No se pudo generar el respaldo: ' . $m, mysqlNoResponde($e) ? 3 : 1);
}
echo sprintf("  %d tablas, %d renglones, %.2f MB\n", $info['tablas'], $info['filas'], $info['bytes'] / 1048576);

echo "Verificando que se restaure y coincida...\n";
$r = (new VerificadorRespaldo(3))->verificar($archivo);
if (!$r['ok'] && ($r['codigo'] ?? 1) === 2) {
    // No se pudo ni intentar la verificación (MySQL se apagó, no dejó crear la base temporal): el archivo no se conoce ni bueno ni malo.
    @unlink($archivo);
    fallar('No se pudo verificar el respaldo: ' . $r['mensaje'] . ' (se borró el archivo sin verificar; no se borró ningún respaldo anterior)',
        str_contains($r['mensaje'], 'No pude conectarme') ? 3 : 1);
}
if (!$r['ok']) {
    $malo = preg_replace('/\.sql$/', '_NO_SIRVE.sql', $archivo);
    @rename($archivo, $malo);
    foreach ($r['problemas'] as $p) fwrite(STDERR, "  - $p\n");
    fallar('El respaldo NO pasó la verificación: ' . $r['mensaje'] . ' (el archivo quedó como ' . basename($malo) . '; no se borró ningún respaldo anterior)');
}

// Limpieza: solo después de saber que el nuevo sirve
$todos = array_map('basename', glob($destino . DIRECTORY_SEPARATOR . 'escuela_*.sql') ?: []);
$quedan = elegirConservados($todos, $conservar, date('Y-m'));
$borrados = 0;
foreach ($todos as $n) {
    if (!preg_match('/^escuela_\d{4}-\d{2}-\d{2}_\d{4}\.sql$/', $n) || in_array($n, $quedan, true)) continue;
    if (@unlink($destino . DIRECTORY_SEPARATOR . $n)) $borrados++;
}
(new VerificadorRespaldo(3))->limpiarTemporales();

@file_put_contents($destino . DIRECTORY_SEPARATOR . 'ultimo_respaldo.txt', $archivo . "\n");
anotar(sprintf('OK %s %.2f MB, %d tablas, %d renglones; verificado%s; quedan %d, borrados %d', basename($archivo),
    $info['bytes'] / 1048576, $info['tablas'], $info['filas'], $r['sin_huella'] ? ' (sin huella)' : '', count($quedan), $borrados));
echo "✓ " . $r['mensaje'] . "\n";
echo "  Se conservan " . count($quedan) . " respaldos" . ($borrados ? " (se borraron $borrados viejos)" : '') . ".\n";
echo "RESPALDO_LISTO=" . $archivo . "\n";
exit(0);

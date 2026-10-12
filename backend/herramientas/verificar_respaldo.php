<?php
/**
 * Comprueba que un respaldo sirve: lo restaura en una base temporal, compara los
 * importes y renglones contra los que tenía la base al respaldar, y borra la
 * base temporal. No toca la base del sistema.
 *
 * SOLO desde la Terminal de la computadora servidor (no se abre desde el navegador):
 *
 *   Mac:      cd /Applications/XAMPP/xamppfiles/htdocs/escuela-musica/backend/herramientas
 *             /Applications/XAMPP/xamppfiles/bin/php verificar_respaldo.php RUTA_DEL_RESPALDO.sql
 *   Windows:  cd C:\xampp\htdocs\escuela-musica\backend\herramientas
 *             C:\xampp\php\php.exe verificar_respaldo.php RUTA_DEL_RESPALDO.sql
 *
 * Termina con código 0 si el respaldo se restaura completo y coincide; 1 si no coincide
 * o está dañado; 2 si no se pudo ni intentar (archivo inexistente, MySQL apagado...).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../services/VerificadorRespaldo.php';

$archivo = $argv[1] ?? '';
if ($archivo === '' || str_starts_with($archivo, '-')) {
    fwrite(STDERR, "Uso: php verificar_respaldo.php RUTA_DEL_RESPALDO.sql\n");
    exit(2);
}
if (!is_file($archivo)) {
    fwrite(STDERR, "No encuentro el archivo: $archivo\n");
    exit(2);
}

echo "Verificando " . $archivo . " (" . number_format(filesize($archivo) / 1048576, 2) . " MB)...\n";
echo "Se restaura en una base temporal y se borra al terminar; la base del sistema no se toca.\n";

$v = new VerificadorRespaldo(3);
$v->limpiarTemporales();
$t0 = microtime(true);
$r = $v->verificar($archivo);
$seg = round(microtime(true) - $t0, 1);

echo "\n" . ($r['ok'] ? '✓ ' : '✕ ') . $r['mensaje'] . "\n";
foreach ($r['problemas'] as $p) echo "  - $p\n";
if (!empty($r['rechazada'])) echo "  Primera instrucción que el sistema no genera: " . $r['rechazada'] . "\n";
if ($r['sentencias'] > 0) echo "($r[sentencias] sentencias ejecutadas en $seg s)\n";

exit($r['codigo']);

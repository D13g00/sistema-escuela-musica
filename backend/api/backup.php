<?php
/**
 * Descarga un respaldo completo de la base. Solo administración: el archivo
 * trae datos de alumnos, pagos, nómina y las contraseñas (cifradas) de los
 * usuarios.
 */
require_once __DIR__ . '/../config/Respuesta.php';
require_once __DIR__ . '/../config/Auth.php';
require_once __DIR__ . '/../services/BackupService.php';

Respuesta::prepararErrores(false);
header('X-Content-Type-Options: nosniff');
header_remove('X-Powered-By');
Auth::requerirRol('admin');

$temporal = null;
// Si la descarga se corta (se cierra la pestaña, se cae la red) o el script muere a medias, el temporal con los datos de
// todos igual se borra: este registro corre siempre al terminar, también después de un error fatal o de un plazo vencido.
register_shutdown_function(function () use (&$temporal) {
    if ($temporal) {
        @unlink($temporal);
        @unlink($temporal . '.parcial');
    }
});
try {
    // El respaldo se arma completo en un archivo temporal (por partes, con una sola foto de la base) y solo si
    // quedó entero se manda: si algo falla a medias, la persona ve el error, no un archivo cortado.
    $temporal = tempnam(sys_get_temp_dir(), 'escuela_resp_');
    if ($temporal === false) throw new Exception('No hay dónde armar el respaldo.');
    (new BackupService())->generarEnArchivo($temporal);
    $nombreArchivo = 'respaldo_escuela_demo_' . date('Y-m-d_His') . '.sql';

    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
    header('Content-Length: ' . filesize($temporal));
    header('Cache-Control: no-store');
    readfile($temporal);
    @unlink($temporal);
} catch (Throwable $e) {
    if ($temporal) @unlink($temporal);
    error_log('Sistema Escuela: no se pudo generar el respaldo: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('No se pudo generar el respaldo. El detalle quedó en el registro de errores del servidor.');
}

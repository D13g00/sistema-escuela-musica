<?php
/**
 * Borradores de alta.
 *   GET                      -> abiertos
 *   GET  ?id=N               -> uno
 *   POST {alumno, clase, id_alumno?, motivo?}        -> crea
 *   PUT  ?id=N {alumno, clase, id_alumno?, motivo?}  -> actualiza
 *   DELETE ?id=N             -> descarta (no se borra: queda como descartado)
 *
 * Completar un borrador no es una acción de aquí: ocurre al dar de alta la
 * clase con id_borrador, dentro de la misma transacción del alta.
 */
require_once __DIR__ . '/../config/Respuesta.php';
require_once __DIR__ . '/../config/Auth.php';
require_once __DIR__ . '/../models/BorradorModel.php';

Respuesta::iniciar();
Auth::requerirLogin();
if (in_array($_SERVER['REQUEST_METHOD'], ['POST','PUT','DELETE'], true)) { Auth::exigirCsrf(); Auth::requerirRol('admin', 'recepcion'); }

$modelo = new BorradorModel();
$usuario = Auth::usuarioActual()['nombre'] ?? null;
$id = (int) ($_GET['id'] ?? 0);

try {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            if ($id) {
                $b = $modelo->obtener($id);
                if (!$b) Respuesta::error('Ese borrador no existe.', 404);
                Respuesta::exito($b);
            }
            Respuesta::exito($modelo->listar($_GET['estado'] ?? 'abierto'));
            break;
        case 'POST':
            Respuesta::exito(['id_borrador' => $modelo->guardar(null, Respuesta::cuerpoJson(), $usuario)], 201);
            break;
        case 'PUT':
            if (!$id) Respuesta::error('Falta el borrador.');
            Respuesta::exito(['id_borrador' => $modelo->guardar($id, Respuesta::cuerpoJson(), $usuario)]);
            break;
        case 'DELETE':
            if (!$id) Respuesta::error('Falta el borrador.');
            $modelo->descartar($id);
            Respuesta::exito(['descartado' => true]);
            break;
        default:
            Respuesta::error('Método no soportado', 405);
    }
} catch (Throwable $e) {
    Respuesta::fallo($e, 400);
}

<?php
/**
 * Facturas del mes.
 *   GET  ?periodo=AAAA-MM                 -> alumnos que requieren factura, con su estado
 *   GET  ?accion=recordatorio             -> cuántas faltan este mes y cuántos días quedan
 *   PUT  {id_alumno, periodo, hecha, folio?} -> marca o desmarca
 */
require_once __DIR__ . '/../config/Respuesta.php';
require_once __DIR__ . '/../config/Auth.php';
require_once __DIR__ . '/../models/FacturaModel.php';

Respuesta::iniciar();
Auth::requerirLogin();
if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'DELETE'], true)) { Auth::exigirCsrf(); Auth::requerirRol('admin'); }

$modelo = new FacturaModel();
try {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            if (($_GET['accion'] ?? '') === 'recordatorio') Respuesta::exito($modelo->recordatorio($_GET['periodo'] ?? null));
            Respuesta::exito($modelo->delMes($_GET['periodo'] ?? null));
            break;
        case 'PUT':
            $d = Respuesta::cuerpoJson();
            Respuesta::exito($modelo->marcar((int) ($d['id_alumno'] ?? 0), $d['periodo'] ?? null, !empty($d['hecha']),
                                             $d['folio'] ?? null, Auth::usuarioActual()['nombre'] ?? null));
            break;
        default:
            Respuesta::error('Método no soportado', 405);
    }
} catch (Throwable $e) {
    Respuesta::fallo($e, 400);
}

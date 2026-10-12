<?php
/**
 * Lista de espera. Una solicitud nunca es inscripción: no ocupa horario ni
 * genera nómina, cargos o deuda.
 *   GET  ?estado=abiertas|pendiente|contactado|inscrito|cancelado
 *   GET  ?id=N
 *   POST {id_alumno | nombre+telefono, id_instrumento, id_maestro?, id_salon?,
 *         dia_semana?, hora_inicio?, duracion_min, modalidad, prioridad?, notas?}
 *   PUT  ?id=N {…}                        -> corrige la solicitud
 *   PUT  ?id=N&accion=estado {estado}     -> contactado / cancelado / pendiente
 *
 * "Inscrito" solo lo pone el alta de la clase (con id_lista_espera), dentro
 * de la transacción del alta. Aquí no hay forma de inscribir a nadie.
 */
require_once __DIR__ . '/../config/Respuesta.php';
require_once __DIR__ . '/../config/Auth.php';
require_once __DIR__ . '/../models/ListaEsperaModel.php';

Respuesta::iniciar();
Auth::requerirLogin();
if (in_array($_SERVER['REQUEST_METHOD'], ['POST','PUT','DELETE'], true)) { Auth::exigirCsrf(); Auth::requerirRol('admin', 'recepcion'); }

$modelo = new ListaEsperaModel();
$id = (int) ($_GET['id'] ?? 0);

try {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            if ($id) {
                $f = $modelo->obtener($id);
                if (!$f) Respuesta::error('Esa solicitud no existe.', 404);
                Respuesta::exito($f);
            }
            Respuesta::exito($modelo->listar($_GET['estado'] ?? 'abiertas'));
            break;
        case 'POST':
            $nuevo = $modelo->crear(Respuesta::cuerpoJson(), Auth::usuarioActual()['nombre'] ?? null);
            Respuesta::exito(['id_lista_espera' => $nuevo], 201);
            break;
        case 'PUT':
            if (!$id) Respuesta::error('Falta la solicitud.');
            $datos = Respuesta::cuerpoJson();
            if (($_GET['accion'] ?? '') === 'estado') {
                $modelo->cambiarEstado($id, (string) ($datos['estado'] ?? ''));
            } else {
                $modelo->actualizar($id, $datos);
            }
            Respuesta::exito($modelo->obtener($id));
            break;
        default:
            Respuesta::error('Método no soportado', 405);
    }
} catch (Throwable $e) {
    Respuesta::fallo($e, 400);
}

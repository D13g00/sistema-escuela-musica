<?php
require_once __DIR__ . '/../config/Respuesta.php';
require_once __DIR__ . '/../config/Auth.php';
require_once __DIR__ . '/../models/MaestroModel.php';

Respuesta::iniciar();
$modelo = new MaestroModel();
$metodo = $_SERVER['REQUEST_METHOD'];

Auth::requerirLogin();
if (in_array($metodo, ['POST', 'PUT', 'DELETE'], true)) {
    Auth::exigirCsrf();
    Auth::requerirRol('admin');
}

/** Lo que se le paga a cada maestro es de administración y consulta, no de recepción */
function sinPaga(array $m): array
{
    return Auth::tieneRol('admin', 'consulta')
        ? $m : array_diff_key($m, array_flip(['tarifa_por_alumno', 'tarifa_por_hora', 'bono_puntualidad', 'modo_pago']));
}

try {
    switch ($metodo) {
        case 'GET':
            if (isset($_GET['id'])) {
                $maestro = $modelo->obtener((int)$_GET['id']);
                if (!$maestro) Respuesta::error('Maestro no encontrado', 404);
                $maestro['instrumentos'] = $modelo->instrumentosDe((int)$_GET['id']);
                Respuesta::exito(sinPaga($maestro));
            } elseif (isset($_GET['instrumento'])) {
                Respuesta::exito(array_map('sinPaga', $modelo->porInstrumento((int)$_GET['instrumento'])));
            } else {
                Respuesta::exito(array_map('sinPaga', $modelo->listar()));
            }
            break;

        case 'POST':
            $datos = Respuesta::cuerpoJson();
            if (empty($datos['nombre']) || empty($datos['apellido'])) {
                Respuesta::error('Nombre y apellido son obligatorios');
            }
            $id = $modelo->crear($datos);
            Respuesta::exito(['id_maestro' => $id], 201);
            break;

        case 'PUT':
            // Reactivar: enviar { "id_maestro": X, "activo": true } junto con los demás campos,
            // o simplemente usar ?accion=reactivar para reactivar sin tocar el resto de datos.
            if (isset($_GET['accion']) && $_GET['accion'] === 'reactivar') {
                $id = (int) ($_GET['id'] ?? 0);
                if (!$id) Respuesta::error('id es requerido');
                $modelo->reactivar($id);
                Respuesta::exito(['reactivado' => true]);
                break;
            }
            $datos = Respuesta::cuerpoJson();
            if (empty($datos['id_maestro'])) Respuesta::error('id_maestro es requerido');
            $modelo->actualizar((int)$datos['id_maestro'], $datos);
            // Se informa cuántos renglones de nómina se ajustaron con la tarifa nueva,
            // para que la pantalla lo diga en vez de dejarlo en silencio.
            $renglones = $modelo->renglonesRecalculados;
            Respuesta::exito(['actualizado' => true, 'nomina_actualizada' => $renglones]);
            break;

        case 'DELETE':
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) Respuesta::error('id es requerido');
            // Si tiene clases, pasan a otro maestro en el mismo paso.
            if (!empty($_GET['pasar_a'])) {
                require_once __DIR__ . '/../services/InscripcionService.php';
                $r = (new InscripcionService())->pasarClasesDeMaestro($id, (int) $_GET['pasar_a'],
                        $_GET['desde'] ?? null, Auth::usuarioActual()['nombre'] ?? null);
                Respuesta::exito(['desactivado' => true] + $r);
            }
            // Con clases activas se niega y dice cuáles (desactivar lo comprueba).
            $modelo->desactivar($id);
            Respuesta::exito(['desactivado' => true]);
            break;

        default:
            Respuesta::error('Método no soportado', 405);
    }
} catch (Throwable $e) {
    Respuesta::fallo($e, 400);
}

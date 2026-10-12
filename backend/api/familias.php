<?php
/**
 * Familias: hermanos que paga una misma persona.
 *   GET                         -> todas, con sus alumnos
 *   GET  ?id=N                  -> una
 *   GET  ?accion=sugerencias    -> hermanos probables (mismo tutor o teléfono)
 *   GET  ?id=N&accion=cuenta    -> lo que debe cada hijo, clase por clase
 *   POST {nombre, responsable?, telefono?, descuento_mensual?, descuento_desde?, notas?, miembros: [id_alumno]}
 *   PUT  ?id=N {…}              -> corrige la familia y quiénes la forman
 *   DELETE ?id=N                -> deshace la familia (pagos y cuentas no cambian)
 *   POST ?accion=cobrar {id_familia, clases: [clave], monto, metodo, condonar_recargo?, simular?, clave?}
 */
require_once __DIR__ . '/../config/Respuesta.php';
require_once __DIR__ . '/../config/Auth.php';
require_once __DIR__ . '/../models/FamiliaModel.php';
require_once __DIR__ . '/../models/CuentaModel.php';

Respuesta::iniciar();
Auth::requerirLogin();
if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'DELETE'], true)) { Auth::exigirCsrf(); Auth::requerirRol('admin', 'recepcion'); }

$modelo = new FamiliaModel();
$id = (int) ($_GET['id'] ?? 0);
$accion = $_GET['accion'] ?? '';

try {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            if ($accion === 'sugerencias') Respuesta::exito($modelo->sugerencias());
            if ($id && $accion === 'cuenta') {
                Respuesta::exito((new CuentaModel())->cuentaFamiliar($id, ($_GET['siguiente'] ?? '') === '1'));
            }
            if ($id) {
                $f = $modelo->obtener($id);
                if (!$f) Respuesta::error('Esa familia no existe.', 404);
                Respuesta::exito($f);
            }
            Respuesta::exito($modelo->listar());
            break;

        case 'POST':
            $d = Respuesta::cuerpoJson();
            if ($accion === 'cobrar') {
                $r = (new CuentaModel())->cobrarFamilia(
                    (int) ($d['id_familia'] ?? 0), (array) ($d['clases'] ?? []),
                    (float) ($d['monto'] ?? 0), (string) ($d['metodo'] ?? 'efectivo'),
                    !empty($d['condonar_recargo']), !empty($d['simular']),
                    Auth::usuarioActual()['nombre'] ?? null, !empty($d['siguiente']),
                    isset($d['clave']) ? (string) $d['clave'] : null
                );
                Respuesta::exito($r, (!empty($d['simular']) || !empty($r['repetido'])) ? 200 : 201);
            }
            Respuesta::exito(['id_familia' => $modelo->guardar(null, $d)], 201);
            break;

        case 'PUT':
            if (!$id) Respuesta::error('Falta la familia.');
            Respuesta::exito(['id_familia' => $modelo->guardar($id, Respuesta::cuerpoJson())]);
            break;

        case 'DELETE':
            if (!$id) Respuesta::error('Falta la familia.');
            $modelo->deshacer($id);
            Respuesta::exito(['deshecha' => true]);
            break;

        default:
            Respuesta::error('Método no soportado', 405);
    }
} catch (Throwable $e) {
    Respuesta::fallo($e, 400);
}

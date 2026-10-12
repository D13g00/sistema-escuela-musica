<?php
/**
 *  GET  ?alumno=N                      -> pagos de un alumno
 *  GET  ?accion=cotizar&alumno=N&periodo=YYYY-MM
 *       -> cuánto debe, con recargo si ya venció
 *  GET  [?limite=N&antes_fecha=YYYY-MM-DD&antes_id=N&q=texto]
 *       -> pagos registrados, del más nuevo al más viejo (máx. 200 por página; q busca por nombre)
 *  POST {id_alumno, monto, periodo, metodo, condonar_recargo?}
 *  PUT  ?id=N {monto?, metodo?, confirmar?, motivo?}  -> corrige un pago, con bitácora
 *  GET  ?accion=por_confirmar                         -> pagos con importe sin confirmar
 *  GET  ?accion=cambios&id=N                          -> bitácora de un pago
 *  GET  ?accion=sin_confirmar                         -> meses de antes del corte sin confirmar
 *  PUT  ?id=N&accion=anular {motivo}                  -> anula un pago con un contramovimiento (admin)
 *  POST ?accion=confirmar_historico {id_alumno, hasta?} -> "¿hasta qué mes pagó?" (admin)
 *  POST ?accion=cobrar {id_alumno, monto, metodo, condonar_recargo?, simular?, clave?}
 *  POST ?accion=inscripcion {id_alumno, monto?, metodo, clave?}  -> cobra la inscripción (concepto 'inscripcion')
 */
require_once __DIR__ . '/../config/Respuesta.php';
require_once __DIR__ . '/../config/Auth.php';
require_once __DIR__ . '/../models/PagoModel.php';
require_once __DIR__ . '/../models/CuentaModel.php';

Respuesta::iniciar();
Auth::requerirLogin();
if (in_array($_SERVER['REQUEST_METHOD'], ['POST','PUT','DELETE'], true)) { Auth::exigirCsrf(); Auth::requerirRol('admin', 'recepcion'); }

$modelo = new PagoModel();
$metodo = $_SERVER['REQUEST_METHOD'];
$accion = $_GET['accion'] ?? '';

try {
    switch ($metodo) {
        case 'GET':
            if ($accion === 'cuenta') {
                $id = (int) ($_GET['alumno'] ?? 0);
                if (!$id) Respuesta::error('alumno es requerido');
                Respuesta::exito((new CuentaModel())->estado($id));
            }
            if ($accion === 'deudores') {
                Respuesta::exito((new CuentaModel())->deudores());
            }
            if ($accion === 'cotizar') {
                $id = (int) ($_GET['alumno'] ?? 0);
                if (!$id) Respuesta::error('alumno es requerido');
                Respuesta::exito($modelo->cotizar($id, $_GET['periodo'] ?? date('Y-m')));
            }
            if ($accion === 'muestras') {
                Respuesta::exito($modelo->clasesMuestra());
            }
            if ($accion === 'por_confirmar') {
                // Con ?id=N, solo ese pago (o null si ya está confirmado).
                if (isset($_GET['id'])) {
                    Respuesta::exito($modelo->porConfirmar((int) $_GET['id'])[0] ?? null);
                }
                Respuesta::exito($modelo->porConfirmar());
            }
            if ($accion === 'cambios') {
                Respuesta::exito($modelo->cambios((int) ($_GET['id'] ?? 0)));
            }
            if ($accion === 'sin_confirmar') {
                Auth::requerirRol('admin', 'consulta');
                Respuesta::exito((new CuentaModel())->mesesPorConfirmar());
            }
            if (isset($_GET['alumno'])) {
                Respuesta::exito($modelo->listarPorAlumno((int) $_GET['alumno']));
            }
            // Lista por páginas: ?limite=N (máx. 200) y, para seguir, la fecha y el id del
            // último pago recibido (?antes_fecha=YYYY-MM-DD&antes_id=N). ?q=texto busca por
            // nombre en todos los pagos, no solo en los que ya se ven.
            $antesId = isset($_GET['antes_id']) && $_GET['antes_id'] !== '' ? (int) $_GET['antes_id'] : null;
            Respuesta::exito($modelo->listarTodos(
                (int) ($_GET['limite'] ?? 200),
                isset($_GET['antes_fecha']) ? (string) $_GET['antes_fecha'] : null,
                $antesId,
                (string) ($_GET['q'] ?? '')
            ));
            break;

        case 'PUT':
            // Corregir un pago: importe, método o confirmar el importe. Queda en la bitácora.
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) Respuesta::error('Falta el pago.');
            // Anular crea un contramovimiento que apunta al pago; no se borra nada.
            // Solo administración.
            if ($accion === 'anular') {
                Auth::requerirRol('admin');
                $d = Respuesta::cuerpoJson();
                Respuesta::exito($modelo->anular($id, (string) ($d['motivo'] ?? ''),
                                                 Auth::usuarioActual()['nombre'] ?? null));
            }
            // Confirmar el importe de un pago "por confirmar" lo hace recepción;
            // corregir uno que ya estaba confirmado es de administración.
            $conf = Database::conexion()->prepare("SELECT monto_confirmado FROM pagos WHERE id_pago = ?");
            $conf->execute([$id]);
            if ((int) $conf->fetchColumn() === 1) Auth::requerirRol('admin');
            Respuesta::exito($modelo->corregir($id, Respuesta::cuerpoJson(),
                                               Auth::usuarioActual()['nombre'] ?? null));
            break;

        case 'POST':
            $datos = Respuesta::cuerpoJson();

            // "¿Hasta qué mes pagó?": confirma de una vez los meses de antes del
            // corte. Solo administración.
            if ($accion === 'confirmar_historico') {
                Auth::requerirRol('admin');
                $id = filter_var($datos['id_alumno'] ?? null, FILTER_VALIDATE_INT);
                if (!$id) Respuesta::error('Falta el alumno.');
                $hasta = isset($datos['hasta']) && $datos['hasta'] !== '' ? (string) $datos['hasta'] : null;
                Respuesta::exito((new CuentaModel())->confirmarHistorico(
                    (int) $id, $hasta, Auth::usuarioActual()['nombre'] ?? null
                ));
            }

            // Cobro de colegiatura con abonos: se reparte del mes más atrasado
            // al más nuevo, y si sobra, se adelanta.
            if ($accion === 'cobrar') {
                $id = filter_var($datos['id_alumno'] ?? null, FILTER_VALIDATE_INT);
                $monto = filter_var($datos['monto'] ?? null, FILTER_VALIDATE_FLOAT);
                if (!$id || $monto === false) Respuesta::error('Faltan el alumno o el monto.');
                // La clave evita cobrar dos veces lo mismo (doble clic, red que reintenta).
                $r = (new CuentaModel())->cobrar(
                    (int) $id, (float) $monto,
                    (string) ($datos['metodo'] ?? 'efectivo'),
                    !empty($datos['condonar_recargo']),
                    !empty($datos['simular']),
                    Auth::usuarioActual()['nombre'] ?? null,
                    isset($datos['clave']) ? (string) $datos['clave'] : null
                );
                Respuesta::exito($r, (!empty($datos['simular']) || !empty($r['repetido'])) ? 200 : 201);
            }

            // Inscripción cobrada por separado (si se olvidó en el alta). No toca el reparto de meses.
            if ($accion === 'inscripcion') {
                $id = filter_var($datos['id_alumno'] ?? null, FILTER_VALIDATE_INT);
                if (!$id) Respuesta::error('Falta el alumno.');
                $monto = isset($datos['monto']) && $datos['monto'] !== '' ? filter_var($datos['monto'], FILTER_VALIDATE_FLOAT) : null;
                if ($monto === false) Respuesta::error('El importe de la inscripción tiene que ser un número.');
                $r = (new CuentaModel())->cobrarInscripcion(
                    (int) $id, $monto, (string) ($datos['metodo'] ?? 'efectivo'),
                    Auth::usuarioActual()['nombre'] ?? null,
                    isset($datos['clave']) ? (string) $datos['clave'] : null
                );
                Respuesta::exito($r, !empty($r['repetido']) ? 200 : 201);
            }

            if ($accion === 'muestra') {
                // Recepción cobra la clase muestra; lo que se le suma al maestro
                // es lo que vale su clase, salvo que administración diga otra cosa.
                $admin = Auth::tieneRol('admin');
                if (!$admin) unset($datos['pago_maestro']);
                $r = $modelo->registrarClaseMuestra($datos, Auth::usuarioActual()['nombre'] ?? null);
                if (!$admin) unset($r['al_maestro']);
                Respuesta::exito($r, 201);
            }

            if (empty($datos['id_alumno']) || !isset($datos['monto'])) {
                Respuesta::error('id_alumno y monto son obligatorios');
            }
            // Blindaje de tipos: el método de pago solo puede ser uno de los tres.
            if (isset($datos['metodo']) &&
                !in_array($datos['metodo'], ['efectivo', 'transferencia', 'tarjeta'], true)) {
                Respuesta::error('Método de pago no válido.');
            }
            // La validación fina (monto positivo, alumno existente, periodo) la
            // hace el modelo, que es quien toca la base.
            Respuesta::exito($modelo->registrar($datos, Auth::usuarioActual()['nombre'] ?? null), 201);
            break;

        default:
            Respuesta::error('Método no soportado', 405);
    }
} catch (Throwable $e) {
    Respuesta::fallo($e, 400);
}

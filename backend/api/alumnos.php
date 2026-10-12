<?php
require_once __DIR__ . '/../config/Respuesta.php';
require_once __DIR__ . '/../config/Auth.php';
require_once __DIR__ . '/../models/AlumnoModel.php';

Respuesta::iniciar();
$modelo = new AlumnoModel();
$metodo = $_SERVER['REQUEST_METHOD'];

// Todo requiere sesión: el directorio de alumnos y maestros es dato personal,
// incluidos menores, tutores y domicilios. El token CSRF, solo en escrituras.
Auth::requerirLogin();
if (in_array($metodo, ['POST', 'PUT', 'DELETE'], true)) {
    Auth::exigirCsrf();
    Auth::requerirRol('admin', 'recepcion');
}

try {
    switch ($metodo) {
        case 'GET':
            if (($_GET['accion'] ?? '') === 'ficha') {
                $id = (int) ($_GET['id'] ?? 0);
                if (!$id) Respuesta::error('id es requerido');
                Respuesta::exito($modelo->ficha($id));
            }
            if (isset($_GET['id'])) {
                $alumno = $modelo->obtener((int)$_GET['id']);
                $alumno ? Respuesta::exito($alumno) : Respuesta::error('Alumno no encontrado', 404);
            } else {
                $soloActivos = isset($_GET['activos']) && $_GET['activos'] === '1';
                Respuesta::exito($modelo->listar($soloActivos));
            }
            break;

        case 'POST':
            $datos = Respuesta::cuerpoJson();
            if (empty($datos['nombre']) || empty($datos['apellido'])) {
                Respuesta::error('Nombre y apellido son obligatorios');
            }
            $id = $modelo->crear($datos);
            // Si venía de un borrador, queda ligado al alumno hasta que se complete la clase.
            if (!empty($datos['id_borrador'])) {
                require_once __DIR__ . '/../models/BorradorModel.php';
                (new BorradorModel())->vincularAlumno((int) $datos['id_borrador'], $id);
            }
            Respuesta::exito(['id_alumno' => $id], 201);
            break;

        case 'PUT':
            if (($_GET['accion'] ?? '') === 'reactivar') {
                $id = (int) ($_GET['id'] ?? 0);
                if (!$id) Respuesta::error('id es requerido');
                $modelo->reactivar($id);
                Respuesta::exito(['reactivado' => true]);
                break;
            }
            $datos = Respuesta::cuerpoJson();
            if (empty($datos['id_alumno'])) Respuesta::error('id_alumno es requerido');
            $modelo->actualizar((int)$datos['id_alumno'], $datos);
            Respuesta::exito(['actualizado' => true]);
            break;

        case 'DELETE':
            // Baja general del alumno (deja de estar activo en la escuela).
            // Nota: si el alumno tiene inscripciones activas, primero deben
            // darse de baja individualmente desde el módulo de Inscripciones,
            // para que se libere su horario y salga de la nómina del maestro.
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) Respuesta::error('id es requerido');
            // Una baja sin adeudo ("no debía existir") es una corrección financiera.
            if (($_GET['adeudo'] ?? '1') === '0') Auth::requerirRol('admin');
            // Con sus clases, en un solo paso: o se dan de baja todas, o ninguna.
            require_once __DIR__ . '/../services/InscripcionService.php';
            $tomadas = json_decode((string) ($_GET['clases'] ?? ''), true);
            $r = (new InscripcionService())->darDeBajaAlumno($id, [
                'fecha_baja'       => $_GET['fecha'] ?? null,
                'conservar_adeudo' => ($_GET['adeudo'] ?? '1') !== '0',
                'clases_tomadas'   => is_array($tomadas) ? $tomadas : [],
                'usuario'          => Auth::usuarioActual()['nombre'] ?? null,
            ]);
            Respuesta::exito(['desactivado' => true] + $r);
            break;

        default:
            Respuesta::error('Método no soportado', 405);
    }
} catch (Throwable $e) {
    Respuesta::fallo($e, 400);
}

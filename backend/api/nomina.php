<?php
/**
 *  GET  ?periodo=2026-02            -> nómina del mes agrupada por maestro
 *  GET  ?maestro=3&periodo=2026-02  -> detalle de un maestro
 *  GET  ?accion=periodos            -> meses con nómina registrada
 *  POST ?accion=generar   {periodo} -> arma la nómina del mes desde las inscripciones
 *  POST ?accion=pagar     {id_maestro, periodo, pagado}
 *
 *  Correcciones a la nómina de un mes (cualquiera), solo administración. Cambian solo lo que se le
 *  paga al maestro; llevan motivo obligatorio y quedan en la bitácora:
 *  POST ?accion=quitar_alumno   {id_maestro, periodo, id_alumno, motivo}
 *  GET  ?accion=clases_alumno&id_alumno=N&maestro=M&periodo=P   -> clases del alumno con el importe sugerido
 *  POST ?accion=agregar_alumno  {id_maestro, periodo, id_inscripcion, clases?, monto?, motivo}
 *  POST ?accion=clase_extra     {id_maestro, fecha, minutos, id_alumno?, monto?, motivo}
 */
require_once __DIR__ . '/../config/Respuesta.php';
require_once __DIR__ . '/../config/Auth.php';
require_once __DIR__ . '/../models/NominaModel.php';
require_once __DIR__ . '/../models/AjusteNominaModel.php';

Respuesta::iniciar();
Auth::requerirLogin();
if (in_array($_SERVER['REQUEST_METHOD'], ['POST','PUT','DELETE'], true)) { Auth::exigirCsrf(); }
$modelo = new NominaModel();
$ajustes = new AjusteNominaModel();
$accion = $_GET['accion'] ?? '';

// Recepción registra faltas y suplencias (y ve las clases del día para eso),
// sin importes. Ver la nómina es de administración y consulta; cambiarla,
// solo de administración.
$paraFaltas = ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'falta')
           || ($_SERVER['REQUEST_METHOD'] === 'GET' && $accion === 'clases_dia');
if ($paraFaltas) {
    Auth::requerirRol('admin', 'recepcion');
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && $accion === 'clases_alumno') {
    Auth::requerirRol('admin');
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    Auth::requerirRol('admin', 'consulta');
} else {
    Auth::requerirRol('admin');
}
$conImportes = Auth::tieneRol('admin');
$periodo = $_GET['periodo'] ?? null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && $accion === 'ajuste') {
        $ajustes->eliminar((int) ($_GET['id'] ?? 0));
        Respuesta::exito(['eliminado' => true]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $datos = Respuesta::cuerpoJson();

        if ($accion === 'falta') {
            $clases = is_array($datos['clases'] ?? null) ? $datos['clases'] : [];
            // El importe de un descuento o de una suplencia lo decide administración;
            // recepción registra la falta y se usa lo que vale la clase.
            if (!$conImportes) {
                $clases = array_map(fn($c) => array_diff_key((array) $c, ['monto' => 1, 'monto_suplente' => 1]), $clases);
            }
            $suplente = !empty($datos['id_suplente']) ? (int) $datos['id_suplente'] : null;
            $r = $ajustes->registrarFalta(
                (int) ($datos['id_maestro'] ?? 0),
                (string) ($datos['fecha'] ?? ''),
                $clases,
                $suplente,
                isset($datos['motivo']) ? (string) $datos['motivo'] : null,
                Auth::usuarioActual()['nombre'] ?? null
            );
            // Recepción sabe que quedó registrada; los importes son de administración.
            Respuesta::exito($conImportes ? $r : ['clases' => $r['clases']], 201);
        }
        if ($accion === 'generar') {
            Respuesta::exito($modelo->generarPeriodo($datos['periodo'] ?? date('Y-m')));
        }
        if ($accion === 'ajustar') {
            $modelo->ajustarMonto(
                (int) ($datos['id_detalle'] ?? 0), (float) ($datos['monto'] ?? 0),
                isset($datos['motivo']) ? (string) $datos['motivo'] : null,
                Auth::usuarioActual()['nombre'] ?? null
            );
            Respuesta::exito(['ajustado' => true]);
        }
        $usuario = Auth::usuarioActual()['nombre'] ?? null;
        $numero = fn($v) => ($v === null || $v === '') ? null : (float) $v;
        if ($accion === 'quitar_alumno') {
            Respuesta::exito($modelo->quitarAlumnoDelMesDetallado(
                (int) ($datos['id_maestro'] ?? 0), (string) ($datos['periodo'] ?? ''), (int) ($datos['id_alumno'] ?? 0),
                (string) ($datos['motivo'] ?? ''), $usuario));
        }
        if ($accion === 'agregar_alumno') {
            $id = $modelo->agregarAlumnoAlMes(
                (int) ($datos['id_maestro'] ?? 0), (string) ($datos['periodo'] ?? ''), (int) ($datos['id_inscripcion'] ?? 0),
                ($datos['clases'] ?? '') === '' || $datos['clases'] === null ? null : (int) $datos['clases'],
                $numero($datos['monto'] ?? null), (string) ($datos['motivo'] ?? ''), $usuario);
            Respuesta::exito(['id_detalle' => $id, 'monto' => (float) ($modelo->renglon($id)['monto'] ?? 0)], 201);
        }
        if ($accion === 'clase_extra') {
            Respuesta::exito($ajustes->registrarClaseExtra(
                (int) ($datos['id_maestro'] ?? 0), (string) ($datos['fecha'] ?? ''), (int) ($datos['minutos'] ?? 0),
                !empty($datos['id_alumno']) ? (int) $datos['id_alumno'] : null,
                $numero($datos['monto'] ?? null), (string) ($datos['motivo'] ?? ''), $usuario), 201);
        }
        if ($accion === 'pagar') {
            $n = $modelo->marcarPagado(
                (int) ($datos['id_maestro'] ?? 0),
                $datos['periodo'] ?? date('Y-m'),
                (bool) ($datos['pagado'] ?? true),
                isset($datos['motivo']) ? (string) $datos['motivo'] : null,
                Auth::usuarioActual()['nombre'] ?? null
            );
            Respuesta::exito(['renglones' => $n]);
        }
        Respuesta::error('Acción no válida', 404);
    }

    if ($accion === 'clases_dia') {
        $dia = $ajustes->clasesDelDia((int) ($_GET['maestro'] ?? 0), (string) ($_GET['fecha'] ?? ''));
        if (!$conImportes) {
            // Sin lo que cobra el maestro: solo sus datos y sus clases.
            $dia['maestro'] = array_intersect_key((array) $dia['maestro'], array_flip(['id_maestro', 'nombre', 'apellido']));
            foreach (['clases', 'sin_horario'] as $k) {
                $dia[$k] = array_map(fn($c) => array_diff_key($c, ['valor' => 1]), $dia[$k] ?? []);
            }
        }
        Respuesta::exito($dia);
    }
    if ($accion === 'clases_alumno') {
        Respuesta::exito($modelo->clasesDeAlumno((int) ($_GET['id_alumno'] ?? 0), (int) ($_GET['maestro'] ?? 0),
            (string) ($periodo ?: date('Y-m'))));
    }
    if ($accion === 'ajustes') {
        Respuesta::exito($ajustes->deMaestro((int) ($_GET['maestro'] ?? 0), $periodo ?: date('Y-m')));
    }
    if ($accion === 'recibo') {
        Respuesta::exito($modelo->recibo((int) ($_GET['maestro'] ?? 0), $periodo ?: date('Y-m')));
    }
    if ($accion === 'periodos') {
        Respuesta::exito($modelo->periodos());
    } elseif (isset($_GET['maestro'])) {
        Respuesta::exito($modelo->detalleDeMaestro((int) $_GET['maestro'], $periodo));
    } else {
        Respuesta::exito($modelo->resumenGeneral($periodo));
    }
} catch (Throwable $e) {
    Respuesta::fallo($e, 400);
}

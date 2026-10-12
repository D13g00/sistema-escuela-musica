<?php
/**
 *  GET                        -> todo el tablero de reportes
 *  GET ?balance=1             -> solo el balance del mes
 *  GET ?instrumento_mes=YYYY-MM
 */
require_once __DIR__ . '/../config/Respuesta.php';
require_once __DIR__ . '/../config/Auth.php';
require_once __DIR__ . '/../models/ReporteModel.php';

Respuesta::iniciar();
// Reportes de ingresos y nómina: administración y consulta.
Auth::requerirRol('admin', 'consulta');
$modelo = new ReporteModel();

try {
    if (isset($_GET['instrumento_mes'])) {
        Respuesta::exito($modelo->ingresosPorInstrumento($_GET['instrumento_mes']));
    }
    if (isset($_GET['balance'])) {
        Respuesta::exito($modelo->balanceMesActual());
    }
    if (isset($_GET['mensuales'])) {
        Respuesta::exito($modelo->ingresosMensuales());
    }

    // Tablero completo: una sola llamada para toda la pantalla.
    Respuesta::exito([
        'balance'        => $modelo->balanceMesActual(),
        'mensuales'      => $modelo->ingresosMensuales(),
        'por_materia'    => $modelo->porMateria(),
        'por_maestro'    => $modelo->porMaestro(),
        'ocupacion'      => $modelo->ocupacion(),
        'clases_muestra' => $modelo->clasesMuestra(),
        'adeudos'        => $modelo->adeudos(),
        'movimiento'     => $modelo->movimiento(),
    ]);
} catch (Throwable $e) {
    Respuesta::fallo($e, 400);
}

<?php
require_once __DIR__ . '/../config/Respuesta.php';
require_once __DIR__ . '/../config/Auth.php';
require_once __DIR__ . '/../models/DashboardModel.php';

Respuesta::iniciar();
Auth::requerirLogin();

// Al entrar se cierran los meses que ya terminaron: el cargo de cada mes
// queda guardado y deja de depender de cómo estén las clases hoy.
require_once __DIR__ . '/../models/CuentaModel.php';
try { (new CuentaModel())->cerrarMesesTerminados(); } catch (Throwable $e) {
    // No debe romper Inicio, pero sí quedar registrado.
    error_log('Sistema Escuela: no se pudieron cerrar los meses terminados: ' . $e->getMessage());
}

try {
    $resumen = (new DashboardModel())->resumen();
    // Lo que cobra cada maestro es de administración (y consulta).
    if (!Auth::tieneRol('admin', 'consulta')) {
        foreach (['nomina_mes', 'nomina_por_pagar', 'nomina_pendientes', 'nomina_urgentes', 'nomina_monto'] as $k) {
            unset($resumen[$k]);
        }
    }
    Respuesta::exito($resumen);
} catch (Throwable $e) {
    Respuesta::fallo($e, 400);
}

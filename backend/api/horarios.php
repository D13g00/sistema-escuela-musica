<?php
require_once __DIR__ . '/../config/Respuesta.php';
require_once __DIR__ . '/../config/Auth.php';
require_once __DIR__ . '/../models/HorarioModel.php';

Respuesta::iniciar();
// El horario lista alumnos, maestros y salones: es dato personal, no público.
Auth::requerirLogin();
$modelo = new HorarioModel();

try {
    // El grupo de un día (Dibujo y Pintura): todos sus alumnos con su horario.
    if (isset($_GET['grupo_de'])) {
        $g = $modelo->grupoDelDia((int) $_GET['grupo_de']);
        if (!$g) Respuesta::error('Esa clase no es de una materia de grupo.', 404);
        Respuesta::exito($g);
    }
    if (isset($_GET['salon'])) {
        Respuesta::exito($modelo->ocupacionPorSalon((int)$_GET['salon']));
    } elseif (isset($_GET['maestro'])) {
        Respuesta::exito($modelo->ocupacionPorMaestro((int)$_GET['maestro']));
    } else {
        Respuesta::exito($modelo->ocupacionCompleta());
    }
} catch (Throwable $e) {
    Respuesta::fallo($e, 400);
}

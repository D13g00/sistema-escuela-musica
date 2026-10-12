<?php
/**
 * Endpoint de autenticación.
 * Acciones: ?accion=login (POST), ?accion=logout (POST), ?accion=estado (GET)
 */

require_once __DIR__ . '/../config/Respuesta.php';
require_once __DIR__ . '/../config/Auth.php';
require_once __DIR__ . '/../config/Database.php';

Respuesta::iniciar();

$metodo = $_SERVER['REQUEST_METHOD'];
$accion = $_GET['accion'] ?? '';

/* ---------------- Iniciar sesión ---------------- */
if ($metodo === 'POST' && $accion === 'login') {
    $datos    = Respuesta::cuerpoJson();
    $usuario  = trim((string) ($datos['usuario'] ?? ''));
    $password = (string) ($datos['password'] ?? '');

    if ($usuario === '' || $password === '') {
        Respuesta::error('Escribe tu usuario y tu contraseña.');
    }
    // Mensaje único si falla, límite de intentos y sesión nueva: todo en Auth.
    $yo = Auth::entrar($usuario, $password);
    Respuesta::exito($yo + ['csrf' => Auth::tokenCsrf()]);
}

/* ---------------- Cerrar sesión ---------------- */
if ($metodo === 'POST' && $accion === 'logout') {
    Auth::salir();
    Respuesta::exito(['mensaje' => 'Sesión cerrada.']);
}

/* ---------------- Estado de la sesión ---------------- */
if ($metodo === 'GET' && $accion === 'estado') {
    $usuario = Auth::usuarioActual();
    Respuesta::exito([
        'autenticado' => $usuario !== null,
        'usuario'     => $usuario,
        'csrf'        => $usuario !== null ? Auth::tokenCsrf() : null,
    ]);
}

Respuesta::error('Acción no válida.', 404);

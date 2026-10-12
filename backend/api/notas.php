<?php
require_once __DIR__ . '/../config/Respuesta.php';
require_once __DIR__ . '/../config/Auth.php';
require_once __DIR__ . '/../models/NotaModel.php';

Respuesta::iniciar();
Auth::requerirLogin();
if (in_array($_SERVER['REQUEST_METHOD'], ['POST','PUT','DELETE'], true)) { Auth::exigirCsrf(); Auth::requerirRol('admin', 'recepcion'); }
$modelo = new NotaModel();
$metodo = $_SERVER['REQUEST_METHOD'];

try {
    switch ($metodo) {
        case 'GET':
            Respuesta::exito($modelo->listar());
            break;

        case 'POST':
            $datos = Respuesta::cuerpoJson();
            if (empty($datos['mensaje'])) Respuesta::error('El mensaje es obligatorio');
            $usuario = Auth::usuarioActual();
            $id = $modelo->crear($usuario['nombre'], $datos['mensaje']);
            Respuesta::exito(['id_nota' => $id], 201);
            break;

        case 'PUT':
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) Respuesta::error('id es requerido');
            $modelo->marcarResuelta($id);
            Respuesta::exito(['resuelto' => true]);
            break;

        case 'DELETE':
            Auth::requerirRol('admin');
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) Respuesta::error('id es requerido');
            $modelo->eliminar($id);
            Respuesta::exito(['eliminado' => true]);
            break;

        default:
            Respuesta::error('Método no soportado', 405);
    }
} catch (Throwable $e) {
    Respuesta::fallo($e, 400);
}

<?php
/**
 * Usuarios del sistema.
 *   GET                       -> lista                                   (admin)
 *   POST ?accion=password     {actual, nueva}   cambia la propia contraseña (cualquiera)
 *   POST ?accion=crear        {usuario, nombre, rol, password}          (admin)
 *   POST ?accion=restablecer  {id_usuario, nueva}                       (admin)
 *   PUT  ?accion=editar&id=N  {usuario, nombre, rol}                    (admin)
 *   PUT  ?accion=estado&id=N  {activo}                                  (admin)
 *
 * Siempre queda al menos un administrador activo: nadie puede quitarse (ni
 * quitarle al último) el acceso de administración.
 */
require_once __DIR__ . '/../config/Respuesta.php';
require_once __DIR__ . '/../config/Auth.php';
require_once __DIR__ . '/../config/Database.php';

Respuesta::iniciar();
Auth::requerirLogin();
if (in_array($_SERVER['REQUEST_METHOD'], ['POST','PUT','DELETE'], true)) { Auth::exigirCsrf(); }

$db     = Database::conexion();
$yo     = Auth::usuarioActual();
$accion = $_GET['accion'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'];

/** Reglas mínimas para una contraseña de trabajo */
function validarClave(string $clave): void
{
    if (mb_strlen($clave) < 8) {
        Respuesta::error('La contraseña debe tener al menos 8 caracteres.');
    }
    if (preg_match('/^\d+$/', $clave)) {
        Respuesta::error('No uses solo números. Mezcla letras y números.');
    }
    $comunes = ['admin123', 'password', '12345678', 'contrasena', 'qwertyui', 'escuela1234'];
    if (in_array(mb_strtolower($clave), $comunes, true)) {
        Respuesta::error('Esa contraseña es demasiado fácil de adivinar. Elige otra.');
    }
}

function validarUsuario(PDO $db, string $usuario, int $idPropio = 0): void
{
    if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $usuario)) {
        Respuesta::error('El usuario lleva de 3 a 50 letras, números, punto, guion o guion bajo, sin espacios.');
    }
    $existe = $db->prepare("SELECT COUNT(*) FROM usuarios WHERE usuario = ? AND id_usuario <> ?");
    $existe->execute([$usuario, $idPropio]);
    if ((int) $existe->fetchColumn() > 0) Respuesta::error('Ese usuario ya existe.');
}

function validarRol(string $rol): void
{
    if (!in_array($rol, Auth::ROLES, true)) Respuesta::error('Elige un rol válido.');
}

/** ¿Cuántos administradores activos quedarían sin ese usuario? */
function otrosAdmins(PDO $db, int $id): int
{
    $s = $db->prepare("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin' AND activo = 1 AND id_usuario <> ?");
    $s->execute([$id]);
    return (int) $s->fetchColumn();
}

function usuarioPorId(PDO $db, int $id): array
{
    $s = $db->prepare("SELECT id_usuario, usuario, nombre, rol, activo FROM usuarios WHERE id_usuario = ?");
    $s->execute([$id]);
    $u = $s->fetch();
    if (!$u) Respuesta::error('Ese usuario no existe.', 404);
    return $u;
}

try {
    // Cambiar la propia contraseña lo puede hacer cualquiera; todo lo demás, solo administración.
    if (!($metodo === 'POST' && $accion === 'password')) Auth::requerirRol('admin');

    if ($metodo === 'GET') {
        Respuesta::exito($db->query(
            "SELECT id_usuario, usuario, nombre, rol, activo, fecha_creacion
               FROM usuarios ORDER BY activo DESC, usuario"
        )->fetchAll());
    }

    if ($metodo === 'POST') {
        $datos = Respuesta::cuerpoJson();

        if ($accion === 'password') {
            $actual = (string) ($datos['actual'] ?? '');
            $nueva  = (string) ($datos['nueva'] ?? '');

            $stmt = $db->prepare("SELECT password_hash FROM usuarios WHERE id_usuario = ?");
            $stmt->execute([$yo['id_usuario']]);
            $hash = (string) $stmt->fetchColumn();

            if (!password_verify($actual, $hash)) {
                Respuesta::error('Tu contraseña actual no coincide.', 403);
            }
            if ($actual === $nueva) {
                Respuesta::error('La contraseña nueva tiene que ser distinta de la actual.');
            }
            validarClave($nueva);

            $db->prepare("UPDATE usuarios SET password_hash = ? WHERE id_usuario = ?")
               ->execute([password_hash($nueva, PASSWORD_BCRYPT), $yo['id_usuario']]);
            // Tus otras sesiones (otra computadora, otro navegador) se cierran; esta sigue.
            Auth::invalidarSesiones((int) $yo['id_usuario']);

            Respuesta::exito(['mensaje' => 'Contraseña actualizada.', 'csrf' => Auth::tokenCsrf()]);
        }

        if ($accion === 'crear') {
            $usuario = trim((string) ($datos['usuario'] ?? ''));
            $nombre  = trim((string) ($datos['nombre'] ?? ''));
            $rol     = (string) ($datos['rol'] ?? 'recepcion');
            $clave   = (string) ($datos['password'] ?? '');

            if ($usuario === '' || $nombre === '') Respuesta::error('El usuario y el nombre son obligatorios.');
            if (mb_strlen($nombre) > 100) Respuesta::error('El nombre es demasiado largo.');
            validarUsuario($db, $usuario);
            validarRol($rol);
            validarClave($clave);

            $db->prepare("INSERT INTO usuarios (usuario, password_hash, nombre, rol) VALUES (?, ?, ?, ?)")
               ->execute([$usuario, password_hash($clave, PASSWORD_BCRYPT), $nombre, $rol]);

            Respuesta::exito(['id_usuario' => (int) $db->lastInsertId()], 201);
        }

        if ($accion === 'restablecer') {
            $id    = (int) ($datos['id_usuario'] ?? 0);
            $nueva = (string) ($datos['nueva'] ?? '');
            if (!$id) Respuesta::error('id_usuario es requerido.');
            usuarioPorId($db, $id);
            validarClave($nueva);

            $db->prepare("UPDATE usuarios SET password_hash = ? WHERE id_usuario = ?")
               ->execute([password_hash($nueva, PASSWORD_BCRYPT), $id]);
            // Quien tuviera la sesión abierta con la contraseña vieja, queda fuera.
            Auth::invalidarSesiones($id);

            Respuesta::exito(['mensaje' => 'Contraseña restablecida.']);
        }

        Respuesta::error('Acción no válida.', 404);
    }

    if ($metodo === 'PUT' && $accion === 'editar') {
        $id = (int) ($_GET['id'] ?? 0);
        $u = usuarioPorId($db, $id);
        $datos = Respuesta::cuerpoJson();
        $usuario = trim((string) ($datos['usuario'] ?? $u['usuario']));
        $nombre  = trim((string) ($datos['nombre'] ?? $u['nombre']));
        $rol     = (string) ($datos['rol'] ?? $u['rol']);

        if ($nombre === '') Respuesta::error('Escribe el nombre de la persona.');
        if (mb_strlen($nombre) > 100) Respuesta::error('El nombre es demasiado largo.');
        validarUsuario($db, $usuario, $id);
        validarRol($rol);
        if ($u['rol'] === 'admin' && $rol !== 'admin' && (int) $u['activo'] === 1 && otrosAdmins($db, $id) === 0) {
            Respuesta::error('Es el único administrador activo: antes dale el rol de administrador a otra persona.');
        }

        $db->prepare("UPDATE usuarios SET usuario = ?, nombre = ?, rol = ? WHERE id_usuario = ?")
           ->execute([$usuario, $nombre, $rol, $id]);
        Respuesta::exito(['actualizado' => true]);
    }

    if ($metodo === 'PUT' && $accion === 'estado') {
        $id = (int) ($_GET['id'] ?? 0);
        $u = usuarioPorId($db, $id);
        $datos = Respuesta::cuerpoJson();
        $activo = !empty($datos['activo']) ? 1 : 0;

        if ($id === (int) $yo['id_usuario'] && !$activo) {
            Respuesta::error('No puedes desactivar tu propio usuario.');
        }
        if (!$activo && $u['rol'] === 'admin' && otrosAdmins($db, $id) === 0) {
            Respuesta::error('Es el único administrador activo: no se puede desactivar.');
        }

        // Desactivar cierra sus sesiones en la siguiente petición (Auth lo revisa siempre).
        $db->prepare("UPDATE usuarios SET activo = ? WHERE id_usuario = ?")->execute([$activo, $id]);
        Respuesta::exito(['activo' => (bool) $activo]);
    }

    Respuesta::error('Método no soportado.', 405);

} catch (Throwable $ex) {
    Respuesta::fallo($ex, 400);
}

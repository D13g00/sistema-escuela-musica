<?php
/**
 * Crea un administrador, o le pone contraseña nueva a uno que ya existe.
 * Para la primera instalación o si nadie puede entrar.
 *
 * SOLO desde la Terminal de la computadora servidor (no se abre desde el
 * navegador, y la carpeta está bloqueada para Apache):
 *
 *   Mac:      cd /Applications/XAMPP/xamppfiles/htdocs/escuela-musica/backend/herramientas
 *             /Applications/XAMPP/xamppfiles/bin/php crear_admin.php
 *   Windows:  cd C:\xampp\htdocs\escuela-musica\backend\herramientas
 *             C:\xampp\php\php.exe crear_admin.php
 *
 * No trae ninguna contraseña de fábrica: la escribe quien la corre.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config/Database.php';

function preguntar(string $texto, string $omision = ''): string
{
    echo $texto . ($omision !== '' ? " [$omision]" : '') . ': ';
    $r = trim((string) fgets(STDIN));
    return $r === '' ? $omision : $r;
}

/** Pide la contraseña sin mostrarla (en Mac/Linux); en Windows la consola no lo permite */
function preguntarClave(string $texto): string
{
    echo $texto . ': ';
    $oculta = DIRECTORY_SEPARATOR === '/' && @shell_exec('stty -echo 2>/dev/null') !== null;
    $r = rtrim((string) fgets(STDIN), "\r\n");
    if ($oculta) { @shell_exec('stty echo 2>/dev/null'); echo "\n"; }
    return $r;
}

function claveValida(string $clave): ?string
{
    if (mb_strlen($clave) < 8) return 'Debe tener al menos 8 caracteres.';
    if (preg_match('/^\d+$/', $clave)) return 'No uses solo números.';
    if (in_array(mb_strtolower($clave), ['admin123', 'password', '12345678', 'contrasena', 'qwertyui', 'escuela1234'], true)) {
        return 'Es demasiado fácil de adivinar.';
    }
    return null;
}

$db = Database::conexion();
echo "\nSistema Escuela — administrador\nBase: " . $db->query("SELECT DATABASE()")->fetchColumn() . "\n\n";

$usuario = preguntar('Usuario', 'admin');
if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $usuario)) exit("El usuario lleva de 3 a 50 letras, números, punto, guion o guion bajo.\n");

$s = $db->prepare("SELECT id_usuario, nombre FROM usuarios WHERE usuario = ?");
$s->execute([$usuario]);
$existe = $s->fetch();
$nombre = preguntar('Nombre de la persona', $existe['nombre'] ?? 'Administrador');

do {
    $clave = preguntarClave('Contraseña nueva');
    $error = claveValida($clave);
    if ($error) { echo "$error\n"; continue; }
    if (preguntarClave('Escríbela otra vez') !== $clave) { echo "No coinciden.\n"; $error = 'x'; }
} while ($error);

$hash = password_hash($clave, PASSWORD_BCRYPT);
if ($existe) {
    $db->prepare("UPDATE usuarios SET password_hash = ?, nombre = ?, rol = 'admin', activo = 1 WHERE id_usuario = ?")
       ->execute([$hash, $nombre, $existe['id_usuario']]);
    // Las sesiones abiertas con la contraseña anterior dejan de valer.
    try {
        $db->prepare("UPDATE usuarios SET version_sesion = version_sesion + 1 WHERE id_usuario = ?")->execute([$existe['id_usuario']]);
    } catch (PDOException $e) { /* base de antes de la 5.11 */ }
    echo "\nListo: $usuario es administrador y tiene contraseña nueva.\n";
} else {
    $db->prepare("INSERT INTO usuarios (usuario, password_hash, nombre, rol, activo) VALUES (?, ?, ?, 'admin', 1)")
       ->execute([$usuario, $hash, $nombre]);
    echo "\nListo: se creó el administrador $usuario.\n";
}

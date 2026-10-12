<?php
require_once __DIR__ . '/Respuesta.php';
require_once __DIR__ . '/Database.php';

/**
 * Sesiones, roles y acceso.
 *
 * Roles: admin (todo), recepcion (el trabajo del día: alumnos, clases,
 * cobros, lista de espera, borradores, faltas) y consulta (solo ver). El rol
 * se comprueba aquí, en el servidor; ocultar botones en la pantalla es solo
 * comodidad.
 *
 * La sesión se revisa contra la base en cada petición: si al usuario lo
 * desactivaron, le cambiaron la contraseña o le cambiaron el rol, se nota
 * de inmediato, no hasta que vuelva a entrar. Todo esto funciona también con
 * la base de antes de la 5.11, para que un administrador pueda entrar y
 * correr el actualizador.
 */
class Auth
{
    public const ROLES = ['admin', 'recepcion', 'consulta'];

    /** Minutos sin actividad antes de cerrar la sesión (credenciales.php puede cambiarlo) */
    private const MINUTOS_INACTIVIDAD = 60;

    /** Límite de intentos: por usuario desde un equipo, y por equipo en total */
    private const FALLOS_POR_USUARIO = 5;
    private const FALLOS_POR_EQUIPO  = 30;
    private const MINUTOS_BLOQUEO    = 15;

    /** Hash de una cadena al azar: se verifica contra él cuando el usuario no existe */
    private const HASH_DE_RELLENO = '$2y$10$ZQQT1Yv8.jFXKIBUmVRSxOsW0CZUIOcOtvjxN3bBl3MbYkPtIInS2';

    private const COOKIE = 'ESCUELA_SESION';

    private static bool $revisado = false;
    private static ?array $vigente = null;
    private static string $motivoCierre = '';

    // =================================================================
    // SESIÓN
    // =================================================================

    /**
     * @param bool $crear true solo al iniciar sesión. Sin cookie no se crea
     *                    sesión: una visita sin cuenta no deja archivos en el servidor.
     */
    public static function iniciarSesion(bool $crear = false): void
    {
        if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_NONE) return;   // en la Terminal no hay sesión
        session_name(self::COOKIE);
        if (!$crear && empty($_COOKIE[self::COOKIE])) return;

        // strict_mode: nadie puede imponer un id de sesión desde fuera.
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) (self::minutos() * 60 + 900));
        // Carpeta propia, fuera de lo público: otras aplicaciones del mismo
        // XAMPP no leen, no escriben ni limpian las sesiones del sistema.
        $dir = Respuesta::carpetaPrivada('escuela_sesiones');
        if ($dir) session_save_path($dir);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => self::rutaCookie(),
            'httponly' => true,    // JavaScript no puede leer la cookie
            'samesite' => 'Lax',   // no viaja en peticiones desde otros sitios
            'secure'   => self::esHttps(),
        ]);
        session_start();
    }

    /** La cookie vale solo para la carpeta del sistema, no para todo el servidor */
    private static function rutaCookie(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        return preg_match('#^(.*?/)backend/#', $script, $m) ? $m[1] : '/';
    }

    private static function esHttps(): bool
    {
        return !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    }

    private static function minutos(): int
    {
        $m = (int) (Database::credenciales()['sesion_minutos'] ?? self::MINUTOS_INACTIVIDAD);
        return max(5, min(720, $m));
    }

    /**
     * El usuario de esta sesión, revisado contra la base una vez por petición.
     * Cierra la sesión si pasó el tiempo de inactividad, si el usuario ya no
     * está activo o si su contraseña cambió desde que entró.
     */
    private static function vigente(): ?array
    {
        if (self::$revisado) return self::$vigente;
        self::$revisado = true;
        self::iniciarSesion();
        if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['id_usuario'])) return null;

        $ahora = time();
        if (isset($_SESSION['ultimo']) && $ahora - (int) $_SESSION['ultimo'] > self::minutos() * 60) {
            self::vaciar('inactividad');
            return null;
        }
        $u = self::leerUsuario((int) $_SESSION['id_usuario']);
        $versionCambio = $u && array_key_exists('version_sesion', $u)
                      && (int) $u['version_sesion'] !== (int) ($_SESSION['version'] ?? 1);
        if (!$u || !(int) $u['activo'] || $versionCambio) {
            self::vaciar('cambio');
            return null;
        }

        $_SESSION['ultimo'] = $ahora;
        $rol = in_array($u['rol'], self::ROLES, true) ? $u['rol'] : 'consulta';
        return self::$vigente = [
            'id_usuario' => (int) $u['id_usuario'],
            'usuario'    => $u['usuario'],
            'nombre'     => $u['nombre'],
            'rol'        => $rol,
        ];
    }

    /** Los datos del usuario; sin version_sesion si la base es de antes de la 5.11 */
    private static function leerUsuario(int $id): ?array
    {
        $db = Database::conexion();
        try {
            $s = $db->prepare("SELECT id_usuario, usuario, nombre, rol, activo, version_sesion FROM usuarios WHERE id_usuario = ?");
            $s->execute([$id]);
        } catch (PDOException $e) {
            $s = $db->prepare("SELECT id_usuario, usuario, nombre, rol, activo FROM usuarios WHERE id_usuario = ?");
            $s->execute([$id]);
        }
        return $s->fetch() ?: null;
    }

    private static function vaciar(string $motivo): void
    {
        $_SESSION = [];
        self::$vigente = null;
        self::$motivoCierre = $motivo;
    }

    public static function estaLogueado(): bool
    {
        return self::vigente() !== null;
    }

    /** Corta la ejecución con 401 si no hay sesión activa */
    public static function requerirLogin(): void
    {
        if (self::estaLogueado()) return;
        $mensaje = match (self::$motivoCierre) {
            'inactividad' => 'Tu sesión se cerró tras ' . self::minutos() . ' minutos sin actividad. Vuelve a entrar.',
            'cambio'      => 'Tu sesión se cerró porque cambió tu contraseña o tu acceso. Vuelve a entrar.',
            default       => 'Inicia sesión para continuar.',
        };
        Respuesta::error($mensaje, 401);
    }

    /** Corta con 403 si el usuario no tiene uno de esos roles */
    public static function requerirRol(string ...$roles): void
    {
        self::requerirLogin();
        if (!in_array(self::rol(), $roles, true)) {
            Respuesta::error('Tu usuario no tiene permiso para hacer esto. Si lo necesitas, pídeselo a un administrador.', 403);
        }
    }

    public static function tieneRol(string ...$roles): bool
    {
        return in_array(self::rol(), $roles, true);
    }

    public static function rol(): ?string
    {
        return self::vigente()['rol'] ?? null;
    }

    public static function usuarioActual(): ?array
    {
        return self::vigente();
    }

    /** Token CSRF de la sesión; se crea la primera vez que se pide */
    public static function tokenCsrf(): string
    {
        if (!self::estaLogueado()) return '';
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    /**
     * Exige el token en las operaciones que cambian datos. El front lo manda en
     * la cabecera X-CSRF-Token. Sin sesión no hay token que validar (esas
     * peticiones ya las corta requerirLogin).
     */
    public static function exigirCsrf(?string $enviado = null): void
    {
        if (!self::estaLogueado()) return;
        $enviado = $enviado ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $real    = $_SESSION['csrf'] ?? '';
        if ($real === '' || !hash_equals($real, (string) $enviado)) {
            Respuesta::error('Tu sesión no pudo verificarse. Recarga la página e inténtalo de nuevo.', 403);
        }
    }

    /**
     * Las sesiones abiertas de ese usuario dejan de valer (cambio de
     * contraseña, restablecimiento). La sesión de quien hace el cambio sigue
     * viva si es la suya.
     */
    public static function invalidarSesiones(int $idUsuario): void
    {
        $db = Database::conexion();
        try {
            $db->prepare("UPDATE usuarios SET version_sesion = version_sesion + 1 WHERE id_usuario = ?")->execute([$idUsuario]);
            if (session_status() === PHP_SESSION_ACTIVE && (int) ($_SESSION['id_usuario'] ?? 0) === $idUsuario) {
                $s = $db->prepare("SELECT version_sesion FROM usuarios WHERE id_usuario = ?");
                $s->execute([$idUsuario]);
                $_SESSION['version'] = (int) $s->fetchColumn();
                session_regenerate_id(true);
            }
        } catch (PDOException $e) {
            error_log('Sistema Escuela: sin version_sesion (falta correr el actualizador): ' . $e->getMessage());
        }
    }

    // =================================================================
    // ENTRAR Y SALIR
    // =================================================================

    /**
     * Entra si la contraseña es correcta. Responde 401 si no, y 429 si ese
     * usuario (desde ese equipo) o ese equipo acumulan demasiados intentos.
     */
    public static function entrar(string $usuario, string $password): array
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $espera = self::minutosDeBloqueo($usuario, $ip);
        if ($espera > 0) {
            Respuesta::error("Demasiados intentos fallidos. Espera $espera " . ($espera === 1 ? 'minuto' : 'minutos')
                . ' y vuelve a intentarlo.', 429);
        }

        $db = Database::conexion();
        $s = $db->prepare("SELECT * FROM usuarios WHERE usuario = ? AND activo = 1");
        $s->execute([$usuario]);
        $fila = $s->fetch() ?: null;

        // La contraseña se verifica siempre, exista o no el usuario: así el
        // tiempo de respuesta no delata qué usuarios existen.
        $correcta = password_verify($password, $fila['password_hash'] ?? self::HASH_DE_RELLENO) && $fila !== null;
        if (!$correcta) {
            self::registrarFallo($usuario, $ip);
            Respuesta::error('Usuario o contraseña incorrectos.', 401);
        }
        self::limpiarFallos($usuario, $ip);

        self::iniciarSesion(true);
        session_regenerate_id(true);   // id nuevo en cada inicio: evita la fijación de sesión
        $_SESSION = [
            'id_usuario' => (int) $fila['id_usuario'],
            'version'    => (int) ($fila['version_sesion'] ?? 1),
            'ultimo'     => time(),
        ];
        self::$revisado = false;
        self::$vigente = null;

        // Rehashea si el algoritmo quedó desactualizado.
        if (password_needs_rehash($fila['password_hash'], PASSWORD_BCRYPT)) {
            $db->prepare("UPDATE usuarios SET password_hash = ? WHERE id_usuario = ?")
               ->execute([password_hash($password, PASSWORD_BCRYPT), $fila['id_usuario']]);
        }
        return self::vigente() ?? [];
    }

    public static function salir(): void
    {
        self::iniciarSesion();
        if (session_status() !== PHP_SESSION_ACTIVE) return;
        $_SESSION = [];
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        session_destroy();
    }

    // =================================================================
    // INTENTOS FALLIDOS
    // =================================================================
    // Se cuentan en la base, por usuario desde un equipo y por equipo, no en
    // la sesión (que quien intenta adivinar puede tirar). Al pasar el límite,
    // se responde de inmediato con cuánto falta: no se retiene al servidor.

    private static function claves(string $usuario, string $ip): array
    {
        return ['u:' . mb_substr(mb_strtolower(trim($usuario)), 0, 60) . '|' . $ip, 'ip:' . $ip];
    }

    private static function minutosDeBloqueo(string $usuario, string $ip): int
    {
        try {
            $s = Database::conexion()->prepare(
                "SELECT MAX(TIMESTAMPDIFF(SECOND, NOW(), bloqueado_hasta)) FROM intentos_acceso
                  WHERE clave IN (?, ?) AND bloqueado_hasta > NOW()"
            );
            $s->execute(self::claves($usuario, $ip));
            $seg = (int) $s->fetchColumn();
            return $seg > 0 ? (int) ceil($seg / 60) : 0;
        } catch (PDOException $e) {
            return 0;   // base de antes de la 5.11: sin límite hasta correr el actualizador
        }
    }

    private static function registrarFallo(string $usuario, string $ip): void
    {
        [$porUsuario, $porEquipo] = self::claves($usuario, $ip);
        $db = Database::conexion();
        try {
            $cuenta = $db->prepare(
                "INSERT INTO intentos_acceso (clave, fallos, desde) VALUES (?, 1, NOW())
                 ON DUPLICATE KEY UPDATE
                    fallos = IF(desde < NOW() - INTERVAL " . self::MINUTOS_BLOQUEO . " MINUTE, 1, fallos + 1),
                    desde  = IF(desde < NOW() - INTERVAL " . self::MINUTOS_BLOQUEO . " MINUTE, NOW(), desde)"
            );
            $bloquea = $db->prepare(
                "UPDATE intentos_acceso SET bloqueado_hasta = NOW() + INTERVAL " . self::MINUTOS_BLOQUEO . " MINUTE,
                        fallos = 0, desde = NOW()
                  WHERE clave = ? AND fallos >= ?"
            );
            foreach ([[$porUsuario, self::FALLOS_POR_USUARIO], [$porEquipo, self::FALLOS_POR_EQUIPO]] as [$clave, $limite]) {
                $cuenta->execute([$clave]);
                $bloquea->execute([$clave, $limite]);
            }
            $db->exec("DELETE FROM intentos_acceso
                        WHERE desde < NOW() - INTERVAL 1 DAY AND (bloqueado_hasta IS NULL OR bloqueado_hasta < NOW())");
        } catch (PDOException $e) {
            error_log('Sistema Escuela: sin intentos_acceso (falta correr el actualizador): ' . $e->getMessage());
        }
    }

    private static function limpiarFallos(string $usuario, string $ip): void
    {
        try {
            Database::conexion()->prepare("DELETE FROM intentos_acceso WHERE clave = ?")
                ->execute([self::claves($usuario, $ip)[0]]);
        } catch (PDOException $e) {
            // base de antes de la 5.11
        }
    }
}

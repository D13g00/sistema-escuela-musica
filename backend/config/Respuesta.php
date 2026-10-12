<?php
/**
 * Zona horaria de la escuela. Sin esto PHP trabaja en UTC y, de las 18:00 en
 * adelante, las fechas se irían al día siguiente: los recargos y los cortes
 * del mes quedarían mal.
 */
date_default_timezone_set('America/Mexico_City');

/**
 * Respuestas JSON uniformes, cabeceras y manejo de errores.
 *
 * Errores: lo que ve la persona es un mensaje claro. Si el problema es
 * interno (una consulta que falló, un error de PHP), ve un mensaje general
 * con un folio, y el detalle (SQL, archivo, línea) queda en el registro de
 * errores, fuera de la carpeta pública. Así nunca se muestran rutas, tablas
 * ni consultas.
 *
 * CORS: la pantalla y la API se sirven desde el mismo lugar, así que no se
 * abre la API a ningún otro origen.
 */
class Respuesta
{
    /** Tope de lo que se acepta en el cuerpo de una petición (los Excel van aparte) */
    private const MAX_CUERPO = 2 * 1024 * 1024;

    public static function iniciar(): void
    {
        self::prepararErrores();
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Cache-Control: no-store');
        header_remove('X-Powered-By');

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    /**
     * Ningún error de PHP llega a la pantalla: va al registro. Si algo se
     * escapa (un error fatal, una excepción sin atrapar), la respuesta sigue
     * siendo JSON con un mensaje general, no una página de error de PHP.
     *
     * @param bool $json false en las páginas de mantenimiento, que son HTML
     */
    public static function prepararErrores(bool $json = true): void
    {
        static $listo = false;
        if ($listo) return;
        $listo = true;

        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        $dir = self::carpetaPrivada('escuela_registros');
        if ($dir) ini_set('error_log', $dir . DIRECTORY_SEPARATOR . 'errores.log');

        // Equipos permitidos (credenciales.php > redes_permitidas): si este equipo no está en la lista, 403 y se acabó.
        // Es el punto común de arranque de la API y de las herramientas de mantenimiento.
        require_once __DIR__ . '/../lib/RedesPermitidas.php';
        RedesPermitidas::exigir($json);

        if (!$json) return;
        set_exception_handler(function (Throwable $e) { self::fallo($e, 500); });
        register_shutdown_function(function () {
            $e = error_get_last();
            if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
            $folio = self::folio();
            error_log("Sistema Escuela [$folio] error fatal: {$e['message']} en {$e['file']}:{$e['line']}");
            if (PHP_SAPI === 'cli' || headers_sent()) return;
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => self::mensajeGeneral($folio)], JSON_UNESCAPED_UNICODE);
        });
    }

    /**
     * Una carpeta fuera de la parte pública para registros o sesiones:
     * junto a htdocs si se puede escribir ahí, si no, en la carpeta temporal
     * del sistema. Nunca dentro de lo que sirve Apache.
     */
    public static function carpetaPrivada(string $nombre): ?string
    {
        foreach ([dirname(__DIR__, 4), sys_get_temp_dir()] as $base) {
            if (!$base) continue;
            $dir = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $nombre;
            if ((is_dir($dir) || @mkdir($dir, 0700, true)) && is_writable($dir)) return $dir;
        }
        return null;
    }

    public static function exito($datos = [], int $codigo = 200): void
    {
        http_response_code($codigo);
        echo json_encode(['ok' => true, 'datos' => $datos], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Si la base todavía no tiene una columna o una tabla que el código nuevo
     * usa, el mensaje de MySQL no le dice nada a quien está en recepción.
     * Se traduce a la única instrucción que sirve: correr el actualizador.
     */
    private static function traducir(string $mensaje): string
    {
        $faltante = str_contains($mensaje, 'Unknown column')
                 || str_contains($mensaje, "doesn't exist")
                 || str_contains($mensaje, 'Base table or view not found');

        if ($faltante) {
            return 'La base de datos está desactualizada: le faltan columnas que esta versión '
                 . 'del sistema necesita. Un administrador debe abrir backend/actualizar_base.php y aplicar los cambios '
                 . 'pendientes; después recarga esta página con Ctrl+F5.';
        }
        return $mensaje;
    }

    /** Un mensaje pensado para la persona que está usando el sistema */
    public static function error(string $mensaje, int $codigo = 400): void
    {
        $mensaje = self::traducir($mensaje);
        http_response_code($codigo);
        echo json_encode(['ok' => false, 'error' => $mensaje], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Responde a una excepción. Las de negocio ("ese horario está ocupado")
     * llegan tal cual. Las internas (SQL, PHP) se registran completas y la
     * persona ve un mensaje general con folio.
     */
    public static function fallo(Throwable $e, int $codigo = 400): void
    {
        if (self::esInterno($e)) {
            $folio = self::registrar($e);
            $traducido = self::traducir($e->getMessage());
            if ($traducido !== $e->getMessage()) self::error($traducido, 500);
            self::error(self::mensajeGeneral($folio), 500);
        }
        self::error($e->getMessage(), $codigo);
    }

    /**
     * El texto que puede ver la persona para esta excepción: el suyo si es de
     * negocio; si es interna, un mensaje general con folio (y el detalle al
     * registro). Para las vistas previas que devuelven el error como dato.
     */
    public static function mensaje(Throwable $e): string
    {
        if (!self::esInterno($e)) return $e->getMessage();
        $traducido = self::traducir($e->getMessage());
        return $traducido !== $e->getMessage() ? $traducido : self::mensajeGeneral(self::registrar($e));
    }

    /** ¿Es un error del sistema, no un mensaje escrito para la persona? */
    private static function esInterno(Throwable $e): bool
    {
        if (!($e instanceof Exception) || $e instanceof PDOException) return true;
        return (bool) preg_match('/SQLSTATE|PDOException|Stack trace|Uncaught|mysqli/i', $e->getMessage());
    }

    private static function folio(): string
    {
        return strtoupper(bin2hex(random_bytes(3)));
    }

    private static function mensajeGeneral(string $folio): string
    {
        return "Algo falló en el servidor (folio $folio). Si vuelve a pasar, avísale al administrador con ese folio.";
    }

    /** Deja el detalle completo en el registro de errores; devuelve el folio */
    private static function registrar(Throwable $e): string
    {
        $folio = self::folio();
        $usuario = (session_status() === PHP_SESSION_ACTIVE) ? ($_SESSION['id_usuario'] ?? '-') : '-';
        error_log(sprintf("Sistema Escuela [%s] %s: %s en %s:%d | %s %s | usuario %s\n%s",
            $folio, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine(),
            $_SERVER['REQUEST_METHOD'] ?? 'CLI', $_SERVER['REQUEST_URI'] ?? '', $usuario,
            substr($e->getTraceAsString(), 0, 3000)));
        return $folio;
    }

    public static function cuerpoJson(): array
    {
        $texto = file_get_contents('php://input', false, null, 0, self::MAX_CUERPO + 1);
        if ($texto !== false && strlen($texto) > self::MAX_CUERPO) {
            self::error('Los datos enviados son demasiado grandes.', 413);
        }
        $entrada = json_decode((string) $texto, true);
        return is_array($entrada) ? $entrada : [];
    }
}

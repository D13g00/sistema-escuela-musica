<?php
require_once __DIR__ . '/Respuesta.php';
require_once __DIR__ . '/Auth.php';

/**
 * La puerta de las herramientas de mantenimiento (actualizar la base,
 * revertir una versión, comprobar la instalación).
 *
 *   · Desde la Terminal corren como siempre (así las usan las pruebas).
 *   · Desde el navegador piden una sesión de ADMINISTRADOR en el sistema, y lo
 *     que cambia la base solo se aplica con el botón (POST con el token de la
 *     sesión): abrir un enlace nunca aplica nada.
 *
 * Ya no dependen de "la petición viene de esta misma computadora": un proxy
 * o un acceso remoto pueden aparentarlo, y además así se pueden usar desde la
 * computadora de recepción cuando el servidor es otra máquina.
 */
final class Mantenimiento
{
    private static ?string $boton = null;
    private static bool $texto = false;

    /** @return bool si se pidió aplicar y está permitido */
    public static function puerta(): bool
    {
        if (PHP_SAPI === 'cli') {
            global $argv;
            return ($_GET['aplicar'] ?? ($argv[1] ?? '')) === '1';
        }

        Respuesta::prepararErrores(false);
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        header_remove('X-Powered-By');

        if (is_file(dirname(__DIR__) . '/.candado')) {
            self::pagina(403, 'Herramientas deshabilitadas',
                'Existe el archivo backend/.candado. Bórralo para volver a usar las herramientas de mantenimiento.');
        }
        if (!Auth::estaLogueado()) {
            self::pagina(401, 'Primero entra al sistema',
                'Entra al sistema con un usuario administrador en este mismo navegador y vuelve a abrir esta página.', true);
        }
        if (!Auth::tieneRol('admin')) {
            self::pagina(403, 'Solo administración', 'Tu usuario no puede usar las herramientas de mantenimiento.', true);
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || ($_POST['aplicar'] ?? '') !== '1') return false;
        if (!hash_equals(Auth::tokenCsrf(), (string) ($_POST['csrf'] ?? ''))) {
            self::pagina(403, 'La página caducó', 'Vuelve a abrirla y usa el botón otra vez. No se aplicó nada.');
        }
        return true;
    }

    /** El botón que aplica: un formulario POST con el token de la sesión */
    public static function formulario(string $texto, string $clase = 'boton'): string
    {
        if (PHP_SAPI === 'cli') return '';   // en la Terminal se aplica con el parámetro 1
        return '<form method="post" style="display:inline;margin:0">'
             . '<input type="hidden" name="csrf" value="' . self::h(Auth::tokenCsrf()) . '">'
             . '<input type="hidden" name="aplicar" value="1">'
             . '<button type="submit" class="' . self::h($clase) . '">' . self::h($texto) . '</button></form>';
    }

    /** Cómo se aplica, dicho según dónde se esté corriendo */
    public static function comoAplicar(): string
    {
        return PHP_SAPI === 'cli' ? 'corre de nuevo con el parámetro 1 para hacerlo' : 'usa el botón de abajo para hacerlo';
    }

    /**
     * Para las herramientas que escriben texto: en el navegador se muestra
     * escapado dentro de una página, y al final aparece el botón si se ofreció.
     */
    public static function textoInicio(string $titulo): void
    {
        if (PHP_SAPI === 'cli') return;
        self::$texto = true;
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
           . '<title>' . self::h($titulo) . '</title>' . self::estilos() . '</head><body><div class="caja"><h1>'
           . self::h($titulo) . '</h1><pre>';
        ob_start(fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8'));
        register_shutdown_function(function () {
            if (!self::$texto) return;
            while (ob_get_level() > 0) ob_end_flush();
            echo '</pre>';
            if (self::$boton !== null) echo '<p>' . self::formulario(self::$boton) . '</p>';
            echo '<p><a href="../frontend/">Ir al sistema</a></p></div></body></html>';
        });
    }

    /** Ofrece el botón para aplicar al terminar de mostrar lo que se haría */
    public static function ofrecer(string $texto): void
    {
        self::$boton = $texto;
    }

    /** Una página corta de aviso, y termina */
    public static function pagina(int $codigo, string $titulo, string $texto, bool $enlace = false): void
    {
        self::$texto = false;
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code($codigo);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
           . '<title>' . self::h($titulo) . '</title>' . self::estilos() . '</head><body><div class="caja"><h1>'
           . self::h($titulo) . '</h1><p>' . self::h($texto) . '</p>'
           . ($enlace ? '<p><a class="boton" href="../frontend/">Ir al sistema</a></p>' : '') . '</div></body></html>';
        exit;
    }

    private static function estilos(): string
    {
        return '<style>body{margin:0;background:#F5F5F7;color:#1D1D1F;padding:50px 20px;'
             . 'font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,system-ui,sans-serif}'
             . '.caja{max-width:760px;margin:0 auto}h1{font-size:26px;letter-spacing:-.02em;margin:0 0 14px}'
             . 'pre{background:#fff;border:1px solid #D2D2D7;border-radius:14px;padding:16px 18px;white-space:pre-wrap}'
             . '.boton,a.boton{display:inline-block;background:#0071E3;color:#fff;border:0;text-decoration:none;'
             . 'border-radius:980px;padding:11px 24px;font:inherit;font-weight:500;cursor:pointer}</style>';
    }

    private static function h($t): string
    {
        return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
    }
}

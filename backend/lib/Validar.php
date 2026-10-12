<?php
/**
 * Validaciones de datos que llegan de la pantalla. Cada una devuelve el valor
 * ya limpio o lanza un mensaje que se entiende en recepción. Con el modo
 * estricto de la base, un dato inválido ya no se guarda recortado ni como 0:
 * aquí se atrapa antes, con un mensaje claro.
 */
final class Validar
{
    public const METODOS = ['efectivo', 'transferencia', 'tarjeta'];

    /** Dinero: número, sin negativos y con un techo sano */
    public static function dinero($valor, string $que, float $maximo = 1000000.0): float
    {
        if ($valor === null || $valor === '') return 0.0;
        $n = filter_var($valor, FILTER_VALIDATE_FLOAT);
        if ($n === false || !is_finite($n)) throw new Exception("$que tiene que ser un número.");
        if ($n < 0) throw new Exception("$que no puede ser negativo.");
        if ($n > $maximo) throw new Exception("$que es demasiado alto. Revísalo.");
        return round($n, 2);
    }

    /** Un id opcional: vacío es null */
    public static function idOpcional($valor): ?int
    {
        if ($valor === null || $valor === '' || $valor === 0 || $valor === '0') return null;
        $n = filter_var($valor, FILTER_VALIDATE_INT);
        if ($n === false || $n <= 0) throw new Exception('Uno de los datos elegidos no es válido. Recarga la página.');
        return $n;
    }

    /** Método de pago: uno de los tres de siempre */
    public static function metodo($valor): string
    {
        $m = (string) ($valor ?? 'efectivo');
        if ($m === '') $m = 'efectivo';
        if (!in_array($m, self::METODOS, true)) throw new Exception('El método de pago tiene que ser efectivo, transferencia o tarjeta.');
        return $m;
    }

    /** Hora HH:MM o HH:MM:SS dentro del día → 'HH:MM:SS' */
    public static function hora($valor, string $que): string
    {
        $v = trim((string) $valor);
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:([0-5]\d))?$/', $v, $m)) {
            throw new Exception("La hora $que no es válida.");
        }
        return sprintf('%s:%s:%s', $m[1], $m[2], $m[4] ?? '00');
    }

    /** Minutos entre dos horas ya validadas */
    public static function minutosEntre(string $inicio, string $fin): int
    {
        return (int) round((strtotime("2000-01-01 $fin") - strtotime("2000-01-01 $inicio")) / 60);
    }

    /** Periodo AAAA-MM */
    public static function periodo($valor, string $que = 'El mes'): string
    {
        $v = (string) $valor;
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v)) throw new Exception("$que no es válido (AAAA-MM).");
        return $v;
    }
}

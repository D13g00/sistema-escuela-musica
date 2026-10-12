<?php
/**
 * Cuentas de calendario para cobrar y pagar por clases dadas.
 *
 * Un mes normal vale "clases al mes" (4 por omisión), aunque tenga cinco
 * miércoles. Solo cuando alguien entra a medio mes, o cambia de maestro a
 * medio mes, se cuentan las clases que de verdad caen en su tramo.
 */
class Calendario
{
    public const NUMERO_DIA = [
        'Lunes' => 1, 'Martes' => 2, 'Miercoles' => 3,
        'Jueves' => 4, 'Viernes' => 5, 'Sabado' => 6,
    ];

    /** Primer y último día de un periodo AAAA-MM */
    public static function limites(string $periodo): array
    {
        $ini = $periodo . '-01';
        return [$ini, date('Y-m-t', strtotime($ini))];
    }

    public static function fechaValida(?string $fecha): bool
    {
        if (!$fecha) return false;
        $d = DateTime::createFromFormat('Y-m-d', $fecha);
        return $d && $d->format('Y-m-d') === $fecha;
    }

    /** Cuántas veces cae ese día de la semana entre dos fechas, ambas incluidas */
    public static function sesionesEntre(string $dia, string $desde, string $hasta): int
    {
        $n = self::NUMERO_DIA[$dia] ?? null;
        if (!$n || $desde > $hasta) return 0;
        $t = strtotime($desde);
        $fin = strtotime($hasta);
        // Avanza al primer día que toca y de ahí cuenta de siete en siete.
        $t = strtotime('+' . (($n - (int) date('N', $t) + 7) % 7) . ' days', $t);
        if ($t > $fin) return 0;
        return 1 + intdiv((int) round(($fin - $t) / 86400), 7);
    }

    /**
     * Qué parte del mes le toca a un tramo.
     *
     * @param string|null $dia   día de la clase; sin día (clase sin horario)
     *                           se reparte por días del mes
     * @param string|null $desde primer día del tramo (null = desde el día 1)
     * @param string|null $hasta último día del tramo (null = hasta fin de mes)
     * @return array {clases: int|null (null = mes completo), desde, hasta}
     */
    public static function tramo(string $periodo, ?string $dia, ?string $desde, ?string $hasta, int $clasesAlMes): array
    {
        [$ini, $fin] = self::limites($periodo);
        $d = ($desde && $desde > $ini) ? $desde : $ini;
        $h = ($hasta && $hasta < $fin) ? $hasta : $fin;

        if ($d === $ini && $h === $fin) return ['clases' => null, 'desde' => null, 'hasta' => null];
        if ($d > $h) return ['clases' => 0, 'desde' => $d, 'hasta' => $h];

        if ($dia && isset(self::NUMERO_DIA[$dia])) {
            $clases = self::sesionesEntre($dia, $d, $h);
        } else {
            $dias = (int) date('t', strtotime($ini));
            $cubre = (int) round((strtotime($h) - strtotime($d)) / 86400) + 1;
            $clases = (int) round($clasesAlMes * $cubre / $dias);
        }
        $clases = min($clases, $clasesAlMes);

        // Si en su tramo caben todas las clases del mes, es un mes completo.
        if ($clases >= $clasesAlMes) return ['clases' => null, 'desde' => null, 'hasta' => null];
        return ['clases' => $clases, 'desde' => $d, 'hasta' => $h];
    }
}

<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../lib/Calendario.php';

/**
 * Historial de precio, duración y día de una clase, con fecha.
 *
 * inscripciones.costo_mensual, duracion_min y dia_semana siguen siendo lo VIGENTE
 * hoy: los usa todo el sistema. Cuando una clase cambia de duración (y de precio)
 * o de día a mitad de mes, el mes se parte en dos tramos: las clases de antes del
 * cambio se cobran (y se le pagan al maestro) como antes, las de desde el cambio
 * como ahora. Para poder calcularlo en cualquier momento (una baja después, un
 * segundo cambio, el cierre del mes) cada cambio deja una fila en
 * precios_clase: "desde esta fecha, la clase cuesta X, dura Y y se da el día Z".
 * Un cambio de día también deja su fila (aunque el precio no cambie): las clases
 * de antes se cuentan con el día de antes y las de después con el nuevo.
 *
 * Reglas:
 *   · Una clase sin filas se calcula exactamente como siempre.
 *   · La primera fila de una clase se guarda con vigente_desde = ORIGEN y lo
 *     que valía antes del primer cambio; así todo día tiene un tramo.
 *   · El último tramo siempre usa lo vigente en inscripciones (precio,
 *     duración y día): una corrección directa al precio nunca queda desfasada
 *     del historial.
 *   · Una clase sin día (sin horario) no usa tramos: se calcula como siempre.
 *   · Una fila con fecha posterior a hoy es un cambio PROGRAMADO (por ejemplo, el
 *     precio que baja el mes que entra porque este mes ya está pagado). Un cambio
 *     nuevo cancela lo programado que caiga después de su fecha y se vuelve a planear.
 *   · Una corrección de precio sin cambio de duración rige TODO el mes en curso,
 *     igual que en una clase sin historial (corregirPrecioDelMes).
 *   · Los meses que ya cerraron no se recalculan: cierran guardando su cargo.
 */
class HistorialClase
{
    /** Fecha de la primera fila: "desde siempre" */
    public const ORIGEN = '1000-01-01';

    /** @var array<int,array>|null id_inscripcion => filas, leído de una vez fuera de transacciones */
    private static ?array $cache = null;

    /** Olvida lo leído (tras escribir, o si un proceso cambia la tabla) */
    public static function olvidar(): void { self::$cache = null; }

    /**
     * Las filas de una clase, de la más vieja a la más nueva:
     * [['desde' => 'AAAA-MM-DD', 'precio' => float, 'duracion' => int|null, 'dia' => string|null], ...]
     */
    public static function de(int $idInscripcion, ?PDO $db = null): array
    {
        if ($idInscripcion <= 0) return [];
        $db = $db ?? Database::conexion();
        // Dentro de una transacción no se guarda nada en memoria: si se deshace, no quedaría basura.
        if ($db->inTransaction()) return self::leer($db, $idInscripcion)[$idInscripcion] ?? [];
        if (self::$cache === null) self::$cache = self::leer($db, null);
        return self::$cache[$idInscripcion] ?? [];
    }

    /**
     * Las filas de la clase para calcular su mes. Una clase sin día no usa tramos (se calcula como
     * siempre), así que no devuelve nada aunque tenga filas.
     */
    public static function filasDe(array $ins, ?PDO $db = null): array
    {
        if (empty($ins['dia_semana'])) return [];
        return self::de((int) ($ins['id_inscripcion'] ?? 0), $db);
    }

    /** ¿Alguna de estas clases tiene historial? (para no recalcular lo que no lo necesita) */
    public static function algunaTiene(array $inscripciones, ?PDO $db = null): bool
    {
        foreach ($inscripciones as $i) if (self::de((int) ($i['id_inscripcion'] ?? 0), $db)) return true;
        return false;
    }

    private static function leer(PDO $db, ?int $id): array
    {
        $r = [];
        try {
            $s = $db->prepare("SELECT id_inscripcion, vigente_desde, precio, duracion_min, dia_semana FROM precios_clase"
                . ($id !== null ? " WHERE id_inscripcion = ?" : "") . " ORDER BY id_inscripcion, vigente_desde");
            $s->execute($id !== null ? [$id] : []);
            foreach ($s->fetchAll() as $f) {
                $r[(int) $f['id_inscripcion']][] = ['desde' => $f['vigente_desde'], 'precio' => (float) $f['precio'],
                                                    'duracion' => $f['duracion_min'] === null ? null : (int) $f['duracion_min'],
                                                    'dia' => $f['dia_semana'] ?: null];
            }
        } catch (PDOException $e) {
            return [];    // base todavía sin migrar
        }
        return $r;
    }

    /**
     * Los tramos de la clase con sus fechas.
     *
     * @param array $filas   de()
     * @param float $precio  precio vigente hoy (inscripciones.costo_mensual)
     * @param int|null $duracion duración vigente hoy
     * @param string|null $dia día vigente hoy (inscripciones.dia_semana)
     * @return array [['desde', 'hasta' (null = sin fin), 'precio', 'duracion', 'dia'], ...]
     */
    public static function tramos(array $filas, float $precio, ?int $duracion, ?string $dia = null): array
    {
        $t = [];
        $n = count($filas);
        foreach ($filas as $i => $f) {
            $ultimo = $i === $n - 1;
            $t[] = [
                'desde'    => $f['desde'],
                'hasta'    => $ultimo ? null : date('Y-m-d', strtotime($filas[$i + 1]['desde'] . ' -1 day')),
                'precio'   => $ultimo ? $precio : $f['precio'],
                'duracion' => $ultimo ? ($duracion ?: $f['duracion']) : $f['duracion'],
                'dia'      => $ultimo ? ($dia ?: ($f['dia'] ?? null)) : ($f['dia'] ?? null),
            ];
        }
        return $t;
    }

    /** El precio que regía en una fecha (el vigente hoy si la clase no tiene historial) */
    public static function precioEn(array $ins, string $fecha): float
    {
        $filas = self::de((int) ($ins['id_inscripcion'] ?? 0));
        $vigente = (float) ($ins['costo_mensual'] ?? 0);
        if (!$filas) return $vigente;
        foreach (self::tramos($filas, $vigente, isset($ins['duracion_min']) ? (int) $ins['duracion_min'] : null,
                              $ins['dia_semana'] ?? null) as $t) {
            if ($fecha >= $t['desde'] && ($t['hasta'] === null || $fecha <= $t['hasta'])) return (float) $t['precio'];
        }
        return $vigente;
    }

    /**
     * El precio de la clase que corresponde a un mes que se muestra en pantalla o en un reporte: en el
     * mes en curso, el de hoy (si hay otro tramo programado para después, no es el de hoy); en un mes
     * pasado o futuro, el del día 1. Sin historial, el precio vigente de siempre.
     */
    public static function precioDelMes(array $ins, string $periodo): float
    {
        [$ini, $fin] = Calendario::limites($periodo);
        $hoy = date('Y-m-d');
        return self::precioEn($ins, ($hoy >= $ini && $hoy <= $fin) ? $hoy : $ini);
    }

    /**
     * Cuánto se aparta el precio de un mes de lo que dice inscripciones.costo_mensual, para las clases vivas
     * que tienen historial (casi siempre ninguna). Sirve a los totales que suman costo_mensual con SQL: sumar
     * estas diferencias les da el precio de ese mes.
     *
     * @return array [['id_inscripcion', 'id_alumno', 'id_maestro', 'id_instrumento', 'diferencia'], ...]
     */
    public static function diferenciasDelMes(string $periodo, ?PDO $db = null): array
    {
        $db = $db ?? Database::conexion();
        if ($db->inTransaction()) {
            $todas = self::leer($db, null);
        } else {
            if (self::$cache === null) self::$cache = self::leer($db, null);
            $todas = self::$cache;
        }
        if (!$todas) return [];
        $ids = implode(',', array_map('intval', array_keys($todas)));
        $r = [];
        $s = $db->query("SELECT id_inscripcion, id_alumno, id_maestro, id_instrumento, costo_mensual, duracion_min, dia_semana
                           FROM inscripciones WHERE estado <> 'baja' AND id_inscripcion IN ($ids)");
        foreach ($s->fetchAll() as $f) {
            $dif = round(self::precioDelMes($f, $periodo) - (float) $f['costo_mensual'], 2);
            if (abs($dif) < 0.005) continue;
            $r[] = ['id_inscripcion' => (int) $f['id_inscripcion'], 'id_alumno' => (int) $f['id_alumno'],
                    'id_maestro' => (int) $f['id_maestro'],
                    'id_instrumento' => $f['id_instrumento'] === null ? null : (int) $f['id_instrumento'], 'diferencia' => $dif];
        }
        return $r;
    }

    /**
     * Cuántas clases del mes caen en cada tramo. Es el mismo conteo que
     * Calendario::tramo (las clases que de verdad caen en el tramo, con tope
     * en las clases del mes), repartido entre los tramos en orden: si entre
     * todos pasaran de las clases del mes, las últimas se quedan sin las que
     * sobran. Un mes completo siempre suma exactamente las clases del mes.
     *
     * Cada tramo cuenta con SU día ($t['dia']); si no lo trae, con $dia.
     *
     * @param string|null $dia   día de la clase (para los tramos que no traen el suyo)
     * @param string|null $desde primer día que cuenta (alta a medio mes); null = desde el 1
     * @param string|null $hasta último día que cuenta (baja); null = hasta fin de mes
     * @return array [['desde', 'hasta', 'precio', 'duracion', 'dia', 'clases'], ...] solo tramos con clases
     */
    public static function reparto(array $tramos, string $periodo, ?string $dia, ?string $desde, ?string $hasta,
                                   int $semanas): array
    {
        [$ini, $fin] = Calendario::limites($periodo);
        $d = ($desde && $desde > $ini) ? $desde : $ini;
        $h = ($hasta && $hasta < $fin) ? $hasta : $fin;
        if ($d > $h) return [];
        $mesCompleto = $d === $ini && $h === $fin;

        $acum = 0; $r = [];
        foreach ($tramos as $t) {
            $sd = max($d, $t['desde']);
            $sh = $t['hasta'] === null ? $h : min($h, $t['hasta']);
            if ($sd > $sh) continue;
            $diaT = $t['dia'] ?? $dia;
            $c = Calendario::tramo($periodo, $diaT, $sd, $sh, $semanas);
            $n = max(0, min($c['clases'] ?? $semanas, $semanas - $acum));
            $acum += $n;
            $r[] = ['desde' => $sd, 'hasta' => $sh, 'precio' => $t['precio'], 'duracion' => $t['duracion'], 'dia' => $diaT, 'clases' => $n];
        }
        // Un mes completo vale sus clases aunque el mes traiga menos de ese día (5 clases al mes, 4 martes).
        // Solo si la clase se dio siempre el mismo día: si cambió de día, las que caen son las que caen.
        if ($mesCompleto && $r && $acum < $semanas && count(array_unique(array_column($r, 'dia'))) === 1) {
            $r[count($r) - 1]['clases'] += $semanas - $acum;
        }
        return array_values(array_filter($r, fn($x) => $x['clases'] > 0));
    }

    /**
     * Las primeras N clases del mes de una clase con historial, cada una con el precio, la duración y el día
     * que tenía ese día. Sirve cuando alguien dice a mano cuántas clases tomó (una baja, una corrección de
     * baja): no se cuentan con el precio de hoy sino con el de cada tramo. Si dice más clases de las que el
     * calendario cuenta hasta la baja, las que sobran van con lo último que regía.
     *
     * @param string|null $desde primer día que cuenta (null = desde el 1)
     * @param string|null $hasta último día que cuenta (la baja)
     * @return array|null [['desde', 'hasta', 'precio', 'duracion', 'dia', 'clases'], ...], o null si la clase no usa
     *                    tramos (sin historial o sin día): ahí se calcula como siempre
     */
    public static function clasesTomadas(array $ins, string $periodo, ?string $desde, ?string $hasta, int $n, int $semanas,
                                         ?PDO $db = null): ?array
    {
        $filas = self::filasDe($ins, $db);
        if (!$filas) return null;
        $tramos = self::tramos($filas, (float) $ins['costo_mensual'], isset($ins['duracion_min']) ? (int) $ins['duracion_min'] : null,
                               $ins['dia_semana'] ?? null);
        $reparto = self::reparto($tramos, $periodo, $ins['dia_semana'] ?? null, $desde, $hasta, $semanas);
        $n = min($n, $semanas);      // un mes no tiene más clases que sus semanas, digan lo que digan a mano
        $r = []; $falta = $n;
        foreach ($reparto as $t) {
            $c = min((int) $t['clases'], $falta);
            if ($c > 0) $r[] = ['clases' => $c] + $t;
            $falta -= $c;
        }
        if ($falta > 0) {
            if ($r) $r[count($r) - 1]['clases'] += $falta;
            else $r[] = ['desde' => $periodo . '-01', 'hasta' => $hasta, 'precio' => (float) $ins['costo_mensual'],
                         'duracion' => isset($ins['duracion_min']) ? (int) $ins['duracion_min'] : null,
                         'dia' => $ins['dia_semana'] ?? null, 'clases' => $falta];
        }
        return $r;
    }

    /** Lo que se cobra por un reparto: cada tramo a su precio, proporcional a sus clases */
    public static function cargo(array $reparto, int $semanas): float
    {
        $suma = 0.0;
        foreach ($reparto as $t) $suma += $t['precio'] * $t['clases'];
        return round($suma / max(1, $semanas), 2);
    }

    /**
     * La última fila que ya rige (fecha de hoy o anterior): un cambio no puede tener fecha anterior a ella,
     * porque reescribiría un tramo ya cobrado. Las filas con fecha posterior a hoy son cambios programados.
     *
     * @return array|null la fila, o null si no hay ninguna que ya rija
     */
    public static function ultimaQueYaRige(array $filas): ?array
    {
        $hoy = date('Y-m-d');
        $r = null;
        foreach ($filas as $f) if ($f['desde'] <= $hoy) $r = $f;
        return $r;
    }

    /** Lanza el error si una fecha de cambio cae antes del último cambio que ya rige */
    public static function validarFecha(array $filas, string $fecha): void
    {
        $u = self::ultimaQueYaRige($filas);
        if ($u && $u['desde'] !== self::ORIGEN && $fecha < $u['desde']) {
            throw new Exception('La fecha del cambio no puede ser anterior al último cambio de esta clase ('
                . date('d/m/Y', strtotime($u['desde'])) . ').');
        }
    }

    /**
     * Guarda un cambio de la clase con fecha. Va dentro de la transacción de quien llama.
     *
     * Si la clase no tenía historial, primero se guarda lo de antes ("desde siempre"). La fecha
     * no puede ser anterior a la del último cambio que ya rige: eso reescribiría un tramo ya cobrado.
     * Lo PROGRAMADO con fecha posterior a este cambio (por ejemplo, el precio que bajaba el mes que
     * entra) se cancela: el cambio nuevo lo sustituye y quien llama vuelve a planear lo que haga falta.
     *
     * @param int|null $duracionAntes duración de antes (para la fila de origen)
     * @param string|null $diaAntes día de antes (para la fila de origen)
     * @param bool $todoElMes el cambio rige todo el mes (importación de horarios): reemplaza lo que ya
     *                        se había guardado desde esa fecha en vez de negarse por ella
     * @return array las filas programadas que se cancelaron ([['desde', 'precio', 'duracion', 'dia'], ...])
     */
    public static function registrar(PDO $db, int $idInscripcion, string $fecha, float $precio, ?int $duracion, ?string $dia,
                                     float $precioAntes, ?int $duracionAntes, ?string $diaAntes, ?string $usuario = null,
                                     bool $todoElMes = false): array
    {
        $filas = self::leer($db, $idInscripcion)[$idInscripcion] ?? [];
        $canceladas = [];
        if (!$filas) {
            $db->prepare("INSERT INTO precios_clase (id_inscripcion, vigente_desde, precio, duracion_min, dia_semana, usuario)
                          VALUES (?, ?, ?, ?, ?, ?)")
               ->execute([$idInscripcion, self::ORIGEN, $precioAntes, $duracionAntes, $diaAntes, $usuario]);
        } else {
            if (!$todoElMes) self::validarFecha($filas, $fecha);
            $hoy = date('Y-m-d');
            foreach ($filas as $f) {
                if ($f['desde'] === self::ORIGEN) continue;
                // Lo que quedaba después de este cambio se cancela; con "todo el mes", también lo que ya
                // regía desde esa fecha (el mes se vuelve a decir completo).
                $sobra = $f['desde'] > $fecha || ($todoElMes && $f['desde'] >= $fecha);
                if ($sobra && $f['desde'] > $hoy) $canceladas[] = $f;
            }
            $db->prepare("DELETE FROM precios_clase WHERE id_inscripcion = ? AND vigente_desde <> ? AND vigente_desde "
                         . ($todoElMes ? '>=' : '>') . " ?")
               ->execute([$idInscripcion, self::ORIGEN, $fecha]);
        }
        $db->prepare(
            "INSERT INTO precios_clase (id_inscripcion, vigente_desde, precio, duracion_min, dia_semana, usuario) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE precio = VALUES(precio), duracion_min = VALUES(duracion_min),
                                     dia_semana = VALUES(dia_semana), usuario = VALUES(usuario)"
        )->execute([$idInscripcion, $fecha, $precio, $duracion, $dia, $usuario]);
        self::olvidar();
        return $canceladas;
    }

    /**
     * Una corrección de precio (sin cambio de duración ni de día) rige TODO el mes en curso, como en una
     * clase sin historial: las filas de este mes toman el precio, y si el mes empezó con otro tramo se
     * parte en el día 1 para que el precio corregido cubra desde ahí. Los meses anteriores no se tocan.
     * Sin filas dentro del mes no hay nada que hacer: el último tramo ya usa el precio vigente.
     */
    public static function corregirPrecioDelMes(PDO $db, int $idInscripcion, string $periodo, float $precio,
                                                ?string $usuario = null): void
    {
        $filas = self::leer($db, $idInscripcion)[$idInscripcion] ?? [];
        if (!$filas) return;
        [$ini, $fin] = Calendario::limites($periodo);
        $dentro = array_filter($filas, fn($f) => $f['desde'] >= $ini && $f['desde'] <= $fin);
        if (!$dentro) return;
        $db->prepare("UPDATE precios_clase SET precio = ? WHERE id_inscripcion = ? AND vigente_desde BETWEEN ? AND ?")
           ->execute([$precio, $idInscripcion, $ini, $fin]);
        if (!array_filter($dentro, fn($f) => $f['desde'] === $ini)) {
            $previa = null;
            foreach ($filas as $f) if ($f['desde'] < $ini) $previa = $f;
            if ($previa) {
                $db->prepare("INSERT INTO precios_clase (id_inscripcion, vigente_desde, precio, duracion_min, dia_semana, usuario)
                              VALUES (?, ?, ?, ?, ?, ?)")
                   ->execute([$idInscripcion, $ini, $precio, $previa['duracion'], $previa['dia'], $usuario]);
            }
        }
        self::olvidar();
    }
}

<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/ConfiguracionModel.php';
require_once __DIR__ . '/../lib/Calendario.php';
require_once __DIR__ . '/FamiliaModel.php';
require_once __DIR__ . '/../lib/Bitacora.php';
require_once __DIR__ . '/HistorialClaseModel.php';

/**
 * Estado de cuenta de un alumno: cuánto se le cobra cada mes, cuánto ha
 * abonado y cuánto le falta.
 *
 * La regla de fondo: un mes está pagado cuando lo abonado cubre la
 * colegiatura, no cuando existe "algún" pago. Alguien que dio $300 de $900
 * sigue debiendo $600 de ese mes.
 *
 * Desde cuándo se le cobra:
 *   · el primer mes que tiene un pago registrado (así, quien viene del Excel
 *     marcado "pagado hasta marzo" arranca en marzo y debe de abril en adelante),
 *   · o el mes en que se inscribió, lo que sea anterior.
 *
 * Quien entra a medio mes paga ese primer mes proporcional a las clases que
 * le quedan: el cargo de ese mes baja en lo que no toma. Lo mismo al irse:
 * solo paga las clases que alcanzó a tomar.
 *
 * CARGOS CONGELADOS, UNO POR CLASE. Lo que se debió en un mes no puede
 * depender de cómo estén las clases hoy. Si se dedujera de las clases vivas,
 * dar de baja a un alumno le borraría lo que debía y subir un precio le
 * recobraría el pasado. Por eso el cargo se guarda en cargos_mensuales, con
 * una fila por clase y mes, igual que nomina_detalle:
 *   · al cerrar cada mes, para todas las clases vivas;
 *   · al dar de baja una clase, para esa clase, con lo que se alcanzó a tomar.
 *
 * El cargo de un mes es la suma de sus clases congeladas más las clases que
 * todavía no lo están, calculadas en vivo. Así un alumno que deja UNA de sus
 * dos clases a medio mes paga esa clase por lo que la tomó, y la otra sigue
 * corriendo hasta que el mes cierre.
 *
 * Los meses anteriores al mes de corte se siguen calculando como antes (la
 * suma de sus clases vivas). Eso es lo que permite depurar el historial viejo
 * sin arrastrar adeudos fantasma.
 *
 * PAGOS CON MONTO POR CONFIRMAR. La hoja de pagos solo dice hasta qué mes
 * pagó cada alumno, no cuánto. Un mes con un pago así cuenta como pagado: el
 * importe guardado es una sugerencia y no puede fabricar una deuda. Cuando
 * alguien confirma el importe real, el mes vuelve a la aritmética normal.
 *
 * Al cobrar se aplica primero a lo más atrasado. Si sobra, se adelanta al
 * mes siguiente. Cada mes vencido carga su recargo una sola vez, aunque se
 * pague en varios abonos.
 *
 * DESCUENTO FAMILIAR. Si la familia tiene un descuento acordado, a cada
 * alumno se le rebaja su parte (FamiliaModel::descuentosPorAlumno) desde el
 * mes indicado. En los meses abiertos se calcula en vivo; al cerrar el mes
 * queda congelado como un renglón negativo 'descuento familiar', igual que
 * los cargos de cada clase, para que el pasado no cambie.
 *
 * COBRO A UNA FAMILIA. Se cobra de una vez al responsable eligiendo qué
 * clases de sus hijos se liquidan (cobrarFamilia). Cada renglón de pago
 * guarda la clase que pagó; la cuenta de cada alumno sigue siendo la suya.
 */
class CuentaModel
{
    private PDO $db;
    private array $config;

    /**
     * Tope de cordura para un dato mal capturado de hace décadas (50 años).
     * Si se pasa, se conservan los meses MÁS RECIENTES, que son los que se
     * cobran: antes el tope era de 36 meses y cortaba los recientes.
     */
    private const MAX_MESES = 600;

    public function __construct()
    {
        $this->db = Database::conexion();
        $this->config = (new ConfiguracionModel())->todo();
    }

    // =================================================================
    // CONSULTA
    // =================================================================

    public function estado(int $idAlumno): array
    {
        $alumno = $this->alumno($idAlumno);
        $colegiatura = $this->colegiatura($idAlumno);
        $hoy = date('Y-m');

        $abonos = $this->abonadoPorMes($idAlumno);
        $rebajas = $this->rebajasPrimerMes($idAlumno)[$idAlumno] ?? [];
        $porClase = $this->cargosPorClase($idAlumno);
        $congelados = $this->cargosCongelados($idAlumno);
        $clases = $this->inscripcionesDe($idAlumno);
        $corte = $this->congelarDesde();
        // El descuento familiar se reparte con el precio que rige en cada mes (no con el de un cambio
        // programado para después): se calcula mes por mes, solo si la familia tiene descuento.
        $fm = new FamiliaModel();
        $descuento = $fm->descuentoDe($idAlumno);
        $descuentoPorMes = [];
        $descuentoEn = function (string $p) use ($fm, $idAlumno, $descuento, &$descuentoPorMes) {
            if (!$descuento) return null;
            if (!array_key_exists($p, $descuentoPorMes)) $descuentoPorMes[$p] = $fm->descuentoDe($idAlumno, $p);
            return $descuentoPorMes[$p];
        };

        // Un alumno sin clases vivas pero con meses congelados sigue debiendo.
        $desde = $this->desde($idAlumno) ?? $hoy;
        if ($congelados) $desde = min($desde, min(array_keys($congelados)));

        // Meses a mostrar: desde el inicio hasta hoy, más los adelantados.
        $hasta = max($hoy, $abonos ? max(array_keys($abonos)) : $hoy);
        $meses = [];
        $total = 0.0;
        $porConfirmarMeses = 0;
        foreach ($this->rangoMeses($desde, $hasta) as $p) {
            $abonado = round($abonos[$p]['monto'] ?? 0, 2);
            $recargo = round($abonos[$p]['recargo'] ?? 0, 2);
            $porConfirmar = !empty($abonos[$p]['por_confirmar']);
            // Lo congelado manda; lo que falta se calcula en vivo.
            $cargo = $this->cargoMes($p, $clases, $porClase[$p] ?? [], $colegiatura,
                                     (float) ($rebajas[$p] ?? 0), $abonado, $hoy, $corte,
                                     self::descuentoDelMes($descuentoEn($p), $p));
            $saldo   = self::saldoDelMes($cargo, $abonado, $porConfirmar);
            $sinConfirmar = $cargo > 0 && self::sinEvidencia($p, $corte, $porClase[$p] ?? [], $abonado, $porConfirmar);
            if ($sinConfirmar) { $saldo = 0.0; $porConfirmarMeses++; }

            $venc = $this->vencimiento($p);
            // Un mes que todavía no llega no se debe: si se adelantó una parte,
            // lo que falta se muestra, pero no cuenta como adeudo.
            $futuro = $p > $hoy;
            $meses[] = [
                'periodo'         => $p,
                'cargo'           => $cargo,
                'abonado'         => $abonado,
                'saldo'           => $saldo,
                'recargo_cobrado' => $recargo,
                'vence'           => $venc,
                'vencido'         => !$futuro && $saldo > 0 && date('Y-m-d') > $venc,
                'futuro'          => $futuro,
                'por_confirmar'   => $porConfirmar,
                // Si una clase cambió de precio a mitad de ese mes: cuántas clases a cada precio (para decírselo a quien lee).
                'reparto'         => $this->repartoDelMes($clases, $p, $corte),
                'estado'          => $cargo <= 0 ? 'sin_cargo'
                                   : ($sinConfirmar ? 'sin_confirmar'
                                   : ($porConfirmar ? 'por_confirmar'
                                   : ($saldo <= 0 ? 'pagado'
                                   : ($futuro ? 'adelantado' : ($abonado > 0 ? 'abonado' : 'pendiente'))))),
            ];
            if (!$futuro) $total += $saldo;
        }

        $debe = array_values(array_filter($meses, fn($m) => $m['saldo'] > 0 && !$m['futuro']));

        // Deuda que traía antes del sistema: va primero, antes que cualquier mes.
        $anterior = $this->adeudoAnterior($idAlumno);
        $total += $anterior['saldo'];

        return [
            'alumno'        => $alumno,
            'colegiatura'   => $colegiatura,
            'anterior'      => $anterior,
            'desde'         => $desde,
            'meses'         => $meses,
            'adeudo'        => round($total, 2),
            'meses_debe'    => count($debe),
            'mas_atrasado'  => $debe[0]['periodo'] ?? null,
            'recargo_mes'   => (float) $this->config['recargo_monto'],
            'congelados'    => count($congelados),
            // Meses de antes del corte sin evidencia: esperan "¿hasta qué mes pagó?".
            'meses_sin_confirmar' => $porConfirmarMeses,
            'descuento_familiar' => $descuento,
            // La parte del descuento en cada mes que se calculó (puede cambiar con el precio de cada mes)
            'descuento_por_mes' => $descuentoPorMes,
            // La inscripción no es colegiatura: no abona a ningún mes, solo se informa si ya se cobró.
            'inscripcion' => $this->inscripcionPagada($idAlumno),
        ];
    }

    /**
     * Antes del mes de corte, un mes sin ningún pago y sin cargo guardado no
     * tiene evidencia: no se sabe si pagó. Queda "por confirmar" y no cuenta
     * como deuda hasta que alguien diga hasta qué mes pagó (confirmarHistorico).
     */
    private static function sinEvidencia(string $periodo, ?string $corte, array $congeladas, float $abonado,
                                         bool $porConfirmar): bool
    {
        return $corte !== null && $periodo < $corte && !$congeladas && $abonado <= 0 && !$porConfirmar;
    }

    /**
     * La parte del descuento familiar que le toca a un estado de cuenta en un mes: la que se calculó con el precio de
     * ese mes si está, y si no (un mes que adelantan) se calcula ahora.
     */
    private function descuentoEnEstado(array $estado, string $periodo): float
    {
        $d = $estado['descuento_familiar'] ?? null;
        if (!$d) return 0.0;
        if (array_key_exists($periodo, $estado['descuento_por_mes'] ?? [])) {
            $d = $estado['descuento_por_mes'][$periodo];
        } elseif (!empty($estado['alumno']['id_alumno'])) {
            $d = (new FamiliaModel())->descuentoDe((int) $estado['alumno']['id_alumno'], $periodo);
        }
        return self::descuentoDelMes($d, $periodo);
    }

    /** La parte del descuento familiar que toca en ese mes (0 antes de que empiece) */
    private static function descuentoDelMes(?array $descuento, string $periodo): float
    {
        return ($descuento && $periodo >= $descuento['desde']) ? (float) $descuento['monto'] : 0.0;
    }

    /**
     * Lo que falta de un mes. Un pago con monto por confirmar cubre el mes:
     * se sabe que pagó, no cuánto, así que no se le puede fabricar deuda.
     */
    private static function saldoDelMes(float $cargo, float $abonado, bool $porConfirmar): float
    {
        return $porConfirmar ? 0.0 : round(max(0, $cargo - $abonado), 2);
    }

    /**
     * EL cargo de un mes. Es la única fórmula: la usan el estado de cuenta, la
     * lista de deudores y el cobro, para que nunca digan cosas distintas.
     *
     *   · Antes del mes de corte: lo que se congeló, o si no hay nada, el
     *     cálculo de siempre (la suma de las clases vivas). No se reconstruye.
     *   · Del corte en adelante: las clases congeladas más las vivas que
     *     todavía no lo están, cada una proporcional a su tramo.
     *
     * Una clase dada de baja solo cuenta por lo que se congeló el día de su
     * baja. Las bajas hechas antes de que existiera el congelado no tienen
     * renglón y por eso no generan deuda: no se reconstruye lo ya depurado.
     *
     * @param array $clases       todas las inscripciones del alumno
     * @param array $congeladas   lo congelado de ese mes [id_inscripcion => fila]
     */
    private function cargoMes(string $periodo, array $clases, array $congeladas, float $colegiatura,
                              float $rebaja, float $abonado, string $hoy, ?string $corte, float $descuento = 0.0): float
    {
        $sumaCongelada = round(array_sum(array_map(fn($c) => (float) $c['monto'], $congeladas)), 2);
        // El descuento familiar de un mes cerrado ya está congelado (renglón negativo).
        foreach ($congeladas as $c) if (($c['motivo'] ?? '') === 'descuento familiar') { $descuento = 0.0; break; }

        if ($corte === null || $periodo < $corte) {
            if ($congeladas) return round(max(0, $sumaCongelada), 2);
            // Un mes anterior no se cobra con el precio de hoy: si alguna clase cambió de precio con fecha, se
            // usa el que regía el día 1 de ese mes (sin historial, es la colegiatura de siempre).
            $colegiatura = $this->colegiaturaEnMes($clases, $periodo, $colegiatura);
            return $periodo <= $hoy || $abonado > 0 ? round(max(0, $colegiatura - $rebaja - $descuento), 2) : 0.0;
        }

        if ($periodo > $hoy && $abonado <= 0) return 0.0;   // mes que no ha llegado

        $total = $sumaCongelada;
        foreach ($clases as $ins) {
            if (isset($congeladas[(int) $ins['id_inscripcion']])) continue;
            if (!self::vivaEn($ins, $periodo)) continue;
            $total += $this->cargoDeClase($ins, $periodo)['monto'];
        }
        return round(max(0, $total - $descuento), 2);
    }

    /**
     * Las clases del mes a cada precio, para las clases que cambiaron de precio dentro de ese mes (si ninguna lo hizo,
     * null). Una lista por clase: [[['clases' => 2, 'precio' => 900.0], ['clases' => 2, 'precio' => 450.0]], ...].
     * Solo del mes de corte en adelante, que es donde el cobro se parte por tramos.
     */
    private function repartoDelMes(array $clases, string $periodo, ?string $corte): ?array
    {
        if ($corte === null || $periodo < $corte) return null;
        [$ini, $fin] = Calendario::limites($periodo);
        $semanas = ConfiguracionModel::semanasDe($periodo);
        $lista = [];
        foreach ($clases as $ins) {
            $filas = HistorialClase::filasDe($ins, $this->db);
            if (!$filas) continue;
            if (!empty($ins['fecha_baja']) && $ins['fecha_baja'] < $ini) continue;
            if (($ins['origen'] ?? 'captura') === 'captura' && ($ins['fecha_alta'] ?? '') > $fin) continue;
            $desde = ($ins['origen'] ?? 'captura') === 'captura' && ($ins['fecha_alta'] ?? '') > $ini ? $ins['fecha_alta'] : null;
            $hasta = !empty($ins['fecha_baja']) && $ins['fecha_baja'] < $fin ? $ins['fecha_baja'] : null;
            $tramos = HistorialClase::tramos($filas, (float) $ins['costo_mensual'],
                                             isset($ins['duracion_min']) ? (int) $ins['duracion_min'] : null, $ins['dia_semana'] ?? null);
            $porPrecio = [];
            foreach (HistorialClase::reparto($tramos, $periodo, $ins['dia_semana'] ?? null, $desde, $hasta, $semanas) as $t) {
                $k = number_format($t['precio'], 2, '.', '');
                $porPrecio[$k] = ($porPrecio[$k] ?? 0) + $t['clases'];
            }
            if (count($porPrecio) < 2) continue;
            $una = [];
            foreach ($porPrecio as $precio => $n) $una[] = ['clases' => $n, 'precio' => (float) $precio];
            $lista[] = $una;
        }
        return $lista ?: null;
    }

    /** La colegiatura de un mes anterior al corte: lo que sumaban sus clases vivas con el precio que tenían ese mes */
    private function colegiaturaEnMes(array $clases, string $periodo, float $colegiatura): float
    {
        if (!HistorialClase::algunaTiene($clases, $this->db)) return $colegiatura;
        $suma = 0.0;
        foreach ($clases as $ins) {
            if (($ins['estado'] ?? '') === 'baja') continue;
            $suma += HistorialClase::precioEn($ins, $periodo . '-01');
        }
        return round($suma, 2);
    }

    /**
     * ¿Esta clase corre en ese mes sin estar congelada? Solo las vivas: una
     * dada de baja ya dejó su renglón congelado el día de su baja.
     */
    private static function vivaEn(array $ins, string $periodo): bool
    {
        if (($ins['estado'] ?? '') === 'baja') return false;
        [, $fin] = Calendario::limites($periodo);
        // Las importadas no traen el día real en que empezaron.
        return ($ins['origen'] ?? 'captura') === 'importacion' || ($ins['fecha_alta'] ?? '') <= $fin;
    }

    /** Todas las inscripciones de un alumno, para calcular sus meses */
    private function inscripcionesDe(int $idAlumno): array
    {
        $s = $this->db->prepare(
            "SELECT id_inscripcion, id_alumno, costo_mensual, duracion_min, dia_semana, fecha_alta, fecha_baja, origen, estado
               FROM inscripciones WHERE id_alumno = ?"
        );
        $s->execute([$idAlumno]);
        return $s->fetchAll();
    }

    /**
     * Lo que una clase debió cobrar en un mes: su precio, proporcional a las
     * clases que de verdad tocaron dentro de su tramo vivo. Es el mismo
     * criterio con el que se le paga al maestro por esa clase.
     */
    public function cargoDeClase(array $ins, string $periodo, ?string $hastaForzado = null): array
    {
        $semanas = ConfiguracionModel::semanasDe($periodo);
        [$ini, $fin] = Calendario::limites($periodo);

        // Las importadas no traen el día real en que empezaron: cuentan desde el 1.
        $desde = ($ins['origen'] ?? 'captura') === 'captura' && ($ins['fecha_alta'] ?? '') > $ini
               ? $ins['fecha_alta'] : null;
        $hasta = $hastaForzado ?: (!empty($ins['fecha_baja']) && $ins['fecha_baja'] < $fin ? $ins['fecha_baja'] : null);

        $t = Calendario::tramo($periodo, $ins['dia_semana'] ?? null, $desde, $hasta, $semanas);
        $precio = (float) ($ins['costo_mensual'] ?? 0);
        $monto = $t['clases'] === null ? $precio : round($precio * $t['clases'] / $semanas, 2);
        $clases = $t['clases'];

        // Una clase que cambió de precio con fecha se cobra por tramos: las clases de antes del
        // cambio al precio de antes y las de desde el cambio al de ahora. Sin historial, como siempre.
        // Cada tramo se cuenta con SU día: si la clase cambió de día a mitad de mes, las de antes van con el día de antes.
        // Una clase sin día no usa tramos (se calcula como siempre).
        $filas = HistorialClase::filasDe($ins, $this->db);
        if ($filas) {
            $tramos = HistorialClase::tramos($filas, $precio, isset($ins['duracion_min']) ? (int) $ins['duracion_min'] : null,
                                             $ins['dia_semana'] ?? null);
            $reparto = HistorialClase::reparto($tramos, $periodo, $ins['dia_semana'] ?? null, $desde, $hasta, $semanas);
            $monto = HistorialClase::cargo($reparto, $semanas);
            $total = array_sum(array_column($reparto, 'clases'));
            $clases = $total >= $semanas ? null : $total;
        }

        return [
            'monto'   => round($monto, 2),
            'precio'  => round($precio, 2),
            'clases'  => $clases,
            'semanas' => $semanas,
            'desde'   => $t['desde'],
            'hasta'   => $t['hasta'] ?: $hastaForzado,
        ];
    }

    // =================================================================
    // CARGOS CONGELADOS
    // =================================================================

    /** Lo congelado de un alumno, clase por clase: [periodo][id_inscripcion] => fila */
    public function cargosPorClase(int $idAlumno): array
    {
        $s = $this->db->prepare(
            "SELECT id_cargo, id_inscripcion, periodo, monto, precio_mensual, clases, clases_mes, desde, hasta, motivo
               FROM cargos_mensuales WHERE id_alumno = ? ORDER BY periodo, id_cargo"
        );
        $s->execute([$idAlumno]);
        $r = [];
        foreach ($s->fetchAll() as $f) {
            // Un cargo cuya clase se borró se guarda bajo una llave propia.
            $k = $f['id_inscripcion'] !== null ? (int) $f['id_inscripcion'] : 'x' . $f['id_cargo'];
            $r[$f['periodo']][$k] = $f;
        }
        return $r;
    }

    /** Lo congelado de un alumno, sumado por mes: [periodo => monto] */
    public function cargosCongelados(int $idAlumno): array
    {
        $r = [];
        foreach ($this->cargosPorClase($idAlumno) as $periodo => $clases) {
            $r[$periodo] = round(array_sum(array_map(fn($c) => (float) $c['monto'], $clases)), 2);
        }
        return $r;
    }

    /** Desde qué mes se congela. Antes de eso el historial se sigue calculando en vivo. */
    private function congelarDesde(): ?string
    {
        $s = $this->db->query("SELECT valor FROM configuracion WHERE clave = 'cargos_congelados_desde'");
        $v = $s ? (string) $s->fetchColumn() : '';
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v) ? $v : null;
    }

    /**
     * Congela una clase en un mes: guarda lo que debió cobrar y con qué cuenta.
     * Si ya estaba congelada no se toca: un cargo cerrado no se reescribe.
     *
     * @param float|null $montoForzado para la depuración, que congela en cero
     * @return bool si escribió
     */
    public function congelarClase(array $ins, string $periodo, ?string $hasta = null,
                                  string $motivo = 'cierre de mes', ?float $montoForzado = null,
                                  ?int $clasesForzadas = null): bool
    {
        $c = $this->cargoDeClase($ins, $periodo, $hasta);
        // Las clases que tomó se dicen a mano (una clase sin día no se puede contar, o la baja se corrige): se
        // congela por esas clases. Si la clase tiene día e historial de precio, cada clase va con el precio de su
        // tramo, igual que al corregir una baja; si no, con el precio de la clase.
        if ($clasesForzadas !== null) {
            $c['clases'] = $clasesForzadas;
            $c['monto'] = round($c['precio'] * $clasesForzadas / max(1, $c['semanas']), 2);
            // Las importadas no traen el día real en que empezaron: cuentan desde el 1.
            $inicio = ($ins['origen'] ?? 'captura') === 'captura' ? ($ins['fecha_alta'] ?? null) : null;
            $partes = HistorialClase::clasesTomadas($ins, $periodo, $inicio, $hasta, $clasesForzadas, (int) $c['semanas'], $this->db);
            if ($partes !== null) $c['monto'] = round(HistorialClase::cargo($partes, (int) $c['semanas']), 2);
        }
        $monto = $montoForzado !== null ? round(max(0, $montoForzado), 2) : $c['monto'];

        $stmt = $this->db->prepare(
            "INSERT IGNORE INTO cargos_mensuales
                (id_alumno, id_inscripcion, periodo, monto, precio_mensual, clases, clases_mes, desde, hasta, motivo)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            (int) $ins['id_alumno'], (int) $ins['id_inscripcion'], $periodo,
            $monto, $c['precio'], $c['clases'], $c['semanas'], $c['desde'], $c['hasta'], $motivo,
        ]);
        $escribio = $stmt->rowCount() > 0;
        // El cierre de cada mes es rutina; congelar por una baja o una depuración queda en la bitácora.
        if ($escribio && $motivo !== 'cierre de mes') {
            Bitacora::registrar($this->db, 'congelar cargo', 'cargos_mensuales', (int) $this->db->lastInsertId(),
                "cargo $periodo (clase " . (int) $ins['id_inscripcion'] . ')', null,
                number_format($monto, 2, '.', ''), $motivo);
        }
        return $escribio;
    }

    /**
     * Guarda lo que un alumno debe hasta el mes indicado, para que dar de baja
     * sus clases no se lo borre.
     *
     *   · Del mes de corte en adelante: clase por clase, con su tramo.
     *   · Antes del corte: un solo renglón con el saldo TAL COMO SE MOSTRABA,
     *     marcado como anterior. No se reconstruye por clase lo que nunca se
     *     llevó por clase: solo se evita que desaparezca.
     *
     * @return int renglones congelados
     */
    public function congelarAlumno(int $idAlumno, string $hasta, string $motivo = 'baja'): int
    {
        $estado = $this->estado($idAlumno);
        $ya = $this->cargosPorClase($idAlumno);
        $corte = $this->congelarDesde();
        $clases = $this->inscripcionesDe($idAlumno);
        $n = 0;

        foreach ($estado['meses'] as $m) {
            $p = $m['periodo'];
            if ($p > $hasta) continue;

            if ($corte === null || $p < $corte) {
                if (!empty($ya[$p]) || $m['cargo'] <= 0) continue;
                $this->db->prepare(
                    "INSERT INTO cargos_mensuales (id_alumno, id_inscripcion, periodo, monto, precio_mensual, motivo)
                     VALUES (?, NULL, ?, ?, ?, 'saldo anterior al corte')"
                )->execute([$idAlumno, $p, $m['cargo'], $m['cargo']]);
                $n++;
                continue;
            }

            foreach ($clases as $ins) {
                if (isset($ya[$p][(int) $ins['id_inscripcion']])) continue;
                if (!self::vivaEn($ins, $p)) continue;
                if ($this->congelarClase($ins, $p, null, $motivo)) $n++;
            }
        }
        return $n;
    }

    /**
     * Congela el mes de las clases que ya lo tienen pagado, con el cálculo de hoy. Se usa antes de cambiar algo
     * que movería lo que se cobra (las clases al mes de este mes) para que lo pagado no quede de más.
     * Solo del mes de corte en adelante (antes del corte el cargo no depende de eso). Lo ya congelado no se toca.
     *
     * @return int renglones congelados
     */
    public function congelarPagadosDelMes(string $periodo, string $motivo): int
    {
        $corte = $this->congelarDesde();
        if ($corte === null || $periodo < $corte) return 0;
        $s = $this->db->prepare(
            "SELECT DISTINCT id_alumno FROM pagos
              WHERE concepto = 'colegiatura' AND periodo = ? AND id_alumno IS NOT NULL AND anulado = 0"
        );
        $s->execute([$periodo]);
        $n = 0;
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $idAlumno) {
            $idAlumno = (int) $idAlumno;
            $todo = $this->pagoDelMes($idAlumno, $periodo, null)['pagado'];
            $ya = $this->cargosPorClase($idAlumno)[$periodo] ?? [];
            foreach ($this->inscripcionesDe($idAlumno) as $ins) {
                if (isset($ya[(int) $ins['id_inscripcion']]) || !self::vivaEn($ins, $periodo)) continue;
                if (!$todo && !$this->pagoDelMes($idAlumno, $periodo, (int) $ins['id_inscripcion'])['pagado']) continue;
                if ($this->congelarClase($ins, $periodo, null, $motivo)) $n++;
            }
        }
        return $n;
    }

    /**
     * Cierra los meses que ya terminaron: guarda lo que cada clase debió.
     * Es idempotente; se puede llamar cada vez que alguien abre el sistema.
     */
    public function cerrarMesesTerminados(): array
    {
        $desdeCfg = $this->congelarDesde();
        $mesActual = date('Y-m');
        if ($desdeCfg === null || $desdeCfg >= $mesActual) return ['meses' => [], 'renglones' => 0];

        // Uno a la vez: si dos personas abren Inicio justo al cambiar de mes, la
        // segunda espera a que la primera termine y ya no encuentra nada que cerrar.
        if (!Database::candado('cierre_mes', 15)) return ['meses' => [], 'renglones' => 0];
        try {
            return $this->cerrarMesesConCandado($desdeCfg, $mesActual);
        } finally {
            Database::soltar('cierre_mes');
        }
    }

    private function cerrarMesesConCandado(string $desdeCfg, string $mesActual): array
    {
        // Marca de hasta dónde ya se cerró. Sin ella habría que recorrer a
        // todos los alumnos en cada carga de Inicio, y eso cuesta un segundo.
        $marca = $this->db->query("SELECT valor FROM configuracion WHERE clave = 'cargos_cerrados_hasta'");
        $cerrado = $marca ? (string) $marca->fetchColumn() : '';
        $arranque = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $cerrado)
                  ? max($desdeCfg, $this->mesSiguiente($cerrado))
                  : $desdeCfg;

        $pendientes = [];
        for ($p = $arranque; $p < $mesActual; $p = $this->mesSiguiente($p)) $pendientes[] = $p;
        if (!$pendientes) return ['meses' => [], 'renglones' => 0];

        $renglones = 0;
        foreach ($pendientes as $periodo) {
            [, $fin] = Calendario::limites($periodo);
            // Toda clase que estuvo viva en ese mes, aunque hoy esté de baja.
            $clases = $this->db->prepare(
                "SELECT i.id_inscripcion, i.id_alumno, i.costo_mensual, i.duracion_min, i.dia_semana,
                        i.fecha_alta, i.fecha_baja, i.origen
                   FROM inscripciones i
                  WHERE (i.origen = 'importacion' OR i.fecha_alta <= ?)
                    AND i.estado <> 'baja'
                    AND NOT EXISTS (SELECT 1 FROM cargos_mensuales c
                                     WHERE c.id_inscripcion = i.id_inscripcion AND c.periodo = ?)"
            );
            $clases->execute([$fin, $periodo]);
            foreach ($clases->fetchAll() as $ins) {
                if ($this->congelarClase($ins, $periodo, null, 'cierre de mes')) $renglones++;
            }
            $renglones += $this->congelarDescuentos($periodo);
        }

        // La marca y su renglón de bitácora van juntos: si uno falla, ninguno queda.
        // Solo se llega aquí cuando de verdad se cerró al menos un mes. Corre solo al
        // abrir Inicio, así que el usuario es el de la sesión que lo disparó (o Terminal).
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $this->db->prepare(
                "INSERT INTO configuracion (clave, valor, descripcion)
                 VALUES ('cargos_cerrados_hasta', ?, 'Último mes cuyo cargo ya quedó congelado')
                 ON DUPLICATE KEY UPDATE valor = VALUES(valor)"
            )->execute([end($pendientes)]);
            $n = count($pendientes);
            Bitacora::registrar($this->db, 'cierre de mes', 'configuracion', null, 'cargos_cerrados_hasta',
                $cerrado !== '' ? $cerrado : null, end($pendientes),
                sprintf('Se %s: %s (%d %s congelados)', $n === 1 ? 'cerró el mes' : 'cerraron los meses', implode(', ', $pendientes),
                    $renglones, $renglones === 1 ? 'renglón' : 'renglones'),
                null, null, 'automático');
            if ($propia) $this->db->commit();
        } catch (Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }

        return ['meses' => $pendientes, 'renglones' => $renglones];
    }

    /**
     * Congela el descuento familiar de un mes que cierra: un renglón negativo
     * por alumno, para que cambiar después el descuento no reescriba el pasado.
     */
    private function congelarDescuentos(string $periodo): int
    {
        $n = 0;
        $ya = $this->db->prepare(
            "SELECT COUNT(*) FROM cargos_mensuales WHERE id_alumno = ? AND periodo = ? AND motivo = 'descuento familiar'"
        );
        $ins = $this->db->prepare(
            "INSERT INTO cargos_mensuales (id_alumno, id_inscripcion, periodo, monto, precio_mensual, clases_mes, motivo)
             VALUES (?, NULL, ?, ?, 0, ?, 'descuento familiar')"
        );
        foreach ((new FamiliaModel())->descuentosPorAlumno(null, $periodo) as $idAlumno => $d) {
            if ($periodo < $d['desde'] || $d['monto'] <= 0) continue;
            $ya->execute([$idAlumno, $periodo]);
            if ((int) $ya->fetchColumn() > 0) continue;
            $ins->execute([$idAlumno, $periodo, -round($d['monto'], 2), ConfiguracionModel::semanasDe($periodo)]);
            $n++;
        }
        return $n;
    }

    /**
     * Todos los alumnos que deben algo, del más atrasado al menos. Todo se
     * carga en pocas consultas y se calcula en memoria con la MISMA fórmula
     * que el estado de cuenta (cargoMes): la lista y la ficha no pueden
     * discrepar.
     */
    public function deudores(): array
    {
        $hoy = date('Y-m');
        $corte = $this->congelarDesde();

        // Todas las clases, agrupadas por alumno.
        $clases = []; $colegiatura = []; $alta = [];
        foreach ($this->db->query(
            "SELECT i.id_inscripcion, i.id_alumno, i.costo_mensual, i.duracion_min, i.dia_semana, i.fecha_alta,
                    i.fecha_baja, i.origen, i.estado
               FROM inscripciones i JOIN alumnos a ON a.id_alumno = i.id_alumno"
        ) as $f) {
            $id = (int) $f['id_alumno'];
            $clases[$id][] = $f;
            if ($f['estado'] !== 'baja') {
                // El precio que rige hoy (no el de un cambio programado para después).
                $colegiatura[$id] = ($colegiatura[$id] ?? 0) + HistorialClase::precioDelMes($f, $hoy);
                $mes = substr((string) $f['fecha_alta'], 0, 7);
                if ($mes && (!isset($alta[$id]) || $mes < $alta[$id])) $alta[$id] = $mes;
            }
        }

        $abonos = []; $primero = []; $porConfirmar = [];
        foreach ($this->db->query(
            "SELECT id_alumno, periodo, SUM(monto) AS monto, MAX(monto_confirmado = 0 AND anulado = 0) AS por_confirmar
               FROM pagos
              WHERE concepto = 'colegiatura' AND id_alumno IS NOT NULL
                AND periodo REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$'
              GROUP BY id_alumno, periodo"
        ) as $f) {
            $id = (int) $f['id_alumno'];
            $abonos[$id][$f['periodo']] = (float) $f['monto'];
            if ($f['por_confirmar']) $porConfirmar[$id][$f['periodo']] = true;
            if (!isset($primero[$id]) || $f['periodo'] < $primero[$id]) $primero[$id] = $f['periodo'];
        }

        // Lo congelado, clase por clase.
        $congelados = [];
        foreach ($this->db->query(
            "SELECT id_cargo, id_alumno, id_inscripcion, periodo, monto, motivo FROM cargos_mensuales"
        ) as $f) {
            $k = $f['id_inscripcion'] !== null ? (int) $f['id_inscripcion'] : 'x' . $f['id_cargo'];
            $congelados[(int) $f['id_alumno']][$f['periodo']][$k] = $f;
        }

        // Deuda anterior al sistema, por alumno.
        $anteriores = [];
        foreach ($this->db->query(
            "SELECT a.id_alumno,
                    a.adeudo_anterior - COALESCE((SELECT SUM(p.monto) FROM pagos p
                        WHERE p.id_alumno = a.id_alumno AND p.concepto = 'adeudo_anterior'), 0) AS saldo
               FROM alumnos a WHERE a.adeudo_anterior > 0"
        ) as $f) {
            if ((float) $f['saldo'] > 0.009) $anteriores[(int) $f['id_alumno']] = round((float) $f['saldo'], 2);
        }

        $rebajas = $this->rebajasPrimerMes();
        // El descuento familiar de cada mes, con el precio que rige en ese mes (una vez por mes, no por alumno).
        $fm = new FamiliaModel();
        $descuentosDe = [];
        $descuentosEn = function (string $p) use ($fm, &$descuentosDe) { return $descuentosDe[$p] ??= $fm->descuentosPorAlumno(null, $p); };

        // Candidatos: quien tiene clases, algo congelado o deuda anterior.
        $ids = array_unique(array_merge(array_keys($clases), array_keys($congelados), array_keys($anteriores)));
        if (!$ids) return [];
        $datos = [];
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        // También exalumnos: una baja no borra lo que quedó a deber.
        $a = $this->db->prepare("SELECT id_alumno, nombre, apellido, telefono, tutor_telefono, activo
                                   FROM alumnos WHERE id_alumno IN ($marcas)");
        $a->execute(array_values($ids));
        foreach ($a->fetchAll() as $f) $datos[(int) $f['id_alumno']] = $f;

        $lista = [];
        foreach ($datos as $id => $al) {
            $mios = $congelados[$id] ?? [];
            $col = round((float) ($colegiatura[$id] ?? 0), 2);

            $desde = min(array_filter([$primero[$id] ?? null, $alta[$id] ?? null]) ?: [$hoy]);
            if ($mios) $desde = min($desde, min(array_keys($mios)));

            $previo = $anteriores[$id] ?? 0.0;
            $adeudo = $previo; $meses = 0; $masViejo = $previo > 0 ? '0000-00' : null;
            $saldoHoy = 0.0; $primerMes = null;

            foreach ($this->rangoMeses($desde, $hoy) as $p) {
                $abonado = (float) ($abonos[$id][$p] ?? 0);
                $cargo = $this->cargoMes($p, $clases[$id] ?? [], $mios[$p] ?? [], $col,
                                         (float) ($rebajas[$id][$p] ?? 0), $abonado, $hoy, $corte,
                                         self::descuentoDelMes($descuentosEn($p)[$id] ?? null, $p));
                $saldo = self::saldoDelMes($cargo, $abonado, !empty($porConfirmar[$id][$p]));
                if (self::sinEvidencia($p, $corte, $mios[$p] ?? [], $abonado, !empty($porConfirmar[$id][$p]))) $saldo = 0.0;
                if ($saldo > 0.009) {
                    $adeudo += $saldo; $meses++;
                    $masViejo ??= $p;
                    $primerMes ??= $p;
                    if ($p === $hoy) $saldoHoy = $saldo;
                }
            }
            if ($adeudo <= 0.009) continue;

            // Solo se muestra como urgente lo que ya venció: el mes en curso
            // antes del día límite todavía no es un atraso.
            // El recargo es de colegiaturas vencidas: una deuda anterior sola no lo genera.
            $vencido = $primerMes !== null
                && ($primerMes < $hoy || date('Y-m-d') > $this->vencimiento($hoy));

            $lista[] = [
                'id_alumno'    => $id,
                'nombre'       => $al['nombre'],
                'apellido'     => $al['apellido'],
                'telefono'     => $al['telefono'] ?: $al['tutor_telefono'],
                'colegiatura'  => $col,
                'adeudo'       => round($adeudo, 2),
                'saldo_mes'    => round($saldoHoy, 2),
                'meses_debe'   => $meses,
                'mas_atrasado' => $masViejo === '0000-00' ? 'anterior' : $masViejo,
                'orden'        => $masViejo,
                'adeudo_anterior' => $previo,
                'vencido'      => $vencido,
                'recargo'      => $vencido ? (float) $this->config['recargo_monto'] : 0.0,
                'exalumno'     => (int) $al['activo'] === 0,
            ];
        }

        // La deuda anterior ('0000-00') es la más vieja: va primero.
        usort($lista, fn($x, $y) => [$x['orden'], -$x['adeudo']] <=> [$y['orden'], -$y['adeudo']]);
        return $lista;
    }

    // =================================================================
    // COBRO
    // =================================================================

    /**
     * Aplica un cobro a la cuenta: primero lo más atrasado, luego adelanta.
     *
     * @param bool $simular no guarda nada: devuelve cómo se repartiría
     */
    public function cobrar(int $idAlumno, float $monto, string $metodo, bool $condonarRecargo,
                           bool $simular, ?string $usuario = null, ?string $clave = null): array
    {
        if (!is_finite($monto) || $monto <= 0) throw new Exception('El monto debe ser mayor que cero.');
        if ($monto > 1000000) throw new Exception('El monto es demasiado alto. Revísalo.');
        if (!in_array($metodo, ['efectivo', 'transferencia', 'tarjeta'], true)) {
            throw new Exception('Método de pago no válido.');
        }

        self::validarClave($clave);

        // Se guarda en una transacción que empieza ANTES de calcular: el alumno
        // queda bloqueado, así un segundo cobro al mismo tiempo (otra
        // computadora) espera y calcula con lo que el primero ya registró. La
        // clave del cobro impide que el mismo cobro se registre dos veces.
        $folio = Bitacora::folio();
        if (!$simular) {
            $this->db->beginTransaction();
            $this->db->prepare("SELECT id_alumno FROM alumnos WHERE id_alumno = ? FOR UPDATE")->execute([$idAlumno]);
            if (!$this->reservarClave($clave, $folio, $usuario)) {
                $this->db->rollBack();
                return $this->cobroRepetido((string) $clave, $this->estado($idAlumno)['adeudo']);
            }
        }
        try {
            $estado = $this->estado($idAlumno);
            $cargo = $estado['colegiatura'];
            if ($cargo <= 0 && $estado['anterior']['saldo'] <= 0) {
                throw new Exception('Este alumno no tiene colegiatura: ponle precio a su clase antes de cobrarle.');
            }

            $recargoMes = (float) $this->config['recargo_monto'];
            $restante = round($monto, 2);
            $reparto = [];

            // 0. La deuda que traía de antes, primero.
            if ($estado['anterior']['saldo'] > 0) {
                $aplica = min($estado['anterior']['saldo'], $restante);
                $restante = round($restante - $aplica, 2);
                $reparto[] = [
                    'periodo' => 'anterior', 'monto' => round($aplica, 2), 'recargo' => 0.0,
                    'liquida' => $aplica >= $estado['anterior']['saldo'] - 0.009,
                    'queda'   => round($estado['anterior']['saldo'] - $aplica, 2),
                    'anterior'=> true,
                ];
            }

            // 1. Lo que debe, del mes más viejo al más nuevo.
            foreach ($estado['meses'] as $m) {
                if ($restante <= 0) break;
                if ($m['saldo'] <= 0) continue;
                // Un mes futuro ya adelantado a medias: se completa aquí, antes de
                // abrir otro mes adelante (sin recargo, porque no ha vencido).

                // Recargo: una sola vez por mes, si ese mes ya venció. Solo se
                // cobra cuando el abono alcanza para el recargo Y deja algo para
                // la colegiatura; si no, quedaría un renglón de cero pesos con
                // recargo, que en el estado de cuenta no se entiende.
                $recargo = 0.0;
                if (!$condonarRecargo && $m['vencido'] && $m['recargo_cobrado'] <= 0
                    && $restante > $recargoMes + 0.009) {
                    $recargo = $recargoMes;
                    $restante = round($restante - $recargo, 2);
                }
                $aplica = min($m['saldo'], $restante);
                $restante = round($restante - $aplica, 2);

                // Nada que registrar para este mes: el dinero se acabó antes.
                if ($aplica <= 0.009 && $recargo <= 0) continue;

                {
                    $reparto[] = [
                        'periodo'  => $m['periodo'], 'monto' => round($aplica, 2), 'recargo' => round($recargo, 2),
                        'liquida'  => $aplica >= $m['saldo'] - 0.009,
                        'queda'    => round($m['saldo'] - $aplica, 2),
                    ];
                }
            }

            // 2. Si sobra, se adelanta a los meses siguientes.
            $siguiente = $this->mesSiguiente(max(date('Y-m'),
                $estado['meses'] ? end($estado['meses'])['periodo'] : date('Y-m')));
            $adelantos = 0;
            while ($cargo > 0 && $restante > 0.009 && $adelantos < 12) {
                $neto = round(max(0, $cargo - $this->descuentoEnEstado($estado, $siguiente)), 2);
                $aplica = min($neto, $restante);
                $restante = round($restante - $aplica, 2);
                $reparto[] = [
                    'periodo' => $siguiente, 'monto' => round($aplica, 2), 'recargo' => 0.0,
                    'liquida' => $aplica >= $neto - 0.009, 'queda' => round($neto - $aplica, 2),
                    'adelanto' => true,
                ];
                $siguiente = $this->mesSiguiente($siguiente);
                $adelantos++;
            }

            $resultado = [
                'reparto'   => $reparto,
                'recibido'  => round($monto, 2),
                'recargos'  => round(array_sum(array_column($reparto, 'recargo')), 2),
                'adeudo_antes'   => $estado['adeudo'],
                'adeudo_despues' => round(max(0, $estado['adeudo'] - array_sum(array_map(
                    fn($r) => empty($r['adelanto']) ? $r['monto'] : 0, $reparto))), 2),
                'sobrante'  => round($restante, 2),
            ];
            if ($restante > 0.009) {
                $resultado['aviso'] = 'Sobran ' . number_format($restante, 2) . ' que no se pueden aplicar. Revisa el monto.';
            }
            if ($simular) return $resultado + ['simulado' => true];

            // 3. Guardar: un renglón por mes, todos con el mismo folio.
            $stmt = $this->db->prepare(
                "INSERT INTO pagos (id_alumno, concepto, monto, recargo, metodo, periodo, origen, operacion, registrado_por)
                 VALUES (?, ?, ?, ?, ?, ?, 'captura', ?, ?)"
            );
            foreach ($reparto as $r) {
                $esAnterior = !empty($r['anterior']);
                $stmt->execute([
                    $idAlumno, $esAnterior ? 'adeudo_anterior' : 'colegiatura',
                    $r['monto'], $r['recargo'], $metodo,
                    $esAnterior ? date('Y-m') : $r['periodo'], $folio, $usuario
                ]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if (!$simular && $this->db->inTransaction()) $this->db->rollBack();
            throw $e instanceof PDOException ? new Exception('No se registró el cobro. ' . $e->getMessage()) : $e;
        }

        return $resultado + ['folio' => $folio, 'simulado' => false];
    }

    /**
     * Cobra la cuota única de inscripción de un alumno que ya está dado de alta (por si se olvidó cobrarla
     * en el alta). Se guarda como concepto 'inscripcion': NO abona a ninguna colegiatura ni mueve el
     * reparto de meses, y nunca lleva recargo. La clave evita registrarla dos veces (doble clic, red que
     * reintenta): con la misma clave solo existe un pago.
     *
     * @param ?float $monto lo que escribió la persona; vacío = el importe de Ajustes
     */
    public function cobrarInscripcion(int $idAlumno, ?float $monto, string $metodo, ?string $usuario = null,
                                      ?string $clave = null): array
    {
        $monto = ConfiguracionModel::importeDeInscripcion($monto);
        if (!in_array($metodo, ['efectivo', 'transferencia', 'tarjeta'], true)) {
            throw new Exception('Método de pago no válido.');
        }
        self::validarClave($clave);

        $folio = Bitacora::folio();
        $this->db->beginTransaction();
        try {
            $existe = $this->db->prepare("SELECT id_alumno FROM alumnos WHERE id_alumno = ? FOR UPDATE");
            $existe->execute([$idAlumno]);
            if (!$existe->fetchColumn()) throw new Exception('Ese alumno no existe.');

            if (!$this->reservarClave($clave, $folio, $usuario)) {
                $this->db->rollBack();
                $r = $this->cobroRepetido((string) $clave, 0.0);
                return ['repetido' => true, 'folio' => $r['folio'], 'monto' => 0.0,
                        'aviso' => 'Esta inscripción ya se había registrado (folio ' . $r['folio'] . '): no se volvió a guardar.'];
            }

            $this->db->prepare(
                "INSERT INTO pagos (id_alumno, concepto, monto, recargo, metodo, periodo, origen, operacion, registrado_por)
                 VALUES (?, 'inscripcion', ?, 0, ?, ?, 'captura', ?, ?)"
            )->execute([$idAlumno, $monto, $metodo, date('Y-m'), $folio, $usuario]);
            $idPago = (int) $this->db->lastInsertId();
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e instanceof PDOException ? new Exception('No se registró la inscripción. ' . $e->getMessage()) : $e;
        }
        return ['id_pago' => $idPago, 'folio' => $folio, 'monto' => $monto, 'repetido' => false];
    }

    /** Lo que lleva pagado de inscripción (neto de anulaciones) y la fecha del último pago de inscripción */
    public function inscripcionPagada(int $idAlumno): array
    {
        $s = $this->db->prepare(
            "SELECT COALESCE(SUM(monto), 0) AS pagada, MAX(CASE WHEN anula_a IS NULL THEN fecha_pago END) AS fecha
               FROM pagos WHERE id_alumno = ? AND concepto = 'inscripcion'"
        );
        $s->execute([$idAlumno]);
        $f = $s->fetch();
        $pagada = round((float) $f['pagada'], 2);
        return ['pagada' => $pagada, 'fecha' => $pagada > 0 ? $f['fecha'] : null];
    }

    // =================================================================
    // COBRO A UNA FAMILIA
    // =================================================================

    /**
     * Lo que debe cada hijo de una familia, clase por clase y mes por mes.
     *
     * La deuda de cada mes es la de siempre (estado()); aquí solo se reparte
     * entre las clases que la originaron, para poder elegir cuáles se pagan:
     *   · el cargo de cada clase ese mes (congelado o en vivo), menos su parte
     *     del descuento familiar, menos lo que ya se pagó señalando esa clase;
     *   · si ya había abonos sin clase, lo de cada clase se ajusta para que la
     *     suma no pase de lo que de verdad falta del mes.
     * Lo que no viene de una clase (saldo de antes del corte) va como "otros".
     */
    public function cuentaFamiliar(int $idFamilia, bool $conSiguiente = false): array
    {
        $familia = (new FamiliaModel())->obtener($idFamilia);
        if (!$familia) throw new Exception('Esa familia no existe.');
        $corte = $this->congelarDesde();

        $alumnos = [];
        foreach ($familia['miembros'] as $m) {
            $id = (int) $m['id_alumno'];
            $estado = $this->estado($id);
            $clases = $this->inscripcionesDe($id);
            $porClase = $this->cargosPorClase($id);

            $d = $this->db->prepare(
                "SELECT i.id_inscripcion, i.estado, i.dia_semana, i.hora_inicio, i.costo_mensual,
                        COALESCE(ins.nombre, 'Clase sin materia') AS materia,
                        CONCAT_WS(' ', ma.nombre, ma.apellido) AS maestro
                   FROM inscripciones i
                   LEFT JOIN instrumentos ins ON ins.id_instrumento = i.id_instrumento
                   JOIN maestros ma ON ma.id_maestro = i.id_maestro
                  WHERE i.id_alumno = ?"
            );
            $d->execute([$id]);
            $info = array_column($d->fetchAll(), null, 'id_inscripcion');

            $t = $this->db->prepare(
                "SELECT periodo, id_inscripcion, SUM(monto) AS monto FROM pagos
                  WHERE id_alumno = ? AND concepto = 'colegiatura' AND id_inscripcion IS NOT NULL
                  GROUP BY periodo, id_inscripcion"
            );
            $t->execute([$id]);
            $etiquetado = [];
            foreach ($t->fetchAll() as $f) $etiquetado[$f['periodo']][(int) $f['id_inscripcion']] = (float) $f['monto'];

            $renglones = [];   // clave => ['meses' => [...]]
            $meses = array_values(array_filter($estado['meses'], fn($x) => !$x['futuro'] && $x['saldo'] > 0.009));
            // El mes que entra, si el responsable quiere adelantarlo.
            if ($conSiguiente) {
                $p2 = $this->mesSiguiente(date('Y-m'));
                $abonos = $this->abonadoPorMes($id);
                $cargo2 = 0.0;
                foreach ($clases as $ins) if (self::vivaEn($ins, $p2)) $cargo2 += $this->cargoDeClase($ins, $p2)['monto'];
                $saldo2 = round(max(0, $cargo2 - $this->descuentoEnEstado($estado, $p2)
                                        - ($abonos[$p2]['monto'] ?? 0)), 2);
                if ($saldo2 > 0.009 && empty($abonos[$p2]['por_confirmar'])) {
                    $meses[] = ['periodo' => $p2, 'saldo' => $saldo2, 'vencido' => false, 'recargo_cobrado' => 0,
                                'adelanto' => true];
                }
            }
            foreach ($meses as $mes) {
                $p = $mes['periodo'];
                $cong = $porClase[$p] ?? [];

                // Cargo de cada clase ese mes.
                $cargos = [];
                foreach ($cong as $c) {
                    if ($c['id_inscripcion'] !== null) $cargos[(int) $c['id_inscripcion']] = (float) $c['monto'];
                    elseif (($c['motivo'] ?? '') !== 'descuento familiar') $cargos['otros'] = ($cargos['otros'] ?? 0) + (float) $c['monto'];
                }
                if ($corte === null || $p < $corte) {
                    if (!$cong) foreach ($clases as $ins) {
                        if ($ins['estado'] !== 'baja') $cargos[(int) $ins['id_inscripcion']] = HistorialClase::precioEn($ins, $p . '-01');
                    }
                } else {
                    foreach ($clases as $ins) {
                        $k = (int) $ins['id_inscripcion'];
                        if (isset($cargos[$k]) || !self::vivaEn($ins, $p)) continue;
                        $cargos[$k] = $this->cargoDeClase($ins, $p)['monto'];
                    }
                }

                // Descuento del mes: congelado o en vivo, repartido entre sus clases.
                $desc = 0.0;
                foreach ($cong as $c) if (($c['motivo'] ?? '') === 'descuento familiar') $desc -= (float) $c['monto'];
                if ($desc <= 0) $desc = $this->descuentoEnEstado($estado, $p);
                $suma = array_sum($cargos);

                $falta = [];
                foreach ($cargos as $k => $c) {
                    $parte = $suma > 0 ? $desc * $c / $suma : 0;
                    $pagado = is_int($k) ? ($etiquetado[$p][$k] ?? 0) : 0;
                    $falta[$k] = round(max(0, $c - $parte - $pagado), 2);
                }
                $total = round(array_sum($falta), 2);
                if ($total > $mes['saldo'] + 0.009) {
                    // Hubo abonos sin clase: lo de cada clase baja en proporción.
                    $llaves = array_keys($falta); $acumulado = 0.0;
                    foreach ($llaves as $i => $k) {
                        $falta[$k] = $i === count($llaves) - 1
                            ? round($mes['saldo'] - $acumulado, 2)
                            : round($falta[$k] * $mes['saldo'] / $total, 2);
                        $acumulado = round($acumulado + $falta[$k], 2);
                    }
                } elseif ($total < $mes['saldo'] - 0.009) {
                    $falta['otros'] = round(($falta['otros'] ?? 0) + $mes['saldo'] - $total, 2);
                }

                foreach ($falta as $k => $monto) {
                    if ($monto <= 0.009) continue;
                    $clave = is_int($k) ? 'c' . $k : 'otros-' . $id;
                    $renglones[$clave]['meses'][] = [
                        'periodo' => $p, 'monto' => $monto, 'vencido' => $mes['vencido'],
                        'recargo_cobrado' => $mes['recargo_cobrado'], 'adelanto' => !empty($mes['adelanto']),
                    ];
                }
            }

            // Una fila por clase viva (aunque no deba) y por lo que se deba de otra cosa.
            $filas = [];
            if ($estado['anterior']['saldo'] > 0) {
                $filas[] = ['clave' => 'anterior-' . $id, 'id_inscripcion' => null, 'que' => 'Adeudo anterior al sistema',
                            'detalle' => $estado['anterior']['nota'] ?? '', 'meses' => [
                                ['periodo' => 'anterior', 'monto' => $estado['anterior']['saldo'], 'vencido' => false,
                                 'recargo_cobrado' => 0]]];
            }
            foreach ($info as $k => $c) {
                $clave = 'c' . $k;
                if ($c['estado'] === 'baja' && empty($renglones[$clave])) continue;
                $filas[] = [
                    'clave' => $clave, 'id_inscripcion' => (int) $k,
                    'que' => $c['materia'] . ($c['estado'] === 'baja' ? ' (dada de baja)' : ''),
                    'detalle' => trim($c['maestro'] . ($c['dia_semana'] ? ' · ' . $c['dia_semana'] . ' ' . substr((string) $c['hora_inicio'], 0, 5) : '')),
                    'precio' => (float) $c['costo_mensual'],
                    'meses' => $renglones[$clave]['meses'] ?? [],
                ];
            }
            if (!empty($renglones['otros-' . $id])) {
                $filas[] = ['clave' => 'otros-' . $id, 'id_inscripcion' => null, 'que' => 'Otros cargos',
                            'detalle' => 'Saldos que no vienen de una clase', 'meses' => $renglones['otros-' . $id]['meses']];
            }
            foreach ($filas as &$f) $f['debe'] = round(array_sum(array_column($f['meses'], 'monto')), 2);
            unset($f);

            $alumnos[] = [
                'id_alumno' => $id, 'nombre' => $m['nombre'], 'apellido' => $m['apellido'],
                'requiere_factura' => (int) ($m['requiere_factura'] ?? 0),
                'colegiatura' => $estado['colegiatura'],
                'descuento' => $estado['descuento_familiar']['monto'] ?? 0.0,
                'adeudo' => $estado['adeudo'],
                'filas' => $filas,
            ];
        }

        return [
            'familia' => array_diff_key($familia, ['miembros' => 1]),
            'alumnos' => $alumnos,
            'adeudo'  => round(array_sum(array_column($alumnos, 'adeudo')), 2),
            'colegiatura' => round(array_sum(array_column($alumnos, 'colegiatura')), 2),
            'recargo_mes' => (float) $this->config['recargo_monto'],
        ];
    }

    /**
     * Cobra a una familia lo que el responsable está liquidando.
     *
     * @param string[] $seleccion claves de cuentaFamiliar: 'c<id_inscripcion>', 'anterior-<id>', 'otros-<id>'
     * Se paga de lo más viejo a lo más nuevo; cada mes vencido de cada alumno
     * carga su recargo una sola vez. Lo que sobre no se pierde en silencio: se
     * avisa y, al guardar, se rechaza para que se revise.
     */
    public function cobrarFamilia(int $idFamilia, array $seleccion, float $monto, string $metodo,
                                  bool $condonarRecargo, bool $simular, ?string $usuario = null,
                                  bool $conSiguiente = false, ?string $clave = null): array
    {
        if (!is_finite($monto) || $monto <= 0) throw new Exception('El monto debe ser mayor que cero.');
        if ($monto > 1000000) throw new Exception('El monto es demasiado alto. Revísalo.');
        if (!in_array($metodo, ['efectivo', 'transferencia', 'tarjeta'], true)) throw new Exception('Método de pago no válido.');
        $sel = array_flip(array_map('strval', $seleccion));
        if (!$sel) throw new Exception('Marca al menos una clase que esté pagando.');

        self::validarClave($clave);

        // Toda la familia queda bloqueada mientras se calcula y se guarda (ver cobrar()).
        $folio = Bitacora::folio();
        if (!$simular) {
            $this->db->beginTransaction();
            $this->db->prepare("SELECT id_alumno FROM alumnos WHERE id_familia = ? ORDER BY id_alumno FOR UPDATE")
                     ->execute([$idFamilia]);
            if (!$this->reservarClave($clave, $folio, $usuario)) {
                $this->db->rollBack();
                return $this->cobroRepetido((string) $clave, $this->cuentaFamiliar($idFamilia)['adeudo']);
            }
        }
        try {
            $cuenta = $this->cuentaFamiliar($idFamilia, $conSiguiente);
            $items = [];
            foreach ($cuenta['alumnos'] as $orden => $a) {
                foreach ($a['filas'] as $f) {
                    if (!isset($sel[$f['clave']])) continue;
                    foreach ($f['meses'] as $m) {
                        $items[] = ['orden' => $m['periodo'] === 'anterior' ? '0000-00' : $m['periodo'], 'alumno' => $orden,
                                    'id_alumno' => $a['id_alumno'], 'quien' => trim($a['nombre'] . ' ' . $a['apellido']),
                                    'clave' => $f['clave'], 'id_inscripcion' => $f['id_inscripcion'], 'que' => $f['que'],
                                    'periodo' => $m['periodo'], 'debe' => $m['monto'], 'vencido' => $m['vencido'],
                                    'recargo_cobrado' => $m['recargo_cobrado'], 'adelanto' => !empty($m['adelanto'])];
                    }
                }
            }
            usort($items, fn($x, $y) => [$x['orden'], $x['alumno'], $x['clave']] <=> [$y['orden'], $y['alumno'], $y['clave']]);

            $recargoMes = (float) $this->config['recargo_monto'];
            $restante = round($monto, 2);
            $conRecargo = [];
            $reparto = [];
            foreach ($items as $it) {
                if ($restante <= 0.009) break;
                $recargo = 0.0;
                $llaveMes = $it['id_alumno'] . '|' . $it['periodo'];
                if (!$condonarRecargo && $it['vencido'] && $it['recargo_cobrado'] <= 0 && !isset($conRecargo[$llaveMes])
                    && $restante > $recargoMes + 0.009) {
                    $recargo = $recargoMes;
                    $restante = round($restante - $recargo, 2);
                    $conRecargo[$llaveMes] = true;
                }
                $aplica = round(min($it['debe'], $restante), 2);
                $restante = round($restante - $aplica, 2);
                if ($aplica <= 0.009 && $recargo <= 0) continue;
                $reparto[] = $it + ['monto' => $aplica, 'recargo' => $recargo,
                                    'liquida' => $aplica >= $it['debe'] - 0.009, 'queda' => round($it['debe'] - $aplica, 2)];
            }

            $seleccionado = round(array_sum(array_column($items, 'debe')), 2);
            $resultado = [
                'reparto'      => $reparto,
                'recibido'     => round($monto, 2),
                'seleccionado' => $seleccionado,
                'aplicado'     => round(array_sum(array_column($reparto, 'monto')), 2),
                'recargos'     => round(array_sum(array_column($reparto, 'recargo')), 2),
                'sobrante'     => round($restante, 2),
                'adeudo_antes' => $cuenta['adeudo'],
            ];
            $adelantado = round(array_sum(array_map(fn($r) => $r['adelanto'] ? $r['monto'] : 0, $reparto)), 2);
            $resultado['adelantado'] = $adelantado;
            $resultado['adeudo_despues'] = round(max(0, $cuenta['adeudo'] - $resultado['aplicado'] + $adelantado), 2);
            if ($restante > 0.009) {
                $resultado['aviso'] = 'Sobran $' . number_format($restante, 2) . ': marca más clases o revisa lo que se recibió.';
            }
            if ($simular) return $resultado + ['simulado' => true];
            if ($restante > 0.009) throw new Exception($resultado['aviso']);
            if (!$reparto) throw new Exception('Las clases marcadas no deben nada.');

            $stmt = $this->db->prepare(
                "INSERT INTO pagos (id_alumno, id_inscripcion, concepto, monto, recargo, metodo, periodo, origen,
                                    operacion, registrado_por)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'captura', ?, ?)"
            );
            foreach ($reparto as $r) {
                $anterior = $r['periodo'] === 'anterior';
                $stmt->execute([$r['id_alumno'], $r['id_inscripcion'], $anterior ? 'adeudo_anterior' : 'colegiatura',
                                $r['monto'], $r['recargo'], $metodo, $anterior ? date('Y-m') : $r['periodo'], $folio, $usuario]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if (!$simular && $this->db->inTransaction()) $this->db->rollBack();
            throw $e instanceof PDOException ? new Exception('No se registró el cobro. ' . $e->getMessage()) : $e;
        }
        return $resultado + ['folio' => $folio, 'simulado' => false];
    }

    /** La clave de un cobro: la manda la pantalla, una por cobro */
    private static function validarClave(?string $clave): void
    {
        if ($clave !== null && $clave !== '' && !preg_match('/^[A-Za-z0-9-]{8,64}$/', $clave)) {
            throw new Exception('La clave del cobro no es válida. Recarga la página.');
        }
    }

    /** Reserva la clave; false si ya se había usado (el mismo cobro llegó dos veces) */
    private function reservarClave(?string $clave, string $folio, ?string $usuario): bool
    {
        if ($clave === null || $clave === '') return true;
        try {
            $this->db->prepare("INSERT INTO operaciones_cobro (clave, folio, usuario) VALUES (?, ?, ?)")
                     ->execute([$clave, $folio, $usuario]);
            return true;
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') return false;
            throw $e;
        }
    }

    /** Lo que se responde cuando el mismo cobro llega otra vez: nada nuevo se guarda */
    private function cobroRepetido(string $clave, float $adeudo): array
    {
        $s = $this->db->prepare("SELECT folio FROM operaciones_cobro WHERE clave = ?");
        $s->execute([$clave]);
        $folio = (string) $s->fetchColumn();
        return ['repetido' => true, 'folio' => $folio, 'reparto' => [], 'recibido' => 0.0, 'recargos' => 0.0,
                'aplicado' => 0.0, 'sobrante' => 0.0, 'adeudo_despues' => round($adeudo, 2), 'simulado' => false,
                'aviso' => "Este cobro ya se había registrado (folio $folio): no se volvió a guardar."];
    }

    // =================================================================
    // MESES DE ANTES DEL CORTE SIN EVIDENCIA: "¿HASTA QUÉ MES PAGÓ?"
    // =================================================================

    /**
     * Alumnos con meses de antes del corte sin pago ni cargo guardado. No son
     * deuda hasta que alguien confirme hasta qué mes pagó.
     */
    public function mesesPorConfirmar(): array
    {
        $corte = $this->congelarDesde();
        if ($corte === null) return [];
        $s = $this->db->prepare(
            "SELECT DISTINCT id_alumno FROM (
                SELECT id_alumno FROM pagos
                 WHERE id_alumno IS NOT NULL AND concepto = 'colegiatura' AND periodo < ?
                UNION
                SELECT id_alumno FROM inscripciones WHERE DATE_FORMAT(fecha_alta, '%Y-%m') < ?) x"
        );
        $s->execute([$corte, $corte]);
        $lista = [];
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $e = $this->estado((int) $id);
            if (!$e['meses_sin_confirmar']) continue;
            $meses = array_values(array_map(fn($m) => ['periodo' => $m['periodo'], 'cargo' => $m['cargo']],
                array_filter($e['meses'], fn($m) => $m['estado'] === 'sin_confirmar')));
            $lista[] = [
                'id_alumno'   => (int) $id,
                'nombre'      => $e['alumno']['nombre'],
                'apellido'    => $e['alumno']['apellido'],
                'colegiatura' => $e['colegiatura'],
                'meses'       => $meses,
                'total'       => round(array_sum(array_column($meses, 'cargo')), 2),
            ];
        }
        usort($lista, fn($a, $b) => [$a['apellido'], $a['nombre']] <=> [$b['apellido'], $b['nombre']]);
        return $lista;
    }

    /**
     * "¿Hasta qué mes pagó?" De los meses por confirmar de antes del corte:
     *   · hasta ese mes (incluido) quedan pagados, por lo que paga el alumno.
     *     Es un pago histórico: no cuenta como dinero que entró hoy.
     *   · después de ese mes quedan como deuda, con un cargo guardado.
     * Sin mes ($hasta = null) es que no pagó ninguno. Queda en la bitácora.
     */
    public function confirmarHistorico(int $idAlumno, ?string $hasta, ?string $usuario = null): array
    {
        $hasta = ($hasta === null || $hasta === '') ? null : $hasta;
        if ($hasta !== null && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $hasta)) {
            throw new Exception('Elige el mes hasta el que pagó.');
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare("SELECT id_alumno FROM alumnos WHERE id_alumno = ? FOR UPDATE")->execute([$idAlumno]);
            $meses = array_values(array_filter($this->estado($idAlumno)['meses'], fn($m) => $m['estado'] === 'sin_confirmar'));
            if (!$meses) throw new Exception('Este alumno ya no tiene meses por confirmar.');

            $folio = Bitacora::folio();
            $pago = $this->db->prepare(
                "INSERT INTO pagos (id_alumno, concepto, monto, recargo, metodo, monto_confirmado, periodo, origen,
                                    fecha_pago, operacion, registrado_por)
                 VALUES (?, 'colegiatura', ?, 0, 'por_confirmar', 1, ?, 'historico', LAST_DAY(CONCAT(?, '-01')), ?, ?)"
            );
            $cargo = $this->db->prepare(
                "INSERT INTO cargos_mensuales (id_alumno, id_inscripcion, periodo, monto, precio_mensual, motivo)
                 VALUES (?, NULL, ?, ?, ?, 'confirmado: no pagó')"
            );
            $r = ['pagados' => 0, 'monto_pagado' => 0.0, 'adeudados' => 0, 'monto_debe' => 0.0, 'folio' => $folio];
            foreach ($meses as $m) {
                $p = $m['periodo'];
                $monto = round((float) $m['cargo'], 2);
                if ($hasta !== null && $p <= $hasta) {
                    $pago->execute([$idAlumno, $monto, $p, $p, $folio, $usuario]);
                    Bitacora::registrar($this->db, 'confirmar mes pagado', 'pagos', (int) $this->db->lastInsertId(),
                        "mes $p", 'por confirmar', "pagado " . number_format($monto, 2, '.', ''),
                        '¿Hasta qué mes pagó?: ' . $hasta, $folio, $usuario);
                    $r['pagados']++; $r['monto_pagado'] += $monto;
                } else {
                    $cargo->execute([$idAlumno, $p, $monto, $monto]);
                    Bitacora::registrar($this->db, 'confirmar mes adeudado', 'cargos_mensuales', (int) $this->db->lastInsertId(),
                        "mes $p", 'por confirmar', "debe " . number_format($monto, 2, '.', ''),
                        '¿Hasta qué mes pagó?: ' . ($hasta ?? 'ninguno'), $folio, $usuario);
                    $r['adeudados']++; $r['monto_debe'] += $monto;
                }
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        $r['monto_pagado'] = round($r['monto_pagado'], 2);
        $r['monto_debe'] = round($r['monto_debe'], 2);
        return $r;
    }

    // =================================================================
    // Auxiliares
    // =================================================================

    private function alumno(int $id): array
    {
        $s = $this->db->prepare("SELECT id_alumno, nombre, apellido, telefono, tutor_nombre, tutor_telefono,
                                        adeudo_anterior, adeudo_anterior_nota, id_familia, requiere_factura
                                   FROM alumnos WHERE id_alumno = ?");
        $s->execute([$id]);
        $a = $s->fetch();
        if (!$a) throw new Exception('Ese alumno no existe.');
        return $a;
    }

    /** Cuánto queda de la deuda anterior al sistema */
    public function adeudoAnterior(int $idAlumno): array
    {
        $s = $this->db->prepare(
            "SELECT a.adeudo_anterior, a.adeudo_anterior_nota,
                    COALESCE((SELECT SUM(p.monto) FROM pagos p
                               WHERE p.id_alumno = a.id_alumno AND p.concepto = 'adeudo_anterior'), 0) AS abonado
               FROM alumnos a WHERE a.id_alumno = ?"
        );
        $s->execute([$idAlumno]);
        $f = $s->fetch() ?: ['adeudo_anterior' => 0, 'adeudo_anterior_nota' => null, 'abonado' => 0];
        return [
            'monto'   => round((float) $f['adeudo_anterior'], 2),
            'abonado' => round((float) $f['abonado'], 2),
            'saldo'   => round(max(0, (float) $f['adeudo_anterior'] - (float) $f['abonado']), 2),
            'nota'    => $f['adeudo_anterior_nota'],
        ];
    }

    /**
     * Lo que paga al mes: la suma de sus clases vivas, con el precio que rige hoy (si una clase tiene un
     * cambio de precio programado para después, todavía no cuenta).
     */
    public function colegiatura(int $idAlumno): float
    {
        $s = $this->db->prepare(
            "SELECT COALESCE(SUM(costo_mensual), 0) FROM inscripciones WHERE id_alumno = ? AND estado <> 'baja'"
        );
        $s->execute([$idAlumno]);
        $suma = (float) $s->fetchColumn();
        foreach (HistorialClase::diferenciasDelMes(date('Y-m'), $this->db) as $d) {
            if ($d['id_alumno'] === $idAlumno) $suma += $d['diferencia'];
        }
        return round($suma, 2);
    }

    /**
     * Lo que se le descuenta a cada alumno en el mes en que entró a medio mes.
     * Solo cuentan las clases capturadas: las importadas traen como fecha de
     * alta el día de la importación, no el día en que empezaron.
     *
     * @return array [id_alumno][periodo] => cuánto baja el cargo de ese mes
     */
    private function rebajasPrimerMes(?int $idAlumno = null): array
    {
        $sql = "SELECT id_inscripcion, id_alumno, costo_mensual, dia_semana, fecha_alta
                  FROM inscripciones
                 WHERE estado <> 'baja' AND origen = 'captura' AND DAY(fecha_alta) > 1";
        $s = $this->db->prepare($sql . ($idAlumno ? ' AND id_alumno = ?' : ''));
        $s->execute($idAlumno ? [$idAlumno] : []);

        $r = [];
        foreach ($s->fetchAll() as $f) {
            $periodo = substr($f['fecha_alta'], 0, 7);
            $semanas = ConfiguracionModel::semanasDe($periodo);
            $t = Calendario::tramo($periodo, $f['dia_semana'], $f['fecha_alta'], null, $semanas);
            if ($t['clases'] === null) continue;
            // El precio que tenía en ese mes (no el de hoy, si cambió después).
            $costo = HistorialClase::precioEn($f, $f['fecha_alta']);
            $rebaja = $costo - round($costo * $t['clases'] / $semanas, 2);
            $id = (int) $f['id_alumno'];
            $r[$id][$periodo] = round(($r[$id][$periodo] ?? 0) + $rebaja, 2);
        }
        return $r;
    }

    private function desde(int $idAlumno): ?string
    {
        $s = $this->db->prepare(
            "SELECT LEAST(
                COALESCE((SELECT MIN(periodo) FROM pagos
                           WHERE id_alumno = ? AND concepto = 'colegiatura'
                             AND periodo REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$'), '9999-99'),
                COALESCE((SELECT DATE_FORMAT(MIN(fecha_alta), '%Y-%m') FROM inscripciones
                           WHERE id_alumno = ?), '9999-99'))"
        );
        $s->execute([$idAlumno, $idAlumno]);
        $v = $s->fetchColumn();
        return ($v && $v !== '9999-99') ? $v : null;
    }

    /**
     * ¿Ese mes de la cuenta del alumno ya tiene pagos? (total o parcial; un pago con monto por
     * confirmar también cuenta: se sabe que pagó). Sirve para no bajarle a un mes ya pagado.
     */
    public function mesConPagos(int $idAlumno, string $periodo): bool
    {
        $a = $this->abonadoPorMes($idAlumno)[$periodo] ?? null;
        return $a !== null && ($a['monto'] > 0.009 || $a['por_confirmar']);
    }

    /**
     * ¿El mes de ESTA clase ya está pagado? No basta con que el alumno tenga un pago ese mes: $300 de una
     * colegiatura de $900 no la cubren. Cuenta como pagado si
     *   · hay un pago con monto por confirmar (se sabe que ese mes se pagó, no cuánto; el estado de cuenta no
     *     le fabrica deuda), o
     *   · el mes ya no debe nada (lo abonado cubre el cargo), o
     *   · hay un pago asignado a esta clase en ese mes.
     * Un pago suelto que no cubre el mes ni se asignó a esta clase no la vuelve "pagada".
     *
     * @return array {pagado: bool, por_confirmar: bool}
     */
    public function pagoDelMes(int $idAlumno, string $periodo, ?int $idInscripcion = null): array
    {
        $a = $this->abonadoPorMes($idAlumno)[$periodo] ?? null;
        if ($a === null || ($a['monto'] <= 0.009 && !$a['por_confirmar'])) return ['pagado' => false, 'por_confirmar' => false];
        if ($a['por_confirmar']) return ['pagado' => true, 'por_confirmar' => true];
        if ($this->saldoPendienteDe($idAlumno, $periodo) <= 0.009) return ['pagado' => true, 'por_confirmar' => false];
        if ($idInscripcion) {
            $s = $this->db->prepare(
                "SELECT COUNT(*) FROM pagos
                  WHERE id_alumno = ? AND id_inscripcion = ? AND concepto = 'colegiatura' AND periodo = ?
                    AND monto > 0 AND anulado = 0 AND anula_a IS NULL"
            );
            $s->execute([$idAlumno, $idInscripcion, $periodo]);
            if ((int) $s->fetchColumn() > 0) return ['pagado' => true, 'por_confirmar' => false];
        }
        return ['pagado' => false, 'por_confirmar' => false];
    }

    /** Lo que le falta por pagar de ese mes (0 si no hay cargo o ya está cubierto) */
    public function saldoPendienteDe(int $idAlumno, string $periodo): float
    {
        foreach ($this->estado($idAlumno)['meses'] as $m) if ($m['periodo'] === $periodo) return (float) $m['saldo'];
        return 0.0;
    }

    private function abonadoPorMes(int $idAlumno): array
    {
        $s = $this->db->prepare(
            "SELECT periodo, SUM(monto) AS monto, SUM(recargo) AS recargo,
                    MAX(monto_confirmado = 0 AND anulado = 0) AS por_confirmar
               FROM pagos
              WHERE id_alumno = ? AND concepto = 'colegiatura'
                AND periodo REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$'
              GROUP BY periodo"
        );
        $s->execute([$idAlumno]);
        $r = [];
        foreach ($s->fetchAll() as $f) {
            $r[$f['periodo']] = ['monto' => (float) $f['monto'], 'recargo' => (float) $f['recargo'],
                                 'por_confirmar' => (bool) $f['por_confirmar']];
        }
        return $r;
    }

    private function vencimiento(string $periodo): string
    {
        return sprintf('%s-%02d', $periodo, (int) $this->config['recargo_dia_limite']);
    }

    private function rangoMeses(string $desde, string $hasta): array
    {
        $r = [];
        for ($p = $desde; $p <= $hasta; $p = $this->mesSiguiente($p)) $r[] = $p;
        return count($r) > self::MAX_MESES ? array_slice($r, -self::MAX_MESES) : $r;
    }

    private function mesSiguiente(string $p): string
    {
        [$a, $m] = array_map('intval', explode('-', $p));
        return $m === 12 ? sprintf('%04d-01', $a + 1) : sprintf('%04d-%02d', $a, $m + 1);
    }
}

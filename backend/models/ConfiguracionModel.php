<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../lib/Bitacora.php';

/**
 * Parámetros de cobro que la escuela puede cambiar sin tocar código:
 * cuánto se recarga y a partir de qué día del mes.
 */
class ConfiguracionModel
{
    private PDO $db;

    private const PREDETERMINADOS = [
        'recargo_monto'      => '50',
        'recargo_dia_limite' => '10',
        'muestra_hora'       => '250',
        'muestra_media_hora' => '125',
        'semanas_por_mes'    => '4',
        'costo_inscripcion'  => '0',
        'tarifa_maestro'     => '0',
        'modo_pago_maestro'  => 'por_alumno',
        'cupo_online'        => '1',
    ];

    public function __construct() { $this->db = Database::conexion(); }

    // =================================================================
    // CLASES AL MES ("semanas por mes") POR PERIODO
    // =================================================================
    // Un cambio de "clases al mes" aplica desde el mes siguiente (o desde este
    // mes, si se pide) y NO reescribe el pasado: cada cambio queda en
    // semanas_por_mes_historial con el valor que había antes. Todo cálculo de
    // un mes concreto pregunta por ese mes: semanasDe('2026-10').

    /** @var array<int,array>|null cambios ordenados por mes; null = sin leer */
    private static ?array $cambiosSemanas = null;
    private static ?int $semanasBase = null;

    /** Olvida lo leído (después de guardar, o si un proceso cambia la tabla a mano) */
    public static function olvidarSemanas(): void { self::$cambiosSemanas = null; self::$semanasBase = null; }

    private static function leerSemanas(): void
    {
        if (self::$cambiosSemanas !== null) return;
        $db = Database::conexion();
        $lista = [];
        try {
            foreach ($db->query("SELECT vigente_desde, semanas, semanas_anterior FROM semanas_por_mes_historial
                                  ORDER BY vigente_desde") as $f) {
                $lista[] = ['desde' => $f['vigente_desde'], 'semanas' => (int) $f['semanas'],
                            'anterior' => (int) $f['semanas_anterior']];
            }
        } catch (PDOException $e) {
            $lista = [];      // base todavía sin migrar: un solo valor para todos los meses
        }
        $v = $db->query("SELECT valor FROM configuracion WHERE clave = 'semanas_por_mes'")->fetchColumn();
        self::$semanasBase = $v === false ? (int) self::PREDETERMINADOS['semanas_por_mes'] : (int) $v;
        self::$cambiosSemanas = $lista;
    }

    /**
     * Cuántas clases vale un mes completo en ESE periodo (AAAA-MM). Sin
     * cambios guardados, es el valor de siempre para todos los meses.
     */
    public static function semanasDe(string $periodo): int
    {
        self::leerSemanas();
        $lista = self::$cambiosSemanas;
        if (!$lista) return max(1, (int) self::$semanasBase);
        $valor = $lista[0]['anterior'];
        foreach ($lista as $c) {
            if ($c['desde'] <= $periodo) $valor = $c['semanas']; else break;
        }
        return max(1, $valor);
    }

    /** El mismo arreglo de configuración, con las clases al mes de ese periodo */
    public static function paraPeriodo(array $config, string $periodo): array
    {
        $config['semanas_por_mes'] = self::semanasDe($periodo);
        return $config;
    }

    /** El próximo cambio ya programado (desde un mes que todavía no llega), si lo hay */
    public static function semanasProximas(): ?array
    {
        self::leerSemanas();
        $mes = date('Y-m');
        foreach (self::$cambiosSemanas as $c) {
            if ($c['desde'] > $mes) return ['semanas' => $c['semanas'], 'desde' => $c['desde']];
        }
        return null;
    }

    /**
     * Todos los cambios de "clases al mes" guardados, para que la pantalla sepa cuántas valía CUALQUIER mes
     * (un mes pasado, este o uno futuro) sin preguntar al servidor: 'antes' es lo que valía antes del primer
     * cambio; 'cambios', desde qué mes rige cada uno. Sin cambios: null.
     */
    public static function semanasCambios(): ?array
    {
        self::leerSemanas();
        if (!self::$cambiosSemanas) return null;
        return ['antes' => self::$cambiosSemanas[0]['anterior'],
                'cambios' => array_map(fn($c) => ['desde' => $c['desde'], 'semanas' => $c['semanas']], self::$cambiosSemanas)];
    }

    /**
     * Cuánto se cobra de inscripción: lo que escribió la persona o, si no escribió nada, el importe de
     * Ajustes. Nunca se inventa: sin ninguno de los dos se pide. Mayor que cero y hasta $100,000.
     *
     * @param mixed $escrito lo que se escribió (null o '' = no escribió nada)
     */
    public static function importeDeInscripcion($escrito): float
    {
        if ($escrito === null || $escrito === '') {
            $monto = (float) (new self())->todo()['costo_inscripcion'];
        } else {
            $monto = filter_var($escrito, FILTER_VALIDATE_FLOAT);
            if ($monto === false || !is_finite($monto)) throw new Exception('El importe de la inscripción tiene que ser un número.');
        }
        if ($monto <= 0) throw new Exception('Escribe cuánto es la inscripción.');
        if ($monto > 100000) throw new Exception('La inscripción es demasiado alta. Revísala.');
        return round($monto, 2);
    }

    public function todo(): array
    {
        $valores = self::PREDETERMINADOS;
        foreach ($this->db->query("SELECT clave, valor FROM configuracion") as $f) {
            $valores[$f['clave']] = $f['valor'];
        }
        return [
            'recargo_monto'      => (float) $valores['recargo_monto'],
            'recargo_dia_limite' => (int) $valores['recargo_dia_limite'],
            'muestra_hora'       => (float) $valores['muestra_hora'],
            'muestra_media_hora' => (float) $valores['muestra_media_hora'],
            // Las de ESTE mes; la programada para después va en semanas_proximas.
            'semanas_por_mes'    => self::semanasDe(date('Y-m')),
            'semanas_proximas'   => self::semanasProximas(),
            'semanas_cambios'    => self::semanasCambios(),
            'costo_inscripcion'  => (float) $valores['costo_inscripcion'],
            'tarifa_maestro'     => (float) $valores['tarifa_maestro'],
            'modo_pago_maestro'  => in_array($valores['modo_pago_maestro'], ['por_alumno','por_hora'], true)
                                    ? $valores['modo_pago_maestro'] : 'por_alumno',
            // Individual por omisión; en línea nunca pasa de 2 (no hay grupos en línea).
            'cupo_online'        => max(1, min(2, (int) $valores['cupo_online'])),
        ];
    }

    /**
     * Guarda solo lo que viene en la petición: un guardado parcial ya no deja el
     * recargo en 0. Se valida todo antes de escribir, y cada cambio queda en la
     * bitácora financiera con su valor anterior (cambian cobros y nómina).
     */
    public function guardar(array $datos): array
    {
        $antes = $this->todo();
        $cambios = [];

        if (array_key_exists('recargo_monto', $datos)) {
            $monto = (float) $datos['recargo_monto'];
            if ($monto < 0) throw new Exception('El recargo no puede ser negativo.');
            $cambios['recargo_monto'] = (string) $monto;
        }
        if (array_key_exists('recargo_dia_limite', $datos)) {
            $dia = (int) $datos['recargo_dia_limite'];
            if ($dia < 1 || $dia > 28) {
                throw new Exception('El día límite debe estar entre 1 y 28, para que exista en todos los meses.');
            }
            $cambios['recargo_dia_limite'] = (string) $dia;
        }
        if (isset($datos['modo_pago_maestro'])
            && in_array($datos['modo_pago_maestro'], ['por_alumno','por_hora'], true)) {
            $cambios['modo_pago_maestro'] = $datos['modo_pago_maestro'];
        }
        if (array_key_exists('cupo_online', $datos)) {
            $cupo = (int) $datos['cupo_online'];
            if ($cupo < 1 || $cupo > 2) throw new Exception('El cupo en línea es 1 (individual) o 2 (compartida).');
            $cambios['cupo_online'] = (string) $cupo;
        }
        foreach (['muestra_hora', 'muestra_media_hora', 'costo_inscripcion', 'tarifa_maestro'] as $clave) {
            if (array_key_exists($clave, $datos)) {
                $valor = (float) $datos[$clave];
                if ($valor < 0) throw new Exception('Los precios no pueden ser negativos.');
                $cambios[$clave] = (string) $valor;
            }
        }
        // Las clases al mes se validan aquí y se guardan aparte (con su mes de arranque).
        $semanas = null;
        if (array_key_exists('semanas_por_mes', $datos)) {
            $v = (float) $datos['semanas_por_mes'];
            if ($v != floor($v) || $v < 1 || $v > 6) {
                throw new Exception('Las clases al mes deben ser un número entero entre 1 y 6.');
            }
            $semanas = (int) $v;
        }

        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO configuracion (clave, valor) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE valor = VALUES(valor)"
            );
            foreach ($cambios as $clave => $valor) {
                $stmt->execute([$clave, $valor]);
                $previo = array_key_exists($clave, $antes) ? (string) $antes[$clave] : null;
                if ($previo === null || (is_numeric($previo) && is_numeric($valor) ? abs((float) $previo - (float) $valor) > 0.0001 : $previo !== $valor)) {
                    Bitacora::registrar($this->db, 'cambiar configuración', 'configuracion', null, $clave, $previo, $valor);
                }
            }
            if ($semanas !== null) {
                $this->cambiarSemanas($semanas, !empty($datos['semanas_este_mes']));
            }
            if ($propia) $this->db->commit();
        } catch (Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            self::olvidarSemanas();
            throw $e;
        }

        return $this->todo();
    }

    /**
     * Cambia las clases al mes. Por omisión rige desde el mes que entra: el
     * mes en curso sigue como está. Con $esteMes, rige desde este mes: la nómina
     * sin pagar (y sin ajuste manual) se vuelve a calcular, y las clases cuyo mes
     * ya está pagado se congelan antes del cambio para que su cargo no se mueva
     * (los que no han pagado sí siguen el valor nuevo). Los meses cerrados ya
     * guardan sus clases_mes.
     */
    private function cambiarSemanas(int $nuevo, bool $esteMes): void
    {
        self::olvidarSemanas();
        $mes = date('Y-m');
        $siguiente = date('Y-m', strtotime($mes . '-01 +1 month'));
        $vigente = self::semanasDe($mes);
        $proxima = self::semanasProximas();
        $guardaValor = fn() => $this->db->prepare(
            "INSERT INTO configuracion (clave, valor) VALUES ('semanas_por_mes', ?)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)")->execute([(string) $nuevo]);

        // ¿Ya está así? No se programa nada. Y si había un cambio programado y se vuelve al
        // valor de hoy sin marcar la casilla, ese cambio programado se cancela.
        if ($nuevo === $vigente && !$proxima) return;
        if ($nuevo === $vigente && $proxima && !$esteMes) {
            $this->db->prepare("DELETE FROM semanas_por_mes_historial WHERE vigente_desde > ?")->execute([$mes]);
            $guardaValor();
            Bitacora::registrar($this->db, 'cambiar configuración', 'configuracion', null, 'semanas_por_mes',
                (string) $proxima['semanas'], (string) $nuevo,
                'Se canceló el cambio programado desde ' . $proxima['desde'] . ': se queda en ' . $nuevo);
            self::olvidarSemanas();
            return;
        }
        if ($proxima && $nuevo === $proxima['semanas'] && !$esteMes) return;   // ya estaba programado así

        $desde = $esteMes ? $mes : $siguiente;
        // Aplicar el cambio a este mes NO debe mover lo que ya se pagó: antes de cambiarlo, las clases cuyo mes ya
        // está pagado se congelan con las clases al mes de hoy (su cargo queda como estaba). Los que no han pagado
        // sí siguen el valor nuevo; la nómina sin pagar se recalcula más abajo.
        if ($esteMes) {
            require_once __DIR__ . '/CuentaModel.php';
            (new CuentaModel())->congelarPagadosDelMes($mes, 'cambio de clases al mes');
        }
        $antesDe = self::semanasDe(date('Y-m', strtotime($desde . '-01 -1 month')));
        // Lo programado desde ese mes en adelante se sustituye por este cambio.
        $this->db->prepare("DELETE FROM semanas_por_mes_historial WHERE vigente_desde >= ?")->execute([$desde]);
        $usuario = PHP_SAPI === 'cli' ? 'Terminal'
                 : (class_exists('Auth', false) ? (Auth::usuarioActual()['nombre'] ?? null) : null);
        $this->db->prepare(
            "INSERT INTO semanas_por_mes_historial (vigente_desde, semanas, semanas_anterior, usuario) VALUES (?, ?, ?, ?)"
        )->execute([$desde, $nuevo, $antesDe, $usuario]);
        $guardaValor();
        self::olvidarSemanas();

        Bitacora::registrar($this->db, 'cambiar configuración', 'configuracion', null, 'semanas_por_mes',
            (string) ($proxima && !$esteMes ? $proxima['semanas'] : $vigente), (string) $nuevo,
            $esteMes ? "Rige desde este mes ($desde): se recalcula la nómina sin pagar de este mes"
                     : "Rige desde el mes que entra ($desde): este mes se queda en $vigente; la nómina sin pagar de ese mes se recalcula");
        $this->recalcularNominaDesde($desde);
    }

    /**
     * La nómina sin pagar (y sin ajuste manual) de ese mes en adelante, la que ya existe, se vuelve a
     * calcular con las clases al mes nuevas. Lo pagado y lo cerrado no se toca.
     */
    private function recalcularNominaDesde(string $desde): void
    {
        require_once __DIR__ . '/NominaModel.php';
        $s = $this->db->prepare("SELECT DISTINCT periodo FROM nomina_detalle WHERE periodo >= ? AND pagado = 0 ORDER BY periodo");
        $s->execute([$desde]);
        $nomina = new NominaModel();
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $periodo) {
            if ($periodo >= date('Y-m')) $nomina->generarPeriodo($periodo);
        }
    }

    /** Cuánto cuesta una clase muestra según su duración */
    public function costoClaseMuestra(int $minutos): float
    {
        $c = $this->todo();
        if ($minutos <= 30) return $c['muestra_media_hora'];
        // Una hora cuesta lo que dice la tabla; más de una hora, proporcional.
        return round($c['muestra_hora'] * ($minutos / 60), 2);
    }

    /**
     * ¿Este pago lleva recargo?
     *
     * La colegiatura de un mes vence el día límite de ese mismo mes. Si se
     * paga después, entra el recargo. Un pago adelantado nunca lo lleva.
     *
     * @param string $periodo YYYY-MM al que corresponde el pago
     * @param string $fecha   día en que se está pagando (hoy, por omisión)
     */
    public function recargoDe(string $periodo, ?string $fecha = null): array
    {
        $config = $this->todo();
        $fecha  = $fecha ?: date('Y-m-d');

        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo)) {
            return ['aplica' => false, 'monto' => 0.0, 'vence' => null, 'dias_tarde' => 0]
                 + ['dia_limite' => $config['recargo_dia_limite']];
        }

        $vence = sprintf('%s-%02d', $periodo, $config['recargo_dia_limite']);
        $tarde = $fecha > $vence;
        $dias  = $tarde
            ? (int) ((strtotime($fecha) - strtotime($vence)) / 86400)
            : 0;

        return [
            'aplica'     => $tarde && $config['recargo_monto'] > 0,
            'monto'      => $tarde ? $config['recargo_monto'] : 0.0,
            'vence'      => $vence,
            'dias_tarde' => $dias,
            'dia_limite' => $config['recargo_dia_limite'],
        ];
    }
}

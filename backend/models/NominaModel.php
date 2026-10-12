<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/ConfiguracionModel.php';
require_once __DIR__ . '/AjusteNominaModel.php';
require_once __DIR__ . '/../lib/Calendario.php';
require_once __DIR__ . '/../lib/Bitacora.php';
require_once __DIR__ . '/HistorialClaseModel.php';

/**
 * Nómina histórica: cada fila pertenece a un periodo (YYYY-MM).
 * El mismo alumno genera una fila cada mes.
 */
class NominaModel
{
    private PDO $db;

    public function __construct() { $this->db = Database::conexion(); }

    /**
     * Cuánto se le paga al maestro por un alumno. NO depende de lo que el
     * alumno paga: eso es ingreso de la escuela. Depende de cómo se le paga
     * a ese maestro.
     *
     *   por_alumno -> su tarifa fija por cada alumno inscrito
     *   por_hora   -> su tarifa por hora, por las horas que da al mes
     *
     * @param array $maestro fila de la tabla maestros
     * @param int   $duracionMin duración de la clase
     */
    public static function montoDeMaestro(array $maestro, int $duracionMin = 60, ?array $config = null): float
    {
        $config = $config ?? (new ConfiguracionModel())->todo();

        if (($maestro['modo_pago'] ?? 'por_alumno') === 'por_hora') {
            $horas = ($duracionMin ?: 60) / 60;
            return round((float) $maestro['tarifa_por_hora'] * $horas * $config['semanas_por_mes'], 2);
        }
        return round((float) $maestro['tarifa_por_alumno'], 2);
    }

    /**
     * Lo que vale una sola clase para ese maestro. Media hora vale media hora:
     * la nómina sigue la duración real de la clase.
     */
    public static function valorDeSesion(array $maestro, int $duracionMin, ?array $config = null): float
    {
        $config = $config ?? (new ConfiguracionModel())->todo();
        if (($maestro['modo_pago'] ?? 'por_alumno') === 'por_hora') {
            return round((float) $maestro['tarifa_por_hora'] * ($duracionMin ?: 60) / 60, 2);
        }
        return round((float) $maestro['tarifa_por_alumno'] / max(1, (int) $config['semanas_por_mes']), 2);
    }

    /**
     * Monto de un renglón: mes completo si $clases es null; si no, solo las
     * clases que dio (entró a medio mes o cambió de maestro).
     */
    public static function montoDeRenglon(array $maestro, int $duracionMin, ?int $clases, ?array $config = null): float
    {
        $config = $config ?? (new ConfiguracionModel())->todo();
        if ($clases === null) return self::montoDeMaestro($maestro, $duracionMin, $config);
        return round(self::valorDeSesion($maestro, $duracionMin, $config) * $clases, 2);
    }

    /** Una parte del mes: si son todas las clases del mes, es el mes completo (sin redondeos de más) */
    private static function montoDeParte(array $maestro, int $duracionMin, int $clases, array $config): float
    {
        return self::montoDeRenglon($maestro, $duracionMin, $clases >= (int) $config['semanas_por_mes'] ? null : $clases, $config);
    }

    /** Lo que se le paga al maestro por un reparto de clases (cada tramo con su duración): [['duracion', 'clases'], ...]; nunca más que el mes completo */
    public static function montoPorTramos(array $maestro, array $partes, ?array $config = null): float
    {
        $config = $config ?? (new ConfiguracionModel())->todo();
        // Un mes no vale más que sus clases (semanas_por_mes): si el reparto trae de más (clases dichas a mano), lo que
        // pasa del tope no se paga. Así el importe y la columna "clases" dicen lo mismo.
        $tope = (int) ($config['semanas_por_mes'] ?? 0);
        $acum = 0; $suma = 0.0;
        foreach ($partes as $t) {
            $c = (int) $t['clases'];
            if ($tope > 0) $c = min($c, max(0, $tope - $acum));
            if ($c <= 0) continue;
            $acum += $c;
            $suma += self::montoDeParte($maestro, (int) ($t['duracion'] ?: 60), $c, $config);
        }
        return round($suma, 2);
    }

    /**
     * El importe de un renglón de nómina siguiendo lo que la clase duraba cada día. Una clase sin
     * cambios de duración con fecha se calcula como siempre (montoDeRenglon); una que cambió a mitad
     * de mes suma cada tramo con la duración que tenía: 2 clases de una hora + 2 de media hora.
     *
     * @param array       $ins   la clase (id_inscripcion, dia_semana, duracion_min, fecha_alta, origen)
     * @param string|null $desde primer día del renglón (null = desde el inicio de la clase o del mes)
     * @param string|null $hasta último día del renglón (null = fin de mes)
     * @param int|null    $clases clases del renglón (null = mes completo)
     */
    public static function montoDelRenglon(array $maestro, array $ins, string $periodo, ?string $desde, ?string $hasta,
                                           ?int $clases, ?array $config = null): float
    {
        $config = ConfiguracionModel::paraPeriodo($config ?? (new ConfiguracionModel())->todo(), $periodo);
        $duracion = (int) ($ins['duracion_min'] ?? 0) ?: 60;
        $sencillo = self::montoDeRenglon($maestro, $duracion, $clases, $config);
        $filas = HistorialClase::filasDe($ins);
        if (!$filas) return $sencillo;

        $semanas = (int) $config['semanas_por_mes'];
        // Un renglón de mes completo (sin clases) cuenta desde el día 1 aunque la clase se haya dado de alta después.
        $inicio = $desde ?: ($clases === null ? null : self::inicioDeClase($ins));
        $tramos = HistorialClase::tramos($filas, 0.0, $duracion, $ins['dia_semana'] ?? null);
        $reparto = HistorialClase::reparto($tramos, $periodo, $ins['dia_semana'] ?? null, $inicio, $hasta, $semanas);
        if (!$reparto) return $sencillo;
        if (count($reparto) === 1) {
            return self::montoDeRenglon($maestro, (int) ($reparto[0]['duracion'] ?: $duracion), $clases, $config);
        }
        // Si las clases del renglón ya no cuadran con el reparto (se corrigieron a mano), manda la duración vigente.
        if (array_sum(array_column($reparto, 'clases')) !== ($clases ?? $semanas)) return $sencillo;
        $suma = 0.0;
        foreach ($reparto as $t) $suma += self::montoDeParte($maestro, (int) ($t['duracion'] ?: $duracion), (int) $t['clases'], $config);
        return round($suma, 2);
    }

    /**
     * Desde cuándo cuenta una clase dentro del periodo. Las capturadas cuentan
     * desde su fecha de alta; las importadas no, porque su fecha de alta es el
     * día de la importación y no el día en que empezaron.
     */
    public static function inicioDeClase(array $inscripcion): ?string
    {
        return ($inscripcion['origen'] ?? 'captura') === 'captura' ? ($inscripcion['fecha_alta'] ?? null) : null;
    }

    private const NUMERO_DIA = Calendario::NUMERO_DIA;

    /**
     * Cuándo toca pagarle a un maestro.
     *
     * A los maestros se les paga el último día del mes en que vienen a la
     * escuela: si alguien solo da clase los miércoles, cobra el último
     * miércoles del mes. Con varios días, cobra el último de ellos.
     *
     * Solo cuentan los días en que viene EN PERSONA: una clase en línea se
     * paga igual en su nómina, pero no lo trae a la escuela. Quien solo da
     * clases en línea no tiene día de pago sugerido.
     *
     * @param array $dias días en que da clase, por ejemplo ['Lunes','Miercoles']
     * @return string|null fecha AAAA-MM-DD, o null si no tiene clases con horario
     */
    public static function diaDePago(array $dias, string $periodo): ?string
    {
        // Un periodo mal formado no debe reventar el cálculo: se descarta.
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo)) return null;

        $numeros = [];
        foreach ($dias as $d) {
            if (isset(self::NUMERO_DIA[$d])) $numeros[] = self::NUMERO_DIA[$d];
        }
        if (!$numeros) return null;

        [$anio, $mes] = array_map('intval', explode('-', $periodo));
        $ultimo = (int) date('t', mktime(0, 0, 0, $mes, 1, $anio));

        for ($dia = $ultimo; $dia >= 1; $dia--) {
            $n = (int) date('N', mktime(0, 0, 0, $mes, $dia, $anio));
            if (in_array($n, $numeros, true)) {
                return sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
            }
        }
        return null;
    }

    /** Meses que ya tienen nómina registrada, del más reciente al más viejo */
    public function periodos(): array
    {
        return $this->db->query(
            "SELECT periodo,
                    COUNT(DISTINCT id_maestro) AS maestros,
                    COUNT(*) AS renglones,
                    SUM(monto) AS total,
                    SUM(pagado) AS pagados
             FROM nomina_detalle
             GROUP BY periodo
             ORDER BY periodo DESC"
        )->fetchAll();
    }

    /** Nómina de un mes, agrupada por maestro */
    public function resumenGeneral(?string $periodo = null): array
    {
        $periodo = $periodo ?: date('Y-m');
        // Periodo saneado: si no cumple AAAA-MM, se usa el mes en curso. Evita
        // que un valor inyectado en la URL llegue a las consultas o al cálculo.
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo)) $periodo = date('Y-m');
        $this->sincronizarMes($periodo);
        // Aparece todo maestro con nómina que siga activo, y también el que ya se
        // fue pero tiene renglones o movimientos en ese mes: su último tramo se
        // le tiene que poder pagar e imprimir.
        $stmt = $this->db->prepare(
            "SELECT m.id_maestro, m.nombre, m.apellido, m.tarifa_por_alumno, m.tarifa_por_hora, m.modo_pago, m.activo,
                    COUNT(DISTINCT nd.id_alumno) AS num_alumnos,
                    COUNT(nd.id_detalle) AS num_clases,
                    COALESCE(SUM(nd.monto), 0) AS total_a_pagar,
                    COALESCE(SUM(nd.pagado), 0) AS renglones_pagados,
                    -- Días en que viene a la escuela: solo clases presenciales.
                    (SELECT GROUP_CONCAT(DISTINCT ins.dia_semana
                                         ORDER BY FIELD(ins.dia_semana,'Lunes','Martes','Miercoles','Jueves','Viernes','Sabado'))
                       FROM inscripciones ins
                      WHERE ins.id_maestro = m.id_maestro AND ins.estado = 'activa'
                        AND ins.modalidad = 'presencial' AND ins.dia_semana IS NOT NULL) AS dias_clase,
                    -- Días con clases en línea: se pagan, pero no son asistencia.
                    (SELECT GROUP_CONCAT(DISTINCT ins.dia_semana
                                         ORDER BY FIELD(ins.dia_semana,'Lunes','Martes','Miercoles','Jueves','Viernes','Sabado'))
                       FROM inscripciones ins
                      WHERE ins.id_maestro = m.id_maestro AND ins.estado = 'activa'
                        AND ins.modalidad = 'online' AND ins.dia_semana IS NOT NULL) AS dias_en_linea,
                    (SELECT MAX(fecha_pago) FROM nomina_detalle
                      WHERE id_maestro = m.id_maestro AND periodo = ?) AS fecha_pagado
             FROM maestros m
             LEFT JOIN nomina_detalle nd ON nd.id_maestro = m.id_maestro AND nd.periodo = ?
             WHERE m.lleva_nomina = 1
               AND (m.activo = 1
                    OR EXISTS (SELECT 1 FROM nomina_detalle x WHERE x.id_maestro = m.id_maestro AND x.periodo = ?)
                    OR EXISTS (SELECT 1 FROM nomina_ajustes y WHERE y.id_maestro = m.id_maestro AND y.periodo = ?))
             GROUP BY m.id_maestro, m.nombre, m.apellido, m.tarifa_por_alumno, m.tarifa_por_hora, m.modo_pago, m.activo
             ORDER BY total_a_pagar DESC, m.apellido, m.nombre"
        );
        $stmt->execute([$periodo, $periodo, $periodo, $periodo]);
        $filas = $stmt->fetchAll();

        // Faltas y suplencias del mes
        $ajustes = [];
        $aj = $this->db->prepare(
            "SELECT id_maestro, SUM(monto) AS total, COUNT(*) AS movimientos,
                    SUM(pagado) AS pagados,
                    SUM(CASE WHEN tipo = 'falta' THEN 1 ELSE 0 END) AS faltas,
                    SUM(CASE WHEN tipo = 'suplencia' THEN 1 ELSE 0 END) AS suplencias,
                    SUM(CASE WHEN tipo = 'bono' THEN monto ELSE 0 END) AS bono,
                    SUM(CASE WHEN tipo = 'muestra' THEN 1 ELSE 0 END) AS muestras
               FROM nomina_ajustes WHERE periodo = ? GROUP BY id_maestro"
        );
        $aj->execute([$periodo]);
        foreach ($aj->fetchAll() as $a) { $ajustes[(int) $a['id_maestro']] = $a; }

        $hoy = date('Y-m-d');

        // Cuántos alumnos distintos hay en la nómina del mes: un alumno con dos maestros cuenta una sola vez
        // (sumar el num_alumnos de cada maestro lo contaría doble).
        $dist = $this->db->prepare(
            "SELECT COUNT(DISTINCT nd.id_alumno) FROM nomina_detalle nd
               JOIN maestros m ON m.id_maestro = nd.id_maestro AND m.lleva_nomina = 1
              WHERE nd.periodo = ?"
        );
        $dist->execute([$periodo]);
        $alumnosDistintos = (int) $dist->fetchColumn();

        foreach ($filas as &$f) {
            $f['alumnos_escuela'] = $alumnosDistintos;
            $dias = array_values(array_filter(explode(',', (string) $f['dias_clase'])));
            $f['dias'] = $dias;
            $f['dias_en_linea'] = array_values(array_filter(explode(',', (string) $f['dias_en_linea'])));
            $f['fecha_pago_sugerida'] = self::diaDePago($dias, $periodo);

            // Base (sus alumnos) + ajustes (faltas restan, suplencias suman).
            $a = $ajustes[(int) $f['id_maestro']] ?? null;
            $f['total_base']     = round((float) $f['total_a_pagar'], 2);
            $f['total_ajustes']  = $a ? round((float) $a['total'], 2) : 0.0;
            $f['faltas']         = $a ? (int) $a['faltas'] : 0;
            $f['suplencias']     = $a ? (int) $a['suplencias'] : 0;
            // Bono y clases muestra también hacen que un maestro cobre este mes
            // aunque no tenga alumnos propios.
            $f['movimientos']    = $a ? (int) $a['movimientos'] : 0;
            $f['bono']           = $a ? round((float) $a['bono'], 2) : 0.0;
            $f['muestras']       = $a ? (int) $a['muestras'] : 0;
            $f['total_a_pagar']  = round(max(0, $f['total_base'] + $f['total_ajustes']), 2);

            // Pagado: todos sus renglones y todos sus ajustes marcados. Un
            // suplente sin alumnos propios se paga solo por sus suplencias.
            $renglones = (int) $f['num_clases'];
            $movs      = $a ? (int) $a['movimientos'] : 0;
            $pagado = ($renglones + $movs) > 0
                   && (int) $f['renglones_pagados'] === $renglones
                   && (!$a || (int) $a['pagados'] === $movs);
            $f['pagado'] = $pagado;

            if ($pagado) {
                $f['urgencia'] = 'pagado';
            } elseif ($f['fecha_pago_sugerida'] === null) {
                // Sin horario asignado no se sabe que dia viene; se atiende al final.
                $f['urgencia'] = 'sin_fecha';
            } elseif ($f['fecha_pago_sugerida'] < $hoy) {
                $f['urgencia'] = 'vencido';
            } elseif ($f['fecha_pago_sugerida'] === $hoy) {
                $f['urgencia'] = 'hoy';
            } else {
                $f['urgencia'] = 'proximo';
            }

            $f['dias_para_pago'] = $f['fecha_pago_sugerida']
                ? (int) floor((strtotime($f['fecha_pago_sugerida']) - strtotime($hoy)) / 86400)
                : null;
        }
        unset($f);

        // Primero los que faltan por pagar, empezando por los que ya vencieron.
        $orden = ['vencido' => 0, 'hoy' => 1, 'proximo' => 2, 'sin_fecha' => 3, 'pagado' => 4];
        usort($filas, function ($a, $b) use ($orden) {
            $c = $orden[$a['urgencia']] <=> $orden[$b['urgencia']];
            if ($c !== 0) return $c;
            $fa = $a['fecha_pago_sugerida'] ?? '9999-99-99';
            $fb = $b['fecha_pago_sugerida'] ?? '9999-99-99';
            if ($fa !== $fb) return $fa <=> $fb;
            return (float) $b['total_a_pagar'] <=> (float) $a['total_a_pagar'];
        });

        return $filas;
    }

    /** Qué alumnos componen la nómina de un maestro en un mes */
    public function detalleDeMaestro(int $idMaestro, ?string $periodo = null): array
    {
        $periodo = $periodo ?: date('Y-m');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo)) $periodo = date('Y-m');
        $stmt = $this->db->prepare(
            "SELECT nd.*, a.nombre AS alumno_nombre, a.apellido AS alumno_apellido,
                    ins.dia_semana, ins.hora_inicio, ins.hora_fin, ins.estado AS estado_inscripcion,
                    ins.duracion_min, ins.costo_mensual AS paga_el_alumno,
                    COALESCE(i.nombre, 'Por asignar') AS instrumento,
                    -- Tramo cerrado: la clase siguió con otro maestro.
                    CASE WHEN nd.hasta IS NOT NULL AND ins.id_maestro <> nd.id_maestro
                         THEN TRIM(CONCAT(mi.nombre, ' ', COALESCE(mi.apellido, ''))) END AS paso_a
             FROM nomina_detalle nd
             JOIN alumnos a         ON a.id_alumno = nd.id_alumno
             JOIN inscripciones ins ON ins.id_inscripcion = nd.id_inscripcion
             JOIN maestros mi       ON mi.id_maestro = ins.id_maestro
             LEFT JOIN instrumentos i ON i.id_instrumento = ins.id_instrumento
             WHERE nd.id_maestro = ? AND nd.periodo = ?
             ORDER BY a.apellido, a.nombre"
        );
        $stmt->execute([$idMaestro, $periodo]);
        // Las clases que valía ese mes (la pantalla dice "2 de 4 clases": el 4 es de ese mes, no el de hoy).
        $filas = $stmt->fetchAll();
        $semanas = ConfiguracionModel::semanasDe($periodo);
        foreach ($filas as &$f) $f['semanas_mes'] = $semanas;
        unset($f);
        return $filas;
    }

    /**
     * Arma la nómina de un mes a partir de las inscripciones vivas. Vuelve a
     * calcular los renglones sin pagar con la tarifa actual, respetando las
     * clases de cada tramo (alta a medio mes, cambio de maestro).
     */
    public function generarPeriodo(string $periodo): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo)) {
            throw new Exception('El periodo debe tener el formato AAAA-MM.');
        }
        // La nómina de un mes pasado es historia: no se vuelve a calcular con las
        // tarifas de hoy ni se le agregan renglones. Un renglón se corrige ajustándolo.
        if ($periodo < date('Y-m')) {
            throw new Exception('La nómina de un mes pasado no se vuelve a generar. Si un renglón está mal, ajústalo.');
        }
        $config = ConfiguracionModel::paraPeriodo((new ConfiguracionModel())->todo(), $periodo);

        $filas = $this->db->prepare(
            "SELECT nd.id_detalle, nd.clases, nd.desde, nd.hasta, nd.id_inscripcion, COALESCE(ins.duracion_min, 60) AS duracion_min,
                    ins.dia_semana, ins.fecha_alta, ins.origen,
                    m.modo_pago, m.tarifa_por_hora, m.tarifa_por_alumno
               FROM nomina_detalle nd
               JOIN inscripciones ins ON ins.id_inscripcion = nd.id_inscripcion
               JOIN maestros m ON m.id_maestro = nd.id_maestro
              WHERE nd.periodo = ? AND nd.pagado = 0 AND nd.ajuste_manual = 0"
        );
        $filas->execute([$periodo]);
        $actualiza = $this->db->prepare("UPDATE nomina_detalle SET monto = ? WHERE id_detalle = ?");
        foreach ($filas->fetchAll() as $f) {
            $clases = $f['clases'] === null ? null : (int) $f['clases'];
            $actualiza->execute([self::montoDelRenglon($f, $f, $periodo, $f['desde'], $f['hasta'], $clases, $config), $f['id_detalle']]);
        }

        $this->agregarFaltantes($periodo, $config);

        $total = $this->db->prepare("SELECT COUNT(*), COALESCE(SUM(monto),0) FROM nomina_detalle WHERE periodo = ?");
        $total->execute([$periodo]);
        [$renglones, $suma] = $total->fetch(PDO::FETCH_NUM);

        return ['periodo' => $periodo, 'renglones' => (int) $renglones, 'total' => (float) $suma];
    }

    /**
     * Le da su renglón a toda clase viva que no tenga ninguno en ese mes. Si
     * entró a medio mes, solo con las clases que le tocan desde su alta.
     */
    private function agregarFaltantes(string $periodo, array $config): int
    {
        $config = ConfiguracionModel::paraPeriodo($config, $periodo);
        [, $fin] = Calendario::limites($periodo);
        $stmt = $this->db->prepare(
            "SELECT ins.id_inscripcion, ins.id_alumno, ins.id_maestro, ins.dia_semana, ins.duracion_min,
                    ins.fecha_alta, ins.origen, m.modo_pago, m.tarifa_por_hora, m.tarifa_por_alumno
               FROM inscripciones ins
               JOIN maestros m ON m.id_maestro = ins.id_maestro AND m.lleva_nomina = 1
              WHERE ins.estado <> 'baja'
                AND (ins.origen = 'importacion' OR ins.fecha_alta <= ?)
                AND NOT EXISTS (SELECT 1 FROM nomina_detalle nd
                                 WHERE nd.id_inscripcion = ins.id_inscripcion AND nd.periodo = ?)"
        );
        $stmt->execute([$fin, $periodo]);

        $n = 0;
        foreach ($stmt->fetchAll() as $ins) {
            $t = Calendario::tramo($periodo, $ins['dia_semana'], self::inicioDeClase($ins), null,
                                   (int) $config['semanas_por_mes']);
            if ($t['clases'] === 0) continue;
            $this->insertar($this->db, [
                'id_maestro' => $ins['id_maestro'], 'id_inscripcion' => $ins['id_inscripcion'],
                'id_alumno' => $ins['id_alumno'], 'periodo' => $periodo,
                'monto' => self::montoDelRenglon($ins, $ins, $periodo, $t['desde'], null, $t['clases'], $config),
                'clases' => $t['clases'], 'desde' => $t['desde'],
            ]);
            $n++;
        }
        return $n;
    }

    /**
     * Mantiene la nómina del mes al día con las clases reales.
     *
     *   · Toda clase viva que no tenga su renglón, lo recibe.
     *   · Toda clase dada de baja pierde su renglón, si no se ha pagado.
     *   · Los renglones que ya existen NO se tocan: si alguien corrigió un monto
     *     a mano, esa corrección se respeta.
     *   · El tramo cerrado de un maestro que dejó la clase a medio mes (el que
     *     tiene fecha "hasta") es suyo: no se mueve ni se borra.
     *
     * Así un maestro con alumnos activos nunca desaparece de la nómina por una
     * fusión, una baja o una clase que cambió de maestro. Se puede llamar las
     * veces que sea: no duplica nada.
     */
    public function sincronizarMes(?string $periodo = null): array
    {
        $periodo = $periodo ?: date('Y-m');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo)) $periodo = date('Y-m');
        // Los meses pasados son historia y no se reescriben.
        if ($periodo < date('Y-m')) return ['agregados' => 0, 'movidos' => 0, 'quitados' => 0];

        $agregados = $this->agregarFaltantes($periodo, (new ConfiguracionModel())->todo());

        // El bono de puntualidad se pone solo y se quita si el mes tiene faltas.
        (new AjusteNominaModel())->sincronizarBonos($periodo);

        // Si la clase cambió de maestro sin pasar por el cambio con fecha (una
        // fusión, por ejemplo), el renglón sin pagar sigue al maestro nuevo.
        // Si ese maestro ya tiene su propio renglón de la clase en el mes, no se encima.
        $candidatos = $this->db->prepare(
            "SELECT nd.id_detalle, ins.id_maestro, ins.id_alumno
               FROM nomina_detalle nd
               JOIN inscripciones ins ON ins.id_inscripcion = nd.id_inscripcion
              WHERE nd.periodo = ? AND nd.pagado = 0 AND nd.hasta IS NULL
                AND (nd.id_maestro <> ins.id_maestro OR nd.id_alumno <> ins.id_alumno)
                AND NOT EXISTS (SELECT 1 FROM nomina_detalle otro
                                 WHERE otro.id_inscripcion = nd.id_inscripcion AND otro.periodo = nd.periodo
                                   AND otro.id_maestro = ins.id_maestro AND otro.id_detalle <> nd.id_detalle)"
        );
        $candidatos->execute([$periodo]);
        $mover = $this->db->prepare("UPDATE nomina_detalle SET id_maestro = ?, id_alumno = ? WHERE id_detalle = ?");
        $movidos = 0;
        foreach ($candidatos->fetchAll() as $c) {
            $mover->execute([$c['id_maestro'], $c['id_alumno'], $c['id_detalle']]);
            $movidos += $mover->rowCount();
        }

        $quitados = self::borrarRenglones($this->db,
            "nd.periodo = ? AND nd.hasta IS NULL
             AND EXISTS (SELECT 1 FROM inscripciones ins WHERE ins.id_inscripcion = nd.id_inscripcion AND ins.estado = 'baja')",
            [$periodo], 'Se quitó porque la clase está dada de baja (tenía un ajuste manual)');

        // Quien no lleva nómina (la dueña) no tiene renglones que pagar.
        self::borrarRenglones($this->db,
            "nd.periodo = ?
             AND EXISTS (SELECT 1 FROM maestros m WHERE m.id_maestro = nd.id_maestro AND m.lleva_nomina = 0)",
            [$periodo], 'Se quitó porque el maestro no lleva nómina (tenía un ajuste manual)');

        return ['agregados' => $agregados, 'movidos' => $movidos, 'quitados' => $quitados];
    }

    /**
     * Una clase pasa a otro maestro a partir de una fecha del mes.
     *
     * Al maestro anterior se le dejan las clases que dio antes de esa fecha
     * (desde el día 1, o desde que el alumno entró), y al nuevo las que quedan
     * del mes. Entre los dos nunca pasan de las clases del mes. Los meses
     * siguientes ya son completos del maestro nuevo. Lo pagado no se toca.
     *
     * Va dentro de la transacción del InscripcionService.
     *
     * @param array $inscripcion la inscripción ANTES del cambio
     * @param array $nuevo       fila de maestros del maestro que la toma
     * @return array cuántas clases le tocaron a cada uno, para avisar en pantalla
     */
    public function cambiarMaestro(PDO $db, array $inscripcion, array $nuevo, string $fecha,
                                   ?string $diaNuevo, int $duracion): array
    {
        $periodo = substr($fecha, 0, 7);
        $config = ConfiguracionModel::paraPeriodo((new ConfiguracionModel())->todo(), $periodo);
        $semanas = (int) $config['semanas_por_mes'];
        $idIns = (int) $inscripcion['id_inscripcion'];
        $idViejo = (int) $inscripcion['id_maestro'];
        $idNuevo = (int) $nuevo['id_maestro'];

        // Meses siguientes: completos del maestro nuevo (o sin renglón, si no lleva nómina).
        if ((string) ($nuevo['lleva_nomina'] ?? '1') === '0') {
            self::borrarRenglones($db, "nd.id_inscripcion = ? AND nd.periodo > ?", [$idIns, $periodo],
                'Se quitó por el cambio de maestro: el maestro nuevo no lleva nómina (tenía un ajuste manual)');
        } else {
            // Los meses siguientes pasan completos al maestro nuevo con su importe de tarifa. Un
            // ajuste manual no se pisa en silencio: queda en la bitácora cuánto era.
            $montoNuevo = self::montoDeMaestro($nuevo, $duracion, $config);
            $manuales = $db->prepare(
                "SELECT id_detalle, monto FROM nomina_detalle
                  WHERE id_inscripcion = ? AND periodo > ? AND pagado = 0 AND ajuste_manual = 1"
            );
            $manuales->execute([$idIns, $periodo]);
            foreach ($manuales->fetchAll() as $m) {
                Bitacora::registrar($db, 'recalcular ajuste manual', 'nomina_detalle', (int) $m['id_detalle'], 'monto',
                    $m['monto'], number_format($montoNuevo, 2, '.', ''),
                    'Se recalculó por el cambio de maestro: tenía un ajuste manual');
            }
            $db->prepare(
                "UPDATE nomina_detalle SET id_maestro = ?, monto = ?, clases = NULL, desde = NULL, hasta = NULL, ajuste_manual = 0
                  WHERE id_inscripcion = ? AND periodo > ? AND pagado = 0"
            )->execute([$idNuevo, $montoNuevo, $idIns, $periodo]);
        }

        $s = $db->prepare("SELECT * FROM nomina_detalle WHERE id_inscripcion = ? AND periodo = ?");
        $s->execute([$idIns, $periodo]);
        $renglones = [];
        foreach ($s->fetchAll() as $r) $renglones[(int) $r['id_maestro']] = $r;
        $viejo = $renglones[$idViejo] ?? null;

        if ($viejo && (int) $viejo['pagado'] === 1) {
            return ['viejo' => null, 'nuevo' => 0, 'clases_mes' => $semanas,
                    'aviso' => 'Este mes ya se le pagó al maestro anterior: el nuevo cobra desde el mes que entra.'];
        }

        // Tramo del maestro anterior: desde donde empezaba su renglón hasta un día antes del cambio.
        $clasesViejo = 0;
        if ($viejo) {
            [$ini] = Calendario::limites($periodo);
            $desdeViejo = $viejo['desde'] ?: (self::inicioDeClase($inscripcion) ?: $ini);
            if ($desdeViejo < $ini) $desdeViejo = $ini;
            $hastaViejo = date('Y-m-d', strtotime($fecha . ' -1 day'));
            $t = Calendario::tramo($periodo, $inscripcion['dia_semana'], $desdeViejo, $hastaViejo, $semanas);
            $clasesViejo = $t['clases'] ?? $semanas;
            if ($clasesViejo === 0) {
                self::borrarRenglones($db, "nd.id_detalle = ?", [$viejo['id_detalle']],
                    'Se quitó por el cambio de maestro: no dio clases ese mes (tenía un ajuste manual)');
            } else {
                $mv = $db->prepare("SELECT * FROM maestros WHERE id_maestro = ?");
                $mv->execute([$idViejo]);
                $maestroViejo = $mv->fetch() ?: [];
                // Solo dio una parte del mes: se le paga lo que dio. Un ajuste manual no se pisa en
                // silencio: se quita la marca y queda en la bitácora cuánto era.
                $montoViejo = self::montoDelRenglon($maestroViejo, $inscripcion, $periodo, $desdeViejo, $hastaViejo, $clasesViejo, $config);
                if ((int) $viejo['ajuste_manual'] === 1) {
                    Bitacora::registrar($db, 'recalcular ajuste manual', 'nomina_detalle', (int) $viejo['id_detalle'], 'monto',
                        $viejo['monto'], number_format($montoViejo, 2, '.', ''),
                        'Se recalculó por el cambio de maestro: tenía un ajuste manual');
                }
                $db->prepare(
                    "UPDATE nomina_detalle SET clases = ?, desde = ?, hasta = ?, monto = ?, ajuste_manual = 0 WHERE id_detalle = ?"
                )->execute([$clasesViejo, $desdeViejo, $hastaViejo, $montoViejo, $viejo['id_detalle']]);
            }
        } else {
            // Sin renglón del anterior: el tramo nuevo arranca donde arrancaba la clase.
            $inicio = self::inicioDeClase($inscripcion);
            if ($inicio && $inicio > $fecha) $fecha = $inicio;
        }

        // Tramo del maestro nuevo: del cambio a fin de mes. Entre todos los
        // maestros que la dieron este mes no pasan de las clases del mes.
        $t = Calendario::tramo($periodo, $diaNuevo, $fecha, null, $semanas);
        $yaTenia = $renglones[$idNuevo] ?? null;   // regresó con uno que ya la dio este mes
        $yaDadas = $clasesViejo + ($yaTenia ? (int) ($yaTenia['clases'] ?? $semanas) : 0);
        foreach ($renglones as $idM => $r) {
            if ($idM !== $idViejo && $idM !== $idNuevo) $yaDadas += (int) ($r['clases'] ?? $semanas);
        }
        $clasesNuevo = max(0, min($t['clases'] ?? $semanas, $semanas - $yaDadas));
        if ($yaTenia && (int) $yaTenia['pagado'] === 1) $clasesNuevo = 0;

        if ($clasesNuevo > 0) {
            $total = $yaTenia ? (int) ($yaTenia['clases'] ?? $semanas) + $clasesNuevo : $clasesNuevo;
            $clases = $total >= $semanas ? null : $total;
            $datos = [
                'monto'  => self::montoDeRenglon($nuevo, $duracion, $clases, $config),
                'clases' => $clases,
                'desde'  => $clases === null ? null : ($yaTenia['desde'] ?? $fecha),
            ];
            if ($yaTenia) {
                // Regresó con un maestro que ya la dio este mes: su renglón suma las
                // clases nuevas. Si estaba ajustado a mano, el cambio queda en la bitácora.
                if ((int) ($yaTenia['ajuste_manual'] ?? 0) === 1) {
                    Bitacora::registrar($db, 'recalcular ajuste manual', 'nomina_detalle', (int) $yaTenia['id_detalle'],
                        'monto', $yaTenia['monto'], number_format((float) $datos['monto'], 2, '.', ''),
                        'Se recalculó por el cambio de maestro: tenía un ajuste manual');
                }
                $db->prepare("UPDATE nomina_detalle SET monto = ?, clases = ?, desde = ?, hasta = NULL, ajuste_manual = 0
                               WHERE id_detalle = ?")
                   ->execute([$datos['monto'], $datos['clases'], $datos['desde'], $yaTenia['id_detalle']]);
            } else {
                $this->insertar($db, $datos + [
                    'id_maestro' => $idNuevo, 'id_inscripcion' => $idIns,
                    'id_alumno' => $inscripcion['id_alumno'], 'periodo' => $periodo,
                ]);
            }
        }

        return ['viejo' => $viejo ? $clasesViejo : null, 'nuevo' => $clasesNuevo, 'clases_mes' => $semanas];
    }

    /**
     * La clase cambió de duración y/o de día a mitad de mes CON EL MISMO MAESTRO (rige desde $fecha,
     * un día del mes en curso). La nómina sin pagar se reparte igual que el cobro al alumno:
     *
     *   · Mes en curso: las clases de antes de la fecha, con la duración y el día de antes, más las de
     *     desde la fecha, con los nuevos (con tope en las clases del mes). Un renglón ya pagado no
     *     se toca: el cambio le aplica desde el mes siguiente.
     *   · Meses siguientes sin pagar: la duración nueva completa.
     *   · Un ajuste manual no se pisa en silencio: si el cambio de verdad mueve el importe, se quita
     *     la marca y queda en la bitácora cuánto era. Si el cambio no mueve lo que se debe (un maestro
     *     que cobra por alumno, un cambio de día sin clases de más ni de menos), el renglón no se toca.
     *
     * Va dentro de la transacción del InscripcionService, con el historial de la clase ya guardado.
     *
     * @param array $antes   la clase antes del cambio
     * @param array $despues la clase después del cambio (misma fila de inscripciones)
     * @param bool  $forzar  el mes se dijo completo otra vez (importación de horarios): se recalcula aunque el importe no cambie
     * @return array {mes: {pagado, antes, despues}|null, futuros: renglones recalculados}
     */
    public function cambioDeClase(PDO $db, array $antes, array $despues, string $fecha, bool $forzar = false): array
    {
        $periodo = substr($fecha, 0, 7);
        $config = ConfiguracionModel::paraPeriodo((new ConfiguracionModel())->todo(), $periodo);
        $semanas = (int) $config['semanas_por_mes'];
        $idIns = (int) $antes['id_inscripcion'];
        $idM = (int) $despues['id_maestro'];
        $mm = $db->prepare("SELECT * FROM maestros WHERE id_maestro = ?");
        $mm->execute([$idM]);
        $maestro = $mm->fetch() ?: [];
        $durVieja = (int) ($antes['duracion_min'] ?? 0) ?: 60;
        $diaViejo = $antes['dia_semana'] ?: null;
        $diaNuevo = $despues['dia_semana'] ?: null;
        $resultado = ['mes' => null, 'futuros' => 0];
        $motivo = 'Se recalculó por el cambio de duración o de día de la clase: tenía un ajuste manual';

        // ---------- el mes en curso
        $s = $db->prepare("SELECT * FROM nomina_detalle WHERE id_inscripcion = ? AND id_maestro = ? AND periodo = ?");
        $s->execute([$idIns, $idM, $periodo]);
        $fila = $s->fetch();
        if ($fila && (int) $fila['pagado'] === 1) {
            $resultado['mes'] = ['pagado' => true, 'antes' => null, 'despues' => null];
        } elseif ($fila) {
            [$ini, $fin] = Calendario::limites($periodo);
            $d0 = $fila['desde'] ?: ($fila['clases'] === null ? $ini : (self::inicioDeClase($antes) ?: $ini));
            if ($d0 < $ini) $d0 = $ini;
            $h0 = $fila['hasta'] ?: $fin;
            if ($fecha <= $h0) {
                $durNueva = (int) ($despues['duracion_min'] ?? 0) ?: 60;
                $filas = HistorialClase::de($idIns, $db);
                // Los tramos de la clase (duración y día) con el cambio ya guardado (sin historial: un solo tramo)...
                $tramos = $filas ? HistorialClase::tramos($filas, 0.0, $durNueva, $diaNuevo)
                                 : [['desde' => HistorialClase::ORIGEN, 'hasta' => null, 'precio' => 0.0, 'duracion' => $durNueva, 'dia' => $diaNuevo]];
                // ...y como estaban antes de este cambio: lo dado desde la fecha seguía con lo de antes.
                $filasSin = array_values(array_filter($filas, fn($x) => $x['desde'] < $fecha));
                $tramosSin = $filasSin ? HistorialClase::tramos($filasSin, 0.0, $durVieja, $diaViejo)
                                       : [['desde' => HistorialClase::ORIGEN, 'hasta' => null, 'precio' => 0.0, 'duracion' => $durVieja, 'dia' => $diaViejo]];

                $suma = fn(array $partes) => array_sum(array_column($partes, 'clases'));
                $importe = function (array $partes) use ($maestro, $config): float {
                    $t = 0.0;
                    foreach ($partes as $x) $t += self::montoDeParte($maestro, (int) $x['duracion'], (int) $x['clases'], $config);
                    return round($t, 2);
                };
                // Con el cambio: lo de antes de la fecha con el día y la duración de antes, y desde la fecha con los nuevos
                // (con tope en las clases del mes): cada tramo se cuenta con SU día. Es la misma cuenta que hace el cobro
                // al alumno y la que repite la nómina al regenerarse, así que el importe no se mueve solo.
                $partes = HistorialClase::reparto($tramos, $periodo, $diaNuevo, $d0, $h0, $semanas);
                $nAntes = $suma(array_filter($partes, fn($x) => $x['desde'] < $fecha));
                $total = $suma($partes);
                $nDespues = $total - $nAntes;
                $clasesNuevas = $total >= $semanas ? null : $total;
                $monto = $importe($partes);

                // Sin el cambio: todo el renglón con el día y las duraciones de antes.
                $sinPartes = HistorialClase::reparto($tramosSin, $periodo, $diaViejo, $d0, $h0, $semanas);
                $totalSin = $suma($sinPartes);
                $clasesSin = $totalSin >= $semanas ? null : $totalSin;
                $montoSin = $importe($sinPartes);
                $resultado['mes'] = ['pagado' => false, 'antes' => $nAntes, 'despues' => $nDespues];

                if ($total === 0) {
                    self::borrarRenglones($db, "nd.id_detalle = ?", [$fila['id_detalle']],
                        'Se quitó por el cambio de día de la clase: ya no le toca ninguna clase este mes (tenía un ajuste manual)');
                } elseif ($forzar || abs($montoSin - $monto) >= 0.015 || $clasesNuevas !== $clasesSin) {
                    if ((int) $fila['ajuste_manual'] === 1) {
                        Bitacora::registrar($db, 'recalcular ajuste manual', 'nomina_detalle', (int) $fila['id_detalle'], 'monto',
                            $fila['monto'], number_format($monto, 2, '.', ''), $motivo);
                    }
                    $db->prepare("UPDATE nomina_detalle SET clases = ?, monto = ?, ajuste_manual = 0 WHERE id_detalle = ?")
                       ->execute([$clasesNuevas, $monto, $fila['id_detalle']]);
                }
            }
        }

        // ---------- los meses siguientes sin pagar: la duración nueva completa
        $f = $db->prepare("SELECT * FROM nomina_detalle WHERE id_inscripcion = ? AND id_maestro = ? AND periodo > ? AND pagado = 0");
        $f->execute([$idIns, $idM, $periodo]);
        foreach ($f->fetchAll() as $r) {
            $clases = $r['clases'] === null ? null : (int) $r['clases'];
            $cfg = ConfiguracionModel::paraPeriodo($config, $r['periodo']);
            $viejo = self::montoDeRenglon($maestro, $durVieja, $clases, $cfg);
            $nuevo = self::montoDelRenglon($maestro, $despues, $r['periodo'], $r['desde'], $r['hasta'], $clases, $cfg);
            if (abs($viejo - $nuevo) < 0.005) continue;
            if ((int) $r['ajuste_manual'] === 1) {
                Bitacora::registrar($db, 'recalcular ajuste manual', 'nomina_detalle', (int) $r['id_detalle'], 'monto',
                    $r['monto'], number_format($nuevo, 2, '.', ''), $motivo);
            }
            $db->prepare("UPDATE nomina_detalle SET monto = ?, ajuste_manual = 0 WHERE id_detalle = ?")
               ->execute([$nuevo, $r['id_detalle']]);
            $resultado['futuros']++;
        }
        return $resultado;
    }

    /**
     * Corrección manual de un renglón, para casos fuera de tarifa. Queda
     * marcado: ni generar la nómina ni cambiar la tarifa lo vuelven a
     * calcular. Y queda en la bitácora con el importe anterior.
     */
    public function ajustarMonto(int $idDetalle, float $monto, ?string $motivo = null, ?string $usuario = null): bool
    {
        if (!is_finite($monto) || $monto < 0) throw new Exception('El monto no puede ser negativo.');
        if ($monto > 100000) throw new Exception('Ese monto es desproporcionado. Revísalo.');
        $s = $this->db->prepare("SELECT monto, pagado FROM nomina_detalle WHERE id_detalle = ?");
        $s->execute([$idDetalle]);
        $antes = $s->fetch();
        if (!$antes) throw new Exception('Ese renglón de nómina no existe.');
        if ((int) $antes['pagado'] === 1) throw new Exception('Ese renglón ya está marcado como pagado; no se puede cambiar.');

        $this->db->prepare("UPDATE nomina_detalle SET monto = ?, ajuste_manual = 1 WHERE id_detalle = ?")
                 ->execute([round($monto, 2), $idDetalle]);
        Bitacora::registrar($this->db, 'ajustar nómina', 'nomina_detalle', $idDetalle, 'monto',
            $antes['monto'], number_format($monto, 2, '.', ''), $motivo, null, $usuario);
        return true;
    }

    // ---------------------------------------------------------------------
    // Correcciones a mano de la nómina de un mes (cualquiera: pasado, actual o futuro).
    // Cambian SOLO lo que se le paga al maestro; lo que debe el alumno no se toca.
    // ---------------------------------------------------------------------

    private function validarCorreccion(int $idMaestro, string $periodo, string $motivo): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo)) throw new Exception('El mes no es válido.');
        if (mb_strlen(trim($motivo)) < 4) throw new Exception('Escribe brevemente el motivo del cambio.');
        $m = $this->db->prepare("SELECT * FROM maestros WHERE id_maestro = ?");
        $m->execute([$idMaestro]);
        $maestro = $m->fetch();
        if (!$maestro) throw new Exception('Ese maestro no existe.');
        if ((string) $maestro['lleva_nomina'] === '0') throw new Exception('Ese maestro no lleva nómina.');
        return $maestro;
    }

    /**
     * Quita a un alumno de la nómina de un maestro en un mes: todos sus renglones de ese maestro.
     * Si el mes es el actual o uno futuro y el alumno sigue inscrito con ese maestro, el sistema lo
     * volvería a agregar solo al armar el mes; para que no regrese, su renglón se queda en $0 (con la
     * marca de ajuste a mano). En un mes pasado el renglón simplemente desaparece.
     *
     * @return array {quitados, en_cero, monto} renglones quitados del todo, renglones que quedaron en $0,
     *               y el dinero que dejó de pagarse
     */
    public function quitarAlumnoDelMesDetallado(int $idMaestro, string $periodo, int $idAlumno, string $motivo,
                                                ?string $usuario = null): array
    {
        $this->validarCorreccion($idMaestro, $periodo, $motivo);
        $motivo = trim($motivo);
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $s = $this->db->prepare(
                "SELECT nd.id_detalle, nd.monto, nd.pagado, nd.ajuste_manual,
                        (ins.estado <> 'baja' AND ins.id_maestro = nd.id_maestro) AS sigue_viva
                   FROM nomina_detalle nd
                   JOIN inscripciones ins ON ins.id_inscripcion = nd.id_inscripcion
                  WHERE nd.id_maestro = ? AND nd.periodo = ? AND nd.id_alumno = ?
                  FOR UPDATE"
            );
            $s->execute([$idMaestro, $periodo, $idAlumno]);
            $filas = $s->fetchAll();
            if (!$filas) throw new Exception('Ese alumno no está en la nómina de ese maestro en ese mes.');
            foreach ($filas as $f) {
                if ((int) $f['pagado'] === 1) throw new Exception(AjusteNominaModel::mensajePagada($this->db, $idMaestro, $periodo));
            }
            if (AjusteNominaModel::nominaPagada($this->db, $idMaestro, $periodo)) {
                throw new Exception(AjusteNominaModel::mensajePagada($this->db, $idMaestro, $periodo));
            }

            $borrar = []; $enCero = 0; $monto = 0.0;
            $regresaSola = $periodo >= date('Y-m');
            foreach ($filas as $f) {
                $id = (int) $f['id_detalle'];
                $monto += (float) $f['monto'];
                if ($regresaSola && (int) $f['sigue_viva'] === 1) {
                    // Un renglón que ya está en $0 por haberse quitado antes no se vuelve a anotar.
                    if ((float) $f['monto'] == 0.0 && (int) $f['ajuste_manual'] === 1) continue;
                    $this->db->prepare("UPDATE nomina_detalle SET monto = 0, ajuste_manual = 1 WHERE id_detalle = ?")->execute([$id]);
                    Bitacora::registrar($this->db, 'quitar de nómina', 'nomina_detalle', $id, "nómina $periodo",
                        $f['monto'], '0.00', $motivo, null, $usuario);
                    $enCero++;
                } else {
                    $borrar[] = $id;
                }
            }
            // El único camino para borrar renglones: deja bitácora de cada uno, con quién y por qué.
            $quitados = $borrar ? self::borrarRenglones($this->db, 'nd.id_detalle IN (' . implode(',', array_fill(0, count($borrar), '?')) . ')',
                $borrar, $motivo, $usuario, 'quitar de nómina', "nómina $periodo", true) : 0;
            if ($quitados + $enCero === 0) throw new Exception('Ese alumno ya estaba quitado de la nómina de ese mes.');
            if ($propia) $this->db->commit();
            return ['quitados' => $quitados, 'en_cero' => $enCero, 'monto' => round($monto, 2)];
        } catch (Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Quita a un alumno de la nómina del mes de un maestro. @return int renglones quitados (o puestos en $0) */
    public function quitarAlumnoDelMes(int $idMaestro, string $periodo, int $idAlumno, string $motivo, ?string $usuario = null): int
    {
        $r = $this->quitarAlumnoDelMesDetallado($idMaestro, $periodo, $idAlumno, $motivo, $usuario);
        return $r['quitados'] + $r['en_cero'];
    }

    /**
     * Las clases (inscripciones) de un alumno, de cualquier maestro y estado, para elegir a cuál
     * agregarlo en la nómina de un maestro. Con el importe sugerido con la tarifa de ESE maestro.
     */
    public function clasesDeAlumno(int $idAlumno, int $idMaestro, string $periodo): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo)) throw new Exception('El mes no es válido.');
        $a = $this->db->prepare("SELECT id_alumno, nombre, apellido, activo FROM alumnos WHERE id_alumno = ?");
        $a->execute([$idAlumno]);
        $alumno = $a->fetch();
        if (!$alumno) throw new Exception('Ese alumno no existe.');
        $m = $this->db->prepare("SELECT * FROM maestros WHERE id_maestro = ?");
        $m->execute([$idMaestro]);
        $maestro = $m->fetch();
        if (!$maestro) throw new Exception('Ese maestro no existe.');

        $semanas = ConfiguracionModel::semanasDe($periodo);
        $config = ConfiguracionModel::paraPeriodo((new ConfiguracionModel())->todo(), $periodo);
        $s = $this->db->prepare(
            "SELECT ins.id_inscripcion, ins.id_alumno, ins.id_maestro, ins.estado, ins.dia_semana, ins.hora_inicio,
                    ins.hora_fin, ins.duracion_min, ins.fecha_alta, ins.origen,
                    COALESCE(i.nombre, 'Por asignar') AS instrumento,
                    TRIM(CONCAT(mi.nombre, ' ', COALESCE(mi.apellido, ''))) AS maestro_actual,
                    (SELECT nd.monto FROM nomina_detalle nd WHERE nd.id_inscripcion = ins.id_inscripcion
                        AND nd.periodo = ? AND nd.id_maestro = ?) AS monto_en_nomina,
                    (SELECT COUNT(*) FROM nomina_detalle nd WHERE nd.id_inscripcion = ins.id_inscripcion
                        AND nd.periodo = ? AND nd.id_maestro = ?) AS ya_en_nomina
               FROM inscripciones ins
               JOIN maestros mi ON mi.id_maestro = ins.id_maestro
               LEFT JOIN instrumentos i ON i.id_instrumento = ins.id_instrumento
              WHERE ins.id_alumno = ?
              ORDER BY (ins.id_maestro = ?) DESC, (ins.estado = 'baja'), ins.dia_semana, ins.hora_inicio, ins.id_inscripcion"
        );
        $s->execute([$periodo, $idMaestro, $periodo, $idMaestro, $idAlumno, $idMaestro]);
        $clases = [];
        foreach ($s->fetchAll() as $c) {
            $dur = (int) ($c['duracion_min'] ?? 0) ?: 60;
            $c['duracion_min'] = $dur;
            $c['es_de_este_maestro'] = (int) $c['id_maestro'] === $idMaestro;
            $c['ya_en_nomina'] = (int) $c['ya_en_nomina'] > 0;
            $c['importe_mes'] = self::montoDelRenglon($maestro, $c, $periodo, null, null, null, $config);
            $c['valor_clase'] = self::valorDeSesion($maestro, $dur, $config);
            $clases[] = $c;
        }
        return ['alumno' => $alumno, 'semanas' => $semanas, 'clases' => $clases,
                'maestro' => ['id_maestro' => $idMaestro, 'modo_pago' => $maestro['modo_pago']]];
    }

    /**
     * Agrega a un alumno (una de sus clases, de cualquier maestro) a la nómina de un maestro en un mes.
     * El renglón queda con la marca de ajuste a mano: ni generar el mes ni cambiar tarifas lo recalculan.
     *
     * @param int|null   $clases clases que tomó ese mes (null o todas las del mes = mes completo)
     * @param float|null $monto  null = el importe sugerido con la tarifa de este maestro
     * @return int id del renglón nuevo
     */
    public function agregarAlumnoAlMes(int $idMaestro, string $periodo, int $idInscripcion, ?int $clases, ?float $monto,
                                       string $motivo, ?string $usuario = null): int
    {
        $maestro = $this->validarCorreccion($idMaestro, $periodo, $motivo);
        $motivo = trim($motivo);
        $semanas = ConfiguracionModel::semanasDe($periodo);
        if ($clases !== null) {
            if ($clases < 1 || $clases > $semanas) throw new Exception("Las clases de ese mes van de 1 a $semanas.");
            if ($clases >= $semanas) $clases = null;   // todas las del mes = mes completo
        }
        if ($monto !== null && (!is_finite($monto) || $monto < 0)) throw new Exception('El importe no puede ser negativo.');
        if ($monto !== null && $monto > 100000) throw new Exception('Ese importe es desproporcionado. Revísalo.');

        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $i = $this->db->prepare("SELECT * FROM inscripciones WHERE id_inscripcion = ?");
            $i->execute([$idInscripcion]);
            $ins = $i->fetch();
            if (!$ins) throw new Exception('Esa clase no existe.');

            AjusteNominaModel::exigirNoPagada($this->db, $idMaestro, $periodo);

            $ya = $this->db->prepare("SELECT 1 FROM nomina_detalle WHERE id_inscripcion = ? AND periodo = ? AND id_maestro = ?");
            $ya->execute([$idInscripcion, $periodo, $idMaestro]);
            if ($ya->fetchColumn()) throw new Exception('Ya está en la nómina de ese mes: cambia su importe o quítalo.');

            // En el mes en curso o uno futuro, armar la nómina limpia lo que no cuadra con la clase real:
            // quita los renglones de clases dadas de baja y pasa al maestro actual los de otro maestro.
            // Para que lo agregado no desaparezca ni cambie de dueño solo, esos casos se piden en un mes pasado.
            if ($periodo >= date('Y-m')) {
                if ($ins['estado'] === 'baja') {
                    throw new Exception('Esa clase está dada de baja: en el mes en curso o en uno futuro ya no se le paga a nadie. '
                        . 'Solo se puede agregar a la nómina de un mes pasado.');
                }
                if ((int) $ins['id_maestro'] !== $idMaestro) {
                    $o = $this->db->prepare("SELECT 1 FROM nomina_detalle WHERE id_inscripcion = ? AND periodo = ? AND id_maestro = ?");
                    $o->execute([$idInscripcion, $periodo, (int) $ins['id_maestro']]);
                    if (!$o->fetchColumn()) {
                        throw new Exception('Esa clase es de otro maestro y este mes todavía no está en su nómina. '
                            . 'Cámbiala de maestro desde Inscripciones, con la fecha desde la que da la clase este maestro.');
                    }
                }
            }

            if ($monto === null) {
                $config = ConfiguracionModel::paraPeriodo((new ConfiguracionModel())->todo(), $periodo);
                $monto = self::montoDelRenglon($maestro, $ins, $periodo, null, null, $clases, $config);
            }
            $monto = round($monto, 2);

            try {
                $this->db->prepare(
                    "INSERT INTO nomina_detalle (id_maestro, id_inscripcion, id_alumno, periodo, monto, clases, ajuste_manual)
                     VALUES (?, ?, ?, ?, ?, ?, 1)"
                )->execute([$idMaestro, $idInscripcion, $ins['id_alumno'], $periodo, $monto, $clases]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') throw new Exception('Ya está en la nómina de ese mes: cambia su importe o quítalo.');
                throw $e;
            }
            $id = (int) $this->db->lastInsertId();
            Bitacora::registrar($this->db, 'agregar a nómina', 'nomina_detalle', $id, "nómina $periodo", null,
                number_format($monto, 2, '.', ''), $motivo, null, $usuario);
            if ($propia) $this->db->commit();
            return $id;
        } catch (Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Un renglón de nómina tal como quedó (para avisar el importe) */
    public function renglon(int $idDetalle): ?array
    {
        $s = $this->db->prepare("SELECT * FROM nomina_detalle WHERE id_detalle = ?");
        $s->execute([$idDetalle]);
        return $s->fetch() ?: null;
    }

    /**
     * Marca la nómina de un maestro en un mes como pagada, o la regresa a
     * pendiente. Regresarla abre la puerta a cambiar un mes ya entregado, así
     * que pide el motivo. Las dos cosas quedan en la bitácora.
     */
    public function marcarPagado(int $idMaestro, string $periodo, bool $pagado = true, ?string $motivo = null,
                                 ?string $usuario = null): int
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo)) throw new Exception('El mes no es válido.');
        if (!$pagado && mb_strlen(trim((string) $motivo)) < 4) {
            throw new Exception('Escribe por qué la nómina vuelve a quedar pendiente: ya estaba marcada como pagada.');
        }
        $p = $pagado ? 1 : 0;
        $stmt = $this->db->prepare(
            "UPDATE nomina_detalle SET pagado = ?, fecha_pago = IF(? = 1, CURDATE(), NULL)
              WHERE id_maestro = ? AND periodo = ?"
        );
        $stmt->execute([$p, $p, $idMaestro, $periodo]);
        $n = $stmt->rowCount();

        // Las faltas y suplencias del mes se liquidan en el mismo sobre.
        $aj = $this->db->prepare("UPDATE nomina_ajustes SET pagado = ? WHERE id_maestro = ? AND periodo = ?");
        $aj->execute([$p, $idMaestro, $periodo]);
        if ($n + $aj->rowCount() > 0) {
            Bitacora::registrar($this->db, $pagado ? 'marcar nómina pagada' : 'regresar nómina a pendiente',
                'maestros', $idMaestro, "nómina $periodo", $pagado ? 'pendiente' : 'pagada', $pagado ? 'pagada' : 'pendiente',
                $motivo, null, $usuario);
        }
        return $n + $aj->rowCount();
    }

    /**
     * ÚNICO camino para quitar renglones de nómina. Un renglón con ajuste manual
     * tiene un importe que alguien puso a propósito: si desaparece, tiene que
     * quedar dicho cuánto era y por qué. Y lo ya pagado nunca se quita.
     *
     * Primero busca los renglones que cumplen la condición, escribe en la
     * bitácora financiera uno por cada ajuste manual (antes = su importe,
     * después = nada) y solo entonces borra exactamente esos renglones. Va en la
     * transacción de quien llama (o abre la suya).
     *
     * @param string $condicion trozo de WHERE sobre la tabla con alias "nd"
     *                          (para cruzar con otras tablas, EXISTS con subconsulta).
     *                          "pagado = 0" se agrega aquí siempre, aunque no lo pidan.
     * @param array  $params    valores de la condición
     * @param string $motivo    por qué se quitan (queda en la bitácora)
     * @param string|null $operacion con qué nombre queda en la bitácora ('quitar ajuste manual' si no se dice)
     * @param string|null $campo     campo de la bitácora ('monto' si no se dice)
     * @param bool    $registrarTodos también deja bitácora de los renglones sin ajuste manual (una quitada a
     *                               propósito por una persona, con su motivo, no debe pasar sin rastro)
     * @return int renglones quitados
     */
    public static function borrarRenglones(PDO $db, string $condicion, array $params, string $motivo,
                                           ?string $usuario = null, ?string $operacion = null, ?string $campo = null,
                                           bool $registrarTodos = false): int
    {
        $propia = !$db->inTransaction();
        if ($propia) $db->beginTransaction();
        try {
            $s = $db->prepare("SELECT nd.id_detalle, nd.monto, nd.ajuste_manual FROM nomina_detalle nd
                                WHERE ($condicion) AND nd.pagado = 0");
            $s->execute($params);
            $filas = $s->fetchAll();
            $quitados = 0;
            if ($filas) {
                foreach ($filas as $f) {
                    if ($registrarTodos || (int) $f['ajuste_manual'] === 1) {
                        Bitacora::registrar($db, $operacion ?? 'quitar ajuste manual', 'nomina_detalle', (int) $f['id_detalle'],
                            $campo ?? 'monto', $f['monto'], null, $motivo, null, $usuario);
                    }
                }
                $ids = array_map(fn($f) => (int) $f['id_detalle'], $filas);
                $marcas = implode(',', array_fill(0, count($ids), '?'));
                $del = $db->prepare("DELETE FROM nomina_detalle WHERE pagado = 0 AND id_detalle IN ($marcas)");
                $del->execute($ids);
                $quitados = $del->rowCount();
            }
            if ($propia) $db->commit();
            return $quitados;
        } catch (Throwable $e) {
            if ($propia && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /** Todo lo necesario para imprimir el recibo de un maestro */
    public function recibo(int $idMaestro, string $periodo): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo)) $periodo = date('Y-m');

        $m = $this->db->prepare("SELECT id_maestro, nombre, apellido, modo_pago, tarifa_por_alumno, tarifa_por_hora
                                   FROM maestros WHERE id_maestro = ?");
        $m->execute([$idMaestro]);
        $maestro = $m->fetch();
        if (!$maestro) throw new Exception('Ese maestro no existe.');

        $detalle = $this->detalleDeMaestro($idMaestro, $periodo);
        $ajustes = (new AjusteNominaModel())->deMaestro($idMaestro, $periodo);

        $base = round(array_sum(array_map(fn($d) => (float) $d['monto'], $detalle)), 2);
        $mov  = round(array_sum(array_map(fn($a) => (float) $a['monto'], $ajustes)), 2);

        $resumen = array_values(array_filter($this->resumenGeneral($periodo),
            fn($r) => (int) $r['id_maestro'] === $idMaestro));

        return [
            'maestro'  => $maestro,
            'periodo'  => $periodo,
            'semanas_mes' => ConfiguracionModel::semanasDe($periodo),
            'detalle'  => $detalle,
            'ajustes'  => $ajustes,
            'base'     => $base,
            'ajuste'   => $mov,
            'total'    => round(max(0, $base + $mov), 2),
            'pagado'   => $resumen[0]['pagado'] ?? false,
            'dias'     => $resumen[0]['dias'] ?? [],
            'dias_en_linea' => $resumen[0]['dias_en_linea'] ?? [],
            'emitido'  => date('Y-m-d H:i'),
        ];
    }

    /**
     * Usado dentro de la transacción del InscripcionService. Un maestro que no
     * lleva nómina (la dueña) nunca recibe renglones: antes se creaban y la
     * sincronización los borraba después.
     */
    public function insertar(PDO $db, array $d): int
    {
        $lleva = $db->prepare("SELECT lleva_nomina FROM maestros WHERE id_maestro = ?");
        $lleva->execute([(int) $d['id_maestro']]);
        if ((string) $lleva->fetchColumn() === '0') return 0;

        $stmt = $db->prepare(
            "INSERT INTO nomina_detalle (id_maestro, id_inscripcion, id_alumno, periodo, monto, clases, desde, hasta)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE monto = VALUES(monto), clases = VALUES(clases),
                                     desde = VALUES(desde), hasta = VALUES(hasta)"
        );
        // clases null = mes completo; con número, solo las clases de su tramo.
        $stmt->execute([
            $d['id_maestro'], $d['id_inscripcion'], $d['id_alumno'],
            $d['periodo'] ?? date('Y-m'), $d['monto'],
            $d['clases'] ?? null, $d['desde'] ?? null, $d['hasta'] ?? null,
        ]);
        return (int) $db->lastInsertId();
    }

    /**
     * Al dar de baja, al maestro se le quedan las clases que ya dio este mes.
     *
     * Quien deja la escuela el día 21 con clase en lunes ya tomó las de los
     * lunes anteriores: esas se le pagan al maestro y el renglón se cierra con
     * su fecha. Los meses siguientes se borran enteros, y los meses pasados no
     * se tocan, como tampoco el tramo de un maestro anterior a un cambio.
     *
     * @param string|null $fechaBaja último día que tomó clase; hoy por omisión
     * @return int clases que le quedan al maestro en el mes de la baja
     */
    public function eliminarPorInscripcion(PDO $db, int $idInscripcion, ?string $fechaBaja = null,
                                           ?int $clasesTomadas = null): int
    {
        $fechaBaja = $fechaBaja && Calendario::fechaValida($fechaBaja) ? $fechaBaja : date('Y-m-d');
        $periodo = substr($fechaBaja, 0, 7);

        // Los meses que ya no va a tomar se borran enteros.
        self::borrarRenglones($db, "nd.id_inscripcion = ? AND nd.periodo > ?", [$idInscripcion, $periodo],
            'Se quitó por la baja: meses que ya no va a tomar (tenía un ajuste manual)');

        $s = $db->prepare(
            "SELECT nd.id_detalle, nd.clases, nd.desde, nd.monto, nd.ajuste_manual, ins.id_inscripcion, ins.dia_semana, ins.duracion_min,
                    ins.costo_mensual, ins.fecha_alta, ins.origen, m.modo_pago, m.tarifa_por_hora, m.tarifa_por_alumno
               FROM nomina_detalle nd
               JOIN inscripciones ins ON ins.id_inscripcion = nd.id_inscripcion
               JOIN maestros m ON m.id_maestro = nd.id_maestro
              WHERE nd.id_inscripcion = ? AND nd.periodo = ? AND nd.pagado = 0 AND nd.hasta IS NULL"
        );
        $s->execute([$idInscripcion, $periodo]);
        $fila = $s->fetch();
        if (!$fila) return 0;

        $config = ConfiguracionModel::paraPeriodo((new ConfiguracionModel())->todo(), $periodo);
        $desde = $fila['desde'] ?: self::inicioDeClase($fila);
        $t = Calendario::tramo($periodo, $fila['dia_semana'], $desde, $fechaBaja,
                               (int) $config['semanas_por_mes']);
        // Una clase sin día no se puede contar: manda lo que dijo quien dio la baja.
        if ($clasesTomadas !== null) {
            $t['clases'] = $clasesTomadas >= (int) $config['semanas_por_mes'] ? null : $clasesTomadas;
        }
        $clases = $t['clases'] ?? (int) $config['semanas_por_mes'];

        if ($clases === 0) {
            // Se fue antes de tomar ninguna clase del mes: no hay qué pagar. Si el renglón
            // tenía un ajuste manual, no desaparece en silencio: queda dicho cuánto era.
            self::borrarRenglones($db, "nd.id_detalle = ?", [$fila['id_detalle']],
                'Se quitó por la baja: no tomó clases ese mes (tenía un ajuste manual)');
            return 0;
        }

        // Se sigue recalculando (dio menos clases: no recalcular pagaría de más), pero un
        // ajuste manual nunca cambia en silencio: el importe deja de ser el manual, se
        // quita la marca y queda en la bitácora cuánto era.
        $monto = self::montoDelRenglon($fila, $fila, $periodo, $t['desde'] ?: $desde, $fechaBaja, $t['clases'], $config);
        // Si las clases se dijeron a mano y la clase cambió de duración a mitad de mes, cada clase va con la duración
        // de su tramo (como al corregir una baja), no con la de hoy.
        if ($clasesTomadas !== null) {
            $partes = HistorialClase::clasesTomadas($fila, $periodo, $desde, $fechaBaja, $clasesTomadas, (int) $config['semanas_por_mes'], $db);
            if ($partes !== null) $monto = self::montoPorTramos($fila, $partes, $config);
        }
        $db->prepare("UPDATE nomina_detalle SET clases = ?, desde = ?, hasta = ?, monto = ?, ajuste_manual = 0 WHERE id_detalle = ?")
           ->execute([$clases, $t['desde'] ?: $desde, $fechaBaja, $monto, $fila['id_detalle']]);
        if ((int) $fila['ajuste_manual'] === 1) {
            Bitacora::registrar($db, 'recalcular ajuste manual', 'nomina_detalle', (int) $fila['id_detalle'], 'monto',
                $fila['monto'], number_format($monto, 2, '.', ''), 'Se recalculó por la baja: tenía un ajuste manual');
        }
        return $clases;
    }
}

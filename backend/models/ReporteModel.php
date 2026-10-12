<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/ConfiguracionModel.php';
require_once __DIR__ . '/CuentaModel.php';
require_once __DIR__ . '/HistorialClaseModel.php';

/**
 * Reportes de la escuela. La idea es responder preguntas concretas de
 * dirección: cuánto entró, cuánto salió, quién debe, qué materias sostienen
 * la escuela y cuántas clases muestra se convierten en alumnos.
 */
class ReporteModel
{
    private PDO $db;

    public function __construct() { $this->db = Database::conexion(); }

    /** Ingresos y nómina mes a mes, con el resultado de cada mes */
    public function ingresosMensuales(int $meses = 12): array
    {
        $stmt = $this->db->prepare(
            "SELECT m.mes,
                    COALESCE(i.num_pagos, 0)      AS num_pagos,
                    COALESCE(i.colegiaturas, 0)   AS colegiaturas,
                    COALESCE(i.inscripciones, 0)  AS inscripciones,
                    COALESCE(i.recargos, 0)       AS recargos,
                    COALESCE(i.muestras, 0)       AS muestras,
                    COALESCE(i.total_ingresos, 0) AS total_ingresos,
                    COALESCE(n.nomina, 0)         AS nomina,
                    COALESCE(i.total_ingresos, 0) - COALESCE(n.nomina, 0) AS resultado
               FROM (
                    SELECT DATE_FORMAT(fecha_pago, '%Y-%m') AS mes FROM pagos WHERE origen = 'captura'
                    UNION
                    SELECT periodo FROM nomina_detalle
                    UNION
                    SELECT periodo FROM nomina_ajustes
               ) m
               LEFT JOIN (
                    SELECT DATE_FORMAT(fecha_pago, '%Y-%m') AS mes,
                           -- Una anulación no es un pago más: resta en su mes, pero no se cuenta.
                           COUNT(CASE WHEN anula_a IS NULL THEN 1 END) AS num_pagos,
                           SUM(CASE WHEN concepto IN ('clase_muestra', 'inscripcion') THEN 0 ELSE monto END) AS colegiaturas,
                           SUM(CASE WHEN concepto = 'inscripcion' THEN monto ELSE 0 END) AS inscripciones,
                           SUM(recargo) AS recargos,
                           SUM(CASE WHEN concepto = 'clase_muestra' THEN monto ELSE 0 END) AS muestras,
                           SUM(monto + recargo) AS total_ingresos
                      FROM pagos WHERE origen = 'captura' GROUP BY 1
               ) i ON i.mes = m.mes
               -- La nómina del mes: sus renglones más faltas, suplencias, bonos y clases
               -- muestra, solo de quien lleva nómina. Es lo que de verdad se paga.
               LEFT JOIN (
                    SELECT mes, SUM(monto) AS nomina FROM (
                        SELECT nd.periodo AS mes, nd.monto FROM nomina_detalle nd
                          JOIN maestros mm ON mm.id_maestro = nd.id_maestro AND mm.lleva_nomina = 1
                        UNION ALL
                        SELECT aj.periodo, aj.monto FROM nomina_ajustes aj
                          JOIN maestros mm ON mm.id_maestro = aj.id_maestro AND mm.lleva_nomina = 1
                    ) x GROUP BY mes
               ) n ON n.mes = m.mes
              ORDER BY m.mes DESC
              LIMIT ?"
        );
        $stmt->bindValue(1, $meses, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Qué materias sostienen la escuela, por colegiaturas comprometidas */
    public function porMateria(): array
    {
        $filas = $this->db->query(
            "SELECT COALESCE(i.nombre, 'Sin materia asignada') AS instrumento,
                    COUNT(DISTINCT ins.id_alumno) AS alumnos,
                    COUNT(*) AS clases,
                    SUM(ins.costo_mensual) AS ingreso_mensual,
                    ROUND(AVG(ins.duracion_min)) AS duracion_promedio
               FROM inscripciones ins
               LEFT JOIN instrumentos i ON i.id_instrumento = ins.id_instrumento
              WHERE ins.estado <> 'baja'
              GROUP BY COALESCE(i.nombre, 'Sin materia asignada')
              ORDER BY ingreso_mensual DESC"
        )->fetchAll();
        // El precio de cada clase es el que rige hoy: un cambio programado para después todavía no cuenta.
        $difs = HistorialClase::diferenciasDelMes(date('Y-m'), $this->db);
        if ($difs) {
            $nombres = $this->db->query("SELECT id_instrumento, nombre FROM instrumentos")->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($difs as $d) {
                $n = $d['id_instrumento'] !== null && isset($nombres[$d['id_instrumento']]) ? $nombres[$d['id_instrumento']] : 'Sin materia asignada';
                foreach ($filas as &$f) if ($f['instrumento'] === $n) { $f['ingreso_mensual'] = round((float) $f['ingreso_mensual'] + $d['diferencia'], 2); break; }
                unset($f);
            }
            usort($filas, fn($a, $b) => (float) $b['ingreso_mensual'] <=> (float) $a['ingreso_mensual']);
        }
        return $filas;
    }

    /** Cada maestro: cuánto genera para la escuela y cuánto cuesta */
    public function porMaestro(?string $periodo = null): array
    {
        $periodo = $periodo ?: date('Y-m');
        $stmt = $this->db->prepare(
            "SELECT m.id_maestro, m.nombre, m.apellido, m.modo_pago, m.lleva_nomina,
                    COUNT(DISTINCT ins.id_alumno) AS alumnos,
                    COUNT(ins.id_inscripcion) AS clases,
                    COALESCE(SUM(ins.costo_mensual), 0) AS genera,
                    COALESCE((SELECT SUM(monto) FROM nomina_detalle nd
                               WHERE nd.id_maestro = m.id_maestro AND nd.periodo = ?), 0)
                    + COALESCE((SELECT SUM(monto) FROM nomina_ajustes aj
                               WHERE aj.id_maestro = m.id_maestro AND aj.periodo = ?), 0) AS cuesta
               FROM maestros m
               LEFT JOIN inscripciones ins
                      ON ins.id_maestro = m.id_maestro AND ins.estado <> 'baja'
              -- También quien ya se fue, si ese mes todavía cuesta.
              WHERE m.activo = 1
                 OR EXISTS (SELECT 1 FROM nomina_detalle x WHERE x.id_maestro = m.id_maestro AND x.periodo = ?)
              GROUP BY m.id_maestro, m.nombre, m.apellido, m.modo_pago, m.lleva_nomina
              HAVING alumnos > 0 OR cuesta > 0
              ORDER BY genera DESC"
        );
        $stmt->execute([$periodo, $periodo, $periodo]);
        $filas = $stmt->fetchAll();
        // Lo que genera cada maestro: el precio que rige en ese mes (un cambio programado para después no cuenta todavía).
        $difs = HistorialClase::diferenciasDelMes($periodo, $this->db);
        if ($difs) {
            foreach ($difs as $d) {
                foreach ($filas as &$f) if ((int) $f['id_maestro'] === $d['id_maestro']) { $f['genera'] = round((float) $f['genera'] + $d['diferencia'], 2); break; }
                unset($f);
            }
            usort($filas, fn($a, $b) => (float) $b['genera'] <=> (float) $a['genera']);
        }
        return $filas;
    }

    /** Ocupación de la escuela: horas dadas por día y por salón */
    public function ocupacion(): array
    {
        $porDia = $this->db->query(
            "SELECT dia_semana,
                    COUNT(*) AS clases,
                    ROUND(SUM(TIMESTAMPDIFF(MINUTE, hora_inicio, hora_fin)) / 60, 1) AS horas
               FROM horario_ocupado
              GROUP BY dia_semana
              ORDER BY FIELD(dia_semana,'Lunes','Martes','Miercoles','Jueves','Viernes','Sabado')"
        )->fetchAll();

        $porSalon = $this->db->query(
            "SELECT s.nombre AS salon, COUNT(ho.id_ocupado) AS clases,
                    ROUND(COALESCE(SUM(TIMESTAMPDIFF(MINUTE, ho.hora_inicio, ho.hora_fin)), 0) / 60, 1) AS horas
               FROM salones s
               LEFT JOIN horario_ocupado ho ON ho.id_salon = s.id_salon
              WHERE s.activo = 1
              GROUP BY s.id_salon, s.nombre
              ORDER BY horas DESC"
        )->fetchAll();

        // Las clases en línea no ocupan salón: van en su propio renglón para
        // que no desaparezcan del reporte ni se le carguen a ningún salón.
        $enLinea = $this->db->query(
            "SELECT 'En línea' AS salon, COUNT(*) AS clases,
                    ROUND(COALESCE(SUM(TIMESTAMPDIFF(MINUTE, hora_inicio, hora_fin)), 0) / 60, 1) AS horas
               FROM horario_ocupado WHERE id_salon IS NULL"
        )->fetch();
        if ((int) $enLinea['clases'] > 0) $porSalon[] = $enLinea;

        return ['por_dia' => $porDia, 'por_salon' => $porSalon];
    }

    /**
     * Clases muestra dadas y cuántas terminaron en inscripción.
     *
     * Una clase muestra anulada nunca se cobró, y su contramovimiento no es una
     * clase: ninguna de las dos cuenta (ni en el conteo ni en el ingreso).
     */
    public function clasesMuestra(int $meses = 6): array
    {
        $stmt = $this->db->prepare(
            "SELECT DATE_FORMAT(fecha_pago, '%Y-%m') AS mes,
                    COUNT(*) AS muestras,
                    SUM(monto) AS ingreso
               FROM pagos
              WHERE concepto = 'clase_muestra' AND anulado = 0 AND anula_a IS NULL
              GROUP BY 1 ORDER BY 1 DESC LIMIT ?"
        );
        $stmt->bindValue(1, $meses, PDO::PARAM_INT);
        $stmt->execute();
        $porMes = $stmt->fetchAll();

        $convertidas = (int) $this->db->query(
            "SELECT COUNT(*) FROM pagos p
              WHERE p.concepto = 'clase_muestra' AND p.anulado = 0 AND p.anula_a IS NULL
                AND EXISTS (SELECT 1 FROM alumnos a
                             JOIN inscripciones ins ON ins.id_alumno = a.id_alumno
                            WHERE CONCAT(a.nombre,' ',a.apellido) = p.nombre_prospecto
                              AND ins.estado <> 'baja')"
        )->fetchColumn();

        $total = (int) $this->db->query(
            "SELECT COUNT(*) FROM pagos WHERE concepto = 'clase_muestra' AND anulado = 0 AND anula_a IS NULL"
        )->fetchColumn();

        return [
            'por_mes'      => $porMes,
            'total'        => $total,
            'convertidas'  => $convertidas,
            'conversion'   => $total > 0 ? round($convertidas * 100 / $total) : 0,
        ];
    }

    /** Quién debe, por saldo real: incluye abonos parciales y meses atrasados */
    public function adeudos(?string $periodo = null): array
    {
        $periodo = $periodo ?: date('Y-m');
        $recargo = (new ConfiguracionModel())->recargoDe($periodo);
        $lista = (new CuentaModel())->deudores();

        foreach ($lista as &$d) {
            $d['total'] = round($d['adeudo'] + $d['recargo'], 2);
        }
        unset($d);

        return [
            'periodo'   => $periodo,
            'vence'     => $recargo['vence'],
            'alumnos'   => $lista,
            'cuantos'   => count($lista),
            'atrasados' => count(array_filter($lista, fn($d) => $d['meses_debe'] > 1)),
            'monto'     => round(array_sum(array_column($lista, 'total')), 2),
        ];
    }

    /** Altas y bajas de los últimos meses */
    public function movimiento(int $meses = 6): array
    {
        $stmt = $this->db->prepare(
            "SELECT mes,
                    SUM(altas) AS altas,
                    SUM(bajas) AS bajas
               FROM (
                    SELECT DATE_FORMAT(fecha_alta, '%Y-%m') AS mes, COUNT(*) AS altas, 0 AS bajas
                      FROM inscripciones GROUP BY 1
                    UNION ALL
                    SELECT DATE_FORMAT(fecha_baja, '%Y-%m') AS mes, 0 AS altas, COUNT(*) AS bajas
                      FROM inscripciones WHERE fecha_baja IS NOT NULL GROUP BY 1
               ) x
              WHERE mes IS NOT NULL
              GROUP BY mes ORDER BY mes DESC LIMIT ?"
        );
        $stmt->bindValue(1, $meses, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Ingresos por instrumento en un mes, a partir de los pagos cobrados */
    public function ingresosPorInstrumento(string $mes): array
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(i.nombre, 'Sin materia') AS instrumento,
                    COUNT(*) AS num_pagos, SUM(p.monto + p.recargo) AS total
               FROM pagos p
               LEFT JOIN inscripciones ins ON ins.id_inscripcion = p.id_inscripcion
               LEFT JOIN instrumentos i ON i.id_instrumento = ins.id_instrumento
              WHERE DATE_FORMAT(p.fecha_pago, '%Y-%m') = ? AND p.origen = 'captura'
              GROUP BY COALESCE(i.nombre, 'Sin materia')
              ORDER BY total DESC"
        );
        $stmt->execute([$mes]);
        return $stmt->fetchAll();
    }

    /** Resumen del mes en curso */
    public function balanceMesActual(): array
    {
        $mes = date('Y-m');

        $ingresos = $this->db->prepare(
            "SELECT COALESCE(SUM(monto + recargo), 0) FROM pagos
              WHERE DATE_FORMAT(fecha_pago,'%Y-%m') = ? AND origen = 'captura'"
        );
        $ingresos->execute([$mes]);

        // Lo que cuesta la nómina del mes: renglones más ajustes, de quien lleva nómina.
        $nomina = $this->db->prepare("SELECT COALESCE(SUM(x.monto), 0) FROM (
                SELECT nd.monto FROM nomina_detalle nd JOIN maestros mm ON mm.id_maestro = nd.id_maestro
                 WHERE nd.periodo = ? AND mm.lleva_nomina = 1
                UNION ALL
                SELECT aj.monto FROM nomina_ajustes aj JOIN maestros mm ON mm.id_maestro = aj.id_maestro
                 WHERE aj.periodo = ? AND mm.lleva_nomina = 1) x");
        $nomina->execute([$mes, $mes]);

        $comprometido = (float) $this->db->query(
            "SELECT COALESCE(SUM(costo_mensual), 0) FROM inscripciones WHERE estado <> 'baja'"
        )->fetchColumn();
        // Lo esperado del mes es lo que rige en ese mes (no un cambio de precio programado para después).
        foreach (HistorialClase::diferenciasDelMes($mes, $this->db) as $d) $comprometido += $d['diferencia'];

        $entra = (float) $ingresos->fetchColumn();
        $sale  = (float) $nomina->fetchColumn();

        return [
            'mes'                    => $mes,
            'ingresos'               => $entra,
            'nomina_del_mes'         => $sale,
            'balance_estimado'       => $entra - $sale,
            'colegiaturas_esperadas' => $comprometido,
            'cobrado_del_esperado'   => $comprometido > 0 ? round($entra * 100 / $comprometido) : 0,
        ];
    }
}

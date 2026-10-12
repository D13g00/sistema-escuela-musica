<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/ConfiguracionModel.php';
require_once __DIR__ . '/NominaModel.php';
require_once __DIR__ . '/CuentaModel.php';
require_once __DIR__ . '/HistorialClaseModel.php';

/** Todo lo que necesita la pantalla de Inicio, en una sola consulta por bloque. */
class DashboardModel
{
    private PDO $db;
    private const DIAS = ['Domingo','Lunes','Martes','Miercoles','Jueves','Viernes','Sabado'];

    public function __construct() { $this->db = Database::conexion(); }

    public function resumen(): array
    {
        $hoy = self::DIAS[(int) date('w')];
        $periodo = date('Y-m');

        $q = fn(string $sql) => $this->db->query($sql)->fetchColumn();

        $clasesHoy = $this->db->prepare(
            "SELECT ins.hora_inicio, ins.hora_fin,
                    CASE WHEN ins.modalidad = 'online' THEN 'En línea' ELSE s.nombre END AS salon,
                    ins.modalidad, ins.comparte_con,
                    a.nombre AS alumno_nombre, a.apellido AS alumno_apellido,
                    m.nombre AS maestro_nombre, m.apellido AS maestro_apellido,
                    COALESCE(i.nombre,'Por asignar') AS instrumento,
                    ins.id_inscripcion, a.id_alumno,
                    -- Al corriente si lo abonado del mes cubre su colegiatura, no con
                    -- tener cualquier pago: $300 de $900 no es estar al corriente.
                    (SELECT COALESCE(SUM(p.monto), 0) FROM pagos p
                      WHERE p.id_alumno = a.id_alumno AND p.periodo = ? AND p.concepto = 'colegiatura') AS abonado_mes,
                    (SELECT COALESCE(SUM(i2.costo_mensual), 0) FROM inscripciones i2
                      WHERE i2.id_alumno = a.id_alumno AND i2.estado <> 'baja') AS colegiatura
             FROM inscripciones ins
             JOIN alumnos a        ON a.id_alumno = ins.id_alumno
             JOIN maestros m       ON m.id_maestro = ins.id_maestro
             LEFT JOIN salones s   ON s.id_salon = ins.id_salon
             LEFT JOIN instrumentos i ON i.id_instrumento = ins.id_instrumento
             WHERE ins.estado = 'activa' AND ins.dia_semana = ?
             ORDER BY ins.hora_inicio, salon"
        );
        $clasesHoy->execute([$periodo, $hoy]);

        // Quién debe: por saldo real, no por "tiene o no un pago". Alguien que
        // abonó $300 de $900 sigue apareciendo, con lo que le falta.
        $listaDeudores = (new CuentaModel())->deudores();

        // Lo que entró este mes: cobrado este mes, sin contar lo importado del
        // Excel (eso es historia, no dinero que llegó hoy).
        $ingresos = $this->db->prepare(
            "SELECT COALESCE(SUM(monto + recargo), 0) FROM pagos
              WHERE DATE_FORMAT(fecha_pago, '%Y-%m') = ? AND origen = 'captura'"
        );
        $ingresos->execute([$periodo]);

        // Lo que cuesta la nómina del mes: renglones más ajustes, de quien lleva nómina.
        $nomina = $this->db->prepare("SELECT COALESCE(SUM(x.monto), 0) FROM (
                SELECT nd.monto FROM nomina_detalle nd JOIN maestros mm ON mm.id_maestro = nd.id_maestro
                 WHERE nd.periodo = ? AND mm.lleva_nomina = 1
                UNION ALL
                SELECT aj.monto FROM nomina_ajustes aj JOIN maestros mm ON mm.id_maestro = aj.id_maestro
                 WHERE aj.periodo = ? AND mm.lleva_nomina = 1) x");
        $nomina->execute([$periodo, $periodo]);

        $recargo = (new ConfiguracionModel())->recargoDe($periodo);

        // Nómina del mes: quién ya debería haber cobrado y no ha cobrado.
        $resumenNomina = (new NominaModel())->resumenGeneral($periodo);
        $porPagar = array_values(array_filter($resumenNomina, fn($m) =>
            !$m['pagado'] && (float) $m['total_a_pagar'] > 0
            && in_array($m['urgencia'], ['vencido', 'hoy', 'proximo'], true)
        ));

        return [
            'dia'              => $hoy,
            'fecha'            => date('Y-m-d'),
            'periodo'          => $periodo,
            'recargo'          => $recargo,
            'alumnos_activos'  => (int) $q("SELECT COUNT(*) FROM alumnos WHERE activo = 1"),
            'maestros_activos' => (int) $q("SELECT COUNT(*) FROM maestros WHERE activo = 1"),
            'inscripciones'    => (int) $q("SELECT COUNT(*) FROM inscripciones WHERE estado = 'activa'"),
            'pendientes'       => (int) $q("SELECT COUNT(*) FROM inscripciones WHERE estado = 'pendiente'"),
            // Facturas del mes que faltan: se recuerdan antes de que se acabe el mes.
            'facturas'         => (function () {
                require_once __DIR__ . '/FacturaModel.php';
                return (new FacturaModel())->recordatorio();
            })(),
            'clases_hoy'       => (function () use ($clasesHoy, $periodo) {
                // Su colegiatura es la que rige hoy: un cambio de precio programado para después todavía no cuenta.
                $filas = $clasesHoy->fetchAll();
                $difs = HistorialClase::diferenciasDelMes($periodo, $this->db);
                if ($difs) {
                    $porAlumno = [];
                    foreach ($difs as $d) $porAlumno[$d['id_alumno']] = ($porAlumno[$d['id_alumno']] ?? 0) + $d['diferencia'];
                    foreach ($filas as &$f) {
                        if (isset($porAlumno[(int) $f['id_alumno']])) $f['colegiatura'] = round((float) $f['colegiatura'] + $porAlumno[(int) $f['id_alumno']], 2);
                    }
                    unset($f);
                }
                return $filas;
            })(),
            'deudores'         => array_slice($listaDeudores, 0, 6),
            'deudores_total'   => count($listaDeudores),
            'deudores_monto'   => round(array_sum(array_column($listaDeudores, 'adeudo')), 2),
            'atrasados'        => count(array_filter($listaDeudores, fn($d) => $d['meses_debe'] > 1)),
            'ingresos_mes'     => (float) $ingresos->fetchColumn(),
            'nomina_mes'       => (float) $nomina->fetchColumn(),
            'nomina_por_pagar' => array_slice($porPagar, 0, 6),
            'nomina_pendientes'=> count($porPagar),
            'nomina_urgentes'  => count(array_filter($porPagar, fn($m) => $m['urgencia'] !== 'proximo')),
            'nomina_monto'     => round(array_sum(array_column($porPagar, 'total_a_pagar')), 2),
            'notas'            => $this->db->query(
                "SELECT * FROM bitacora_notas WHERE resuelto = 0 ORDER BY fecha_creacion DESC LIMIT 4"
            )->fetchAll(),
        ];
    }
}

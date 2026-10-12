<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/NominaModel.php';
require_once __DIR__ . '/HorarioModel.php';
require_once __DIR__ . '/AjusteNominaModel.php';
require_once __DIR__ . '/../lib/Validar.php';
require_once __DIR__ . '/../lib/Bitacora.php';

class MaestroModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::conexion();
    }

    public function listar(): array
    {
        $sql = "SELECT m.*,
                       GROUP_CONCAT(i.nombre ORDER BY i.nombre SEPARATOR ', ') AS instrumentos,
                       GROUP_CONCAT(i.id_instrumento ORDER BY i.nombre) AS ids_instrumentos,
                       (SELECT COUNT(DISTINCT id_alumno) FROM inscripciones WHERE id_maestro = m.id_maestro
                         AND estado <> 'baja') AS alumnos,
                       (SELECT COUNT(*) FROM inscripciones WHERE id_maestro = m.id_maestro
                         AND estado <> 'baja') AS clases
                FROM maestros m
                LEFT JOIN maestro_instrumento mi ON mi.id_maestro = m.id_maestro
                LEFT JOIN instrumentos i ON i.id_instrumento = mi.id_instrumento
                GROUP BY m.id_maestro
                ORDER BY m.apellido, m.nombre";
        return $this->db->query($sql)->fetchAll();
    }

    public function obtener(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM maestros WHERE id_maestro = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function crear(array $datos): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO maestros (nombre, apellido, telefono, email, tarifa_por_alumno, tarifa_por_hora,
                                   modo_pago, bono_puntualidad, lleva_nomina)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $datos['nombre'], $datos['apellido'], $datos['telefono'] ?? null,
            $datos['email'] ?? null,
            Validar::dinero($datos['tarifa_por_alumno'] ?? 0, 'La tarifa por alumno', 100000),
            Validar::dinero($datos['tarifa_por_hora'] ?? 0, 'La tarifa por hora', 100000),
            in_array($datos['modo_pago'] ?? '', ['por_alumno','por_hora'], true) ? $datos['modo_pago'] : 'por_alumno',
            self::bonoValido($datos['bono_puntualidad'] ?? 0),
            self::llevaNomina($datos)
        ]);
        $idMaestro = (int) $this->db->lastInsertId();

        if (!empty($datos['instrumentos']) && is_array($datos['instrumentos'])) {
            $this->asignarInstrumentos($idMaestro, $datos['instrumentos']);
        }
        return $idMaestro;
    }

    public function actualizar(int $id, array $datos): bool
    {
        $antes = $this->obtener($id);
        if (!$antes) throw new Exception('Ese maestro no existe.');
        $nuevo = [
            'tarifa_por_alumno' => Validar::dinero($datos['tarifa_por_alumno'] ?? 0, 'La tarifa por alumno', 100000),
            'tarifa_por_hora'   => Validar::dinero($datos['tarifa_por_hora'] ?? 0, 'La tarifa por hora', 100000),
            'modo_pago'         => in_array($datos['modo_pago'] ?? '', ['por_alumno','por_hora'], true) ? $datos['modo_pago'] : 'por_alumno',
            'bono_puntualidad'  => self::bonoValido($datos['bono_puntualidad'] ?? 0),
            'lleva_nomina'      => self::llevaNomina($datos),
        ];
        // Lo que decide cuánto se le paga: si cambia, queda en la bitácora con el valor de antes.
        $cambiosPaga = [];
        foreach ($nuevo as $campo => $valor) {
            if ((string) $antes[$campo] !== (string) $valor
                && !(is_numeric($antes[$campo]) && is_numeric($valor) && abs((float) $antes[$campo] - (float) $valor) < 0.005)) {
                $cambiosPaga[$campo] = [$antes[$campo], $valor];
            }
        }

        // Todo o nada: la ficha, la bitácora, el bono y la nómina del mes.
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "UPDATE maestros SET nombre = ?, apellido = ?, telefono = ?, email = ?,
                        tarifa_por_alumno = ?, tarifa_por_hora = ?, modo_pago = ?, bono_puntualidad = ?,
                        lleva_nomina = ?
                  WHERE id_maestro = ?"
            );
            $stmt->execute([
                $datos['nombre'], $datos['apellido'], $datos['telefono'] ?? null, $datos['email'] ?? null,
                $nuevo['tarifa_por_alumno'], $nuevo['tarifa_por_hora'], $nuevo['modo_pago'],
                $nuevo['bono_puntualidad'], $nuevo['lleva_nomina'], $id
            ]);
            foreach ($cambiosPaga as $campo => [$a, $b]) {
                Bitacora::registrar($this->db, 'cambiar paga del maestro', 'maestros', $id, $campo, $a, $b);
            }

            // El bono del mes en curso sigue a la ficha: subirlo o quitarlo se ve
            // en la nómina sin tener que volver a generarla.
            (new AjusteNominaModel())->sincronizarBono($id, date('Y-m'));

            // Si cambió cómo se le paga, la nómina sin pagar del mes en curso y los
            // siguientes lo sigue (sin tocar los renglones ajustados a mano). Si
            // solo cambió su teléfono o su nombre, la nómina no se toca.
            $this->renglonesRecalculados = array_intersect_key($cambiosPaga, array_flip(['tarifa_por_alumno', 'tarifa_por_hora', 'modo_pago']))
                ? $this->recalcularNomina($id) : 0;

            if (isset($datos['instrumentos']) && is_array($datos['instrumentos'])) {
                // Reemplaza por completo la lista de instrumentos que imparte
                $this->db->prepare("DELETE FROM maestro_instrumento WHERE id_maestro = ?")->execute([$id]);
                $this->asignarInstrumentos($id, $datos['instrumentos']);
            }
            if ($propia) $this->db->commit();
        } catch (Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return true;
    }

    /** Sin el campo, lleva nómina: es lo normal para cualquier maestro */
    private static function llevaNomina(array $datos): int
    {
        if (!array_key_exists('lleva_nomina', $datos)) return 1;
        return in_array($datos['lleva_nomina'], [0, '0', false, 'false', 'off', ''], true) ? 0 : 1;
    }

    /** El bono es una cantidad al mes; ni negativa ni absurda */
    private static function bonoValido($valor): float
    {
        $bono = (float) $valor;
        if (!is_finite($bono) || $bono < 0) throw new Exception('El bono no puede ser negativo.');
        if ($bono > 100000) throw new Exception('Ese bono es desproporcionado. Revísalo.');
        return round($bono, 2);
    }

    public function asignarInstrumentos(int $idMaestro, array $idsInstrumentos): void
    {
        $stmt = $this->db->prepare(
            "INSERT IGNORE INTO maestro_instrumento (id_maestro, id_instrumento) VALUES (?, ?)"
        );
        foreach ($idsInstrumentos as $idInstrumento) {
            $stmt->execute([$idMaestro, (int)$idInstrumento]);
        }
    }

    public function instrumentosDe(int $idMaestro): array
    {
        $stmt = $this->db->prepare(
            "SELECT i.id_instrumento, i.nombre FROM maestro_instrumento mi
             JOIN instrumentos i ON i.id_instrumento = mi.id_instrumento
             WHERE mi.id_maestro = ?"
        );
        $stmt->execute([$idMaestro]);
        return $stmt->fetchAll();
    }

    /** Maestros que dan cierto instrumento (para el formulario de alta de inscripción) */
    public function porInstrumento(int $idInstrumento): array
    {
        $stmt = $this->db->prepare(
            "SELECT m.* FROM maestros m
             JOIN maestro_instrumento mi ON mi.id_maestro = m.id_maestro
             WHERE mi.id_instrumento = ? AND m.activo = 1"
        );
        $stmt->execute([$idInstrumento]);
        return $stmt->fetchAll();
    }

    /**
     * Da de baja al maestro. Si todavía tiene clases activas se niega y dice
     * cuáles: primero hay que pasarlas a otro maestro (eso las pasa y lo da de
     * baja en un solo paso; ver InscripcionService::pasarClasesDeMaestro).
     *
     * @throws Exception con las clases que se lo impiden
     */
    public function desactivar(int $id): bool
    {
        return HorarioModel::conCandado(function () use ($id) {
            $clases = $this->clasesActivasDe($id);
            if ($clases) {
                $ab = HorarioModel::ABREV;
                $txt = array_map(fn($c) => $c['dia_semana']
                    ? sprintf('%s (%s %s–%s)', trim($c['alumno']), $ab[$c['dia_semana']] ?? $c['dia_semana'],
                              substr($c['hora_inicio'], 0, 5), substr($c['hora_fin'], 0, 5))
                    : trim($c['alumno']) . ' (sin horario)', $clases);
                $n = count($clases);
                $m = $this->obtener($id);
                throw new Exception(sprintf(
                    '%s tiene %d %s: %s%s. Usa «Pasar sus clases y dar de baja» para pasarlas a otro maestro.',
                    $m ? trim($m['nombre'] . ' ' . $m['apellido']) : 'Ese maestro', $n, $n === 1 ? 'clase activa' : 'clases activas',
                    implode(', ', array_slice($txt, 0, 3)), $n > 3 ? ' y ' . ($n - 3) . ' más' : ''));
            }
            $stmt = $this->db->prepare("UPDATE maestros SET activo = 0 WHERE id_maestro = ?");
            return $stmt->execute([$id]);
        });
    }

    /** Las clases vivas (no de baja) de un maestro, con alumno y horario */
    public function clasesActivasDe(int $id): array
    {
        $s = $this->db->prepare(
            "SELECT CONCAT_WS(' ', a.nombre, a.apellido) AS alumno, ins.dia_semana, ins.hora_inicio, ins.hora_fin
               FROM inscripciones ins JOIN alumnos a ON a.id_alumno = ins.id_alumno
              WHERE ins.id_maestro = ? AND ins.estado <> 'baja'
              ORDER BY FIELD(ins.dia_semana,'Lunes','Martes','Miercoles','Jueves','Viernes','Sabado'), ins.hora_inicio, ins.id_inscripcion"
        );
        $s->execute([$id]);
        return $s->fetchAll();
    }

    /** Cuántos renglones de nómina se ajustaron en la última actualización */
    public int $renglonesRecalculados = 0;

    /**
     * Vuelve a calcular lo que se le paga a este maestro por cada alumno, en
     * el mes en curso y en los siguientes. Los meses ya pagados no se tocan:
     * ese dinero ya se entregó y su recibo ya se imprimió.
     */
    public function recalcularNomina(int $idMaestro): int
    {
        $m = $this->obtener($idMaestro);
        if (!$m) return 0;

        $stmt = $this->db->prepare(
            "SELECT nd.id_detalle, nd.periodo, nd.clases, nd.desde, nd.hasta, nd.id_inscripcion,
                    COALESCE(ins.duracion_min, 60) AS duracion_min, ins.dia_semana, ins.fecha_alta, ins.origen
               FROM nomina_detalle nd
               JOIN inscripciones ins ON ins.id_inscripcion = nd.id_inscripcion
              WHERE nd.id_maestro = ? AND nd.periodo >= ? AND nd.pagado = 0 AND nd.ajuste_manual = 0"
        );
        $stmt->execute([$idMaestro, date('Y-m')]);
        $filas = $stmt->fetchAll();
        if (!$filas) return 0;

        $actualiza = $this->db->prepare("UPDATE nomina_detalle SET monto = ? WHERE id_detalle = ?");
        $n = 0;
        foreach ($filas as $f) {
            // Un tramo parcial (entró a medio mes, cambió de maestro) conserva sus clases.
            $clases = $f['clases'] === null ? null : (int) $f['clases'];
            $monto = NominaModel::montoDelRenglon($m, $f, $f['periodo'], $f['desde'], $f['hasta'], $clases);
            $actualiza->execute([$monto, $f['id_detalle']]);
            $n += $actualiza->rowCount();
        }
        return $n;
    }

    public function reactivar(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE maestros SET activo = 1 WHERE id_maestro = ?");
        return $stmt->execute([$id]);
    }

    /** ¿Tiene alumnos activos actualmente? (para avisar antes de dar de baja) */
    public function tieneAlumnosActivos(int $id): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM inscripciones WHERE id_maestro = ? AND estado <> 'baja'"
        );
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn() > 0;
    }
}

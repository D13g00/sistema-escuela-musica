<?php
require_once __DIR__ . '/../config/Database.php';

class InscripcionModel
{
    public PDO $db; // público: el Service lo usa dentro de su propia transacción

    public function __construct() { $this->db = Database::conexion(); }

    /**
     * Los JOIN de instrumento y salón son LEFT: una inscripción 'pendiente'
     * (importada desde Excel) todavía no los tiene asignados.
     */
    public function listar(?string $estado = null): array
    {
        $sql = "SELECT ins.*, a.nombre AS alumno_nombre, a.apellido AS alumno_apellido,
                       m.nombre AS maestro_nombre, m.apellido AS maestro_apellido,
                       i.nombre AS instrumento, s.nombre AS salon,
                       m.modo_pago, m.tarifa_por_alumno, m.tarifa_por_hora
                FROM inscripciones ins
                JOIN alumnos a  ON a.id_alumno = ins.id_alumno
                JOIN maestros m ON m.id_maestro = ins.id_maestro
                LEFT JOIN instrumentos i ON i.id_instrumento = ins.id_instrumento
                LEFT JOIN salones s      ON s.id_salon = ins.id_salon";
        if ($estado) $sql .= " WHERE ins.estado = " . $this->db->quote($estado);
        $sql .= " ORDER BY ins.estado = 'pendiente' DESC, ins.fecha_alta DESC, ins.id_inscripcion DESC";
        return $this->db->query($sql)->fetchAll();
    }

    public function obtener(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM inscripciones WHERE id_inscripcion = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Usado dentro de la transacción del InscripcionService */
    public function insertar(array $d): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO inscripciones
                (id_alumno, id_maestro, id_instrumento, id_salon, modalidad, dia_semana, hora_inicio, hora_fin,
                 duracion_min, costo_mensual, estado, comparte_con, fecha_alta)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'activa', ?, ?)"
        );
        // fecha_alta es el día en que empieza a tomar clase: con ella se
        // calcula el primer mes cuando entra a medio mes.
        $stmt->execute([
            $d['id_alumno'], $d['id_maestro'], $d['id_instrumento'], $d['id_salon'],
            ($d['modalidad'] ?? 'presencial') === 'online' ? 'online' : 'presencial',
            $d['dia_semana'], $d['hora_inicio'], $d['hora_fin'],
            $d['duracion_min'] ?? 60, $d['costo_mensual'], $d['comparte_con'] ?? null,
            $d['fecha_inicio'] ?? date('Y-m-d'),
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** Completa una inscripción pendiente con su horario y la deja activa */
    public function completar(int $id, array $d): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE inscripciones
                SET id_instrumento = ?, id_salon = ?, modalidad = ?, comparte_con = ?,
                    dia_semana = ?, hora_inicio = ?, hora_fin = ?,
                    duracion_min = ?, costo_mensual = ?, estado = 'activa'
              WHERE id_inscripcion = ?"
        );
        return $stmt->execute([
            $d['id_instrumento'], $d['id_salon'],
            ($d['modalidad'] ?? 'presencial') === 'online' ? 'online' : 'presencial',
            $d['comparte_con'] ?? null,
            $d['dia_semana'], $d['hora_inicio'], $d['hora_fin'], $d['duracion_min'] ?? 60, $d['costo_mensual'], $id
        ]);
    }

    /** ¿Este alumno ya tuvo alguna clase antes? Sirve para no cobrarle dos veces la inscripción. */
    public function esPrimeraClase(int $idAlumno): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM inscripciones WHERE id_alumno = ?");
        $stmt->execute([$idAlumno]);
        return (int) $stmt->fetchColumn() === 0;
    }

    public function marcarBaja(int $idInscripcion, ?string $fechaBaja = null): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE inscripciones SET estado = 'baja', fecha_baja = ? WHERE id_inscripcion = ?"
        );
        return $stmt->execute([$fechaBaja ?: date('Y-m-d'), $idInscripcion]);
    }
}

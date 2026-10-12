<?php
require_once __DIR__ . '/../config/Database.php';

/**
 * Borradores de alta: una inscripción que se dejó a medias.
 *
 * Un borrador es una INTENCIÓN de alta, no un alumno. Nunca aparece como
 * alumno activo, deudor, inscripción, nómina ni horario ocupado. Se guarda
 * aunque el horario que se quería esté ocupado: justo para eso existe.
 *
 * Los datos van en dos bloques JSON (los del alumno y los de la clase), pero
 * solo con las claves conocidas y con su forma revisada: nada que llegue del
 * navegador se guarda sin pasar por aquí.
 */
class BorradorModel
{
    private PDO $db;

    private const CLAVES_ALUMNO = [
        'nombre' => 160, 'apellido' => 160, 'telefono' => 20, 'email' => 120, 'fecha_nacimiento' => 10,
        'tutor_nombre' => 120, 'tutor_telefono' => 20, 'tutor_parentesco' => 40, 'direccion' => 200, 'notas' => 1000,
        'requiere_factura' => 1, 'rfc' => 13, 'razon_social' => 200, 'correo_factura' => 120, 'datos_fiscales' => 300,
    ];
    private const DIAS = ['Lunes','Martes','Miercoles','Jueves','Viernes','Sabado'];

    public function __construct() { $this->db = Database::conexion(); }

    public function listar(string $estado = 'abierto'): array
    {
        if (!in_array($estado, ['abierto', 'completado', 'descartado'], true)) $estado = 'abierto';
        $s = $this->db->prepare(
            "SELECT b.*, CONCAT_WS(' ', a.nombre, a.apellido) AS alumno_existente
               FROM borradores_alumnos b LEFT JOIN alumnos a ON a.id_alumno = b.id_alumno
              WHERE b.estado = ? ORDER BY b.fecha_actualizacion DESC"
        );
        $s->execute([$estado]);
        return array_map([$this, 'decodificar'], $s->fetchAll());
    }

    public function obtener(int $id): ?array
    {
        $s = $this->db->prepare(
            "SELECT b.*, CONCAT_WS(' ', a.nombre, a.apellido) AS alumno_existente
               FROM borradores_alumnos b LEFT JOIN alumnos a ON a.id_alumno = b.id_alumno
              WHERE b.id_borrador = ?"
        );
        $s->execute([$id]);
        $f = $s->fetch();
        return $f ? $this->decodificar($f) : null;
    }

    /**
     * Crea o actualiza un borrador abierto.
     *
     * @param array $d {id_alumno?, alumno: {...}, clase: {...}, motivo?}
     */
    public function guardar(?int $id, array $d, ?string $usuario): int
    {
        $idAlumno = !empty($d['id_alumno']) ? (int) $d['id_alumno'] : null;
        if ($idAlumno) {
            $e = $this->db->prepare("SELECT CONCAT_WS(' ', nombre, apellido) FROM alumnos WHERE id_alumno = ?");
            $e->execute([$idAlumno]);
            $existente = $e->fetchColumn();
            if ($existente === false) throw new Exception('Ese alumno no existe.');
        }
        $alumno = $this->limpiarAlumno(is_array($d['alumno'] ?? null) ? $d['alumno'] : []);
        $clase  = $this->limpiarClase(is_array($d['clase'] ?? null) ? $d['clase'] : []);

        $nombre = trim(($alumno['nombre'] ?? '') . ' ' . ($alumno['apellido'] ?? ''));
        if ($nombre === '' && $idAlumno) $nombre = (string) $existente;
        if ($nombre === '') throw new Exception('Escribe al menos el nombre para guardar el borrador.');

        $motivo = mb_substr(trim((string) ($d['motivo'] ?? '')), 0, 200) ?: null;
        $jsonA = $alumno ? json_encode($alumno, JSON_UNESCAPED_UNICODE) : null;
        $jsonC = $clase ? json_encode($clase, JSON_UNESCAPED_UNICODE) : null;

        if ($id) {
            $actual = $this->obtener($id);
            if (!$actual) throw new Exception('Ese borrador ya no existe.');
            if ($actual['estado'] !== 'abierto') throw new Exception('Ese borrador ya está cerrado.');
            $this->db->prepare(
                "UPDATE borradores_alumnos SET id_alumno = ?, nombre_mostrar = ?, datos_alumno = ?, datos_clase = ?,
                        motivo = ?, actualizado_por = ? WHERE id_borrador = ?"
            )->execute([$idAlumno ?? $actual['id_alumno'], mb_substr($nombre, 0, 160), $jsonA, $jsonC, $motivo, $usuario, $id]);
            return $id;
        }
        $this->db->prepare(
            "INSERT INTO borradores_alumnos (id_alumno, nombre_mostrar, datos_alumno, datos_clase, motivo, creado_por, actualizado_por)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([$idAlumno, mb_substr($nombre, 0, 160), $jsonA, $jsonC, $motivo, $usuario, $usuario]);
        return (int) $this->db->lastInsertId();
    }

    /** Se creó el alumno a partir del borrador, pero la clase todavía falta. */
    public function vincularAlumno(int $id, int $idAlumno): void
    {
        $this->db->prepare(
            "UPDATE borradores_alumnos SET id_alumno = ? WHERE id_borrador = ? AND estado = 'abierto'"
        )->execute([$idAlumno, $id]);
    }

    /** El alta se completó. Va dentro de la transacción del alta. */
    public function completar(PDO $db, int $id, int $idAlumno, int $idInscripcion): void
    {
        $s = $db->prepare("SELECT estado FROM borradores_alumnos WHERE id_borrador = ? FOR UPDATE");
        $s->execute([$id]);
        $estado = $s->fetchColumn();
        if ($estado === false) throw new Exception('Ese borrador ya no existe.');
        if ($estado !== 'abierto') throw new Exception('Ese borrador ya estaba cerrado.');
        $db->prepare(
            "UPDATE borradores_alumnos
                SET estado = 'completado', id_alumno_resultante = ?, id_inscripcion_resultante = ?, fecha_cierre = NOW()
              WHERE id_borrador = ?"
        )->execute([$idAlumno, $idInscripcion, $id]);
    }

    public function descartar(int $id): void
    {
        $s = $this->db->prepare(
            "UPDATE borradores_alumnos SET estado = 'descartado', fecha_cierre = NOW()
              WHERE id_borrador = ? AND estado = 'abierto'"
        );
        $s->execute([$id]);
        if ($s->rowCount() === 0) throw new Exception('Ese borrador ya no está abierto.');
    }

    // -----------------------------------------------------------------

    private function decodificar(array $f): array
    {
        $f['alumno'] = $f['datos_alumno'] ? (json_decode($f['datos_alumno'], true) ?: []) : [];
        $f['clase']  = $f['datos_clase'] ? (json_decode($f['datos_clase'], true) ?: []) : [];
        unset($f['datos_alumno'], $f['datos_clase']);
        return $f;
    }

    private function limpiarAlumno(array $a): array
    {
        $r = [];
        foreach (self::CLAVES_ALUMNO as $clave => $largo) {
            $v = trim((string) ($a[$clave] ?? ''));
            if ($v === '') continue;
            if ($clave === 'fecha_nacimiento' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) continue;
            if ($clave === 'requiere_factura') { $r[$clave] = in_array($v, ['0', 'false', 'off'], true) ? '0' : '1'; continue; }
            if ($clave === 'email' && !filter_var($v, FILTER_VALIDATE_EMAIL)) continue;
            $r[$clave] = mb_substr($v, 0, $largo);
        }
        return $r;
    }

    private function limpiarClase(array $c): array
    {
        $r = [];
        foreach (['id_instrumento', 'id_maestro', 'id_salon', 'compartir_con'] as $k) {
            if (!empty($c[$k]) && (int) $c[$k] > 0) $r[$k] = (int) $c[$k];
        }
        if (in_array($c['modalidad'] ?? '', ['presencial', 'online'], true)) $r['modalidad'] = $c['modalidad'];
        if (($r['modalidad'] ?? '') === 'online') unset($r['id_salon']);
        if (in_array($c['dia_semana'] ?? '', self::DIAS, true)) $r['dia_semana'] = $c['dia_semana'];
        if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', (string) ($c['hora_inicio'] ?? ''))) {
            $r['hora_inicio'] = substr($c['hora_inicio'], 0, 5) . ':00';
        }
        $dur = (int) ($c['duracion_min'] ?? 0);
        if ($dur >= 30 && $dur <= 240 && $dur % 30 === 0) $r['duracion_min'] = $dur;
        if (isset($c['costo_mensual']) && is_numeric($c['costo_mensual'])
            && (float) $c['costo_mensual'] >= 0 && (float) $c['costo_mensual'] <= 1000000) {
            $r['costo_mensual'] = round((float) $c['costo_mensual'], 2);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($c['fecha_inicio'] ?? ''))) $r['fecha_inicio'] = $c['fecha_inicio'];
        if (!empty($c['id_lista_espera']) && (int) $c['id_lista_espera'] > 0) $r['id_lista_espera'] = (int) $c['id_lista_espera'];
        return $r;
    }
}

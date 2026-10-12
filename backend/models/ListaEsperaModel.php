<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/HorarioModel.php';

/**
 * Lista de espera: quien quiere una clase que hoy está llena.
 *
 * Una solicitud NO es una inscripción. No ocupa horario, no genera nómina, no
 * genera cargos ni deuda, y no aparece en ningún horario. Cuando se libera un
 * lugar compatible, el sistema solo AVISA: la persona se contacta y se inscribe
 * de la forma normal. Hasta que eso pasa, sigue siendo una solicitud.
 *
 * Coincidencia exacta (la que dispara el aviso):
 *   · misma materia y mismo día;
 *   · la MISMA hora de inicio que pidió: si pidió martes 17:00 y se liberó
 *     martes 17:30, no es coincidencia;
 *   · que lo que pidió quepa en lo que se liberó (duración);
 *   · el maestro y el salón, si los pidió; la modalidad, salvo "cualquiera";
 *   · y que ese lugar esté libre DE VERDAD en este momento.
 * Lo que comparte materia y día pero no la hora se muestra aparte, como
 * alternativa, nunca mezclado con las coincidencias.
 */
class ListaEsperaModel
{
    private PDO $db;
    private HorarioModel $horario;

    private const DIAS = ['Lunes','Martes','Miercoles','Jueves','Viernes','Sabado'];
    private const ESTADOS = ['pendiente','contactado','inscrito','cancelado'];
    private const MODALIDADES = ['presencial','online','cualquiera'];

    public function __construct()
    {
        $this->db = Database::conexion();
        $this->horario = new HorarioModel();
    }

    private const SELECT = "
        SELECT le.*,
               -- CONCAT_WS da '' (no NULL) cuando no hay alumno: por eso el IF.
               IF(le.id_alumno IS NULL, le.nombre, CONCAT_WS(' ', a.nombre, a.apellido)) AS quien,
               COALESCE(NULLIF(a.telefono, ''), NULLIF(a.tutor_telefono, ''), le.telefono) AS contacto,
               i.nombre AS materia, COALESCE(i.es_grupal, 0) AS es_grupal,
               CONCAT_WS(' ', m.nombre, m.apellido) AS maestro,
               s.nombre AS salon
          FROM lista_espera le
          LEFT JOIN alumnos a      ON a.id_alumno = le.id_alumno
          JOIN instrumentos i      ON i.id_instrumento = le.id_instrumento
          LEFT JOIN maestros m     ON m.id_maestro = le.id_maestro
          LEFT JOIN salones s      ON s.id_salon = le.id_salon";

    /** Las solicitudes, con la marca de si su lugar está libre hoy mismo */
    public function listar(?string $estado = null): array
    {
        $sql = self::SELECT;
        $par = [];
        if ($estado === 'abiertas') {
            $sql .= " WHERE le.estado IN ('pendiente','contactado')";
        } elseif ($estado && in_array($estado, self::ESTADOS, true)) {
            $sql .= " WHERE le.estado = ?"; $par[] = $estado;
        }
        $sql .= " ORDER BY FIELD(le.estado,'pendiente','contactado','inscrito','cancelado'),
                           le.prioridad DESC, le.fecha_solicitud, le.id_lista_espera";
        $s = $this->db->prepare($sql);
        $s->execute($par);
        $filas = $s->fetchAll();
        foreach ($filas as &$f) {
            $f['disponible_ahora'] = in_array($f['estado'], ['pendiente','contactado'], true)
                ? $this->estaLibre($f) : null;
        }
        unset($f);
        return $filas;
    }

    public function obtener(int $id): ?array
    {
        $s = $this->db->prepare(self::SELECT . " WHERE le.id_lista_espera = ?");
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public function crear(array $d, ?string $usuario): int
    {
        $v = $this->validar($d);
        $this->db->prepare(
            "INSERT INTO lista_espera (id_alumno, nombre, telefono, correo, id_instrumento, id_maestro, id_salon,
                                       dia_semana, hora_inicio, duracion_min, modalidad, prioridad, notas, registrado_por)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([$v['id_alumno'], $v['nombre'], $v['telefono'], $v['correo'], $v['id_instrumento'],
                    $v['id_maestro'], $v['id_salon'], $v['dia_semana'], $v['hora_inicio'], $v['duracion_min'],
                    $v['modalidad'], $v['prioridad'], $v['notas'], $usuario]);
        return (int) $this->db->lastInsertId();
    }

    public function actualizar(int $id, array $d): void
    {
        $actual = $this->obtener($id);
        if (!$actual) throw new Exception('Esa solicitud no existe.');
        if ($actual['estado'] === 'inscrito') throw new Exception('Esa solicitud ya terminó en una inscripción.');
        $v = $this->validar($d + $actual);
        $this->db->prepare(
            "UPDATE lista_espera SET id_alumno = ?, nombre = ?, telefono = ?, correo = ?, id_instrumento = ?,
                    id_maestro = ?, id_salon = ?, dia_semana = ?, hora_inicio = ?, duracion_min = ?,
                    modalidad = ?, prioridad = ?, notas = ?
              WHERE id_lista_espera = ?"
        )->execute([$v['id_alumno'], $v['nombre'], $v['telefono'], $v['correo'], $v['id_instrumento'],
                    $v['id_maestro'], $v['id_salon'], $v['dia_semana'], $v['hora_inicio'], $v['duracion_min'],
                    $v['modalidad'], $v['prioridad'], $v['notas'], $id]);
    }

    /** Contactado o cancelado. "Inscrito" solo lo pone un alta real, nunca a mano. */
    public function cambiarEstado(int $id, string $estado): void
    {
        if (!in_array($estado, ['pendiente', 'contactado', 'cancelado'], true)) {
            throw new Exception('Una solicitud solo pasa a inscrita al completar su alta.');
        }
        $actual = $this->obtener($id);
        if (!$actual) throw new Exception('Esa solicitud no existe.');
        if ($actual['estado'] === 'inscrito') throw new Exception('Esa solicitud ya terminó en una inscripción.');
        $this->db->prepare(
            "UPDATE lista_espera SET estado = ?, fecha_contacto = IF(? = 'contactado', NOW(), fecha_contacto)
              WHERE id_lista_espera = ?"
        )->execute([$estado, $estado, $id]);
    }

    /**
     * La solicitud se cumplió: pasó por un alta normal. Va dentro de la
     * transacción del alta, así que si el alta falla la solicitud sigue abierta.
     */
    public function marcarInscrito(PDO $db, int $id, int $idInscripcion): void
    {
        $s = $db->prepare("SELECT estado FROM lista_espera WHERE id_lista_espera = ? FOR UPDATE");
        $s->execute([$id]);
        $estado = $s->fetchColumn();
        if ($estado === false) throw new Exception('La solicitud de la lista de espera ya no existe.');
        if (!in_array($estado, ['pendiente', 'contactado'], true)) {
            throw new Exception('Esa solicitud de la lista de espera ya estaba cerrada.');
        }
        $db->prepare("UPDATE lista_espera SET estado = 'inscrito', id_inscripcion_resultante = ? WHERE id_lista_espera = ?")
           ->execute([$idInscripcion, $id]);
    }

    /**
     * Quién espera lo que se acaba de liberar.
     *
     * @param array $hueco {id_instrumento, id_maestro, id_salon, modalidad, dia_semana,
     *                      hora_inicio, hora_fin, grupal, libera}
     * @return array {hueco, exactas: [...], alternativas: [...]}
     */
    public function coincidencias(array $hueco): array
    {
        $vacio = ['hueco' => $hueco, 'exactas' => [], 'alternativas' => []];
        if (empty($hueco['id_instrumento']) || empty($hueco['dia_semana'])) return $vacio;

        $minutos = (int) round((strtotime($hueco['hora_fin']) - strtotime($hueco['hora_inicio'])) / 60);
        // En un grupo operativo (Dibujo y Pintura), quien espera cualquiera de
        // sus materias espera el mismo lugar.
        $materias = $this->horario->materiasDelGrupo((int) $hueco['id_instrumento']) ?: [(int) $hueco['id_instrumento']];
        $marcas = implode(',', array_fill(0, count($materias), '?'));
        $s = $this->db->prepare(self::SELECT . "
             WHERE le.estado IN ('pendiente','contactado')
               AND le.id_instrumento IN ($marcas) AND le.dia_semana = ?
               AND (le.id_maestro IS NULL OR le.id_maestro = ?)
               AND (le.modalidad = 'cualquiera' OR le.modalidad = ?)
             ORDER BY le.prioridad DESC, le.fecha_solicitud, le.id_lista_espera");
        $s->execute(array_merge($materias, [$hueco['dia_semana'], (int) $hueco['id_maestro'],
                     $hueco['modalidad'] ?? 'presencial']));

        $exactas = []; $alternativas = [];
        foreach ($s->fetchAll() as $f) {
            $mismaHora = $f['hora_inicio'] !== null
                && substr($f['hora_inicio'], 0, 5) === substr($hueco['hora_inicio'], 0, 5);
            $cabe = (int) $f['duracion_min'] <= $minutos;
            $salonOk = $f['id_salon'] === null || ($hueco['modalidad'] ?? 'presencial') === 'online'
                    || (int) $f['id_salon'] === (int) $hueco['id_salon'];

            if ($mismaHora && $cabe && $salonOk) {
                // Coincide en todo lo pedido; falta que el lugar siga libre ahora.
                $f['disponible_ahora'] = $this->estaLibre($f + [
                    'id_maestro_hueco' => (int) $hueco['id_maestro'], 'id_salon_hueco' => $hueco['id_salon'],
                ]);
                if ($f['disponible_ahora']) { $exactas[] = $f; continue; }
            }
            $f['por_que_no'] = !$mismaHora ? 'pidió otra hora'
                             : (!$cabe ? 'pidió más tiempo del que se liberó'
                             : (!$salonOk ? 'pidió otro salón' : 'el lugar ya no está libre'));
            $alternativas[] = $f;
        }
        return ['hueco' => $hueco, 'exactas' => $exactas, 'alternativas' => $alternativas];
    }

    /**
     * ¿El lugar que pide esta solicitud está libre ahora mismo?
     *   · grupal: hay grupo con cupo en ese hueco, o el hueco entero está libre;
     *   · individual: ni el maestro ni el salón chocan.
     * Sin día u hora no se puede saber: no se marca como disponible.
     */
    private function estaLibre(array $f): bool
    {
        if (empty($f['dia_semana']) || empty($f['hora_inicio'])) return false;
        $idMaestro = !empty($f['id_maestro']) ? (int) $f['id_maestro'] : (int) ($f['id_maestro_hueco'] ?? 0);
        if (!$idMaestro) return false;
        $online = ($f['modalidad'] ?? '') === 'online';
        $idSalon = $online ? null
                 : (!empty($f['id_salon']) ? (int) $f['id_salon']
                 : (!empty($f['id_salon_hueco']) ? (int) $f['id_salon_hueco'] : null));
        if (!$online && !$idSalon) return false;

        $ini = $f['hora_inicio'];
        $fin = date('H:i:s', strtotime($ini) + 60 * (int) $f['duracion_min']);

        // Grupo: sin choques con otra cosa y con cupo a esa hora, aunque los
        // demás del grupo lleguen a otras horas.
        if ((int) ($f['es_grupal'] ?? 0) === 1 && $idSalon) {
            return $this->horario->lugarParaGrupo($idSalon, $idMaestro, (int) $f['id_instrumento'],
                                                  $f['dia_semana'], $ini, $fin);
        }
        return $this->horario->choque($idSalon, $idMaestro, $f['dia_semana'], $ini, $fin) === null;
    }

    /** Solo se guarda lo que se conoce, bien formado. */
    private function validar(array $d): array
    {
        $idAlumno = !empty($d['id_alumno']) ? (int) $d['id_alumno'] : null;
        $nombre   = trim((string) ($d['nombre'] ?? '')) ?: null;
        $telefono = trim((string) ($d['telefono'] ?? '')) ?: null;
        if ($idAlumno) {
            $e = $this->db->prepare("SELECT COUNT(*) FROM alumnos WHERE id_alumno = ?");
            $e->execute([$idAlumno]);
            if (!(int) $e->fetchColumn()) throw new Exception('Ese alumno no existe.');
        } elseif (!$nombre || !$telefono) {
            throw new Exception('Elige un alumno, o escribe el nombre y el teléfono de quien espera.');
        }

        $idInstr = (int) ($d['id_instrumento'] ?? 0);
        $e = $this->db->prepare("SELECT COUNT(*) FROM instrumentos WHERE id_instrumento = ?");
        $e->execute([$idInstr]);
        if (!$idInstr || !(int) $e->fetchColumn()) throw new Exception('Elige la materia que quiere.');

        $modalidad = in_array($d['modalidad'] ?? '', self::MODALIDADES, true) ? $d['modalidad'] : 'presencial';
        if ($modalidad === 'online' && $this->horario->esGrupal($idInstr)) {
            throw new Exception('Pintura y dibujo son presenciales: no hay lista de espera en línea.');
        }
        $dia = in_array($d['dia_semana'] ?? '', self::DIAS, true) ? $d['dia_semana'] : null;
        $hora = preg_match('/^\d{2}:\d{2}(:\d{2})?$/', (string) ($d['hora_inicio'] ?? ''))
              ? substr($d['hora_inicio'], 0, 5) . ':00' : null;
        $duracion = (int) ($d['duracion_min'] ?? 60);
        if ($duracion < 30 || $duracion > 240 || $duracion % 30 !== 0) {
            throw new Exception('La duración va de media hora a cuatro horas, en medias horas.');
        }

        return [
            'id_alumno' => $idAlumno,
            'nombre' => $idAlumno ? null : mb_substr($nombre, 0, 160),
            'telefono' => $idAlumno ? null : mb_substr($telefono, 0, 20),
            'correo' => $idAlumno ? null : (mb_substr(trim((string) ($d['correo'] ?? '')), 0, 120) ?: null),
            'id_instrumento' => $idInstr,
            'id_maestro' => !empty($d['id_maestro']) ? (int) $d['id_maestro'] : null,
            'id_salon' => $modalidad === 'online' || empty($d['id_salon']) ? null : (int) $d['id_salon'],
            'dia_semana' => $dia,
            'hora_inicio' => $hora,
            'duracion_min' => $duracion,
            'modalidad' => $modalidad,
            'prioridad' => max(0, min(100, (int) ($d['prioridad'] ?? 0))),
            'notas' => mb_substr(trim((string) ($d['notas'] ?? '')), 0, 500) ?: null,
        ];
    }
}

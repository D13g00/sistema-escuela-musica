<?php
require_once __DIR__ . '/../config/Database.php';

class HorarioModel
{
    private PDO $db;
    public const DIAS = ['Lunes','Martes','Miercoles','Jueves','Viernes','Sabado'];

    public const ABREV = ['Lunes' => 'lun', 'Martes' => 'mar', 'Miercoles' => 'mié', 'Jueves' => 'jue', 'Viernes' => 'vie', 'Sabado' => 'sáb'];

    public function __construct() { $this->db = Database::conexion(); }

    /**
     * Todo lo que valida un horario y luego lo escribe (altas, ediciones,
     * cambios de salón o de capacidad, la importación) corre con este candado:
     * de dos personas que quieren el mismo hueco a la vez, la segunda espera a
     * que la primera termine y entonces ve el hueco ocupado. El candado es
     * reentrante: una operación que llama a otra no se espera a sí misma.
     *
     * @throws Exception si en 5 segundos no se pudo tomar
     */
    public static function conCandado(callable $operacion)
    {
        if (!Database::candado('horario', 5)) {
            throw new Exception('Otra persona está guardando un horario en este momento. Intenta de nuevo en unos segundos.');
        }
        try {
            return $operacion();
        } finally {
            Database::soltar('horario');
        }
    }

    /** "Juan Pérez (lun 16:00–17:00)", para explicar qué clases estorban */
    private static function etiquetaDeClase(array $c): string
    {
        return sprintf('%s (%s %s–%s)', trim($c['alumno']), self::ABREV[$c['dia_semana']] ?? $c['dia_semana'],
            substr($c['hora_inicio'], 0, 5), substr($c['hora_fin'], 0, 5));
    }

    /** Lista de hasta $max textos y "y N más": "A, B, C y 2 más" */
    private static function listaCorta(array $textos, int $max = 3, string $sep = ', '): string
    {
        $n = count($textos);
        $txt = implode($sep, array_slice($textos, 0, $max));
        return $n > $max ? $txt . ' y ' . ($n - $max) . ' más' : $txt;
    }

    /**
     * Motivo por el que NO se puede poner una clase con ese maestro o en ese
     * salón porque están desactivados (null si están bien). Solo para altas y
     * cambios: una baja o su corrección no pasan por aquí.
     */
    public function motivoDeInactivo(int $idMaestro, ?int $idSalon): ?string
    {
        $m = $this->db->prepare("SELECT activo FROM maestros WHERE id_maestro = ?");
        $m->execute([$idMaestro]);
        $activo = $m->fetchColumn();
        if ($activo === false) return 'Ese maestro no existe: elige otro.';
        if ((int) $activo !== 1) return 'Ese maestro está desactivado: elige otro o reactívalo en Maestros.';
        if ($idSalon) {
            $s = $this->db->prepare("SELECT activo FROM salones WHERE id_salon = ?");
            $s->execute([$idSalon]);
            $activo = $s->fetchColumn();
            if ($activo === false) return 'Ese salón no existe: elige otro.';
            if ((int) $activo !== 1) return 'Ese salón está desactivado: elige otro.';
        }
        return null;
    }

    // -----------------------------------------------------------------
    // Ajustes de los salones que no pueden dejar clases fuera
    // -----------------------------------------------------------------

    /**
     * El momento en que más alumnos están a la vez en el salón (cualquier día).
     * Cuenta a quien comparte la clase y a los del taller que coinciden.
     *
     * @return array|null {max, dia_semana, inicio, fin, alumnos[]}; null si no hay clases
     */
    public function maximoALaVezEnSalon(int $idSalon): ?array
    {
        $s = $this->db->prepare(
            "SELECT ho.id_inscripcion, ho.dia_semana, ho.hora_inicio, ho.hora_fin,
                    CONCAT_WS(' ', a.nombre, a.apellido) AS alumno
               FROM horario_ocupado ho
               JOIN inscripciones ins ON ins.id_inscripcion = ho.id_inscripcion
               JOIN alumnos a ON a.id_alumno = ins.id_alumno
              WHERE ho.id_salon = ? AND ins.estado <> 'baja'"
        );
        $s->execute([$idSalon]);
        $filas = $s->fetchAll();
        if (!$filas) return null;

        $comp = $this->db->prepare(
            "SELECT c.comparte_con, CONCAT_WS(' ', a.nombre, a.apellido) AS alumno
               FROM inscripciones c JOIN alumnos a ON a.id_alumno = c.id_alumno
              WHERE c.estado <> 'baja' AND c.comparte_con IN (
                    SELECT ho.id_inscripcion FROM horario_ocupado ho WHERE ho.id_salon = ?)"
        );
        $comp->execute([$idSalon]);
        $integrantes = [];
        foreach ($comp->fetchAll() as $c) $integrantes[(int) $c['comparte_con']][] = $c['alumno'];
        foreach ($filas as &$f) {
            $f['nombres'] = array_merge([$f['alumno']], $integrantes[(int) $f['id_inscripcion']] ?? []);
        }
        unset($f);

        $mejor = null;
        $porDia = [];
        foreach ($filas as $f) $porDia[$f['dia_semana']][] = $f;
        foreach ($porDia as $dia => $clases) {
            // El máximo se da siempre en el arranque de alguna clase.
            foreach ($clases as $ancla) {
                $t = $ancla['hora_inicio'];
                $nombres = []; $fin = null;
                foreach ($clases as $f) {
                    if ($f['hora_inicio'] <= $t && $f['hora_fin'] > $t) {
                        $nombres = array_merge($nombres, $f['nombres']);
                        $fin = $fin === null || $f['hora_fin'] < $fin ? $f['hora_fin'] : $fin;
                    }
                }
                if (!$mejor || count($nombres) > $mejor['max']) {
                    $mejor = ['max' => count($nombres), 'dia_semana' => $dia, 'inicio' => $t, 'fin' => $fin, 'alumnos' => $nombres];
                }
            }
        }
        return $mejor;
    }

    /**
     * @throws Exception si bajar la capacidad del salón a $nueva dejaría clases con más alumnos a la vez
     */
    public function comprobarCapacidadSalon(int $idSalon, int $nueva): void
    {
        $s = $this->db->prepare("SELECT nombre, capacidad FROM salones WHERE id_salon = ?");
        $s->execute([$idSalon]);
        $salon = $s->fetch();
        if (!$salon) throw new Exception('Ese salón no existe.');
        if ($nueva >= (int) $salon['capacidad']) return;   // subir o dejar igual nunca deja a nadie fuera

        $m = $this->maximoALaVezEnSalon($idSalon);
        if ($m && $m['max'] > $nueva) {
            throw new Exception(sprintf(
                'El espacio «%s» tiene momentos con %d alumnos a la vez (p.ej. %s %s–%s: %s). Mueve esas clases antes de bajar el cupo a %d.',
                $salon['nombre'], $m['max'], self::ABREV[$m['dia_semana']] ?? $m['dia_semana'],
                substr($m['inicio'], 0, 5), substr($m['fin'], 0, 5), self::listaCorta($m['alumnos'], 4), $nueva));
        }
    }

    /**
     * Cambia nombre, capacidad y tipo de un salón. Si baja la capacidad por
     * debajo de lo que ya hay a la vez, se niega y no cambia nada.
     */
    public function guardarSalon(int $idSalon, string $nombre, int $capacidad, string $tipo): void
    {
        self::conCandado(function () use ($idSalon, $nombre, $capacidad, $tipo) {
            $propia = !$this->db->inTransaction();
            if ($propia) $this->db->beginTransaction();
            try {
                $this->comprobarCapacidadSalon($idSalon, $capacidad);
                $this->db->prepare("UPDATE salones SET nombre = ?, capacidad = ?, tipo = ? WHERE id_salon = ?")
                         ->execute([$nombre, $capacidad, $tipo, $idSalon]);
                if ($propia) $this->db->commit();
            } catch (Throwable $e) {
                if ($propia && $this->db->inTransaction()) $this->db->rollBack();
                throw $e;
            }
        });
    }

    /** Las clases vivas de un salón (con quién y cuándo), para explicar por qué no se puede desactivar */
    public function clasesActivasEnSalon(int $idSalon): array
    {
        $s = $this->db->prepare(
            "SELECT CONCAT_WS(' ', a.nombre, a.apellido) AS alumno, ins.dia_semana, ins.hora_inicio, ins.hora_fin
               FROM inscripciones ins JOIN alumnos a ON a.id_alumno = ins.id_alumno
              WHERE ins.id_salon = ? AND ins.estado <> 'baja'
              ORDER BY FIELD(ins.dia_semana,'Lunes','Martes','Miercoles','Jueves','Viernes','Sabado'), ins.hora_inicio, ins.id_inscripcion"
        );
        $s->execute([$idSalon]);
        return $s->fetchAll();
    }

    /** Activa o desactiva un salón. Desactivarlo con clases activas se niega y dice cuáles. */
    public function activarSalon(int $idSalon, bool $activo): void
    {
        self::conCandado(function () use ($idSalon, $activo) {
            $s = $this->db->prepare("SELECT nombre FROM salones WHERE id_salon = ?");
            $s->execute([$idSalon]);
            $nombre = $s->fetchColumn();
            if ($nombre === false) throw new Exception('Ese salón no existe.');
            if (!$activo) {
                $clases = $this->clasesActivasEnSalon($idSalon);
                if ($clases) {
                    $txt = array_map(fn($c) => $c['dia_semana'] ? self::etiquetaDeClase($c) : trim($c['alumno']), $clases);
                    throw new Exception(sprintf(
                        'El espacio «%s» tiene %d %s: %s. Muévelas a otro salón antes de desactivarlo.',
                        $nombre, count($clases), count($clases) === 1 ? 'clase activa' : 'clases activas', self::listaCorta($txt)));
                }
            }
            $this->db->prepare("UPDATE salones SET activo = ? WHERE id_salon = ?")->execute([$activo ? 1 : 0, $idSalon]);
        });
    }

    public function salones(): array
    {
        return $this->db->query("SELECT * FROM salones WHERE activo = 1 ORDER BY id_salon")->fetchAll();
    }

    public function bloques(): array
    {
        return $this->db->query("SELECT * FROM bloques_horario ORDER BY hora_inicio")->fetchAll();
    }

    /** Horario de apertura de la escuela, por día */
    public function horarioEscuela(): array
    {
        return $this->db->query(
            "SELECT * FROM horario_escuela
             ORDER BY FIELD(dia_semana,'Lunes','Martes','Miercoles','Jueves','Viernes','Sabado')"
        )->fetchAll();
    }

    /**
     * Guarda el horario de apertura. Un día sin turno matutino no existe:
     * si la escuela no abre ese día, se borra su renglón.
     */
    public function guardarHorarioEscuela(array $dias): array
    {
        return self::conCandado(fn() => $this->guardarHorarioEscuelaAdentro($dias));
    }

    /**
     * Clases vivas que caerían fuera del horario de apertura tal como está
     * guardado ahora. Se usa dentro de la transacción que recorta el horario:
     * si sale alguna, se niega y se dice cuáles.
     */
    private function clasesFueraDelHorarioGuardado(): array
    {
        $h = [];
        foreach ($this->db->query("SELECT * FROM horario_escuela") as $f) $h[$f['dia_semana']] = $f;
        $fuera = [];
        $s = $this->db->query(
            "SELECT ins.id_inscripcion, ins.dia_semana, ins.hora_inicio, ins.hora_fin, CONCAT_WS(' ', a.nombre, a.apellido) AS alumno,
                    CONCAT_WS(' ', m.nombre, m.apellido) AS maestro
               FROM inscripciones ins
               JOIN alumnos a ON a.id_alumno = ins.id_alumno
               JOIN maestros m ON m.id_maestro = ins.id_maestro
              WHERE ins.estado <> 'baja' AND ins.dia_semana IS NOT NULL AND ins.hora_inicio IS NOT NULL
              ORDER BY FIELD(ins.dia_semana,'Lunes','Martes','Miercoles','Jueves','Viernes','Sabado'), ins.hora_inicio, ins.id_inscripcion"
        );
        foreach ($s as $c) {
            $d = $h[$c['dia_semana']] ?? null;
            if (!$d || !self::cabeEnTurnos($d, $c['hora_inicio'], $c['hora_fin'])) $fuera[] = $c;
        }
        return $fuera;
    }

    /** ¿De inicio a fin cabe en alguno de los dos turnos de ese día? */
    private static function cabeEnTurnos(array $h, string $inicio, string $fin): bool
    {
        foreach ([['abre_1', 'cierra_1'], ['abre_2', 'cierra_2']] as [$a, $c]) {
            if ($h[$a] && $inicio >= $h[$a] && $fin <= $h[$c]) return true;
        }
        return false;
    }

    private function guardarHorarioEscuelaAdentro(array $dias): array
    {
        $validos = self::DIAS;
        $this->db->beginTransaction();
        try {
            // Las que ya estaban fuera antes de este cambio (datos viejos) no son culpa de él.
            $yaFuera = array_column($this->clasesFueraDelHorarioGuardado(), 'id_inscripcion');
            foreach ($dias as $d) {
                $dia = $d['dia_semana'] ?? '';
                if (!in_array($dia, $validos, true)) continue;

                $abre1 = trim((string) ($d['abre_1'] ?? ''));
                $cierra1 = trim((string) ($d['cierra_1'] ?? ''));

                if ($abre1 === '' || $cierra1 === '') {
                    $this->db->prepare("DELETE FROM horario_escuela WHERE dia_semana = ?")->execute([$dia]);
                    continue;
                }
                if ($cierra1 <= $abre1) {
                    throw new Exception("En $dia, la hora de cierre debe ser posterior a la de apertura.");
                }

                $abre2 = trim((string) ($d['abre_2'] ?? ''));
                $cierra2 = trim((string) ($d['cierra_2'] ?? ''));
                $hayTarde = $abre2 !== '' && $cierra2 !== '';
                if ($hayTarde && $cierra2 <= $abre2) {
                    throw new Exception("En $dia, el turno de la tarde termina antes de empezar.");
                }
                if ($hayTarde && $abre2 < $cierra1) {
                    throw new Exception("En $dia, los dos turnos se enciman.");
                }

                $this->db->prepare(
                    "INSERT INTO horario_escuela (dia_semana, abre_1, cierra_1, abre_2, cierra_2)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE abre_1 = VALUES(abre_1), cierra_1 = VALUES(cierra_1),
                                             abre_2 = VALUES(abre_2), cierra_2 = VALUES(cierra_2)"
                )->execute([$dia, $abre1, $cierra1, $hayTarde ? $abre2 : null, $hayTarde ? $cierra2 : null]);
            }
            // El recorte no puede dejar clases fuera: se revisa con el horario ya
            // aplicado (dentro de la transacción) y, si alguna queda fuera, se deshace todo.
            $afectadas = array_values(array_filter($this->clasesFueraDelHorarioGuardado(),
                fn($c) => !in_array($c['id_inscripcion'], $yaFuera)));
            if ($afectadas) {
                $txt = array_map(fn($c) => sprintf('%s %s–%s, %s con %s', self::ABREV[$c['dia_semana']] ?? $c['dia_semana'],
                    substr($c['hora_inicio'], 0, 5), substr($c['hora_fin'], 0, 5), trim($c['alumno']), trim($c['maestro'])), $afectadas);
                $una = count($afectadas) === 1;
                throw new Exception(sprintf(
                    'Con ese horario %s fuera de la escuela: %s. %s antes de recortar el horario.',
                    $una ? 'una clase quedaría' : count($afectadas) . ' clases quedarían',
                    self::listaCorta($txt, 5, '; '), $una ? 'Muévela' : 'Muévelas'));
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->regenerarBloques();
        return $this->horarioEscuela();
    }

    /** Los bloques de media hora se recalculan a partir del horario de apertura */
    private function regenerarBloques(): void
    {
        $limites = $this->db->query(
            "SELECT MIN(abre_1) a1, MAX(cierra_1) c1, MIN(abre_2) a2, MAX(cierra_2) c2 FROM horario_escuela"
        )->fetch();
        if (!$limites || !$limites['a1']) return;

        $tramos = [[$limites['a1'], $limites['c1']]];
        if ($limites['a2'] && $limites['c2']) $tramos[] = [$limites['a2'], $limites['c2']];

        $this->db->exec("DELETE FROM bloques_horario");
        $stmt = $this->db->prepare(
            "INSERT INTO bloques_horario (hora_inicio, hora_fin, turno) VALUES (?, ?, ?)"
        );
        foreach ($tramos as $i => [$desde, $hasta]) {
            for ($t = strtotime($desde); $t < strtotime($hasta); $t += 1800) {
                $stmt->execute([date('H:i:s', $t), date('H:i:s', $t + 1800), $i === 0 ? 'matutino' : 'vespertino']);
            }
        }
    }

    /**
     * ¿La clase cae dentro del horario en que la escuela está abierta ese día?
     * Devuelve null si todo está bien, o el motivo del rechazo.
     */
    public function fueraDeHorario(string $dia, string $horaInicio, string $horaFin): ?string
    {
        $stmt = $this->db->prepare("SELECT * FROM horario_escuela WHERE dia_semana = ?");
        $stmt->execute([$dia]);
        $h = $stmt->fetch();

        if (!$h) return "La escuela no abre los $dia.";
        if ($horaFin <= $horaInicio) return "La hora de fin debe ser posterior a la de inicio.";

        if (self::cabeEnTurnos($h, $horaInicio, $horaFin)) return null;

        $turnos = substr($h['abre_1'], 0, 5) . ' a ' . substr($h['cierra_1'], 0, 5);
        if ($h['abre_2']) $turnos .= ' y ' . substr($h['abre_2'], 0, 5) . ' a ' . substr($h['cierra_2'], 0, 5);

        return "Los $dia la escuela abre de $turnos. Esa clase queda fuera, o se cruza con el cierre de mediodía.";
    }

    public function ocupacionCompleta(): array
    {
        $sql = "SELECT ho.id_ocupado, ho.dia_semana, ho.hora_inicio, ho.hora_fin,
                       COALESCE(s.nombre, 'En línea') AS salon, ho.id_salon, ins.modalidad,
                       m.nombre AS maestro_nombre, m.apellido AS maestro_apellido,
                       a.nombre AS alumno_nombre, a.apellido AS alumno_apellido,
                       COALESCE(i.nombre,'Por asignar') AS instrumento, ho.id_inscripcion,
                       ins.duracion_min, ins.id_instrumento, ins.id_alumno, ho.id_maestro,
                       ins.costo_mensual,
                       (SELECT GROUP_CONCAT(CONCAT_WS(' ', a2.nombre, a2.apellido) SEPARATOR ' y ')
                          FROM inscripciones c
                          JOIN alumnos a2 ON a2.id_alumno = c.id_alumno
                         WHERE c.comparte_con = ins.id_inscripcion AND c.estado <> 'baja') AS acompanantes,
                       (SELECT GROUP_CONCAT(CONCAT_WS(' ', a3.nombre, a3.apellido) SEPARATOR '||')
                          FROM inscripciones c3
                          JOIN alumnos a3 ON a3.id_alumno = c3.id_alumno
                         WHERE c3.comparte_con = ins.id_inscripcion AND c3.estado <> 'baja') AS lista_grupo,
                       -- Lo mismo, pero con nombre y apellido separados (nombre~apellido): la rejilla
                       -- abrevia con el primer nombre y el primer apellido, no con iniciales sueltas.
                       (SELECT GROUP_CONCAT(CONCAT(a4.nombre, '~', a4.apellido) SEPARATOR '||')
                          FROM inscripciones c4
                          JOIN alumnos a4 ON a4.id_alumno = c4.id_alumno
                         WHERE c4.comparte_con = ins.id_inscripcion AND c4.estado <> 'baja') AS lista_grupo_p,
                       1 + (SELECT COUNT(*) FROM inscripciones c2
                             WHERE c2.comparte_con = ins.id_inscripcion AND c2.estado <> 'baja') AS en_clase,
                       1 + (SELECT COUNT(*) FROM inscripciones c2
                             WHERE c2.comparte_con = ins.id_inscripcion AND c2.estado <> 'baja') AS en_grupo,
                       COALESCE(i.es_grupal, 0) AS es_grupal, s.capacidad,
                       -- Grupo operativo: Dibujo y Pintura van juntas en la rejilla.
                       COALESCE(g.nombre, i.nombre) AS grupo_nombre,
                       CASE WHEN i.es_grupal = 1
                            THEN COALESCE(CONCAT('g', i.id_grupo_materia), CONCAT('i', i.id_instrumento)) END AS clave_grupo
                FROM horario_ocupado ho
                LEFT JOIN salones s    ON s.id_salon = ho.id_salon
                JOIN maestros m        ON m.id_maestro = ho.id_maestro
                JOIN inscripciones ins ON ins.id_inscripcion = ho.id_inscripcion
                JOIN alumnos a         ON a.id_alumno = ins.id_alumno
                LEFT JOIN instrumentos i ON i.id_instrumento = ins.id_instrumento
                LEFT JOIN grupos_materia g ON g.id_grupo_materia = i.id_grupo_materia
                ORDER BY FIELD(ho.dia_semana,'Lunes','Martes','Miercoles','Jueves','Viernes','Sabado'), ho.hora_inicio";
        return $this->db->query($sql)->fetchAll();
    }

    public function ocupacionPorSalon(int $idSalon): array
    {
        $stmt = $this->db->prepare(
            "SELECT ho.*, COALESCE(s.nombre, 'En línea') AS salon, ins.modalidad,
                    m.nombre AS maestro_nombre, m.apellido AS maestro_apellido,
                    a.nombre AS alumno_nombre, a.apellido AS alumno_apellido,
                    COALESCE(i.nombre,'Por asignar') AS instrumento
             FROM horario_ocupado ho
             LEFT JOIN salones s    ON s.id_salon = ho.id_salon
             JOIN maestros m        ON m.id_maestro = ho.id_maestro
             JOIN inscripciones ins ON ins.id_inscripcion = ho.id_inscripcion
             JOIN alumnos a         ON a.id_alumno = ins.id_alumno
             LEFT JOIN instrumentos i ON i.id_instrumento = ins.id_instrumento
             WHERE ho.id_salon = ?
             ORDER BY FIELD(ho.dia_semana,'Lunes','Martes','Miercoles','Jueves','Viernes','Sabado'), ho.hora_inicio"
        );
        $stmt->execute([$idSalon]);
        return $stmt->fetchAll();
    }

    public function ocupacionPorMaestro(int $idMaestro): array
    {
        $stmt = $this->db->prepare(
            "SELECT ho.*, COALESCE(s.nombre, 'En línea') AS salon, ins.modalidad,
                    a.nombre AS alumno_nombre, a.apellido AS alumno_apellido,
                    COALESCE(i.nombre,'Por asignar') AS instrumento
             FROM horario_ocupado ho
             LEFT JOIN salones s    ON s.id_salon = ho.id_salon
             JOIN inscripciones ins ON ins.id_inscripcion = ho.id_inscripcion
             JOIN alumnos a         ON a.id_alumno = ins.id_alumno
             LEFT JOIN instrumentos i ON i.id_instrumento = ins.id_instrumento
             WHERE ho.id_maestro = ?
             ORDER BY FIELD(ho.dia_semana,'Lunes','Martes','Miercoles','Jueves','Viernes','Sabado'), ho.hora_inicio"
        );
        $stmt->execute([$idMaestro]);
        return $stmt->fetchAll();
    }

    /**
     * Busca un traslape real, no solo una coincidencia de hora de inicio.
     * Con clases de media hora, 10:00-11:00 y 10:30-11:30 se pisan aunque
     * empiecen distinto: dos rangos se cruzan si cada uno empieza antes de
     * que termine el otro.
     *
     * @param string $campo 'id_salon' o 'id_maestro'
     * @param int|null $ignorar inscripción a excluir (al mover una clase ya existente)
     */
    private function traslape(string $campo, int $id, string $dia, string $inicio, string $fin, ?int $ignorar = null,
                              ?array $grupo = null): ?array
    {
        // LEFT JOIN: una clase en línea no tiene salón, pero sí ocupa al maestro.
        $sql = "SELECT ho.*, a.nombre AS alumno_nombre, a.apellido AS alumno_apellido,
                       COALESCE(s.nombre, 'línea') AS salon, m.nombre AS maestro_nombre, m.apellido AS maestro_apellido
                  FROM horario_ocupado ho
                  JOIN inscripciones ins ON ins.id_inscripcion = ho.id_inscripcion
                  JOIN alumnos a  ON a.id_alumno = ins.id_alumno
                  LEFT JOIN salones s ON s.id_salon = ho.id_salon
                  JOIN maestros m ON m.id_maestro = ho.id_maestro
                 WHERE ho.$campo = ? AND ho.dia_semana = ?
                   AND ho.hora_inicio < ? AND ho.hora_fin > ?";
        $par = [$id, $dia, $fin, $inicio];
        if ($ignorar) { $sql .= " AND ho.id_inscripcion <> ?"; $par[] = $ignorar; }
        // Las clases del mismo grupo (misma materia operativa, maestro y salón)
        // son una sola sesión del grupo: llegar a otra hora no las hace chocar.
        if ($grupo && $grupo['materias']) {
            $marcas = implode(',', array_fill(0, count($grupo['materias']), '?'));
            $sql .= " AND NOT (ho.id_maestro = ? AND ho.id_salon <=> ? AND ins.id_instrumento IN ($marcas))";
            array_push($par, $grupo['id_maestro'], $grupo['id_salon'], ...$grupo['materias']);
        }
        $sql .= " LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($par);
        return $stmt->fetch() ?: null;
    }

    /**
     * ¿Hay una clase de ese maestro, en ese salón, exactamente ese día y a esa
     * hora? Devuelve su inscripción. Sirve para las clases compartidas: dos
     * alumnos que entran juntos tienen que coincidir por completo, no a medias.
     */
    public function mismoHueco(?int $idSalon, int $idMaestro, string $dia, string $inicio, string $fin): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT id_inscripcion FROM horario_ocupado
              WHERE id_salon <=> ? AND id_maestro = ? AND dia_semana = ?
                AND hora_inicio = ? AND hora_fin = ? LIMIT 1"
        );
        $stmt->execute([$idSalon, $idMaestro, $dia, $inicio, $fin]);
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    }

    /**
     * Las materias del mismo grupo operativo que ésta, incluida ella: Dibujo
     * y Pintura son la misma clase en la escuela. Una materia grupal sin grupo
     * operativo forma grupo sola. Vacío si la materia no es grupal.
     *
     * @return int[]
     */
    public function materiasDelGrupo(?int $idInstrumento): array
    {
        if (!$idInstrumento || !$this->esGrupal($idInstrumento)) return [];
        $s = $this->db->prepare(
            "SELECT i2.id_instrumento
               FROM instrumentos i
               JOIN instrumentos i2 ON i2.id_instrumento = i.id_instrumento
                    OR (i.id_grupo_materia IS NOT NULL AND i2.id_grupo_materia = i.id_grupo_materia)
              WHERE i.id_instrumento = ? AND i2.es_grupal = 1
              ORDER BY i2.id_instrumento"
        );
        $s->execute([$idInstrumento]);
        return array_values(array_unique(array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN))));
    }

    /** "Dibujo y Pintura": el nombre del grupo operativo, o el de la materia */
    public function nombreDelGrupo(?int $idInstrumento): ?string
    {
        if (!$idInstrumento) return null;
        $s = $this->db->prepare(
            "SELECT COALESCE(g.nombre, i.nombre) FROM instrumentos i
               LEFT JOIN grupos_materia g ON g.id_grupo_materia = i.id_grupo_materia
              WHERE i.id_instrumento = ?"
        );
        $s->execute([$idInstrumento]);
        $n = $s->fetchColumn();
        return $n === false ? null : (string) $n;
    }

    /**
     * Cuántos alumnos del grupo están en el salón A LA VEZ en algún momento de
     * [inicio, fin): los que llegan a otra hora solo cuentan mientras se
     * enciman. Es lo que se compara contra la capacidad del salón.
     *
     * @param int|null $ignorar la inscripción que se está moviendo (no se cuenta)
     */
    public function presentesALaVez(int $idSalon, int $idMaestro, array $materias, string $dia,
                                    string $inicio, string $fin, ?int $ignorar = null): int
    {
        if (!$materias) return 0;
        $marcas = implode(',', array_fill(0, count($materias), '?'));
        $s = $this->db->prepare(
            "SELECT ho.hora_inicio, ho.hora_fin,
                    (ho.id_inscripcion <> COALESCE(?, 0))
                    + (SELECT COUNT(*) FROM inscripciones c
                        WHERE c.comparte_con = ho.id_inscripcion AND c.estado <> 'baja'
                          AND c.id_inscripcion <> COALESCE(?, 0)) AS personas
               FROM horario_ocupado ho
               JOIN inscripciones ins ON ins.id_inscripcion = ho.id_inscripcion
              WHERE ho.id_salon = ? AND ho.id_maestro = ? AND ho.dia_semana = ?
                AND ins.id_instrumento IN ($marcas)
                AND ho.hora_inicio < ? AND ho.hora_fin > ?"
        );
        $s->execute(array_merge([$ignorar, $ignorar, $idSalon, $idMaestro, $dia], $materias, [$fin, $inicio]));
        $filas = $s->fetchAll();

        // El máximo se da siempre en el arranque de alguna clase (o en el inicio pedido).
        $puntos = [$inicio];
        foreach ($filas as $f) if ($f['hora_inicio'] > $inicio) $puntos[] = $f['hora_inicio'];
        $max = 0;
        foreach ($puntos as $t) {
            $n = 0;
            foreach ($filas as $f) if ($f['hora_inicio'] <= $t && $f['hora_fin'] > $t) $n += (int) $f['personas'];
            $max = max($max, $n);
        }
        return $max;
    }

    /** Cuántos alumnos tiene el grupo ese día con ese maestro, lleguen a la hora que lleguen */
    public function alumnosDelGrupoEnElDia(int $idMaestro, array $materias, string $dia, ?int $ignorar = null): int
    {
        if (!$materias) return 0;
        $marcas = implode(',', array_fill(0, count($materias), '?'));
        $s = $this->db->prepare(
            "SELECT COUNT(*) FROM inscripciones
              WHERE id_maestro = ? AND dia_semana = ? AND estado = 'activa' AND modalidad = 'presencial'
                AND id_instrumento IN ($marcas) AND id_inscripcion <> COALESCE(?, 0)"
        );
        $s->execute(array_merge([$idMaestro, $dia], $materias, [$ignorar]));
        return (int) $s->fetchColumn();
    }

    /**
     * El grupo de un día: todos los alumnos de esa materia operativa con ese
     * maestro ese día, cada uno con su propio horario. Es lo que se ve al
     * abrir el grupo en Horarios.
     */
    public function grupoDelDia(int $idInscripcion): ?array
    {
        $s = $this->db->prepare("SELECT id_maestro, id_instrumento, dia_semana FROM inscripciones WHERE id_inscripcion = ?");
        $s->execute([$idInscripcion]);
        $base = $s->fetch();
        if (!$base || !$base['dia_semana']) return null;
        $materias = $this->materiasDelGrupo($base['id_instrumento'] ? (int) $base['id_instrumento'] : null);
        if (!$materias) return null;

        $marcas = implode(',', array_fill(0, count($materias), '?'));
        $a = $this->db->prepare(
            "SELECT ins.id_inscripcion, ins.id_alumno, ins.id_maestro, ins.id_instrumento, ins.id_salon, ins.modalidad,
                    ins.dia_semana, ins.hora_inicio, ins.hora_fin, ins.duracion_min, ins.costo_mensual,
                    ins.comparte_con, a.nombre AS alumno_nombre, a.apellido AS alumno_apellido,
                    i.nombre AS instrumento, s.nombre AS salon, s.capacidad,
                    m.nombre AS maestro_nombre, m.apellido AS maestro_apellido
               FROM inscripciones ins
               JOIN alumnos a ON a.id_alumno = ins.id_alumno
               JOIN instrumentos i ON i.id_instrumento = ins.id_instrumento
               JOIN maestros m ON m.id_maestro = ins.id_maestro
               LEFT JOIN salones s ON s.id_salon = ins.id_salon
              WHERE ins.id_maestro = ? AND ins.dia_semana = ? AND ins.estado = 'activa'
                AND ins.modalidad = 'presencial' AND ins.id_instrumento IN ($marcas)
              ORDER BY ins.hora_inicio, a.apellido, a.nombre"
        );
        $a->execute(array_merge([(int) $base['id_maestro'], $base['dia_semana']], $materias));
        return [
            'grupo'      => $this->nombreDelGrupo((int) $base['id_instrumento']),
            'dia_semana' => $base['dia_semana'],
            'id_maestro' => (int) $base['id_maestro'],
            'materias'   => $materias,
            'alumnos'    => $a->fetchAll(),
        ];
    }

    /**
     * ¿La clase de esa inscripción es del taller (materia que se da en grupo)?
     * Es el valor de horario_ocupado.en_taller: 1 si la materia es grupal.
     * Toda escritura en horario_ocupado lo pone con esto, ya con la
     * inscripción guardada.
     */
    public function enTallerDe(int $idInscripcion): int
    {
        $s = $this->db->prepare(
            "SELECT COALESCE(i.es_grupal, 0) FROM inscripciones ins
               LEFT JOIN instrumentos i ON i.id_instrumento = ins.id_instrumento
              WHERE ins.id_inscripcion = ?"
        );
        $s->execute([$idInscripcion]);
        return (int) $s->fetchColumn() === 1 ? 1 : 0;
    }

    /**
     * Clases de la materia (las mismas filas de horario que el UPDATE de cambiarTaller va a tocar) que se encimarían con otra clase (del mismo
     * maestro o del mismo salón, ese día) si la materia dejara de ser del
     * taller: dentro del taller encimarse es lo normal, fuera es un choque.
     * Cada par sale una vez, con lo que hace falta para explicarlo.
     */
    public function encimadasDeMateria(int $idInstrumento): array
    {
        $s = $this->db->prepare(
            "SELECT a.dia_semana, a.hora_inicio AS a_inicio, a.hora_fin AS a_fin,
                    b.hora_inicio AS b_inicio, b.hora_fin AS b_fin,
                    CONCAT(aa.nombre, ' ', aa.apellido) AS a_alumno, CONCAT(ab.nombre, ' ', ab.apellido) AS b_alumno
               FROM horario_ocupado a
               JOIN inscripciones ia ON ia.id_inscripcion = a.id_inscripcion
               JOIN alumnos aa ON aa.id_alumno = ia.id_alumno
               JOIN horario_ocupado b ON b.id_ocupado <> a.id_ocupado AND b.dia_semana = a.dia_semana
                    AND (b.id_maestro = a.id_maestro OR (a.id_salon IS NOT NULL AND b.id_salon = a.id_salon))
                    AND a.hora_inicio < b.hora_fin AND a.hora_fin > b.hora_inicio
               JOIN inscripciones ib ON ib.id_inscripcion = b.id_inscripcion
               JOIN alumnos ab ON ab.id_alumno = ib.id_alumno
              WHERE ia.id_instrumento = ?
                AND (ib.id_instrumento IS NULL OR ib.id_instrumento <> ia.id_instrumento OR a.id_ocupado < b.id_ocupado)
              ORDER BY FIELD(a.dia_semana,'Lunes','Martes','Miercoles','Jueves','Viernes','Sabado'), a.hora_inicio, b.hora_inicio
              LIMIT 20"
        );
        $s->execute([$idInstrumento]);
        return $s->fetchAll();
    }

    /**
     * Cambia si una materia se da en grupo (taller) y deja al día la marca
     * en_taller de todas sus clases, todo o nada. Al quitarlo se niega si esas
     * clases se enciman entre sí (o con las de otra materia del taller): fuera
     * del taller serían choques, y habría que moverlas primero.
     *
     * @throws Exception con el motivo en lenguaje llano
     */
    public function cambiarTaller(int $idInstrumento, bool $grupal): void
    {
        self::conCandado(fn() => $this->cambiarTallerAdentro($idInstrumento, $grupal));
    }

    private function cambiarTallerAdentro(int $idInstrumento, bool $grupal): void
    {
        $s = $this->db->prepare("SELECT nombre, es_grupal FROM instrumentos WHERE id_instrumento = ?");
        $s->execute([$idInstrumento]);
        $m = $s->fetch();
        if (!$m) throw new Exception('Esa materia no existe.');
        $eraGrupal = (int) $m['es_grupal'] === 1;

        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            if ($eraGrupal && !$grupal) {
                $dias = ['Lunes' => 'lun', 'Martes' => 'mar', 'Miercoles' => 'mié', 'Jueves' => 'jue', 'Viernes' => 'vie', 'Sabado' => 'sáb'];
                $pares = $this->encimadasDeMateria($idInstrumento);
                if ($pares) {
                    $txt = array_map(fn($p) => sprintf('%s (%s %s–%s) y %s (%s–%s)', $p['a_alumno'],
                        $dias[$p['dia_semana']] ?? $p['dia_semana'], substr($p['a_inicio'], 0, 5), substr($p['a_fin'], 0, 5),
                        $p['b_alumno'], substr($p['b_inicio'], 0, 5), substr($p['b_fin'], 0, 5)), array_slice($pares, 0, 3));
                    throw new Exception(sprintf(
                        'No se puede quitar «Se da en grupo» a %s: %s%s están en el salón (o con el maestro) a la vez. '
                        . 'Muévelas primero.', $m['nombre'], implode('; ', $txt), count($pares) > 3 ? ' y más' : ''));
                }
                // Los que entraron a la misma hora exacta van juntos (comparte_con); fuera
                // del taller eso es una clase compartida, que es de máximo 2 alumnos.
                $g = $this->db->prepare(
                    "SELECT CONCAT(a.nombre, ' ', a.apellido) AS alumno, t.dia_semana, t.hora_inicio, t.hora_fin,
                            1 + (SELECT COUNT(*) FROM inscripciones c WHERE c.comparte_con = t.id_inscripcion AND c.estado <> 'baja') AS personas
                       FROM inscripciones t JOIN alumnos a ON a.id_alumno = t.id_alumno
                      WHERE t.id_instrumento = ? AND t.estado <> 'baja' AND t.comparte_con IS NULL
                     HAVING personas > 2 LIMIT 1"
                );
                $g->execute([$idInstrumento]);
                if ($x = $g->fetch()) {
                    throw new Exception(sprintf(
                        'No se puede quitar «Se da en grupo» a %s: con %s (%s %s–%s) entran %d alumnos a la misma hora, '
                        . 'y una clase compartida es de máximo 2. Muévelos primero.', $m['nombre'], $x['alumno'],
                        $dias[$x['dia_semana']] ?? $x['dia_semana'], substr($x['hora_inicio'], 0, 5),
                        substr($x['hora_fin'], 0, 5), (int) $x['personas']));
                }

                // Dibujo y Pintura entran juntas a la misma hora (comparte_con) aunque sean
                // materias distintas. Si solo una deja de ser del taller, esa unión pasaría a
                // ser una «compartida» que mezcla una materia del taller con otra que no lo
                // es: no se permite. Se ven las dos direcciones: alumnos de esta materia
                // unidos a una titular de la otra, y titulares de esta con integrantes de la otra.
                $mezcla = $this->db->prepare(
                    "SELECT CONCAT_WS(' ', a.nombre, a.apellido) AS integrante, ii.nombre AS materia_i,
                            CONCAT_WS(' ', ta.nombre, ta.apellido) AS titular, it.nombre AS materia_t,
                            t.dia_semana, t.hora_inicio, t.hora_fin
                       FROM inscripciones i
                       JOIN inscripciones t ON t.id_inscripcion = i.comparte_con
                       JOIN alumnos a ON a.id_alumno = i.id_alumno
                       JOIN alumnos ta ON ta.id_alumno = t.id_alumno
                       JOIN instrumentos ii ON ii.id_instrumento = i.id_instrumento
                       JOIN instrumentos it ON it.id_instrumento = t.id_instrumento
                      WHERE i.estado <> 'baja' AND t.estado <> 'baja'
                        AND ((i.id_instrumento = ? AND it.es_grupal = 1 AND t.id_instrumento <> ?)
                          OR (t.id_instrumento = ? AND ii.es_grupal = 1 AND i.id_instrumento <> ?))
                      ORDER BY t.id_inscripcion, i.id_inscripcion"
                );
                $mezcla->execute([$idInstrumento, $idInstrumento, $idInstrumento, $idInstrumento]);
                $mezclas = $mezcla->fetchAll();
                if ($mezclas) {
                    $txt = array_map(fn($x) => sprintf('%s (%s) con %s (%s), %s %s–%s', $x['integrante'], $x['materia_i'],
                        $x['titular'], $x['materia_t'], $dias[$x['dia_semana']] ?? $x['dia_semana'],
                        substr($x['hora_inicio'], 0, 5), substr($x['hora_fin'], 0, 5)), $mezclas);
                    throw new Exception(sprintf(
                        'No se puede quitar «Se da en grupo» a %s: estas clases entran juntas a la misma hora con otra materia del taller: %s. '
                        . 'Fuera del taller una clase compartida no mezcla materias. Sepáralas primero.',
                        $m['nombre'], self::listaCorta($txt, 3, '; ')));
                }
            }
            $this->db->prepare("UPDATE instrumentos SET es_grupal = ? WHERE id_instrumento = ?")
                     ->execute([$grupal ? 1 : 0, $idInstrumento]);
            $this->db->prepare(
                "UPDATE horario_ocupado ho
                   JOIN inscripciones ins ON ins.id_inscripcion = ho.id_inscripcion
                    SET ho.en_taller = ?
                  WHERE ins.id_instrumento = ?"
            )->execute([$grupal ? 1 : 0, $idInstrumento]);
            if ($propia) $this->db->commit();
        } catch (Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * ¿Cabe un alumno más del grupo en ese horario? Sin choques reales (con
     * cualquier cosa que no sea el mismo grupo) y sin pasar el cupo del salón
     * contando a los que están a la vez. Empezar a la misma hora que otro con
     * distinta duración no estorba: es un taller.
     */
    public function lugarParaGrupo(int $idSalon, int $idMaestro, int $idInstrumento, string $dia,
                                   string $inicio, string $fin, ?int $ignorar = null): bool
    {
        $materias = $this->materiasDelGrupo($idInstrumento);
        if (!$materias) return false;
        $ctx = ['materias' => $materias, 'id_maestro' => $idMaestro, 'id_salon' => $idSalon];
        if ($this->choque($idSalon, $idMaestro, $dia, $inicio, $fin, $ignorar, $ctx)) return false;
        return $this->presentesALaVez($idSalon, $idMaestro, $materias, $dia, $inicio, $fin, $ignorar)
             < $this->capacidadSalon($idSalon);
    }

    /**
     * Clases de un maestro con las que otra se puede juntar en una compartida
     * (no de grupo), a cualquier hora: al juntarlas, las dos toman el mismo
     * horario. Dice cuántas personas hay ya en cada una.
     */
    public function juntables(int $idMaestro, ?int $ignorar = null): array
    {
        $s = $this->db->prepare(
            "SELECT ins.id_inscripcion, a.nombre AS alumno_nombre, a.apellido AS alumno_apellido,
                    COALESCE(i.nombre, 'Por asignar') AS instrumento, ins.dia_semana, ins.hora_inicio, ins.hora_fin,
                    ins.duracion_min, ins.id_salon, ins.modalidad, ins.comparte_con,
                    1 + (SELECT COUNT(*) FROM inscripciones c WHERE c.comparte_con = ins.id_inscripcion
                          AND c.estado <> 'baja' AND c.id_inscripcion <> COALESCE(?, 0)) AS en_clase
               FROM inscripciones ins
               JOIN alumnos a ON a.id_alumno = ins.id_alumno
               LEFT JOIN instrumentos i ON i.id_instrumento = ins.id_instrumento
              WHERE ins.id_maestro = ? AND ins.estado = 'activa' AND COALESCE(i.es_grupal, 0) = 0
                AND ins.id_inscripcion <> COALESCE(?, 0)
              ORDER BY FIELD(ins.dia_semana,'Lunes','Martes','Miercoles','Jueves','Viernes','Sabado'), ins.hora_inicio"
        );
        $s->execute([$ignorar, $idMaestro, $ignorar]);
        return $s->fetchAll();
    }

    /**
     * Quién ocupa ese horario (en el salón o con el maestro), para ofrecer
     * intercambiar en lugar de solo decir que está ocupado.
     */
    public function ocupantes(?int $idSalon, int $idMaestro, string $dia, string $inicio, string $fin, ?int $ignorar = null): array
    {
        $s = $this->db->prepare(
            "SELECT ho.id_inscripcion, ho.hora_inicio, ho.hora_fin, ho.id_salon, ho.id_maestro,
                    ins.duracion_min, ins.id_instrumento, a.nombre AS alumno_nombre, a.apellido AS alumno_apellido,
                    COALESCE(i.es_grupal, 0) AS es_grupal,
                    (SELECT COUNT(*) FROM inscripciones c WHERE c.comparte_con = ho.id_inscripcion AND c.estado <> 'baja') AS acompanantes
               FROM horario_ocupado ho
               JOIN inscripciones ins ON ins.id_inscripcion = ho.id_inscripcion
               JOIN alumnos a ON a.id_alumno = ins.id_alumno
               LEFT JOIN instrumentos i ON i.id_instrumento = ins.id_instrumento
              WHERE ho.dia_semana = ? AND ho.hora_inicio < ? AND ho.hora_fin > ?
                AND (ho.id_maestro = ? OR (? IS NOT NULL AND ho.id_salon = ?))
                AND ho.id_inscripcion <> COALESCE(?, 0)"
        );
        $s->execute([$dia, $fin, $inicio, $idMaestro, $idSalon, $idSalon, $ignorar]);
        return $s->fetchAll();
    }

    /** La materia de una inscripción (null si aún no tiene) */
    public function materiaDe(int $idInscripcion): ?int
    {
        $s = $this->db->prepare("SELECT id_instrumento FROM inscripciones WHERE id_inscripcion = ?");
        $s->execute([$idInscripcion]);
        $id = $s->fetchColumn();
        return $id ? (int) $id : null;
    }

    /** ¿La materia se da en grupo? (dibujo, pintura) */
    public function esGrupal(?int $idInstrumento): bool
    {
        if (!$idInstrumento) return false;
        $s = $this->db->prepare("SELECT es_grupal FROM instrumentos WHERE id_instrumento = ?");
        $s->execute([$idInstrumento]);
        return (int) $s->fetchColumn() === 1;
    }

    /**
     * El grupo que ya existe en ese hueco para esa materia: mismo maestro,
     * mismo salón, mismo día y hora exacta, misma materia grupal.
     */
    public function grupoEnHueco(int $idSalon, int $idMaestro, array $materias,
                                 string $dia, string $inicio, string $fin): ?int
    {
        if (!$materias) return null;
        $marcas = implode(',', array_fill(0, count($materias), '?'));
        $s = $this->db->prepare(
            "SELECT ho.id_inscripcion FROM horario_ocupado ho
               JOIN inscripciones ins ON ins.id_inscripcion = ho.id_inscripcion
              WHERE ho.id_salon = ? AND ho.id_maestro = ? AND ho.dia_semana = ?
                AND ho.hora_inicio = ? AND ho.hora_fin = ? AND ins.id_instrumento IN ($marcas)
              LIMIT 1"
        );
        $s->execute(array_merge([$idSalon, $idMaestro, $dia, $inicio, $fin], $materias));
        $id = $s->fetchColumn();
        return $id ? (int) $id : null;
    }

    /** Cuántos alumnos hay ya en el grupo (el titular más los que comparten) */
    public function tamanoGrupo(int $idTitular): int
    {
        $s = $this->db->prepare(
            "SELECT 1 + (SELECT COUNT(*) FROM inscripciones WHERE comparte_con = ? AND estado <> 'baja')"
        );
        $s->execute([$idTitular]);
        return (int) $s->fetchColumn();
    }

    /** Cuántos pueden entrar a una clase en línea (regla propia; nunca más de 2) */
    public function cupoOnline(): int
    {
        $v = $this->db->query("SELECT valor FROM configuracion WHERE clave = 'cupo_online'")->fetchColumn();
        return max(1, min(2, (int) ($v ?: 1)));
    }

    /** La titular del horario físico de una inscripción: ella misma o con quien comparte */
    public function titularDe(int $idInscripcion): ?int
    {
        $s = $this->db->prepare("SELECT id_inscripcion, comparte_con FROM inscripciones WHERE id_inscripcion = ?");
        $s->execute([$idInscripcion]);
        $f = $s->fetch();
        if (!$f) return null;
        return $f['comparte_con'] ? (int) $f['comparte_con'] : (int) $f['id_inscripcion'];
    }

    /** Integrantes vivas que dependen de una titular, de la más antigua a la más nueva */
    public function integrantes(int $idTitular): array
    {
        $s = $this->db->prepare(
            "SELECT id_inscripcion FROM inscripciones
              WHERE comparte_con = ? AND estado <> 'baja' ORDER BY id_inscripcion"
        );
        $s->execute([$idTitular]);
        return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Clases con las que se puede compartir: las titulares de ese hueco exacto
     * (mismo maestro, salón, día, inicio y fin), con cuántos alumnos llevan.
     * Una clase grupal no aparece aquí: a un grupo se entra por su materia.
     */
    public function clasesParaCompartir(?int $idSalon, int $idMaestro, string $dia, string $inicio, string $fin,
                                         ?int $ignorar = null): array
    {
        $s = $this->db->prepare(
            "SELECT ho.id_inscripcion, a.nombre AS alumno_nombre, a.apellido AS alumno_apellido,
                    COALESCE(i.nombre, 'Por asignar') AS instrumento, ins.modalidad,
                    COALESCE(i.es_grupal, 0) AS es_grupal,
                    1 + (SELECT COUNT(*) FROM inscripciones c
                          WHERE c.comparte_con = ho.id_inscripcion AND c.estado <> 'baja'
                            AND c.id_inscripcion <> COALESCE(?, 0)) AS en_clase
               FROM horario_ocupado ho
               JOIN inscripciones ins ON ins.id_inscripcion = ho.id_inscripcion
               JOIN alumnos a ON a.id_alumno = ins.id_alumno
               LEFT JOIN instrumentos i ON i.id_instrumento = ins.id_instrumento
              WHERE ho.id_salon <=> ? AND ho.id_maestro = ? AND ho.dia_semana = ?
                AND ho.hora_inicio = ? AND ho.hora_fin = ? AND ho.id_inscripcion <> COALESCE(?, 0)"
        );
        $s->execute([$ignorar, $idSalon, $idMaestro, $dia, $inicio, $fin, $ignorar]);
        return $s->fetchAll();
    }

    public function capacidadSalon(int $idSalon): int
    {
        $s = $this->db->prepare("SELECT capacidad FROM salones WHERE id_salon = ?");
        $s->execute([$idSalon]);
        return max(1, (int) $s->fetchColumn());
    }

    /**
     * Devuelve el motivo del choque, o null si el espacio está libre. Sin salón
     * (clase en línea) solo se revisa al maestro: no bloquea ningún salón, pero
     * el maestro no puede estar en dos clases a la vez, sean en línea o no.
     */
    public function choque(?int $idSalon, int $idMaestro, string $dia, string $inicio, string $fin, ?int $ignorar = null,
                           ?array $grupo = null): ?string
    {
        $enSalon = $idSalon ? $this->traslape('id_salon', $idSalon, $dia, $inicio, $fin, $ignorar, $grupo) : null;
        if ($enSalon) {
            return sprintf('En %s ya hay clase ese %s de %s a %s: %s %s con %s %s.',
                $enSalon['salon'], $dia, substr($enSalon['hora_inicio'], 0, 5), substr($enSalon['hora_fin'], 0, 5),
                $enSalon['alumno_nombre'], $enSalon['alumno_apellido'],
                $enSalon['maestro_nombre'], $enSalon['maestro_apellido']);
        }

        $conMaestro = $this->traslape('id_maestro', $idMaestro, $dia, $inicio, $fin, $ignorar, $grupo);
        if ($conMaestro) {
            return sprintf('El maestro ya tiene clase ese %s de %s a %s con %s %s en %s. '
                         . 'Tampoco puede dar dos clases a la vez aunque una sea en línea.',
                $dia, substr($conMaestro['hora_inicio'], 0, 5), substr($conMaestro['hora_fin'], 0, 5),
                $conMaestro['alumno_nombre'], $conMaestro['alumno_apellido'], $conMaestro['salon']);
        }
        return null;
    }

    /**
     * ¿El choque es contra una clase idéntica del mismo alumno con el mismo
     * maestro? Entonces no es un empalme: es la misma clase registrada dos
     * veces, y lo que hay que hacer es quitar la repetida, no mover nada.
     */
    public function claseRepetida(int $idAlumno, int $idMaestro, string $dia, string $inicio, ?int $ignorar = null): ?array
    {
        $sql = "SELECT ins.id_inscripcion, ins.estado, s.nombre AS salon
                  FROM inscripciones ins
                  LEFT JOIN salones s ON s.id_salon = ins.id_salon
                 WHERE ins.id_alumno = ? AND ins.id_maestro = ? AND ins.estado <> 'baja'
                   AND ins.dia_semana = ? AND ins.hora_inicio = ?";
        $par = [$idAlumno, $idMaestro, $dia, $inicio];
        if ($ignorar) { $sql .= " AND ins.id_inscripcion <> ?"; $par[] = $ignorar; }

        $stmt = $this->db->prepare($sql . " LIMIT 1");
        $stmt->execute($par);
        return $stmt->fetch() ?: null;
    }

    public function salonOcupado(int $idSalon, string $dia, string $horaInicio): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM horario_ocupado WHERE id_salon = ? AND dia_semana = ? AND hora_inicio = ?"
        );
        $stmt->execute([$idSalon, $dia, $horaInicio]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function maestroOcupado(int $idMaestro, string $dia, string $horaInicio): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM horario_ocupado WHERE id_maestro = ? AND dia_semana = ? AND hora_inicio = ?"
        );
        $stmt->execute([$idMaestro, $dia, $horaInicio]);
        return (int) $stmt->fetchColumn() > 0;
    }
}

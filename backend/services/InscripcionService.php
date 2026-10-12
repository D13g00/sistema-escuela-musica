<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../models/InscripcionModel.php';
require_once __DIR__ . '/../models/NominaModel.php';
require_once __DIR__ . '/../models/PagoModel.php';
require_once __DIR__ . '/../models/HorarioModel.php';
require_once __DIR__ . '/../models/AlumnoModel.php';
require_once __DIR__ . '/../models/MaestroModel.php';
require_once __DIR__ . '/../models/ConfiguracionModel.php';
require_once __DIR__ . '/WhatsAppService.php';
require_once __DIR__ . '/../lib/Calendario.php';
require_once __DIR__ . '/../models/CuentaModel.php';
require_once __DIR__ . '/../models/ListaEsperaModel.php';
require_once __DIR__ . '/../models/BorradorModel.php';
require_once __DIR__ . '/../lib/Validar.php';
require_once __DIR__ . '/../lib/Bitacora.php';
require_once __DIR__ . '/../models/HistorialClaseModel.php';

/**
 * InscripcionService: aquí vive la regla de negocio central del sistema.
 *
 * ALTA de un alumno en un instrumento con un maestro y horario:
 *   1) INSERT en inscripciones
 *   2) INSERT en horario_ocupado   -> bloquea el salón+horario y el maestro+horario
 *   3) INSERT en nomina_detalle    -> aparece en la nómina del maestro
 *   4) INSERT en pagos             -> se registra el pago inicial (si se indica)
 *   Todo dentro de UNA transacción: si algo falla, se revierte todo (ROLLBACK).
 *
 * CANDADO DE HORARIO: todo lo que valida un horario y lo escribe corre dentro
 * del candado 'horario' (HorarioModel::conCandado, GET_LOCK, reentrante): dos
 * personas que piden el mismo hueco a la vez se ponen en fila, y la segunda
 * ve el hueco ocupado en vez de duplicarlo.
 *
 * BAJA de una inscripción:
 *   1) UPDATE inscripciones (estado='baja', fecha_baja=hoy)  -- se conserva como historial
 *   2) DELETE en horario_ocupado   -> libera el espacio del salón y del maestro
 *   3) DELETE en nomina_detalle    -> desaparece de la nómina del maestro
 *   (los pagos históricos jamás se borran)
 */
class InscripcionService
{
    private PDO $db;
    private InscripcionModel $inscripcionModel;
    private NominaModel $nominaModel;
    private PagoModel $pagoModel;
    private HorarioModel $horarioModel;

    public function __construct()
    {
        $this->db = Database::conexion();
        $this->inscripcionModel = new InscripcionModel();
        $this->nominaModel = new NominaModel();
        $this->pagoModel = new PagoModel();
        $this->horarioModel = new HorarioModel();
    }

    /**
     * DÓNDE CAE UNA CLASE. Es la única autoridad para el alta y la edición:
     * decide si la clase va sola, si entra a un grupo o si comparte con otra,
     * y rechaza lo que no se puede. Nada de lo que diga el navegador sobre si
     * una materia es grupal cuenta: eso se lee de la base.
     *
     *   · Materias grupales (Dibujo y Pintura, un solo grupo operativo) son un
     *     TALLER: siempre presenciales, en el mismo salón y con el mismo maestro,
     *     y cada alumno entra y sale a su hora. Pueden llegar a horas distintas,
     *     encimarse e incluso empezar a la misma hora y durar distinto: nada de
     *     eso es choque (horario_ocupado.en_taller los deja fuera de las claves
     *     únicas de inicio). Sí lo es chocar con cualquier otra cosa (otra clase
     *     del maestro o del salón que no sea del taller), y pasar el cupo del
     *     salón contando a los que están a la vez. Si la hora es exactamente la
     *     de otra clase del grupo, entran juntas (comparte_con) con la
     *     titularidad de siempre.
     *   · Clase compartida: solo si se pide, contra una clase idéntica (maestro,
     *     salón, día, inicio y fin) y con máximo 2 alumnos. En línea, además,
     *     respeta el cupo de las clases en línea.
     *   · Si no, la clase va sola y el hueco tiene que estar libre: ni el salón
     *     ni el maestro pueden estar ocupados. En línea no ocupa salón, pero el
     *     maestro sí queda ocupado.
     *
     * @param array    $d        datos de la clase
     * @param int|null $ignorar  la inscripción que se está editando (no choca consigo misma)
     * @return array {tipo: individual|grupo|compartida, comparte_con, modalidad, id_salon,
     *                en_clase, cupo, mensaje}
     */
    public function resolverHueco(array $d, ?int $ignorar = null): array
    {
        $modalidad = ($d['modalidad'] ?? 'presencial') === 'online' ? 'online' : 'presencial';
        $idInstr   = !empty($d['id_instrumento']) ? (int) $d['id_instrumento'] : null;
        $grupal    = $this->horarioModel->esGrupal($idInstr);
        $idMaestro = (int) ($d['id_maestro'] ?? 0);
        $dia = (string) ($d['dia_semana'] ?? ''); $ini = (string) ($d['hora_inicio'] ?? ''); $fin = (string) ($d['hora_fin'] ?? '');

        if (!$idMaestro || $dia === '' || $ini === '' || $fin === '') {
            throw new Exception('Faltan el maestro, el día o la hora de la clase.');
        }
        if ($grupal && $modalidad === 'online') {
            throw new Exception('Pintura y dibujo son un taller en el salón: no pueden ser en línea.');
        }
        $idSalon = $modalidad === 'online' ? null : (!empty($d['id_salon']) ? (int) $d['id_salon'] : null);
        if ($modalidad === 'presencial' && !$idSalon) throw new Exception('Elige el salón de la clase.');

        // No se inscribe con un maestro o un salón desactivados. Solo aplica a lo que
        // se pone o se mueve (altas, cambios, reactivar): dar de baja una clase o
        // corregir su baja no pasa por aquí, así que siguen funcionando.
        $inactivo = $this->horarioModel->motivoDeInactivo($idMaestro, $idSalon);
        if ($inactivo) throw new Exception($inactivo);

        $fuera = $this->horarioModel->fueraDeHorario($dia, $ini, $fin);
        if ($fuera) throw new Exception($fuera);

        $idAlumno = (int) ($d['id_alumno'] ?? 0);
        $pideCompartir = !empty($d['compartir_con']) ? (int) $d['compartir_con'] : null;
        $base = ['modalidad' => $modalidad, 'id_salon' => $idSalon];
        // Al editar a una titular que se queda con integrantes, su lugar no se
        // libera: lo hereda otra. Quien llama lo indica con ocupacion_propia.
        $ignorarOcupacion = array_key_exists('ignorar_ocupacion', $d) ? $d['ignorar_ocupacion'] : $ignorar;

        // ------------------------------------------------ materias grupales
        if ($grupal) {
            $materias = $this->horarioModel->materiasDelGrupo($idInstr);
            $grupo = $this->horarioModel->nombreDelGrupo($idInstr);
            $ctx = ['materias' => $materias, 'id_maestro' => $idMaestro, 'id_salon' => $idSalon];

            // Un alumno no puede estar dos veces en el taller a la vez: si ya
            // tiene clase ese día con ese maestro y se encima, lo que quiere es
            // cambiarle la hora a la que ya tiene, no inscribirse otra vez.
            if ($idAlumno && $materias) {
                $marcas = implode(',', array_fill(0, count($materias), '?'));
                $yaTiene = $this->db->prepare(
                    "SELECT hora_inicio, hora_fin FROM inscripciones
                      WHERE id_alumno = ? AND estado <> 'baja' AND id_inscripcion <> COALESCE(?, 0)
                        AND id_maestro = ? AND dia_semana = ? AND id_instrumento IN ($marcas)
                        AND hora_inicio < ? AND hora_fin > ?
                      LIMIT 1"
                );
                $yaTiene->execute(array_merge([$idAlumno, $ignorar, $idMaestro, $dia], $materias, [$fin, $ini]));
                if ($otra = $yaTiene->fetch()) {
                    throw new Exception(sprintf(
                        'Ese alumno ya está en el taller ese día de %s a %s. Si cambió de horario, '
                        . 'edita su clase en vez de inscribirlo otra vez.',
                        substr($otra['hora_inicio'], 0, 5), substr($otra['hora_fin'], 0, 5)));
                }
            }

            $cupo = $this->horarioModel->capacidadSalon($idSalon);
            $titular = $this->horarioModel->grupoEnHueco($idSalon, $idMaestro, $materias, $dia, $ini, $fin);
            $delDia = $this->horarioModel->alumnosDelGrupoEnElDia($idMaestro, $materias, $dia, $ignorar);
            $info = ['grupo' => $grupo, 'alumnos_del_dia' => $delDia + 1];
            // ¿Ya era de este grupo ese día (se está cambiando solo su hora)?
            $yaEstaba = false;
            if ($ignorar) {
                $e = $this->db->prepare("SELECT id_maestro, dia_semana, id_instrumento, estado FROM inscripciones WHERE id_inscripcion = ?");
                $e->execute([$ignorar]);
                $antes = $e->fetch();
                $yaEstaba = $antes && $antes['estado'] === 'activa' && (int) $antes['id_maestro'] === $idMaestro
                         && $antes['dia_semana'] === $dia && in_array((int) $antes['id_instrumento'], $materias, true);
            }

            if ($titular && $titular === $ignorar) {
                // Es la titular y se queda donde está: el grupo sigue igual.
                return $base + $info + ['tipo' => 'individual', 'comparte_con' => null,
                                'en_clase' => $this->horarioModel->tamanoGrupo($titular), 'cupo' => $cupo,
                                'mensaje' => 'Sigue en su lugar del taller.'];
            }
            if ($titular && $pideCompartir && $this->horarioModel->titularDe($pideCompartir) !== $titular) {
                throw new Exception('Esa clase no es la del taller a esa hora.');
            }
            // Choques reales: con todo lo que no sea este mismo grupo.
            $choque = $this->horarioModel->choque($idSalon, $idMaestro, $dia, $ini, $fin, $ignorarOcupacion, $ctx);
            if ($choque) throw new Exception($choque);

            $aLaVez = $this->horarioModel->presentesALaVez($idSalon, $idMaestro, $materias, $dia, $ini, $fin, $ignorar);
            if ($aLaVez >= $cupo) {
                throw new Exception("El taller ya está lleno a esa hora: hay $aLaVez alumnos en el salón, "
                    . "que es para $cupo. Elige otra hora o anótalo en la lista de espera. "
                    . '(Si de verdad caben más, administración puede subir el cupo del salón.)');
            }
            if ($titular) {
                $this->comprobarQueNoEsteYa($idAlumno, $titular, $ignorar, 'Ese alumno ya está en ese taller.');
                return $base + $info + ['tipo' => 'grupo', 'comparte_con' => $titular, 'en_clase' => $aLaVez + 1,
                                'cupo' => $cupo,
                                'mensaje' => sprintf('Entra al taller de %s de ese día con ese maestro, a la misma hora que otro alumno: '
                                                     . 'serán %d alumnos ese día, hasta %d a la vez (cupo %d).',
                                                     $grupo, $delDia + 1, $aLaVez + 1, $cupo)];
            }
            return $base + $info + ['tipo' => 'individual', 'comparte_con' => null, 'en_clase' => $aLaVez + 1,
                            'cupo' => $cupo,
                            'mensaje' => $delDia > 0
                                ? sprintf('%s de %s de ese día con ese maestro: serán %d alumnos ese día, '
                                          . 'hasta %d a la vez (cupo %d).',
                                          $yaEstaba ? 'Sigue en el taller' : 'Entra al taller',
                                          $grupo, $delDia + 1, $aLaVez + 1, $cupo)
                                : sprintf('Abre el taller de %s de ese día con ese maestro.', $grupo)];
        }

        // ------------------------------------------------ clase compartida
        $pideLegado = !$pideCompartir && !empty($d['compartir']);
        if ($pideCompartir || $pideLegado) {
            $titular = $pideCompartir
                ? $this->horarioModel->titularDe($pideCompartir)
                : $this->horarioModel->mismoHueco($idSalon, $idMaestro, $dia, $ini, $fin);
            if (!$titular || $titular === $ignorar) {
                throw new Exception('No hay ninguna clase de ese maestro en ese salón, ese día y a esa hora exacta. '
                    . 'Una clase compartida tiene que coincidir por completo con la otra.');
            }
            $t = $this->db->prepare(
                "SELECT ins.estado, ins.modalidad, ins.id_instrumento, ho.id_salon, ho.id_maestro, ho.dia_semana,
                        ho.hora_inicio, ho.hora_fin
                   FROM inscripciones ins JOIN horario_ocupado ho ON ho.id_inscripcion = ins.id_inscripcion
                  WHERE ins.id_inscripcion = ?"
            );
            $t->execute([$titular]);
            $cl = $t->fetch();
            $coincide = $cl && $cl['estado'] === 'activa'
                && $cl['modalidad'] === $modalidad
                && (int) $cl['id_maestro'] === $idMaestro
                && ($cl['id_salon'] === null ? null : (int) $cl['id_salon']) === $idSalon
                && $cl['dia_semana'] === $dia
                && substr($cl['hora_inicio'], 0, 5) === substr($ini, 0, 5)
                && substr($cl['hora_fin'], 0, 5) === substr($fin, 0, 5);
            if (!$coincide) {
                throw new Exception('Esa clase no coincide con la que quieres compartir: tienen que ser el mismo '
                    . 'maestro, salón (o las dos en línea), día, hora de inicio y hora de fin.');
            }
            if ($this->horarioModel->esGrupal($cl['id_instrumento'] ? (int) $cl['id_instrumento'] : null)) {
                throw new Exception('Esa clase es del taller de pintura o dibujo: al taller se entra eligiendo su materia.');
            }
            $cupo = $modalidad === 'online' ? min(2, $this->horarioModel->cupoOnline()) : 2;
            $en = 1 + count(array_filter($this->horarioModel->integrantes($titular), fn($i) => $i !== $ignorar));
            if ($en >= $cupo) {
                throw new Exception($modalidad === 'online' && $cupo === 1
                    ? 'Las clases en línea son individuales: el cupo en línea está en 1 (se cambia en Ajustes).'
                    : 'Una clase compartida es de máximo 2 alumnos. Para más, tiene que ser una materia de grupo.');
            }
            $this->comprobarQueNoEsteYa($idAlumno, $titular, $ignorar, 'Ese alumno ya está en esa clase.');
            return $base + ['tipo' => 'compartida', 'comparte_con' => $titular, 'en_clase' => $en + 1, 'cupo' => $cupo,
                            'mensaje' => 'Compartirá la clase: serán 2 en la misma hora, cada uno con su nómina.'];
        }

        // ------------------------------------------------ clase individual
        $choque = $this->horarioModel->choque($idSalon, $idMaestro, $dia, $ini, $fin, $ignorarOcupacion);
        if ($choque) {
            $mismo = $this->horarioModel->mismoHueco($idSalon, $idMaestro, $dia, $ini, $fin);
            // Con una clase del taller no se comparte: al taller se entra con su materia.
            if ($mismo && !$this->horarioModel->esGrupal($this->horarioModel->materiaDe($mismo))) {
                $choque .= ' Si los dos entran juntos a esa misma clase, marca "clase compartida".';
            }
            throw new Exception($choque);
        }
        return $base + ['tipo' => 'individual', 'comparte_con' => null, 'en_clase' => 1, 'cupo' => 1,
                        'mensaje' => 'Clase individual.'];
    }

    /** El mismo alumno no puede estar dos veces en la misma clase o grupo */
    private function comprobarQueNoEsteYa(int $idAlumno, int $titular, ?int $ignorar, string $mensaje): void
    {
        if (!$idAlumno) return;
        $s = $this->db->prepare(
            "SELECT COUNT(*) FROM inscripciones
              WHERE id_alumno = ? AND estado <> 'baja' AND id_inscripcion <> COALESCE(?, 0)
                AND (id_inscripcion = ? OR comparte_con = ?)"
        );
        $s->execute([$idAlumno, $ignorar, $titular, $titular]);
        if ((int) $s->fetchColumn() > 0) throw new Exception($mensaje);
    }

    /**
     * La titular deja su lugar (baja o cambio de horario): la integrante más
     * antigua hereda la ocupación y las demás pasan a depender de ella. Así un
     * grupo o una clase compartida nunca se quedan sin horario ni apuntando a
     * una baja. Va dentro de la transacción de quien llama.
     *
     * @return int|null la nueva titular, o null si no había integrantes
     */
    private function promoverTitular(int $idTitular): ?int
    {
        $integrantes = $this->horarioModel->integrantes($idTitular);
        if (!$integrantes) return null;
        $nueva = array_shift($integrantes);

        $this->db->prepare("UPDATE horario_ocupado SET id_inscripcion = ? WHERE id_inscripcion = ?")
                 ->execute([$nueva, $idTitular]);
        $this->db->prepare("UPDATE inscripciones SET comparte_con = NULL WHERE id_inscripcion = ?")
                 ->execute([$nueva]);
        $this->db->prepare(
            "UPDATE inscripciones SET comparte_con = ?
              WHERE comparte_con = ? AND estado <> 'baja' AND id_inscripcion <> ?"
        )->execute([$nueva, $idTitular, $nueva]);
        return $nueva;
    }

    /**
     * Qué quedó libre cuando una clase deja su lugar: todo el horario (si iba
     * sola) o un lugar dentro de un grupo. Una compartida que pierde a uno de
     * sus dos alumnos no libera nada: el otro sigue ahí.
     */
    private function huecoQueDeja(array $ins, bool $eraTitular, int $integrantes): ?array
    {
        if (empty($ins['dia_semana']) || empty($ins['hora_inicio'])) return null;   // pendiente: no ocupaba nada
        $grupal = $this->horarioModel->esGrupal($ins['id_instrumento'] ? (int) $ins['id_instrumento'] : null);
        if (!$grupal && ($integrantes > 0 || !$eraTitular)) return null;
        return [
            'id_instrumento' => $ins['id_instrumento'] ? (int) $ins['id_instrumento'] : null,
            'id_maestro' => (int) $ins['id_maestro'],
            'id_salon'   => $ins['id_salon'] ? (int) $ins['id_salon'] : null,
            'modalidad'  => $ins['modalidad'] ?? 'presencial',
            'dia_semana' => $ins['dia_semana'],
            'hora_inicio'=> $ins['hora_inicio'],
            'hora_fin'   => $ins['hora_fin'],
            'grupal'     => $grupal,
            'libera'     => $grupal ? 'lugar' : 'horario',
        ];
    }

    /**
     * @param array $datos {id_alumno, id_maestro, id_instrumento, id_salon,
     *                      dia_semana, hora_inicio, hora_fin, costo_mensual,
     *                      registrar_pago_inicial (bool), metodo_pago, periodo}
     * @return array datos de la inscripción creada
     * @throws Exception si el horario ya está ocupado o falla la transacción
     */
    /**
     * Lo único que se acepta de la pantalla para un alta. Lo que decide el
     * servidor (con quién comparte en realidad, qué ocupación se ignora, la
     * duración, el mes, el recargo, quién la hizo) no se toma de la petición.
     */
    private static function datosDeAlta(array $d): array
    {
        $permitidos = ['id_alumno', 'alumno_nuevo', 'id_maestro', 'id_instrumento', 'id_salon', 'modalidad',
                       'dia_semana', 'hora_inicio', 'hora_fin', 'costo_mensual', 'fecha_inicio', 'compartir_con',
                       'compartir', 'cobrar_inscripcion', 'monto_inscripcion', 'registrar_pago_inicial', 'metodo_pago',
                       'id_borrador', 'id_lista_espera'];
        $d = array_intersect_key($d, array_flip($permitidos));
        $d['hora_inicio'] = Validar::hora($d['hora_inicio'] ?? '', 'de inicio');
        $d['hora_fin'] = Validar::hora($d['hora_fin'] ?? '', 'de fin');
        $d['duracion_min'] = Validar::minutosEntre($d['hora_inicio'], $d['hora_fin']);
        $d['id_instrumento'] = Validar::idOpcional($d['id_instrumento'] ?? null);
        $d['id_salon'] = Validar::idOpcional($d['id_salon'] ?? null);
        $d['id_maestro'] = (int) ($d['id_maestro'] ?? 0);
        $d['costo_mensual'] = Validar::dinero($d['costo_mensual'] ?? null, 'La colegiatura', 100000);
        $d['metodo_pago'] = Validar::metodo($d['metodo_pago'] ?? 'efectivo');
        // Un pago solo se registra si se pide: nunca por omisión.
        $d['registrar_pago_inicial'] = !empty($d['registrar_pago_inicial']);
        // La inscripción se cobra si la persona la marcó; el importe se valida aquí, antes de guardar nada.
        $d['cobrar_inscripcion'] = !empty($d['cobrar_inscripcion']);
        $d['monto_inscripcion'] = $d['cobrar_inscripcion']
            ? ConfiguracionModel::importeDeInscripcion($d['monto_inscripcion'] ?? null) : 0.0;
        return $d;
    }

    /** @param string|null $usuario quien la registra: queda como autor de sus pagos */
    public function darDeAlta(array $datos, ?string $usuario = null): array
    {
        return HorarioModel::conCandado(fn() => $this->darDeAltaAdentro($datos, $usuario));
    }

    private function darDeAltaAdentro(array $datos, ?string $usuario = null): array
    {
        $datos = self::datosDeAlta($datos);

        // Alumno nuevo: se valida aquí, pero se crea DENTRO de la transacción,
        // junto con su clase. Si la clase no se puede, no queda ningún alumno.
        $alumnoNuevo = null;
        if (empty($datos['id_alumno']) && is_array($datos['alumno_nuevo'] ?? null)) {
            $alumnoNuevo = $datos['alumno_nuevo'];
            if (trim((string) ($alumnoNuevo['nombre'] ?? '')) === '' || trim((string) ($alumnoNuevo['apellido'] ?? '')) === '') {
                throw new Exception('Escribe el nombre y el apellido del alumno nuevo.');
            }
            $datos['id_alumno'] = 0;
        }
        if (empty($datos['id_alumno']) && !$alumnoNuevo) throw new Exception('Falta el alumno.');

        // Dónde cae la clase: sola, en un grupo o compartida. Lo decide el
        // servidor; el navegador solo propone.
        $hueco = $this->resolverHueco($datos);
        $comparteCon = $hueco['comparte_con'];
        $datos['modalidad'] = $hueco['modalidad'];
        $datos['id_salon']  = $hueco['id_salon'];

        // Desde qué día toma clase. Si entra a medio mes, ese primer mes solo
        // paga (y al maestro solo se le pagan) las clases que le quedan.
        $datos['fecha_inicio'] = self::fechaDelMes($datos['fecha_inicio'] ?? null, 'de inicio', 62);
        $periodoInicio = substr($datos['fecha_inicio'], 0, 7);
        $config = ConfiguracionModel::paraPeriodo((new ConfiguracionModel())->todo(), $periodoInicio);
        $tramo = Calendario::tramo($periodoInicio, $datos['dia_semana'], $datos['fecha_inicio'], null,
                                   (int) $config['semanas_por_mes']);
        if ($tramo['clases'] === 0) {
            throw new Exception('Con esa fecha de inicio ya no le queda ninguna clase este mes. '
                . 'Ponle de inicio el primer día del mes que entra.');
        }

        $propia = $this->empezar();
        try {
            if ($alumnoNuevo) {
                $datos['id_alumno'] = (new AlumnoModel())->crear($alumnoNuevo);
            }

            // 1) Inscripción
            $idInscripcion = $this->inscripcionModel->insertar($datos + ['comparte_con' => $comparteCon]);

            // Si venía de un borrador o de la lista de espera, se cierran aquí,
            // en la misma transacción: si el alta falla, siguen abiertos.
            if (!empty($datos['id_borrador'])) {
                (new BorradorModel())->completar($this->db, (int) $datos['id_borrador'],
                                                 (int) $datos['id_alumno'], $idInscripcion);
            }
            if (!empty($datos['id_lista_espera'])) {
                (new ListaEsperaModel())->marcarInscrito($this->db, (int) $datos['id_lista_espera'], $idInscripcion);
            }

            // 2) Ocupar horario (salón + maestro).
            //    Quien entra a una clase compartida no ocupa el lugar por su
            //    cuenta: ya lo ocupa la clase con la que entra. Pero sí genera
            //    su propia nómina y su propio pago, como cualquier otro alumno.
            if (!$comparteCon) {
                $stmt = $this->db->prepare(
                    "INSERT INTO horario_ocupado (id_inscripcion, id_salon, id_maestro, dia_semana, hora_inicio, hora_fin, en_taller)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $idInscripcion, $datos['id_salon'], $datos['id_maestro'],
                    $datos['dia_semana'], $datos['hora_inicio'], $datos['hora_fin'],
                    $this->horarioModel->enTallerDe($idInscripcion)
                ]);
            }

            // 3) Nómina del maestro.
            //    Ojo: NO se usa costo_mensual. Eso es lo que paga el alumno y
            //    es ingreso de la escuela. Al maestro se le paga su tarifa.
            //    Si entró a medio mes, solo las clases que le tocan ese mes.
            $maestro = (new MaestroModel())->obtener((int) $datos['id_maestro']);
            $montoNomina = NominaModel::montoDeRenglon($maestro ?: [], (int) $datos['duracion_min'],
                                                       $tramo['clases'], $config);

            $this->nominaModel->insertar($this->db, [
                'id_maestro'     => $datos['id_maestro'],
                'id_inscripcion' => $idInscripcion,
                'id_alumno'      => $datos['id_alumno'],
                'monto'          => $montoNomina,
                'periodo'        => $periodoInicio,
                'clases'         => $tramo['clases'],
                'desde'          => $tramo['desde'],
            ]);

            // 4) Pago inicial (opcional, activado por defecto). El primer mes
            //    a medias se cobra proporcional a las clases que toma.
            if ($datos['registrar_pago_inicial']) {
                $this->pagoModel->insertarEnTransaccion($this->db, [
                    'id_alumno'      => $datos['id_alumno'],
                    'id_inscripcion' => $idInscripcion,
                    'monto'          => self::proporcional((float) $datos['costo_mensual'], $tramo['clases'], $config),
                    'metodo'         => $datos['metodo_pago'] ?? 'efectivo',
                    'periodo'        => $periodoInicio,
                    'recargo'        => 0,
                    'concepto'       => 'colegiatura',
                    'registrado_por' => $usuario,
                ]);
            }

            // 5) Cuota única de inscripción, en la misma operación del alta. La pantalla la propone marcada
            //    cuando es su primera vez en la escuela y sin marcar si ya estuvo inscrito; si la persona la
            //    marcó, se cobra (el importe ya se validó arriba). No abona a ninguna colegiatura.
            if ($datos['cobrar_inscripcion']) {
                $this->pagoModel->insertarEnTransaccion($this->db, [
                    'id_alumno'      => $datos['id_alumno'],
                    'id_inscripcion' => $idInscripcion,
                    'monto'          => $datos['monto_inscripcion'],
                    'metodo'         => $datos['metodo_pago'] ?? 'efectivo',
                    'periodo'        => $periodoInicio,
                    'recargo'        => 0,
                    'concepto'       => 'inscripcion',
                    'registrado_por' => $usuario,
                ]);
            }

            $this->confirmar($propia);

            $inscripcionCreada = $this->inscripcionModel->obtener($idInscripcion);
            $inscripcionCreada['id_inscripcion'] = $idInscripcion;
            $inscripcionCreada['alumno_creado'] = (bool) $alumnoNuevo;
            $inscripcionCreada['inscripcion_cobrada'] = $datos['cobrar_inscripcion'] ? $datos['monto_inscripcion'] : 0.0;
            $inscripcionCreada['primer_mes'] = [
                'periodo'       => $periodoInicio,
                'clases'        => $tramo['clases'] ?? (int) $config['semanas_por_mes'],
                'clases_mes'    => (int) $config['semanas_por_mes'],
                'paga_alumno'   => self::proporcional((float) $datos['costo_mensual'], $tramo['clases'], $config),
                'cobra_maestro' => $montoNomina,
            ];

            // Notificación por WhatsApp (silenciosa: si falla o está desactivada, no rompe el alta)
            try {
                $alumno = (new AlumnoModel())->obtener((int)$datos['id_alumno']);
                if ($alumno && !empty($alumno['telefono'])) {
                    $instrumentoNombre = $this->db->query(
                        "SELECT nombre FROM instrumentos WHERE id_instrumento = " . (int)$datos['id_instrumento']
                    )->fetchColumn();
                    WhatsAppService::enviarPlantillaConfirmacionAlta(
                        $alumno['telefono'], $alumno['nombre'], $instrumentoNombre ?: ''
                    );
                }
            } catch (\Throwable $e) {
                // Nunca dejamos que un fallo de WhatsApp afecte el alta ya confirmada
            }

            return $inscripcionCreada;

        } catch (Exception $e) {
            $this->deshacer($propia);
            throw new Exception("No se pudo completar el alta: " . $e->getMessage());
        }
    }

    /**
     * Inscribe a varios alumnos al mismo grupo de una vez (dibujo, pintura).
     * El primero abre el grupo si todavía no existe; los demás se le unen.
     * Cada uno se procesa por separado y se informa quién entró y quién no
     * (por ejemplo, porque ya estaba o porque se llenó el cupo): un alumno
     * con problema no impide inscribir a los demás.
     */
    public function darDeAltaGrupo(array $datos, array $idsAlumnos, ?string $usuario = null): array
    {
        return HorarioModel::conCandado(fn() => $this->darDeAltaGrupoAdentro($datos, $idsAlumnos, $usuario));
    }

    private function darDeAltaGrupoAdentro(array $datos, array $idsAlumnos, ?string $usuario = null): array
    {
        $idsAlumnos = array_values(array_unique(array_filter(array_map('intval', $idsAlumnos))));
        if (!$idsAlumnos) throw new Exception('Elige al menos un alumno.');
        if (count($idsAlumnos) > 40) throw new Exception('Demasiados alumnos de una vez.');
        if (!$this->horarioModel->esGrupal(!empty($datos['id_instrumento']) ? (int) $datos['id_instrumento'] : null)) {
            throw new Exception('Solo el taller (dibujo, pintura) acepta varios alumnos a la vez.');
        }

        // Inscripción: se cobra solo a quien nunca ha tenido una clase aquí, con el importe escrito o, si no hay,
        // el de Ajustes. Sin importe de ninguno de los dos, a nadie se le cobra (aquí no hay dónde pedirlo).
        $cobrarInsc = !empty($datos['cobrar_inscripcion']);
        $hayImporte = true;
        if ($cobrarInsc) {
            try { ConfiguracionModel::importeDeInscripcion($datos['monto_inscripcion'] ?? null); }
            catch (Exception $e) { $hayImporte = false; }
        }

        $inscritos = []; $fallas = [];
        foreach ($idsAlumnos as $id) {
            try {
                $suyo = $datos;
                $suyo['cobrar_inscripcion'] = ($cobrarInsc && $hayImporte && $this->inscripcionModel->esPrimeraClase($id)) ? 1 : 0;
                $this->darDeAlta(['id_alumno' => $id] + $suyo, $usuario);
                $inscritos[] = $id;
            } catch (Throwable $e) {
                $fallas[] = ['id_alumno' => $id, 'motivo' => $e->getMessage()];
            }
        }
        return ['inscritos' => $inscritos, 'fallas' => $fallas];
    }

    /**
     * La primera hora libre de un día para esa clase: con el maestro, el día, el salón (o en línea) y la duración
     * elegidos, la primera hora de inicio (las del horario de la escuela, de media en media hora) en la que la
     * clase cabe según las mismas reglas del alta. Sirve para no ofrecer de entrada una hora que choca.
     * Sin ninguna libre: null. Sin maestro, día o duración: null (no hay contra qué revisar).
     *
     * @param array $d id_maestro, dia_semana, duracion_min, modalidad, id_salon, id_instrumento, id_alumno
     */
    public function primerHuecoLibre(array $d): ?string
    {
        $dur = (int) ($d['duracion_min'] ?? 0);
        if ((int) ($d['id_maestro'] ?? 0) <= 0 || empty($d['dia_semana']) || $dur <= 0) return null;
        foreach ($this->horarioModel->bloques() as $b) {
            $ini = (string) $b['hora_inicio'];
            $fin = date('H:i:s', strtotime("2000-01-01 $ini +$dur minutes"));
            if (substr($fin, 0, 5) <= substr($ini, 0, 5)) continue;      // cruzaría la medianoche
            try {
                $this->resolverHueco(array_merge($d, ['hora_inicio' => $ini, 'hora_fin' => $fin]));
                return $ini;
            } catch (Throwable $e) {
                // Ahí no cabe: se sigue con la siguiente hora.
            }
        }
        return null;
    }

    /**
     * Completa una inscripción 'pendiente' (la que se creó al importar el
     * Excel de nómina, sin horario): le asigna instrumento, salón, día y hora,
     * ocupa el horario y la deja activa. Misma transacción, mismas reglas
     * de choque de horarios que un alta normal.
     */
    public function completar(int $idInscripcion, array $datos): array
    {
        return HorarioModel::conCandado(fn() => $this->completarAdentro($idInscripcion, $datos));
    }

    private function completarAdentro(int $idInscripcion, array $datos): array
    {
        $inscripcion = $this->inscripcionModel->obtener($idInscripcion);
        if (!$inscripcion) {
            throw new Exception("La inscripción no existe.");
        }
        if ($inscripcion['estado'] === 'baja') {
            throw new Exception("Esa inscripción está dada de baja.");
        }
        // Horas bien formadas; la duración sale de ellas, no de lo que diga la pantalla.
        $datos['hora_inicio'] = Validar::hora($datos['hora_inicio'] ?? '', 'de inicio');
        $datos['hora_fin'] = Validar::hora($datos['hora_fin'] ?? '', 'de fin');
        if (isset($datos['costo_mensual']) && $datos['costo_mensual'] !== '' && $datos['costo_mensual'] !== null) {
            $datos['costo_mensual'] = Validar::dinero($datos['costo_mensual'], 'La colegiatura', 100000);
        }

        // Se puede cambiar de maestro sin dar de baja al alumno: es el mismo
        // alumno con la misma clase, solo cambia quién se la da.
        $idMaestro = isset($datos['id_maestro']) && (int) $datos['id_maestro'] > 0
            ? (int) $datos['id_maestro'] : (int) $inscripcion['id_maestro'];
        $cambioDeMaestro = $idMaestro !== (int) $inscripcion['id_maestro'];
        if ($cambioDeMaestro) {
            $existe = $this->db->prepare("SELECT COUNT(*) FROM maestros WHERE id_maestro = ? AND activo = 1");
            $existe->execute([$idMaestro]);
            if (!(int) $existe->fetchColumn()) throw new Exception('Ese maestro no existe o está dado de baja.');
        }

        // Antes de hablar de empalmes: puede que esta clase ya exista, y que
        // lo que se esté intentando asignar sea una copia de la misma.
        $repetida = $this->horarioModel->claseRepetida(
            (int) $inscripcion['id_alumno'], $idMaestro,
            $datos['dia_semana'], $datos['hora_inicio'], $idInscripcion
        );
        if ($repetida) {
            throw new Exception(
                'Esta clase ya está registrada con ese mismo alumno y maestro, ese día y a esa hora'
                . ($repetida['salon'] ? ' en ' . $repetida['salon'] : '')
                . '. Lo que tienes es una inscripción repetida: bórrala desde Pendientes en vez de moverla.'
            );
        }

        // ¿Cómo estaba? Titular (con o sin integrantes) o integrante de otra.
        $eraIntegrante = !empty($inscripcion['comparte_con']);
        $integrantes = $eraIntegrante ? [] : $this->horarioModel->integrantes($idInscripcion);
        $modalidad = ($datos['modalidad'] ?? $inscripcion['modalidad'] ?? 'presencial') === 'online' ? 'online' : 'presencial';
        $idSalonNuevo = $modalidad === 'online' ? null : (!empty($datos['id_salon']) ? (int) $datos['id_salon'] : null);
        $idInstr = !empty($datos['id_instrumento']) ? (int) $datos['id_instrumento'] : null;

        // Una titular con integrantes que se queda en su mismo hueco (y, si es
        // grupo, con la misma materia) conserva su lugar. Si se va, su lugar lo
        // hereda otra, así que al revisar choques NO se ignora esa ocupación.
        $sigueEnSuHueco = !$eraIntegrante
            && $inscripcion['modalidad'] === $modalidad
            && ($inscripcion['id_salon'] === null ? null : (int) $inscripcion['id_salon']) === $idSalonNuevo
            && (int) $inscripcion['id_maestro'] === $idMaestro
            && $inscripcion['dia_semana'] === $datos['dia_semana']
            && substr((string) $inscripcion['hora_inicio'], 0, 5) === substr($datos['hora_inicio'], 0, 5)
            && substr((string) $inscripcion['hora_fin'], 0, 5) === substr($datos['hora_fin'], 0, 5)
            // En un grupo, pasar de Dibujo a Pintura no la saca de su lugar: es el mismo grupo.
            && (!$this->horarioModel->esGrupal($inscripcion['id_instrumento'] ? (int) $inscripcion['id_instrumento'] : null)
                || in_array($idInstr, $this->horarioModel->materiasDelGrupo((int) $inscripcion['id_instrumento']), true));
        $dejaIntegrantes = $integrantes && !$sigueEnSuHueco;

        $hueco = $this->resolverHueco([
            'id_alumno' => (int) $inscripcion['id_alumno'], 'id_maestro' => $idMaestro,
            'id_instrumento' => $idInstr, 'modalidad' => $modalidad, 'id_salon' => $idSalonNuevo,
            'dia_semana' => $datos['dia_semana'], 'hora_inicio' => $datos['hora_inicio'], 'hora_fin' => $datos['hora_fin'],
            'compartir_con' => $datos['compartir_con'] ?? null,
            'ignorar_ocupacion' => $dejaIntegrantes ? null : $idInscripcion,
        ], $idInscripcion);

        // Entrar a un grupo al editar se confirma: la pantalla lo pregunta antes.
        $entraAGrupoNuevo = $hueco['tipo'] === 'grupo'
            && (int) ($inscripcion['comparte_con'] ?? 0) !== (int) $hueco['comparte_con'];
        if ($entraAGrupoNuevo && empty($datos['confirmar_grupo'])) {
            throw new Exception('CONFIRMAR_GRUPO: ' . $hueco['mensaje']);
        }

        $precioInscripcion = (float) $inscripcion['costo_mensual'];
        $duracion = Validar::minutosEntre($datos['hora_inicio'], $datos['hora_fin']);

        // Una clase que ya estaba en el horario y cambia de duración o de día a mitad de mes: lo de antes
        // del cambio se cobra y se paga como antes, lo de desde el cambio como ahora.
        $yaAgendada = !empty($inscripcion['dia_semana']) && $inscripcion['estado'] === 'activa';
        $duracionAntes = (int) $inscripcion['duracion_min'];
        $cambioDuracion = $yaAgendada && $duracion !== $duracionAntes;
        $cambioDia = $yaAgendada && $datos['dia_semana'] !== $inscripcion['dia_semana'];

        // A partir de qué día rige el cambio (una sola fecha para todo). Con cambio de maestro: lo de
        // antes se le paga al anterior; lo que queda del mes, al nuevo.
        $fechaCambio = ($cambioDeMaestro || $cambioDuracion || $cambioDia)
            ? self::fechaDelMes($datos['fecha_cambio'] ?? null, $cambioDeMaestro ? 'del cambio de maestro' : 'del cambio', 0)
            : null;

        // El precio que rige el día del cambio. Casi siempre es el de la clase; si hay un cambio de precio
        // PROGRAMADO para después (el que bajaba el mes que entra), este día todavía rige el de antes: es el
        // precio de partida, y ese cambio programado se cancela y se vuelve a planear con lo nuevo.
        $precioAntes = $fechaCambio ? HistorialClase::precioEn($inscripcion, $fechaCambio) : $precioInscripcion;
        // Si no mandan costo (o mandan el mismo que ya tenía), se conserva el que regía. Antes llegaba 0
        // desde la rejilla de horarios y borraba la colegiatura.
        $costoExplicito = isset($datos['costo_mensual']) && $datos['costo_mensual'] !== '' && $datos['costo_mensual'] !== null
            && abs((float) $datos['costo_mensual'] - $precioInscripcion) >= 0.005;
        $costo = $costoExplicito ? (float) $datos['costo_mensual'] : $precioAntes;

        // ¿Su colegiatura sigue a la duración? Si la casilla de la pantalla viene marcada, el precio nuevo
        // lo calcula el servidor (precio de la materia por la duración), igual que al inscribir.
        $plan = null;
        if ($cambioDuracion && !empty($datos['precio_por_duracion'])) {
            $plan = $this->planDePrecio($inscripcion, $idInstr, $duracion, $fechaCambio, $datos['dia_semana']);
            if ($plan) $costo = $plan['precio_nuevo'];
        }
        $reparto = null;
        $liberado = !$sigueEnSuHueco
            ? $this->huecoQueDeja($inscripcion, !$eraIntegrante, count($integrantes)) : null;
        $antes = $this->fotoDeClase($idInscripcion);

        $propia = $this->empezar();
        try {
            // 1) Si era titular con integrantes y se va, una de ellas hereda el lugar.
            if ($dejaIntegrantes) $this->promoverTitular($idInscripcion);

            if ($cambioDeMaestro) {
                $this->db->prepare("UPDATE inscripciones SET id_maestro = ? WHERE id_inscripcion = ?")
                         ->execute([$idMaestro, $idInscripcion]);

                $maestro = (new MaestroModel())->obtener($idMaestro);
                $reparto = $this->nominaModel->cambiarMaestro(
                    $this->db, $inscripcion, $maestro ?: ['id_maestro' => $idMaestro],
                    $fechaCambio, $datos['dia_semana'], (int) $duracion
                );
            }

            $this->inscripcionModel->completar($idInscripcion, [
                // La materia puede venir vacía: una clase importada del Excel
                // de horarios todavía no la tiene.
                'id_instrumento' => $idInstr,
                'id_salon'       => $hueco['id_salon'],
                'modalidad'      => $hueco['modalidad'],
                'comparte_con'   => $hueco['comparte_con'],
                'dia_semana'     => $datos['dia_semana'],
                'hora_inicio'    => $datos['hora_inicio'],
                'hora_fin'       => $datos['hora_fin'],
                'costo_mensual'  => $costo,
                'duracion_min'   => $duracion,
            ]);

            // 2) Ocupación: la titular ocupa el horario; quien entra a un grupo o
            //    comparte no ocupa nada propio (ya lo ocupa la clase con la que va).
            //    Se borra su fila y se inserta de nuevo (no se actualiza): si el hueco chocara
            //    con otra clase, da error en vez de pisar la fila de OTRA inscripción.
            $this->db->prepare("DELETE FROM horario_ocupado WHERE id_inscripcion = ?")->execute([$idInscripcion]);
            if (!$hueco['comparte_con']) {
                $this->db->prepare(
                    "INSERT INTO horario_ocupado (id_inscripcion, id_salon, id_maestro, dia_semana, hora_inicio, hora_fin, en_taller)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                )->execute([
                    $idInscripcion, $hueco['id_salon'], $idMaestro,
                    $datos['dia_semana'], $datos['hora_inicio'], $datos['hora_fin'],
                    $this->horarioModel->enTallerDe($idInscripcion)
                ]);
                // Si se quedó con integrantes (titular que no se movió), ellas siguen
                // coincidiendo con su hueco: nada de ellas depende de la fila de horario.
            }

            // 3) Cambio de duración o de día (con o sin precio nuevo): queda en el historial de la clase con su
            //    fecha, para que el cobro y la nómina del mes se repartan por tramos (cada uno con su día y su
            //    duración) y una baja o un segundo cambio después sigan cuadrando. Un precio corregido a mano en
            //    la misma edición rige todo el mes (es una corrección); solo el precio por duración se parte.
            $usuario = $datos['usuario'] ?? null;
            $precioDelTramoViejo = $plan ? $precioAntes : $costo;
            $todoElMes = !empty($datos['rige_todo_el_mes']);
            $canceladas = [];
            if ($cambioDuracion || $cambioDia) {
                if ($plan && $plan['desde'] > $fechaCambio) {
                    // El precio nuevo no rige todavía (mes ya pagado que baja, o ya no quedan clases este mes): la
                    // duración y el día cambian hoy, el precio el día que dice el plan.
                    $canceladas = HistorialClase::registrar($this->db, $idInscripcion, $fechaCambio, $precioAntes, $duracion,
                                                            $datos['dia_semana'], $precioAntes, $duracionAntes, $inscripcion['dia_semana'],
                                                            $usuario, $todoElMes);
                    HistorialClase::registrar($this->db, $idInscripcion, $plan['desde'], $costo, $duracion, $datos['dia_semana'],
                                              $precioAntes, $duracionAntes, $inscripcion['dia_semana'], $usuario);
                } else {
                    $canceladas = HistorialClase::registrar($this->db, $idInscripcion, $fechaCambio, $costo, $duracion,
                                                            $datos['dia_semana'], $precioDelTramoViejo, $duracionAntes,
                                                            $inscripcion['dia_semana'], $usuario, $todoElMes);
                }
            }
            // Una corrección de precio (sin precio por duración) rige el mes completo, tenga o no historial la clase.
            if (!$plan && abs($costo - $precioAntes) >= 0.005) {
                HistorialClase::corregirPrecioDelMes($this->db, $idInscripcion, substr($fechaCambio ?: date('Y-m-d'), 0, 7), $costo, $usuario);
            }
            if (abs($costo - $precioInscripcion) >= 0.005) {
                Bitacora::registrar($this->db, 'cambio de precio', 'inscripciones', $idInscripcion, 'costo_mensual',
                    number_format($precioInscripcion, 2, '.', ''), number_format($costo, 2, '.', ''),
                    $plan
                        ? sprintf('La duración pasó de %d a %d min. Rige desde el %s%s', $duracionAntes, $duracion,
                                  date('d/m/Y', strtotime($plan['desde'])),
                                  substr($plan['desde'], 8, 2) !== '01' ? ' (proporcional a las clases del mes)' : ' (mes completo)')
                        : ($canceladas
                            ? 'Se canceló el cambio de precio programado desde el ' . date('d/m/Y', strtotime($canceladas[0]['desde']))
                              . ': el precio se queda en lo que regía'
                            : 'Precio corregido al editar la clase'),
                    null, $usuario);
            }

            //    La nómina sin pagar de este mes y de los siguientes la sigue. Antes de que la clase tuviera
            //    horario (importada, pendiente) no hay "antes": se recalcula el mes completo.
            $cambioNomina = null;
            if (!$cambioDeMaestro && $yaAgendada && ($cambioDuracion || $cambioDia)) {
                $despuesFila = $this->inscripcionModel->obtener($idInscripcion);
                $cambioNomina = $this->nominaModel->cambioDeClase($this->db, $inscripcion, $despuesFila, $fechaCambio, $todoElMes);
            } elseif (!$cambioDeMaestro && !$yaAgendada && $duracion !== $duracionAntes) {
                $this->recalcularNominaDe($idInscripcion, $idMaestro, $duracion);
            }

            // Lo que la pantalla le cuenta a quien edita: desde cuándo rige el precio (UNA fecha ya decidida) y cuánto
            // queda pendiente si el precio sube en un mes ya pagado.
            $infoPrecio = null;
            if ($plan) {
                $mes = substr($plan['desde'], 0, 7);
                $infoPrecio = ['antes' => $precioAntes, 'despues' => $costo, 'desde' => $plan['desde'],
                               'mes_pagado' => $plan['mes_pagado'], 'por_confirmar' => $plan['por_confirmar'],
                               'baja' => $plan['baja'], 'sin_clases_este_mes' => $plan['sin_clases_este_mes'],
                               'mes_completo' => $plan['mes_completo']];
                if ($plan['mes_pagado'] && !$plan['baja'] && !$plan['por_confirmar'] && $mes === substr($fechaCambio, 0, 7)) {
                    $infoPrecio['queda_por_pagar'] = (new CuentaModel())->saldoPendienteDe((int) $inscripcion['id_alumno'], $mes);
                }
            }

            // 4) Bitácora: qué había, qué quedó, quién y por qué. Misma transacción.
            $this->registrarCambios($idInscripcion, $antes, $this->fotoDeClase($idInscripcion), $datos);

            $this->confirmar($propia);
        } catch (Exception $e) {
            $this->deshacer($propia);
            throw new Exception("No se pudo asignar el horario: " . $e->getMessage());
        }

        $resultado = $this->inscripcionModel->obtener($idInscripcion);
        if ($reparto) $resultado['reparto_nomina'] = $reparto;
        if ($infoPrecio) $resultado['cambio_precio'] = $infoPrecio;
        if ($cambioNomina) $resultado['cambio_nomina'] = $cambioNomina;
        $resultado['hueco'] = ['tipo' => $hueco['tipo'], 'mensaje' => $hueco['mensaje'],
                               'en_clase' => $hueco['en_clase'], 'cupo' => $hueco['cupo']];
        // El lugar que dejó puede ser justo el que alguien espera.
        if ($liberado) $resultado['lista_espera'] = (new ListaEsperaModel())->coincidencias($liberado);
        return $resultado;
    }

    /**
     * La nómina sin pagar de esta clase, del mes en curso en adelante, se
     * vuelve a calcular con la duración nueva. Respeta los tramos: si ese mes
     * el maestro solo dio 2 clases, se le pagan 2 clases de la duración nueva.
     */
    private function recalcularNominaDe(int $idInscripcion, int $idMaestro, int $duracion): void
    {
        $maestro = (new MaestroModel())->obtener($idMaestro) ?: [];
        $config = (new ConfiguracionModel())->todo();
        $s = $this->db->prepare(
            "SELECT id_detalle, periodo, clases, monto, ajuste_manual FROM nomina_detalle
              WHERE id_inscripcion = ? AND id_maestro = ? AND pagado = 0 AND periodo >= ?"
        );
        $s->execute([$idInscripcion, $idMaestro, date('Y-m')]);
        // Cambió la duración: el importe se recalcula (si no, se pagaría de más o de menos).
        // Un ajuste manual no se pisa en silencio: se quita la marca y queda en la bitácora.
        $up = $this->db->prepare("UPDATE nomina_detalle SET monto = ?, ajuste_manual = 0 WHERE id_detalle = ?");
        foreach ($s->fetchAll() as $f) {
            $clases = $f['clases'] === null ? null : (int) $f['clases'];
            $monto = NominaModel::montoDeRenglon($maestro, $duracion, $clases,
                                                 ConfiguracionModel::paraPeriodo($config, $f['periodo']));
            $up->execute([$monto, $f['id_detalle']]);
            if ((int) $f['ajuste_manual'] === 1) {
                Bitacora::registrar($this->db, 'recalcular ajuste manual', 'nomina_detalle', (int) $f['id_detalle'], 'monto',
                    $f['monto'], number_format($monto, 2, '.', ''), 'Se recalculó por el cambio de duración: tenía un ajuste manual');
            }
        }
    }

    /**
     * Una fecha AAAA-MM-DD que no caiga antes de este mes. Sin fecha, hoy.
     *
     * @param int $diasAdelante cuánto puede adelantarse; 0 = solo este mes
     */
    private static function fechaDelMes(?string $fecha, string $que, int $diasAdelante): string
    {
        if ($fecha === null || $fecha === '') return date('Y-m-d');
        if (!Calendario::fechaValida($fecha)) throw new Exception("La fecha $que no es válida.");
        if ($fecha < date('Y-m-01')) {
            throw new Exception("La fecha $que no puede ser de un mes anterior: esa nómina ya se cerró.");
        }
        $tope = $diasAdelante > 0 ? date('Y-m-d', strtotime("+$diasAdelante days")) : date('Y-m-t');
        if ($fecha > $tope) {
            throw new Exception($diasAdelante > 0
                ? "La fecha $que está demasiado adelante."
                : "La fecha $que tiene que caer en este mes.");
        }
        return $fecha;
    }

    // =================================================================
    // CAMBIO DE DURACIÓN -> PRECIO
    // =================================================================

    /**
     * El precio que le corresponde a una clase por su duración: el de su materia por la duración,
     * igual que al inscribir (y que "recalcular precios"). Null si la materia no tiene precio.
     */
    public function precioSugerido(?int $idInstrumento, int $duracionMin): ?float
    {
        if (!$idInstrumento) return null;
        $s = $this->db->prepare("SELECT costo_mensual FROM instrumentos WHERE id_instrumento = ?");
        $s->execute([$idInstrumento]);
        $lista = (float) $s->fetchColumn();
        return $lista > 0 ? round($lista * ($duracionMin ?: 60) / 60, 2) : null;
    }

    /**
     * Qué pasa con la colegiatura si la clase cambia de duración el día $fecha y su precio sigue a la
     * duración (null si no hay nada que cambiar: la materia no tiene precio o da lo mismo).
     *
     *   · Rige desde una sola fecha, ya decidida aquí ('desde'): las clases del mes antes de esa fecha, al
     *     precio de antes; las de desde esa fecha, al nuevo. Los meses siguientes, al nuevo completo; los
     *     anteriores no cambian.
     *   · Si el mes ya está pagado y el precio BAJA, este mes no se reduce: rige desde el día 1 del mes
     *     siguiente. Si SUBE, se aplica lo proporcional y queda pendiente la diferencia.
     *   · Si desde esa fecha ya no le queda ninguna clase al mes, el precio nuevo tampoco cobraría nada de
     *     este mes: también rige desde el día 1 del mes siguiente.
     *   · Un mes cubierto por un pago POR CONFIRMAR cuenta como pagado, pero sin diferencia por cobrar: el
     *     estado de cuenta no le fabrica deuda a un mes del que solo se sabe que se pagó.
     *
     * @param string|null $diaNuevo día de la clase después del cambio (por omisión, el de hoy)
     * @return array|null {precio_antes, precio_nuevo, desde, fecha_pedida, mes_pagado, por_confirmar, baja, sin_clases_este_mes}
     */
    private function planDePrecio(array $ins, ?int $idInstrumento, int $duracion, string $fecha, ?string $diaNuevo = null): ?array
    {
        $nuevo = $this->precioSugerido($idInstrumento, $duracion);
        $antes = HistorialClase::precioEn($ins, $fecha);
        if ($nuevo === null || abs($nuevo - $antes) < 0.005) return null;

        $mes = substr($fecha, 0, 7);
        $siguiente = date('Y-m-d', strtotime($mes . '-01 +1 month'));
        $baja = $nuevo < $antes;
        $pago = (new CuentaModel())->pagoDelMes((int) $ins['id_alumno'], $mes, (int) $ins['id_inscripcion']);
        $desde = $baja && $pago['pagado'] ? $siguiente : $fecha;
        $sinClases = false;
        $r = $this->repartoDelCambio($ins, substr($desde, 0, 7), $fecha, $desde, $antes, $nuevo, $duracion, $diaNuevo);
        $cuenta = fn(array $r, string $d) => [
            array_sum(array_map(fn($t) => $t['desde'] < $d ? $t['clases'] : 0, $r)),
            array_sum(array_map(fn($t) => $t['desde'] >= $d ? $t['clases'] : 0, $r))];
        [$clasesAntes, $clasesDespues] = $cuenta($r, $desde);
        if ($desde === $fecha && $clasesDespues === 0) {
            // Ya no queda ninguna clase del mes desde esa fecha: el precio nuevo no cobraría nada de este mes.
            $desde = $siguiente; $sinClases = true;
            $r = $this->repartoDelCambio($ins, substr($desde, 0, 7), $fecha, $desde, $antes, $nuevo, $duracion, $diaNuevo);
            [$clasesAntes, $clasesDespues] = $cuenta($r, $desde);
        }
        return ['precio_antes' => $antes, 'precio_nuevo' => $nuevo, 'desde' => $desde, 'fecha_pedida' => $fecha,
                'mes_pagado' => $pago['pagado'], 'por_confirmar' => $pago['por_confirmar'], 'baja' => $baja,
                'sin_clases_este_mes' => $sinClases,
                // Rige todo el mes de esa fecha (día 1, o antes de su primera clase del mes): así se dice en pantalla.
                'clases_antes' => $clasesAntes, 'clases_despues' => $clasesDespues,
                'mes_completo' => $clasesAntes === 0 || substr($desde, 8, 2) === '01',
                'reparto' => $r];
    }

    /**
     * Cómo quedaría el reparto de un mes (clases a cada precio, cada tramo con su día) si la clase cambiara el
     * día $fecha, y el precio nuevo rigiera desde $desdePrecio. Lo programado con fecha posterior al cambio se
     * cancela, así que no cuenta. No guarda nada.
     */
    private function repartoDelCambio(array $ins, string $mes, string $fecha, string $desdePrecio, float $precioAntes,
                                      float $precioNuevo, int $duracion, ?string $diaNuevo): array
    {
        $semanas = ConfiguracionModel::semanasDe($mes);
        $durAntes = (int) $ins['duracion_min'];
        $diaAntes = $ins['dia_semana'] ?: null;
        $diaNuevo = $diaNuevo ?: $diaAntes;
        $filas = HistorialClase::de((int) $ins['id_inscripcion'], $this->db);
        if (!$filas) $filas = [['desde' => HistorialClase::ORIGEN, 'precio' => $precioAntes, 'duracion' => $durAntes, 'dia' => $diaAntes]];
        $filas = array_values(array_filter($filas, fn($f) => $f['desde'] < $fecha));
        if (!$filas) $filas = [['desde' => HistorialClase::ORIGEN, 'precio' => $precioAntes, 'duracion' => $durAntes, 'dia' => $diaAntes]];
        if ($desdePrecio > $fecha) {
            $filas[] = ['desde' => $fecha, 'precio' => $precioAntes, 'duracion' => $duracion, 'dia' => $diaNuevo];
        }
        $filas[] = ['desde' => $desdePrecio, 'precio' => $precioNuevo, 'duracion' => $duracion, 'dia' => $diaNuevo];
        $tramos = HistorialClase::tramos($filas, $precioNuevo, $duracion, $diaNuevo);
        $inicio = ($ins['origen'] ?? 'captura') === 'captura' && $ins['fecha_alta'] > $mes . '-01' ? $ins['fecha_alta'] : null;
        return HistorialClase::reparto($tramos, $mes, $diaNuevo, $inicio, null, $semanas);
    }

    /**
     * Vista previa para la pantalla "Editar clase": si la duración cambia, qué pasaría con la
     * colegiatura (sin guardar nada). La casilla de la ventana se arma con esto.
     *
     * 'desde' es LA fecha en que rige el precio nuevo (ya decidida): la casilla, la nota y el aviso de éxito
     * de la pantalla la usan tal cual, y 'fecha_minima' es el primer día que se puede elegir para el cambio.
     *
     * @param array $q duracion_min, id_instrumento (opcional), dia_semana (opcional), fecha (opcional; hoy por omisión)
     */
    public function vistaPrecio(int $idInscripcion, array $q): array
    {
        $ins = $this->inscripcionModel->obtener($idInscripcion);
        if (!$ins) throw new Exception('La clase no existe.');
        $duracion = (int) ($q['duracion_min'] ?? 0);
        $no = fn(string $motivo) => ['aplica' => false, 'motivo' => $motivo, 'precio_actual' => (float) $ins['costo_mensual']];
        if ($ins['estado'] !== 'activa' || empty($ins['dia_semana'])) return $no('La clase todavía no tiene horario.');
        if ($duracion <= 0 || $duracion === (int) $ins['duracion_min']) return $no('La duración no cambió.');

        $fecha = self::fechaDelMes($q['fecha'] ?? null, 'del cambio', 0);
        // Una fecha de cambio que caiga antes del último cambio de la clase se niega desde aquí (con el mismo texto que al guardar).
        $filasVivas = HistorialClase::de($idInscripcion, $this->db);
        HistorialClase::validarFecha($filasVivas, $fecha);
        $idInstr = !empty($q['id_instrumento']) ? (int) $q['id_instrumento'] : ($ins['id_instrumento'] ? (int) $ins['id_instrumento'] : null);
        $diaNuevo = !empty($q['dia_semana']) && isset(Calendario::NUMERO_DIA[$q['dia_semana']]) ? $q['dia_semana'] : $ins['dia_semana'];
        $plan = $this->planDePrecio($ins, $idInstr, $duracion, $fecha, $diaNuevo);
        if (!$plan) {
            return array_merge($no($this->precioSugerido($idInstr, $duracion) === null
                ? 'Su materia no tiene precio en la lista.' : 'Su colegiatura queda igual.'),
                ['precio_actual' => HistorialClase::precioEn($ins, $fecha)]);
        }

        // Cómo quedaría el mes del precio nuevo: clases a cada precio (ya calculado con el plan).
        $mes = substr($plan['desde'], 0, 7);
        $semanas = ConfiguracionModel::semanasDe($mes);
        $reparto = $plan['reparto'];
        $clasesAntes = $plan['clases_antes']; $clasesDespues = $plan['clases_despues'];
        $cuenta = new CuentaModel();
        $mesNuevo = HistorialClase::cargo($reparto, $semanas);
        $mesActual = $cuenta->cargoDeClase($ins, $mes)['monto'];
        unset($plan['reparto']);

        $r = $plan + [
            'aplica' => true, 'periodo' => $mes, 'semanas' => $semanas,
            'precio_actual' => $plan['precio_antes'],
            'proporcional' => $clasesAntes > 0 && $clasesDespues > 0,
            'mes_de_la_clase' => $mesNuevo,
        ];
        // Mes ya pagado y el precio sube: lo que quedaría pendiente de ese mes (con un pago por confirmar no hay diferencia por cobrar).
        if ($plan['mes_pagado'] && !$plan['baja'] && !$plan['por_confirmar']) {
            $r['queda_por_pagar'] = round(max(0, $cuenta->saldoPendienteDe((int) $ins['id_alumno'], $mes) + ($mesNuevo - $mesActual)), 2);
        } elseif ($plan['mes_pagado'] && !$plan['baja']) {
            $r['queda_por_pagar'] = 0.0;
        }
        return $r;
    }

    /** Lo que paga el alumno un mes a medias: proporcional a las clases que toma */
    public static function proporcional(float $costoMensual, ?int $clases, array $config): float
    {
        if ($clases === null) return round($costoMensual, 2);
        return round($costoMensual * $clases / max(1, (int) $config['semanas_por_mes']), 2);
    }

    /**
     * Da de baja una inscripción: libera horario, cierra su nómina del mes con
     * las clases que alcanzó a tomar, y conserva el registro histórico en
     * 'inscripciones' y en 'pagos'.
     *
     * Antes de soltar la clase se congela lo que el alumno debía, porque el
     * adeudo de cada mes se calcula con sus clases vivas: sin congelarlo,
     * darlo de baja se lo borraría. Con 'conservar_adeudo' en falso no se
     * congela nada y el registro queda sin deuda, que es lo que hace falta
     * al depurar duplicados o alumnos viejos que nunca debieron estar.
     */
    public function darDeBaja(int $idInscripcion, array $datos = []): array
    {
        return HorarioModel::conCandado(fn() => $this->darDeBajaAdentro($idInscripcion, $datos));
    }

    private function darDeBajaAdentro(int $idInscripcion, array $datos = []): array
    {
        $inscripcion = $this->inscripcionModel->obtener($idInscripcion);
        if (!$inscripcion) {
            throw new Exception("La inscripción no existe.");
        }
        if ($inscripcion['estado'] === 'baja') {
            throw new Exception("Esta inscripción ya estaba dada de baja.");
        }

        // Último día que tomó clase. Lo de antes ya se dio y se le paga al maestro.
        $fechaBaja = self::fechaDelMes($datos['fecha_baja'] ?? null, 'de la baja', 0);
        $idAlumno  = (int) $inscripcion['id_alumno'];

        $mes = substr($fechaBaja, 0, 7);
        $sinDeuda = !($datos['conservar_adeudo'] ?? true);

        // Una clase sin día (importada, sin horario) no deja contar cuántas
        // clases tomó este mes. No se inventa: quien da la baja lo dice.
        $clasesTomadas = isset($datos['clases_tomadas']) && $datos['clases_tomadas'] !== ''
            ? (int) $datos['clases_tomadas'] : null;
        $semanas = ConfiguracionModel::semanasDe($mes);
        if ($clasesTomadas !== null && ($clasesTomadas < 0 || $clasesTomadas > $semanas)) {
            throw new Exception("Las clases que tomó van de 0 a $semanas.");
        }
        if (!$sinDeuda && empty($inscripcion['dia_semana']) && $clasesTomadas === null) {
            throw new Exception('Esta clase no tiene día asignado, así que no se puede contar cuántas clases tomó '
                . 'este mes. Indica cuántas tomó (0 si ninguna).');
        }
        $antes = $this->fotoDeClase($idInscripcion);

        // ¿Le quedan más clases después de esta?
        $quedan = $this->db->prepare(
            "SELECT COUNT(*) FROM inscripciones WHERE id_alumno = ? AND estado <> 'baja' AND id_inscripcion <> ?"
        );
        $quedan->execute([$idAlumno, $idInscripcion]);
        $leQuedan = (int) $quedan->fetchColumn();

        $propia = $this->empezar();
        try {
            // Si era titular de un grupo o de una clase compartida, su lugar
            // lo hereda otra integrante: el grupo no se queda sin horario.
            $integrantesBaja = empty($inscripcion['comparte_con'])
                ? $this->horarioModel->integrantes($idInscripcion) : [];
            if ($integrantesBaja) $this->promoverTitular($idInscripcion);

            // El congelado va DENTRO de la transacción: si la baja falla, no
            // queda ningún cargo guardado a medias. CuentaModel usa la misma
            // conexión, así que comparte la transacción.
            $cuenta = new CuentaModel();
            $congelados = 0;

            if ($sinDeuda) {
                // Depuración: el registro no debía existir. Su mes se cierra en
                // cero, con el motivo a la vista. Los meses ya cerrados no se
                // tocan: eso requiere una corrección, no una baja.
                if ($cuenta->congelarClase($inscripcion, $mes, $fechaBaja, 'depuración: no debía existir', 0.0)) {
                    $congelados++;
                }
            } else {
                // Lo ya transcurrido, de todas sus clases, para que la deuda no
                // dependa de que conserve clases vivas.
                $anterior = date('Y-m', strtotime($mes . '-01 -1 month'));
                $congelados += $cuenta->congelarAlumno($idAlumno, $anterior, 'baja');

                // Esta clase, del mes en curso: solo lo que alcanzó a tomar,
                // aunque le queden otras clases. Es lo que se le paga al maestro.
                if ($cuenta->congelarClase($inscripcion, $mes, $fechaBaja, 'baja', null, $clasesTomadas)) $congelados++;
            }

            // 1) Marcar la inscripción como dada de baja (se conserva como historial)
            $this->inscripcionModel->marcarBaja($idInscripcion, $fechaBaja);

            // 2) Liberar el horario ocupado (salón + maestro)
            $stmt = $this->db->prepare("DELETE FROM horario_ocupado WHERE id_inscripcion = ?");
            $stmt->execute([$idInscripcion]);

            // 3) La nómina del mes se cierra con las clases que sí se dieron. Un
            //    registro que no debía existir no dio clases: no deja nómina
            //    de este mes en adelante (lo ya pagado nunca se toca).
            if ($sinDeuda) {
                // Los renglones con ajuste manual que se quitan dejan su importe en la bitácora.
                NominaModel::borrarRenglones($this->db, "nd.id_inscripcion = ? AND nd.periodo >= ?", [$idInscripcion, $mes],
                    'Se quitó por la baja: el registro no debía existir (tenía un ajuste manual)', $datos['usuario'] ?? null);
                $clases = 0;
            } else {
                $clases = $this->nominaModel->eliminarPorInscripcion($this->db, $idInscripcion, $fechaBaja, $clasesTomadas);
            }

            // Los pagos NO se tocan: quedan como historial financiero del alumno.

            $this->registrarCambios($idInscripcion, $antes, $this->fotoDeClase($idInscripcion), $datos + [
                'motivo' => $datos['motivo'] ?? ($sinDeuda ? 'Baja: el registro no debía existir' : 'Baja: dejó la clase'),
            ]);

            $this->confirmar($propia);
            $r = ['clases_del_mes' => $clases, 'fecha_baja' => $fechaBaja,
                  'meses_congelados' => $congelados, 'le_quedan_clases' => $leQuedan];

            // Se liberó un horario (o un lugar en un grupo): se avisa a quien
            // lo esperaba. Solo se avisa; nadie se inscribe solo.
            $liberado = $this->huecoQueDeja($inscripcion, empty($inscripcion['comparte_con']), count($integrantesBaja));
            if ($liberado) $r['lista_espera'] = (new ListaEsperaModel())->coincidencias($liberado);
            return $r;
        } catch (Exception $e) {
            $this->deshacer($propia);
            throw new Exception("No se pudo completar la baja: " . $e->getMessage());
        }
    }

    /**
     * Corrige una baja ya hecha, en su mes, mientras la nómina de ese mes no
     * se haya pagado:
     *   · 'no_debia_existir': no tomó clases. Se quita lo que quedó en la
     *     nómina del maestro de ese mes en adelante y su cargo del mes queda en
     *     cero.
     *   · 'clases': tomó N clases ese mes (para las clases sin día, cuyo número
     *     no se puede contar). Nómina y cargo del mes quedan por esas N.
     * Todo queda en la bitácora de la clase.
     */
    public function corregirBaja(int $idInscripcion, array $datos): array
    {
        $ins = $this->inscripcionModel->obtener($idInscripcion);
        if (!$ins) throw new Exception('La inscripción no existe.');
        if ($ins['estado'] !== 'baja' || empty($ins['fecha_baja'])) throw new Exception('Esa clase no está dada de baja.');
        $mes = substr($ins['fecha_baja'], 0, 7);
        $config = ConfiguracionModel::paraPeriodo((new ConfiguracionModel())->todo(), $mes);
        $semanas = (int) $config['semanas_por_mes'];

        $pagada = $this->db->prepare("SELECT COUNT(*) FROM nomina_detalle WHERE id_inscripcion = ? AND periodo >= ? AND pagado = 1");
        $pagada->execute([$idInscripcion, $mes]);
        if ((int) $pagada->fetchColumn() > 0) {
            throw new Exception('La nómina de ese mes ya se le pagó al maestro: eso ya no se corrige desde aquí.');
        }

        $modo = (string) ($datos['modo'] ?? '');
        if ($modo === 'no_debia_existir') {
            $n = 0;
        } elseif ($modo === 'clases') {
            $n = (int) ($datos['clases'] ?? -1);
            if ($n < 0 || $n > $semanas) throw new Exception("Las clases que tomó van de 0 a $semanas.");
        } else {
            throw new Exception('Indica si el registro no debía existir o cuántas clases tomó.');
        }

        $antes = $this->fotoDeClase($idInscripcion);
        // Una clase con historial de precio, duración o día no se cuenta con lo de hoy: las N clases que tomó son
        // las primeras N del mes, cada una con el precio y la duración que tenía ese día. Sin historial, como siempre.
        $filasClase = HistorialClase::filasDe($ins, $this->db);
        $partesTomadas = fn(?string $desde): array =>
            HistorialClase::clasesTomadas($ins, $mes, $desde, $ins['fecha_baja'], $n, $semanas, $this->db) ?? [];
        $propia = $this->empezar();
        try {
            // Nómina: del mes de la baja en adelante, solo lo no pagado.
            NominaModel::borrarRenglones($this->db, "nd.id_inscripcion = ? AND nd.periodo > ?", [$idInscripcion, $mes],
                'Se quitó por la corrección de la baja: meses posteriores (tenía un ajuste manual)', $datos['usuario'] ?? null);
            $s = $this->db->prepare(
                "SELECT nd.id_detalle, nd.monto, nd.ajuste_manual, nd.desde, ins.duracion_min, m.modo_pago, m.tarifa_por_hora, m.tarifa_por_alumno
                   FROM nomina_detalle nd
                   JOIN inscripciones ins ON ins.id_inscripcion = nd.id_inscripcion
                   JOIN maestros m ON m.id_maestro = nd.id_maestro
                  WHERE nd.id_inscripcion = ? AND nd.periodo = ? AND nd.pagado = 0"
            );
            $s->execute([$idInscripcion, $mes]);
            foreach ($s->fetchAll() as $f) {
                if ($n === 0) {
                    // No tomó clases: el renglón se quita, pero un ajuste manual no desaparece en silencio.
                    NominaModel::borrarRenglones($this->db, "nd.id_detalle = ?", [$f['id_detalle']],
                        'Se quitó por la corrección de la baja: no tomó clases ese mes (tenía un ajuste manual)', $datos['usuario'] ?? null);
                } else {
                    // Las clases que de verdad dio cambian el importe; un ajuste manual no se
                    // pisa en silencio: se quita la marca y queda en la bitácora.
                    $nuevoMonto = $filasClase
                        ? NominaModel::montoPorTramos($f, $partesTomadas($f['desde'] ?: NominaModel::inicioDeClase($ins)), $config)
                        : NominaModel::montoDeRenglon($f, (int) $f['duracion_min'], $n >= $semanas ? null : $n, $config);
                    $this->db->prepare("UPDATE nomina_detalle SET clases = ?, hasta = ?, monto = ?, ajuste_manual = 0 WHERE id_detalle = ?")
                             ->execute([$n >= $semanas ? null : $n, $ins['fecha_baja'], $nuevoMonto, $f['id_detalle']]);
                    if ((int) $f['ajuste_manual'] === 1) {
                        Bitacora::registrar($this->db, 'recalcular ajuste manual', 'nomina_detalle', (int) $f['id_detalle'], 'monto',
                            $f['monto'], number_format($nuevoMonto, 2, '.', ''),
                            'Se recalculó por la corrección de la baja: tenía un ajuste manual', null, $datos['usuario'] ?? null);
                    }
                }
            }

            // Cargo del alumno de ese mes: por las clases que de verdad tomó.
            $precio = (float) $ins['costo_mensual'];
            $monto = $modo === 'no_debia_existir' ? 0.0
                   : ($filasClase ? HistorialClase::cargo($partesTomadas(NominaModel::inicioDeClase($ins)), $semanas)
                                  : round($precio * $n / max(1, $semanas), 2));
            $motivo = $modo === 'no_debia_existir' ? 'depuración: no debía existir' : 'baja: clases indicadas';
            $previo = $this->db->prepare("SELECT monto FROM cargos_mensuales WHERE id_inscripcion = ? AND periodo = ?");
            $previo->execute([$idInscripcion, $mes]);
            $cargoAntes = $previo->fetchColumn();
            Bitacora::registrar($this->db, 'corregir baja', 'inscripciones', $idInscripcion, "cargo $mes",
                $cargoAntes === false ? null : $cargoAntes, number_format($monto, 2, '.', ''),
                $datos['motivo'] ?? $motivo, null, $datos['usuario'] ?? null);
            $up = $this->db->prepare(
                "UPDATE cargos_mensuales SET monto = ?, clases = ?, motivo = ? WHERE id_inscripcion = ? AND periodo = ?"
            );
            $up->execute([$monto, $n, $motivo, $idInscripcion, $mes]);
            if ($up->rowCount() === 0) {
                $existe = $this->db->prepare("SELECT COUNT(*) FROM cargos_mensuales WHERE id_inscripcion = ? AND periodo = ?");
                $existe->execute([$idInscripcion, $mes]);
                if (!(int) $existe->fetchColumn()) {
                    $this->db->prepare(
                        "INSERT INTO cargos_mensuales (id_alumno, id_inscripcion, periodo, monto, precio_mensual, clases,
                                                       clases_mes, hasta, motivo)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    )->execute([(int) $ins['id_alumno'], $idInscripcion, $mes, $monto, $precio, $n, $semanas,
                                $ins['fecha_baja'], $motivo]);
                }
            }

            $this->registrarCambios($idInscripcion, $antes, $this->fotoDeClase($idInscripcion), $datos + [
                'motivo' => $datos['motivo'] ?? ($modo === 'no_debia_existir'
                    ? 'Corrección de baja: el registro no debía existir'
                    : "Corrección de baja: tomó $n clases ese mes"),
            ]);
            $this->confirmar($propia);
        } catch (Throwable $e) {
            $this->deshacer($propia);
            throw new Exception('No se pudo corregir la baja: ' . $e->getMessage());
        }
        return ['clases' => $n, 'periodo' => $mes, 'cargo' => $monto];
    }

    // =================================================================
    // OPERACIONES DE UN SOLO PASO
    // =================================================================
    // Lo que antes pedía malabares (mover a una a una hora falsa, acomodar a
    // la otra, regresar a la primera) se hace aquí en un paso, dentro de una
    // sola transacción y con las mismas reglas de siempre: si algo no se
    // puede, no cambia nada.

    /** Los datos de horario de una clase, en la forma que pide completar() */
    private function datosDeClase(array $ins): array
    {
        return [
            'id_maestro' => (int) $ins['id_maestro'],
            'id_instrumento' => $ins['id_instrumento'] ? (int) $ins['id_instrumento'] : null,
            'modalidad' => $ins['modalidad'] ?? 'presencial',
            'id_salon' => $ins['id_salon'] ? (int) $ins['id_salon'] : null,
            'dia_semana' => $ins['dia_semana'], 'hora_inicio' => $ins['hora_inicio'], 'hora_fin' => $ins['hora_fin'],
            'duracion_min' => (int) $ins['duracion_min'], 'costo_mensual' => $ins['costo_mensual'],
        ];
    }

    private function claseViva(int $id, string $quien): array
    {
        $ins = $this->inscripcionModel->obtener($id);
        if (!$ins) throw new Exception("La clase de $quien no existe.");
        if ($ins['estado'] === 'baja') throw new Exception("La clase de $quien está dada de baja.");
        return $ins;
    }

    private function nombreDeAlumno(array $ins): string
    {
        $s = $this->db->prepare("SELECT CONCAT_WS(' ', nombre, apellido) FROM alumnos WHERE id_alumno = ?");
        $s->execute([(int) $ins['id_alumno']]);
        return (string) $s->fetchColumn();
    }

    /**
     * Juntar dos clases en una sola compartida: la primera queda en el horario
     * pedido y la segunda entra a compartirla, con ese mismo horario y
     * duración. Cada una conserva su inscripción, su precio y su nómina.
     *
     * @param array $datos el horario final (id_maestro, id_salon, modalidad,
     *                     dia_semana, hora_inicio, hora_fin, duracion_min) y motivo
     */
    public function juntar(int $idA, int $idB, array $datos): array
    {
        return HorarioModel::conCandado(fn() => $this->juntarAdentro($idA, $idB, $datos));
    }

    private function juntarAdentro(int $idA, int $idB, array $datos): array
    {
        if ($idA === $idB) throw new Exception('Elige otra clase para juntar.');
        $a = $this->claseViva($idA, 'la primera alumna');
        $b = $this->claseViva($idB, 'la segunda alumna');
        foreach ([$a, $b] as $x) {
            if ($this->horarioModel->esGrupal($x['id_instrumento'] ? (int) $x['id_instrumento'] : null)) {
                throw new Exception('Dibujo y Pintura no se juntan así: son un taller y sus alumnos ya quedan juntos con solo tener '
                    . 'el mismo maestro el mismo día.');
            }
        }
        // Nadie más puede estar compartiendo con ellas: una compartida es de dos.
        foreach ([[$a, $idB], [$b, $idA]] as [$x, $otra]) {
            $terceros = array_diff($this->horarioModel->integrantes((int) $x['id_inscripcion']), [$otra]);
            if ($terceros || ($x['comparte_con'] && (int) $x['comparte_con'] !== $otra)) {
                throw new Exception($this->nombreDeAlumno($x) . ' ya comparte su clase con otra persona. '
                    . 'Una clase compartida es de máximo 2.');
            }
        }
        foreach (['dia_semana', 'hora_inicio', 'hora_fin'] as $c) {
            if (empty($datos[$c])) throw new Exception('Falta el horario de la clase compartida.');
        }
        $base = ['id_maestro' => (int) ($datos['id_maestro'] ?? $a['id_maestro']),
                 'modalidad' => ($datos['modalidad'] ?? $a['modalidad']) === 'online' ? 'online' : 'presencial',
                 'dia_semana' => $datos['dia_semana'], 'hora_inicio' => $datos['hora_inicio'], 'hora_fin' => $datos['hora_fin'],
                 'duracion_min' => (int) ($datos['duracion_min']
                      ?? round((strtotime($datos['hora_fin']) - strtotime($datos['hora_inicio'])) / 60)),
                 'fecha_cambio' => $datos['fecha_cambio'] ?? null, 'confirmar_grupo' => 1,
                 'motivo' => $datos['motivo'] ?? 'Se juntaron en una clase compartida',
                 'evidencia' => $datos['evidencia'] ?? null, 'usuario' => $datos['usuario'] ?? null];
        $base['id_salon'] = $base['modalidad'] === 'online' ? null : (int) ($datos['id_salon'] ?? $a['id_salon']);

        $propia = $this->empezar();
        try {
            // Si ya compartían, se sueltan; y la segunda deja su lugar. Así la
            // primera puede tomar cualquier hora, incluso una que se encime con
            // la suya de antes o la que tenía la segunda.
            $this->db->prepare("UPDATE inscripciones SET comparte_con = NULL WHERE id_inscripcion IN (?, ?)")
                     ->execute([$idA, $idB]);
            $this->db->prepare("DELETE FROM horario_ocupado WHERE id_inscripcion = ?")->execute([$idB]);
            $rA = $this->completar($idA, $base + [
                'id_instrumento' => $datos['id_instrumento'] ?? $a['id_instrumento'],
                'costo_mensual' => $datos['costo_mensual'] ?? $a['costo_mensual'], 'compartir_con' => null]);
            $rB = $this->completar($idB, $base + [
                'id_instrumento' => $b['id_instrumento'], 'costo_mensual' => $b['costo_mensual'], 'compartir_con' => $idA]);
            $this->confirmar($propia);
        } catch (Throwable $e) {
            $this->deshacer($propia);
            throw new Exception('No se pudieron juntar: ' . preg_replace('/^No se pudo asignar el horario: /', '', $e->getMessage()));
        }
        return ['a' => $rA, 'b' => $rB];
    }

    /**
     * Intercambiar el horario de dos clases individuales de la misma duración:
     * cada una se queda con su maestro, su materia y su precio, y toma el día,
     * la hora y el salón de la otra.
     */
    public function intercambiar(int $idA, int $idB, array $datos = []): array
    {
        return HorarioModel::conCandado(fn() => $this->intercambiarAdentro($idA, $idB, $datos));
    }

    private function intercambiarAdentro(int $idA, int $idB, array $datos = []): array
    {
        if ($idA === $idB) throw new Exception('Elige otra clase para intercambiar.');
        $a = $this->claseViva($idA, 'la primera clase');
        $b = $this->claseViva($idB, 'la segunda clase');
        foreach ([$a, $b] as $x) {
            if (!$x['dia_semana']) throw new Exception($this->nombreDeAlumno($x) . ' todavía no tiene horario.');
            if ($x['comparte_con'] || $this->horarioModel->integrantes((int) $x['id_inscripcion'])) {
                throw new Exception($this->nombreDeAlumno($x) . ' comparte su clase: cámbiala desde su edición, '
                    . 'con "mover también" a quien la comparte.');
            }
        }
        if ((int) $a['duracion_min'] !== (int) $b['duracion_min']) {
            throw new Exception('Duran distinto (' . $a['duracion_min'] . ' y ' . $b['duracion_min'] . ' minutos): '
                . 'cámbialas una por una.');
        }
        $extra = ['motivo' => $datos['motivo'] ?? ('Intercambio de horario con ' . $this->nombreDeAlumno($b)),
                  'usuario' => $datos['usuario'] ?? null, 'confirmar_grupo' => 1];
        $slot = fn(array $x) => ['modalidad' => $x['modalidad'], 'id_salon' => $x['id_salon'] ? (int) $x['id_salon'] : null,
                                 'dia_semana' => $x['dia_semana'], 'hora_inicio' => $x['hora_inicio'],
                                 'hora_fin' => $x['hora_fin'], 'duracion_min' => (int) $x['duracion_min']];

        $propia = $this->empezar();
        try {
            // Las dos sueltan su lugar; luego cada una toma el de la otra.
            $this->db->prepare("DELETE FROM horario_ocupado WHERE id_inscripcion IN (?, ?)")->execute([$idA, $idB]);
            $rA = $this->completar($idA, $slot($b) + $this->datosDeClase($a) + $extra);
            $rB = $this->completar($idB, $slot($a) + $this->datosDeClase($b)
                + ['motivo' => $datos['motivo'] ?? ('Intercambio de horario con ' . $this->nombreDeAlumno($a))] + $extra);
            $this->confirmar($propia);
        } catch (Throwable $e) {
            $this->deshacer($propia);
            throw new Exception('No se pudo intercambiar: ' . preg_replace('/^No se pudo asignar el horario: /', '', $e->getMessage()));
        }
        return ['a' => $rA, 'b' => $rB];
    }

    /**
     * Deshacer una baja hecha por error: la clase vuelve con su horario (si
     * sigue libre), su nómina y su cobro del mes en curso en adelante. Lo de
     * los meses ya cerrados no se toca.
     */
    public function reactivar(int $idInscripcion, array $datos = []): array
    {
        return HorarioModel::conCandado(fn() => $this->reactivarAdentro($idInscripcion, $datos));
    }

    private function reactivarAdentro(int $idInscripcion, array $datos = []): array
    {
        $ins = $this->inscripcionModel->obtener($idInscripcion);
        if (!$ins) throw new Exception('La clase no existe.');
        if ($ins['estado'] !== 'baja') throw new Exception('Esa clase no está dada de baja.');
        $mes = date('Y-m');

        // Con un maestro o un salón desactivados no se reactiva: se dice tal cual, sin
        // confundirlo con "su horario ya no está libre".
        $inactivo = $this->horarioModel->motivoDeInactivo((int) $ins['id_maestro'],
            $ins['dia_semana'] && ($ins['modalidad'] ?? 'presencial') !== 'online' && $ins['id_salon'] ? (int) $ins['id_salon'] : null);
        if ($inactivo) throw new Exception('No se pudo reactivar: ' . $inactivo);

        $hueco = null;
        if ($ins['dia_semana']) {
            $d = $this->datosDeClase($ins) + ['id_alumno' => (int) $ins['id_alumno']];
            try {
                $hueco = $this->resolverHueco($d + ['compartir_con' => $ins['comparte_con']], $idInscripcion);
            } catch (Exception $e) {
                if (!$ins['comparte_con']) throw new Exception('Su horario ya no está libre: ' . $e->getMessage()
                    . ' Reactívala y luego cámbiale la hora, o inscríbela de nuevo.');
                $hueco = $this->resolverHueco($d, $idInscripcion);   // la que compartía ya no está: va sola
            }
        }

        $antes = $this->fotoDeClase($idInscripcion);
        $propia = $this->empezar();
        try {
            $this->db->prepare(
                "UPDATE inscripciones SET estado = ?, fecha_baja = NULL, comparte_con = ? WHERE id_inscripcion = ?"
            )->execute([$ins['dia_semana'] ? 'activa' : 'pendiente', $hueco['comparte_con'] ?? null, $idInscripcion]);
            if ($hueco && !$hueco['comparte_con']) {
                $this->db->prepare(
                    "INSERT INTO horario_ocupado (id_inscripcion, id_salon, id_maestro, dia_semana, hora_inicio, hora_fin, en_taller)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                )->execute([$idInscripcion, $hueco['id_salon'], (int) $ins['id_maestro'], $ins['dia_semana'],
                            $ins['hora_inicio'], $ins['hora_fin'], $this->horarioModel->enTallerDe($idInscripcion)]);
            }
            $this->db->prepare("UPDATE alumnos SET activo = 1 WHERE id_alumno = ?")->execute([(int) $ins['id_alumno']]);
            // Del mes en curso en adelante, como si nunca se hubiera ido. Lo que se
            // quita (el cargo que dejó la baja) queda en la bitácora.
            $quita = $this->db->prepare("SELECT periodo, monto FROM cargos_mensuales WHERE id_inscripcion = ? AND periodo >= ?");
            $quita->execute([$idInscripcion, $mes]);
            foreach ($quita->fetchAll() as $c) {
                Bitacora::registrar($this->db, 'reactivar clase', 'inscripciones', $idInscripcion, 'cargo ' . $c['periodo'],
                    $c['monto'], null, $datos['motivo'] ?? 'Se reactivó: la baja fue un error', null, $datos['usuario'] ?? null);
            }
            $this->db->prepare("DELETE FROM cargos_mensuales WHERE id_inscripcion = ? AND periodo >= ?")
                     ->execute([$idInscripcion, $mes]);
            NominaModel::borrarRenglones($this->db, "nd.id_inscripcion = ? AND nd.periodo >= ?", [$idInscripcion, $mes],
                'Se quitó al reactivar la clase: la nómina se arma de nuevo (tenía un ajuste manual)', $datos['usuario'] ?? null);
            $this->nominaModel->sincronizarMes($mes);
            $this->registrarCambios($idInscripcion, $antes, $this->fotoDeClase($idInscripcion), $datos + [
                'motivo' => $datos['motivo'] ?? 'Se reactivó: la baja fue un error']);
            $this->confirmar($propia);
        } catch (Throwable $e) {
            $this->deshacer($propia);
            throw new Exception('No se pudo reactivar: ' . $e->getMessage());
        }
        return $this->inscripcionModel->obtener($idInscripcion);
    }

    /**
     * Dar de baja a un alumno con todas sus clases, en un paso. Si algo no se
     * puede (por ejemplo, falta decir cuántas clases tomó en una sin día), no
     * se da de baja ninguna.
     *
     * @param array $datos fecha_baja, conservar_adeudo, clases_tomadas: [id_inscripcion => n], motivo, usuario
     */
    public function darDeBajaAlumno(int $idAlumno, array $datos): array
    {
        return HorarioModel::conCandado(fn() => $this->darDeBajaAlumnoAdentro($idAlumno, $datos));
    }

    private function darDeBajaAlumnoAdentro(int $idAlumno, array $datos): array
    {
        $s = $this->db->prepare("SELECT id_inscripcion FROM inscripciones WHERE id_alumno = ? AND estado <> 'baja'");
        $s->execute([$idAlumno]);
        $ids = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
        $tomadas = (array) ($datos['clases_tomadas'] ?? []);

        $propia = $this->empezar();
        try {
            $resultados = [];
            foreach ($ids as $id) {
                $resultados[$id] = $this->darDeBaja($id, ['clases_tomadas' => $tomadas[$id] ?? null] + $datos);
            }
            (new AlumnoModel())->desactivar($idAlumno);
            $this->confirmar($propia);
        } catch (Throwable $e) {
            $this->deshacer($propia);
            throw new Exception(preg_replace('/^No se pudo completar la baja: /', '', $e->getMessage()));
        }
        $exactas = [];
        foreach ($resultados as $r) foreach ($r['lista_espera']['exactas'] ?? [] as $x) $exactas[(int) $x['id_lista_espera']] = $x;
        return ['clases' => count($ids), 'resultados' => $resultados,
                'lista_espera' => $exactas ? ['exactas' => array_values($exactas), 'alternativas' => []] : null];
    }

    /**
     * Un maestro se va: sus clases pasan a otro desde una fecha, en un paso.
     * El mes se reparte como en cualquier cambio de maestro. Si alguna clase
     * choca con el horario del nuevo, no se pasa ninguna y se dice cuáles.
     */
    public function pasarClasesDeMaestro(int $idMaestro, int $idNuevo, ?string $desde, ?string $usuario): array
    {
        return HorarioModel::conCandado(fn() => $this->pasarClasesDeMaestroAdentro($idMaestro, $idNuevo, $desde, $usuario));
    }

    private function pasarClasesDeMaestroAdentro(int $idMaestro, int $idNuevo, ?string $desde, ?string $usuario): array
    {
        if ($idMaestro === $idNuevo) throw new Exception('Elige a otro maestro.');
        $n = $this->db->prepare("SELECT COUNT(*) FROM maestros WHERE id_maestro = ? AND activo = 1");
        $n->execute([$idNuevo]);
        if (!(int) $n->fetchColumn()) throw new Exception('El maestro nuevo no existe o está dado de baja.');

        // Primero las que ocupan el horario; después las que comparten con ellas.
        $s = $this->db->prepare(
            "SELECT * FROM inscripciones WHERE id_maestro = ? AND estado <> 'baja'
              ORDER BY comparte_con IS NOT NULL, dia_semana IS NULL, id_inscripcion"
        );
        $s->execute([$idMaestro]);
        $clases = $s->fetchAll();
        $motivo = 'El maestro dejó la escuela: sus clases pasan a otro maestro';
        // Desde qué día las da el nuevo: lo de antes es del maestro que se va.
        $fechaCambio = self::fechaDelMes($desde, 'del cambio de maestro', 0);
        $maestroNuevo = (new MaestroModel())->obtener($idNuevo) ?: ['id_maestro' => $idNuevo];

        $propia = $this->empezar();
        $fallas = [];
        $hechas = [];
        try {
            foreach ($clases as $ins) {
                $id = (int) $ins['id_inscripcion'];
                if (isset($hechas[$id])) continue;
                if (!$ins['dia_semana']) {
                    // Sin horario: cambia de maestro y el mes se reparte por fecha, igual
                    // que en una clase con horario. Antes, la sincronización le pasaba
                    // el mes completo al nuevo y el que se iba perdía lo que ya dio.
                    $antes = $this->fotoDeClase($id);
                    $this->nominaModel->cambiarMaestro($this->db, $ins, $maestroNuevo, $fechaCambio, null,
                                                       (int) $ins['duracion_min']);
                    $this->db->prepare("UPDATE inscripciones SET id_maestro = ? WHERE id_inscripcion = ?")->execute([$idNuevo, $id]);
                    $this->registrarCambios($id, $antes, $this->fotoDeClase($id), ['motivo' => $motivo, 'usuario' => $usuario]);
                    continue;
                }
                try {
                    // Quienes comparten con ella se sueltan y vuelven a entrar ya
                    // con el maestro nuevo: si no, se estorbarían en su propio salón.
                    $miembros = $this->horarioModel->integrantes($id);
                    if ($miembros) {
                        $this->db->prepare("UPDATE inscripciones SET comparte_con = NULL WHERE comparte_con = ?")->execute([$id]);
                    }
                    $grupal = $this->horarioModel->esGrupal($ins['id_instrumento'] ? (int) $ins['id_instrumento'] : null);
                    $cambio = ['id_maestro' => $idNuevo, 'fecha_cambio' => $desde, 'confirmar_grupo' => 1,
                               'motivo' => $motivo, 'usuario' => $usuario];
                    $this->completar($id, $cambio + ['compartir_con' => null] + $this->datosDeClase($ins));
                    $hechas[$id] = true;
                    foreach ($miembros as $m) {
                        $mi = $this->inscripcionModel->obtener($m);
                        $this->completar($m, $cambio + ['compartir_con' => $grupal ? null : $id] + $this->datosDeClase($mi));
                        $hechas[$m] = true;
                    }
                } catch (Throwable $e) {
                    $fallas[] = $this->nombreDeAlumno($ins) . ' (' . $ins['dia_semana'] . ' ' . substr($ins['hora_inicio'], 0, 5) . '): '
                              . preg_replace('/^No se pudo asignar el horario: /', '', $e->getMessage());
                }
            }
            if ($fallas) throw new Exception("No se pasó ninguna clase porque estas chocan con el horario del maestro nuevo:
· "
                                             . implode("
· ", $fallas));
            $this->db->prepare("UPDATE maestros SET activo = 0 WHERE id_maestro = ?")->execute([$idMaestro]);
            $this->confirmar($propia);
        } catch (Throwable $e) {
            $this->deshacer($propia);
            throw $e;
        }
        return ['clases' => count($clases)];
    }

    // =================================================================
    // TRANSACCIONES QUE SE PUEDEN ANIDAR
    // =================================================================
    // Una operación de varios pasos (juntar dos clases, intercambiarlas, dar
    // de baja a un alumno con todas sus clases) abre una sola transacción y
    // llama a las operaciones de siempre: esas se suman a la que ya está
    // abierta en lugar de abrir la suya. Si algo falla, no queda nada a medias.

    /** @return bool si esta llamada abrió la transacción (y le toca cerrarla) */
    private function empezar(): bool
    {
        if ($this->db->inTransaction()) return false;
        $this->db->beginTransaction();
        return true;
    }

    private function confirmar(bool $propia): void
    {
        if ($propia) $this->db->commit();
    }

    private function deshacer(bool $propia): void
    {
        if ($propia && $this->db->inTransaction()) $this->db->rollBack();
        HistorialClase::olvidar();
        ConfiguracionModel::olvidarSemanas();
    }

    // =================================================================
    // BITÁCORA DE LA CLASE
    // =================================================================

    private const CAMPOS_BITACORA = ['estado', 'fecha_baja', 'id_maestro', 'id_instrumento', 'modalidad', 'id_salon',
        'dia_semana', 'hora_inicio', 'hora_fin', 'duracion_min', 'costo_mensual', 'comparte_con'];

    /** Cómo está una clase ahora: sus datos y su nómina sin pagar, por mes */
    private function fotoDeClase(int $idInscripcion): array
    {
        $s = $this->db->prepare("SELECT * FROM inscripciones WHERE id_inscripcion = ?");
        $s->execute([$idInscripcion]);
        $f = $s->fetch() ?: [];
        $foto = [];
        foreach (self::CAMPOS_BITACORA as $c) $foto[$c] = isset($f[$c]) ? (string) $f[$c] : null;

        $n = $this->db->prepare(
            "SELECT nd.periodo, nd.monto, nd.clases, CONCAT_WS(' ', m.nombre, m.apellido) AS maestro
               FROM nomina_detalle nd JOIN maestros m ON m.id_maestro = nd.id_maestro
              WHERE nd.id_inscripcion = ? AND nd.pagado = 0 ORDER BY nd.periodo, nd.id_detalle"
        );
        $n->execute([$idInscripcion]);
        foreach ($n->fetchAll() as $r) {
            $clave = 'nomina ' . $r['periodo'];
            $texto = '$' . number_format((float) $r['monto'], 2) . ' ' . $r['maestro']
                   . ($r['clases'] !== null ? ' (' . $r['clases'] . ' clases)' : '');
            $foto[$clave] = isset($foto[$clave]) ? $foto[$clave] . ' + ' . $texto : $texto;
        }
        return $foto;
    }

    /**
     * Guarda en la bitácora cada campo que cambió, con un folio común. Va
     * dentro de la transacción de quien llama.
     *
     * @param array $datos motivo, evidencia y usuario, si vienen
     */
    private function registrarCambios(int $idInscripcion, array $antes, array $despues, array $datos): void
    {
        $folio = date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $ins = $this->db->prepare(
            "INSERT INTO inscripciones_cambios (id_inscripcion, operacion, campo, valor_anterior, valor_nuevo,
                                                motivo, evidencia, usuario)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $motivo = mb_substr(trim((string) ($datos['motivo'] ?? '')), 0, 255) ?: null;
        $evidencia = mb_substr(trim((string) ($datos['evidencia'] ?? '')), 0, 255) ?: null;
        $usuario = mb_substr(trim((string) ($datos['usuario'] ?? '')), 0, 100) ?: null;
        foreach (array_unique(array_merge(array_keys($antes), array_keys($despues))) as $campo) {
            $a = $antes[$campo] ?? null; $d = $despues[$campo] ?? null;
            if ($a === $d) continue;
            $ins->execute([$idInscripcion, $folio, $campo, $a === null ? null : mb_substr($a, 0, 200),
                           $d === null ? null : mb_substr($d, 0, 200), $motivo, $evidencia, $usuario]);
        }
    }

    /** La bitácora de una clase, de lo más nuevo a lo más viejo */
    public function cambiosDe(int $idInscripcion): array
    {
        $s = $this->db->prepare("SELECT * FROM inscripciones_cambios WHERE id_inscripcion = ? ORDER BY fecha DESC, id_cambio");
        $s->execute([$idInscripcion]);
        $filas = $s->fetchAll();
        // "Comparte con" se lee mejor con el nombre de la otra alumna que con un número.
        $nombre = $this->db->prepare(
            "SELECT CONCAT_WS(' ', a.nombre, a.apellido) FROM inscripciones i JOIN alumnos a ON a.id_alumno = i.id_alumno
              WHERE i.id_inscripcion = ?"
        );
        foreach ($filas as &$f) {
            if ($f['campo'] !== 'comparte_con') continue;
            foreach (['valor_anterior', 'valor_nuevo'] as $k) {
                if (!$f[$k]) continue;
                $nombre->execute([(int) $f[$k]]);
                $n = $nombre->fetchColumn();
                if ($n) $f[$k] = $n;
            }
        }
        unset($f);
        return $filas;
    }
}

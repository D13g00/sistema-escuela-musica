<?php
require_once __DIR__ . '/../config/Respuesta.php';
require_once __DIR__ . '/../config/Auth.php';
require_once __DIR__ . '/../models/InscripcionModel.php';
require_once __DIR__ . '/../services/InscripcionService.php';

Respuesta::iniciar();
$modelo = new InscripcionModel();
$servicio = new InscripcionService();
$metodo = $_SERVER['REQUEST_METHOD'];

// Todo requiere sesión: la lista trae nombres, horarios y colegiaturas.
// Las escrituras piden además el token.
Auth::requerirLogin();
if (in_array($metodo, ['POST', 'PUT', 'DELETE'])) {
    Auth::exigirCsrf();
    Auth::requerirRol('admin', 'recepcion');
}

try {
    switch ($metodo) {
        case 'GET':
            // Vista previa: dónde caería la clase (sola, en un grupo o compartida)
            // sin guardar nada. La pantalla la usa para pedir confirmación.
            if (($_GET['accion'] ?? '') === 'hueco') {
                $id = (int) ($_GET['id'] ?? 0) ?: null;
                $d = $_GET;
                unset($d['ignorar_ocupacion']);   // eso solo lo decide el servidor
                if ($id && ($ins = $modelo->obtener($id))) $d['id_alumno'] = $ins['id_alumno'];
                // "Ahí no se puede" es una respuesta normal de la vista previa, no un error.
                try {
                    $hueco = $servicio->resolverHueco($d, $id);
                } catch (Throwable $e) {
                    Respuesta::exito(['posible' => false, 'error' => Respuesta::mensaje($e)]);
                }
                Respuesta::exito(['posible' => true] + $hueco);
            }
            // La primera hora libre del día para esa clase (el alta la ofrece de entrada en vez de una que choca).
            if (($_GET['accion'] ?? '') === 'primer_hueco') {
                $d = $_GET;
                unset($d['ignorar_ocupacion']);
                Respuesta::exito(['hora_inicio' => $servicio->primerHuecoLibre($d)]);
            }
            // Clases idénticas de ese hueco con las que se puede compartir.
            if (($_GET['accion'] ?? '') === 'compartibles') {
                require_once __DIR__ . '/../models/HorarioModel.php';
                $online = ($_GET['modalidad'] ?? '') === 'online';
                Respuesta::exito((new HorarioModel())->clasesParaCompartir(
                    $online ? null : ((int) ($_GET['id_salon'] ?? 0) ?: null),
                    (int) ($_GET['id_maestro'] ?? 0), (string) ($_GET['dia_semana'] ?? ''),
                    (string) ($_GET['hora_inicio'] ?? ''), (string) ($_GET['hora_fin'] ?? ''),
                    ((int) ($_GET['id'] ?? 0)) ?: null
                ));
            }
            // Con quién comparte esta clase (si es una compartida, no un grupo).
            if (($_GET['accion'] ?? '') === 'pareja') {
                require_once __DIR__ . '/../models/HorarioModel.php';
                $hm = new HorarioModel();
                $id = (int) ($_GET['id'] ?? 0);
                $ins = $modelo->obtener($id);
                $pareja = null;
                if ($ins && !$hm->esGrupal($ins['id_instrumento'] ? (int) $ins['id_instrumento'] : null)) {
                    $otras = $ins['comparte_con'] ? [(int) $ins['comparte_con']] : $hm->integrantes($id);
                    if (count($otras) === 1) {
                        $o = $modelo->obtener($otras[0]);
                        $n = Database::conexion()->prepare("SELECT nombre, apellido FROM alumnos WHERE id_alumno = ?");
                        $n->execute([(int) $o['id_alumno']]);
                        $pareja = ['id_inscripcion' => (int) $o['id_inscripcion']] + $n->fetch();
                    }
                }
                Respuesta::exito($pareja);
            }
            // Clases del maestro con las que se puede juntar en una compartida.
            if (($_GET['accion'] ?? '') === 'juntables') {
                require_once __DIR__ . '/../models/HorarioModel.php';
                Respuesta::exito((new HorarioModel())->juntables((int) ($_GET['id_maestro'] ?? 0), ((int) ($_GET['id'] ?? 0)) ?: null));
            }
            // Quién ocupa un horario (para ofrecer intercambiar).
            if (($_GET['accion'] ?? '') === 'ocupantes') {
                require_once __DIR__ . '/../models/HorarioModel.php';
                $online = ($_GET['modalidad'] ?? '') === 'online';
                Respuesta::exito((new HorarioModel())->ocupantes(
                    $online ? null : (((int) ($_GET['id_salon'] ?? 0)) ?: null), (int) ($_GET['id_maestro'] ?? 0),
                    (string) ($_GET['dia_semana'] ?? ''), (string) ($_GET['hora_inicio'] ?? ''),
                    (string) ($_GET['hora_fin'] ?? ''), ((int) ($_GET['id'] ?? 0)) ?: null));
            }
            // Bitácora de una clase: qué cambió, quién, cuándo y por qué.
            if (($_GET['accion'] ?? '') === 'cambios') {
                Respuesta::exito($servicio->cambiosDe((int) ($_GET['id'] ?? 0)));
            }
            // El primer día que puede elegirse para un cambio de duración o de día: no antes del último cambio que ya rige.
            if (($_GET['accion'] ?? '') === 'ultimo_cambio') {
                require_once __DIR__ . '/../models/HistorialClaseModel.php';
                $u = HistorialClase::ultimaQueYaRige(HistorialClase::de((int) ($_GET['id'] ?? 0)));
                Respuesta::exito(['fecha' => $u && $u['desde'] !== HistorialClase::ORIGEN && $u['desde'] >= date('Y-m-01') ? $u['desde'] : null]);
            }
            // Vista previa del cambio de duración: qué pasaría con la colegiatura (sin guardar nada).
            if (($_GET['accion'] ?? '') === 'precio_previa') {
                Respuesta::exito($servicio->vistaPrecio((int) ($_GET['id'] ?? 0), $_GET));
            }
            if (($_GET['accion'] ?? '') === 'baja_previa') {
                require_once __DIR__ . '/../models/CuentaModel.php';
                $ins = $modelo->obtener((int) ($_GET['id'] ?? 0));
                if (!$ins) Respuesta::error('La inscripción no existe.');
                $cuenta = new CuentaModel();
                $est = $cuenta->estado((int) $ins['id_alumno']);
                $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['fecha'] ?? '') ? $_GET['fecha'] : date('Y-m-d');
                // Lo que esta clase dejaría cobrado del mes: las clases que tomó hasta ese día.
                $clase = $cuenta->cargoDeClase($ins, substr($fecha, 0, 7), $fecha);
                Respuesta::exito([
                    'adeudo'       => $est['adeudo'],
                    'meses_debe'   => $est['meses_debe'],
                    'del_mes'      => $clase['monto'],
                    'clases_mes'   => $clase['clases'] ?? $clase['semanas'],
                    'semanas'      => $clase['semanas'],
                    'precio'       => $clase['precio'],
                    'colegiatura'  => $est['colegiatura'],
                ]);
            }
            if (($_GET['accion'] ?? '') === 'primera') {
                $modelo = new InscripcionModel();
                Respuesta::exito(['primera' => $modelo->esPrimeraClase((int) ($_GET['alumno'] ?? 0))]);
            }
            // ?estado=activa | baja  (sin parámetro = todas)
            $estado = $_GET['estado'] ?? null;
            Respuesta::exito($modelo->listar($estado));
            break;

        case 'POST':
            // ALTA: dispara la cascada -> inscripciones + horario_ocupado + nomina_detalle + pagos
            $datos = Respuesta::cuerpoJson();

            // Operaciones de un solo paso sobre dos clases.
            $usuario = Auth::usuarioActual()['nombre'] ?? null;
            if (($_GET['accion'] ?? '') === 'juntar') {
                // Simulación: se hace completo dentro de una transacción y se
                // deshace, para decir antes de guardar si las dos caben.
                if (!empty($datos['simular'])) {
                    $db = Database::conexion();
                    $db->beginTransaction();
                    try {
                        $servicio->juntar((int) ($datos['id_a'] ?? 0), (int) ($datos['id_b'] ?? 0), $datos);
                        $db->rollBack();
                        Respuesta::exito(['posible' => true]);
                    } catch (Throwable $e) {
                        if ($db->inTransaction()) $db->rollBack();
                        Respuesta::exito(['posible' => false,
                                          'error' => preg_replace('/^No se pudieron juntar: /', '', Respuesta::mensaje($e))]);
                    }
                }
                Respuesta::exito($servicio->juntar((int) ($datos['id_a'] ?? 0), (int) ($datos['id_b'] ?? 0),
                                                   ['usuario' => $usuario] + $datos));
            }
            if (($_GET['accion'] ?? '') === 'intercambiar') {
                Respuesta::exito($servicio->intercambiar((int) ($datos['id_a'] ?? 0), (int) ($datos['id_b'] ?? 0),
                                                         ['usuario' => $usuario] + $datos));
            }

            // Varios alumnos al mismo grupo de dibujo o pintura
            $grupo = is_array($datos['ids_alumnos'] ?? null) ? $datos['ids_alumnos'] : null;

            // En línea no lleva salón; presencial sí.
            $requeridos = ['id_maestro','id_instrumento','dia_semana','hora_inicio','hora_fin','costo_mensual'];
            if (($datos['modalidad'] ?? 'presencial') !== 'online') $requeridos[] = 'id_salon';
            if (!$grupo && empty($datos['alumno_nuevo'])) $requeridos[] = 'id_alumno';
            foreach ($requeridos as $campo) {
                if (!isset($datos[$campo]) || $datos[$campo] === '') {
                    Respuesta::error("Falta el campo requerido: $campo");
                }
            }

            if ($grupo) {
                unset($datos['ids_alumnos'], $datos['id_alumno']);
                Respuesta::exito($servicio->darDeAltaGrupo($datos, $grupo, $usuario), 201);
            }
            $creada = $servicio->darDeAlta($datos, $usuario);
            if (!Auth::tieneRol('admin', 'consulta')) unset($creada['primer_mes']['cobra_maestro']);
            Respuesta::exito($creada, 201);
            break;

        case 'PUT':
            // Completar una inscripción pendiente, o mover una clase ya agendada.
            $datos = Respuesta::cuerpoJson();
            $id = (int) ($_GET['id'] ?? $datos['id_inscripcion'] ?? 0);
            if (!$id) Respuesta::error('id_inscripcion es requerido');
            // Deshacer una baja hecha por error.
            if (($_GET['accion'] ?? '') === 'reactivar') {
                Respuesta::exito($servicio->reactivar($id, ['usuario' => Auth::usuarioActual()['nombre'] ?? null] + $datos));
            }
            // Corregir una baja ya hecha (no debía existir / cuántas clases tomó).
            if (($_GET['accion'] ?? '') === 'corregir_baja') {
                Auth::requerirRol('admin');   // cambia nómina y cargo de un mes: corrección financiera
                Respuesta::exito($servicio->corregirBaja($id, ['usuario' => Auth::usuarioActual()['nombre'] ?? null] + $datos));
            }
            $datos['usuario'] = Auth::usuarioActual()['nombre'] ?? null;
            unset($datos['rige_todo_el_mes']);   // solo lo manda el importador de horarios
            $obligatorios = ['dia_semana','hora_inicio','hora_fin'];
            if (($datos['modalidad'] ?? 'presencial') !== 'online') $obligatorios[] = 'id_salon';
            foreach ($obligatorios as $campo) {
                if (empty($datos[$campo])) Respuesta::error("Falta el campo requerido: $campo");
            }
            $hecho = $servicio->completar($id, $datos);
            // Lo que se le paga al maestro solo lo ve administración.
            if (!Auth::tieneRol('admin', 'consulta')) unset($hecho['cambio_nomina'], $hecho['reparto_nomina']);
            Respuesta::exito($hecho);
            break;

        case 'DELETE':
            // BAJA: dispara la cascada inversa -> libera horario y nómina, conserva historial
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) Respuesta::error('id (id_inscripcion) es requerido');
            // "Ya no va" / "no debía existir" deja el mes sin cargo: corrección financiera.
            if (($_GET['adeudo'] ?? '1') === '0') Auth::requerirRol('admin');
            $r = $servicio->darDeBaja($id, [
                'fecha_baja'       => $_GET['fecha'] ?? null,
                // Por omisión la baja conserva lo que el alumno debía.
                'conservar_adeudo' => ($_GET['adeudo'] ?? '1') !== '0',
                'cobrar_mes'       => ($_GET['cobrar_mes'] ?? '1') !== '0',
                // Solo para clases sin día: cuántas tomó este mes (no se puede contar).
                'clases_tomadas'   => $_GET['clases'] ?? null,
                'motivo'           => $_GET['motivo'] ?? null,
                'usuario'          => Auth::usuarioActual()['nombre'] ?? null,
            ]);
            Respuesta::exito(['baja' => true] + $r);
            break;

        default:
            Respuesta::error('Método no soportado', 405);
    }
} catch (Throwable $e) {
    $m = $e->getMessage();
    if (($p = strpos($m, 'CONFIRMAR_GRUPO: ')) !== false) {
        Respuesta::error(substr($m, $p + strlen('CONFIRMAR_GRUPO: ')), 409);
    }
    Respuesta::fallo($e, 400);
}

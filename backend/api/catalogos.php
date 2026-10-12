<?php
/**
 * Catálogos: materias que imparte la escuela y espacios donde se dan las clases.
 *
 *  GET    ?tipo=instrumentos | salones | bloques | horario_escuela | todo
 *  POST   ?tipo=instrumentos {nombre}
 *  POST   ?tipo=salones      {nombre, capacidad, tipo}
 *  PUT    ?tipo=...&id=N     {…}
 *  DELETE ?tipo=...&id=N     (no borra si está en uso: lo desactiva o avisa)
 */
require_once __DIR__ . '/../config/Respuesta.php';
require_once __DIR__ . '/../config/Auth.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../models/HorarioModel.php';
require_once __DIR__ . '/../models/ConfiguracionModel.php';

Respuesta::iniciar();

$metodo = $_SERVER['REQUEST_METHOD'];
$tipo   = $_GET['tipo'] ?? '';

// Todo exige sesión. No hay catálogo que un desconocido tenga por qué leer.
Auth::requerirLogin();
if (in_array($_SERVER['REQUEST_METHOD'], ['POST','PUT','DELETE'], true)) { Auth::exigirCsrf(); Auth::requerirRol('admin'); }

$db = Database::conexion();
$horarioModel = new HorarioModel();

try {
    /* ---------------------------------------------------------- Lectura */
    if ($metodo === 'GET') {
        switch ($tipo) {
            case 'instrumentos':
                Respuesta::exito($db->query(
                    "SELECT i.*,
                            (SELECT COUNT(*) FROM inscripciones
                              WHERE id_instrumento = i.id_instrumento
                                AND comparte_con IS NOT NULL AND estado <> 'baja') AS en_grupo,
                            (SELECT COUNT(*) FROM inscripciones WHERE id_instrumento = i.id_instrumento) AS en_uso,
                            (SELECT COUNT(*) FROM maestro_instrumento WHERE id_instrumento = i.id_instrumento) AS maestros
                       FROM instrumentos i ORDER BY i.nombre"
                )->fetchAll());
            case 'salones':
                Respuesta::exito($horarioModel->salones());
            case 'salones_todos':
                Respuesta::exito($db->query(
                    "SELECT s.*,
                            (SELECT COUNT(*) FROM horario_ocupado WHERE id_salon = s.id_salon) AS clases
                       FROM salones s ORDER BY s.id_salon"
                )->fetchAll());
            case 'bloques':
                Respuesta::exito($horarioModel->bloques());
            case 'horario_escuela':
                Respuesta::exito($horarioModel->horarioEscuela());
            case 'red':
                // Dirección para entrar desde otra computadora de la escuela.
                // Se arma desde el servidor porque quien está en recepción no
                // tiene por qué saber buscar la IP con ipconfig.
                $ruta = preg_replace('#/backend/api/.*$#', '', $_SERVER['REQUEST_URI'] ?? '');
                // Sirve cualquier IP real de la máquina que no sea la de
                // "esta misma computadora" (127.x), que desde otra PC no lleva
                // a ningún lado.
                $util = fn($ip) => $ip
                    && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                    && !str_starts_with($ip, '127.');
                $candidatas = array_values(array_unique(array_filter([
                    $_SERVER['SERVER_ADDR'] ?? null,
                    @gethostbyname(@gethostname() ?: ''),
                ], $util)));

                Respuesta::exito([
                    'ip'        => $candidatas[0] ?? null,
                    'todas'     => $candidatas,
                    'ruta'      => $ruta . '/frontend/',
                    'direccion' => $candidatas
                        ? 'http://' . $candidatas[0] . $ruta . '/frontend/' : null,
                    'equipo'    => @gethostname() ?: null,
                    'puerto'    => $_SERVER['SERVER_PORT'] ?? '80',
                ]);
            case 'configuracion':
                Respuesta::exito((new ConfiguracionModel())->todo());
            case 'todo':
                Respuesta::exito([
                    'instrumentos'    => $db->query("SELECT * FROM instrumentos ORDER BY nombre")->fetchAll(),
                    'configuracion'   => (new ConfiguracionModel())->todo(),
                    'salones'         => $horarioModel->salones(),
                    'bloques'         => $horarioModel->bloques(),
                    'horario_escuela' => $horarioModel->horarioEscuela(),
                    'dias'            => HorarioModel::DIAS,
                ]);
            default:
                Respuesta::error('Parámetro "tipo" inválido.');
        }
    }

    $datos = Respuesta::cuerpoJson();
    $id    = (int) ($_GET['id'] ?? 0);

    /* ------------------------------------------------------ Instrumentos */
    /* ------------------------------------------ Horario de la escuela */
    if ($tipo === 'horario_escuela') {
        if ($metodo !== 'PUT') Respuesta::error('Usa PUT para guardar el horario.', 405);
        Respuesta::exito($horarioModel->guardarHorarioEscuela($datos['dias'] ?? []));
    }

    /* --------------------------------------------------- Cobros */
    if ($tipo === 'configuracion') {
        if ($metodo !== 'PUT') Respuesta::error('Usa PUT para guardar la configuración.', 405);
        Respuesta::exito((new ConfiguracionModel())->guardar($datos));
    }

    if ($tipo === 'instrumentos') {
        $nombre = trim($datos['nombre'] ?? '');
        $costo  = max(0, (float) ($datos['costo_mensual'] ?? 0));
        // Una materia grupal admite varios alumnos en el mismo horario y salón.
        $grupal = !empty($datos['es_grupal']) ? 1 : 0;

        if ($metodo === 'POST' || $metodo === 'PUT') {
            if ($nombre === '') Respuesta::error('Escribe el nombre de la materia.');
            if (mb_strlen($nombre) > 60) Respuesta::error('El nombre es demasiado largo.');

            $repetido = $db->prepare("SELECT COUNT(*) FROM instrumentos WHERE nombre = ? AND id_instrumento <> ?");
            $repetido->execute([$nombre, $id]);
            if ((int) $repetido->fetchColumn() > 0) Respuesta::error('Esa materia ya está en la lista.');
        }

        if ($metodo === 'POST') {
            $db->prepare("INSERT INTO instrumentos (nombre, costo_mensual, es_grupal) VALUES (?, ?, ?)")
               ->execute([$nombre, $costo, $grupal]);
            Respuesta::exito(['id_instrumento' => (int) $db->lastInsertId()], 201);
        }
        if ($metodo === 'PUT') {
            if (!$id) Respuesta::error('id es requerido.');
            // Cambiar «se da en grupo» cambia cómo se cuidan las clases de la materia
            // en el horario (taller: cada alumno a su hora). Todo o nada; y se niega
            // al quitarlo si esas clases se enciman entre sí.
            HorarioModel::conCandado(function () use ($db, $horarioModel, $nombre, $costo, $id, $grupal) {
                $db->beginTransaction();
                try {
                    $db->prepare("UPDATE instrumentos SET nombre = ?, costo_mensual = ? WHERE id_instrumento = ?")
                       ->execute([$nombre, $costo, $id]);
                    $horarioModel->cambiarTaller($id, (bool) $grupal);
                    $db->commit();
                } catch (Throwable $ex) {
                    if ($db->inTransaction()) $db->rollBack();
                    throw $ex;
                }
            });
            Respuesta::exito(['actualizado' => true]);
        }
        if ($metodo === 'DELETE') {
            if (!$id) Respuesta::error('id es requerido.');
            $uso = $db->prepare("SELECT COUNT(*) FROM inscripciones WHERE id_instrumento = ?");
            $uso->execute([$id]);
            if ((int) $uso->fetchColumn() > 0) {
                Respuesta::error('Esta materia tiene inscripciones a su nombre, así que no se puede borrar sin perder historial. Cámbiale el nombre si te equivocaste al escribirla.');
            }
            $db->prepare("DELETE FROM maestro_instrumento WHERE id_instrumento = ?")->execute([$id]);
            $db->prepare("DELETE FROM instrumentos WHERE id_instrumento = ?")->execute([$id]);
            Respuesta::exito(['eliminado' => true]);
        }
    }

    /* ----------------------------------------------------------- Salones */
    if ($tipo === 'salones') {
        $nombre     = trim($datos['nombre'] ?? '');
        $capacidad  = max(1, (int) ($datos['capacidad'] ?? 1));
        $tipoSalon  = in_array($datos['tipo'] ?? '', ['salon','compartido','exterior'], true)
                        ? $datos['tipo'] : 'salon';

        if ($metodo === 'POST') {
            if ($nombre === '') Respuesta::error('Escribe el nombre del espacio.');
            $db->prepare("INSERT INTO salones (nombre, capacidad, tipo) VALUES (?, ?, ?)")
               ->execute([$nombre, $capacidad, $tipoSalon]);
            Respuesta::exito(['id_salon' => (int) $db->lastInsertId()], 201);
        }
        if ($metodo === 'PUT') {
            if (!$id) Respuesta::error('id es requerido.');
            if (array_key_exists('activo', $datos)) {
                $activo = !empty($datos['activo']) ? 1 : 0;
                // Desactivarlo con clases activas se niega y dice cuáles (nada cambia).
                $horarioModel->activarSalon($id, (bool) $activo);
                Respuesta::exito(['activo' => (bool) $activo]);
            }
            if ($nombre === '') Respuesta::error('Escribe el nombre del espacio.');
            // Bajar la capacidad por debajo de los alumnos que ya coinciden a la vez se niega.
            $horarioModel->guardarSalon($id, $nombre, $capacidad, $tipoSalon);
            Respuesta::exito(['actualizado' => true]);
        }
        if ($metodo === 'DELETE') {
            Respuesta::error('Los espacios no se borran, se desactivan, para no perder el historial de clases.');
        }
    }

    Respuesta::error('Combinación de tipo y método no soportada.', 405);

} catch (Throwable $ex) {
    Respuesta::fallo($ex, 400);
}

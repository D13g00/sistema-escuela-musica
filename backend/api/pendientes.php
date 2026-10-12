<?php
/**
 * Lista de trabajo: lo que le falta al sistema para estar completo.
 *
 *  GET                          -> todo lo pendiente por resolver
 *  POST ?accion=fusionar        {tipo: maestros|alumnos, principal, duplicado}
 */
require_once __DIR__ . '/../config/Respuesta.php';
require_once __DIR__ . '/../config/Auth.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../services/Fusionador.php';
require_once __DIR__ . '/../services/BackupService.php';

/**
 * Antes de cualquier operación que junta o borra registros se guarda un
 * respaldo completo en el servidor. Si algo sale mal, se restaura desde
 * phpMyAdmin. Se conservan los 20 más recientes.
 */
function carpetaRespaldos(): string
{
    // 1º: FUERA de la carpeta pública. En XAMPP esto es C:\xampp\respaldos-escuela,
    //     que Apache no sirve nunca, pase lo que pase con los .htaccess.
    $fuera = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'respaldos-escuela';
    if ((is_dir($fuera) || @mkdir($fuera, 0750, true)) && is_writable($fuera)) {
        return $fuera;
    }
    // 2º: si ahí no se puede escribir, dentro del proyecto, pero bloqueada por
    //     .htaccess, con un index vacío y nombres imposibles de adivinar.
    $dentro = __DIR__ . '/../respaldos';
    if (!is_dir($dentro)) @mkdir($dentro, 0750, true);
    @file_put_contents($dentro . '/.htaccess', "Require all denied\n");
    @file_put_contents($dentro . '/index.html', '');
    return $dentro;
}

function respaldoPrevio(string $motivo): ?string
{
    try {
        $dir = carpetaRespaldos();
        // El sufijo aleatorio hace que el nombre no se pueda adivinar aunque la
        // carpeta quedara expuesta por un error de configuración.
        $nombre = 'antes-de-' . preg_replace('/[^a-z]/', '', $motivo) . '-' . date('Ymd-His')
                . '-' . bin2hex(random_bytes(6)) . '.sql';
        // Por partes y con una sola foto de la base; si algo falla a medias no queda un archivo cortado.
        (new BackupService())->generarEnArchivo($dir . DIRECTORY_SEPARATOR . $nombre);

        $todos = glob($dir . DIRECTORY_SEPARATOR . 'antes-de-*.sql') ?: [];
        rsort($todos);
        foreach (array_slice($todos, 20) as $viejo) { @unlink($viejo); }
        return $dir . DIRECTORY_SEPARATOR . $nombre;
    } catch (Throwable $e) {
        return null;
    }
}

Respuesta::iniciar();
Auth::requerirLogin();
if (in_array($_SERVER['REQUEST_METHOD'], ['POST','PUT','DELETE'], true)) { Auth::exigirCsrf(); Auth::requerirRol('admin'); }

$db = Database::conexion();
$fusionador = new Fusionador();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $datos = Respuesta::cuerpoJson();
        $accion = $_GET['accion'] ?? '';

        /**
         * Pone al día el precio que paga el alumno usando la tarifa de su
         * materia y la duración de su clase.
         *
         * Hace falta porque la importación de nómina guardó como colegiatura
         * lo que se le paga al MAESTRO, que es otra cosa: si a la maestra se
         * le pagan 450 por un alumno, el alumno no paga 450, paga 900 por su
         * hora. Solo toca las inscripciones que vinieron de una importación.
         */
        if ($accion === 'recalcular_precios') {
            $aplicar = !empty($datos['aplicar']);

            $filas = $db->query(
                "SELECT ins.id_inscripcion, ins.costo_mensual, ins.duracion_min,
                        COALESCE(i.costo_mensual, 0) AS tarifa_materia,
                        COALESCE(i.nombre, 'Sin materia') AS materia,
                        a.nombre AS alumno_nombre, a.apellido AS alumno_apellido
                   FROM inscripciones ins
                   JOIN alumnos a ON a.id_alumno = ins.id_alumno
                   LEFT JOIN instrumentos i ON i.id_instrumento = ins.id_instrumento
                  WHERE ins.estado <> 'baja' AND ins.origen = 'importacion'"
            )->fetchAll();

            $cambios = []; $sinMateria = 0;
            foreach ($filas as $f) {
                if ((float) $f['tarifa_materia'] <= 0) { $sinMateria++; continue; }
                $nuevo = round((float) $f['tarifa_materia'] * ((int) $f['duracion_min'] ?: 60) / 60, 2);
                if (abs($nuevo - (float) $f['costo_mensual']) < 0.01) continue;
                $cambios[] = [
                    'id_inscripcion' => (int) $f['id_inscripcion'],
                    'alumno'  => trim($f['alumno_nombre'] . ' ' . $f['alumno_apellido']),
                    'materia' => $f['materia'],
                    'duracion_min' => (int) $f['duracion_min'],
                    'antes'   => (float) $f['costo_mensual'],
                    'despues' => $nuevo,
                ];
            }

            if ($aplicar && $cambios) {
                $db->beginTransaction();
                try {
                    require_once __DIR__ . '/../lib/Bitacora.php';
                    require_once __DIR__ . '/../models/HistorialClaseModel.php';
                    $folio = Bitacora::folio();
                    $stmt = $db->prepare("UPDATE inscripciones SET costo_mensual = ? WHERE id_inscripcion = ?");
                    foreach ($cambios as $c) {
                        $stmt->execute([$c['despues'], $c['id_inscripcion']]);
                        // Rige el mes en curso completo, tenga o no historial de cambios la clase.
                        HistorialClase::corregirPrecioDelMes($db, (int) $c['id_inscripcion'], date('Y-m'), (float) $c['despues']);
                        Bitacora::registrar($db, 'recalcular precios', 'inscripciones', $c['id_inscripcion'], 'costo_mensual',
                            $c['antes'], $c['despues'], 'Precio de la materia por la duración de la clase', $folio);
                    }
                    $db->commit();
                } catch (Throwable $e) {
                    $db->rollBack();
                    throw $e;
                }
            }

            Respuesta::exito([
                'aplicado'    => $aplicar,
                'cambios'     => $cambios,
                'cuantos'     => count($cambios),
                'sin_materia' => $sinMateria,
                'suma_antes'  => round(array_sum(array_column($cambios, 'antes')), 2),
                'suma_despues'=> round(array_sum(array_column($cambios, 'despues')), 2),
            ]);
        }

        if ($accion === 'unir') {
            $a = (int) ($datos['id_a'] ?? 0);
            $b = (int) ($datos['id_b'] ?? 0);
            if (!$a || !$b || $a === $b) Respuesta::error('Faltan las dos clases a unir.');
            $respaldo = respaldoPrevio('unir');
            if ($respaldo === null) Respuesta::error('No se pudo sacar el respaldo previo, así que no se unió nada. Revisa el espacio en disco.', 500);
            Respuesta::exito($fusionador->unirInscripciones($a, $b) + ['respaldo' => $respaldo]);
        }

        if ($accion === 'separar') {
            $id = filter_var($datos['id_alumno'] ?? null, FILTER_VALIDATE_INT);
            $nombres = is_array($datos['nombres'] ?? null) ? $datos['nombres'] : [];
            if (!$id) Respuesta::error('Falta el alumno a separar.');
            Respuesta::exito($fusionador->separar((int) $id, $nombres));
        }

        if ($accion !== 'fusionar') Respuesta::error('Acción no válida.', 404);

        $principal = (int) ($datos['principal'] ?? 0);
        $duplicado = (int) ($datos['duplicado'] ?? 0);
        if (!$principal || !$duplicado) Respuesta::error('Faltan los dos registros a fusionar.');

        $respaldo = respaldoPrevio('fusionar');
        // Sin respaldo no se fusiona: la fusión borra el registro repetido.
        if ($respaldo === null) Respuesta::error('No se pudo sacar el respaldo previo, así que no se fusionó nada. Revisa el espacio en disco.', 500);
        $r = ($datos['tipo'] ?? '') === 'alumnos'
            ? $fusionador->fusionarAlumnos($principal, $duplicado)
            : $fusionador->fusionarMaestros($principal, $duplicado);
        Respuesta::exito($r + ['respaldo' => $respaldo]);
    }

    $sinHorario = $db->query(
        "SELECT ins.id_inscripcion, ins.costo_mensual, ins.id_alumno, ins.id_maestro,
                ins.id_instrumento, ins.id_salon, ins.dia_semana, ins.hora_inicio, ins.duracion_min,
                ins.modalidad, ins.comparte_con,
                a.nombre AS alumno_nombre, a.apellido AS alumno_apellido,
                m.nombre AS maestro_nombre, m.apellido AS maestro_apellido
           FROM inscripciones ins
           JOIN alumnos a  ON a.id_alumno = ins.id_alumno
           JOIN maestros m ON m.id_maestro = ins.id_maestro
          WHERE ins.estado = 'pendiente'
            -- Si ese alumno ya tiene otra clase viva con el mismo maestro, esta
            -- es una copia: sale en 'Clases repetidas' para unirla, no aquí,
            -- donde el botón 'Ya no va' borraría justo la que tiene la nómina.
            AND NOT EXISTS (SELECT 1 FROM inscripciones o
                             WHERE o.id_alumno = ins.id_alumno AND o.id_maestro = ins.id_maestro
                               AND o.id_inscripcion <> ins.id_inscripcion AND o.estado <> 'baja')
          ORDER BY a.apellido, a.nombre"
    )->fetchAll();

    $sinTarifa = $db->query(
        "SELECT m.id_maestro, m.nombre, m.apellido, m.modo_pago,
                COUNT(DISTINCT ins.id_alumno) AS alumnos
           FROM maestros m
           JOIN inscripciones ins ON ins.id_maestro = m.id_maestro AND ins.estado <> 'baja'
          -- Quien no lleva nómina (la dueña) no tiene tarifa a propósito: no es un pendiente.
          WHERE m.activo = 1 AND m.lleva_nomina = 1 AND m.tarifa_por_alumno = 0 AND m.tarifa_por_hora = 0
          GROUP BY m.id_maestro, m.nombre, m.apellido, m.modo_pago
          ORDER BY alumnos DESC"
    )->fetchAll();

    $sinMateria = $db->query(
        "SELECT ins.id_inscripcion, ins.dia_semana, ins.hora_inicio, ins.costo_mensual,
                ins.id_salon, ins.duracion_min, ins.id_instrumento, ins.modalidad, ins.comparte_con,
                a.nombre AS alumno_nombre, a.apellido AS alumno_apellido,
                m.nombre AS maestro_nombre, m.apellido AS maestro_apellido
           FROM inscripciones ins
           JOIN alumnos a  ON a.id_alumno = ins.id_alumno
           JOIN maestros m ON m.id_maestro = ins.id_maestro
          WHERE ins.estado = 'activa' AND ins.id_instrumento IS NULL
          ORDER BY a.apellido, a.nombre"
    )->fetchAll();

    $sinApellido = $db->query(
        "SELECT id_alumno, nombre FROM alumnos
          WHERE activo = 1 AND (apellido IS NULL OR apellido = '')
          ORDER BY nombre"
    )->fetchAll();

    $sinPrecio = $db->query(
        "SELECT ins.id_inscripcion, a.nombre AS alumno_nombre, a.apellido AS alumno_apellido,
                m.nombre AS maestro_nombre, m.apellido AS maestro_apellido,
                ins.duracion_min, ins.dia_semana, ins.hora_inicio,
                ins.id_instrumento, ins.id_salon, ins.costo_mensual, ins.modalidad, ins.comparte_con
           FROM inscripciones ins
           JOIN alumnos a  ON a.id_alumno = ins.id_alumno
           JOIN maestros m ON m.id_maestro = ins.id_maestro
          WHERE ins.estado <> 'baja' AND ins.costo_mensual = 0
          ORDER BY a.apellido, a.nombre"
    )->fetchAll();

    // Clases que dependen de otra que ya no sirve de titular: dada de baja, sin
    // horario, o en otro hueco. Nunca debería pasar; si pasa, se ve aquí.
    $gruposRotos = $db->query(
        "SELECT c.id_inscripcion, c.id_alumno, a.nombre AS alumno_nombre, a.apellido AS alumno_apellido,
                c.comparte_con, t.estado AS estado_titular,
                CASE WHEN t.id_inscripcion IS NULL THEN 'la clase con la que comparte ya no existe'
                     WHEN t.estado = 'baja' THEN 'la clase con la que comparte está dada de baja'
                     WHEN ho.id_ocupado IS NULL THEN 'la clase con la que comparte no tiene horario'
                     ELSE 'no coincide con el horario de la clase con la que comparte' END AS motivo
           FROM inscripciones c
           JOIN alumnos a ON a.id_alumno = c.id_alumno
           LEFT JOIN inscripciones t ON t.id_inscripcion = c.comparte_con
           LEFT JOIN horario_ocupado ho ON ho.id_inscripcion = c.comparte_con
          WHERE c.comparte_con IS NOT NULL AND c.estado <> 'baja'
            AND (t.id_inscripcion IS NULL OR t.estado = 'baja' OR ho.id_ocupado IS NULL
                 OR c.id_maestro <> ho.id_maestro OR NOT (c.id_salon <=> ho.id_salon)
                 OR c.dia_semana <> ho.dia_semana OR c.hora_inicio <> ho.hora_inicio OR c.hora_fin <> ho.hora_fin)"
    )->fetchAll();
    $borradores = $db->query(
        "SELECT COUNT(*) FROM borradores_alumnos WHERE estado = 'abierto'"
    )->fetchColumn();

    $repetidas   = $fusionador->inscripcionesRepetidas();
    $variasPersonas = $fusionador->variasPersonas();
    $dupMaestros = $fusionador->duplicadosMaestros();
    $dupAlumnos  = $fusionador->duplicadosAlumnos();

    Respuesta::exito([
        'sin_horario'        => $sinHorario,
        'clases_repetidas'   => $repetidas,
        'duplicados_maestros'=> $dupMaestros,
        'duplicados_alumnos' => $dupAlumnos,
        'varias_personas'    => $variasPersonas,
        'sin_tarifa'         => $sinTarifa,
        'sin_materia'        => $sinMateria,
        'sin_precio'         => $sinPrecio,
        'sin_apellido'       => $sinApellido,
        'grupos_rotos'       => $gruposRotos,
        'borradores_abiertos'=> (int) $borradores,
        // Hermanos probables (mismo tutor o teléfono): no es un problema, es una sugerencia.
        'familias_sugeridas' => (function () {
            require_once __DIR__ . '/../models/FamiliaModel.php';
            return (new FamiliaModel())->sugerencias();
        })(),
        'total'              => count($sinHorario) + count($dupMaestros) + count($dupAlumnos) + count($variasPersonas) + count($repetidas)
                              + count($sinTarifa) + count($sinMateria) + count($sinPrecio)
                              + count($sinApellido) + count($gruposRotos),
    ]);
} catch (Throwable $e) {
    Respuesta::fallo($e, 400);
}

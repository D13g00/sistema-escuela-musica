<?php
/**
 * Sistema Escuela — Comprobar la instalación
 *
 * Responde una sola pregunta: ¿los archivos que están en el servidor son los
 * nuevos, y la base está al día? Lee los archivos en disco, así que lo que
 * reporta es la realidad del servidor, no lo que tu navegador tenga guardado.
 *
 * Solo administración: entra al sistema como administrador y abre
 * backend/comprobar.php en el mismo navegador. No cambia nada.
 */

const VERSION_ESPERADA = '5.14';


/**
 * Encuentra la carpeta backend del proyecto, sin importar dónde quedó este
 * archivo: junto a config/, en la raíz del proyecto, o un nivel más arriba.
 * Sin esto, moverlo de carpeta rompe el require con un error de include_path.
 */
function ubicarBackend(): ?string
{
    $candidatos = [
        __DIR__,
        __DIR__ . '/backend',
        dirname(__DIR__),
        dirname(__DIR__) . '/backend',
        dirname(__DIR__, 2) . '/backend',
        __DIR__ . '/escuela-musica/backend',
    ];
    foreach ($candidatos as $ruta) {
        if (is_readable($ruta . '/config/Database.php')) {
            return rtrim(str_replace('\\', '/', $ruta), '/');
        }
    }
    return null;
}

$backend = ubicarBackend();
if ($backend === null) {
    http_response_code(500);
    exit('<p style="font:16px system-ui;padding:40px;max-width:640px;margin:auto">'
       . 'No encontré la carpeta <b>backend</b> del proyecto desde <code>' . htmlspecialchars(__DIR__)
       . '</code>.<br><br>Mueve este archivo dentro de <code>escuela-musica\\backend</code> y vuelve a abrirlo.</p>');
}
$raiz = dirname($backend);
require_once $backend . '/config/Mantenimiento.php';
Mantenimiento::puerta();   // solo administración; esta página no aplica nada

/** Señales que solo existen en la versión 4 */
$marcas = [
    'frontend/js/app.js' => [
        'Ficha del alumno con pestañas'        => 'function fichaAlumno',
        'Recibos de nómina completos (5.13.1)' => 'function acomodarRecibos',
        'Corregir la nómina de un mes (5.13.2)' => 'accion=quitar_alumno',
        'Cobrar la inscripción (5.13.3)'       => 'accion=inscripcion',
        'Aviso claro cuando el servidor falla (5.14)' => 'function textoErrorServidor',
        'Menú y botones según el rol'          => 'function aplicarRol',
        'Fechas con la hora de la escuela'     => 'const periodoActual = () => hoyLocal()',
        'Horario por día con todos los salones'=> 'Un día, todos los salones',
        'Aviso de bitácora al entrar'          => 'avisarBitacora',
        'Corrección de pagos'                  => 'formularioCorregirPago',
        'Duraciones hasta cuatro horas'        => 'Cuatro horas',
        'Buscador de alumnos'                  => 'function elegirAlumno',
        'Actualización sin recargar la vista'  => 'async function refrescar',
        'Clases compartidas'                   => 'name="compartir_con"',
        'Recibo de nómina imprimible'          => 'function htmlRecibo',
        'Encabezados fijos en horarios'        => 'caja-horario',
        'Alta de alumno con su clase'          => 'alumno_nuevo: datosDeAlumno(datos)',
        'Fecha de inicio y cambio de maestro'  => 'fecha_cambio',
        'Bono de puntualidad'                 => 'bono_puntualidad',
        'Recibos en sobre (Carta, 60 mm, 130 %)' => 'function documentoRecibos',
        'Grupo de Dibujo y Pintura en horarios'=> 'async function panelGrupo',
        'Cobro a una familia'                  => 'async function cobrarFamilia',
        'Modo claro y oscuro'                  => 'function aplicarTema',
        'Facturas del mes'                     => 'async function pintarFacturas',
        'Juntar e intercambiar clases'         => "accion=intercambiar",
    ],
    'frontend/css/style.css' => [
        'Menú sin barra de desplazamiento'     => 'flex-wrap:wrap; gap:2px',
        'Fichas de materias'                   => '.fichas label',
        'Franja de aviso'                      => '.franja-aviso',
    ],
    'frontend/index.html' => [
        'Archivos con número de versión'       => 'js/app.js?v=5.14',
    ],
    'backend/models/HorarioModel.php' => [
        'Taller de Dibujo y Pintura (cada alumno a su hora)' => 'function enTallerDe',
    ],
    'backend/models/AlumnoModel.php' => [
        'Ficha completa del alumno'            => 'public function ficha',
        'Datos de padre o tutor'               => 'tutor_parentesco',
    ],
    'backend/services/InscripcionService.php' => [
        'Cambio de duración con su precio y fecha' => 'function vistaPrecio',
        'Candado de horario en cada operación' => 'HorarioModel::conCandado',
        'Inscripción cobrada en el alta (5.13.3)' => 'monto_inscripcion',
        'Operaciones de un solo paso'          => 'public function juntar',
        'El alta solo acepta lo que usa la pantalla' => 'function datosDeAlta',
        'Nómina independiente del alumno'      => 'montoDeRenglon',
    ],
    'backend/services/BackupService.php' => [
        'Respaldo consistente y por partes (5.14)' => 'START TRANSACTION WITH CONSISTENT SNAPSHOT',
    ],
    'backend/services/VerificadorRespaldo.php' => [
        'Verificación de respaldos en una base temporal (5.14)' => 'class VerificadorRespaldo',
    ],
    'backend/herramientas/respaldo_diario.php' => [
        'Respaldo diario con rotación (5.14)'  => 'function elegirConservados',
    ],
    'backend/herramientas/verificar_respaldo.php' => [
        'Herramienta para verificar un respaldo (5.14)' => 'VerificadorRespaldo',
    ],
    'backend/herramientas/revisar_servidor.php' => [
        'Revisión del servidor: carpeta completa y de una versión (5.14)' => 'RevisionServidor::faltantes',
    ],
    'backend/lib/RevisionServidor.php' => [
        'Lecturas de la revisión del servidor (5.14)' => 'final class RevisionServidor',
    ],
    'backend/herramientas/asegurar_base.php' => [
        'Usuario propio del sistema en la base (5.14)' => 'PERMISOS_BASE',
    ],
    'backend/lib/RedesPermitidas.php' => [
        'Equipos permitidos por red (5.14)'    => 'final class RedesPermitidas',
    ],
    'backend/lib/LectorZipSimple.php' => [
        'Lector de .xlsx sin extensión zip'    => 'class LectorZipSimple',
    ],
    'backend/services/ImportadorHorarios.php' => [
        'Importador del Excel de horarios'     => 'class ImportadorHorarios',
    ],
    'backend/models/AjusteNominaModel.php' => [
        'Faltas y suplencias'                  => 'function registrarFalta',
    ],
    'backend/models/PagoModel.php' => [
        'Pagos corregibles con bitácora'       => 'function corregir',
        'Anular pagos sin borrarlos'           => 'public function anular',
    ],
    'backend/services/ImportadorPagos.php' => [
        'Importador del tablero de pagos'      => 'class ImportadorPagos',
    ],
    'backend/models/HistorialClaseModel.php' => [
        'Historial de precio y duración de cada clase' => 'class HistorialClase',
        'Clases tomadas dichas a mano, con el precio de cada tramo (5.14)' => 'function clasesTomadas',
    ],
    'backend/models/ConfiguracionModel.php' => [
        'Clases al mes por mes'                => 'function semanasDe',
    ],
    'backend/models/CuentaModel.php' => [
        'Cobro del mes partido por tramos de precio y de día' => 'HistorialClase::tramos',
        'Abonos y estado de cuenta'            => 'function cobrar',
        'Cargos congelados por clase'          => 'function congelarClase',
        'Cobros sin duplicados'                => 'function reservarClave',
        'Cobrar la inscripción por separado (5.13.3)' => 'function cobrarInscripcion',
        '¿Hasta qué mes pagó?'                 => 'function confirmarHistorico',
    ],
    'backend/services/Fusionador.php' => [
        'Detector de duplicados'               => 'function sonLaMisma',
        'Fusiones con el candado de horario'   => 'HorarioModel::conCandado',
    ],
    'backend/models/FamiliaModel.php' => [
        'Familias y descuento familiar'        => 'function descuentosPorAlumno',
        'Descuento familiar con el precio de cada mes (5.14)' => 'private function colegiaturas',
    ],

    'backend/api/pendientes.php' => [
        'Lista de pendientes por resolver'     => 'duplicados_maestros',
    ],
    'backend/models/NominaModel.php' => [
        'Día de pago de cada maestro'          => 'function diaDePago',
        'Día de pago solo con clases presenciales' => 'dias_en_linea',
        'Prioridad de nómina por pagar'        => "'urgencia'",
        'Ajustes manuales de nómina respetados' => 'ajuste_manual',
        'Nómina del mes partida por duración'  => 'function cambioDeClase',
    ],
    'backend/lib/LectorXlsx.php' => [
        'Lectura correcta de horas sueltas'    => 'serieAHora',
    ],
    'backend/config/Auth.php' => [
        'Roles: administración, recepción y consulta' => 'function requerirRol',
        'Cierre de sesión por inactividad'     => 'MINUTOS_INACTIVIDAD',
        'Límite de intentos para entrar'       => 'intentos_acceso',
        'Cierre de sesiones al cambiar contraseña' => 'function invalidarSesiones',
    ],
    'backend/config/Respuesta.php' => [
        'Errores sin datos internos (con folio)' => 'function fallo',
    ],
    'backend/config/Mantenimiento.php' => [
        'Mantenimiento solo para administración' => 'function puerta',
    ],
    'backend/config/Database.php' => [
        'Conexión propia de cada computadora'  => 'credenciales.php',
        'Conexión con plazo para las herramientas (5.14)' => 'public static function nueva',
        'Modo estricto de la base'             => 'STRICT_TRANS_TABLES',
    ],
    'backend/lib/Validar.php' => [
        'Validación de importes, horas y métodos' => 'final class Validar',
    ],
    'backend/lib/Bitacora.php' => [
        'Bitácora financiera'                  => 'final class Bitacora',
    ],
];

$resultados = [];
$faltan = 0;
foreach ($marcas as $archivo => $pruebas) {
    $ruta = $raiz . '/' . $archivo;
    $contenido = is_readable($ruta) ? (string) file_get_contents($ruta) : null;
    foreach ($pruebas as $titulo => $aguja) {
        $ok = $contenido !== null && str_contains($contenido, $aguja);
        if (!$ok) $faltan++;
        $resultados[] = [
            'archivo' => $archivo,
            'titulo'  => $titulo,
            'ok'      => $ok,
            'detalle' => $contenido === null ? 'no se encontró el archivo' : ($ok ? 'presente' : 'falta'),
            'fecha'   => $contenido !== null ? date('d/m/Y H:i', filemtime($ruta)) : '',
        ];
    }
}

/* ---- Estado de la base ---- */
$base = [];
try {
    require_once $backend . '/config/Database.php';
    $db = Database::conexion();
    $esquema = $db->query("SELECT DATABASE()")->fetchColumn();

    $columna = function (string $tabla, string $col) use ($db, $esquema) {
        $s = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $s->execute([$esquema, $tabla, $col]);
        return (int) $s->fetchColumn() > 0;
    };

    $base = [
        'Base conectada (' . $esquema . ')'          => true,
        'nomina_detalle.periodo'                     => $columna('nomina_detalle', 'periodo'),
        'inscripciones.duracion_min'                 => $columna('inscripciones', 'duracion_min'),
        'maestros.modo_pago'                         => $columna('maestros', 'modo_pago'),
        'nomina_detalle.clases (meses a medias)'     => $columna('nomina_detalle', 'clases'),
        'maestros.bono_puntualidad'                  => $columna('maestros', 'bono_puntualidad'),
        'pagos.id_maestro (clase muestra)'           => $columna('pagos', 'id_maestro'),
        'cargos_mensuales.id_inscripcion'            => $columna('cargos_mensuales', 'id_inscripcion'),
        'pagos.monto_confirmado'                     => $columna('pagos', 'monto_confirmado'),
        'maestros.lleva_nomina'                      => $columna('maestros', 'lleva_nomina'),
        'inscripciones.modalidad'                    => $columna('inscripciones', 'modalidad'),
        'Tabla lista_espera'                         => $columna('lista_espera', 'estado'),
        'Tabla borradores_alumnos'                   => $columna('borradores_alumnos', 'estado'),
        'instrumentos.id_grupo_materia'              => $columna('instrumentos', 'id_grupo_materia'),
        'Tabla inscripciones_cambios'                => $columna('inscripciones_cambios', 'campo'),
        'Tabla familias'                             => $columna('familias', 'descuento_mensual'),
        'alumnos.id_familia'                         => $columna('alumnos', 'id_familia'),
        'alumnos.requiere_factura'                   => $columna('alumnos', 'requiere_factura'),
        'Tabla facturas'                             => $columna('facturas', 'hecha'),
        'pagos.concepto (clases muestra)'            => $columna('pagos', 'concepto'),
        'alumnos.tutor_nombre'                       => $columna('alumnos', 'tutor_nombre'),
        'instrumentos.costo_mensual'                 => $columna('instrumentos', 'costo_mensual'),
        'Tabla configuracion'                        => $columna('configuracion', 'clave'),
        'usuarios.version_sesion'                    => $columna('usuarios', 'version_sesion'),
        'Tabla intentos_acceso'                      => $columna('intentos_acceso', 'clave'),
        'pagos.anula_a'                              => $columna('pagos', 'anula_a'),
        'nomina_detalle.ajuste_manual'               => $columna('nomina_detalle', 'ajuste_manual'),
        'Tabla bitacora_financiera'                  => $columna('bitacora_financiera', 'operacion'),
        'Tabla operaciones_cobro'                    => $columna('operaciones_cobro', 'clave'),
        'Tabla precios_clase (historial de precio y duración)' => $columna('precios_clase', 'vigente_desde'),
        'precios_clase.dia_semana (día de cada tramo)'        => $columna('precios_clase', 'dia_semana'),
        'Tabla semanas_por_mes_historial (clases al mes por mes)' => $columna('semanas_por_mes_historial', 'vigente_desde'),
        'horario_ocupado.en_taller'                   => $columna('horario_ocupado', 'en_taller'),
        'horario_ocupado.inicio_exclusivo (taller)'  => $columna('horario_ocupado', 'inicio_exclusivo'),
        'Taller de Dibujo y Pintura: el maestro puede tener dos clases con el mismo inicio' => (function () use ($db, $esquema) {
            $s = $db->prepare("SELECT COLUMN_NAME FROM information_schema.STATISTICS
                                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'horario_ocupado' AND INDEX_NAME = 'uq_maestro_horario'");
            $s->execute([$esquema]);
            return in_array('inicio_exclusivo', $s->fetchAll(PDO::FETCH_COLUMN), true);
        })(),
        'Roles recepción y consulta'                 => (function () use ($db, $esquema) {
            $s = $db->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'rol'");
            $s->execute([$esquema]);
            return str_contains((string) $s->fetchColumn(), 'recepcion');
        })(),
        // Seguridad: archivos que no deben estar en el servidor, y la protección de carpetas.
        'Sin setup_admin.php en el servidor'         => !is_file($backend . '/setup_admin.php'),
        'Sin diagnostico_login.php en el servidor'   => !is_file($raiz . '/diagnostico_login.php'),
        'Protección de la carpeta del sistema (.htaccess)' => is_file($raiz . '/.htaccess'),
    ];
} catch (Throwable $e) {
    $base = ['No hay conexión con MySQL: ' . $e->getMessage() => false];
}
$faltanBase = count(array_filter($base, fn($v) => !$v));

function h($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sistema Escuela — Comprobar la instalación</title>
<style>
  :root{ --linea:#D2D2D7; --suave:#6E6E73; --azul:#0071E3; --rojo:#C7362B; --verde:#1D7A4C; --ambar:#9A6700; }
  body{ margin:0; background:#F5F5F7; color:#1D1D1F; padding:48px 20px;
        font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,system-ui,sans-serif; }
  .caja{ max-width:780px; margin:0 auto; }
  h1{ font-size:28px; letter-spacing:-.022em; margin:0 0 6px; }
  h2{ font-size:16px; margin:30px 0 10px; }
  p.intro{ color:var(--suave); margin:0 0 24px; }
  .tarjeta{ background:#fff; border:1px solid var(--linea); border-radius:18px; overflow:hidden; }
  ul{ list-style:none; margin:0; padding:0; }
  li{ display:flex; gap:13px; align-items:flex-start; padding:12px 20px; border-bottom:1px solid var(--linea); }
  li:last-child{ border-bottom:0; }
  .marca{ width:16px; flex-shrink:0; font-weight:600; }
  .si .marca{ color:var(--verde); } .no .marca{ color:var(--rojo); }
  .detalle{ color:var(--suave); font-size:12.5px; margin-top:2px; }
  .veredicto{ border-radius:16px; padding:18px 22px; margin-bottom:24px; background:#fff;
              border:1px solid var(--linea); border-left:3px solid var(--verde); }
  .veredicto.mal{ border-left-color:var(--rojo); }
  .veredicto h2{ margin:0 0 6px; font-size:18px; }
  code{ background:#F0F0F3; padding:2px 6px; border-radius:5px; font-size:13px; }
  ol{ padding-left:20px; } ol li{ display:list-item; border:0; padding:5px 0; }
</style>
</head>
<body>
<div class="caja">
  <h1>Comprobar la instalación</h1>
  <p class="intro">Esta página lee los archivos del servidor, no la copia que tenga tu navegador.
     Versión esperada: <strong><?= VERSION_ESPERADA ?></strong>.</p>

  <?php if ($faltan === 0 && $faltanBase === 0): ?>
    <div class="veredicto">
      <h2>Los archivos nuevos están en su lugar y la base está al día</h2>
      <p style="margin:0">Si al abrir el sistema sigues viendo la interfaz anterior, lo que ves es la
         copia guardada en tu navegador. Abre
         <code>http://localhost/escuela-musica/frontend/</code> y recarga con
         <strong>Ctrl + F5</strong> (Cmd + Shift + R en Mac). En la pantalla de acceso, abajo,
         debe decir <strong>Sistema Escuela <?= VERSION_ESPERADA ?></strong>.</p>
    </div>
  <?php elseif ($faltan > 0): ?>
    <div class="veredicto mal">
      <h2>Los archivos del servidor son los de una versión anterior</h2>
      <p style="margin:0 0 10px">Faltan <?= $faltan ?> señales de la versión <?= VERSION_ESPERADA ?>.
         El reemplazo de la carpeta no llegó a donde Apache está sirviendo. Revisa:</p>
      <ol style="margin:0">
        <li>Que la carpeta correcta sea <code><?= h($raiz) ?></code>, que es de donde se está
            ejecutando esta página.</li>
        <li>Que al descomprimir no haya quedado anidada:
            <code>escuela-musica\escuela-musica\backend</code> en lugar de
            <code>escuela-musica\backend</code>.</li>
        <li>Que Windows no haya preguntado "¿reemplazar archivos?" y se haya quedado en "omitir".</li>
      </ol>
    </div>
  <?php else: ?>
    <div class="veredicto mal">
      <h2>Los archivos están bien, pero falta actualizar la base</h2>
      <p style="margin:0">Abre <code>actualizar_base.php</code> y aplica los cambios pendientes.</p>
    </div>
  <?php endif; ?>

  <h2>Archivos en el servidor</h2>
  <div class="tarjeta">
    <ul>
      <?php foreach ($resultados as $r): ?>
        <li class="<?= $r['ok'] ? 'si' : 'no' ?>">
          <span class="marca"><?= $r['ok'] ? '✓' : '✕' ?></span>
          <span>
            <?= h($r['titulo']) ?>
            <span class="detalle"><?= h($r['archivo']) ?><?= $r['fecha'] ? ' · modificado ' . h($r['fecha']) : '' ?></span>
          </span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <h2>Estado de la base de datos</h2>
  <div class="tarjeta">
    <ul>
      <?php foreach ($base as $titulo => $ok): ?>
        <li class="<?= $ok ? 'si' : 'no' ?>">
          <span class="marca"><?= $ok ? '✓' : '✕' ?></span>
          <span><?= h($titulo) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <p class="detalle" style="margin-top:24px">
    Leyendo el proyecto desde <code><?= h($raiz) ?></code>.<br>
    PHP <?= PHP_VERSION ?> · extensión zip <?= extension_loaded('zip') ? 'activa' : 'no activa (se usa el lector propio)' ?>
    · zlib <?= function_exists('gzinflate') ? 'activa' : 'NO activa' ?>.
    Borra este archivo cuando el sistema esté funcionando.
  </p>
</div>
</body>
</html>

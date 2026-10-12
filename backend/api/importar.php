<?php
/**
 * Importación de archivos de Excel.
 *   POST ?accion=previsualizar  (multipart: archivo, hoja?)  -> no toca la base
 *   POST ?accion=importar       (multipart: archivo, periodo, hoja?)
 *   GET  ?accion=historial
 */
require_once __DIR__ . '/../config/Respuesta.php';
require_once __DIR__ . '/../config/Auth.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../services/ImportadorNomina.php';
require_once __DIR__ . '/../services/ImportadorHorarios.php';
require_once __DIR__ . '/../services/ImportadorPagos.php';

Respuesta::iniciar();
Auth::requerirRol('admin');
if (in_array($_SERVER['REQUEST_METHOD'], ['POST','PUT','DELETE'], true)) { Auth::exigirCsrf(); }

$accion = $_GET['accion'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'];

try {
    if ($metodo === 'GET' && $accion === 'historial') {
        $db = Database::conexion();
        Respuesta::exito($db->query("SELECT * FROM importaciones ORDER BY fecha DESC LIMIT 30")->fetchAll());
    }

    if ($metodo !== 'POST') Respuesta::error('Método no soportado', 405);

    if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
        $codigos = [
            UPLOAD_ERR_INI_SIZE  => 'El archivo pesa más de lo que permite PHP (upload_max_filesize).',
            UPLOAD_ERR_FORM_SIZE => 'El archivo pesa demasiado.',
            UPLOAD_ERR_PARTIAL   => 'La subida quedó incompleta. Inténtalo otra vez.',
            UPLOAD_ERR_NO_FILE   => 'No se recibió ningún archivo.',
        ];
        $err = $_FILES['archivo']['error'] ?? UPLOAD_ERR_NO_FILE;
        Respuesta::error($codigos[$err] ?? 'No se pudo recibir el archivo.');
    }

    $nombre = $_FILES['archivo']['name'];
    $ruta   = $_FILES['archivo']['tmp_name'];

    // 1. Solo .xlsx, comprobando la extensión Y que sea un ZIP de verdad (los
    //    .xlsx empiezan por 'PK'). Nadie sube un ejecutable renombrado.
    if (strtolower(pathinfo($nombre, PATHINFO_EXTENSION)) !== 'xlsx') {
        Respuesta::error('El archivo debe ser .xlsx. Si es .xls o .csv, ábrelo en Excel y guárdalo como .xlsx.');
    }
    if (!is_uploaded_file($ruta)) {
        // El archivo tiene que venir de una subida HTTP real, no de una ruta
        // que alguien haya inyectado en tmp_name.
        Respuesta::error('Subida no válida.');
    }
    $cabecera = (string) file_get_contents($ruta, false, null, 0, 2);
    if ($cabecera !== 'PK') {
        Respuesta::error('El archivo no es un .xlsx válido.');
    }

    // 2. Tope de tamaño, además del de php.ini: un .xlsx legítimo de la escuela
    //    no pesa más de unos pocos MB. Frena la denegación de servicio por peso.
    if (filesize($ruta) > 8 * 1024 * 1024) {
        Respuesta::error('El archivo es demasiado grande (máximo 8 MB).');
    }

    $hoja    = $_POST['hoja'] ?? null;
    $tipo    = $_POST['tipo'] ?? $_GET['tipo'] ?? 'nomina';
    $usuario = Auth::usuarioActual()['nombre'] ?? null;

    if ($tipo === 'pagos') {
        $importador = new ImportadorPagos();
        $referencia = $_POST['referencia'] ?? null;
        if ($accion === 'previsualizar') {
            Respuesta::exito($importador->previsualizar($ruta, $referencia));
        }
        if ($accion === 'importar') {
            Respuesta::exito($importador->importar($ruta, $referencia, $usuario, $nombre, [
                'crear_faltantes'   => !empty($_POST['crear_faltantes']),
                'completar_nombres' => !empty($_POST['completar_nombres']),
                'poner_precio'      => !empty($_POST['poner_precio']),
            ]));
        }
    }

    if ($tipo === 'horarios') {
        $importador = new ImportadorHorarios();
        if ($accion === 'previsualizar') {
            Respuesta::exito($importador->previsualizar($ruta, $hoja));
        }
        if ($accion === 'importar') {
            Respuesta::exito($importador->importar($ruta, [
                'crear_faltantes' => !empty($_POST['crear_faltantes']),
                'usuario'         => $usuario,
            ], $hoja, $nombre));
        }
    }

    $importador = new ImportadorNomina();

    if ($accion === 'previsualizar') {
        Respuesta::exito($importador->previsualizar($ruta, $hoja));
    }

    if ($accion === 'importar') {
        $periodo = trim($_POST['periodo'] ?? '');
        Respuesta::exito($importador->importar($ruta, $periodo, $hoja, $usuario, $nombre));
    }

    Respuesta::error('Acción no válida. Usa previsualizar o importar.', 404);

} catch (Throwable $e) {
    Respuesta::fallo($e, 400);
}

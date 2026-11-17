<?php
require __DIR__ . '/../../vendor/autoload.php';

use Icewind\SMB\ServerFactory;
use Icewind\SMB\BasicAuth;
use Icewind\SMB\Options;

header('Content-Type: application/json');

$serverIp = '192.168.100.17';
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (stripos($contentType, 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true);
} else {
    if (empty($_POST) && !empty($_SERVER['CONTENT_LENGTH'])) {
        http_response_code(413);
        echo json_encode([
            'ok' => false,
            'error' => 'El bloque supera el límite permitido por PHP.'
        ]);
        exit;
    }
    $input = $_POST;
}

if (!$input) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Datos inválidos o vacíos']);
    exit;
}

$accion   = $input['accion'] ?? '';
$usuario  = $input['usuario'] ?? '';
$password = $input['password'] ?? '';
$recurso  = $input['recurso'] ?? '';

if (empty($usuario) || empty($password) || empty($recurso)) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => "Faltan credenciales: usuario='$usuario', recurso='$recurso'"
    ]);
    exit;
}

try {
    $options = new Options();
    $options->setTimeout(3600);

    $serverFactory = new ServerFactory($options);
    $auth = new BasicAuth($usuario, 'WORKGROUP', $password);
    $server = $serverFactory->createServer($serverIp, $auth);
    $share = $server->getShare($recurso);

    if ($accion === 'listar') {
        $ruta = $input['ruta'] ?? '';
        $contenido = $share->dir($ruta);
        $archivos = [];

        foreach ($contenido as $item) {
            $archivos[] = [
                'nombre' => $item->getName(),
                'es_carpeta' => $item->isDirectory()
            ];
        }

        echo json_encode(['ok' => true, 'archivos' => $archivos]);

    } elseif ($accion === 'descargar') {
        set_time_limit(0);
        ignore_user_abort(true);

        while (ob_get_level()) {
            ob_end_clean();
        }

        $rutaArchivo = $input['ruta_archivo'] ?? '';
        if (empty($rutaArchivo)) {
            throw new \Exception('No se especificó la ruta del archivo a descargar.');
        }

        $info = $share->stat($rutaArchivo);
        $tamano = $info->getSize();

        header_remove('Content-Type');
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($rutaArchivo) . '"');
        header('Content-Length: ' . $tamano);
        header('Cache-Control: no-cache');

        $rutaSmb    = str_replace('/', '\\', $rutaArchivo);
        $recursoEsc = escapeshellarg("//{$serverIp}/{$recurso}");
        $userEsc    = escapeshellarg("{$usuario}%{$password}");
        $comandoSmb = escapeshellarg("get \"{$rutaSmb}\" -");

        passthru("smbclient {$recursoEsc} -U {$userEsc} -c {$comandoSmb} 2>/dev/null");
        exit;

    } elseif ($accion === 'subir') {
        // Bloquear subida si es el usuario de solo descarga
        if ($usuario === 'Archivo' && $password === 'Archivo_1234') {
            http_response_code(403);
            echo json_encode([
                'ok' => false,
                'error' => 'Acceso denegado: El usuario Archivo solo tiene permisos de descarga.'
            ]);
            exit;
        }

        set_time_limit(0);

        if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
            throw new \Exception('Error al recibir el bloque en el servidor.');
        }

        $rutaActual    = $input['ruta'] ?? '';
        $nombreArchivo = basename($input['nombre_archivo'] ?? $_FILES['archivo']['name']);
        $indiceBloque  = (int)($input['indice_bloque'] ?? 0);
        $totalBloques  = (int)($input['total_bloques'] ?? 1);

        $archivoEnsamblado = "/var/tmp/smb_upload_" . md5($usuario . $nombreArchivo);

        $modo = ($indiceBloque === 0) ? 'wb' : 'ab';
        $destinoLocal = fopen($archivoEnsamblado, $modo);
        $origenBloque = fopen($_FILES['archivo']['tmp_name'], 'rb');

        stream_copy_to_stream($origenBloque, $destinoLocal);
        fclose($origenBloque);
        fclose($destinoLocal);

        if ($indiceBloque + 1 < $totalBloques) {
            echo json_encode(['ok' => true, 'parcial' => true]);
            exit;
        }

        $rutaDestino = $rutaActual ? $rutaActual . '\\' . $nombreArchivo : $nombreArchivo;
        $recursoEsc  = escapeshellarg("//{$serverIp}/{$recurso}");
        $userEsc     = escapeshellarg("{$usuario}%{$password}");
        $comandoSmb  = escapeshellarg("put \"{$archivoEnsamblado}\" \"{$rutaDestino}\"");

        exec("smbclient {$recursoEsc} -U {$userEsc} -c {$comandoSmb} 2>&1", $salida, $codigoRetorno);

        @unlink($archivoEnsamblado);

        if ($codigoRetorno !== 0) {
            throw new \Exception("Error de SMB al subir: " . implode(" ", $salida));
        }

        echo json_encode(['ok' => true, 'mensaje' => 'Archivo subido correctamente']);
    }

} catch (\Exception $e) {
    http_response_code(401);
    $mensajeError = $e->getMessage() ?: get_class($e);
    echo json_encode(['ok' => false, 'error' => $mensajeError]);
}

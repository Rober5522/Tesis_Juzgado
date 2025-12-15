<?php
// api/subir_archivo_actuario.php

// 1. Configuración de errores para no romper el JSON con warnings
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");

// Función para devolver error JSON de forma limpia
function sendError($message, $code = 500) {
    http_response_code($code);
    echo json_encode(["message" => $message]);
    exit();
}

// 2. Conexión a Base de Datos
try {
    include_once 'conexion.php'; 
    if (!isset($conn)) {
        $host = "localhost";
        $db_name = "juzgado_db";
        $username = "root";
        $password = "";
        $conn = new PDO("mysql:host=" . $host . ";dbname=" . $db_name, $username, $password);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $conn->exec("set names utf8");
    }
} catch(PDOException $exception) {
    sendError("Error crítico de base de datos: " . $exception->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 3. Validar datos recibidos
    if (!isset($_FILES['archivo']) || !isset($_POST['causa_id'])) {
        sendError("Faltan datos (archivo o causa_id).", 400);
    }

    $causa_id = $_POST['causa_id'];
    $archivo = $_FILES['archivo'];
    
    if ($archivo['error'] !== UPLOAD_ERR_OK) {
        sendError("Error nativo PHP al subir archivo. Código: " . $archivo['error']);
    }

    // 4. Preparar carpeta
    $directorio_base = "../uploads/adicionales/"; 
    
    if (!file_exists($directorio_base)) {
        if (!@mkdir($directorio_base, 0777, true)) {
            $error = error_get_last();
            sendError("No se pudo crear la carpeta 'uploads/adicionales'. Verifique permisos.");
        }
    }

    $nombre_original = $archivo['name'];
    $nombre_final = time() . "_" . basename($nombre_original);
    
    $ruta_fisica = $directorio_base . $nombre_final;
    $ruta_web = "uploads/adicionales/" . $nombre_final; 

    // 5. Mover archivo
    if (@move_uploaded_file($archivo['tmp_name'], $ruta_fisica)) {
        
        // 6. Guardar en BD (CORREGIDO: Sin columna 'subido_por')
        try {
            $sql = "INSERT INTO archivo_adicional (causa_id, nombre_archivo, ruta_archivo) VALUES (?, ?, ?)";
            $stmt = $conn->prepare($sql);
            
            if ($stmt->execute([$causa_id, $nombre_original, $ruta_web])) {
                echo json_encode(["message" => "Archivo subido correctamente."]);
            } else {
                @unlink($ruta_fisica); // Borrar si falla la BD
                sendError("Error al guardar registro en Base de Datos.");
            }
        } catch (Exception $e) {
            @unlink($ruta_fisica);
            sendError("Error SQL: " . $e->getMessage());
        }

    } else {
        sendError("Error al mover el archivo a la carpeta final.");
    }

} else {
    sendError("Método no permitido.", 405);
}
?>
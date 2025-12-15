<?php
// Encabezados
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");

// --- 1. CONFIGURACIÓN DE LA BASE DE DATOS ---
$host = "localhost";
$db_name = "juzgado_db";
$username = "root";
$password = "";

// Validar que se recibió el RUT
if (!isset($_GET['rut'])) {
    http_response_code(400); // Bad Request
    echo json_encode(["message" => "Debe proporcionar un RUT."]);
    exit();
}
$rut_usuario = $_GET['rut'];

try {
    $conn = new PDO("mysql:host=" . $host . ";dbname=" . $db_name, $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->exec("set names utf8");
} catch(PDOException $exception) {
    http_response_code(500);
    echo json_encode(["message" => "Error de conexión a la base de datos: " . $exception->getMessage()]);
    exit();
}

// --- 2. LÓGICA DE CONSULTA ---
try {
    // 1. Buscar a la persona por su RUT
    $stmt_persona = $conn->prepare("SELECT persona_id, nombre_completo FROM persona WHERE rut = ?");
    $stmt_persona->execute([$rut_usuario]);
    $persona = $stmt_persona->fetch(PDO::FETCH_ASSOC);

    if (!$persona) {
        http_response_code(404); // Not Found
        echo json_encode(["message" => "RUT no encontrado en el sistema.", "causas" => []]);
        exit();
    }

    $persona_id = $persona['persona_id'];
    $nombre_usuario = $persona['nombre_completo'];

    // 2. Buscar todas las causas asociadas a esa persona
    $query_causas = "
        SELECT 
            c.causa_id,
            c.numero_causa, 
            c.tipo_procedimiento, 
            c.tipo_reclamo, 
            c.fecha_recepcion,
            e.nombre_estado, 
            r.nombre_rol,
            j.nombre AS nombre_juzgado,
            c.archivo_principal_path
        FROM causa_persona cp
        JOIN causa c ON cp.causa_id = c.causa_id
        JOIN estado e ON c.estado_id = e.estado_id
        JOIN rol r ON cp.rol_id = r.rol_id
        JOIN juzgado j ON c.juzgado_id = j.juzgado_id
        WHERE cp.persona_id = ?
        ORDER BY c.fecha_recepcion DESC
    ";
    
    $stmt_causas = $conn->prepare($query_causas);
    $stmt_causas->execute([$persona_id]);
    $causas = $stmt_causas->fetchAll(PDO::FETCH_ASSOC);

    // 3. Preparar consultas para los bucles
    $stmt_archivos = $conn->prepare("SELECT nombre_archivo, ruta_archivo FROM archivo_adicional WHERE causa_id = ?");
    $stmt_participantes = $conn->prepare("
        SELECT p.nombre_completo, p.rut, r.nombre_rol
        FROM causa_persona cp
        JOIN persona p ON cp.persona_id = p.persona_id
        JOIN rol r ON cp.rol_id = r.rol_id
        WHERE cp.causa_id = ? AND r.nombre_rol != 'Actuario'
        ORDER BY r.nombre_rol
    ");
    // --- NUEVO: Preparar consulta de fechas de incidente ---
    $stmt_fechas = $conn->prepare("SELECT fecha FROM fecha_incidente WHERE causa_id = ? ORDER BY fecha ASC");

    foreach ($causas as $key => $causa) {
        $causa_id = $causa['causa_id'];
        
        // 4. Buscar archivos
        $archivos = [];
        if (!empty($causa['archivo_principal_path'])) {
            $archivos[] = [
                "nombre_archivo" => "Documento Principal (Demanda/Querella)",
                "ruta_archivo" => $causa['archivo_principal_path']
            ];
        }
        $stmt_archivos->execute([$causa_id]);
        $archivos_adicionales = $stmt_archivos->fetchAll(PDO::FETCH_ASSOC);
        $causas[$key]['archivos'] = array_merge($archivos, $archivos_adicionales);
        unset($causas[$key]['archivo_principal_path']);

        // 5. Buscar participantes (excluyendo actuarios)
        $stmt_participantes->execute([$causa_id]);
        $causas[$key]['participantes'] = $stmt_participantes->fetchAll(PDO::FETCH_ASSOC);
        
        // --- NUEVO PASO 6: Buscar fechas de incidente ---
        $stmt_fechas->execute([$causa_id]);
        $causas[$key]['fechas_incidente'] = $stmt_fechas->fetchAll(PDO::FETCH_COLUMN);
    }

    // 7. Devolver todo
    http_response_code(200);
    echo json_encode([
        "nombre_usuario" => $nombre_usuario,
        "causas" => $causas
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["message" => "Error al consultar las causas: " . $e->getMessage()]);
}
?>
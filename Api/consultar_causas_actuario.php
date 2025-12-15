<?php
// api/consultar_causas_actuario.php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");

$host = "localhost";
$db_name = "juzgado_db";
$username = "root";
$password = "";

if (!isset($_GET['rut'])) {
    http_response_code(400);
    echo json_encode(["message" => "Debe proporcionar un RUT."]);
    exit();
}
$rut_usuario = $_GET['rut'];

try {
    $conn = new PDO("mysql:host=" . $host . ";dbname="."$db_name", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->exec("set names utf8");

    // 1. Verificar Actuario
    $stmt_persona = $conn->prepare("
        SELECT p.persona_id, p.nombre_completo 
        FROM persona p
        JOIN persona_rol pr ON p.persona_id = pr.persona_id
        JOIN rol r ON pr.rol_id = r.rol_id
        WHERE p.rut = ? AND r.nombre_rol = 'Actuario'
    ");
    $stmt_persona->execute([$rut_usuario]);
    $actuario = $stmt_persona->fetch(PDO::FETCH_ASSOC);

    if (!$actuario) {
        http_response_code(403);
        echo json_encode(["message" => "Acceso denegado. No es actuario."]);
        exit();
    }

    $actuario_id = $actuario['persona_id'];
    $nombre_actuario = $actuario['nombre_completo'];

    // 2. Obtener Causas Asignadas
    $query_causas = "
        SELECT 
            c.causa_id, c.numero_causa, c.tipo_procedimiento, c.tipo_reclamo, 
            c.fecha_recepcion, c.estado_id, e.nombre_estado, 
            j.nombre AS nombre_juzgado, c.archivo_principal_path
        FROM causa c
        JOIN estado e ON c.estado_id = e.estado_id
        JOIN juzgado j ON c.juzgado_id = j.juzgado_id
        WHERE c.actuario_asignado_id = ?
        ORDER BY c.fecha_recepcion DESC
    ";
    
    $stmt_causas = $conn->prepare($query_causas);
    $stmt_causas->execute([$actuario_id]);
    $causas = $stmt_causas->fetchAll(PDO::FETCH_ASSOC);

    // 3. Obtener Estados posibles
    $stmt_estados = $conn->query("SELECT estado_id, nombre_estado FROM estado");
    $todos_los_estados = $stmt_estados->fetchAll(PDO::FETCH_ASSOC);

    // 4. Preparar consultas secundarias

    // CAMBIO AQUI: Quitamos 'fecha_subida' y ordenamos por 'archivo_id DESC' (el ID más alto es el más nuevo)
    $stmt_archivos_extra = $conn->prepare("
        SELECT nombre_archivo, ruta_archivo 
        FROM archivo_adicional 
        WHERE causa_id = ? 
        ORDER BY archivo_id DESC
    ");
    
    // Consulta Participantes
    $stmt_participantes = $conn->prepare("
        SELECT p.nombre_completo, p.rut, r.nombre_rol
        FROM causa_persona cp
        JOIN persona p ON cp.persona_id = p.persona_id
        JOIN rol r ON cp.rol_id = r.rol_id
        WHERE cp.causa_id = ? AND r.nombre_rol != 'Actuario'
        ORDER BY r.nombre_rol
    ");

    // Consulta Fechas
    $stmt_fechas = $conn->prepare("SELECT fecha FROM fecha_incidente WHERE causa_id = ? ORDER BY fecha ASC");

    foreach ($causas as $key => $causa) {
        $causa_id = $causa['causa_id'];
        $lista_archivos = [];

        // A. Archivo Principal (Demanda)
        if (!empty($causa['archivo_principal_path'])) {
            $lista_archivos[] = [
                "nombre_archivo" => "Demanda Inicial (Principal)",
                "ruta_archivo" => $causa['archivo_principal_path'],
                "tipo" => "principal"
            ];
        }

        // B. Archivos Adicionales (Sin fecha_subida)
        $stmt_archivos_extra->execute([$causa_id]);
        $extras = $stmt_archivos_extra->fetchAll(PDO::FETCH_ASSOC);
        foreach($extras as $extra) {
            $lista_archivos[] = [
                "nombre_archivo" => $extra['nombre_archivo'],
                "ruta_archivo" => $extra['ruta_archivo'],
                "tipo" => "adicional"
            ];
        }

        $causas[$key]['archivos'] = $lista_archivos;
        unset($causas[$key]['archivo_principal_path']);

        // C. Participantes
        $stmt_participantes->execute([$causa_id]);
        $causas[$key]['participantes'] = $stmt_participantes->fetchAll(PDO::FETCH_ASSOC);
        
        // D. Fechas
        $stmt_fechas->execute([$causa_id]);
        $fechas_raw = $stmt_fechas->fetchAll(PDO::FETCH_COLUMN);
        $fechas_formateadas = array_map(function($fecha) {
            return date("d-m-Y", strtotime($fecha));
        }, $fechas_raw);
        $causas[$key]['fechas_incidente'] = $fechas_formateadas;
    }

    http_response_code(200);
    echo json_encode([
        "actuario_id" => $actuario_id,
        "nombre_actuario" => $nombre_actuario,
        "causas_asignadas" => $causas,
        "estados_posibles" => $todos_los_estados
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["message" => "Error: " . $e->getMessage()]);
}
?>
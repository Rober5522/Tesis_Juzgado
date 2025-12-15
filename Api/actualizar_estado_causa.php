<?php
// Encabezados
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// --- 1. CONFIGURACIÓN DE LA BASE DE DATOS ---
$host = "localhost";
$db_name = "juzgado_db";
$username = "root";
$password = "";

try {
    $conn = new PDO("mysql:host=" . $host . ";dbname="."$db_name", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->exec("set names utf8");
} catch(PDOException $exception) {
    http_response_code(500);
    echo json_encode(["message" => "Error de conexión: " . $exception->getMessage()]);
    exit();
}

// --- 2. LÓGICA DE ACTUALIZACIÓN ---

// Obtener los datos enviados (en formato JSON)
$data = json_decode(file_get_contents("php://input"));

// Validar que los datos llegaron
if (
    !isset($data->causa_id) ||
    !isset($data->nuevo_estado_id) ||
    !isset($data->actuario_id)
) {
    http_response_code(400);
    echo json_encode(["message" => "Datos incompletos. Se requiere causa_id, nuevo_estado_id y actuario_id."]);
    exit();
}

try {
    // 3. Ejecutar la actualización
    // Se incluye "actuario_asignado_id = ?" como medida de seguridad
    // para asegurar que un actuario solo pueda modificar sus propias causas.
    $sql = "UPDATE causa 
            SET estado_id = ? 
            WHERE causa_id = ? AND actuario_asignado_id = ?";
            
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        $data->nuevo_estado_id,
        $data->causa_id,
        $data->actuario_id
    ]);

    // Verificar si la actualización fue exitosa
    if ($stmt->rowCount() > 0) {
        http_response_code(200);
        echo json_encode(["message" => "Estado de la causa actualizado exitosamente."]);
    } else {
        http_response_code(403); // Forbidden
        echo json_encode(["message" => "No se pudo actualizar la causa. Verifique que la causa le pertenece."]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["message" => "Error al actualizar la causa: " . $e->getMessage()]);
}
?>
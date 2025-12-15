<?php
// Encabezados para permitir peticiones AJAX desde cualquier origen (CORS) y especificar que la respuesta es JSON.
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// --- 1. CONFIGURACIÓN DE LA BASE DE DATOS ---
$host = "localhost";
$db_name = "juzgado_db";
$username = "root"; // Usuario por defecto en XAMPP
$password = "";     // Contraseña por defecto en XAMPP es vacía

try {
    $conn = new PDO("mysql:host=" . $host . ";dbname=" . $db_name, $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->exec("set names utf8");
} catch(PDOException $exception) {
    http_response_code(500);
    echo json_encode(["message" => "Error de conexión a la base de datos: " . $exception->getMessage()]);
    exit();
}


// --- 2. FUNCIÓN AUXILIAR PARA BUSCAR O CREAR PERSONAS ---
function findOrCreatePersona($conn, $personaData) {
    // Buscar si la persona ya existe
    $stmt = $conn->prepare("SELECT persona_id FROM persona WHERE rut = ?");
    $stmt->execute([$personaData['rut']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result) {
        // La persona ya existe, devolvemos su ID
        return $result['persona_id'];
    } else {
        // La persona no existe, la creamos
        $query = "INSERT INTO persona (rut, nombre_completo, domicilio, email, telefono) VALUES (?, ?, ?, ?, ?)";
        $stmt_insert = $conn->prepare($query);
        $stmt_insert->execute([
            $personaData['rut'],
            $personaData['nombreCompleto'],
            $personaData['domicilio'],
            isset($personaData['email']) ? $personaData['email'] : null,
            isset($personaData['telefono']) ? $personaData['telefono'] : null
        ]);
        // Devolvemos el ID de la persona recién creada
        return $conn->lastInsertId();
    }
}


// --- 3. LÓGICA PRINCIPAL DE PROCESAMIENTO ---

$conn->beginTransaction();

try {
    // IDs de Roles
    $roles = [
        'Abogado' => 1,
        'Actuario' => 2,
        'Demandante' => 3,
        'Demandado' => 4,
        'Querellante' => 5,
        'Querellado' => 6
    ];

    // --- Lógica para Número de Causa ---
    $stmt_count_total = $conn->query("SELECT COUNT(*) as total FROM causa");
    $total_causas_global = $stmt_count_total->fetch(PDO::FETCH_ASSOC)['total'];
    $nuevo_numero_causa = "CAUSA-" . str_pad($total_causas_global + 1, 6, "0", STR_PAD_LEFT);

    // --- Lógica de Asignación 1: JUZGADO ---
    $juzgado_id = ($total_causas_global % 5) + 1;

    // --- Lógica de Asignación 2: ACTUARIO ---
    $stmt_count_juzgado = $conn->prepare("SELECT COUNT(*) as total FROM causa WHERE juzgado_id = ?");
    $stmt_count_juzgado->execute([$juzgado_id]);
    $total_causas_juzgado = $stmt_count_juzgado->fetch(PDO::FETCH_ASSOC)['total'];

    $stmt_actuarios = $conn->prepare("SELECT persona_id FROM persona WHERE juzgado_id = ? ORDER BY persona_id ASC");
    $stmt_actuarios->execute([$juzgado_id]);
    $actuarios_del_juzgado = $stmt_actuarios->fetchAll(PDO::FETCH_COLUMN);

    $actuario_asignado_id = null;
    if (count($actuarios_del_juzgado) > 0) {
        $actuario_index = $total_causas_juzgado % count($actuarios_del_juzgado);
        $actuario_asignado_id = $actuarios_del_juzgado[$actuario_index];
    } else {
        throw new Exception("No se encontraron actuarios para el juzgado ID: " . $juzgado_id);
    }

    // --- Crear la Causa principal ---
    $query_causa = "INSERT INTO causa (numero_causa, tipo_procedimiento, tipo_reclamo, juzgado_id, actuario_asignado_id, archivo_principal_path) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt_causa = $conn->prepare($query_causa);
    
    // ================== INICIO DE LA CORRECCIÓN 1 ==================
    $archivo_principal_path = null;
    if (isset($_FILES['archivoDemanda'])) {
        $file_name = uniqid() . '-' . basename($_FILES['archivoDemanda']['name']);
        
        // Esta es la ruta para guardar en la BBDD (la que ve el HTML)
        $db_path = 'uploads/' . $file_name;
        
        // Esta es la ruta para mover el archivo (la que ve el servidor PHP)
        $upload_path = '../uploads/' . $file_name; 

        if (move_uploaded_file($_FILES['archivoDemanda']['tmp_name'], $upload_path)) {
            $archivo_principal_path = $db_path; // Guardamos la ruta 'uploads/file.pdf'
        } else {
            throw new Exception("Error al subir el archivo principal. Revisa los permisos de la carpeta 'uploads'.");
        }
    }
    // ================== FIN DE LA CORRECCIÓN 1 ==================

    $stmt_causa->execute([
        $nuevo_numero_causa,
        $_POST['tipoProcedimiento'],
        $_POST['tipoReclamo'],
        $juzgado_id,
        $actuario_asignado_id,
        $archivo_principal_path // Aquí se guarda la ruta $db_path
    ]);
    $causa_id = $conn->lastInsertId();

    // --- Procesar y vincular a las personas ---
    $stmt_causa_persona = $conn->prepare("INSERT INTO causa_persona (causa_id, persona_id, rol_id) VALUES (?, ?, ?)");

    // Abogado
    $con_abogado = isset($_POST['conAbogado']) && $_POST['conAbogado'] === 'true';
    if ($con_abogado) {
        $abogadoData = json_decode($_POST['abogado'], true);
        $abogado_id = findOrCreatePersona($conn, $abogadoData);
        $stmt_causa_persona->execute([$causa_id, $abogado_id, $roles['Abogado']]);
    }

    // Demandantes / Querellantes
    $rol_demandante = ($_POST['tipoProcedimiento'] === 'querella') ? $roles['Querellante'] : $roles['Demandante'];
    $demandantes = json_decode($_POST['demandantes'], true);
    foreach ($demandantes as $demandanteData) {
        $demandante_id = findOrCreatePersona($conn, $demandanteData);
        $stmt_causa_persona->execute([$causa_id, $demandante_id, $rol_demandante]);
    }

    // Demandados / Querellados
    $rol_demandado = ($_POST['tipoProcedimiento'] === 'querella') ? $roles['Querellado'] : $roles['Demandado'];
    $demandados = json_decode($_POST['demandados'], true);
    foreach ($demandados as $demandadoData) {
        $demandado_id = findOrCreatePersona($conn, $demandadoData);
        $stmt_causa_persona->execute([$causa_id, $demandado_id, $rol_demandado]);
    }

    // --- Guardar Fechas de Incidentes y Archivos Adicionales ---
    $fechasIncidentes = json_decode($_POST['fechasIncidentes'], true);
    $stmt_fecha = $conn->prepare("INSERT INTO fecha_incidente (causa_id, fecha) VALUES (?, ?)");
    foreach ($fechasIncidentes as $fecha) {
        if (!empty($fecha)) {
            $stmt_fecha->execute([$causa_id, $fecha]);
        }
    }

    // ================== INICIO DE LA CORRECCIÓN 2 ==================
    if (isset($_FILES['archivosAdicionales'])) {
        $stmt_archivo_add = $conn->prepare("INSERT INTO archivo_adicional (causa_id, nombre_archivo, ruta_archivo) VALUES (?, ?, ?)");
        
        foreach ($_FILES['archivosAdicionales']['name'] as $key => $name) {
            $file_name = uniqid() . '-' . basename($name);
            
            // Esta es la ruta para guardar en la BBDD (la que ve el HTML)
            $db_path = 'uploads/' . $file_name;
            
            // Esta es la ruta para mover el archivo (la que ve el servidor PHP)
            $upload_path = '../uploads/' . $file_name; 

            if (move_uploaded_file($_FILES['archivosAdicionales']['tmp_name'][$key], $upload_path)) {
                $stmt_archivo_add->execute([$causa_id, $name, $db_path]); // Guardamos la ruta 'uploads/file.pdf'
            }
        }
    }
    // ================== FIN DE LA CORRECCIÓN 2 ==================

    // Si todo fue exitoso, confirma los cambios en la base de datos
    $conn->commit();

    // Enviar respuesta de éxito al frontend
    http_response_code(201);
    echo json_encode([
        "message" => "Causa creada exitosamente.",
        "numero_causa" => $nuevo_numero_causa
    ]);

} catch (Exception $e) {
    // Si algo falla, revierte todos los cambios
    $conn->rollBack();

    // Enviar respuesta de error al frontend
    http_response_code(500);
    echo json_encode(["message" => "Error al crear la causa: " . $e->getMessage()]);
}
?>
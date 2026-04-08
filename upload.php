<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$host   = '82.223.120.91';
$port   = '5432';
$dbname = 'intermodal_db';
$user   = 'postgres';
$pass   = 'EstrucDatosAdmin';

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de conexión: ' . $e->getMessage()]);
    exit;
}

// Crear tabla cargas si no existe
$pdo->exec("
    CREATE TABLE IF NOT EXISTS cargas (
        id             SERIAL PRIMARY KEY,
        nombre_archivo VARCHAR(255),
        fecha_subida   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        num_registros  INT
    )
");

$action = $_GET['action'] ?? 'upload';

// ── UPLOAD ────────────────────────────────────────────────────────────────────
if ($action === 'upload') {

    $uploaded_file = $_FILES['file'] ?? null;
    if (!$uploaded_file || $uploaded_file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'No se recibió ningún archivo o hubo un error en la subida.']);
        exit;
    }

    try {
        $handle = fopen($uploaded_file['tmp_name'], 'r');
        if (!$handle) throw new Exception("No se pudo leer el archivo.");

        $cabeceras = fgetcsv($handle);
        if (!$cabeceras) throw new Exception("CSV vacío o sin cabeceras.");

        // Limpiar BOM si existe
        $cabeceras[0] = ltrim($cabeceras[0], "\xEF\xBB\xBF");

        $filas = [];
        while (($fila = fgetcsv($handle)) !== false) {
            if (count($fila) === count($cabeceras)) {
                $filas[] = array_combine($cabeceras, $fila);
            }
        }
        fclose($handle);

        if (empty($filas)) throw new Exception("El CSV no tiene filas de datos.");

        // Preparar INSERT dinámico (igual que procesador.php)
        $columnas     = implode(', ', array_map(fn($c) => '"' . $c . '"', $cabeceras));
        $placeholders = implode(', ', array_map(fn($c) => ':' . preg_replace('/[^a-zA-Z0-9_]/', '_', $c), $cabeceras));
        $sql  = "INSERT INTO lecturas_colector ($columnas) VALUES ($placeholders)";
        $stmt = $pdo->prepare($sql);

        $pdo->beginTransaction();

        // Borrar filas duplicadas (mismos timestamps)
        $timestamps = array_filter(array_map(fn($f) => $f['timestamp'] ?? null, $filas));
        if (!empty($timestamps)) {
            $tsPlaceholders = implode(',', array_fill(0, count($timestamps), '?'));
            $pdo->prepare("DELETE FROM lecturas_colector WHERE timestamp::text IN ($tsPlaceholders)")
                ->execute(array_values($timestamps));
        }

        // Insertar filas
        foreach ($filas as $fila) {
            $params = [];
            foreach ($cabeceras as $col) {
                $key          = ':' . preg_replace('/[^a-zA-Z0-9_]/', '_', $col);
                $params[$key] = $fila[$col] !== '' ? $fila[$col] : null;
            }
            $stmt->execute($params);
        }

        // Registrar la carga
        $pdo->prepare("INSERT INTO cargas (nombre_archivo, num_registros) VALUES (?, ?)")
            ->execute([$uploaded_file['name'], count($filas)]);

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => count($filas) . ' registros procesados correctamente.']);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── GET UPLOADS ───────────────────────────────────────────────────────────────
if ($action === 'get_uploads') {
    $stmt = $pdo->query(
        "SELECT id, nombre_archivo,
                to_char(fecha_subida, 'DD/MM/YYYY HH24:MI') AS fecha_fmt,
                num_registros
         FROM cargas
         ORDER BY fecha_subida DESC
         LIMIT 20"
    );
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ── DELETE UPLOAD ─────────────────────────────────────────────────────────────
if ($action === 'delete_upload') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id   = $data['id'] ?? null;
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'ID requerido.']);
        exit;
    }
    $pdo->prepare("DELETE FROM cargas WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Acción no reconocida.']);

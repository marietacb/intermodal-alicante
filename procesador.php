<?php
/**
 * procesador.php — ETL: Lee CSVs de datos_nuevos/ e inserta en PostgreSQL
 * Uso: php procesador.php
 */

$host   = '82.223.120.91';
$port   = '5432';
$dbname = 'intermodal_db';
$user   = 'postgres';
$pass   = 'EstrucDatosAdmin';

$CARPETA_NUEVOS     = __DIR__ . '/datos_nuevos';
$CARPETA_PROCESADOS = __DIR__ . '/datos_procesados';
$CARPETA_ERRORES    = __DIR__ . '/datos_con_errores';

foreach ([$CARPETA_NUEVOS, $CARPETA_PROCESADOS, $CARPETA_ERRORES] as $carpeta) {
    if (!is_dir($carpeta)) mkdir($carpeta, 0755, true);
}

$archivos = glob("$CARPETA_NUEVOS/*.csv");

if (empty($archivos)) {
    echo "No hay archivos nuevos.\n";
    exit(0);
}

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Exception $e) {
    echo "[ERROR] No se pudo conectar a la BD: " . $e->getMessage() . "\n";
    exit(1);
}

foreach ($archivos as $ruta_origen) {
    $archivo = basename($ruta_origen);

    try {
        // 1. Leer el CSV
        $handle = fopen($ruta_origen, 'r');
        if (!$handle) throw new Exception("No se pudo abrir el archivo");

        $cabeceras = fgetcsv($handle);
        if (!$cabeceras) throw new Exception("CSV vacio o sin cabeceras");

        // Limpiar BOM si existe
        $cabeceras[0] = ltrim($cabeceras[0], "\xEF\xBB\xBF");

        $filas = [];
        while (($fila = fgetcsv($handle)) !== false) {
            if (count($fila) === count($cabeceras)) {
                $filas[] = array_combine($cabeceras, $fila);
            }
        }
        fclose($handle);

        if (empty($filas)) throw new Exception("El CSV no tiene filas de datos");

        // 2. Insertar en la BD
        $columnas = implode(', ', array_map(fn($c) => '"' . $c . '"', $cabeceras));
        $placeholders = implode(', ', array_map(fn($c) => ':' . preg_replace('/[^a-zA-Z0-9_]/', '_', $c), $cabeceras));
        $sql = "INSERT INTO lecturas_colector ($columnas) VALUES ($placeholders)";
        $stmt = $pdo->prepare($sql);

        $pdo->beginTransaction();
        foreach ($filas as $fila) {
            $params = [];
            foreach ($cabeceras as $col) {
                $key = ':' . preg_replace('/[^a-zA-Z0-9_]/', '_', $col);
                $params[$key] = $fila[$col] !== '' ? $fila[$col] : null;
            }
            $stmt->execute($params);
        }
        $pdo->commit();

        // 3. Mover a procesados
        rename($ruta_origen, "$CARPETA_PROCESADOS/$archivo");
        echo "[OK] $archivo guardado en BD (" . count($filas) . " filas).\n";

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // 4. Mover a errores si falla
        rename($ruta_origen, "$CARPETA_ERRORES/$archivo");
        echo "[ERROR] $archivo: " . $e->getMessage() . "\n";
    }
}

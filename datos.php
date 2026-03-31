<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

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

    $stmt = $pdo->query(
        "SELECT * FROM lecturas_colector ORDER BY timestamp ASC LIMIT 1000"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Formatear timestamp igual que en Flask
    foreach ($rows as &$row) {
        if (isset($row['timestamp'])) {
            $dt = new DateTime($row['timestamp']);
            $row['timestamp'] = $dt->format('Y-m-d\TH:i:s');
        }
    }

    echo json_encode($rows, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

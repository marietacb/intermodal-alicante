<?php
// BORRAR ESTE ARCHIVO TRAS EL DIAGNOSTICO
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h3>Extensiones PDO disponibles:</h3>";
echo implode(', ', PDO::getAvailableDrivers());
echo "<br><br>";

echo "<h3>Conexion a PostgreSQL:</h3>";
try {
    $pdo = new PDO(
        'pgsql:host=82.223.120.91;port=5432;dbname=intermodal_db',
        'postgres',
        'EstrucDatosAdmin',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "Conexion OK<br>";
    $stmt = $pdo->query("SELECT COUNT(*) FROM lecturas_colector");
    echo "Filas en BD: " . $stmt->fetchColumn();
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}

# Mecanismo de Subida de Datos — Guia para Reutilizar en Otro Proyecto

Este documento describe completamente el sistema de subida de archivos CSV del proyecto inclinometros para que pueda ser replicado en otro proyecto.

---

## Vision General del Flujo

```
Usuario selecciona sensor + version + archivo CSV
        |
        v
Frontend (FormData + axios) → POST api.php?action=upload
        |
        v
Backend PHP:
  1. Validar sesion y permisos
  2. Leer y parsear el CSV
  3. Detectar cabeceras (Eje A y Eje B)
  4. Transformar datos (fechas, floats)
  5. Registrar la carga en tabla `cargas`
  6. Borrar duplicados (mismas fechas ya existentes)
  7. Insertar registros en tabla `lecturas`
  8. Commit o Rollback
        |
        v
Respuesta JSON → SweetAlert2 con exito o error
```

---

## 1. Formato del Archivo CSV

El sistema espera un CSV delimitado por **punto y coma** con esta estructura:

```
depth;01/01/2024;02/01/2024;03/01/2024
0.5;-2.34;-2.45;-2.56
1.0;-1.23;-1.34;-1.45
1.5;-0.56;-0.67;-0.78

depth;01/01/2024;02/01/2024;03/01/2024
0.5;3.44;3.55;3.66
1.0;2.33;2.44;2.55
1.5;1.22;1.33;1.44
```

### Reglas del formato
- **Delimitador**: punto y coma (`;`)
- **Primera columna**: siempre la palabra `depth` en la cabecera, luego los valores de profundidad en metros
- **Columnas siguientes**: fechas en formato `DD/MM/YYYY` en la cabecera, luego los valores de medicion en mm
- **Decimales**: pueden usar coma o punto (el sistema convierte automaticamente)
- **Dos bloques**: el primer bloque es Eje A, el segundo bloque es Eje B (separados por linea en blanco o contiguos)
- **Eje B opcional**: si solo hay un bloque, los valores de `valor_b` quedan como NULL o 0

---

## 2. Base de Datos

### Tablas necesarias

```sql
-- Sensores / instrumentos
CREATE TABLE sensores (
    id        SERIAL PRIMARY KEY,
    nombre    VARCHAR(100) NOT NULL,
    version   INT DEFAULT 1,
    -- Campos adicionales segun el proyecto:
    latitud   FLOAT,
    longitud  FLOAT,
    nf        FLOAT,          -- nivel freatico (inclinometros)
    lugar     VARCHAR(50),
    foto_path VARCHAR(255)
);

-- Registro de cargas (historial de subidas)
CREATE TABLE cargas (
    id             SERIAL PRIMARY KEY,
    sensor_id      INT NOT NULL REFERENCES sensores(id) ON DELETE CASCADE,
    nombre_archivo VARCHAR(255),
    usuario        VARCHAR(100),
    fecha_subida   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Lecturas / datos medidos
CREATE TABLE lecturas (
    id          SERIAL PRIMARY KEY,
    sensor_id   INT NOT NULL REFERENCES sensores(id) ON DELETE CASCADE,
    carga_id    INT NOT NULL REFERENCES cargas(id) ON DELETE CASCADE,
    fecha       DATE NOT NULL,
    profundidad FLOAT NOT NULL,
    valor_a     FLOAT,
    valor_b     FLOAT
);
```

### Adaptar al nuevo proyecto
Si el nuevo proyecto tiene variables distintas a `valor_a` / `valor_b`, renombrar esas columnas o anadir las que correspondan. El resto de la logica (cargas, sensores, transacciones) es identica.

---

## 3. Backend PHP (api.php)

### Endpoint de subida

```php
<?php
// api.php — fragmento del action=upload

session_start();

// Conexion a PostgreSQL (adaptar credenciales)
$pdo = new PDO("pgsql:host=localhost;dbname=tu_base_de_datos", "usuario", "password");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$action = $_GET['action'] ?? '';

// ── UPLOAD ────────────────────────────────────────────────────────────────────
if ($action === 'upload') {

    // 1. Autenticacion y permisos
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'No autorizado']);
        exit;
    }
    // Bloquear rol 'cliente' (solo admin puede subir)
    if (($_SESSION['rol'] ?? '') === 'cliente') {
        echo json_encode(['success' => false, 'message' => 'Permisos insuficientes.']);
        exit;
    }

    // 2. Validar parametros
    $sensor_id    = $_POST['sensor_id'] ?? null;
    $uploaded_file = $_FILES['file']    ?? null;
    if (!$sensor_id || !$uploaded_file) {
        echo json_encode(['success' => false, 'message' => 'Faltan datos']);
        exit;
    }

    try {
        // 3. Leer el archivo
        $content = file_get_contents($uploaded_file['tmp_name']);
        $lines   = preg_split('/\r\n|\r|\n/', $content);

        // 4. Detectar cabeceras (lineas que empiezan por "depth;" y tienen fecha DD/MM/YYYY)
        $headerIndices = [];
        foreach ($lines as $i => $line) {
            $clean = trim($line);
            if (stripos($clean, 'depth;') === 0 && preg_match('/\d{2}\/\d{2}\/\d{4}/', $clean)) {
                $headerIndices[] = $i;
            }
        }
        if (empty($headerIndices)) {
            throw new Exception('No se encontraron cabeceras válidas.');
        }

        // 5. Parsear bloques de datos
        $idx_a  = $headerIndices[0];
        $idx_b  = $headerIndices[1] ?? null;
        $end_a  = $idx_b ? ($idx_b - 2) : count($lines);

        $mergedData = [];

        // Funcion auxiliar para procesar un bloque (Eje A o Eje B)
        function processBlock($lines, $start, $end, $axis, &$mergedData) {
            $header  = explode(';', trim($lines[$start]));
            $dateMap = [];  // column_index => 'YYYY-MM-DD'

            foreach ($header as $col => $val) {
                $val = trim($val);
                if (preg_match('/(\d{2})\/(\d{2})\/(\d{4})/', $val, $m)) {
                    $dateMap[$col] = "{$m[3]}-{$m[2]}-{$m[1]}";
                }
            }

            for ($i = $start + 1; $i <= $end; $i++) {
                $row = explode(';', trim($lines[$i] ?? ''));
                if (empty(trim($row[0] ?? ''))) continue;

                $profundidad = floatval(str_replace(',', '.', $row[0]));
                $profKey     = number_format($profundidad, 4, '.', '');

                foreach ($dateMap as $col => $dateStr) {
                    $val = floatval(str_replace(',', '.', $row[$col] ?? 0));
                    if (!isset($mergedData[$dateStr][$profKey])) {
                        $mergedData[$dateStr][$profKey] = ['a' => null, 'b' => null];
                    }
                    $mergedData[$dateStr][$profKey][$axis] = $val;
                }
            }
        }

        processBlock($lines, $idx_a, $end_a, 'a', $mergedData);
        if ($idx_b !== null) {
            processBlock($lines, $idx_b, count($lines) - 1, 'b', $mergedData);
        }

        // 6. Guardar en base de datos (transaccion atomica)
        $pdo->beginTransaction();

        // Registrar la carga
        $stmtCarga = $pdo->prepare(
            "INSERT INTO cargas (sensor_id, nombre_archivo, usuario) VALUES (?, ?, ?) RETURNING id"
        );
        $stmtCarga->execute([
            $sensor_id,
            $uploaded_file['name'],
            $_SESSION['usuario'] ?? 'desconocido'
        ]);
        $carga_id = $stmtCarga->fetchColumn();

        // Borrar lecturas previas para las mismas fechas (evitar duplicados)
        $fechas = array_keys($mergedData);
        $placeholders = implode(',', array_fill(0, count($fechas), '?'));
        $pdo->prepare(
            "DELETE FROM lecturas WHERE sensor_id = ? AND fecha IN ($placeholders)"
        )->execute(array_merge([$sensor_id], $fechas));

        // Insertar datos nuevos
        $stmtInsert = $pdo->prepare(
            "INSERT INTO lecturas (sensor_id, carga_id, fecha, profundidad, valor_a, valor_b)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $count = 0;
        foreach ($mergedData as $fecha => $profs) {
            foreach ($profs as $prof => $vals) {
                $stmtInsert->execute([
                    $sensor_id, $carga_id, $fecha,
                    floatval($prof), $vals['a'], $vals['b']
                ]);
                $count++;
            }
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => "Archivo guardado. $count registros procesados."]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
```

---

## 4. Frontend HTML + JavaScript

### Formulario HTML

```html
<!-- Dependencias necesarias -->
<script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<!-- Formulario de subida -->
<form id="uploadForm">

  <!-- Selector de sensor -->
  <div class="mb-3">
    <label class="form-label fw-bold">Sensor</label>
    <select id="sensorSelect" class="form-select" required>
      <option value="">Cargando sensores...</option>
    </select>
  </div>

  <!-- Selector de version (se carga segun sensor elegido) -->
  <div class="mb-3">
    <label class="form-label fw-bold">Version</label>
    <select id="versionSelect" class="form-select" required>
      <option value="">-- Selecciona version --</option>
    </select>
    <div class="form-text text-muted">Los datos se guardaran en la version elegida.</div>
  </div>

  <!-- Archivo CSV -->
  <div class="mb-3">
    <label class="form-label fw-bold">Archivo CSV</label>
    <input type="file" name="file" class="form-control" accept=".csv" required>
  </div>

  <button type="submit" class="btn btn-success w-100 fw-bold py-2">
    SUBIR DATOS
  </button>
</form>

<!-- Historial de cargas -->
<div id="historialContainer" class="mt-4"></div>
```

### JavaScript

```javascript
// ── Cargar lista de sensores al iniciar ────────────────────────────────────────
async function loadSensors() {
  try {
    const res = await axios.get('api.php?action=get_sensors');
    const select = document.getElementById('sensorSelect');
    select.innerHTML = '<option value="">-- Selecciona sensor --</option>';
    (res.data || []).forEach(s => {
      const opt = document.createElement('option');
      opt.value = s.id;
      opt.textContent = s.nombre;
      select.appendChild(opt);
    });
  } catch (e) {
    console.error('Error cargando sensores:', e);
  }
}

// ── Cargar versiones segun sensor seleccionado ─────────────────────────────────
async function loadVersions(baseId) {
  const select = document.getElementById('versionSelect');
  select.innerHTML = '<option value="">Cargando...</option>';

  try {
    const res = await axios.get(`api.php?action=get_versions&id=${baseId}`);
    select.innerHTML = '<option value="">-- Selecciona version --</option>';
    (res.data || []).forEach((v, i) => {
      const opt = document.createElement('option');
      opt.value = v.id;
      const periodo = v.f_ini && v.f_fin
        ? `Periodo: ${v.f_ini} - ${v.f_fin}`
        : 'Sin datos aun';
      opt.textContent = `v${v.version} — ${periodo}${i === 0 ? ' (Actual)' : ''}`;
      select.appendChild(opt);
    });
    // Auto-seleccionar la primera (la mas reciente)
    if (select.options.length > 1) select.selectedIndex = 1;
  } catch (e) {
    console.error('Error cargando versiones:', e);
  }
}

// ── Envio del formulario ───────────────────────────────────────────────────────
document.getElementById('uploadForm').addEventListener('submit', async (e) => {
  e.preventDefault();

  const sensorId = document.getElementById('versionSelect').value;
  if (!sensorId) {
    return Swal.fire('Atención', 'Selecciona una version', 'warning');
  }

  const formData = new FormData(e.target);
  formData.append('sensor_id', sensorId);

  Swal.fire({ title: 'Subiendo...', didOpen: () => Swal.showLoading() });

  try {
    const res = await axios.post('api.php?action=upload', formData, {
      headers: { 'Content-Type': 'multipart/form-data' }
    });

    if (res.data.success) {
      Swal.fire('Exito', res.data.message, 'success');
      e.target.reset();
      loadHistory(); // Refrescar historial
    } else {
      Swal.fire('Error', res.data.message, 'error');
    }
  } catch (err) {
    console.error(err);
    Swal.fire('Error', 'Fallo de conexion', 'error');
  }
});

// ── Cambio de sensor dispara carga de versiones ────────────────────────────────
document.getElementById('sensorSelect').addEventListener('change', function () {
  if (this.value) loadVersions(this.value);
});

// ── Cargar historial de subidas ────────────────────────────────────────────────
async function loadHistory() {
  try {
    const res = await axios.get('api.php?action=get_uploads');
    const container = document.getElementById('historialContainer');
    if (!res.data || res.data.length === 0) {
      container.innerHTML = '<p class="text-muted">Sin cargas registradas.</p>';
      return;
    }

    let html = `<table class="table table-sm table-striped">
      <thead><tr>
        <th>Fecha</th><th>Sensor</th><th>Archivo</th><th>Usuario</th><th>Registros</th><th></th>
      </tr></thead><tbody>`;

    res.data.forEach(c => {
      html += `<tr>
        <td>${c.fecha_fmt}</td>
        <td>${c.sensor_nombre}</td>
        <td>${c.nombre_archivo}</td>
        <td>${c.usuario}</td>
        <td>${c.num_datos}</td>
        <td>
          <button class="btn btn-danger btn-sm" onclick="deleteUpload(${c.id})">
            Eliminar
          </button>
        </td>
      </tr>`;
    });

    html += '</tbody></table>';
    container.innerHTML = html;
  } catch (e) {
    console.error('Error cargando historial:', e);
  }
}

// ── Eliminar una carga (y sus datos en cascada) ────────────────────────────────
async function deleteUpload(cargaId) {
  const confirm = await Swal.fire({
    title: '¿Eliminar esta carga?',
    text: 'Se eliminaran todos los datos asociados.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#d33',
    confirmButtonText: 'Si, eliminar'
  });
  if (!confirm.isConfirmed) return;

  try {
    const res = await axios.post('api.php?action=delete_upload', { id: cargaId });
    if (res.data.success) {
      Swal.fire('Eliminado', '', 'success');
      loadHistory();
    } else {
      Swal.fire('Error', res.data.message, 'error');
    }
  } catch (e) {
    Swal.fire('Error', 'Fallo de conexion', 'error');
  }
}

// ── Inicializar ────────────────────────────────────────────────────────────────
loadSensors();
loadHistory();
```

---

## 5. Endpoints de Soporte en api.php

Ademas del `action=upload`, se necesitan estos endpoints:

### get_sensors
```php
if ($action === 'get_sensors') {
    $stmt = $pdo->query("SELECT DISTINCT ON (nombre) id, nombre, version
                         FROM sensores ORDER BY nombre, version DESC");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}
```

### get_versions
```php
if ($action === 'get_versions') {
    $baseId = $_GET['id'] ?? null;
    // Buscar el nombre del sensor base
    $stmtNombre = $pdo->prepare("SELECT nombre FROM sensores WHERE id = ?");
    $stmtNombre->execute([$baseId]);
    $nombre = $stmtNombre->fetchColumn();

    // Traer todas las versiones con rango de fechas
    $stmt = $pdo->prepare(
        "SELECT s.id, s.version,
                to_char(MIN(l.fecha), 'DD/MM/YYYY') AS f_ini,
                to_char(MAX(l.fecha), 'DD/MM/YYYY') AS f_fin
         FROM sensores s
         LEFT JOIN lecturas l ON l.sensor_id = s.id
         WHERE s.nombre = ?
         GROUP BY s.id, s.version
         ORDER BY s.version DESC"
    );
    $stmt->execute([$nombre]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}
```

### get_uploads
```php
if ($action === 'get_uploads') {
    $stmt = $pdo->query(
        "SELECT c.id,
                c.nombre_archivo,
                to_char(c.fecha_subida, 'DD/MM/YYYY HH24:MI') AS fecha_fmt,
                c.usuario,
                s.nombre AS sensor_nombre,
                (SELECT COUNT(*) FROM lecturas l WHERE l.carga_id = c.id) AS num_datos
         FROM cargas c
         JOIN sensores s ON c.sensor_id = s.id
         ORDER BY c.fecha_subida DESC
         LIMIT 50"
    );
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}
```

### delete_upload
```php
if ($action === 'delete_upload') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id   = $data['id'] ?? null;
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'ID requerido']);
        exit;
    }
    // Las lecturas se eliminan automaticamente por ON DELETE CASCADE
    $stmt = $pdo->prepare("DELETE FROM cargas WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}
```

---

## 6. Sistema de Versiones de Sensor

Este proyecto permite tener **multiples versiones del mismo sensor** (por ejemplo, si un sensor se reinstala o recalibra). La logica es:

```php
// Crear nuevo sensor con version automatica
if ($action === 'create_sensor') {
    $nombre        = $_POST['nombre'];
    $createVersion = isset($_POST['create_version']); // checkbox

    if ($createVersion) {
        // Obtener la version maxima actual para este nombre
        $stmt = $pdo->prepare("SELECT COALESCE(MAX(version), 0) FROM sensores WHERE nombre = ?");
        $stmt->execute([$nombre]);
        $version = (int)$stmt->fetchColumn() + 1;
    } else {
        $version = 1;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO sensores (nombre, version, latitud, longitud, nf, lugar)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$nombre, $version, $lat, $lon, $nf, $lugar]);
    echo json_encode(['success' => true]);
    exit;
}
```

Esto permite:
- `CA01 v1` — primer periodo de medicion
- `CA01 v2` — tras reinstalacion del sensor
- Cada version tiene sus propios datos en `lecturas`
- El dropdown de version en el formulario muestra el periodo de cada una

---

## 7. Dependencias y Configuracion

### PHP
- PHP 7.4+
- Extension PDO + PDO_PGSQL (o PDO_MYSQL si se adapta)
- Sesiones PHP activas (`session_start()`)

### Base de datos
- PostgreSQL (el codigo usa `RETURNING id` y `to_char()`)
- Para MySQL: cambiar `RETURNING id` por `lastInsertId()` y `to_char(fecha, 'DD/MM/YYYY')` por `DATE_FORMAT(fecha, '%d/%m/%Y')`

### Frontend
```html
<!-- Axios para peticiones HTTP -->
<script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
<!-- SweetAlert2 para feedback visual -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- Bootstrap (opcional, para estilos del formulario) -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5/dist/css/bootstrap.min.css" rel="stylesheet">
```

---

## 8. Checklist para Implementar en el Nuevo Proyecto

- [ ] Crear las tablas `sensores`, `cargas`, `lecturas` (adaptar columnas si las variables son distintas)
- [ ] Copiar el fragmento PHP de `action=upload` en el api del nuevo proyecto
- [ ] Anadir los endpoints `get_sensors`, `get_versions`, `get_uploads`, `delete_upload`
- [ ] Copiar el HTML del formulario y el JavaScript de subida
- [ ] Verificar que la sesion PHP usa `$_SESSION['user_id']` y `$_SESSION['rol']`
- [ ] Ajustar el formato del CSV si las columnas de datos son distintas a `valor_a`/`valor_b`
- [ ] Si se usa MySQL en lugar de PostgreSQL, adaptar `RETURNING id` y `to_char()`
- [ ] Probar con un CSV de ejemplo antes de conectar los graficos

---

## Notas de Adaptacion

**Si el nuevo proyecto no tiene Eje A / Eje B** sino otras variables (por ejemplo temperatura y humedad), cambiar en el PHP:
- La funcion `processBlock` para que use los nombres de columna correctos
- Las columnas en `lecturas` de `valor_a`, `valor_b` a los nombres nuevos
- El CSV puede tener un solo bloque (sin segundo `depth;` header)

**Si no se necesita control de versiones**, simplificar: eliminar la columna `version` en `sensores` y quitar el dropdown de version del formulario. El `sensor_id` lo toma directamente del selector de sensor.

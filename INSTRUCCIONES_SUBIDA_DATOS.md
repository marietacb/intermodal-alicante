# Instrucciones para subir datos al sistema Intermodal Alicante

## Requisitos del archivo CSV

El archivo debe estar en formato CSV (valores separados por comas) con las siguientes columnas **en este orden exacto**:

```
timestamp,S1_CH1_T_273901_0001,S2_CH1_T_273902_0001,S3_CH1_T_273904_0002,S4_CH2_T_273901_0002,S5_CH2_T_273902_0003,S6_CH2_T_273904_0003,S9_CH4_T_240351_0001,S10_CH4_T_273903_0001,S11_CH4_T_273904_0001,S1_CH1_LG_273901_0001,S2_CH1_LG_273902_0001,S3_CH1_LG_273904_0002,S4_CH2_LG_273901_0002,S5_CH2_LG_273902_0003,S6_CH2_LG_273904_0003,S9_CH4_LG_240351_0001,S10_CH4_LG_273903_0001,S11_CH4_LG_273904_0001,S7_CH3_CL_242474_0001,S8_CH3_CL_227745_0002,Nivel_RS485_m
```

### Formato del campo `timestamp`

```
YYYY-MM-DD HH:MM:SS
```

Ejemplo: `2026-03-28 10:00:00`

### Ejemplo de archivo válido

```
timestamp,S1_CH1_T_273901_0001,S2_CH1_T_273902_0001,...,Nivel_RS485_m
2026-03-28 10:00:00,15.25,20.66,18.71,18.59,18.85,20.45,19.18,19.90,19.23,0.99,-4.01,4.15,-0.47,1.27,1.34,1.08,0.05,0.25,-5.25,-0.93,2.446
2026-03-28 10:15:00,15.17,20.59,18.60,18.39,18.70,20.33,19.06,19.77,19.08,0.76,5.13,3.11,7.10,7.21,5.62,6.38,5.01,6.21,-5.26,-0.93,2.433
```

**Reglas importantes:**
- La primera fila debe ser siempre la cabecera (nombres de columnas).
- Los valores decimales usan punto (`.`), no coma.
- Las celdas vacías se permiten y se tratan como nulo.
- La codificación del archivo debe ser **UTF-8**.

---

## Pasos para subir el archivo

### Paso 1 — Acceder al servidor

Conéctate al servidor usando un cliente FTP (como FileZilla) o el panel de control del hosting.

- **Servidor:** `calsens.eu`
- Accede con las credenciales que te hayan proporcionado.

### Paso 2 — Copiar el archivo a la carpeta correcta

Sube el archivo CSV a la siguiente carpeta del servidor:

```
/intermodal/datos_nuevos/
```

> El nombre del archivo puede ser cualquiera, pero se recomienda incluir la fecha para identificarlo fácilmente.  
> Ejemplo: `lecturas_2026-03-28.csv`

### Paso 3 — Ejecutar el procesador

Una vez subido el archivo, ejecuta el procesador desde la terminal del servidor:

```bash
php /ruta/intermodal/procesador.php
```

> Si tienes acceso al panel de control del hosting (cPanel, Plesk, etc.), también puedes ejecutarlo desde el apartado de tareas cron o terminal web.

### Paso 4 — Verificar el resultado

El sistema mueve automáticamente el archivo según el resultado:

| Resultado | Dónde queda el archivo | Mensaje en pantalla |
|-----------|------------------------|---------------------|
| Correcto  | `/datos_procesados/`   | `[OK] archivo.csv guardado en BD (N filas).` |
| Error     | `/datos_con_errores/`  | `[ERROR] archivo.csv: descripción del error` |

Si el archivo termina en `datos_con_errores/`, revisa que el formato del CSV sea correcto (columnas, codificación, separador) y vuelve a intentarlo moviéndolo a `datos_nuevos/`.

---

## Errores frecuentes

| Error | Causa | Solución |
|-------|-------|----------|
| `CSV vacío o sin cabeceras` | El archivo está vacío o mal formado | Verifica que tiene cabecera y al menos una fila de datos |
| `número de columnas incorrecto` | Faltan o sobran columnas | Comprueba que el CSV tiene exactamente las 21 columnas indicadas |
| `violación de clave única` | Ya existen registros con el mismo timestamp | Elimina los duplicados del CSV antes de subirlo |
| `No se pudo conectar a la BD` | Problema de red o credenciales | Contacta con el administrador del sistema |

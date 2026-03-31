import os
import shutil
import pandas as pd
from sqlalchemy import create_engine, Table, MetaData

# --- CONFIGURACIÓN BD ---
DB_USER = 'postgres'
DB_PASS = 'EstrucDatosAdmin'
DB_HOST = '82.223.120.91' 
DB_PORT = '5432'
DB_NAME = 'intermodal_db'

# CAMBIO 1: Especificar explícitamente postgresql+psycopg2
cadena_conexion = f'postgresql+psycopg2://{DB_USER}:{DB_PASS}@{DB_HOST}:{DB_PORT}/{DB_NAME}'
engine = create_engine(cadena_conexion)

CARPETA_NUEVOS = 'datos_nuevos'
CARPETA_PROCESADOS = 'datos_procesados'
CARPETA_ERRORES = 'datos_con_errores'

# Crear carpetas si no existen
for carpeta in [CARPETA_NUEVOS, CARPETA_PROCESADOS, CARPETA_ERRORES]:
    os.makedirs(carpeta, exist_ok=True)

def procesar_archivos():
    archivos = [f for f in os.listdir(CARPETA_NUEVOS) if f.endswith('.csv')]
    
    if not archivos:
        print("No hay archivos nuevos.")
        return

    for archivo in archivos:
        ruta_origen = os.path.join(CARPETA_NUEVOS, archivo)
        
        try:
            # 1. Leer el CSV
            df = pd.read_csv(ruta_origen)
            df['timestamp'] = pd.to_datetime(df['timestamp'])
            
            tabla = Table('lecturas_colector', MetaData(), autoload_with=engine)
            with engine.begin() as conn:
                conn.execute(tabla.insert(), df.to_dict(orient='records'))
            
            # 3. Mover a procesados
            shutil.move(ruta_origen, os.path.join(CARPETA_PROCESADOS, archivo))
            print(f"[OK] {archivo} guardado en BD.")
            
        except Exception as e:
            # 4. Mover a errores si falla
            shutil.move(ruta_origen, os.path.join(CARPETA_ERRORES, archivo))
            print(f"[ERROR] {archivo}: {e}")

if __name__ == '__main__':
    procesar_archivos()
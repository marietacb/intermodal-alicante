from flask import Flask, render_template, Response
from flask_cors import CORS
import pandas as pd
from sqlalchemy import create_engine, text

app = Flask(__name__)
CORS(app) 

# --- CONFIGURACIÓN BD ---
DB_USER = 'postgres'
DB_PASS = 'EstrucDatosAdmin'
DB_HOST = '82.223.120.91'
DB_PORT = '5432'
DB_NAME = 'intermodal_db'

# ESPECIFICAMOS postgresql+psycopg2 AQUÍ TAMBIÉN
cadena_conexion = f'postgresql+psycopg2://{DB_USER}:{DB_PASS}@{DB_HOST}:{DB_PORT}/{DB_NAME}'
engine = create_engine(cadena_conexion)

@app.route('/')
def index():
    return render_template('index.html')

@app.route('/datos')
def obtener_datos():
    try:
        query = text("SELECT * FROM lecturas_colector ORDER BY timestamp ASC LIMIT 1000")

        with engine.connect() as conn:
            result = conn.execute(query)
            df = pd.DataFrame(result.fetchall(), columns=result.keys())
        
        df['timestamp'] = pd.to_datetime(df['timestamp']).dt.strftime('%Y-%m-%dT%H:%M:%S')
        json_data = df.to_json(orient='records')
        
        return Response(json_data, mimetype='application/json')
    
    except Exception as e:
        print(f"Error interno en la BD: {e}")
        return Response('{"error": "No hay datos o fallo en la BD"}', status=500, mimetype='application/json')

if __name__ == '__main__':
    app.run(debug=True, port=8000)
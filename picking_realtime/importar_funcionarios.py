import pandas as pd
from mysql.connector import connect, Error
import os

# ===================================================
# ⚠️ 1. AJUSTE AS CONFIGURAÇÕES 
# ===================================================
NOME_ARQUIVO_DADOS = 'func.xlsx' # 🚨 MUDEI O NOME DA VARIÁVEL PARA SER GENÉRICA E FÁCIL DE ENCONTRAR! 🚨

DB_CONFIG = {
    "host": "127.0.0.1",
    "port": 3307,        
    "user": "root",      
    "password": "SUA_SENHA_AQUI",  # 🚨 MUDAR ESTA SENHA 🚨
    "database": "picking" 
}
# ===================================================

# Definição do MAPEAMENTO de Colunas:
COLUNA_MAP = {
    'NOME DO FUNCIONÁRIO': 'nome',
    'FUNÇÃO': 'funcao_padrao',
    'PERÍODO': 'periodo_padrao'
}

def importar_dados():
    # Agora a checagem usa o nome de arquivo correto:
    if not os.path.exists(NOME_ARQUIVO_DADOS): 
        print(f"❌ Erro: Arquivo '{NOME_ARQUIVO_DADOS}' não encontrado!")
        print("Certifique-se de que o arquivo está no mesmo diretório.")
        return

    try:
        print(f"✅ Lendo arquivo Excel: {NOME_ARQUIVO_DADOS}...")
        
        # Lê o arquivo Excel (.xlsx)
        df = pd.read_excel(NOME_ARQUIVO_DADOS) 

        # ... O RESTANTE DO CÓDIGO PERMANECE IGUAL (LEITURA, LIMPEZA, INSERÇÃO) ...
        # ... (Mantendo a lógica de mapeamento, limpeza, etc.)
        
        # O resto do código da Seção 3 (Conexão e Inserção no MariaDB)
        # deve usar a variável 'df' e rodar normalmente.
        
        # CONTINUAÇÃO DA LÓGICA DE INSERÇÃO NO BANCO (NÃO COPIADA AQUI POR BREVIDADE)
        colunas_esperadas = ['nome', 'funcao_padrao', 'periodo_padrao']
        
        df.columns = df.columns.str.upper().str.strip()
        df = df.rename(columns={k.upper(): v for k, v in COLUNA_MAP.items()})
        df = df[colunas_esperadas]
        
        df.dropna(subset=['nome', 'funcao_padrao'], inplace=True)
        for col in colunas_esperadas:
             df[col] = df[col].astype(str).str.strip().str.upper()
        df.drop_duplicates(subset=['nome'], inplace=True)

        print(f"✅ {len(df)} registros de funcionários prontos para inserção.")
        
        conn = connect(**DB_CONFIG)
        cursor = conn.cursor()
        
        print("⚠️ Limpando a tabela 'db_funcionarios' para nova carga...")
        cursor.execute("TRUNCATE TABLE db_funcionarios") 

        sql_insert = "INSERT INTO db_funcionarios (nome, funcao_padrao, periodo_padrao) VALUES (%s, %s, %s)"

        registros_inseridos = 0
        for index, row in df.iterrows():
            try:
                cursor.execute(sql_insert.replace('INSERT INTO', 'INSERT IGNORE INTO'), 
                               (row['nome'], row['funcao_padrao'], row['periodo_padrao']))
                registros_inseridos += 1
            except Error as db_err:
                print(f"Erro ao inserir {row['nome']}: {db_err}")
                
        conn.commit()
        print(f"🎉 Importação concluída! {registros_inseridos} funcionários inseridos no DB.")

    except Error as e:
        print(f"❌ Erro de Banco de Dados: {e}")
    except KeyError as e:
        print(f"❌ Erro: Coluna não encontrada. Verifique se o nome das colunas no Excel é exato. Erro: {e}")
    except Exception as e:
        print(f"❌ Ocorreu um erro: {e}")
    finally:
        if 'conn' in locals() and conn.is_connected():
            cursor.close()
            conn.close()

if __name__ == "__main__":
    importar_dados()
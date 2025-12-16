import time
from mysql.connector import connect, Error
from typing import Dict, Any

# ===================================================
# 1. CONFIGURAÇÃO (DEVE BATER COM O main_teste.py)
# ===================================================
DB_CONFIG = {
    "host": "127.0.0.1", # Conexão local no Servidor 0.63
    "port": 3307,        
    "user": "root",
    "database": "picking"      
}

# Define o tamanho do bloco de processamento
BATCH_SIZE = 1000

# ===================================================
# 2. FUNÇÃO DE FAXINA (BACKFILL)
# ===================================================

def run_backfill_transacao_sync() -> int:
    """
    Percorre o log_operacao e preenche o codigo_transacao que está NULL 
    usando a tabela de vendas ETL (vendas_erp_detalhe).
    Processa em batches (blocos) para não sobrecarregar o DB.
    """
    conn = None
    total_atualizados = 0
    erros_ocorridos = 0
    
    print("\n=============================================")
    print(" 🧹 INICIANDO ROBÔ BACKFILL DE CÓDIGO TRANSACAO 🧹")
    print("=============================================")

    # Vamos rodar em loop até não haver mais registros elegíveis para atualização
    while True:
        try:
            conn = connect(**DB_CONFIG)
            cursor = conn.cursor()
            
            # 1. Encontra logs que PRECISAM de atualização
            # O LEFT JOIN é substituído por INNER JOIN pois a gente SÓ quer atualizar 
            # se o pedido existir na tabela de vendas ETL.
            sql_select = f"""
                SELECT 
                    L.id, 
                    V.codigo_transacao
                FROM 
                    log_operacao AS L
                INNER JOIN 
                    vendas_erp_detalhe AS V ON L.pedido_id = V.numero_pedido
                WHERE 
                    (L.codigo_transacao IS NULL OR L.codigo_transacao = '') 
                LIMIT {BATCH_SIZE} 
            """
            cursor.execute(sql_select)
            registros_para_atualizar = cursor.fetchall()
            
            if not registros_para_atualizar:
                print("\n✅ Backfill concluído: Nenhum registro para atualizar na base de dados.")
                break
                
            print(f"\n⏳ Processando Batch de {len(registros_para_atualizar)} logs...")

            # 2. Prepara os dados para o UPDATE em massa
            sql_update = """
                UPDATE log_operacao 
                SET 
                    codigo_transacao = %s,
                    acao_movimentacao = IF(acao_movimentacao IS NULL, 'BACKFILL_TRANSACAO', CONCAT(acao_movimentacao, ' / BACKFILL_TRANSACAO'))
                WHERE 
                    id = %s
            """
            
            # Mapeia (codigo_transacao, log_id) para o executemany
            updates = [(transacao, log_id) 
                       for log_id, transacao in registros_para_atualizar if transacao is not None]
            
            # 3. Executa o UPDATE
            if updates:
                cursor.executemany(sql_update, updates)
                conn.commit()
                total_atualizados += cursor.rowcount
                print(f"   -> Batch concluído! {cursor.rowcount} logs atualizados. Total: {total_atualizados}")
            else:
                 # Caso a lista de updates esteja vazia por algum motivo bizarro
                 print("   ⚠️ Nenhum dado válido para UPDATE neste batch.")

        except Error as e:
            print(f"\n❌ ERRO CRÍTICO no Backfill (Batch Size: {BATCH_SIZE}): {e}")
            erros_ocorridos += 1
            if conn and conn.is_connected():
                conn.rollback()
            # Espera um pouco antes de tentar o próximo bloco, para não sobrecarregar o DB
            time.sleep(5) 
        
        except Exception as e:
            print(f"\n❌ ERRO INESPERADO: {e}")
            erros_ocorridos += 1
            break
            
        finally:
            if conn and conn.is_connected():
                cursor.close()
                conn.close()

    print("\n=============================================")
    print(f"🏁 FIM DA EXECUÇÃO | Total Corrigidos: {total_atualizados} | Erros: {erros_ocorridos}")
    print("=============================================")
    return total_atualizados


if __name__ == "__main__":
    run_backfill_transacao_sync()
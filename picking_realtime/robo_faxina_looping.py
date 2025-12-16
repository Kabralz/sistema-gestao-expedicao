import requests
import time
from datetime import datetime, timedelta
from mysql.connector import connect, Error

# ==============================================================================
# 1. CONFIGURAÇÕES
# ==============================================================================
API_HOST = "http://192.168.0.63:8085"
PENDENTES_URL = f"{API_HOST}/api/pendentes"
ERP_DATA_BASE_URL = f"{API_HOST}/api/pedido_erp_data"
UPDATE_LOG_URL = f"{API_HOST}/api/update_log_data"

DB_CONFIG = {
    "host": "127.0.0.1", 
    "port": 3307,        
    "user": "root",
    "database": "picking"      
}

DIAS_TOLERANCIA = 5 
TAMANHO_MAXIMO_PEDIDO = 7  # 🔒 TRAVA DE DIGITAÇÃO: Máximo de 7 dígitos
INTERVALO_MINUTOS = 30     # ⏱️ Tempo de espera entre as faxinas

# ==============================================================================
# 2. FUNÇÕES AUXILIARES
# ==============================================================================

def tentar_ler_data(pedido_json):
    """ Lê data do JSON (suporta formatos BR e ISO) """
    data_str = pedido_json.get('timestamp_registro')
    if not data_str:
        d = pedido_json.get('data_evento')
        h = pedido_json.get('hora_evento')
        if d and h: data_str = f"{d} {h}"
    if not data_str: data_str = pedido_json.get('data_evento')
    if not data_str: return None, "S/ Data"
    
    formatos = ["%Y-%m-%d %H:%M:%S", "%Y-%m-%dT%H:%M:%S", "%Y-%m-%d", "%d/%m/%Y %H:%M:%S", "%d/%m/%Y"]
    for fmt in formatos:
        try: return datetime.strptime(str(data_str), fmt), "OK"
        except ValueError: continue
    return None, "Fmt Inválido"

def registrar_auditoria(cursor, usuario_raw, acao, detalhes):
    if not usuario_raw: usuario_raw = "SISTEMA"
    usuario_limpo = usuario_raw.split('(')[0].strip() if '(' in usuario_raw else usuario_raw
    sql = "INSERT INTO log_acesso_auditoria (usuario, acao, detalhes, timestamp) VALUES (%s, %s, %s, NOW())"
    try:
        cursor.execute(sql, (usuario_limpo, acao, detalhes))
        print(f"   📝 Log Gravado: {detalhes}")
    except Error: pass

def soft_delete_pedido(cursor, log_id, motivo="ERRO_ERP_NAO_ENCONTRADO"):
    sql = "UPDATE log_operacao SET acao_movimentacao = %s WHERE id = %s"
    try:
        cursor.execute(sql, (motivo, log_id))
        print(f"   🗑️  ARQUIVADO (Motivo: {motivo})")
    except Error as e: print(f"   ❌ Erro SQL: {e}")

def backfill_transacao(cursor, pedido_id, log_id):
    sql = "SELECT codigo_transacao FROM vendas_erp_detalhe WHERE numero_pedido = %s LIMIT 1"
    cursor.execute(sql, (pedido_id,))
    res = cursor.fetchone()
    if res and res[0]:
        cursor.execute("UPDATE log_operacao SET codigo_transacao = %s WHERE id = %s", (res[0], log_id))
        print(f"   💳 Transação vinculada.")

def get_erp_data(pedido_id):
    try:
        r = requests.get(f"{ERP_DATA_BASE_URL}/{pedido_id}", timeout=5)
        return r.json() if r.status_code == 200 else None
    except: return "ERRO"

def update_api_data(log_id, itens, volumes):
    try:
        requests.post(UPDATE_LOG_URL, json={"log_id": log_id, "itens": itens, "sku_volumes": volumes}, timeout=5)
        return True
    except: return False

# ==============================================================================
# 3. MOTOR PRINCIPAL (GERENTE GERAL)
# ==============================================================================

def run_gerente_geral():
    print(f"\n👔 GERENTE V7 (Anti-Gigantes) - Max Dígitos: {TAMANHO_MAXIMO_PEDIDO}")
    print(f"🕒 Hora da execução: {datetime.now().strftime('%H:%M:%S')}")
    
    try:
        resp = requests.get(PENDENTES_URL, timeout=10)
        pendentes = resp.json().get('pendentes', [])
    except Exception as e: 
        print(f"⚠️ Erro ao conectar na API: {e}")
        return

    if not pendentes:
        print("✅ Tudo limpo!")
        return

    conn = connect(**DB_CONFIG)
    cursor = conn.cursor()
    agora = datetime.now()

    for p in pendentes:
        log_id = p.get('log_id')
        pedido_id = str(p.get('pedido_id')).strip()
        usuario = p.get('func_funcao', 'SISTEMA')
        acao_atual = p.get('acao_movimentacao')
        
        # Vacina Anti-Loop: Se já foi tratado como erro, ignora
        if acao_atual and 'ERRO' in acao_atual: continue

        print(f"\n👉 Pedido {pedido_id} (Log {log_id})")

        # --- 🚨 NOVA TRAVA: VALIDAÇÃO DE TAMANHO ---
        if len(pedido_id) > TAMANHO_MAXIMO_PEDIDO:
            print(f"   ⛔ ERRO: Número GIGANTE ({len(pedido_id)} dígitos). Descartando.")
            registrar_auditoria(cursor, usuario, "ERRO_DIGITACAO", f"Pedido {pedido_id} inválido (Muito Longo).")
            # Marca com um erro específico pra diferenciar
            soft_delete_pedido(cursor, log_id, motivo="ERRO_DIGITACAO_NUMERO_INVALIDO")
            conn.commit()
            continue 
        # ---------------------------------------------

        # 1. Data
        data_log, _ = tentar_ler_data(p)
        eh_recente = True
        idade_dias = 0
        if data_log:
            idade = agora - data_log
            idade_dias = idade.days
            if idade_dias >= DIAS_TOLERANCIA:
                eh_recente = False
                print(f"   👴 Antigo ({idade_dias} dias).")
            else:
                print(f"   👶 Recente ({idade_dias} dias).")

        # 2. ERP
        erp = get_erp_data(pedido_id)

        if erp == "ERRO": continue

        if erp is None: # 404
            if eh_recente:
                print("   ✋ Protegido pela janela de tempo.")
            else:
                print("   🚫 LIXO CONFIRMADO.")
                detalhe_log = f"Pedido {pedido_id} (Log {log_id}) inexistente há {idade_dias} dias."
                registrar_auditoria(cursor, usuario, "LIMPEZA_AUTO", detalhe_log)
                soft_delete_pedido(cursor, log_id)
                conn.commit()
        else:
            itens = int(erp.get('sku', 0))
            vols = int(erp.get('volumes', 0))
            if itens > 0 or vols > 0:
                update_api_data(log_id, itens, vols)
                backfill_transacao(cursor, pedido_id, log_id)
                conn.commit()
                print("   ✅ Atualizado.")
            else:
                print("   ⚠️ ERP zerado.")

    cursor.close()
    conn.close()
    print("\n🏁 Fim do ciclo.")

# ==============================================================================
# 4. LOOP DE EXECUÇÃO CONTÍNUA
# ==============================================================================

if __name__ == "__main__":
    print("🚀 ROBO FAXINA SUPREMO - MODO LOOP ATIVADO")
    print(f"⏱️  Intervalo configurado: {INTERVALO_MINUTOS} minutos")
    print("--------------------------------------------------")
    
    try:
        while True:
            # Roda a função principal
            run_gerente_geral()
            
            # Calcula quando será a próxima execução
            agora = datetime.now()
            proxima = agora + timedelta(minutes=INTERVALO_MINUTOS)
            
            print(f"\n💤 Dormindo... Próxima varredura às: {proxima.strftime('%H:%M:%S')}")
            print("="*60)
            
            # Pausa a execução (segundos)
            time.sleep(INTERVALO_MINUTOS * 60)
            
    except KeyboardInterrupt:
        # Permite parar o script com Ctrl+C sem dar erro feio
        print("\n\n🛑 Robô interrompido pelo usuário. Até mais!")
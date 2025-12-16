import requests
import time
import json
from typing import Dict, Any, Optional

# ===================================================
# 1. CONFIGURAÇÃO (Configure apenas o host da sua API FastAPI)
# ===================================================
# Endereço da API FastAPI (Onde o seu main63.py está rodando)
API_HOST = "http://192.168.0.63:8085"

PENDENTES_URL = f"{API_HOST}/api/pendentes"
ERP_DATA_BASE_URL = f"{API_HOST}/api/pedido_erp_data"
UPDATE_LOG_URL = f"{API_HOST}/api/update_log_data"

# ===================================================
# 2. FUNÇÕES DE AUTOMAÇÃO E SEGURANÇA
# ===================================================

def fetch_pending_orders() -> list:
    """Busca a lista de pedidos pendentes (itens=0) da API."""
    print("1. Buscando lista de pedidos pendentes...")
    try:
        response = requests.get(PENDENTES_URL, timeout=10)
        response.raise_for_status()
        data = response.json()
        return data.get('pendentes', [])
    except requests.exceptions.RequestException as e:
        print(f"❌ Erro Crítico ao buscar pendentes da API: {e}")
        return []

def get_erp_data(pedido_id: str) -> Optional[Dict[str, int]]:
    """Busca os SKUs e Volumes reais do pedido no ERP via API."""
    try:
        response = requests.get(f"{ERP_DATA_BASE_URL}/{pedido_id}", timeout=10)
        
        # Se a API retornar 404 (Pedido não achado ou fora de status), tratamos como NULL e ignoramos
        if response.status_code == 404:
            print(f"   ⚠️ Pedido {pedido_id}: Não encontrado no ERP (404). Ignorando.")
            return None
            
        response.raise_for_status() # Lança erro para 4xx/5xx (exceto 404)
        return response.json()
        
    except requests.exceptions.RequestException as e:
        print(f"   ❌ Erro de conexão ao buscar dados ERP para {pedido_id}: {e}")
        return None

def update_log_data(log_id: int, itens: int, sku_volumes: int) -> Optional[Dict[str, Any]]:
    """Envia a correção de SKUs e Volumes para o log específico."""
    payload = {
        "log_id": log_id,
        "itens": itens,
        "sku_volumes": sku_volumes
    }
    try:
        response = requests.post(UPDATE_LOG_URL, json=payload, timeout=10)
        response.raise_for_status()
        return response.json()
    except requests.exceptions.RequestException as e:
        print(f"   ❌ Erro ao enviar correção para Log ID {log_id}: {e}")
        return None

# ===================================================
# 3. FLUXO PRINCIPAL (CORREÇÃO EM LOTE)
# ===================================================
def run_fix_pendentes():
    pendentes = fetch_pending_orders()
    
    if not pendentes:
        print("✅ Nenhuma pendência encontrada. Robô concluído.")
        return

    print(f"2. Encontrados {len(pendentes)} pedidos pendentes de dados para verificação.")
    
    corrigidos_count = 0
    ignorados_count = 0
    erros_count = 0

    for i, p in enumerate(pendentes):
        log_id = p.get('log_id')
        pedido_id = p.get('pedido_id')
        
        # Validação básica para evitar quebra
        if not log_id or not pedido_id:
             print(f"   ⚠️ Linha inválida pulada: Log ID {log_id}, Pedido {pedido_id}")
             continue
        
        # Converte para string se não for (segurança)
        pedido_id_str = str(pedido_id)

        print(f"\n3. Processando {i+1}/{len(pendentes)}: Pedido {pedido_id_str} (Log ID: {log_id})")
        
        # 3.1 Busca dados reais do ERP
        erp_data = get_erp_data(pedido_id_str)

        if erp_data is None:
            ignorados_count += 1
            continue
            
        novos_itens = int(erp_data.get('sku', 0))
        novos_volumes = int(erp_data.get('volumes', 0))

        # 3.2 Checa se há algo para atualizar (só atualiza se o valor for positivo)
        if novos_itens > 0 or novos_volumes > 0:
            
            # 3.3 Envia a correção via API
            result = update_log_data(log_id, novos_itens, novos_volumes)
            
            if result and result.get('status') == 'success':
                print(f"   🎉 CORRIGIDO: Log ID {log_id} atualizado com {novos_itens} SKUs / {novos_volumes} Vol.")
                corrigidos_count += 1
            else:
                print(f"   ❌ Falha na API ao atualizar. Log ID: {log_id}")
                erros_count += 1
        else:
            print(f"   ⚠️ Pedido {pedido_id_str} tem 0 SKUs/Vol no ERP. Mantido como pendente.")
            ignorados_count += 1

    print("\n=============================================")
    print(f"🏁 FIM DA EXECUÇÃO | Corrigidos: {corrigidos_count} | Ignorados/Mantidos: {ignorados_count} | Erros de API: {erros_count}")
    print("=============================================")

if __name__ == "__main__":
    run_fix_pendentes()
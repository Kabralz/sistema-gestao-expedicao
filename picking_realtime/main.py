import uvicorn
import asyncio
from fastapi import FastAPI, WebSocket, HTTPException, BackgroundTasks 
from mysql.connector import connect, Error
import decimal 
import json
from fastapi.middleware.cors import CORSMiddleware 
from starlette.concurrency import run_in_threadpool 
from pydantic import BaseModel 
from typing import List, Dict, Any, Optional 

# ===================================================
# 1. CONFIGURAÇÃO DE CREDENCIAIS E HOSTS
# ===================================================
DB_CONFIG = {
    "host": "127.0.0.1", # Conexão local no Servidor 0.63
    "port": 3307,        
    "user": "root",
    "database": "picking"      
}

# Configurações do Servidor FastAPI
HOST_IP = "192.168.0.63" 
WS_PORT = 8085 # PORTA DE TESTE/DESENVOLVIMENTO (8085) 

app = FastAPI()

# Middleware CORS (Permite a comunicação da porta 8080/80 com 8085)
origins = [
    f"http://{HOST_IP}:8080", "http://127.0.0.1:8080",
    f"http://{HOST_IP}", "http://localhost",
]

app.add_middleware(
    CORSMiddleware,
    allow_origins=origins, allow_credentials=True,
    allow_methods=["*"], allow_headers=["*"],
)

active_connections: List[WebSocket] = [] 

# Variável GLOBAL para rastreamento de mudanças no DB (Polling)
last_db_state: int = 0 
polling_interval_seconds = 5

# Modelos Pydantic
class Funcionario(BaseModel):
    nome: str
    funcao: str
    periodo: str

# 🚨 NOVA LÓGICA: Modelo para o UPDATE de funcionário (inclui ID e status ATIVO) 🚨
class FuncionarioUpdate(BaseModel):
    id: int
    nome: str
    funcao_padrao: str
    periodo_padrao: str
    ativo: int

class Movimentacao(BaseModel):
    log_id: int               
    pedido_id: int            
    funcionario_antigo: str   
    funcionario_novo: str     
    funcao_origem: str        
    periodo_origem: str       

class CheckPedido(BaseModel):
    pedido_id: int 
    funcao: str    

class ExclusaoBase(BaseModel):
    pedido_id: int
    usuario_logado: str
    
class ExclusaoLog(ExclusaoBase):
    log_id: int    

class Notificacao(BaseModel):
    data_alvo: Optional[str] = None 
    periodo_alvo: Optional[str] = None 

# Para atualizar SKUs/Volumes de um log (Rastreamento)
class LogUpdateData(BaseModel):
    log_id: int
    itens: int
    sku_volumes: int

# ===================================================
# 2. FUNÇÕES AUXILIARES DE LIMPEZA DE DADOS E DB
# ===================================================

def clean_decimal_data(data: Any) -> Any:
    """Recursivamente converte objetos Decimal em floats para serialização JSON."""
    if isinstance(data, decimal.Decimal):
        return float(data)
    if isinstance(data, dict):
        return {k: clean_decimal_data(v) for k, v in data.items()}
    if isinstance(data, list):
        return [clean_decimal_data(item) for item in data]
    return data

def fetch_metas_db() -> Dict[str, int]:
    """Busca todas as metas na tabela 'metas' do MariaDB."""
    metas_dict = {}
    try:
        conn = connect(**DB_CONFIG)
        cursor = conn.cursor(dictionary=True)
        cursor.execute("SELECT chave, valor FROM metas")
        
        for row in cursor.fetchall():
            metas_dict[row['chave'].lower()] = int(row['valor'])
            
        cursor.close()
        conn.close()
        return metas_dict
    except Error as e:
        print(f"❌ ERRO ao buscar metas no DB: {e}")
        return {
            'sep_pedidos': 25, 'sep_volumes': 800, 'sep_sku': 1000, 
            'conf_pedidos': 40, 'conf_volumes': 1200, 'conf_sku': 1400  
        }

# FUNÇÃO: Buscando Status do ERP (CORRIGIDA: Default = Hoje)
def fetch_pedidos_erp_sync(data_alvo: Optional[str] = None, periodo_alvo: Optional[str] = None) -> Dict[str, Any]:
    """
    Busca o saldo de pedidos por status.
    CORREÇÃO: Se data_alvo for None (modo tempo real), filtra Faturados apenas de HOJE (CURDATE()).
    """
    try:
        conn = connect(**DB_CONFIG)
        cursor = conn.cursor(dictionary=True)
        
        # 1. Filtro para pedidos EM ABERTO (Janela deslizante de 45 dias para não pegar lixo antigo)
        DIAS_LIMITE_ABERTO = 45 
        data_aberto_filter = f" AND data_implantacao >= DATE_SUB(CURDATE(), INTERVAL {DIAS_LIMITE_ABERTO} DAY)"
        
        # 🚨 CORREÇÃO DA LÓGICA DE FATURADOS 🚨
        # Cenário 1: Usuário filtrou uma data -> Usa a data.
        # Cenário 2: Modo Real-Time (None) -> Usa CURDATE() (Hoje).
        if data_alvo:
            faturamento_filter = f"AND data_faturamento = '{data_alvo}'"
        else:
            faturamento_filter = "AND data_faturamento = CURDATE()"
        
        # 3. Montagem da Query
        sql = f"""
            SELECT 
                situacao_pedido, 
                codigo_transacao,
                COUNT(DISTINCT numero_pedido) AS total_pedidos  
            FROM vendas_erp_detalhe
            WHERE 
                -- GRUPO A: Pedidos em Aberto (DIGITADO/PROCESSADO) - Usa janela de 45 dias
                (
                    situacao_pedido IN ('DIGITADO', 'PROCESSADO')
                    {data_aberto_filter}  
                )
                
                -- GRUPO B: Pedidos Faturados (Filtro Rígido: Data Selecionada OU Hoje)
                OR (
                    situacao_pedido = 'FATURADO' 
                    {faturamento_filter}
                )
            
            GROUP BY situacao_pedido, codigo_transacao;
        """
        
        cursor.execute(sql)
        results = cursor.fetchall()
        
        data_erp = {
            "total_digitado_qtd": 0, 
            "total_processado_qtd": 0, 
            "total_faturado_venda_qtd": 0,      
            "total_faturado_bonificacao_qtd": 0, 
            "total_faturado_qtd": 0,            
            "total_em_aberto_qtd": 0, 
        }

        for row in results:
            situacao = row['situacao_pedido'].upper()
            qtd = row['total_pedidos']
            codigo_transacao = int(row.get('codigo_transacao')) if row.get('codigo_transacao') else None 

            
            if situacao == 'DIGITADO':
                data_erp['total_digitado_qtd'] += qtd
                
            elif situacao == 'PROCESSADO':
                data_erp['total_processado_qtd'] += qtd

            elif situacao == 'FATURADO':
                data_erp['total_faturado_qtd'] += qtd 
                
                if codigo_transacao in [1, 765]:
                    data_erp['total_faturado_venda_qtd'] += qtd
                elif codigo_transacao in [6, 766]:
                    data_erp['total_faturado_bonificacao_qtd'] += qtd
            
            if situacao in ['DIGITADO', 'PROCESSADO']:
                data_erp['total_em_aberto_qtd'] += qtd
        
        cursor.close()
        conn.close()
        return clean_decimal_data(data_erp)

    except Error as e:
        print(f"❌ ERRO ao buscar pedidos ERP na tabela ETL: {e}")
        return {}

def get_current_db_state() -> int:
    """Função SÍNCRONA: Retorna a contagem total de linhas do log_operacao (usado no polling)."""
    try:
        conn = connect(**DB_CONFIG)
        cursor = conn.cursor()
        cursor.execute("SELECT COUNT(*) FROM log_operacao")
        count = cursor.fetchone()[0]
        cursor.close()
        conn.close()
        return count
    except Error as e:
        print(f"❌ ERRO ao contar linhas do DB para Polling: {e}")
        return -1 

# FUNÇÃO DE MANUTENÇÃO: Backfill para preencher dados antigos (chamar separadamente)
def run_backfill_transacao_sync() -> int:
    """
    Percorre o log_operacao e preenche o codigo_transacao que está NULL 
    usando a tabela de vendas ETL (vendas_erp_detalhe).
    """
    conn = None
    try:
        conn = connect(**DB_CONFIG)
        cursor = conn.cursor()
        
        # 1. Encontra logs que PRECISAM de atualização
        sql_select = """
            SELECT 
                L.id, 
                L.pedido_id, 
                V.codigo_transacao
            FROM 
                log_operacao AS L
            INNER JOIN 
                vendas_erp_detalhe AS V ON L.pedido_id = V.numero_pedido
            WHERE 
                (L.codigo_transacao IS NULL OR L.codigo_transacao = '') 
            LIMIT 1000 
        """
        cursor.execute(sql_select)
        registros_para_atualizar = cursor.fetchall()
        
        if not registros_para_atualizar:
            print("🧹 Backfill concluído: Nenhum registro para atualizar.")
            return 0
            
        print(f"⏳ Backfill: Encontrados {len(registros_para_atualizar)} logs para preencher...")

        # 2. Executa o UPDATE
        sql_update = """
            UPDATE log_operacao 
            SET 
                codigo_transacao = %s,
                acao_movimentacao = IF(acao_movimentacao IS NULL, 'BACKFILL_TRANSACAO', CONCAT(acao_movimentacao, ' / BACKFILL_TRANSACAO'))
            WHERE 
                id = %s
        """
        updates = [(transacao, log_id) 
                   for log_id, _, transacao in registros_para_atualizar if transacao is not None]
        
        cursor.executemany(sql_update, updates)
        conn.commit()
        
        print(f"✅ Backfill: {cursor.rowcount} logs de transação atualizados.")
        return cursor.rowcount

    except Error as e:
        print(f"❌ ERRO no Backfill: {e}")
        if conn and conn.is_connected():
            conn.rollback()
        return -1
    finally:
        if conn and conn.is_connected():
            cursor.close()
            conn.close()

# 🚨 NOVA LÓGICA: Função Síncrona de UPDATE/EDIÇÃO de funcionário 🚨
def update_funcionario_db_sync(data: FuncionarioUpdate) -> bool:
    """Atualiza um funcionário existente na tabela db_funcionarios."""
    conn = None
    try:
        conn = connect(**DB_CONFIG)
        cursor = conn.cursor()
        
        # 1. Query de UPDATE
        sql = """
            UPDATE db_funcionarios 
            SET 
                nome = %s, 
                funcao_padrao = %s, 
                periodo_padrao = %s, 
                ativo = %s
            WHERE 
                id = %s
        """
        
        # Executa o UPDATE
        cursor.execute(sql, (
            data.nome.upper(), 
            data.funcao_padrao.upper(), 
            data.periodo_padrao.upper(),
            data.ativo,
            data.id
        ))
        
        conn.commit()
        return cursor.rowcount > 0

    except Error as e:
        print(f"Erro ao atualizar funcionário no DB: {e}")
        if conn and conn.is_connected():
            conn.rollback()
        return False
        
    finally:
        if conn and conn.is_connected():
            cursor.close()
            conn.close()


# ===================================================
# 3. FUNÇÃO MASTER SÍNCRONA: BUSCA TODOS OS DADOS DO DB (Com Regra de Bonificação)
# ===================================================
def fetch_dashboard_data_sync(data_alvo: Optional[str] = None, periodo_alvo: Optional[str] = None) -> Optional[Dict[str, Any]]: 
    """Busca todos os dados agregados para o Dashboard (SÍNCRONA) aplicando a regra de Bonificação."""
    data = {}
    conn = None
    
    # 1. Monta a base da cláusula WHERE de data
    data_value = f"'{data_alvo}'" if data_alvo else "CURDATE()"

    # Condição WHERE base para os Rankings
    rank_where_condition = f"L.data_evento = {data_value}"
    periodo_clean = None
    if periodo_alvo:
        periodo_clean = str(periodo_alvo).upper().strip()
        if periodo_clean in ['T1', 'T2', 'T3']:
            rank_where_condition += f" AND L.periodo = '{periodo_clean}'"
        
    # Condição para o Rodapé (sem alias)
    rodape_where_condition = f"data_evento = {data_value}"
    if periodo_clean and periodo_clean in ['T1', 'T2', 'T3']:
        rodape_where_condition += f" AND periodo = '{periodo_clean}'"
    
    try:
        conn = connect(**DB_CONFIG)
        cursor = conn.cursor(dictionary=True)
        
        # 1. Ranking de Separação (USA O CAMPO DA FATO - L.codigo_transacao)
        cursor.execute(f"""
            SELECT 
                L.funcionario, 
                
                -- Pedidos: Conta IDs UNICOS do log que SÃO de venda (1 ou 765). Ignora bonificação.
                COUNT(DISTINCT CASE 
                    WHEN L.codigo_transacao IN ('1', '765') THEN L.pedido_id 
                    ELSE NULL 
                END) AS total_pedidos,
                
                -- Volumes: SOMA DIRETO do log_operacao (L). (Conta o trabalho físico total)
                SUM(L.sku_volumes) AS total_volumes,
                
                -- SKUs: SOMA DIRETO do log_operacao (L). (Conta o trabalho físico total)
                SUM(L.itens) AS total_sku

            FROM 
                log_operacao AS L
                
            WHERE 
                L.funcao = 'SEP' AND {rank_where_condition} 
            
            GROUP BY 
                L.funcionario 
            ORDER BY 
                total_volumes DESC
        """)
        data['ranking_separacao'] = cursor.fetchall()
        
        # 2. Ranking de Conferência (USA O CAMPO DA FATO - L.codigo_transacao)
        cursor.execute(f"""
            SELECT 
                L.funcionario, 
                
                -- Pedidos: Conta IDs UNICOS do log que SÃO de venda (1 ou 765)
                COUNT(DISTINCT CASE 
                    WHEN L.codigo_transacao IN ('1', '765') THEN L.pedido_id 
                    ELSE NULL 
                END) AS total_pedidos,
                
                -- Volumes: SOMA DIRETO do log_operacao (L).
                SUM(L.sku_volumes) AS total_volumes,
                
                -- SKUs: SOMA DIRETO do log_operacao (L).
                SUM(L.itens) AS total_sku

            FROM 
                log_operacao AS L
            
            WHERE 
                L.funcao = 'CONF' AND {rank_where_condition} 
            
            GROUP BY 
                L.funcionario 
            ORDER BY 
                total_volumes DESC
        """)
        data['ranking_conferencia'] = cursor.fetchall()
        
        # 3. Totais do Rodapé - Usando o campo da FATO
        cursor.execute(f"""
            SELECT 
                   -- Pedidos SEP: CONTA TUDO, independente do codigo_transacao
                   SUM(CASE WHEN funcao = 'SEP' THEN 1 ELSE 0 END) AS total_pedidos_separados, 
                   
                   -- Pedidos CONF: CONTA TUDO, independente do codigo_transacao
                   SUM(CASE WHEN funcao = 'CONF' THEN 1 ELSE 0 END) AS total_pedidos_conferidos, 
                   
                   -- SKUs e VOLUMES continuam a somar TUDO (trabalho físico)
                   SUM(CASE WHEN funcao = 'SEP' THEN itens ELSE 0 END) AS total_sku_separado, 
                   SUM(CASE WHEN funcao = 'CONF' THEN itens ELSE 0 END) AS total_sku_conferido, 
                   SUM(CASE WHEN funcao = 'SEP' THEN sku_volumes ELSE 0 END) AS total_volumes_separado, 
                   SUM(CASE WHEN funcao = 'CONF' THEN sku_volumes ELSE 0 END) AS total_volumes_conferido 
            
            FROM log_operacao 
            WHERE {rodape_where_condition}
        """)
        data['totais_resumo'] = cursor.fetchone()

        # 4. Contagem de Funcionários Ativos
        cursor.execute(f"""
            SELECT 
                COUNT(DISTINCT CASE WHEN funcao = 'SEP' THEN funcionario END) AS sep_ativos,
                COUNT(DISTINCT CASE WHEN funcao = 'CONF' THEN funcionario END) AS conf_ativos
            FROM log_operacao 
            WHERE {rodape_where_condition}
        """)
        contagem_ativos = cursor.fetchone() 
        data['contagem_ativos'] = contagem_ativos
                
        cursor.close()
        
        data['metas'] = fetch_metas_db()
        data['saldo_status'] = None 
        
        return clean_decimal_data(data)

    except Error as e:
        print(f"🚨 ERRO GERAL NO FETCH (MariaDB): {e}")
        return None
    except Exception as e:
        print(f"🚨 ERRO INESPERADO NA EXECUÇÃO: {e}")
        return None
    finally:
        if conn and conn.is_connected():
            conn.close()

# ===================================================
# 4. FUNÇÃO AUXILIAR DE BROADCAST (Refatorada)
# ===================================================

async def broadcast_update(data_alvo: Optional[str] = None, periodo_alvo: Optional[str] = None): 
    """Busca dados e distribui via WebSocket para todos os clientes ativos."""
    new_data = await run_in_threadpool(fetch_dashboard_data_sync, data_alvo, periodo_alvo)
    erp_data = await run_in_threadpool(fetch_pedidos_erp_sync, data_alvo, periodo_alvo)
    
    if new_data:
        if erp_data:
            new_data.update(erp_data) 
            
        new_data['data_selecionada'] = data_alvo
        new_data['periodo_selecionado'] = periodo_alvo 
        for connection in active_connections:
            try:
                await connection.send_json(new_data)
            except Exception as e:
                print(f"Falha ao enviar atualização para um cliente (conexão perdida): {e}")
                pass 
    else:
        print("Erro: Não foi possível buscar novos dados do DB para broadcast.")

async def unicast_update(websocket: WebSocket, data_alvo: Optional[str] = None, periodo_alvo: Optional[str] = None):
    """Busca dados e envia *apenas* para a conexão WebSocket especificada."""
    
    new_data = await run_in_threadpool(fetch_dashboard_data_sync, data_alvo, periodo_alvo)
    erp_data = await run_in_threadpool(fetch_pedidos_erp_sync, data_alvo, periodo_alvo) 
    
    if new_data:
        if erp_data:
            new_data.update(erp_data)
            
        new_data['data_selecionada'] = data_alvo
        new_data['periodo_selecionado'] = periodo_alvo
        
        try:
            await websocket.send_json(new_data)
        except Exception as e:
            print(f"Falha ao enviar unicast para cliente (conexão perdida): {e}")


# 🚨 FUNÇÃO AJUSTADA: Retorna ID e ATIVO para o modal de gerenciamento 🚨
def fetch_funcionarios_db_sync() -> List[Dict[str, Any]]:
    """Busca a lista de funcionários (incluindo ID e status ATIVO) para o modal de gerenciamento e datalist."""
    try:
        conn = connect(**DB_CONFIG)
        cursor = conn.cursor(dictionary=True)
        # Buscar ID e ATIVO
        cursor.execute("SELECT id, nome, funcao_padrao, periodo_padrao, ativo FROM db_funcionarios ORDER BY nome ASC") 
        data = cursor.fetchall()
        cursor.close()
        conn.close()
        return clean_decimal_data(data)
    except Error:
        print("❌ ERRO: Falha ao buscar a tabela db_funcionarios. Verifique o nome/status da tabela.")
        return []

def add_funcionario_db_sync(data: Funcionario) -> bool:
    """Salva um novo funcionário na tabela db_funcionarios."""
    conn = None
    try:
        conn = connect(**DB_CONFIG)
        cursor = conn.cursor()
        
        cursor.execute("SELECT 1 FROM db_funcionarios WHERE nome = %s", (data.nome.upper(),))
        if cursor.fetchone():
            raise ValueError("Funcionário já cadastrado.")
            
        # Garante que o novo funcionário começa como ATIVO=1
        sql = """
            INSERT INTO db_funcionarios (nome, funcao_padrao, periodo_padrao, ativo)
            VALUES (%s, %s, %s, 1)
        """
        cursor.execute(sql, (
            data.nome.upper(), 
            data.funcao.upper(), 
            data.periodo.upper()
        ))
        
        conn.commit()
        return True

    except Error as e:
        print(f"Erro ao adicionar funcionário no DB: {e}")
        if conn and conn.is_connected():
            conn.rollback()
        return False
        
    except ValueError as e:
        raise HTTPException(status_code=409, detail=str(e)) # 409 Conflict

    finally:
        if conn and conn.is_connected():
            cursor.close()
            conn.close()


# ===================================================
# 5. ROTAS E WEBSOCKETS (Funcionalidades de rede)
# ===================================================

@app.websocket("/ws")
async def websocket_endpoint(websocket: WebSocket):
    await websocket.accept()
    active_connections.append(websocket)
    
    await unicast_update(websocket, None, None) 

    try:
        while True:
            message = await websocket.receive_text()
            
            try:
                msg_json = json.loads(message)
                action = msg_json.get('action')
                data_alvo = msg_json.get('data_alvo') 
                periodo_alvo = msg_json.get('periodo_alvo') 
            except json.JSONDecodeError:
                action = message
                data_alvo = None
                periodo_alvo = None 

            if action == 'request_update' or action == 'request_initial_data':
                 await unicast_update(websocket, data_alvo, periodo_alvo) 

    except Exception:
        pass
    finally:
        if websocket in active_connections:
            active_connections.remove(websocket)


@app.post("/notify_update")
async def handle_notify_update(data_notificacao: Notificacao, background_tasks: BackgroundTasks):
    """
    Recebe a notificação de mudança e agenda o broadcast para rodar em 
    segundo plano, liberando a thread de resposta imediatamente.
    """
    print(f"📢 AVISO RECEBIDO: Notificação de atualização de dados (via PHP) para data: {data_notificacao.data_alvo or 'HOJE'} e turno: {data_notificacao.periodo_alvo or 'TODOS'}!") 
    
    background_tasks.add_task(
        broadcast_update, 
        data_notificacao.data_alvo, 
        data_notificacao.periodo_alvo
    )
    
    return {"status": "success", "message": "Broadcast agendado e resposta enviada com sucesso."}


@app.get("/api/funcionarios")
async def get_funcionarios_list():
    return {"funcionarios": await run_in_threadpool(fetch_funcionarios_db_sync)}

# ===================================================
# 6. ROTAS DE VALIDAÇÃO, PESQUISA E MOVIMENTAÇÃO
# ===================================================

# 🚨 NOVA ROTA: Edita um funcionário existente (PUT) 🚨
@app.put("/api/funcionario/{func_id}")
async def update_funcionario_route(func_id: int, data: FuncionarioUpdate):
    """Rota para atualizar um funcionário pelo ID."""
    # Garante que o ID da URL bate com o ID do corpo (segurança)
    if func_id != data.id:
        raise HTTPException(status_code=400, detail="ID no corpo da requisição não corresponde ao ID na URL.")
        
    try:
        success = await run_in_threadpool(update_funcionario_db_sync, data)
        if success:
            return {"status": "success", "message": f"Funcionário {data.nome} atualizado com sucesso!"}
        else:
            raise HTTPException(status_code=404, detail=f"Funcionário ID {func_id} não encontrado ou dados não alterados.")

    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Erro desconhecido: {e}")


@app.post("/api/add_funcionario") 
async def add_funcionario_route(data: Funcionario):
    """Rota para cadastrar um novo funcionário."""
    try:
        success = await run_in_threadpool(add_funcionario_db_sync, data)
        if success:
            return {"status": "success", "message": f"Funcionário {data.nome} cadastrado com sucesso!"}
        else:
            raise HTTPException(status_code=500, detail="Erro interno ao salvar no banco de dados.")

    except HTTPException as e:
        raise e
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Erro desconhecido: {e}")

# Busca SKUs/Volumes/CODIGO_TRANSACAO (Para Autopreenchimento)
@app.get("/api/pedido_erp_data/{numero_pedido}")
async def get_pedido_erp_data(numero_pedido: str):
    """Busca SKU, Volume E Código de Transação do pedido na tabela ETL."""
    try:
        conn = await run_in_threadpool(connect, **DB_CONFIG)
        cursor = conn.cursor(dictionary=True)
        
        sql = """
            SELECT 
                sku, 
                volumes,
                codigo_transacao 
            FROM vendas_erp_detalhe
            WHERE numero_pedido = %s
            LIMIT 1
        """
        cursor.execute(sql, (numero_pedido,))
        result = cursor.fetchone() 
        
        cursor.close()
        conn.close()
        
        if result:
            return clean_decimal_data({
                "sku": result['sku'] or 0, 
                "volumes": result['volumes'] or 0,
                "codigo_transacao": result['codigo_transacao'] or "" 
            }) 
        else:
            raise HTTPException(status_code=404, detail="Pedido não encontrado ou não está no status de picking.")

    except Error as e:
        print(f"❌ ERRO ao buscar dados ETL do pedido: {e}")
        raise HTTPException(status_code=500, detail="Erro interno ao consultar o banco de dados.")

# Busca Pedidos Pendentes (itens=0)
@app.get("/api/pendentes")
async def get_pedidos_pendentes():
    """Busca pedidos pendentes, IGNORANDO os que já foram marcados como erro pelo robô."""
    try:
        conn = await run_in_threadpool(connect, **DB_CONFIG)
        cursor = conn.cursor(dictionary=True)
        
        # 🚨 ALTERAÇÃO AQUI: Adicionamos o filtro para excluir ERRO_ERP_NAO_ENCONTRADO
        sql = """
            SELECT 
                id AS log_id, 
                pedido_id, 
                funcionario, 
                funcao,
                timestamp_registro
            FROM log_operacao 
            WHERE 
                itens = 0 
                AND funcao IN ('SEP', 'CONF')
                AND (acao_movimentacao IS NULL OR acao_movimentacao != 'ERRO_ERP_NAO_ENCONTRADO')
            ORDER BY timestamp_registro ASC
            LIMIT 100 
        """
        cursor.execute(sql)
        result = cursor.fetchall()
        
        cursor.close()
        conn.close()
        
        return {"pendentes": clean_decimal_data(result)}

    except Error as e:
        print(f"❌ ERRO ao buscar pedidos pendentes: {e}")
        raise HTTPException(status_code=500, detail="Erro interno ao consultar o banco de dados.")

# Atualiza SKUs/Volumes de um Log (Pós-verificação)
@app.post("/api/update_log_data")
async def update_log_data(data: LogUpdateData):
    """Atualiza itens e volumes de um log específico (usado para correção ERP)."""
    conn = None
    try:
        conn = connect(**DB_CONFIG)
        cursor = conn.cursor()
        
        sql = """
            UPDATE log_operacao 
            SET 
                itens = %s,
                sku_volumes = %s,
                acao_movimentacao = 'DADOS ERP ATUALIZADOS'
            WHERE 
                id = %s                      
        """
        
        cursor.execute(sql, (
            data.itens, 
            data.sku_volumes,
            data.log_id 
        ))
        
        if cursor.rowcount == 0:
            raise HTTPException(status_code=404, detail=f"Erro: Registro de Log ID {data.log_id} não encontrado.")

        conn.commit()
        
        await broadcast_update() 

        return {"status": "success", "message": f"Log ID {data.log_id} atualizado com novos dados."}

    except Error as e:
        print(f"Erro ao atualizar log: {e}")
        raise HTTPException(status_code=500, detail="Erro interno ao tentar salvar a atualização.")

    finally:
        if conn and conn.is_connected():
            cursor.close()
            conn.close()


@app.post("/api/check_pedido_id")
async def check_pedido_id_existence(data: CheckPedido): 
    """Checa se um pedido_id já existe para a função específica (SEP/CONF)."""
    try:
        conn = await run_in_threadpool(connect, **DB_CONFIG)
        cursor = conn.cursor()
        
        sql = """
            SELECT EXISTS(
                SELECT 1 FROM log_operacao 
                WHERE pedido_id = %s AND funcao = %s 
                LIMIT 1
            )
        """
        cursor.execute(sql, (data.pedido_id, data.funcao)) 
        
        exists = cursor.fetchone()[0]
        
        cursor.close()
        conn.close()
        
        return {"pedido_id": data.pedido_id, "exists": bool(exists)}

    except Error as e:
        print(f"❌ ERRO ao checar pedido_id no DB: {e}")
        raise HTTPException(status_code=500, detail="Erro interno ao consultar o banco de dados.")

@app.get("/api/pedido/{pedido_id}")
async def get_pedido_details(pedido_id: int):
    """Busca TODOS os registros de um pedido e retorna o timestamp bruto."""
    try:
        conn = await run_in_threadpool(connect, **DB_CONFIG)
        cursor = conn.cursor(dictionary=True)
        
        sql = """
            SELECT
                id, 
                funcionario, funcao, periodo, 
                timestamp_registro,
                itens, sku_volumes, acao_movimentacao
            FROM log_operacao 
            WHERE pedido_id = %s 
            ORDER BY timestamp_registro ASC 
        """
        
        cursor.execute(sql, (pedido_id,))
        result = cursor.fetchall() 
        
        cursor.close()
        conn.close()
        
        if result:
            return clean_decimal_data({"historico": result}) 
        else:
            raise HTTPException(status_code=404, detail=f"Pedido ID {pedido_id} não encontrado.")

    except Error as e:
        print(f"❌ ERRO ao buscar detalhes do pedido {pedido_id}: {e}")
        raise HTTPException(status_code=500, detail="Erro interno ao consultar o banco de dados.")

@app.post("/api/movimentar_pedido")
async def movimentar_pedido(data: Movimentacao):
    """ATUALIZA o registro de log existente (UPDATE) para transferir o pedido."""
    conn = None
    try:
        conn = connect(**DB_CONFIG)
        cursor = conn.cursor()
        
        sql = """
            UPDATE log_operacao 
            SET 
                funcionario = %s,              
                data_evento = CURDATE(),       
                hora_evento = CURTIME(),       
                timestamp_registro = NOW(),    
                acao_movimentacao = %s
            WHERE 
                id = %s                        
        """
        
        acao_detalhe = f"TRANSFERIDO DE {data.funcionario_antigo} PARA {data.funcionario_novo} (LOG ID: {data.log_id})"
        
        cursor.execute(sql, (
            data.funcionario_novo.upper(), 
            acao_detalhe,
            data.log_id 
        ))
        
        if cursor.rowcount == 0:
            raise HTTPException(status_code=404, detail=f"Erro: Registro de Log ID {data.log_id} não encontrado para atualização.")

        conn.commit()
        
        await broadcast_update() 

        return {"status": "success", "message": f"Pedido ID {data.pedido_id} (${data.funcao_origem}) transferido para {data.funcionario_novo}."}

    except Error as e:
        print(f"Erro ao movimentar pedido: {e}")
        raise HTTPException(status_code=500, detail="Erro interno ao tentar salvar a movimentação.")

    finally:
        if conn and conn.is_connected():
            cursor.close()
            conn.close()

@app.post("/api/excluir_log_unico")
async def excluir_log_unico(data: ExclusaoLog):
    conn = None
    try:
        conn = connect(**DB_CONFIG)
        cursor = conn.cursor()

        sql_auditoria = "INSERT INTO log_acesso_auditoria (usuario, acao, detalhes) VALUES (%s, %s, %s)"
        detalhes_auditoria = f"EXCLUIU_LOG_UNICO | Log ID: {data.log_id} | Pedido ID: {data.pedido_id}"
        cursor.execute(sql_auditoria, (data.usuario_logado, 'EXCLUSAO_LOG', detalhes_auditoria))

        sql_exclusao = "DELETE FROM log_operacao WHERE id = %s AND pedido_id = %s"
        cursor.execute(sql_exclusao, (data.log_id, data.pedido_id))
        
        if cursor.rowcount == 0:
            conn.rollback()
            raise HTTPException(status_code=404, detail=f"Registro de Log ID {data.log_id} ou Pedido {data.pedido_id} não encontrado.")

        conn.commit()
        await broadcast_update() 

        return {"status": "success", "message": f"Registro de Log ID {data.log_id} do Pedido {data.pedido_id} excluído com sucesso!"}

    except Error as e:
        if conn and conn.is_connected():
            conn.rollback()
        print(f"Erro ao excluir log único: {e}")
        raise HTTPException(status_code=500, detail="Erro interno ao tentar excluir o log.")

    finally:
        if conn and conn.is_connected():
            cursor.close()
            conn.close()

@app.post("/api/excluir_pedido_fisico") 
async def excluir_pedido_fisico(data: ExclusaoBase):
    conn = None
    try:
        conn = connect(**DB_CONFIG)
        cursor = conn.cursor()

        sql_auditoria = "INSERT INTO log_acesso_auditoria (usuario, acao, detalhes) VALUES (%s, %s, %s)"
        detalhes_auditoria = f"EXCLUIU_PEDIDO_COMPLETO | Pedido ID: {data.pedido_id}"
        cursor.execute(sql_auditoria, (data.usuario_logado, 'EXCLUSAO_PEDIDO', detalhes_auditoria))

        sql_exclusao = "DELETE FROM log_operacao WHERE pedido_id = %s"
        cursor.execute(sql_exclusao, (data.pedido_id,))

        if cursor.rowcount == 0:
            conn.rollback()
            raise HTTPException(status_code=404, detail=f"Pedido ID {data.pedido_id} não encontrado para exclusão.")

        conn.commit()
        await broadcast_update() 

        return {"status": "success", "message": f"Pedido ID {data.pedido_id} e todo seu histórico excluídos permanentemente!"}

    except Error as e:
        if conn and conn.is_connected():
            conn.rollback()
        print(f"Erro ao excluir pedido físico: {e}")
        raise HTTPException(status_code=500, detail="Erro interno ao tentar excluir o pedido.")

    finally:
        if conn and conn.is_connected():
            cursor.close()
            conn.close()            

# ===================================================
# 7. EXECUÇÃO DO SERVIDOR 
# ===================================================
if __name__ == "__main__":
    print(f"🚀 Servidor Real-Time iniciado em http://{HOST_IP}:{WS_PORT}")
    uvicorn.run(app, host=HOST_IP, port=WS_PORT)
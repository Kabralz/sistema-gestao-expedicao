<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ===============================================
// 1. CONFIGURAÇÕES E PROTEÇÃO
// ===============================================

$DB_CONFIG = [
    'host' => '127.0.0.1', 'port' => 3307, 'user' => 'root', 
    'pass' => '', 'name' => 'picking'
]; 
// 🚨 MUDANÇA PARA A PORTA 8085 (AMBIENTE DE TESTE/DEV) 🚨
$PYTHON_NOTIFY_URL = 'http://192.168.0.63:8085/notify_update';
$PYTHON_API_URL = 'http://192.168.0.63:8085'; // Host do Robô (FastAPI)

$usuario_logado = $_SESSION['usuario'] ?? 'DESCONHECIDO';
$mensagem = '';
$sucesso = false;

function registrar_auditoria($db, $usuario, $acao, $detalhes = '') {
    $stmt = $db->prepare("INSERT INTO log_acesso_auditoria (usuario, acao, detalhes) VALUES (?, ?, ?)");
    $stmt->execute([$usuario, $acao, $detalhes]);
}

// LÓGICA DE PROTEÇÃO DE SESSÃO E PRIMEIRO ACESSO (Mantida)
if (!isset($_SESSION['logado']) || $_SESSION['logado'] !== true) {
    header('Location: login.php');
    exit();
}

try {
    $db_check = new PDO("mysql:host={$DB_CONFIG['host']};port={$DB_CONFIG['port']};dbname={$DB_CONFIG['name']};charset=utf8", $DB_CONFIG['user'], $DB_CONFIG['pass']);
    $db_check->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt_check = $db_check->prepare("SELECT primeiro_acesso FROM usuarios WHERE usuario = ? AND ativo = 1");
    $stmt_check->execute([$usuario_logado]);
    $user_check = $stmt_check->fetch(PDO::FETCH_ASSOC);

    if ($user_check && $user_check['primeiro_acesso']) {
        header('Location: troca_senha.php');
        exit();
    }
} catch (PDOException $e) {
    session_destroy();
    header('Location: login.php');
    exit();
}


// ===================================================
// 2. PROCESSAMENTO DO FORMULÁRIO 
// ===================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registro'])) {
    
    $registros = $_POST['registro']; 
    $registros_inseridos = 0;
    $pedidos_inseridos_ids = []; // Variável já existe, vamos usá-la
    
    try {
        $db = new PDO("mysql:host={$DB_CONFIG['host']};port={$DB_CONFIG['port']};dbname={$DB_CONFIG['name']};charset=utf8", $DB_CONFIG['user'], $DB_CONFIG['pass']);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->beginTransaction(); // INÍCIO DA TRANSAÇÃO

        $total_linhas = count($registros);
        registrar_auditoria($db, $usuario_logado, 'SALVANDO_REGISTROS', "Total de linhas recebidas: {$total_linhas}");
        
        $stmt_log = $db->prepare("
            INSERT INTO log_operacao 
            (data_evento, hora_evento, funcionario, itens, pedido_id, codigo_transacao, sku_volumes, funcao, periodo, empresa)
            VALUES 
            (CURDATE(), CURTIME(), :funcionario, :itens, :pedido_id, :codigo_transacao, :sku_volumes, :funcao, :periodo, 'SOUZA')
        ");
        
        foreach ($registros as $registro) {
            
            if (!empty($registro['funcionario']) && !empty($registro['pedido_id'])) {
                
                $funcionario_upper = strtoupper(trim($registro['funcionario']));
                $funcao = htmlspecialchars($registro['funcao'] ?: 'SEP'); 
                $periodo = htmlspecialchars($registro['periodo'] ?? 'T1'); 
                $codigo_transacao = htmlspecialchars($registro['codigo_transacao'] ?? null);
                $pedido_id = (int)$registro['pedido_id']; // Mantém a linha original
                
                $stmt_log->execute([
                    ':funcionario' => $funcionario_upper,
                    ':itens' => (int)($registro['itens'] ?: 0),
                    ':pedido_id' => $pedido_id,
                    ':codigo_transacao' => $codigo_transacao, 
                    ':sku_volumes' => (int)($registro['sku_volumes'] ?: 0),
                    ':funcao' => $funcao,
                    ':periodo' => $periodo 
                ]);
                $registros_inseridos++;
                $pedidos_inseridos_ids[] = $pedido_id; // Mantém a linha original (coleta o ID)
            }
        }
        
        $db->commit(); // SUCESSO: SALVA TUDO
        
        if ($registros_inseridos > 0) {
            
            // 🚨 NOVO: GERA A STRING DE IDS PARA A AUDITORIA FINAL 🚨
            $ids_string = implode(', ', array_unique($pedidos_inseridos_ids)); 
            $detalhes_auditoria = "Pedidos inseridos: {$registros_inseridos}. IDs: ({$ids_string})";

            // ===================================================
            // ENVIANDO JSON PARA O FASTAPI (8085)
            // ===================================================
            $data_json = json_encode(['data_alvo' => null]); 
            
            $ch = curl_init($PYTHON_NOTIFY_URL);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data_json); 
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Content-Length: ' . strlen($data_json)
            ));
            
            $api_response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            // ===================================================

            if ($http_code != 200) {
                 error_log("Falha ao notificar API Python ({$PYTHON_NOTIFY_URL}). Status HTTP: {$http_code}. Resposta: {$api_response}");
            }
            
            $sucesso = true;
            // 🚨 AJUSTE: O registro de auditoria final agora usa a string de IDs 🚨
            registrar_auditoria($db, $usuario_logado, 'REGISTRO_SUCESSO', $detalhes_auditoria); 
            $mensagem = "Sucesso! **{$registros_inseridos}** registros salvos e Dashboard atualizado em tempo real!";
        } else {
            registrar_auditoria($db, $usuario_logado, 'REGISTRO_VAZIO', 'Nenhuma linha válida encontrada para salvar.');
            $mensagem = "Nenhum registro válido foi inserido.";
        }

    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack(); 
        }
        
        $mensagem_erro_db = $e->getMessage();
        if (strpos($mensagem_erro_db, 'Duplicate entry') !== false && strpos($mensagem_erro_db, 'for key') !== false) {
             // 🚨 AJUSTE: Não podemos garantir a lista de IDs em caso de erro, usamos a contagem bruta.
             $mensagem = "Erro: Foi detectado um Pedido ID já existente durante a tentativa de salvar (chave duplicada). **Nenhum registro foi salvo.**";
        } else {
             $mensagem = "Erro ao salvar no banco de dados. Tente novamente ou contate o suporte.";
        }
        // Mantemos o registro de ERRO_DB_SALVAR que já coleta o IP e a mensagem bruta de erro do DB.
        registrar_auditoria($db, $usuario_logado, 'ERRO_DB_SALVAR', $e->getMessage() . " | IP: " . $_SERVER['REMOTE_ADDR']);

    } catch (Exception $e) {
         registrar_auditoria($db, $usuario_logado, 'ERRO_GERAL', $e->getMessage());
         $mensagem = "Erro: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Registro de Atividade em Lote - Picking</title>
    <style>
        /* CSS: VISUAL PROFISSIONAL E DIMENSIONAMENTO */
        :root {
            --base-dark: #121228;
            --container-dark: #1e1e40;
            --excel-white: #ffffff;
        }

        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background-color: var(--base-dark); 
            color: #f0f0f0; 
            padding: 2.5vh 0;
            margin: 0;
            display: flex;
            justify-content: center;
            font-size: 1.35em; /* Aumenta o zoom visual */
            zoom: 1.15; /* Aumenta o zoom visual (compatibilidade) */
        }
        
        .container { 
            max-width: 95vw; 
            min-height: 80vh; 
            margin: 0 auto; 
            background: var(--container-dark); 
            padding: 20px; 
            border-radius: 12px; 
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.5); 
            display: flex;
            flex-direction: column;
        }
        
        /* Cabeçalho superior (Usuário e Sair) */
        .top-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 10px;
            margin-bottom: 20px;
            border-bottom: 1px solid #3a3a6b;
        }
        .user-info {
            color: #2196f3; 
            font-size: 1.1em;
            font-weight: bold;
        }
        .logout-link { color: #f44336; text-decoration: none; font-weight: bold; }
        
        h2 { 
            color: #ffeb3b; 
            text-align: center; 
            margin-bottom: 25px; 
            font-size: 1.8em;
        }
        
        /* Botão Adicionar Linha */
        .actions { 
            display: flex; 
            gap: 10px;
            text-align: left; 
            margin-bottom: 15px; 
        }
        .add-btn { 
            background-color: #2196f3; 
            color: white;
            padding: 8px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
        }
        .add-btn:hover { background-color: #1977d2; }
        
        /* Área da Tabela - Foco BRANCO */
        .table-scroll {
            background-color: var(--excel-white); 
            max-height: 60vh; 
            overflow-y: auto; 
            border: 1px solid #ccc; 
            margin-bottom: 15px; 
            border-radius: 4px;
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin: 0;
            color: #000000ff;
        }
        th, td { 
            border-left: 1px solid #ddd; 
            border-right: 1px solid #ddd; 
            border-bottom: 1px solid #ddd;
            padding: 0; 
            height: 35px;
            color: #333;
            font-weight: bold;
            transition: background-color 0.2s; /* Adicionado para suavizar a transição de cor */
        }
        th { 
            background-color: #f0f0f0; 
            color: #333; 
            font-size: 14px; 
            padding: 8px 5px; 
            border-bottom: 2px solid #ccc; 
            position: sticky; 
            top: 0; 
            z-index: 10; 
            font-weight: bold;
        }
        
        /* Inputs dentro da área branca */
        input[type="text"], input[type="number"] { 
            width: 100%; 
            padding: 5px; 
            box-sizing: border-box; 
            border: none;
            height: 35px; 
            background: transparent; 
            color: #333;
            font-weight: bold;
        }
        input:focus { 
            background-color: #e6f7ff; 
            outline: 1px solid #007bff; 
            border: none;
        } 
    .data-hora { font-size: 11px; color: #000000ff; text-align: center; padding: 5px; font-weight: bold; }

        /* Botão Salvar */
        .submit-btn { 
            background-color: #4CAF50; 
            color: white; 
            margin-top: 15px;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            font-weight: bold;
        }
        .submit-btn:hover { background-color: #45a049; }
        
        /* Estilos de mensagem (Sucesso/Erro) */
        .message { padding: 15px; margin-bottom: 20px; border-radius: 4px; font-weight: bold; text-align: center; }
        .success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        /* MODAL E PESQUISA */
        .modal {
            display:none; position:fixed; z-index:100; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(0,0,0,0.6);
        }
        /* 🚨 AJUSTE PRINCIPAL: MODAL CONTENT RESPONSIVO 🚨 */
        .modal-content {
            background-color: white; 
            margin: 5vh auto; /* Centraliza verticalmente com margem menor */
            padding: 25px; 
            border-radius: 8px; 
            width: 90%; 
            max-width: 800px; /* Aumenta a largura máxima para comportar a tabela */
            max-height: 90vh; /* Limita a altura do modal */
            overflow-y: auto; /* Permite rolagem no modal se o conteúdo exceder 90vh */
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }

        .modal-content h3 { color: #121228; }
        .modal-content label { color: #121228; font-weight: bold; }
        .modal-close {
            color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor:pointer;
        }
        .mov-result-card {
            border: 1px solid #ddd; padding: 15px; border-radius: 4px; margin-top: 15px; background-color: #f0f0f0;
        }
        
        /* NOVO ESTILO PARA ITENS SELECIONÁVEIS */
        .historico-item {
            padding: 8px 10px;
            border: 1px solid #ccc;
            margin-bottom: 5px;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.2s;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .historico-item:hover {
            background-color: #e6f7ff;
        }
        .historico-item.selected {
            border: 2px solid #2196f3;
            background-color: #cce7ff !important;
        }

        /* 🚨 AJUSTE PARA A TABELA DE GERENCIAMENTO 🚨 */
        #lista-gerenciamento {
            max-height: 40vh; /* Altura máxima para a lista de funcionários */
            overflow-y: auto; /* Permite rolagem apenas na tabela */
            padding: 10px; border: 1px solid #ddd; margin-top: 15px;
        }
        #lista-gerenciamento table {
            font-size: 1.1em;
        }

        /* 🚨 NOVO: Estilo para Esconder o formulário de detalhes por padrão 🚨 */
        #bloco-detalhes-edicao {
            display: none;
        }

    </style>
</head>
<body>
    <datalist id="lista-funcionarios"></datalist>

    <div id="modal-alerta-digitos" class="modal" style="z-index: 200;">
        <div class="modal-content" style="border-left: 10px solid #ff9800; max-width: 500px;">
            <h3 style="color: #e65100; text-align: center;">⚠️ Atenção: Número Longo</h3>
            
            <p style="font-size: 1.1em; color: #000000; text-align: center; font-weight: 500;">
                Você digitou um número com <strong id="lbl-qtd-digitos" style="color:#d84315;">0</strong> dígitos.
            </p>
            
            <div style="background: #fff3e0; padding: 15px; border-radius: 8px; text-align: center; margin: 15px 0; border: 1px solid #ffe0b2;">
                <span style="font-size: 0.9em; color: #000000; font-weight: bold;">Número digitado:</span><br>
                <strong id="lbl-numero-digitado" style="font-size: 1.8em; color: #000000;"></strong>
            </div>

            <p style="text-align: center; font-weight: bold; color: #121228;">Esse número está correto?</p>
            
            <div style="display: flex; gap: 10px; justify-content: center; margin-top: 20px;">
                <button id="btn-corrigir-numero" type="button" style="flex: 1; padding: 12px; background-color: #f44336; color: white; border: none; border-radius: 4px; font-weight: bold; cursor: pointer;">
                    NÃO (Apagar)
                </button>
                <button id="btn-confirmar-numero" type="button" style="flex: 1; padding: 12px; background-color: #4CAF50; color: white; border: none; border-radius: 4px; font-weight: bold; cursor: pointer;">
                    SIM (Manter)
                </button>
            </div>
        </div>
    </div>

    <div id="modal-movimentacao" class="modal">

        <div class="modal-content">
            <span id="close-mov-modal" class="modal-close">&times;</span>
            <h3 style="text-align: center;">Movimentar Pedido Existente</h3>
            
            <form id="form-movimentacao">
                <label>Número do Pedido:</label>
                <input type="number" id="mov-pedido-id" required style="width: 70%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box;">
                <button type="button" id="btn-buscar-pedido" style="width: 25%; padding: 10px; background-color: #2196f3; color: white; border: none; border-radius: 4px; margin-left: 5px;">Buscar</button>
                
                <div id="mov-resultado" class="mov-result-card" style="display: none;">
                    </div>
            </form>
        </div>
    </div>
    
    <div id="modal-funcionario" class="modal">
        <div class="modal-content">
            <span id="close-modal" class="modal-close">&times;</span>
            <h3 style="color: #121228; text-align: center; margin-bottom: 20px;">Cadastrar Novo Funcionário</h3>
            
            <form id="form-add-funcionario">
                <label style="color: #121228; font-weight: bold;">Nome Completo:</label>
                <input type="text" id="func-nome" required style="width: 100%; padding: 10px; margin-bottom: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; text-transform: uppercase;">
                
                <label style="color: #121228; font-weight: bold;">Função Padrão:</label>
                <select id="func-funcao" required style="width: 100%; padding: 10px; margin-bottom: 10px; border: 1px solid #ccc; border-radius: 4px;">
                    <option value="SEP">SEP (Separação)</option>
                    <option value="CONF">CONF (Conferência)</option>
                </select>
                
                <label style="color: #121228; font-weight: bold;">Período Padrão:</label>
                <select id="func-periodo" required style="width: 100%; padding: 10px; margin-bottom: 20px; border: 1px solid #ccc; border-radius: 4px;">
                    <option value="T1">T1</option>
                    <option value="T2">T2</option>
                    <option value="T3">T3</option>
                </select>
                
                <button type="submit" style="width: 100%; padding: 10px; background-color: #4CAF50; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">Salvar Funcionário</button>
            </form>
        </div>
    </div>

    <div id="modal-pendentes" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <span id="close-pendentes-modal" class="modal-close">&times;</span>
            <h3 style="color: #121228; text-align: center;">Pedidos Pendentes de Dados (Itens/SKU = 0)</h3>
            
            <div id="lista-pendentes" style="max-height: 40vh; overflow-y: auto; padding: 10px; border: 1px solid #ddd; margin-top: 15px;">
                <p style="color:#121228;">Carregando...</p>
            </div>
            
        </div>
    </div>

    <div id="modal-gerenciar-funcionarios" class="modal">
        <div class="modal-content">
            <span id="close-gerenciar-modal" class="modal-close">&times;</span>
            <h3 style="color: #121228; text-align: center;">Gerenciar Cadastros de Funcionários</h3>
            
            <div id="lista-gerenciamento" style="max-height: 50vh; overflow-y: auto; padding: 10px; border: 1px solid #ddd; margin-top: 15px;">
                <p style="color:#121228;">Carregando...</p>
            </div>

            <div id="bloco-detalhes-edicao">
                <h4 style="color: #121228; margin-top: 20px;">Detalhes do Funcionário Selecionado:</h4>
                
                <form id="form-editar-funcionario" style="border: 1px solid #ccc; padding: 15px; border-radius: 5px;">
                    <input type="hidden" id="edit-func-id">
                    
                    <label style="color: #121228; font-weight: bold;">Nome (Uppercase):</label>
                    <input type="text" id="edit-func-nome" required style="width: 100%; padding: 8px; margin-bottom: 8px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; text-transform: uppercase;">
                    
                    <div style="display: flex; gap: 20px; margin-bottom: 8px;">
                        <div style="flex: 1;">
                            <label style="color: #121228; font-weight: bold;">Função Padrão:</label>
                            <select id="edit-func-funcao" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                                <option value="SEP">SEP (Separação)</option>
                                <option value="CONF">CONF (Conferência)</option>
                            </select>
                        </div>
                        <div style="flex: 1;">
                            <label style="color: #121228; font-weight: bold;">Período Padrão:</label>
                            <select id="edit-func-periodo" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                                <option value="T1">T1</option>
                                <option value="T2">T2</option>
                                <option value="T3">T3</option>
                                <option value="DIA">DIA</option>
                            </select>
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <label style="color: #121228; font-weight: bold;">Status Ativo:</label>
                        <input type="checkbox" id="edit-func-ativo" checked> Ativo
                    </div>
                    
                    <button type="submit" style="width: 100%; padding: 10px; background-color: #2196f3; color: white; border: none; border-radius: 4px; font-weight: bold; cursor: pointer;">
                        SALVAR ALTERAÇÕES
                    </button>
                </form>
            </div>
            
        </div>
    </div>


<div class="container">
    <div class="top-header">
        <span class="user-info">Usuário Logado: **<?= htmlspecialchars($usuario_logado) ?>**</span>
        <a href="logout.php" class="logout-link">Sair</a>
    </div>

    <h2>Registro de Atividade em Lote</h2>

    <?php if ($mensagem): ?>
        <div class="message <?= $sucesso ? 'success' : 'error' ?>">
            <?= $mensagem ?>
        </div>
    <?php endif; ?>

    <form method="POST" id="lote-form"> 
        <div class="actions">
            <button type="button" id="btn-add-linha" class="add-btn">+ Adicionar Linha</button>
            <button type="button" id="btn-add-funcionario" class="add-btn" style="background-color: #ff9800;">+ Cadastrar Funcionário</button>
            <button type="button" id="btn-movimentar-pedido" class="add-btn" style="background-color: #00bcd4;">🔍 Pesquisar/Movimentar</button>
            <button type="button" id="btn-verificar-pendentes" class="add-btn" style="background-color: #FFC300;">⚠️ Pedidos Pendentes</button>
            <button type="button" id="btn-gerenciar-funcionarios" class="add-btn" style="background-color: #9C27B0;">⚙️ Gerenciar Funcionários</button>
        </div>

        <div class="table-scroll"> 
            <table id="tabela-registros">
                <thead>
    <tr>
        <th style="width: 20%;">Funcionário <span style="color: red;">*</span></th>
        <th style="width: 20%;">ID Pedido <span style="color: red;">*</span></th>
        <th style="width: 15%;">Função <span style="color: red;">*</span></th>
        <th style="width: 15%;">Itens (SKUs)</th>
        <th style="width: 15%;">Volumes</th>
        <th style="width: 15%;">Data/Hora</th>
    </tr>
</thead>
<tbody id="tabela-body">
                    </tbody>
            </table>
        </div>

        <button type="button" class="submit-btn" id="submit-button">Salvar Registros e Atualizar Dashboard</button>
    </form>
</div>

<script>
// Tempo limite de ociosidade (2 minutos e 30 segundos)
const IDLE_TIMEOUT_MS = 150000; // 2 minutos e 30 segundos
let lastActivity = Date.now;

// Função para derrubar o usuário
function derrubarUsuarioPorOciosidade() {
    // Evita múltiplos redirecionamentos
    if (!window.__derrubado) {
        window.__derrubado = true;
        window.location.href = 'logout.php';
    }
}

// Atualiza o timestamp da última atividade
function updateLastActivity() {
    lastActivity = Date.now();
}

// Eventos que contam como atividade
['mousemove', 'keydown', 'mousedown', 'touchstart', 'scroll'].forEach(evt => {
    window.addEventListener(evt, updateLastActivity);
});

// Checa ociosidade a cada 2 segundos
setInterval(() => {
    if (Date.now() - lastActivity > IDLE_TIMEOUT_MS) {
        derrubarUsuarioPorOciosidade();
    }
}, 2000);

    const tabelaBody = document.getElementById('tabela-body');
    const btnAddLinha = document.getElementById('btn-add-linha');
    const scrollContainer = document.querySelector('.table-scroll'); 
    const submitButton = document.getElementById('submit-button');
    const loteForm = document.getElementById('lote-form'); 
    let linhaId = 0;
    
    // ===================================================
    // 🚨 INÍCIO: LÓGICA DO MODAL DE ALERTA DE DÍGITOS 🚨
    // ===================================================
    
    const modalAlertaDigitos = document.getElementById('modal-alerta-digitos');
    const lblNumeroDigitado = document.getElementById('lbl-numero-digitado');
    const lblQtdDigitos = document.getElementById('lbl-qtd-digitos');
    const btnCorrigirNumero = document.getElementById('btn-corrigir-numero');
    const btnConfirmarNumero = document.getElementById('btn-confirmar-numero');
    let inputEmAnalise = null; 

    // Função que checa ENQUANTO o usuário digita
    function verificarQtdDigitos(inputElement) {
        const valor = inputElement.value.replace(/\D/g, ''); 
        
        // Se o usuário já confirmou, não incomoda mais
        if (inputElement.dataset.confirmado === "true") return;

        // REGRA: Se passar de 6 dígitos
        if (valor.length > 6) {
            inputEmAnalise = inputElement;
            inputElement.blur(); // Tira o foco
            
            lblNumeroDigitado.textContent = valor;
            lblQtdDigitos.textContent = valor.length;
            modalAlertaDigitos.style.display = 'block';
        }
    }

    // BOTÃO "NÃO" (Apagar)
    if(btnCorrigirNumero) {
        btnCorrigirNumero.addEventListener('click', () => {
            if (inputEmAnalise) {
                inputEmAnalise.value = ''; 
                inputEmAnalise.dataset.confirmado = "false"; 
                inputEmAnalise.focus();
            }
            modalAlertaDigitos.style.display = 'none';
        });
    }

    // BOTÃO "SIM" (Manter)
    if(btnConfirmarNumero) {
        btnConfirmarNumero.addEventListener('click', () => {
            if (inputEmAnalise) {
                inputEmAnalise.dataset.confirmado = "true"; 
                inputEmAnalise.focus();
            }
            modalAlertaDigitos.style.display = 'none';
        });
    }
    
    // EVITAR FECHAR O MODAL CLICANDO FORA (OBRIGA DECISÃO)
    window.addEventListener('click', (event) => {
        // Se clicar fora do modal de alerta, NÃO fecha ele automaticamente
        if (event.target == modalAlertaDigitos) {
            // Faz nada, obriga a clicar SIM ou NÃO
        }
    });
    // ===================================================
    // FIM DA LÓGICA DO MODAL
    // ===================================================

    let FUNCIONARIOS_MAP = {}; 
    // 🚨 APONTANDO PARA O HOST DE TESTE (8085) 🚨
    const PYTHON_API_URL = 'http://192.168.0.63:8085'; 
    const MAX_RETRIES = 3;
    const RETRY_DELAY_MS = 5000;
    const REFRESH_INTERVAL_MS = 1800000; // 30 minutos

    // Modal elements
    const btnAddFuncionario = document.getElementById('btn-add-funcionario');
    const modalFuncionario = document.getElementById('modal-funcionario');
    const closeModal = document.getElementById('close-modal');
    const formAddFuncionario = document.getElementById('form-add-funcionario');
    
    // 🛑 ELEMENTOS DE MOVIMENTAÇÃO (CRÍTICO) 🛑
    const btnMovimentarPedido = document.getElementById('btn-movimentar-pedido');
    const modalMovimentacao = document.getElementById('modal-movimentacao');
    const closeMovModal = document.getElementById('close-mov-modal');
    const btnBuscarPedido = document.getElementById('btn-buscar-pedido');
    const movResultadoDiv = document.getElementById('mov-resultado');
    const movPedidoIdInput = document.getElementById('mov-pedido-id');

    // 🛑 NOVOS ELEMENTOS DO MODAL PENDENTES 🛑
    const btnVerificarPendentes = document.getElementById('btn-verificar-pendentes');
    const modalPendentes = document.getElementById('modal-pendentes');
    const closePendentesModal = document.getElementById('close-pendentes-modal');
    const listaPendentesDiv = document.getElementById('lista-pendentes');
    
    // 🚨 NOVOS ELEMENTOS DO MODAL GERENCIAMENTO 🚨
    const btnGerenciarFuncionarios = document.getElementById('btn-gerenciar-funcionarios');
    const modalGerenciar = document.getElementById('modal-gerenciar-funcionarios');
    const closeGerenciarModal = document.getElementById('close-gerenciar-modal');
    const listaGerenciamentoDiv = document.getElementById('lista-gerenciamento');
    const formEditarFuncionario = document.getElementById('form-editar-funcionario');
    const blocoDetalhesEdicao = document.getElementById('bloco-detalhes-edicao'); // 🚨 NOVO ELEMENTO 🚨
    let TODOS_FUNCIONARIOS = []; // Mapeamento completo (inclui ID e ATIVO)


    // Variável PHP para o usuário logado (Usada para auditoria)
    const USUARIO_LOGADO = '<?= htmlspecialchars($usuario_logado) ?>';
    // Objeto global para guardar dados do pedido pesquisado (MANTIDO)
    const MOV_PEDIDO_DATA = {}; 
    // NOVO: ID do registro de log que o usuário está escolhendo para ser a origem da transferência
    let MOV_LOG_ID = null; 


    // ===================================================
    // FUNÇÕES DE AUDITORIA E EXCLUSÃO (MANTIDAS)
    // ===================================================

    async function excluirLogUnico(logId, pedidoId) {
        
        const confirmacao = confirm(`ATENÇÃO: Você tem certeza que deseja EXCLUIR APENAS ESTE REGISTRO (ID: ${logId}) do Pedido ${pedidoId}?`);
        
        if (confirmacao) {
            try {
                const response = await fetch(`${PYTHON_API_URL}/api/excluir_log_unico`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        log_id: logId, 
                        pedido_id: pedidoId,
                        usuario_logado: USUARIO_LOGADO 
                    }) 
                });
                
                const result = await response.json();

                if (response.ok) {
                    alert(result.message);
                    // Após excluir, refaz a busca do pedido para atualizar o modal
                    await buscarPedido(pedidoId);
                } else {
                     alert(`Falha na exclusão: ${result.detail || result.message}`);
                }

            } catch (error) {
                alert('Erro de conexão com a API Python durante a exclusão.');
            }
        }
    }
    
    async function excluirPedidoFisico(pedidoId) {
        // Funções que dependem do MOV_PEDIDO_DATA.id_pedido
        const confirmacao = confirm(`ATENÇÃO CRÍTICA: Você tem certeza que deseja EXCLUIR PERMANENTEMENTE o Pedido ${pedidoId}? Esta ação NÃO PODE ser desfeita e APAGA o histórico!`);
        
        if (confirmacao) {
            try {
                const response = await fetch(`${PYTHON_API_URL}/api/excluir_pedido_fisico`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        pedido_id: pedidoId, 
                        usuario_logado: USUARIO_LOGADO 
                    }) 
                });
                
                const result = await response.json();

                if (response.ok) {
                    alert(result.message);
                    // Fecha o modal
                    document.getElementById('modal-movimentacao').style.display = 'none';
                    document.getElementById('mov-resultado').style.display = 'none';
                    document.getElementById('mov-pedido-id').value = '';
                } else {
                     alert(`Falha na exclusão: ${result.detail || result.message}`);
                }
            } catch (error) {
                alert('Erro de conexão com a API Python durante a exclusão.');
            }
        }
    }


    // ===================================================
    // FUNÇÃO CRÍTICA: LIMPEZA ANTES DO ENViO (MANTIDA)
    // ===================================================
    function preSubmitCleanup() {
        const rows = tabelaBody.getElementsByTagName('tr');
        let hasValidData = false;
        
        // 1. Reativamos todos os campos para a checagem
        loteForm.querySelectorAll('input:disabled, select:disabled').forEach(element => {
            element.disabled = false;
        });

        // 2. Iteramos para desativar SÓ o que está vazio
        for (let i = 0; i < rows.length; i++) {
            const row = rows[i];
            
            const funcInput = row.querySelector('input[name$="[funcionario]"]');
            const pedidoInput = row.querySelector('input[name$="[pedido_id]"]');

            if (funcInput && pedidoInput) {
                if (funcInput.value.trim() !== '' && pedidoInput.value.trim() !== '') {
                    hasValidData = true;
                } else {
                    row.querySelectorAll('input, select').forEach(element => {
                        element.disabled = true;
                    });
                }
            }
        }
        return hasValidData; 
    }
    
    // NOVO LISTENER: GERENCIA O CLIQUE DO BOTão (MANTIDO)
    submitButton.addEventListener('click', (e) => {
        const hasValidData = preSubmitCleanup();
        
        if (hasValidData) {
            loteForm.submit();
        } else {
            alert("Erro: Preencha pelo menos um Pedido e Funcionário antes de salvar.");
            
            setTimeout(() => {
                loteForm.querySelectorAll('input:disabled, select:disabled').forEach(element => {
                    element.disabled = false;
                });
            }, 100); 
        }
    });

    // ===================================================
    // FUNÇÕES DE UTILIDADE E CARREGAMENTO (MANTIDAS)
    // ===================================================

    function getTimestamp() {
        const now = new Date();
        const time = now.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        const date = now.toLocaleDateString('pt-BR');
        return `${date} ${time}`;
    }
    
    function focusAndScroll(row) {
        const firstInput = row.cells[0].querySelector('input'); 
        if (firstInput) {
            firstInput.focus();
            
            if (scrollContainer) {
                const rowTop = row.offsetTop;
                const containerHeight = scrollContainer.clientHeight;
                const rowHeight = row.offsetHeight;

                scrollContainer.scrollTop = rowTop - containerHeight + rowHeight + 10;
            }
        }
    }

    function preencherAutomatico(inputElement) {
        const nome = inputElement.value.toUpperCase().trim();
        const row = inputElement.closest('tr');
        // 🚨 NOVO: Mapeia apenas os ATIVOS para o autocomplete do formulário 🚨
        const funcionarioAtivo = TODOS_FUNCIONARIOS.find(f => f.nome === nome && f.ativo === 1);
        const rowData = funcionarioAtivo ? { funcao: funcionarioAtivo.funcao, periodo: funcionarioAtivo.periodo } : FUNCIONARIOS_MAP[nome];

        const funcInput = row.querySelector('input[name$="[funcao]"]');
        const periodoHiddenInput = row.querySelector('input[name$="[periodo]"][type="hidden"]'); 

        if (rowData) {
            if (funcInput) funcInput.value = rowData.funcao;
            if (periodoHiddenInput) periodoHiddenInput.value = rowData.periodo; 
        } else {
            if (funcInput) funcInput.value = 'SEP';
            if (periodoHiddenInput) periodoHiddenInput.value = 'T1'; 
        }
        
        updateTimestamp(row); 
    }

    function updateTimestamp(row) {
        const timestampDiv = row.querySelector('.data-hora');
        if (timestampDiv) {
            timestampDiv.textContent = getTimestamp();
        }
    }

    async function carregarFuncionarios(attempt = 1) {
        try {
            const response = await fetch(`${PYTHON_API_URL}/api/funcionarios`);
            
            if (!response.ok) {
                throw new Error(`Erro HTTP: ${response.status}`);
            }

            const data = await response.json();
            
            // 🚨 Mapeamento completo para gerenciamento e Datalist 🚨
            TODOS_FUNCIONARIOS = data.funcionarios.map(f => ({
                id: f.id,
                nome: f.nome,
                funcao: f.funcao_padrao,
                periodo: f.periodo_padrao,
                ativo: f.ativo 
            }));
            
            // Mapeamento filtrado apenas para o autocomplete (Datalist)
            const funcionariosAtivos = TODOS_FUNCIONARIOS.filter(f => f.ativo === 1);
            FUNCIONARIOS_MAP = funcionariosAtivos.reduce((map, obj) => {
                map[obj.nome] = { funcao: obj.funcao_padrao, periodo: obj.periodo_padrao }; 
                return map;
            }, {});

            let dataList = document.getElementById('lista-funcionarios');
            const funcionarioOptions = funcionariosAtivos.map(f => `<option value="${f.nome}">`).join('');
            dataList.innerHTML = funcionarioOptions;
            
        } catch (error) {
            if (attempt < MAX_RETRIES) {
                await new Promise(resolve => setTimeout(resolve, RETRY_DELAY_MS));
                carregarFuncionarios(attempt + 1); 
            }
        }
    }

    // ===================================================
    // FUNÇÃO DE VALIDAÇÃO E AUTOPREENCHIMENTO (AJUSTADA)
    // ===================================================

    const CHECAGEM_CACHE = {}; 

    async function checkPedidoId(inputElement) { 
        const row = inputElement.closest('tr');
        const pedidoId = inputElement.value.trim();
        const cell = inputElement.closest('td');
        
        // Pega os inputs de destino (para autopreenchimento)
        const funcaoInput = row.querySelector('input[name$="[funcao]"]');
        const itensInput = row.querySelector('input[name$="[itens]"]');
        const volumesInput = row.querySelector('input[name$="[sku_volumes]"]');
        // 🚨 NOVO: Campo oculto da transação 🚨
        const transacaoHiddenInput = row.querySelector('input[name$="[codigo_transacao]"][type="hidden"]'); 

        const funcao = funcaoInput ? funcaoInput.value.toUpperCase().trim() : '';
        
        // Se o campo estiver vazio ou for inválido, limpa e sai
        if (!pedidoId || !funcao || isNaN(parseInt(pedidoId))) {
            cell.style.backgroundColor = 'transparent';
            cell.title = '';
            itensInput.value = '';
            volumesInput.value = '';
            // 🚨 Limpa transação 🚨
            if (transacaoHiddenInput) transacaoHiddenInput.value = '';
            return;
        }

        // 1. Checar Duplicidade (BACKEND)
        const cacheKey = `${pedidoId}-${funcao}`;
        let isDuplicated = false;

        // Checar Cache de Duplicidade
        if (CHECAGEM_CACHE[cacheKey]) {
            isDuplicated = CHECAGEM_CACHE[cacheKey].exists;
        } else {
            // Se não está no cache, checa no backend (regra de 1x por função)
            try {
                const response = await fetch(`${PYTHON_API_URL}/api/check_pedido_id`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ pedido_id: parseInt(pedidoId), funcao: funcao })
                });
                
                const result = await response.json();
                isDuplicated = result.exists;
                CHECAGEM_CACHE[cacheKey] = result; // Salva no cache
                
            } catch (error) {
                console.error("Erro na checagem de duplicidade:", error);
                cell.style.backgroundColor = '#f44336'; 
                cell.title = '❌ ERRO: Falha na API de duplicidade. Checagem manual necessária.';
                itensInput.value = '';
                volumesInput.value = '';
                if (transacaoHiddenInput) transacaoHiddenInput.value = ''; // 🚨 Limpa transação 🚨
                return;
            }
        }

        // 2. Tratar Duplicidade (BLOQUEIO OBRIGATÓRIO)
        if (isDuplicated) {
            cell.style.backgroundColor = '#ffcccc'; 
            cell.title = `🔴 BLOQUEIO: Pedido ${pedidoId} já existe para ${funcao}.`;
            itensInput.value = '';
            volumesInput.value = '';
            if (transacaoHiddenInput) transacaoHiddenInput.value = ''; // 🚨 Limpa transação 🚨
            return;
        } 

        // 3. Se não é duplicado: Buscar Dados do ERP (SKU, Volume E CODIGO_TRANSACAO)
        
        cell.style.backgroundColor = '#ccffcc'; 
        cell.title = '✅ Pedido ID novo/disponível. Buscando dados do ERP...';

        try {
            const erpResponse = await fetch(`${PYTHON_API_URL}/api/pedido_erp_data/${pedidoId}`);

            if (erpResponse.status === 404) {
                // ADVERTÊNCIA: Permite o cadastro com 0 SKUs/Volumes (bandeira para rastreamento)
                cell.style.backgroundColor = '#ffeb3b'; 
                cell.title = `⚠️ ADVERTÊNCIA: Pedido ${pedidoId} não encontrado na base ERP. Inserindo com 0 SKUs/Vol e Transação NULL.`;
                itensInput.value = '0'; 
                volumesInput.value = '0';
                if (transacaoHiddenInput) transacaoHiddenInput.value = ''; // 🚨 Transação NULL/Vazio
                return;
            }

            if (!erpResponse.ok) {
                throw new Error(`Erro HTTP: ${erpResponse.status}`);
            }
            
            const erpData = await erpResponse.json();

            // 4. Autopreencher (com dados reais)
            itensInput.value = erpData.sku; 
            volumesInput.value = erpData.volumes; 
            
            // 🚨 NOVO: GRAVA O CODIGO_TRANSACAO NO CAMPO OCULTO 🚨
            if (transacaoHiddenInput) {
                transacaoHiddenInput.value = erpData.codigo_transacao || ''; // Usa '' se vier NULL
            }

            cell.title = '✅ Dados do ERP preenchidos. Pronto para salvar.';

        } catch (error) {
            // Erro de conexão/servidor. Bloqueia.
            console.error("Erro na busca de dados do ERP:", error);
            cell.style.backgroundColor = '#f44336'; 
            cell.title = '❌ ERRO FATAL: Falha na conexão com a API. Verifique o servidor.';
            itensInput.value = '';
            volumesInput.value = '';
            if (transacaoHiddenInput) transacaoHiddenInput.value = ''; // 🚨 Limpa transação 🚨
        }
    }


    // ===================================================
    // FUNÇÃO DE NAVEGAÇÃO (CORREÇÃO DO ERRO setupRowNavigation)
    // ===================================================
    function setupRowNavigation(row) {
        // Permite navegação apenas entre Funcionário e ID Pedido
        const allInputs = [
            row.querySelector('input[name$="[funcionario]"][type="text"]'), 
            row.querySelector('input[name$="[pedido_id]"]')
        ].filter(Boolean);

        allInputs.forEach((input, idx) => {
            input.addEventListener('keydown', function(e) {
                // TAB para frente
                if (e.key === 'Tab' && !e.shiftKey) {
                    e.preventDefault();
                    if (idx < allInputs.length - 1) {
                        allInputs[idx + 1].focus();
                    } else {
                        // Último input da linha
                        const isLastRow = tabelaBody.lastElementChild === row;
                        const funcInput = row.querySelector('input[name$="[funcionario]"]');
                        if (isLastRow && funcInput && funcInput.value.trim() !== '') {
                            const novaLinha = adicionarLinha(false);
                        } else {
                            const nextRow = row.nextElementSibling;
                            if (nextRow) {
                                const nextFuncInput = nextRow.querySelector('input[name$="[funcionario]"]');
                                if (nextFuncInput) nextFuncInput.focus();
                            } else if (isLastRow) {
                                submitButton.focus();
                            }
                        }
                    }
                }
                // TAB para trás
                else if (e.key === 'Tab' && e.shiftKey) {
                    e.preventDefault();
                    if (idx > 0) {
                        allInputs[idx - 1].focus();
                    } else {
                        const prevRow = row.previousElementSibling;
                        if (prevRow) {
                            const prevInputs = [
                                prevRow.querySelector('input[name$="[funcionario]"]'),
                                prevRow.querySelector('input[name$="[pedido_id]"]')
                            ].filter(Boolean);
                            if (prevInputs.length) prevInputs[prevInputs.length - 1].focus();
                        }
                    }
                }
                // Setas esquerda/direita para mover entre campos
                else if (e.key === 'ArrowRight') {
                    if (idx < allInputs.length - 1) {
                        allInputs[idx + 1].focus();
                        e.preventDefault();
                    }
                } else if (e.key === 'ArrowLeft') {
                    if (idx > 0) {
                        allInputs[idx - 1].focus();
                        e.preventDefault();
                    }
                }
                // Setas cima/baixo para mover entre linhas
                else if (e.key === 'ArrowDown') {
                    e.preventDefault(); 
                    const rowIdx = row.rowIndex - 1; 
                    const nextRow = tabelaBody.rows[rowIdx + 1];
                    if (nextRow) {
                        const nextInputs = [
                            nextRow.querySelector('input[name$="[funcionario]"]'),
                            nextRow.querySelector('input[name$="[pedido_id]"]')
                        ].filter(Boolean);
                        if (nextInputs[idx]) nextInputs[idx].focus();
                    }
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault(); 
                    const rowIdx = row.rowIndex - 1;
                    const prevRow = tabelaBody.rows[rowIdx - 1];
                    if (prevRow) {
                        const prevInputs = [
                            prevRow.querySelector('input[name$="[funcionario]"]'),
                            prevRow.querySelector('input[name$="[pedido_id]"]')
                        ].filter(Boolean);
                        if (prevInputs[idx]) prevInputs[idx].focus();
                    }
                }
            });
        });
    }

    // ===================================================
    // FUNÇÕES DE CRIAÇÃO DE LINHAS (AJUSTADA)
    // ===================================================

    function criarLinha(funcionario = '', pedido = '', funcao = 'SEP', itens = '', volumes = '', periodo = 'T1') {
        const row = tabelaBody.insertRow();
        row.id = `row-${linhaId++}`;

        const cols = [
            // 1. Funcionário (INPUT + DATALIST)
            `<input type="text" name="registro[${row.id}][funcionario]" value="${funcionario}" 
                    onchange="preencherAutomatico(this)" list="lista-funcionarios" required style="text-transform: uppercase;">
            <input type="hidden" name="registro[${row.id}][periodo]" value="${periodo}">`, 
            
            // 2. Pedido ID (Com Trava) + CODIGO_TRANSACAO OCULTO
            `<input type="number" 
                    name="registro[${row.id}][pedido_id]" 
                    value="${pedido}" 
                    required min="1" 
                    placeholder="Ex: 123456"
                    data-confirmado="false"
                    oninput="verificarQtdDigitos(this)" 
                    onchange="updateTimestamp(this.closest('tr')); checkPedidoId(this)">
             <input type="hidden" name="registro[${row.id}][codigo_transacao]" value="">`,
            
            // 3. Função (readonly)
            `<input type="text" name="registro[${row.id}][funcao]" value="${funcao}" required readonly tabindex="-1" style="background:#f0f0f0; cursor:not-allowed;">`,
            
            // 4. Itens (SKUs)
            `<input type="number" name="registro[${row.id}][itens]" value="${itens}" min="0" readonly onchange="updateTimestamp(this.closest('tr'))">`,
            
            // 5. Volumes
            `<input type="number" name="registro[${row.id}][sku_volumes]" value="${volumes}" min="0" readonly onchange="updateTimestamp(this.closest('tr'))">`,

            // 6. Data e Hora
            `<div class="data-hora">--</div>`,
        ];

        cols.forEach((html, index) => {
            const cell = row.insertCell(index);
            cell.innerHTML = html;
        });
        
        // AJUSTE: Busca apenas o input TEXT
        const funcInput = row.cells[0].querySelector('input[type="text"]');
        if (funcInput && funcionario) {
            preencherAutomatico(funcInput);
        }

        const pedidoInput = row.cells[1].querySelector('input[type="number"]');
        if (pedidoInput && pedido) {
            checkPedidoId(pedidoInput);
        }

        setupRowNavigation(row);

        return row; 
    }
    
    function adicionarLinha(manual = true) {
        let ultimoFuncionario = '';
        
        if (tabelaBody.rows.length > 0) {
            const ultimaLinha = tabelaBody.rows[tabelaBody.rows.length - 1];
            // 🛑 CORREÇÃO: Busca o input type="text"
            const inputFuncionario = ultimaLinha.cells[0].querySelector('input[type="text"]');
            ultimoFuncionario = inputFuncionario ? inputFuncionario.value : '';
        }
        if (!ultimoFuncionario) ultimoFuncionario = ''; 
        
        const novaLinha = criarLinha(ultimoFuncionario);
        focusAndScroll(novaLinha);
        return novaLinha; 
    }
    
    // Evento para o BOTÃO MANUAL
    btnAddLinha.addEventListener('click', () => {
        adicionarLinha(true);
    });

    // Evento de remoção de linha (via DELETE/BACKSPACE no campo Pedido ID vazio)
    document.addEventListener('keydown', (e) => {
        const activeElement = document.activeElement;
        
        if ((e.key === 'Delete' || e.key === 'Backspace') && 
            activeElement.tagName === 'INPUT' && 
            activeElement.name.includes('[pedido_id]') &&
            activeElement.value === '') {
            
            const row = activeElement.closest('tr');
            if (row && tabelaBody.rows.length > 1) { 
                const currentRowIndex = row.rowIndex; 
                row.remove();
                e.preventDefault();
                
                if (tabelaBody.rows.length > currentRowIndex) {
                    tabelaBody.rows[currentRowIndex].cells[1].querySelector('input').focus(); 
                } else if (tabelaBody.rows.length > 0) {
                    tabelaBody.rows[tabelaBody.rows.length - 1].cells[1].querySelector('input').focus();
                }
            }
        }
    });

    // ===================================================
    // FUNÇÕES DO MODAL DE CADASTRO DE FUNCIONÁRIO (MANTIDAS)
    // ===================================================

    // Listeners do Modal
    btnAddFuncionario.addEventListener('click', () => {
        modalFuncionario.style.display = 'block';
    });

    closeModal.addEventListener('click', () => {
        modalFuncionario.style.display = 'none';
        document.getElementById('form-add-funcionario').reset();
    });

    // Lida com o envio do formulário de funcionário via AJAX/Fetch
    document.getElementById('form-add-funcionario').addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const nome = document.getElementById('func-nome').value.toUpperCase().trim();
        const funcao = document.getElementById('func-funcao').value;
        const periodo = document.getElementById('func-periodo').value;
        
        if (!nome) {
             alert('O nome do funcionário é obrigatório.');
             return;
        }

        try {
            const response = await fetch(`${PYTHON_API_URL}/api/add_funcionario`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ nome, funcao, periodo })
            });
            
            const result = await response.json();

            if (response.ok) {
                alert(result.message);
                modalFuncionario.style.display = 'none';
                document.getElementById('form-add-funcionario').reset();
                await carregarFuncionarios(); 

            } else {
                alert(`Falha ao cadastrar: ${result.detail || result.message}`);
            }

        } catch (error) {
            alert('Erro de conexão com a API Python. Verifique se o main.py está rodando.');
        }
    });


    // ===================================================
    // FUNÇÕES DO MODAL DE PESQUISA E MOVIMENTAÇÃO (MANTIDAS/RESTABELECIDAS)
    // ===================================================

    function selecionarMovimentacao(logId, funcionario, funcao, periodo) {
        // Remove a seleção de todos os itens
        document.querySelectorAll('.historico-item').forEach(item => {
            item.classList.remove('selected');
        });

        // Adiciona a seleção ao item clicado
        const selectedItem = document.querySelector(`.historico-item[data-log-id="${logId}"]`);
        if (selectedItem) {
            selectedItem.classList.add('selected');
        }

        // 1. Guarda o ID da linha do log a ser movimentada (CRÍTICO)
        MOV_LOG_ID = logId;
        
        // 2. Guarda o status atual desse log para referência (quem está saindo)
        MOV_PEDIDO_DATA.log_id = logId;
        MOV_PEDIDO_DATA.funcionario_antigo = funcionario;
        MOV_PEDIDO_DATA.funcao_origem = funcao; 
        MOV_PEDIDO_DATA.periodo_origem = periodo;
        
        // 3. Atualiza o texto de origem da transferência para feedback
        document.getElementById('transferencia-origem-info').innerHTML = `
            <p style="color:#121228;">Movimentando **${funcao}/${periodo}** de: **${funcionario}**</p>
        `;

        // 4. Mostra o bloco de destino
        document.getElementById('bloco-destino-movimentacao').style.display = 'block';
    }

    async function buscarPedido(pedidoId) {
        // Limpa a seleção anterior
        MOV_LOG_ID = null;
        movResultadoDiv.innerHTML = '<p style="color:#121228;">Buscando...</p>';
        movResultadoDiv.style.display = 'block';
        
        try {
            const response = await fetch(`${PYTHON_API_URL}/api/pedido/${pedidoId}`);
            
            if (response.status === 404) {
                 movResultadoDiv.innerHTML = `<p style="color:red; font-weight:bold;">Pedido ${pedidoId} não encontrado no log.</p>`;
                 return;
            }

            if (!response.ok) {
                throw new Error('Erro ao buscar na API.');
            }

            const data = await response.json();
            
            const historico = data.historico;
            if (!historico || historico.length === 0) {
                 movResultadoDiv.innerHTML = `<p style="color:red; font-weight:bold;">Pedido ${pedidoId} não encontrado no log.</p>`;
                 return;
            }
            
            // O último registro ainda é útil para status atual
            const statusAtual = historico[historico.length - 1];
            
            const dataHoraISO = statusAtual.timestamp_registro; 
            const dataHoraDateObj = new Date(dataHoraISO);
            const dataHoraAtualFormatada = dataHoraDateObj.toLocaleDateString('pt-BR') + ' ' + 
                                            dataHoraDateObj.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });

            // 1. Guarda o ID do Pedido
            MOV_PEDIDO_DATA.id_pedido = pedidoId; 


            // 2. Monta o HTML do histórico
            let historicoHTML = historico.map(evento => {
                const rawISO = evento.timestamp_registro;
                const dataHoraPronta = rawISO ? new Date(rawISO).toLocaleDateString('pt-BR') + ' ' + new Date(rawISO).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) : 'N/A';
                
                let acao = `[${evento.funcao}/${evento.periodo}] - **${evento.funcionario}**`;
                let detalhes = (evento.itens > 0 || evento.sku_volumes > 0) ? 
                                `(${evento.itens} Itens / ${evento.sku_volumes} Vol.)` : '';
                
                const bgColor = (evento.acao_movimentacao && evento.acao_movimentacao.includes("TRANSFERIDO")) ? '#fff0e5' : '#ffffff';
                const isUltimo = statusAtual.id === evento.id;

                const btnExcluir = `
                    <button type="button" 
                        class="btn-excluir-log" 
                        onclick="excluirLogUnico(${evento.id}, ${pedidoId})" 
                        style="background-color: #9C27B0; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 0.8em; margin-left: 10px;">
                        X Excluir Log
                    </button>
                `;
                
                return `
                    <div class="historico-item ${isUltimo ? 'selected' : ''}" 
                         data-log-id="${evento.id}" 
                         onclick="selecionarMovimentacao(
                            ${evento.id}, 
                            '${evento.funcionario.replace(/'/g, "\\'")}', 
                            '${evento.funcao}', 
                            '${evento.periodo}'
                         )"
                         style="background-color: ${bgColor}; display: flex; justify-content: space-between; align-items: center;">
                         
                        <div>
                            <span style="font-weight: bold; color: #007bff; margin-right: 10px;">${dataHoraPronta}</span>
                            <p style="margin: 2px 0; color: #333; display: inline;">
                                ${acao} ${detalhes}
                            </p>
                        </div>
                        <span style="font-size: 0.8em; color: #999; margin-right: 10px;">ID: ${evento.id}</span>
                        ${btnExcluir} 
                    </div>
                `;
            }).join('');


            // 3. Renderiza o resultado
            movResultadoDiv.innerHTML = `
                <h4 style="color: #121228; margin-bottom: 5px;">Pedido: **${pedidoId}**</h4>
                
                <hr style="border-top: 1px solid #ccc; margin: 10px 0;">

                <p style="color:#121228; font-weight: bold; margin-bottom: 5px;">Status Atual (Último Registro):</p>
                <p style="color:#121228;">&nbsp;&nbsp;&nbsp;➡️ **Funcionário:** ${statusAtual.funcionario}</p>
                <p style="color:#121228;">&nbsp;&nbsp;&nbsp;➡️ **Função/Período:** ${statusAtual.funcao} / ${statusAtual.periodo}</p>
                <p style="color:#121228;">&nbsp;&nbsp;&nbsp;➡️ **Data/Hora:** ${dataHoraAtualFormatada}</p>
                
                <hr style="border-top: 1px solid #3a3a6b; margin: 15px 0;">
                
                <h4 style="color: #121228; margin-bottom: 10px;">Histórico Completo (Clique para selecionar origem da movimentação):</h4>
                <div id="historico-lista" style="max-height: 150px; overflow-y: auto; padding-right: 5px; margin-bottom: 15px;">
                    ${historicoHTML}
                </div>
                
                <hr style="border-top: 1px solid #ccc; margin: 10px 0;">
                
                <button type="button" onclick="excluirPedidoFisico(${pedidoId})" style="width: 100%; padding: 10px; background-color: #f44336; color: white; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; margin-bottom: 10px;">
                    🔴 EXCLUIR PEDIDO COMPLETO (ATENÇÃO)
                </button>

                <div id="bloco-destino-movimentacao" style="display:none; padding-top: 10px;">
                    <h4 style="color: #121228; margin-bottom: 5px;">Movimentar:</h4>
                    <div id="transferencia-origem-info" style="margin-bottom: 10px;"></div>
                    
                    <label style="color: #121228; font-weight: bold; display: block;">Transferir para:</label>
                    <input type="text" id="mov-novo-funcionario" list="lista-funcionarios" placeholder="Nome do novo funcionário (Obrigatório)" 
                        required style="width: 100%; padding: 8px; margin-bottom: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; text-transform: uppercase;">
                    
                    <button type="button" id="btn-confirmar-mov" style="width: 100%; padding: 10px; background-color: #25d61fff; color: white; border: none; border-radius: 4px; font-weight: bold; cursor: pointer;">
                        CONFIRMAR MOVIMENTAÇÃO
                    </button>

                </div>
            `;
            
            // 4. Seleciona o último registro por padrão (para facilitar)
            selecionarMovimentacao(
                statusAtual.id, 
                statusAtual.funcionario, 
                statusAtual.funcao, 
                statusAtual.periodo
            );


            // Adiciona listener para o botão de confirmação
            document.getElementById('btn-confirmar-mov').addEventListener('click', confirmarMovimentacao);


        } catch (error) {
            console.error("Erro na busca de pedido:", error);
            movResultadoDiv.innerHTML = `<p style="color:red; font-weight:bold;">Erro de conexão. Tente novamente.</p>`;
        }
    }
    
    async function confirmarMovimentacao() {
        const novoFuncInput = document.getElementById('mov-novo-funcionario');
        const novoFuncionario = novoFuncInput.value.toUpperCase().trim();
        
        // TRAVA CRÍTICA: Verifica se MOV_LOG_ID está preenchido
        if (!MOV_LOG_ID || !MOV_PEDIDO_DATA.id_pedido) {
            alert("Erro: Selecione um item do histórico para iniciar a movimentação.");
            return;
        }

        if (!novoFuncionario || !MOV_PEDIDO_DATA.log_id) {
            alert("Preencha o nome do novo funcionário.");
            return;
        }
        
        // 🚨 VERIFICA SE O NOVO FUNCIONÁRIO ESTÁ ATIVO NO MAPA 🚨
        const funcionarioEncontrado = TODOS_FUNCIONARIOS.find(f => f.nome === novoFuncionario && f.ativo === 1);
        
        if (!funcionarioEncontrado) {
            alert(`Funcionário ${novoFuncionario} não cadastrado ou está inativo. Cadastre-o ou ative-o.`);
            return;
        }

        // Monta o objeto de dados para a API (O backend VAI PRECISAR DESSE log_id)
        const movData = {
            log_id: MOV_LOG_ID, 
            pedido_id: MOV_PEDIDO_DATA.id_pedido, 
            funcionario_antigo: MOV_PEDIDO_DATA.funcionario_antigo,
            funcionario_novo: novoFuncionario,
            funcao_origem: MOV_PEDIDO_DATA.funcao_origem,
            periodo_origem: MOV_PEDIDO_DATA.periodo_origem
        };
        
        try {
            const response = await fetch(`${PYTHON_API_URL}/api/movimentar_pedido`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(movData)
            });
            
            const result = await response.json();

            if (response.ok) {
                alert(result.message);
                // Limpa o estado e fecha o modal
                MOV_LOG_ID = null;
                modalMovimentacao.style.display = 'none';
                movResultadoDiv.style.display = 'none';
                movPedidoIdInput.value = '';
            } else {
                 alert(`Falha na movimentação: ${result.detail || result.message}`);
            }

        } catch (error) {
            alert('Erro de conexão com a API Python durante a movimentação.');
            console.error('Erro de movimentação:', error);
        }
    }


    // 🛑 FUNÇÕES DE VERIFICAÇÃO DE PENDENTES (MANTIDAS) 🛑
    async function forcarAtualizacaoLog(logId, pedidoId) {
        
        if (!confirm(`Confirma a verificação do Pedido ${pedidoId} (Log ID: ${logId})?`)) {
            return;
        }

        try {
            const erpResponse = await fetch(`${PYTHON_API_URL}/api/pedido_erp_data/${pedidoId}`);
            
            if (erpResponse.status === 404) {
                 alert(`Pedido ${pedidoId} AINDA NÃO ENCONTRADO na base ERP. Possível erro de digitação.`);
                 return;
            }

            if (!erpResponse.ok) {
                throw new Error(`Erro HTTP: ${erpResponse.status}`);
            }
            
            const erpData = await erpResponse.json();
            const novosItens = erpData.sku;
            const novosVolumes = erpData.volumes;

            if (novosItens === 0 && novosVolumes === 0) {
                 alert(`Pedido ${pedidoId} encontrado no ERP, mas com 0 SKUs/Volumes. Não será atualizado.`);
                 return;
            }

            const updateResponse = await fetch(`${PYTHON_API_URL}/api/update_log_data`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    log_id: logId, 
                    itens: novosItens, 
                    sku_volumes: novosVolumes 
                }) 
            });

            if (updateResponse.ok) {
                alert(`✅ Pedido ${pedidoId} (Log ID: ${logId}) ATUALIZADO com ${novosItens} SKUs e ${novosVolumes} Volumes.`);
                await carregarPedidosPendentes();
            } else {
                 const result = await updateResponse.json();
                 alert(`Falha ao salvar a atualização no log: ${result.detail}`);
            }

        } catch (error) {
            alert(`Erro ao forçar a atualização do pedido: ${error.message}`);
        }
    }


    async function carregarPedidosPendentes() {
        listaPendentesDiv.innerHTML = '<p style="color:#121228;">Buscando pedidos com Itens = 0...</p>';

        try {
            const response = await fetch(`${PYTHON_API_URL}/api/pendentes`);
            if (!response.ok) throw new Error('Falha ao carregar lista de pendentes.');
            
            const data = await response.json();
            const pendentes = data.pendentes;
            
            if (pendentes.length === 0) {
                listaPendentesDiv.innerHTML = '<p style="color:#121228; font-weight:bold;">🎉 Nenhum pedido com dados zerados encontrado.</p>';
                return;
            }
            
            let html = '<table style="width:100%; font-size: 0.9em; text-align: left;"><thead><tr><th>ID Pedido</th><th>Log ID</th><th>Func. / Função</th><th>Data Log</th><th>Ação</th></tr></thead><tbody>';
            
            pendentes.forEach(p => {
                const dataFormatada = new Date(p.timestamp_registro).toLocaleDateString('pt-BR') + ' ' + new Date(p.timestamp_registro).toLocaleTimeString('pt-BR');
                
                html += `
                    <tr>
                        <td><strong>${p.pedido_id}</strong></td>
                        <td>${p.log_id}</td>
                        <td>${p.funcionario} (${p.funcao})</td>
                        <td>${dataFormatada}</td>
                        <td>
                            <button onclick="forcarAtualizacaoLog(${p.log_id}, ${p.pedido_id})" style="background-color: #2196f3; color: white; border: none; padding: 5px 8px; border-radius: 4px; cursor: pointer; font-size: 0.9em;">
                                Verificar ERP
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            html += '</tbody></table>';
            listaPendentesDiv.innerHTML = html;

        } catch (error) {
            listaPendentesDiv.innerHTML = `<p style="color:red;">❌ Erro: ${error.message}</p>`;
        }
    }

    // ===================================================
    // 🚨 NOVA LÓGICA: FUNÇÕES DO MODAL GERENCIAMENTO 🚨
    // ===================================================

    // Carrega e renderiza a lista de funcionários no modal
    async function carregarFuncionariosGerenciamento() {
        listaGerenciamentoDiv.innerHTML = '<p style="color:#121228;">Buscando lista completa...</p>';

        try {
            const response = await fetch(`${PYTHON_API_URL}/api/funcionarios`); 
            
            if (!response.ok) throw new Error('Falha ao carregar lista de funcionários.');
            
            const data = await response.json();
            
            // Re-mapeia TODOS os dados para ter certeza que está atualizado
            TODOS_FUNCIONARIOS = data.funcionarios.map(f => ({
                id: f.id,
                nome: f.nome,
                funcao: f.funcao_padrao,
                periodo: f.periodo_padrao,
                ativo: f.ativo
            }));
            
            // Re-popula o mapa de ativos para o autocomplete (caso tenha mudado status)
            const funcionariosAtivos = TODOS_FUNCIONARIOS.filter(f => f.ativo === 1);
            FUNCIONARIOS_MAP = funcionariosAtivos.reduce((map, obj) => {
                map[obj.nome] = { funcao: obj.funcao_padrao, periodo: obj.periodo_padrao }; 
                return map;
            }, {});


            if (TODOS_FUNCIONARIOS.length === 0) {
                listaGerenciamentoDiv.innerHTML = '<p style="color:#121228; font-weight:bold;">Nenhum funcionário cadastrado.</p>';
                return;
            }
            
            let html = '<table style="width:100%; font-size: 0.9em; text-align: left;"><thead><tr><th>ID</th><th>Nome</th><th>Função</th><th>Período</th><th>Status</th><th>Ação</th></tr></thead><tbody>';
            
            TODOS_FUNCIONARIOS.forEach(f => {
                const status = f.ativo == 1 ? '<span style="color: green; font-weight: bold;">Ativo</span>' : '<span style="color: red; font-weight: bold;">Inativo</span>';
                
                html += `
                    <tr>
                        <td>${f.id}</td>
                        <td><strong>${f.nome}</strong></td>
                        <td>${f.funcao}</td>
                        <td>${f.periodo}</td>
                        <td>${status}</td>
                        <td>
                            <button onclick="prepararEdicao(${f.id})" style="background-color: #2196f3; color: white; border: none; padding: 5px 8px; border-radius: 4px; cursor: pointer; font-size: 0.9em;">
                                Editar
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            html += '</tbody></table>';
            listaGerenciamentoDiv.innerHTML = html;

        } catch (error) {
            listaGerenciamentoDiv.innerHTML = `<p style="color:red;">❌ Erro ao carregar funcionários: ${error.message}</p>`;
        }
    }

    // Preenche o formulário de edição com os dados do funcionário
    function prepararEdicao(funcionarioId) {
        const func = TODOS_FUNCIONARIOS.find(f => f.id === funcionarioId);
        
        // 🚨 NOVO: MOSTRA O BLOCO DE DETALHES 🚨
        blocoDetalhesEdicao.style.display = 'block';

        if (func) {
            document.getElementById('edit-func-id').value = func.id;
            document.getElementById('edit-func-nome').value = func.nome;
            document.getElementById('edit-func-funcao').value = func.funcao;
            document.getElementById('edit-func-periodo').value = func.periodo;
            document.getElementById('edit-func-ativo').checked = (func.ativo == 1);
            
            // 🚨 NOVO: Rola o modal para o formulário de edição 🚨
            const formContainer = document.getElementById('form-editar-funcionario');
            if (formContainer) {
                formContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    }

    // Envia a atualização para a API

    formEditarFuncionario.addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const funcId = parseInt(document.getElementById('edit-func-id').value);
        const nome = document.getElementById('edit-func-nome').value.toUpperCase().trim();
        const funcao = document.getElementById('edit-func-funcao').value;
        const periodo = document.getElementById('edit-func-periodo').value;
        const ativo = document.getElementById('edit-func-ativo').checked ? 1 : 0;
        
        const updateData = {
            id: funcId,
            nome: nome,
            funcao_padrao: funcao,
            periodo_padrao: periodo,
            ativo: ativo
        };

        try {
            const response = await fetch(`${PYTHON_API_URL}/api/funcionario/${funcId}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(updateData)
            });
            
            const result = await response.json();

            if (response.ok) {
                alert(result.message);
                // 1. Recarrega a lista no modal (atualiza a tabela)
                await carregarFuncionariosGerenciamento(); 
                // 2. Recarrega a lista principal (datalist)
                await carregarFuncionarios(); 
                document.getElementById('form-editar-funcionario').reset();
                blocoDetalhesEdicao.style.display = 'none'; // 🚨 ESCONDE APÓS SALVAR 🚨
            } else {
                 alert(`Falha ao atualizar: ${result.detail || result.message}`);
            }

        } catch (error) {
            alert('Erro de conexão com a API Python durante a atualização.');
        }
    });


    // ===================================================
    // LISTENERS DE ABERTURA E FECHAMENTO DE MODAIS (INCLUINDO GERENCIAMENTO)
    // ===================================================

    // Movimentação
    btnMovimentarPedido.addEventListener('click', () => {
        modalMovimentacao.style.display = 'block';
    });

    closeMovModal.addEventListener('click', () => {
        modalMovimentacao.style.display = 'none';
        movResultadoDiv.style.display = 'none';
        movPedidoIdInput.value = '';
        MOV_LOG_ID = null; 
    });

    // Pedidos Pendentes
    btnVerificarPendentes.addEventListener('click', () => {
        modalPendentes.style.display = 'block';
        carregarPedidosPendentes();
    });

    closePendentesModal.addEventListener('click', () => {
        modalPendentes.style.display = 'none';
    });
    
    // 🚨 NOVO: GERENCIAR FUNCIONÁRIOS 🚨
    btnGerenciarFuncionarios.addEventListener('click', () => {
        modalGerenciar.style.display = 'block';
        blocoDetalhesEdicao.style.display = 'none'; // 🚨 ESCONDE O FORM AO ABRIR 🚨
        carregarFuncionariosGerenciamento();
    });

    closeGerenciarModal.addEventListener('click', () => {
        modalGerenciar.style.display = 'none';
        document.getElementById('form-editar-funcionario').reset(); // Limpa o formulário de edição
        blocoDetalhesEdicao.style.display = 'none'; // 🚨 ESCONDE AO FECHAR 🚨
    });


    // Listener para o botão de Busca dentro do modal
    btnBuscarPedido.addEventListener('click', (e) => {
        e.preventDefault();
        const pedidoId = movPedidoIdInput.value.trim();
        if (pedidoId) {
            buscarPedido(pedidoId);
        } else {
            alert("Digite o número do pedido.");
        }
    });


    window.addEventListener('click', (event) => {
        if (event.target == modalPendentes) {
            modalPendentes.style.display = 'none';
        }
        if (event.target == modalFuncionario) {
            modalFuncionario.style.display = 'none';
            document.getElementById('form-add-funcionario').reset();
        }
        // 🚨 NOVO: Fechar Gerenciamento 🚨
        if (event.target == modalGerenciar) {
            modalGerenciar.style.display = 'none';
            document.getElementById('form-editar-funcionario').reset();
            blocoDetalhesEdicao.style.display = 'none'; // 🚨 ESCONDE AO CLICAR FORA 🚨
        }
        // Listener para fechar o modal de movimentação ao clicar fora
        if (event.target == modalMovimentacao) {
            modalMovimentacao.style.display = 'none';
            movResultadoDiv.style.display = 'none';
            movPedidoIdInput.value = '';
            MOV_LOG_ID = null;
        }
    });


    // ===================================================
    // INICIALIZAÇÃO (MANTIDA)
    // ===================================================

    window.onload = function() {
        for(let i=0; i<15; i++){
            criarLinha();
        }
        
        carregarFuncionarios();
        setInterval(carregarFuncionarios, REFRESH_INTERVAL_MS);

        const primeiroCampo = document.querySelector('input[name^="registro["][name$="[funcionario]"]');
        if (primeiroCampo) primeiroCampo.focus();
    };
</script>

</body>
</html>
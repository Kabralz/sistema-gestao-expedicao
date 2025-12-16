<?php
// ENGENHEIRO DIGITAL - Diretório de Funções (PHP/MySQL - Versão 5.0 - DARK MODE)

// 1. DADOS DE CONEXÃO DO BANCO (Preencha aqui!)
$db_host = '127.0.0.1:3307';     // Host 
$db_user = 'root';   // Seu usuário do MySQL
$db_pass = '';     // Sua senha
$db_name = 'picking';     // Nome do seu banco de dados
$db_table = 'db_funcionarios'; // Nome da tabela

// 2. TENTA ESTABELECER A CONEXÃO
$conexao = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conexao->connect_error) {
    die("Falha na Conexão com o Banco de Dados: " . $conexao->connect_error);
}
$conexao->set_charset("utf8");

// 3. QUERY PRINCIPAL FOCADA
$sql_principal = "
    SELECT 
        nome, funcao_padrao, periodo_padrao, ativo
    FROM 
        {$db_table}
    WHERE 
        nome IS NOT NULL AND TRIM(nome) != '' AND ativo = 1
    ORDER BY 
        nome ASC"; 

$resultado = $conexao->query($sql_principal);
$employees_json = [];

if ($resultado && $resultado->num_rows > 0) {
    while($row = $resultado->fetch_assoc()) {
        $employees_json[] = [
            'nome' => $row['nome'],
            'funcao' => $row['funcao_padrao'],
            'periodo' => $row['periodo_padrao'],
            'ativo' => $row['ativo'] 
        ];
    }
}

$conexao->close();
?>

<!doctype html>
<html lang="pt-BR">
 <head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Colaboradores</title>
  
  <style>
    /* ------------------------------------------------------------------- */
    /* --- CSS - DARK MODE DEFINIÇÕES E AJUSTES --- */
    /* ------------------------------------------------------------------- */
    :root {
      /* CORES DARK MODE */
      --primary-dark: #3498db;        /* Azul de Destaque */
      --bg-main: #1e1e1e;             /* Fundo Principal Escuro */
      --bg-surface: #2c2c2c;          /* Fundo de Cards/Controles */
      --text-light: #f5f5f5;          /* Texto Principal Claro */
      --text-medium: #bbb;            /* Texto Secundário */
      --border-dark: #444;            /* Borda Escura */
      --copy-btn-bg: #2ecc71;         /* Verde para Cópia */
      --copy-btn-hover: #27ae60;
      
      /* CORES TOAST DE SUCESSO */
      --toast-bg: #2ecc71;
      --toast-text: #1e1e1e;
    }
    
    body {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      /* Background para dark mode */
      background: var(--bg-main); 
      background-size: cover; 
      min-height: 100vh; 
      padding: 2rem 1rem;
      color: var(--text-light);
    }

    * { box-sizing: border-box; }

    .container {
      max-width: 950px; 
      margin: 0 auto;
      background: var(--bg-surface); /* Fundo da box principal */
      border-radius: 16px;
      box-shadow: 0 15px 50px rgba(0, 0, 0, 0.5); /* Sombra mais escura */
      overflow: hidden;
    }

    .header {
      background: var(--primary-dark); /* Fundo do Header em azul de destaque */
      color: white;
      padding: 2rem;
      text-align: center;
      border-top-left-radius: 16px;
      border-top-right-radius: 16px;
    }

    .header h1 { margin: 0 0 0.5rem 0; font-size: 2.2rem; font-weight: 700; }
    .header p { margin: 0; font-size: 1.1rem; opacity: 0.9; }

    .controls {
      padding: 2rem;
      background: var(--bg-surface);
      border-bottom: 1px solid var(--border-dark);
    }

    .search-bar { width: 100%; display: flex; }

    .search-input {
      flex: 1; min-width: 250px; padding: 0.875rem 1rem 0.875rem 3rem;
      border: 1px solid var(--border-dark); 
      border-radius: 10px; font-size: 1rem;
      transition: all 0.2s; color: var(--text-light);
      background: var(--bg-main) url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="%233498db" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>') no-repeat 1rem center;
    }

    .search-input:focus {
      outline: none; border-color: var(--primary-dark);
      box-shadow: 0 0 0 4px rgba(52, 152, 219, 0.3); 
    }
    
    .content { padding: 2rem; }
    .table-container { 
      overflow-x: auto; 
      border-radius: 12px; 
      border: 1px solid var(--border-dark); 
    }

    table { width: 100%; border-collapse: collapse; background: var(--bg-surface); border: 1px solid var(--border-dark); }
    thead { background: #333; } /* Cabeçalho da tabela levemente mais escuro */

    th {
      padding: 0.8rem 1rem; 
      text-align: left; font-weight: 700; color: var(--text-medium);
      font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.05em;
      border: 1px solid var(--border-dark); 
    }

    td {
      padding: 0.8rem 1rem; 
      border: 1px solid var(--border-dark); 
      color: var(--text-light);
      vertical-align: middle; 
      font-size: 1rem;
    }
    
    /* EFEITO ZEBRA DARK */
    tbody tr:nth-child(even) { background-color: #333; } 
    tbody tr:hover { background: #444; } 
    
    th.small-cell, td.small-cell {
        width: 150px;
        text-align: center;
        white-space: nowrap;
        font-weight: 600;
        color: var(--primary-dark); /* Destaque em azul */
    }
    
    .btn-copy-name { 
        background: var(--copy-btn-bg); 
        color: white; 
        padding: 0.5rem 0.8rem; 
        border-radius: 6px; 
        font-weight: 600; 
        cursor: pointer; 
        transition: background 0.2s; 
        border: none; 
    }
    .btn-copy-name:hover { background: var(--copy-btn-hover); }
    
    .actions-cell {
        text-align: center;
        width: 120px;
    }

    .empty-state { text-align: center; padding: 4rem 2rem; color: var(--text-medium); }
    .empty-state h3 { color: var(--text-light); }
    
    /* TOAST AJUSTADO PARA SER MAIS VISÍVEL E CLARO */
    .toast {
    position: fixed; top: 10%; left: 50%; transform: translateX(-50%); 
    background: var(--toast-bg);
    color: var(--toast-text);
    padding: 1rem 1.5rem; border-radius: 8px; 
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.4);
    align-items: center; gap: 0.75rem;
    animation: fadeIn 0.3s ease; z-index: 10000;
    min-width: 250px; text-align: center;
    font-weight: 600;
    display: none;
    }
    
    /* Cor do ícone do Toast (Preto) */
    .toast svg {
        stroke: var(--toast-text);
    }
    
    .toast.show { display: flex; }
  </style>
 </head>
 <body>
  <div class="container">
   <div class="header">
    <h1 id="page-title">Colaboradores</h1>
    <p>Consulta rápida por Nome, Função ou Período. Use o botão para copiar o nome.</p>
   </div>
   <div class="controls">
    <div class="search-bar">
      <input type="text" id="search-input" class="search-input" placeholder="Buscar por Nome, Função ou Período (T1, T2, T3)...">
    </div>
   </div>
   <div class="content">
    <div class="table-container">
     <table id="employees-table">
      <thead>
       <tr>
        <th>Nome</th>
        <th class="small-cell">Função</th>
        <th class="small-cell">Período</th>
        <th class="actions-cell">Ações</th> 
       </tr>
      </thead>
      <tbody id="table-body">
      </tbody>
     </table>
    </div>
    <div id="empty-state" class="empty-state">
     <h3>Nenhum colaborador encontrado</h3>
     <p>Verifique o termo de busca.</p>
    </div>
   </div>
  </div>
  
  <div id="toast" class="toast">
   <svg width="24" height="24" viewbox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
   <span id="toast-message"></span>
  </div>

  <script>
    // ----------------------------------------------------
    // --- FUNÇÕES DE UTILITY GLOBAIS ---
    // ----------------------------------------------------

    function showToast(message) {
      const toast = document.getElementById('toast');
      const toastMessage = document.getElementById('toast-message');
      if (toast && toastMessage) {
        toastMessage.textContent = message;
        toast.classList.add('show');
        setTimeout(() => {
          toast.classList.remove('show');
        }, 3000);
      }
    }
    
    // Método de cópia antigo (Fallback)
    function fallbackCopyTextToClipboard(text, type) {
        const textArea = document.createElement("textarea");
        textArea.value = text;
        textArea.style.position = "fixed";
        textArea.style.top = 0;
        textArea.style.left = 0;
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();

        try {
            const successful = document.execCommand('copy');
            if (successful) {
                showToast(`${type} copiado com sucesso!`); 
            } else {
                showToast(`Erro ao copiar ${type} (Fallback falhou).`);
            }
        } catch (err) {
            console.error('Fallback: Falha ao copiar texto: ', err);
            showToast(`Erro grave ao copiar ${type}.`);
        }
        document.body.removeChild(textArea);
    }

    // FUNÇÃO DE CÓPIA DE NOME
    function copyName(content) {
        const cleanContent = content.trim(); 
        
        if (!cleanContent || cleanContent === '-') {
            showToast(`Nenhum nome para copiar`);
            return;
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(cleanContent).then(() => {
                showToast(`Nome (${cleanContent}) copiado com sucesso!`); 
            }).catch(err => {
                fallbackCopyTextToClipboard(cleanContent, 'Nome');
            });
        } else {
            fallbackCopyTextToClipboard(cleanContent, 'Nome');
        }
    }


    // ----------------------------------------------------
    // ** Lógica Principal (JS) **
    // ----------------------------------------------------
    document.addEventListener('DOMContentLoaded', function() {
        
        // 🚨 IMPORTANTE: Expõe a função para ser usada pelo onclick do HTML
        window.copyName = copyName; 

        let employees = <?php echo json_encode($employees_json); ?>; 
        let filteredEmployees = employees;
        
        
        function renderTable() {
            const tbody = document.getElementById('table-body');
            const emptyState = document.getElementById('empty-state');
            
            if (filteredEmployees.length === 0) {
                tbody.innerHTML = '';
                emptyState.style.display = 'block';
                return;
            }

            emptyState.style.display = 'none';
            tbody.innerHTML = filteredEmployees.map(emp => {
                const nome = emp.nome || '-';
                const funcao = emp.funcao || '-';
                const periodo = emp.periodo || '-';
                
                // Tratamento especial para o nome (escapa aspas simples para o JS)
                const safeName = nome.replace(/'/g, "\\'");

                return `
                    <tr>
                    <td>${nome}</td>
                    <td class="small-cell">${funcao}</td>     
                    <td class="small-cell">${periodo}</td>    
                    <td class="actions-cell">
                        <button title="Copiar nome completo" class="btn-copy-name" onclick="copyName('${safeName}')">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle;"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect> <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                        </button>
                    </td>
                    </tr>
                `;
            }).join('');
        }


        function filterEmployees() {
            const searchTerm = document.getElementById('search-input').value.toLowerCase();
            
            filteredEmployees = employees.filter(emp => {
                const searchableText = `${emp.nome} ${emp.funcao} ${emp.periodo}`.toLowerCase();
                return searchableText.includes(searchTerm);
            });
            
            renderTable();
        }


        // --- EVENT LISTENERS E INICIALIZAÇÃO ---

        renderTable(); 

        document.getElementById('search-input').addEventListener('input', filterEmployees);

    }); 
  </script>

  <script>
  setInterval(function() {
    location.reload(true); 
  }, 2100000);
  </script>
 </body>
</html>
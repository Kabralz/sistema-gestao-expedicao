<?php
// ===================================================================================
// CONFIGURAÇÃO E CONEXÃO COM O BANCO DE DADOS (AJUSTE ESTES DADOS!)
// ===================================================================================
$servername = "127.0.0.1:3307";
$username = "root";
$password = "";
$dbname = "picking";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Conexão com o banco falhou: " . $conn->connect_error);
}

// -----------------------------------------------------------------------------------
// QUERY PARA BUSCAR DADOS
// -----------------------------------------------------------------------------------
$sql = "SELECT 
    id, data_ocorrencia, tipo_falha, como_resolvido, setor_ocorreu, tipo_devolucao,
    tipo_oc, pedido, nfd, valor_devolucao, cod_cliente, rc, separador,
    conferente, autor_ocorrencia, descricao 
    FROM registro_ocorrencias ORDER BY id DESC";
$result = $conn->query($sql);

$conn->close();

$listas_json = json_encode([]);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <title>Registro de Ocorrências Dinâmico</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Tailwind e jQuery -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Visual do prototipo.txt -->
    <style>
        body {
            box-sizing: border-box;
            background-color: #0f172a;
            color: #e2e8f0;
            font-family: Inter, sans-serif;
        }
        .table-scrollable {
            overflow-x: auto;
            overflow-y: auto;
        }
        .table-cell-editable {
            cursor: text;
            transition: background-color 0.2s;
        }
        .table-cell-editable:hover {
            background-color: rgba(59, 130, 246, 0.1);
        }
        .table-cell-editing {
            background-color: rgba(59, 130, 246, 0.2);
            outline: 2px solid #3b82f6;
            outline-offset: -2px;
        }
        input.cell-input {
            width: 100%;
            height: 100%;
            background: transparent;
            border: none;
            outline: none;
            padding: 0.5rem;
            color: inherit;
            font-size: inherit;
        }
        .sticky-header {
            position: sticky;
            top: 0;
            z-index: 10;
        }
        th, td {
            padding: 0.32rem 0.25rem; /* Mais compacto */
            font-size: 0.89rem;       /* Fonte menor */
            text-align: left;
            border-bottom: 1px solid #374151;
            vertical-align: middle;
            word-break: break-word;
            line-height: 1.15;
        }
        th {
            background: #181c24;
            color: #38d9a9;
            font-size: 0.93rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 2px solid #374151;
            padding-top: 0.5rem;
            padding-bottom: 0.5rem;
            vertical-align: middle;
            text-align: center;
        }
        thead tr {
            border-radius: 12px 12px 0 0;
            overflow: hidden;
        }
        th:first-child {
            border-top-left-radius: 12px;
        }
        th:last-child {
            border-top-right-radius: 12px;
        }
        .table-scrollable {
            border-radius: 10px;
            overflow: hidden;
            background: #23283a;
            box-shadow: 0 4px 16px rgba(0,0,0,0.25);
        }
        tbody tr:nth-child(even) td {
            background: #263043;
        }
        tbody tr:nth-child(odd) td {
            background: #23283a;
        }
        tbody tr:hover td {
            background: #374151;
            color: #38d9a9;
        }
        .linha-vazia td {
            background: #23283a;
            color: #374151;
            height: 28px;
        }
        .btn-container button,
        #btnInserirLinha,
        #btnAjustarListas {
            font-family: inherit;
            font-size: 1rem;
            border-radius: 0.5rem;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            transition: all 0.2s;
        }
        #btnInserirLinha {
            background: #10b981;
            color: #fff;
            border: none;
        }
        #btnAjustarListas {
            background: #f59e0b;
            color: #000;
            border: none;
        }
        #btnInserirLinha:hover, #btnAjustarListas:hover {
            filter: brightness(1.1);
            scale: 1.03;
        }
        .search-bar {
            background: #334155;
            border-radius: 0.5rem;
            padding: 0.5rem 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .search-bar input {
            background: transparent;
            border: none;
            outline: none;
            color: #e2e8f0;
            font-size: 1rem;
            width: 16rem;
        }
        /* Destaque para inputs e selects ao editar */
        input[type="text"], input[type="number"], input[type="date"], select {
            background: #fff !important;
            color: #222 !important;
            border: 2px solid #38d9a9 !important;
            border-radius: 6px;
            font-size: 1rem;
            padding: 0.3rem 0.5rem;
            box-shadow: 0 2px 8px rgba(56,217,169,0.08);
        }

        input[type="text"]:focus, input[type="number"]:focus, input[type="date"]:focus, select:focus {
            outline: 2px solid #38d9a9;
            background: #f0fdfa !important;
        }

        #modalAjuste {
            background: rgba(15,23,42,0.95);
        }
        #modalAjuste .modal-content,
        #modalAjuste > div {
            background: #23283a;
            color: #e2e8f0;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.45);
            padding: 32px 24px 24px 24px;
            max-width: 650px;
            min-width: 340px;
        }
        #modalAjuste h2 {
            color: #38d9a9;
            font-size: 2rem;
            margin-bottom: 1.5rem;
            font-weight: 700;
        }
        #modalAjuste .close-modal,
        #fecharModal {
            position: absolute;
            right: 24px;
            top: 18px;
            font-size: 2.2rem;
            color: #38d9a9;
            cursor: pointer;
            font-weight: bold;
        }
        #painelAjuste {
            margin-top: 1.5rem;
        }
        #painelAjuste .gabarito-tabela {
            margin-top: 1.5rem;
            width: 100%;
        }
        #painelAjuste .gabarito-tabela.active {
            display: block;
        }
        #painelAjuste .gabarito-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.2rem;
        }
        #painelAjuste .gabarito-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #263043;
            border-radius: 8px;
            margin-bottom: 0.5rem;
            padding: 0.7rem 1rem;
            font-size: 1rem;
            word-break: break-word;
            min-height: 44px;
        }
        #painelAjuste .gabarito-item span {
            flex: 1;
            color: #e2e8f0;
        }
        #painelAjuste .btnDeletar {
            color: #fff;
            background: #ef4444;
            border: none;
            border-radius: 4px;
            padding: 0.3rem 0.8rem;
            cursor: pointer;
            font-size: 0.95em;
            font-weight: 600;
            transition: background 0.2s;
        }
        #painelAjuste .btnDeletar:hover {
            background: #b91c1c;
        }
        #painelAjuste .gabarito-tabela {
            display: none;
        }
        #painelAjuste .gabarito-tabela.active {
            display: block;
        }
        #painelAjuste .gabarito-select {
            margin-bottom: 1.2rem;
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        #painelAjuste select,
        #painelAjuste input[type="text"] {
            background: #fff;
            color: #222;
            border: 2px solid #38d9a9;
            border-radius: 6px;
            font-size: 1rem;
            padding: 0.3rem 0.5rem;
        }
        #painelAjuste button[type="button"], #painelAjuste .btnDeletar {
            background: #38d9a9;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 0.3rem 1rem;
            font-weight: 600;
            margin-left: 0.5rem;
            cursor: pointer;
            transition: background 0.2s;
        }
        #painelAjuste .btnDeletar {
            background: #ef4444;
            margin-left: 0;
            margin-top: 0.2rem;
            padding: 0.3rem 0.8rem;
            font-size: 0.95em;
        }
        #painelAjuste .btnDeletar:hover {
            background: #b91c1c;
        }
    </style>
    <style>@view-transition { navigation: auto; }</style>
</head>
<body class="h-full w-full">
    <div id="app-wrapper" class="h-full w-full overflow-auto">
        <div class="min-h-full w-full p-6">
            <!-- Header -->
            <div class="mb-6">
                <h1 id="page-title" class="text-3xl font-bold mb-6">Registro de Ocorrências Dinâmico</h1>
                <!-- Action Bar -->
                <div class="flex flex-wrap gap-4 items-center mb-4">
                    <button id="btnInserirLinha" class="flex items-center gap-2 px-6 py-3 rounded-lg font-semibold transition-all hover:scale-105 focus:outline-none focus:ring-2 focus:ring-offset-2">
                        <span>➕</span>
                        <span>Inserir Nova Linha de Ocorrência</span>
                    </button>
                    <button id="btnAjustarListas" class="flex items-center gap-2 px-6 py-3 rounded-lg font-semibold transition-all hover:scale-105 focus:outline-none focus:ring-2 focus:ring-offset-2">
                        <span>⚙️</span>
                        <span>Ajustar Listas de Gabarito</span>
                    </button>
                    <div class="ml-auto flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-700">
                        <span class="text-xl">🔍</span>
                        <input id="search-input" type="text" placeholder="Buscar ocorrências..." class="bg-transparent border-none outline-none text-sm w-64 text-white">
                    </div>
                </div>
            </div>
            <!-- Table Container -->
            <div class="table-scrollable rounded-lg shadow-2xl" style="max-height: calc(100vh - 250px);">
                <table class="w-full border-collapse" id="tabelaOcorrencias">
                    <thead class="sticky-header">
                        <tr>
                            <th>ID</th>
                            <th>Data</th>
                            <th>Tipo de Falha</th>
                            <th>Como Foi Resolvido</th>
                            <th>Setor Onde Ocorreu a Falha</th>
                            <th>Tipo de Devolução</th>
                            <th>Tipo OC</th>
                            <th>Pedido</th>
                            <th>NFD</th>
                            <th>Valor[Dev]</th>
                            <th>Cód. Clientes</th>
                            <th>RC</th>
                            <th>Separador</th>
                            <th>Conferente</th>
                            <th>Autor da Ocorrência</th>
                            <th>Descrição/Observações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Exibe as linhas existentes (com coluna ID)
                        if ($result->num_rows > 0) {
                            while($row = $result->fetch_assoc()) {
                                echo "<tr data-id='{$row['id']}'>";
                                echo "<td>{$row['id']}</td>";
                                echo "<td class='editable' data-campo='data_ocorrencia'>{$row['data_ocorrencia']}</td>";
                                echo "<td class='editable gabarito' data-campo='tipo_falha'>{$row['tipo_falha']}</td>";
                                echo "<td class='editable gabarito' data-campo='como_resolvido'>{$row['como_resolvido']}</td>";
                                echo "<td class='editable gabarito' data-campo='setor_ocorreu'>{$row['setor_ocorreu']}</td>";
                                echo "<td class='editable gabarito' data-campo='tipo_devolucao'>{$row['tipo_devolucao']}</td>";
                                echo "<td class='editable gabarito' data-campo='tipo_oc'>{$row['tipo_oc']}</td>";
                                echo "<td class='editable' data-campo='pedido'>{$row['pedido']}</td>";
                                echo "<td class='editable' data-campo='nfd'>{$row['nfd']}</td>";
                                echo "<td class='editable' data-campo='valor_devolucao'>{$row['valor_devolucao']}</td>";
                                echo "<td class='editable' data-campo='cod_cliente'>{$row['cod_cliente']}</td>";
                                echo "<td class='editable' data-campo='rc'>{$row['rc']}</td>";
                                echo "<td class='editable funcionario' data-campo='separador'>{$row['separador']}</td>";
                                echo "<td class='editable funcionario' data-campo='conferente'>{$row['conferente']}</td>";
                                echo "<td class='editable gabarito' data-campo='autor_ocorrencia'>{$row['autor_ocorrencia']}</td>";
                                echo "<td class='editable' data-campo='descricao'>{$row['descricao']}</td>";
                                echo "</tr>";
                            }
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal Ajuste de Listas de Validação -->
<div id="modalAjuste" class="hidden fixed z-50 inset-0 bg-black bg-opacity-40 overflow-auto">
    <div class="bg-slate-800 text-white m-auto mt-[5%] p-6 border border-slate-600 rounded-lg w-11/12 md:w-4/5 lg:w-3/5 relative">
        <h2 class="text-2xl font-bold mb-4 text-emerald-400">Ajuste de Listas de Validação</h2>
        <button id="fecharModal" type="button"
            style="position:absolute;top:18px;right:24px;font-size:2.2rem;color:#38d9a9;font-weight:bold;cursor:pointer;background:none;border:none;line-height:1;">
            &times;
        </button>
        <div id="painelAjuste" class="mt-4">Carregando listas...</div>
        <div id="msgAjuste" class="mt-4 text-blue-400"></div>
    </div>
</div>

<!-- Seu JS permanece igual, não remova nenhuma lógica -->
<script>
    // Armazena todas as listas de validação (Gabaritos e Funcionários)
    var gabaritoListas = {}; 
    var listaFuncionarios = []; 
    var listasCarregadas = false;

    // Função única para carregar TODAS as listas de validação (Gabaritos e Funcionários)
    function carregarTodasAsListas() {
        // Chamadas AJAX para as duas APIs que puxam do BD
        var deferredGabaritos = $.getJSON('api_gabaritos.php'); // Puxa listas (Tipo Falha, Autor, etc.)
        var deferredFuncionarios = $.getJSON('api_funcionarios.php'); // Puxa nomes (Separador, Conferente)

        // Usa $.when para esperar as duas chamadas terminarem antes de continuar
        $.when(deferredGabaritos, deferredFuncionarios).done(function(gabaritoResponse, funcionarioResponse) {
            
            // 1. Armazena os dados do Gabarito
            if (gabaritoResponse[0]) {
                gabaritoListas = gabaritoResponse[0];
            }
            
            // 2. Armazena a lista de Funcionários
            if (funcionarioResponse[0] && !funcionarioResponse[0].error) {
                listaFuncionarios = funcionarioResponse[0];
            } else {
                console.error("Erro ao carregar lista de colaboradores.");
            }
            
            listasCarregadas = true;
            console.log("Todas as listas de validação foram carregadas do banco de dados!");

        }).fail(function() {
            console.error("Falha ao carregar as listas de validação. Verifique api_gabaritos.php e api_funcionarios.php.");
            alert("ERRO: Não foi possível carregar as listas de validação do banco. A edição com SELECTs pode falhar.");
        });
    }

    // Função de salvamento (UPDATE ou INSERT) via AJAX - Com correção do Timeout
    function salvarViaAjax(idRegistro, campo, valorNovo, celula, metodo) {
        // Limpa mensagens
        $('#mensagem').text('');

        $.ajax({
            url: 'salvar_ocorrencia.php',
            method: 'POST',
            data: {
                id: idRegistro,
                campo: campo,
                valor: valorNovo,
                metodo: metodo
            },
            success: function(response) {
                if (response.startsWith('sucesso')) {
                    if (metodo === 'INSERT') {
                        var novoId = response.split(':')[1];
                        celula.parent().attr('data-id', novoId);
                        celula.parent().find('td.id-placeholder').text(novoId).removeClass('id-placeholder');
                        $('#mensagem').text('Nova linha inserida com sucesso!').fadeIn().delay(3000).fadeOut();
                        $('#mensagem-vazia').remove();
                    }
                    celula.text(valorNovo);
                } else {
                    alert('Erro ao salvar: ' + response);
                    celula.text(celula.data('valor-antigo'));
                }
            },
            error: function() {
                alert('Erro na comunicação com o servidor!');
                celula.text(celula.data('valor-antigo'));
            }
        });
    }

    $(document).ready(function() {
        carregarTodasAsListas(); // <-- CHAMA A FUNÇÃO DE CARREGAMENTO NO INÍCIO
        
        // =========================================================================
        // LÓGICA DE EDIÇÃO EM TEMPO REAL (UPDATE/INSERT)
        // =========================================================================
        $('#tabelaOcorrencias').on('click', '.editable', function() {
            var celula = $(this);
            var campo = celula.data('campo');
            var valorAntigo = celula.text().trim();
            var idRegistro = celula.parent().data('id');

            if (!idRegistro) idRegistro = 0;
            
            if (celula.find('input, select').length > 0) return; 

            if (!listasCarregadas && (celula.hasClass('gabarito') || celula.hasClass('funcionario'))) {
                alert("Aguarde um momento, as listas de validação estão sendo carregadas...");
                return;
            }

            celula.data('valor-antigo', valorAntigo); 

            var listaDeOpcoes = [];
            var isSelect = false;
            var tipoInput = 'text';
            
            // 1. Decide o tipo de campo e carrega a lista
            if (campo === 'separador' || campo === 'conferente') {
                listaDeOpcoes = listaFuncionarios; 
                isSelect = true;
            } else if (celula.hasClass('gabarito')) {
                // Mapeia o campo HTML/BD para a chave do gabarito JSON
                var chaveGabarito = campo.toUpperCase(); 
                
                // Tratamento especial para as chaves conforme o BD (Passo 1)
                if (campo === 'autor_ocorrencia') chaveGabarito = 'AUTOR_OCORRENCIA';
                if (campo === 'setor_ocorreu') chaveGabarito = 'SETOR_OCORREU';
                if (campo === 'tipo_falha') chaveGabarito = 'TIPO_FALHA';
                if (campo === 'como_resolvido') chaveGabarito = 'COMO_RESOLVIDO';
                if (campo === 'tipo_devolucao') chaveGabarito = 'TIPO_DEVOLUCAO';
                if (campo === 'tipo_oc') chaveGabarito = 'TIPO_OC';
                
                if (gabaritoListas[chaveGabarito]) {
                     listaDeOpcoes = gabaritoListas[chaveGabarito];
                     isSelect = true;
                }
            } else if (campo === 'data_ocorrencia') {
                tipoInput = 'date';
            } else if (campo === 'valor_devolucao' || campo === 'pedido' || campo === 'cod_cliente') {
                tipoInput = 'number';
            }


            // 2. Cria o elemento de edição (SELECT ou INPUT)
            var elementoEdicao;

            if (isSelect) {
                elementoEdicao = $('<select></select>');
                elementoEdicao.append($('<option value="">Selecione...</option>'));
                
                $.each(listaDeOpcoes, function(i, item) {
                    var itemTrim = (item || "").trim();
                    var isSelected = (itemTrim === valorAntigo);

                    elementoEdicao.append($('<option>', { 
                        value: itemTrim,
                        text: itemTrim,
                        selected: isSelected
                    }));
                });
                
            } else {
                elementoEdicao = $('<input type="' + tipoInput + '" value="' + valorAntigo + '">');
            }
            
            // 3. Substitui, foca e define o salvamento (blur/change)
            celula.html(elementoEdicao);
            elementoEdicao.focus();

            // 4. Salva quando o foco sai ou o valor muda
            elementoEdicao.on('blur change', function(event) {
                var valorNovo = $(this).val();
                var isSelectEvent = isSelect && event.type === 'change';
                var isInputEvent = !isSelect && event.type === 'blur';

                // Só dispara se for o evento correto (change para select, blur para input)
                if (!(isSelectEvent || isInputEvent)) return;

                // Se o valor não mudou, volta o texto e sai
                if (valorNovo.trim() === valorAntigo.trim()) {
                    celula.text(valorAntigo);
                    return;
                }
                
                var metodo = (idRegistro > 0) ? 'UPDATE' : 'INSERT';
                
                // AJUSTE CRÍTICO: Usa setTimeout para corrigir o bug de INSERT duplicado
                setTimeout(function() {
                     salvarViaAjax(idRegistro, campo, valorNovo, celula, metodo);
                }, 10);
            });
        });
        
        // =========================================================================
        // LÓGICA DO BOTÃO INSERIR NOVA LINHA (INSERÇÃO NO TOPO)
        // =========================================================================
        $('#btnInserirLinha').on('click', function() {
            // Cria uma nova linha sem ID e sem a palavra "Novo"
            var novaLinhaHtml = `
                <tr data-id="0">
                    <td class="id-placeholder"></td>
                    <td class='editable' data-campo='data_ocorrencia'></td>
                    <td class='editable gabarito' data-campo='tipo_falha'></td>
                    <td class='editable gabarito' data-campo='como_resolvido'></td>
                    <td class='editable gabarito' data-campo='setor_ocorreu'></td>
                    <td class='editable gabarito' data-campo='tipo_devolucao'></td>
                    <td class='editable gabarito' data-campo='tipo_oc'></td>
                    <td class='editable' data-campo='pedido'></td>
                    <td class='editable' data-campo='nfd'></td>
                    <td class='editable' data-campo='valor_devolucao'></td>
                    <td class='editable' data-campo='cod_cliente'></td>
                    <td class='editable' data-campo='rc'></td>
                    <td class='editable funcionario' data-campo='separador'></td>
                    <td class='editable funcionario' data-campo='conferente'></td>
                    <td class='editable gabarito' data-campo='autor_ocorrencia'></td>
                    <td class='editable' data-campo='descricao'></td>
                </tr>
            `;
            $('#tabelaOcorrencias tbody').prepend(novaLinhaHtml);

            var $novaLinha = $('#tabelaOcorrencias tbody tr').first();
            $('html, body').animate({
                scrollTop: $novaLinha.offset().top - 50 
            }, 500, function() {
                $novaLinha.find('td.editable:first').click();
            });
        });
        
        // =========================================================================
        // LÓGICA DO CRUD DE GABARITOS (Modal)
        // =========================================================================

        $('#fecharModal').on('click', function() {
            $('#modalAjuste').addClass('hidden').hide();
            carregarTodasAsListas();
        });

        $('#btnAjustarListas').on('click', function() {
            $('#modalAjuste').removeClass('hidden').show();
            carregarPainelAjuste();
        });

                        function carregarPainelAjuste() {
                    $('#painelAjuste').html(`
                        <div class="gabarito-select" style="margin-bottom:1.2rem; display:flex; gap:1rem; align-items:center;">
                            <label for="filtroGabarito" style="font-weight:600;">Escolha a lista:</label>
                            <select id="filtroGabarito">
                                <option value="">Selecione...</option>
                                <option value="TIPO_FALHA">Tipo de Falha</option>
                                <option value="COMO_RESOLVIDO">Como Foi Resolvido</option>
                                <option value="SETOR_OCORREU">Setor Onde Ocorreu</option>
                                <option value="AUTOR_OCORRENCIA">Autor da Ocorrência</option>
                                <option value="TIPO_OC">Tipo OC</option>
                                <option value="TIPO_DEVOLUCAO">Tipo Devolução</option>
                            </select>
                            <input type="text" id="novoValorGabarito" placeholder="Novo Valor da Opção" style="width: 40%;">
                            <button type="button" id="btnAdicionarGabarito">➕ Adicionar</button>
                        </div>
                        <div id="msgAjuste" class="mt-2 text-blue-400"></div>
                        <div id="gabaritoTabelas"></div>
                    `);
                
                    $('#filtroGabarito').on('change', function() {
                        var tipo = $(this).val();
                        if (!tipo) {
                            $('#gabaritoTabelas').html('');
                            return;
                        }
                        $('#gabaritoTabelas').html('<div style="text-align:center;padding:2rem;">Carregando...</div>');
                        $.post('crud_gabaritos.php', { acao: 'ler', tipo: tipo }, function(response) {
                            if (response.sucesso) {
                                var html = `<div class="gabarito-tabela active"><h4 style="margin-bottom:1rem;">${tipo.replace(/_/g,' ')} (${response.dados.length} itens)</h4>
                <div class="gabarito-grid">`;
                                $.each(response.dados, function(i, item) {
                                    html += `<div class="gabarito-item" data-id="${item.id}">
                    <span>${item.valor}</span>
                    <button class="btnDeletar" data-id="${item.id}">Excluir</button>
                </div>`;
                                });
                                html += '</div></div>';
                                $('#gabaritoTabelas').html(html);
                            } else {
                                $('#gabaritoTabelas').html('<div style="color:red;">Erro ao carregar dados: '+response.mensagem+'</div>');
                            }
                        }, 'json');
                    });
                
                    $('#btnAdicionarGabarito').on('click', function() {
                        var tipo = $('#filtroGabarito').val();
                        var valor = $('#novoValorGabarito').val().toUpperCase().trim();
                        if (!tipo || !valor) {
                            $('#msgAjuste').text('Selecione a lista e digite o valor!').css('color', 'red');
                            return;
                        }
                        $.post('crud_gabaritos.php', { acao: 'inserir', tipo: tipo, valor: valor }, function(response) {
                            if (response.sucesso) {
                                $('#msgAjuste').text(response.mensagem).css('color', 'green');
                                $('#novoValorGabarito').val('');
                                $('#filtroGabarito').trigger('change');
                            } else {
                                $('#msgAjuste').text(response.mensagem).css('color', 'red');
                            }
                        }, 'json');
                    });
                
                    $(document).off('click', '.btnDeletar').on('click', '.btnDeletar', function() {
                        var id = $(this).data('id');
                        if (confirm('Tem certeza que deseja excluir esta opção?')) {
                            $.post('crud_gabaritos.php', { acao: 'deletar', id: id }, function(response) {
                                if (response.sucesso) {
                                    $('#msgAjuste').text(response.mensagem).css('color', 'green');
                                    $('#filtroGabarito').trigger('change');
                                } else {
                                    $('#msgAjuste').text(response.mensagem).css('color', 'red');
                                }
                            }, 'json');
                        }
                    });
                }

        // Lógica para INSERÇÃO
        $(document).on('submit', '#formInserirGabarito', function(e) {
            e.preventDefault();
            var tipo = $(this).find('select[name="tipo"]').val();
            var valor = $(this).find('input[name="valor"]').val().toUpperCase().trim(); 

            if (!tipo || !valor) {
                $('#msgAjuste').text('Preencha o tipo e o valor!').css('color', 'red');
                return;
            }

            $.post('crud_gabaritos.php', { acao: 'inserir', tipo: tipo, valor: valor }, function(response) {
                if (response.sucesso) {
                    $('#msgAjuste').text(response.mensagem).css('color', 'green');
                    $('#formInserirGabarito')[0].reset(); 
                    carregarPainelAjuste(); // Recarrega a lista para mostrar o novo item
                } else {
                    $('#msgAjuste').text(response.mensagem).css('color', 'red');
                }
            }, 'json');
        });

        // Lógica para DELEÇÃO (usando delegação de eventos)
        $(document).on('click', '.btnDeletar', function() {
            var id = $(this).data('id');
            if (confirm('Tem certeza que deseja excluir esta opção?')) {
                $.post('crud_gabaritos.php', { acao: 'deletar', id: id }, function(response) {
                    if (response.sucesso) {
                        $('#msgAjuste').text(response.mensagem).css('color', 'green');
                        carregarPainelAjuste(); // Recarrega a lista
                    } else {
                        $('#msgAjuste').text(response.mensagem).css('color', 'red');
                    }
                }, 'json');
            }
        });

        // Movimentação por setas direcionais: sempre fecha o campo de edição e restaura o valor original antes de abrir o próximo
$(document).on('keydown', 'input, select', function(e) {
    var $cell = $(this).closest('td');
    var $row = $cell.parent();
    var colIdx = $cell.index();
    var $tbody = $row.closest('tbody');
    var $visibleRows = $tbody.find('tr:visible');
    var rowIdx = $visibleRows.index($row);
    var nextCell;

    function findNextEditableCell(rowIdx, colIdx, dir) {
        var $nextRow = $visibleRows.eq(rowIdx + (dir === 'down' ? 1 : dir === 'up' ? -1 : 0));
        var nextCol = colIdx + (dir === 'right' ? 1 : dir === 'left' ? -1 : 0);
        if ($nextRow.length && nextCol >= 0 && nextCol < $nextRow.find('td').length) {
            var $candidate = $nextRow.find('td').eq(nextCol);
            if ($candidate.hasClass('editable')) return $candidate;
        }
        return null;
    }

    if (["ArrowRight", "ArrowLeft", "ArrowDown", "ArrowUp"].includes(e.key)) {
        if (e.key === "ArrowRight") nextCell = findNextEditableCell(rowIdx, colIdx, 'right');
        if (e.key === "ArrowLeft")  nextCell = findNextEditableCell(rowIdx, colIdx, 'left');
        if (e.key === "ArrowDown")  nextCell = findNextEditableCell(rowIdx, colIdx, 'down');
        if (e.key === "ArrowUp")    nextCell = findNextEditableCell(rowIdx, colIdx, 'up');

        if (nextCell && nextCell.length) {
            // Restaura o valor original da célula atual antes de abrir o próximo
            var valorAntigo = $cell.data('valor-antigo');
            if (typeof valorAntigo !== "undefined") {
                $cell.text(valorAntigo);
            } else {
                $cell.text($cell.find('input,select').val() || "");
            }
            setTimeout(function() {
                nextCell.click();
            }, 10);
            e.preventDefault();
        }
    }
});

// Filtro dinâmico na tabela
$('#search-input').on('input', function() {
    var termo = $(this).val().toLowerCase();
    $('#tabelaOcorrencias tbody tr').each(function() {
        var texto = $(this).text().toLowerCase();
        $(this).toggle(texto.indexOf(termo) !== -1);
    });
});
    });

</script>

</body>
</html>
<?php
// Define o cabeçalho para dizer ao navegador que o conteúdo é JSON
header('Content-Type: application/json');

// ===================================================================================
// CONFIGURAÇÃO E CONEXÃO COM O BANCO DE DADOS (AJUSTE ESTES DADOS!)
// ===================================================================================
$servername = "127.0.0.1:3307";
$username = "root";
$password = "";
$dbname = "picking";

// Estabelece a conexão
$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Conexão com o banco falhou.']);
    exit();
}

// -----------------------------------------------------------------------------------
// PREPARAÇÃO DE VARIÁVEIS
// -----------------------------------------------------------------------------------
// Sanitiza as entradas POST para evitar problemas de segurança
$acao = $_POST['acao'] ?? '';
$tipo = $_POST['tipo'] ?? '';
$valor = $_POST['valor'] ?? '';
$id = $_POST['id'] ?? null;

$response = ['sucesso' => false, 'mensagem' => 'Ação inválida ou parâmetros faltando.'];

// ===================================================================================
// LÓGICA DO CRUD (CREATE, READ, DELETE)
// ===================================================================================

// READ (Ler itens de uma lista específica) -- DEVE VIR PRIMEIRO!
if ($acao === 'ler' && $tipo) {
    $stmt = $conn->prepare("SELECT id, valor FROM listas_gabarito WHERE tipo = ? ORDER BY valor ASC");
    $stmt->bind_param("s", $tipo);
    $stmt->execute();
    $result = $stmt->get_result();
    $dados = [];
    while ($row = $result->fetch_assoc()) {
        $dados[] = ['id' => $row['id'], 'valor' => $row['valor']];
    }
    echo json_encode(['sucesso' => true, 'dados' => $dados]);
    $stmt->close();
    $conn->close();
    exit();

// READ (Ler todas as listas para exibição)
} elseif ($acao === 'ler') {
    $sql = "SELECT id, tipo, valor FROM listas_gabarito ORDER BY tipo, valor ASC";
    $result = $conn->query($sql);
    
    $dados = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $dados[] = $row;
        }
        $response = ['sucesso' => true, 'dados' => $dados];
    } else {
        $response['mensagem'] = 'Erro ao consultar a tabela listas_gabarito.';
    }

// CREATE (Inserir novo item na lista)
} elseif ($acao === 'inserir' && $tipo && $valor) {
    // Garante que o valor seja salvo em MAIÚSCULAS e limpo
    $valor_upper = strtoupper(trim($valor));
    
    $stmt = $conn->prepare("INSERT INTO listas_gabarito (tipo, valor) VALUES (?, ?)");
    $stmt->bind_param("ss", $tipo, $valor_upper);
    
    if ($stmt->execute()) {
        $response = ['sucesso' => true, 'id' => $conn->insert_id, 'mensagem' => 'Opção inserida com sucesso!'];
    } else {
        // Erro 1062 é o erro de chave duplicada (UNIQUE KEY)
        if ($conn->errno === 1062) {
             $response['mensagem'] = 'Erro: Esta opção já existe para o tipo selecionado.';
        } else {
            $response['mensagem'] = 'Erro ao inserir: ' . $stmt->error;
        }
    }
    $stmt->close();

// DELETE (Remover um item da lista)
} elseif ($acao === 'deletar' && $id) {
    $stmt = $conn->prepare("DELETE FROM listas_gabarito WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $response = ['sucesso' => true, 'mensagem' => 'Opção deletada com sucesso.'];
    } else {
        $response['mensagem'] = 'Erro ao deletar: ' . $stmt->error;
    }
    $stmt->close();
}

// ===================================================================================
// FINALIZAÇÃO
// ===================================================================================
echo json_encode($response);
$conn->close();
?>
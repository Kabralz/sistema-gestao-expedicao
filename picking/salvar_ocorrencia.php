<?php
// Define o cabeçalho como texto puro
header('Content-Type: text/plain');

// ===================================================================================
// CONFIGURAÇÃO E CONEXÃO COM O BANCO DE DADOS (AJUSTE ESTES DADOS!)
// ===================================================================================
$servername = "127.0.0.1:3307";
$username = "root";
$password = "";
$dbname = "picking";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Conexão falhou: " . $conn->connect_error);
}

// ===================================================================================
// VALIDAÇÃO DOS DADOS ENVIADOS
// ===================================================================================
if (!isset($_POST['id']) || !isset($_POST['campo']) || !isset($_POST['valor']) || !isset($_POST['metodo'])) {
    die("Dados incompletos.");
}

$id = (int)$_POST['id'];
$campo = $_POST['campo'];
$valor = $_POST['valor'];
$metodo = $_POST['metodo'];

// Lista BRANCA: APENAS estes campos podem ser atualizados ou inseridos. ESSENCIAL PARA SEGURANÇA!
$campos_permitidos = ['data_ocorrencia', 'tipo_falha', 'como_resolvido', 'setor_ocorreu', 'tipo_devolucao', 'tipo_oc', 'pedido', 'nfd', 'valor_devolucao', 'cod_cliente', 'rc', 'separador', 'conferente', 'autor_ocorrencia', 'descricao'];

if (!in_array($campo, $campos_permitidos)) {
    die("Campo inválido ou não permitido.");
}

// ===================================================================================
// EXECUÇÃO DO SQL
// ===================================================================================

if ($metodo === 'UPDATE') {
    // ------------------------------------
    // LÓGICA DE UPDATE (Edição de linha existente)
    // ------------------------------------
    $stmt = $conn->prepare("UPDATE registro_ocorrencias SET {$campo} = ? WHERE id = ?");
    // O tipo 's' é string, 'i' é inteiro. Assume que a maioria é string para ser seguro.
    $stmt->bind_param("si", $valor, $id);

    if ($stmt->execute()) {
        echo "sucesso:{$id}"; // Retorna o ID do registro
    } else {
        echo "Erro ao atualizar: " . $stmt->error;
    }
    $stmt->close();

} elseif ($metodo === 'INSERT') {
    // ------------------------------------
    // LÓGICA DE INSERT (Primeiro preenchimento de uma nova linha)
    // ------------------------------------
    // Cria o SQL de INSERT. Só insere o campo que foi alterado.
    $stmt = $conn->prepare("INSERT INTO registro_ocorrencias ({$campo}) VALUES (?)");
    $stmt->bind_param("s", $valor);

    if ($stmt->execute()) {
        $novo_id = $conn->insert_id; // Pega o ID que acabou de ser gerado
        echo "sucesso:{$novo_id}"; 
    } else {
        echo "Erro ao inserir: " . $stmt->error;
    }
    $stmt->close();
} else {
    die("Método inválido.");
}

$conn->close();
?>
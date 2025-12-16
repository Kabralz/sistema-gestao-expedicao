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

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    // Retorna um JSON de erro em caso de falha de conexão
    http_response_code(500);
    echo json_encode(['error' => 'Conexão com o banco falhou: ' . $conn->connect_error]);
    exit();
}

// -----------------------------------------------------------------------------------
// QUERY PARA BUSCAR NOMES ÚNICOS E ATIVOS
// -----------------------------------------------------------------------------------
// 1. SELECT DISTINCT: Garante que cada nome apareça apenas uma vez.
// 2. WHERE ativo = 1: Puxa apenas os funcionários que estão ativos.
// 3. ORDER BY nome ASC: Deixa a lista em ordem alfabética (melhor para o SELECT).
$sql = "SELECT DISTINCT nome FROM db_funcionarios WHERE ativo = 1 ORDER BY nome ASC";
$result = $conn->query($sql);

$nomes_funcionarios = [];
if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        // Adiciona apenas o valor do nome no array
        $nomes_funcionarios[] = $row['nome'];
    }
}

// -----------------------------------------------------------------------------------
// RETORNA O RESULTADO EM FORMATO JSON
// -----------------------------------------------------------------------------------
echo json_encode($nomes_funcionarios);

$conn->close();
?>
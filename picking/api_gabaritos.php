<?php
// api_gabaritos.php

header('Content-Type: application/json');

// ===================================================================================
// CONFIGURAÇÃO E CONEXÃO COM O BANCO DE DADOS (AJUSTE ESTES DADOS!)
// ===================================================================================
$servername = "127.0.0.1:3307";
$username = "root"; // <<<< AJUSTAR
$password = "";  // <<<< AJUSTAR
$dbname = "picking";      // <<<< AJUSTAR 

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Conexão com o banco falhou.']);
    exit();
}

// -----------------------------------------------------------------------------------
// QUERY PARA BUSCAR TODOS OS GABARITOS
// -----------------------------------------------------------------------------------
$sql = "SELECT tipo, valor FROM listas_gabarito ORDER BY tipo, valor ASC";
$result = $conn->query($sql);

$gabaritos = [];
if ($result->num_rows > 0) {
    // Organiza os dados no formato: {"TIPO_FALHA": ["Opcao 1", "Opcao 2"], "AUTOR_OCORRENCIA": ["Nome A", "Nome B"]}
    while($row = $result->fetch_assoc()) {
        $tipo = $row['tipo'];
        $gabaritos[$tipo][] = $row['valor'];
    }
}

// -----------------------------------------------------------------------------------
// RETORNA O RESULTADO EM FORMATO JSON
// -----------------------------------------------------------------------------------
echo json_encode($gabaritos);

$conn->close();
?>
<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ===============================================
// 1. CONFIGURAÇÕES E PROTEÇÃO DE ACESSO
// ===============================================
// 🚨 ATENÇÃO: Substitua 'SUA_SENHA_AQUI' pela senha real do seu MariaDB.
$DB_CONFIG = ['host' => '127.0.0.1', 'port' => 3307, 'user' => 'root', 'pass' => '', 'name' => 'picking']; 
$usuario_logado = $_SESSION['usuario'] ?? null;
$mensagem = '';
$erro = false;

// Redireciona se não estiver logado
if (!isset($_SESSION['logado'])) {
    header('Location: login.php');
    exit();
}

// Função para registrar auditoria (a mesma usada no login.php)
function registrar_auditoria($db, $usuario, $acao, $detalhes = '') {
    $stmt = $db->prepare("INSERT INTO log_acesso_auditoria (usuario, acao, detalhes) VALUES (?, ?, ?)");
    $stmt->execute([$usuario, $acao, $detalhes]);
}


// ===============================================
// 2. LÓGICA DE TROCA DE SENHA
// ===============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nova_senha = $_POST['nova_senha'];
    $confirma_senha = $_POST['confirma_senha'];

    if ($nova_senha !== $confirma_senha) {
        $mensagem = 'As senhas não coincidem.';
        $erro = true;
    } elseif (strlen($nova_senha) < 5) {
        $mensagem = 'A senha deve ter pelo menos 5 caracteres.';
        $erro = true;
    } else {
        try {
            // Conexão com o banco
            $db = new PDO("mysql:host={$DB_CONFIG['host']};port={$DB_CONFIG['port']};dbname={$DB_CONFIG['name']};charset=utf8", $DB_CONFIG['user'], $DB_CONFIG['pass']);
            
            // Criptografa a nova senha antes de salvar
            $senha_hash = password_hash($nova_senha, PASSWORD_DEFAULT);
            
            // Atualiza o hash da senha e desativa a flag de 'primeiro_acesso'
            $stmt = $db->prepare("UPDATE usuarios SET senha_hash = ?, primeiro_acesso = FALSE WHERE usuario = ?");
            $stmt->execute([$senha_hash, $usuario_logado]);
            
            registrar_auditoria($db, $usuario_logado, 'TROCA_SENHA_SUCESSO', 'Senha alterada no primeiro acesso.');

            $mensagem = 'Senha alterada com sucesso! Você será redirecionado para o formulário de lançamentos.';
            $erro = false;
            
            // Redireciona automaticamente após 3 segundos
            header('Refresh: 3; URL=inserir_dados.php');
        } catch (PDOException $e) {
            registrar_auditoria($db, $usuario_logado, 'ERRO_TROCA_SENHA_DB', $e->getMessage());
            $mensagem = 'Erro ao salvar a nova senha.';
            $erro = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Trocar Senha - Picking Lançamentos</title>
    <style>
        /* CSS: Estilo Dark Mode */
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background-color: #121228; 
            color: #f0f0f0; 
            display: flex; 
            flex-direction: column;
            justify-content: center; 
            align-items: center; 
            height: 100vh; 
            margin: 0; 
        }
        .change-box { 
            background: #1e1e40; 
            padding: 40px; 
            border-radius: 12px; 
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4); 
            width: 380px; 
            max-width: 90%;
        }
        h2 { 
            text-align: center; 
            color: #ffeb3b; 
            margin-bottom: 10px;
            font-size: 1.5em;
        }
        p.required {
            text-align: center;
            color: #f44336;
            margin-bottom: 20px;
        }
        .input-group {
            position: relative;
            margin-bottom: 15px;
        }
        input[type="password"] { 
            width: 100%; 
            padding: 12px; 
            border: 1px solid #3a3a6b;
            border-radius: 6px; 
            box-sizing: border-box; 
            background: #2a2a50; 
            color: white; 
        }
        input:focus {
            outline: none;
            border-color: #4CAF50; 
        }
        button.primary { 
            background-color: #2196f3; 
            color: white; 
            padding: 12px; 
            border: none; 
            border-radius: 6px; 
            width: 100%; 
            cursor: pointer; 
            margin-top: 20px; 
            font-weight: bold;
        }
        button.primary:hover {
            background-color: #1976d2; 
        }
        .alert { 
            padding: 10px; 
            border-radius: 4px; 
            text-align: center;
            margin-bottom: 15px;
        }
        .error { background: #3a1e1e; color: #f44336; }
        .success { background: #1e3a1e; color: #4CAF50; }
    </style>
</head>
<body>
    <div class="change-box">
        <h2>Troca de Senha Obrigatória</h2>
        <p class="required">Olá, **<?= htmlspecialchars($usuario_logado) ?>**. Para sua segurança, troque sua senha no primeiro acesso.</p>

        <?php if ($mensagem): ?>
            <p class="alert <?= $erro ? 'error' : 'success' ?>"><?= $mensagem ?></p>
        <?php endif; ?>
        
        <form method="POST">
            <div class="input-group">
                <input type="password" name="nova_senha" placeholder="Nova Senha (Mínimo 5 caracteres)" required>
            </div>
            <div class="input-group">
                <input type="password" name="confirma_senha" placeholder="Confirme a Nova Senha" required>
            </div>
            
            <button type="submit" class="primary">Alterar Senha e Entrar</button>
        </form>
        
    </div>
</body>
</html>
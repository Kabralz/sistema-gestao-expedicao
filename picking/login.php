<?php
session_start();
// Configurações do seu banco MariaDB
// 🚨 ATENÇÃO: Substitua 'SUA_SENHA_AQUI' pela senha real do seu MariaDB.
$DB_CONFIG = ['host' => '127.0.0.1', 'port' => 3307, 'user' => 'root', 'pass' => '', 'name' => 'picking']; 

$mensagem = '';

function registrar_auditoria($db, $usuario, $acao, $detalhes = '') {
    $stmt = $db->prepare("INSERT INTO log_acesso_auditoria (usuario, acao, detalhes) VALUES (?, ?, ?)");
    $stmt->execute([$usuario, $acao, $detalhes]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = strtoupper(trim($_POST['username']));
    $password = $_POST['password'];

    try {
        $db = new PDO("mysql:host={$DB_CONFIG['host']};port={$DB_CONFIG['port']};dbname={$DB_CONFIG['name']};charset=utf8", $DB_CONFIG['user'], $DB_CONFIG['pass']);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $db->prepare("SELECT usuario, senha_hash, primeiro_acesso FROM usuarios WHERE usuario = ? AND ativo = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // ATENÇÃO: Use password_verify se as senhas no DB foram salvas com password_hash
        // Se as senhas foram salvas com a função PASSWORD() do MariaDB, use a sintaxe abaixo:
        // if ($user && $db->query("SELECT PASSWORD(?) = '{$user['senha_hash']}'")->fetchColumn()) {
        
        // Assumindo que você usou password_hash() no PHP para maior segurança:
        if ($user && password_verify($password, $user['senha_hash'])) { 
            
            $_SESSION['logado'] = true;
            $_SESSION['usuario'] = $username;
            
            registrar_auditoria($db, $username, 'LOGIN_SUCESSO', 'IP: ' . $_SERVER['REMOTE_ADDR']);

            if ($user['primeiro_acesso']) {
                header('Location: troca_senha.php');
            } else {
                header('Location: inserir_dados.php');
            }
            exit();
        } else {
            // Se a validação falhar, o login falhou
            registrar_auditoria($db, $username, 'LOGIN_FALHA', 'IP: ' . $_SERVER['REMOTE_ADDR']);
            $mensagem = 'Usuário ou senha inválidos.';
        }
    } catch (PDOException $e) {
        $mensagem = 'Erro de conexão com o banco de dados. Tente novamente.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Login - Picking Lançamentos</title>
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
        .login-box { 
            background: #1e1e40; 
            padding: 40px; 
            border-radius: 12px; 
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4); 
            width: 350px; 
            max-width: 90%;
        }
        .logo-img {
            display: block;
            margin: 0 auto 10px;
            height: 40px; 
            /* GARANTE QUE A IMAGEM SVG FIQUE CLARA */
            filter: drop-shadow(0 0 2px rgba(255, 255, 255, 0.5)); 
        }
        h2 { 
            text-align: center; 
            color: #f0f0f0; 
            margin-bottom: 25px;
            font-size: 1.5em;
        }
        .input-group {
            position: relative;
            margin-bottom: 15px;
        }
        input[type="text"], input[type="password"] { 
            width: 100%; 
            padding: 12px; 
            border: 1px solid #3a3a6b;
            border-radius: 6px; 
            box-sizing: border-box; 
            background: #2a2a50; 
            color: white; 
            transition: border-color 0.3s;
        }
        input::placeholder {
            color: #9a9a9a;
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
            margin-top: 15px; 
            font-weight: bold;
            transition: background-color 0.3s;
        }
        button.primary:hover {
            background-color: #1976d2; 
        }
        .error { 
            color: #f44336; 
            text-align: center; 
            margin-bottom: 15px; 
            padding: 10px;
            background: #3a1e1e;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="login-box">
        <img src="logo.svg" alt="Souza Logo" class="logo-img">
        <h2>Picking Lançamentos</h2>

        <?php if ($mensagem): ?>
            <p class="error"><?= $mensagem ?></p>
        <?php endif; ?>
        
        <form method="POST">
            <div class="input-group">
                <input type="text" name="username" placeholder="Seu usuário" required>
            </div>
            <div class="input-group">
                <input type="password" name="password" placeholder="Sua senha" required>
            </div>
            
            <button type="submit" class="primary">Entrar</button>
        </form>
        
        </div>
</body>
</html>
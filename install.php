<?php
// Interactive installer for the system with modern design

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? 'root');
    $dbPass = trim($_POST['db_pass'] ?? '');
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $adminPass = trim($_POST['admin_pass'] ?? '');

    $errors = [];

    if (!$dbName) {
        $errors[] = "Nome do banco de dados é obrigatório.";
    }
    if (!$adminEmail) {
        $errors[] = "Email do usuário admin é obrigatório.";
    }
    if (!$adminPass) {
        $errors[] = "Senha do usuário admin é obrigatória.";
    }

    if (count($errors) === 0) {
        try {
            $pdo_init = new PDO("mysql:host=localhost", $dbUser, $dbPass);
            $pdo_init->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $steps = [];

            // Create database
            $pdo_init->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
            $steps[] = "Banco de dados '$dbName' verificado/criado com sucesso.";

            // Connect to new database
            $pdo = new PDO("mysql:host=localhost;dbname=$dbName", $dbUser, $dbPass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Create tables
            $sql_usuarios = "
            CREATE TABLE IF NOT EXISTS `usuarios` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `email` VARCHAR(255) NOT NULL UNIQUE,
              `senha` VARCHAR(255) NOT NULL,
              `criado_em` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB;
            ";
            $pdo->exec($sql_usuarios);
            $steps[] = "Tabela 'usuarios' verificada/criada com sucesso.";

            $sql_config = "
            CREATE TABLE IF NOT EXISTS `config` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `chave` VARCHAR(255) NOT NULL UNIQUE,
              `valor` TEXT
            ) ENGINE=InnoDB;
            ";
            $pdo->exec($sql_config);
            $steps[] = "Tabela 'config' verificada/criada com sucesso.";

            $sql_curriculos = "
            CREATE TABLE IF NOT EXISTS `curriculos` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `nome` VARCHAR(255) NOT NULL,
                `data_nascimento` DATE NOT NULL,
                `estado_civil` VARCHAR(50),
                `telefone` VARCHAR(255),
                `is_whatsapp` TINYINT(1) DEFAULT 0,
                `email` VARCHAR(255),
                `facebook` VARCHAR(255),
                `instagram` VARCHAR(255),
                `endereco` TEXT,
                `cidade` VARCHAR(255),
                `estado` VARCHAR(255),
                `escolaridade` VARCHAR(255),
                `estudando` TINYINT(1) DEFAULT 0,
                `periodo_estudo` VARCHAR(50),
                `possui_cursos` TINYINT(1) DEFAULT 0,
                `cursos` TEXT,
                `possui_experiencia` TINYINT(1) DEFAULT 0,
                `experiencias` TEXT,
                `motivacao` TEXT,
                `arquivo_curriculo` VARCHAR(255),
                `arquivo_foto` VARCHAR(255),
                `ip_cadastro` VARCHAR(45),
                `data_cadastro` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB;
            ";
            $pdo->exec($sql_curriculos);
            $steps[] = "Tabela 'curriculos' verificada/criada com sucesso.";

            // Insert admin user if not exists
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
            $stmt->execute([$adminEmail]);
            if ($stmt->rowCount() == 0) {
                $senha_hash = password_hash($adminPass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO usuarios (email, senha) VALUES (?, ?)");
                $stmt->execute([$adminEmail, $senha_hash]);
                $steps[] = "Usuário administrador '$adminEmail' inserido com sucesso.";
            } else {
                $steps[] = "Usuário administrador '$adminEmail' já existe.";
            }

            // Insert default config if not exists
            $configs_iniciais = [
                'api_token' => '',
                'api_url' => 'https://app.whapichat.com.br:443/backend/api/messages/send',
                'notification_number' => '5500000000000',
                'completion_message' => 'Olá {nome}! Obrigado por se cadastrar no nosso sistema. Seu currículo foi recebido com sucesso e entraremos em contato em breve.'
            ];

            $stmt_check = $pdo->prepare("SELECT id FROM config WHERE chave = ?");
            $stmt_insert = $pdo->prepare("INSERT INTO config (chave, valor) VALUES (?, ?)");

            foreach ($configs_iniciais as $chave => $valor) {
                $stmt_check->execute([$chave]);
                if ($stmt_check->rowCount() == 0) {
                    $stmt_insert->execute([$chave, $valor]);
                    $steps[] = "Configuração padrão '{$chave}' inserida com sucesso.";
                } else {
                    $steps[] = "Configuração '{$chave}' já existe.";
                }
            }

            $success = true;

            // Atualizar o arquivo db_connect.php com as configurações do banco de dados fornecidas
            $dbConnectPath = __DIR__ . '/db_connect.php';
            if (is_writable($dbConnectPath)) {
                $dbConnectContent = file_get_contents($dbConnectPath);

                // Substituir as definições de configuração do banco de dados
                $dbConnectContent = str_replace("define('DB_HOST', 'localhost');", "define('DB_HOST', 'localhost');", $dbConnectContent);
                $dbConnectContent = str_replace("define('DB_USER', 'root');", "define('DB_USER', '" . addslashes($dbUser) . "');", $dbConnectContent);
                $dbConnectContent = str_replace("define('DB_PASS', '');", "define('DB_PASS', '" . addslashes($dbPass) . "');", $dbConnectContent);
                $dbConnectContent = str_replace("define('DB_NAME', 'caurri');", "define('DB_NAME', '" . addslashes($dbName) . "');", $dbConnectContent);

                file_put_contents($dbConnectPath, $dbConnectContent);
                $steps[] = "Arquivo db_connect.php atualizado com as configurações do banco de dados.";
            } else {
                $steps[] = "Não foi possível atualizar o arquivo db_connect.php. Verifique as permissões.";
            }

        } catch (PDOException $e) {
            $errors[] = "Erro durante a instalação: " . $e->getMessage();
            $success = false;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Instalador do Sistema de Currículos</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }
        .container {
            background: white;
            padding: 30px 40px;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.1);
            width: 400px;
            max-width: 90%;
        }
        h1 {
            margin-bottom: 20px;
            color: #333;
            text-align: center;
        }
        label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: #555;
        }
        input[type="text"],
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 10px 12px;
            margin-bottom: 15px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }
        input[type="text"]:focus,
        input[type="email"]:focus,
        input[type="password"]:focus {
            border-color: #007bff;
            outline: none;
        }
        button {
            width: 100%;
            padding: 12px;
            background: #007bff;
            border: none;
            border-radius: 8px;
            color: white;
            font-size: 1.1rem;
            cursor: pointer;
            transition: background 0.3s ease;
        }
        button:hover {
            background: #0056b3;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        .success {
            background: #d4edda;
            color: #155724;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        ul {
            list-style: none;
            padding-left: 0;
        }
        li {
            margin-bottom: 8px;
            padding-left: 20px;
            position: relative;
        }
        li::before {
            content: "✔️";
            position: absolute;
            left: 0;
            top: 0;
        }
        a {
            display: inline-block;
            margin-top: 15px;
            text-decoration: none;
            color: #007bff;
            font-weight: 600;
        }
        a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Instalador do Sistema de Currículos</h1>

<?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
    <?php if (!empty($errors)): ?>
        <div class="error">
            <h2>Erros encontrados:</h2>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
            <a href="install.php">Voltar</a>
        </div>
    <?php elseif ($success): ?>
        <div class="success">
            <h2>Instalação concluída com sucesso!</h2>
            <ul>
                <?php foreach ($steps as $step): ?>
                    <li><?php echo htmlspecialchars($step); ?></li>
                <?php endforeach; ?>
            </ul>
            <p>Por segurança, remova ou renomeie o arquivo <code>install.php</code> agora.</p>
        </div>
    <?php endif; ?>
<?php else: ?>
    <form method="POST" action="install.php">
        <label for="db_name">Nome do Banco de Dados:</label>
        <input type="text" id="db_name" name="db_name" required />

        <label for="db_user">Usuário do Banco de Dados:</label>
        <input type="text" id="db_user" name="db_user" value="root" required />

        <label for="db_pass">Senha do Banco de Dados:</label>
        <input type="password" id="db_pass" name="db_pass" />

        <label for="admin_email">Email do Usuário Admin:</label>
        <input type="email" id="admin_email" name="admin_email" required />

        <label for="admin_pass">Senha do Usuário Admin:</label>
        <input type="password" id="admin_pass" name="admin_pass" required />

        <button type="submit">Instalar</button>
    </form>
    <?php endif; ?>
</div>
</body>
</html>

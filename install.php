<?php
// --- SCRIPT DE INSTALAÇÃO DO BANCO DE DADOS ---
// Execute este script uma vez para configurar o banco de dados e as tabelas.

// Configurações Iniciais (as mesmas de db_connect.php, mas sem selecionar o DB_NAME ainda)
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'curriculos_db');

echo "<pre>"; // Para formatar a saída

try {
    // 1. Conectar ao MySQL sem selecionar um banco de dados
    $pdo_init = new PDO('mysql:host=' . DB_HOST, DB_USER, DB_PASS);
    $pdo_init->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 2. Criar o banco de dados se ele não existir
    $pdo_init->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
    echo "Banco de dados '" . DB_NAME . "' verificado/criado com sucesso.\n";

    // Agora, conecte-se ao banco de dados recém-criado
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 3. Criar a tabela 'usuarios'
    $sql_usuarios = "
    CREATE TABLE IF NOT EXISTS `usuarios` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `email` VARCHAR(255) NOT NULL UNIQUE,
      `senha` VARCHAR(255) NOT NULL,
      `criado_em` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;
    ";
    $pdo->exec($sql_usuarios);
    echo "Tabela 'usuarios' verificada/criada com sucesso.\n";

    // 4. Criar a tabela 'config'
    $sql_config = "
    CREATE TABLE IF NOT EXISTS `config` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `chave` VARCHAR(255) NOT NULL UNIQUE,
      `valor` TEXT
    ) ENGINE=InnoDB;
    ";
    $pdo->exec($sql_config);
    echo "Tabela 'config' verificada/criada com sucesso.\n";

    // 5. Criar a tabela 'curriculos'
    $sql_curriculos = "
    CREATE TABLE IF NOT EXISTS `curriculos` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `nome` VARCHAR(255) NOT NULL,
        `data_nascimento` DATE NOT NULL,
        `estado_civil` VARCHAR(50),
        `telefone` VARCHAR(50),
        `is_whatsapp` VARCHAR(5),
        `email` VARCHAR(255),
        `endereco` TEXT,
        `cidade` VARCHAR(255),
        `estado` VARCHAR(255),
        `escolaridade` VARCHAR(255),
        `estudando` VARCHAR(20),
        `periodo_estudo` VARCHAR(50),
        `possui_cursos` VARCHAR(5),
        `cursos` TEXT,
        `possui_experiencia` VARCHAR(50),
        `experiencias` TEXT,
        `motivacao` TEXT,
        `arquivo_curriculo` VARCHAR(255),
        `arquivo_foto` VARCHAR(255),
        `ip_cadastro` VARCHAR(45),
        `data_cadastro` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;
    ";
    $pdo->exec($sql_curriculos);
    echo "Tabela 'curriculos' verificada/criada com sucesso.\n";

    // 6. Inserir dados iniciais (usuário e configurações)
    
    // Inserir usuário admin (apenas se não existir)
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
    $stmt->execute(['rh@gorinformatica.com.br']);
    if ($stmt->rowCount() == 0) {
        $senha_hash = password_hash('Gor103Dmas@', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO usuarios (email, senha) VALUES (?, ?)");
        $stmt->execute(['rh@gorinformatica.com.br', $senha_hash]);
        echo "Usuário administrador padrão inserido com sucesso.\n";
    } else {
        echo "Usuário administrador padrão já existe.\n";
    }

    // Inserir configurações (apenas se não existirem)
    $configs_iniciais = [
        'api_token' => '',
        'api_url' => 'https://app.whapichat.com.br:443/backend/api/messages/send',
        'notification_number' => '5500000000000'
    ];

    $stmt_check = $pdo->prepare("SELECT id FROM config WHERE chave = ?");
    $stmt_insert = $pdo->prepare("INSERT INTO config (chave, valor) VALUES (?, ?)");

    foreach ($configs_iniciais as $chave => $valor) {
        $stmt_check->execute([$chave]);
        if ($stmt_check->rowCount() == 0) {
            $stmt_insert->execute([$chave, $valor]);
            echo "Configuração padrão '{$chave}' inserida com sucesso.\n";
        } else {
            echo "Configuração '{$chave}' já existe.\n";
        }
    }

    echo "\nINSTALAÇÃO CONCLUÍDA COM SUCESSO!\n";
    echo "Por segurança, remova ou renomeie o arquivo 'install.php' agora.";

} catch (PDOException $e) {
    die("ERRO DURANTE A INSTALAÇÃO: " . $e->getMessage());
}

echo "</pre>";
?>

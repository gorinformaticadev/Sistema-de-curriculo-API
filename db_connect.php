<?php
// Impedir acesso direto ao arquivo
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    die('Acesso direto não permitido.');
}

// Configurações do Banco de Dados (substitua com suas credenciais)
// Configurações do Banco de Dados (substitua com suas credenciais)
define('DB_HOST', 'localhost');
define('DB_USER', 'gorinf79_curriculosgor');      // Usuário padrão do XAMPP
define('DB_PASS', 'Gor103Dmas@');          // Senha padrão do XAMPP é vazia
define('DB_NAME', 'gorinf79_curriculos2'); // Nome do banco de dados que vamos criar

// String de Conexão (DSN)
$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

// Opções do PDO
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Lançar exceções em erros
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Retornar arrays associativos
    PDO::ATTR_EMULATE_PREPARES   => false,                  // Usar prepared statements nativos
];

try {
    // Cria a instância do PDO
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    // Verificar se o erro é porque o banco não existe
    if (strpos($e->getMessage(), 'Unknown database') !== false || strpos($e->getMessage(), 'Base de dados desconhecida') !== false) {
        try {
            // Tentar conectar ao MySQL sem especificar banco
            $pdo_init = new PDO("mysql:host=" . DB_HOST, DB_USER, DB_PASS, $options);

            // Criar o banco de dados
            $pdo_init->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
            logError('DATABASE_CREATED: Banco de dados "' . DB_NAME . '" criado automaticamente.', 'INFO');

            // Conectar ao banco recém-criado
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

            // Executar migrações automaticamente
            require_once 'includes/migration.php';
            $migration = new DatabaseMigration($pdo);
            $migrationResult = $migration->migrate();

            if ($migrationResult['success']) {
                logError('MIGRATIONS_COMPLETED: Migrações executadas com sucesso.', 'INFO');
            } else {
                logError('MIGRATIONS_FAILED: Erro nas migrações: ' . implode(', ', $migrationResult['errors']), 'ERROR');
            }

            // Inserir configurações padrão
            $configs_iniciais = [
                'api_token' => '',
                'api_url' => 'https://app.whapichat.com.br:443/backend/api/messages/send',
                'notification_number' => '5500000000000',
                'completion_message' => 'Olá {nome}! Obrigado por se cadastrar no nosso sistema. Seu currículo foi recebido com sucesso e entraremos em contato em breve.',
                'smtp_host' => 'smtp.gmail.com',
                'smtp_port' => '587',
                'smtp_user' => '',
                'smtp_pass' => '',
                'smtp_from' => 'noreply@gorinformatica.com.br',
                'notification_email' => 'rh@gorinformatica.com.br'
            ];

            $stmt_check = $pdo->prepare("SELECT id FROM config WHERE chave = ?");
            $stmt_insert = $pdo->prepare("INSERT INTO config (chave, valor) VALUES (?, ?)");

            foreach ($configs_iniciais as $chave => $valor) {
                $stmt_check->execute([$chave]);
                if ($stmt_check->rowCount() == 0) {
                    $stmt_insert->execute([$chave, $valor]);
                }
            }

            // Criar usuário admin padrão se não existir
            $admin_email = 'admin@gorinformatica.com.br';
            $admin_pass = 'admin123'; // Senha padrão - deve ser alterada

            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
            $stmt->execute([$admin_email]);
            if ($stmt->rowCount() == 0) {
                $senha_hash = password_hash($admin_pass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO usuarios (email, senha, tipo) VALUES (?, ?, 'admin')");
                $stmt->execute([$admin_email, $senha_hash]);
                logError('ADMIN_USER_CREATED: Usuário admin padrão criado: ' . $admin_email, 'INFO');
            }

            logError('AUTO_SETUP_COMPLETED: Configuração automática do banco concluída.', 'INFO');

        } catch (Exception $initError) {
            // Logar o erro antes de terminar a execução
            if (!function_exists('logError')) {
                function logError($message, $type = 'ERROR') {
                    $logFile = 'error.log';
                    $maxSize = 5 * 1024 * 1024; // 5MB

                    // Rotação de logs
                    if (file_exists($logFile) && filesize($logFile) > $maxSize) {
                        $backupFile = $logFile . '.bak';
                        // Se já existir um backup, ele será sobrescrito
                        rename($logFile, $backupFile);
                    }

                    $timestamp = date('Y-m-d H:i:s');
                    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                    $logMessage = "[$timestamp] [$type] [IP: $ip] $message" . PHP_EOL;
                    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
                }
            }
            logError('AUTO_SETUP_FAILED: Falha na configuração automática: ' . $initError->getMessage());

            // Retornar erro
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Erro crítico do servidor: falha na configuração automática do banco de dados.'
            ]);
            exit;
        }
    } else {
        // Outro tipo de erro de conexão
        if (!function_exists('logError')) {
            function logError($message, $type = 'ERROR') {
                $logFile = 'error.log';
                $maxSize = 5 * 1024 * 1024; // 5MB

                // Rotação de logs
                if (file_exists($logFile) && filesize($logFile) > $maxSize) {
                    $backupFile = $logFile . '.bak';
                    // Se já existir um backup, ele será sobrescrito
                    rename($logFile, $backupFile);
                }

                $timestamp = date('Y-m-d H:i:s');
                $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                $logMessage = "[$timestamp] [$type] [IP: $ip] $message" . PHP_EOL;
                file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
            }
        }
        logError('DB_CONNECT_FAILURE: Falha na conexão com o banco de dados: ' . $e->getMessage());

        // Retornar um JSON de erro padronizado para o frontend
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Erro crítico do servidor: não foi possível conectar ao banco de dados.'
        ]);
        exit;
    }
}

// A variável $pdo agora está disponível para ser usada nos scripts que incluírem este arquivo.
?>

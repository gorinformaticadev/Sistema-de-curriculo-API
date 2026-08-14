<?php
// Impedir acesso direto ao arquivo
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    die('Acesso direto não permitido.');
}

// Carregar variáveis de ambiente do arquivo .env
function loadEnv($path) {
    if (!file_exists($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), "#") === 0) continue;
        if (strpos($line, "=") === false) continue;
        list($key, $value) = explode("=", $line, 2);
        $key = trim($key);
        $value = trim($value);
        $value = trim($value, "\"'");
        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}
loadEnv(__DIR__ . "/.env");

// Configurações do Banco de Dados
define('DB_HOST', getenv('DB_HOST') !== false ? getenv('DB_HOST') : 'localhost');
define('DB_USER', getenv('DB_USER') !== false ? getenv('DB_USER') : 'gorinf79_curriculosgor');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'Gor103Dmas@');
define('DB_NAME', getenv('DB_NAME') !== false ? getenv('DB_NAME') : 'gorinf79_curriculos2');

// String de Conexão (DSN)
$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

// Opções do PDO
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    
    // ============================================
    // ATUALIZAÇÃO AUTOMÁTICA DO BANCO DE DADOS
    // Aplica migrações pendentes a cada carregamento.
    // A checagem é barata (1 SELECT) e as migrações
    // são idempotentes (controladas pela tabela db_version).
    // ============================================
    try {
        require_once __DIR__ . '/includes/migration.php';
        $migration = new DatabaseMigration($pdo);
        if ($migration->hasPendingMigrations()) {
            $migrationResult = $migration->migrate();
            if (empty($migrationResult['success'])) {
                error_log('[MIGRATIONS_FAILED] ' . implode(', ', $migrationResult['errors']));
            } else {
                error_log('[MIGRATIONS_APPLIED] ' . count($migrationResult['applied_migrations']) . ' migração(ões) aplicada(s).');
            }
        }
    } catch (Exception $migrationError) {
        // Nunca derruba o sistema por falha de migração: registra e segue.
        error_log('[MIGRATIONS_ERROR] ' . $migrationError->getMessage());
    }
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Unknown database') !== false || strpos($e->getMessage(), 'Base de dados desconhecida') !== false) {
        try {
            $pdo_init = new PDO("mysql:host=" . DB_HOST, DB_USER, DB_PASS, $options);
            $pdo_init->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
            logError('DATABASE_CREATED: Banco de dados "' . DB_NAME . '" criado automaticamente.', 'INFO');

            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

            require_once __DIR__ . '/includes/migration.php';
            $migration = new DatabaseMigration($pdo);
            $migrationResult = $migration->migrate();

            if ($migrationResult['success']) {
                logError('MIGRATIONS_COMPLETED: Migrações executadas com sucesso.', 'INFO');
            } else {
                logError('MIGRATIONS_FAILED: Erro nas migrações: ' . implode(', ', $migrationResult['errors']), 'ERROR');
            }

            $configs_iniciais = [
                'api_token' => '',
                'api_url' => 'https://app.pluggor.com.br/api/messages/send',
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

            logError('AUTO_SETUP_COMPLETED: Configuração automática do banco concluída.', 'INFO');

        } catch (Exception $initError) {
            if (!function_exists('logError')) {
                function logError($message, $type = 'ERROR') {
                    $logFile = 'error.log';
                    $maxSize = 5 * 1024 * 1024;
                    if (file_exists($logFile) && filesize($logFile) > $maxSize) {
                        $backupFile = $logFile . '.bak';
                        rename($logFile, $backupFile);
                    }
                    $timestamp = date('Y-m-d H:i:s');
                    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                    $logMessage = "[$timestamp] [$type] [IP: $ip] $message" . PHP_EOL;
                    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
                }
            }
            logError('AUTO_SETUP_FAILED: Falha na configuração automática: ' . $initError->getMessage());

            while (ob_get_level()) ob_end_clean();
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Erro crítico do servidor: falha na configuração automática do banco de dados.'
            ]);
            exit;
        }
    } else {
        if (!function_exists('logError')) {
            function logError($message, $type = 'ERROR') {
                $logFile = 'error.log';
                $maxSize = 5 * 1024 * 1024;
                if (file_exists($logFile) && filesize($logFile) > $maxSize) {
                    $backupFile = $logFile . '.bak';
                    rename($logFile, $backupFile);
                }
                $timestamp = date('Y-m-d H:i:s');
                $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                $logMessage = "[$timestamp] [$type] [IP: $ip] $message" . PHP_EOL;
                file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
            }
        }
        logError('DB_CONNECT_FAILURE: Falha na conexão com o banco de dados: ' . $e->getMessage());

        while (ob_get_level()) ob_end_clean();
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Erro crítico do servidor: não foi possível conectar ao banco de dados.'
        ]);
        exit;
    }
}
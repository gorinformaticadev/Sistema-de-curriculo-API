<?php
// Impedir acesso direto ao arquivo
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    die('Acesso direto não permitido.');
}

// Configurações do Banco de Dados (substitua com suas credenciais)
define('DB_HOST', 'localhost');
define('DB_USER', 'root');      // Usuário padrão do XAMPP
define('DB_PASS', '');          // Senha padrão do XAMPP é vazia
define('DB_NAME', 'curriculos'); // Nome do banco de dados que vamos criar

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
    // Logar o erro antes de terminar a execução é crucial para a depuração.
    // Esta função de log precisa estar disponível ou definida antes da chamada.
    // Como este arquivo é incluído, vamos definir uma função de log de emergência aqui.
    if (!function_exists('logError')) {
        function logError($message, $type = 'ERROR') {
            $timestamp = date('Y-m-d H:i:s');
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $logMessage = "[$timestamp] [$type] [IP: $ip] $message" . PHP_EOL;
            file_put_contents('error.log', $logMessage, FILE_APPEND | LOCK_EX);
        }
    }
    logError('DB_CONNECT_FAILURE: Falha na conexão com o banco de dados: ' . $e->getMessage());
    
    // Retornar um JSON de erro padronizado para o frontend
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erro crítico do servidor: não foi possível conectar ao banco de dados.'
    ]);
    exit; // Usar exit em vez de die
}

// A variável $pdo agora está disponível para ser usada nos scripts que incluírem este arquivo.
?>

<?php
/**
 * Configuração e conexão com banco de dados
 * Arquivo responsável pela conexão PDO e carregamento de configurações
 */

// Funções de criptografia do token da API (Bearer)
require_once dirname(__DIR__) . '/includes/crypto.php';

class Database {
    private static $instance = null;
    private $pdo;
    
    private function __construct() {
        try {
            // Configurações do banco (pode ser movido para arquivo .env no futuro)
            $host = 'localhost';
            $dbname = 'gor_informatica';
            $username = 'root';
            $password = '';
            
            $this->pdo = new PDO(
                "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            error_log("Erro de conexão com banco: " . $e->getMessage());
            throw new Exception("Erro de conexão com banco de dados");
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->pdo;
    }
    
    /**
     * Carrega configurações do banco de dados
     */
    public function loadConfig() {
        $config = [];
        $stmt = $this->pdo->query("SELECT chave, valor FROM config");
        while ($row = $stmt->fetch()) {
            $config[$row['chave']] = $row['valor'];
        }
        
        // Descriptografa o token da API e migra tokens legados para criptografia
        if (!empty($config['api_token']) && strpos($config['api_token'], 'enc:v1:') !== 0) {
            $plain = $config['api_token'];
            $encrypted = tokenEncrypt($plain);
            $upd = $this->pdo->prepare("UPDATE config SET valor = ? WHERE chave = 'api_token'");
            $upd->execute([$encrypted]);
            $config['api_token'] = $plain;
        } elseif (!empty($config['api_token'])) {
            $config['api_token'] = tokenDecrypt($config['api_token']);
        }
        
        // Configurações padrão se não existirem no banco
        $defaultConfigs = [
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
        
        // Inserir configurações padrão se não existirem
        foreach ($defaultConfigs as $key => $value) {
            if (!isset($config[$key])) {
                $stmt = $this->pdo->prepare("INSERT INTO config (chave, valor) VALUES (?, ?)");
                $stmt->execute([$key, $value]);
                $config[$key] = $value;
            }
        }
        
        return $config;
    }
}

// Função de conveniência para obter a conexão PDO
function getPDO() {
    return Database::getInstance()->getConnection();
}

// Função de conveniência para carregar configurações
function getConfig() {
    return Database::getInstance()->loadConfig();
}
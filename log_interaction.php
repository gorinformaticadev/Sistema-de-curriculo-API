<?php
/**
 * Endpoint para registrar interações do formulário
 * Suporta ações: change, select, check, file_selected, form_submitted, form_submit_click, form_access, form_abandoned
 */

// Buffer de saída para evitar que warnings/notices corrompam o JSON
ob_start();

// Suprimir warnings/notices na saída (loga apenas no error.log)
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

require_once 'db_connect.php';

// Função de log local (caso helpers não esteja carregado)
if (!function_exists('logError')) {
    function logError($message, $type = 'ERROR') {
        $logFile = __DIR__ . '/error.log';
        $timestamp = date('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $logMessage = "[$timestamp] [$type] [IP: $ip] [log_interaction.php] $message" . PHP_EOL;
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
}

// =============================================
// AUTO-REPARO: Garantir que a tabela existe e tem todas as colunas
// =============================================
function ensureTableStructure($pdo) {
    try {
        // 1. Criar tabela se não existir
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `form_interactions` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `session_id` VARCHAR(255) NOT NULL,
                `ip` VARCHAR(45) NOT NULL,
                `user_agent` TEXT,
                `browser` VARCHAR(100),
                `os` VARCHAR(100),
                `device` VARCHAR(50),
                `nome_completo` VARCHAR(255) DEFAULT NULL,
                `ultimo_campo` VARCHAR(100),
                `acao` VARCHAR(50),
                `valor_campo` TEXT,
                `timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_session` (`session_id`),
                INDEX `idx_ip` (`ip`),
                INDEX `idx_timestamp` (`timestamp`),
                INDEX `idx_device` (`device`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // 2. Verificar e adicionar coluna user_agent se não existir
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM information_schema.columns 
            WHERE table_schema = DATABASE() 
            AND table_name = 'form_interactions' 
            AND column_name = 'user_agent'
        ");
        $stmt->execute();
        if ($stmt->fetch()['count'] == 0) {
            $pdo->exec("ALTER TABLE `form_interactions` ADD COLUMN `user_agent` TEXT AFTER `ip`");
            logError("Auto-reparo: coluna 'user_agent' adicionada à tabela form_interactions", 'INFO');
        }

        return true;
    } catch (Exception $e) {
        logError("Auto-reparo falhou: " . $e->getMessage());
        return false;
    }
}

// Executar auto-reparo ao carregar
ensureTableStructure($pdo);

// Função para detectar navegador
function detectBrowser($userAgent) {
    if (strpos($userAgent, 'Firefox') !== false) return 'Firefox';
    if (strpos($userAgent, 'Chrome') !== false && strpos($userAgent, 'Edg') === false) return 'Chrome';
    if (strpos($userAgent, 'Safari') !== false && strpos($userAgent, 'Chrome') === false) return 'Safari';
    if (strpos($userAgent, 'Edg') !== false) return 'Edge';
    if (strpos($userAgent, 'Opera') !== false || strpos($userAgent, 'OPR') !== false) return 'Opera';
    if (strpos($userAgent, 'MSIE') !== false || strpos($userAgent, 'Trident') !== false) return 'Internet Explorer';
    return 'Desconhecido';
}

// Função para detectar sistema operacional
function detectOS($userAgent) {
    if (strpos($userAgent, 'Windows NT 10.0') !== false) return 'Windows 10';
    if (strpos($userAgent, 'Windows NT 6.3') !== false) return 'Windows 8.1';
    if (strpos($userAgent, 'Windows NT 6.2') !== false) return 'Windows 8';
    if (strpos($userAgent, 'Windows NT 6.1') !== false) return 'Windows 7';
    if (strpos($userAgent, 'Windows') !== false) return 'Windows';
    if (strpos($userAgent, 'Mac OS X') !== false) return 'macOS';
    if (strpos($userAgent, 'Linux') !== false) return 'Linux';
    if (strpos($userAgent, 'Android') !== false) return 'Android';
    if (strpos($userAgent, 'iPhone') !== false || strpos($userAgent, 'iPad') !== false) return 'iOS';
    return 'Desconhecido';
}

// Função para detectar tipo de dispositivo
function detectDevice($userAgent) {
    if (strpos($userAgent, 'Mobile') !== false || strpos($userAgent, 'Android') !== false) return 'Mobile';
    if (strpos($userAgent, 'Tablet') !== false || strpos($userAgent, 'iPad') !== false) return 'Tablet';
    return 'Desktop';
}

try {
    // Obter dados do POST
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data || !isset($data['interactions'])) {
        throw new Exception('Dados inválidos recebidos. Input: ' . substr($input, 0, 200));
    }

    $interactions = $data['interactions'] ?? [];
    $globalLastField = $data['lastField'] ?? null;
    $globalUserName = $data['userName'] ?? null;

    // Obter informações do cliente
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $browser = detectBrowser($userAgent);
    $os = detectOS($userAgent);
    $device = detectDevice($userAgent);

    // Preparar statement para inserção
    $stmt = $pdo->prepare("
        INSERT INTO form_interactions 
        (session_id, ip, user_agent, browser, os, device, nome_completo, ultimo_campo, acao, valor_campo, timestamp) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $insertedCount = 0;
    $errors = [];

    // Se não houver interações mas houver dados globais (ex: abandono sem novos campos)
    if (empty($interactions) && ($globalLastField || $globalUserName)) {
        $interactions[] = [
            'sessionId' => $data['sessionId'] ?? 'unknown',
            'action' => 'update_state',
            'fieldLabel' => $globalLastField,
            'fieldValue' => 'Atualização de estado',
            'timestamp' => date('c')
        ];
    }

    foreach ($interactions as $interaction) {
        try {
            $sessionId = $interaction['sessionId'] ?? 'unknown';
            $fieldLabel = $interaction['fieldLabel'] ?? $interaction['fieldName'] ?? 'unknown';
            $action = $interaction['action'] ?? 'unknown';
            $fieldValue = $interaction['fieldValue'] ?? '';
            $timestamp = $interaction['timestamp'] ?? date('Y-m-d H:i:s');
            
            // Usar o nome do usuário da interação ou o global
            $currentUserName = $interaction['userName'] ?? $globalUserName;
            
            // Se for um evento de abandono, garantir que o último campo seja o global se o da interação for genérico
            $currentLastField = $fieldLabel;
            if ($action === 'form_abandoned' && $globalLastField) {
                $currentLastField = $globalLastField;
            }

            // Converter timestamp ISO para MySQL datetime
            $timestamp = date('Y-m-d H:i:s', strtotime($timestamp));

            $stmt->execute([
                $sessionId,
                $ip,
                $userAgent,
                $browser,
                $os,
                $device,
                $currentUserName,
                $currentLastField,
                $action,
                $fieldValue,
                $timestamp
            ]);

            $insertedCount++;
        } catch (Exception $rowError) {
            $errors[] = "Erro na interação '{$action}': " . $rowError->getMessage();
            logError("Erro ao inserir interação (acao={$action}): " . $rowError->getMessage());
        }
    }

    // Limpar qualquer conteúdo no buffer antes de enviar JSON
    while (ob_get_level()) {
        ob_end_clean();
    }

    $response = [
        'success' => $insertedCount > 0,
        'message' => "$insertedCount interações registradas com sucesso",
        'inserted' => $insertedCount
    ];

    if (!empty($errors)) {
        $response['errors'] = $errors;
        logError("Interações com erros: " . count($errors) . " de " . count($interactions));
    }

    echo json_encode($response);

} catch (Exception $e) {
    // Limpar buffer antes de enviar erro
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    logError("ERRO GERAL: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Erro ao registrar interações: ' . $e->getMessage()
    ]);
}
?>

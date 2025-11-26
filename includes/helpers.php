<?php
/**
 * Funções auxiliares e utilitárias
 * Contém funções de log, validação e outras utilidades do sistema
 */

/**
 * Função para log de erros no sistema
 */
function logError($message, $type = 'ERROR') {
    $logFile = 'error.log';
    $maxSize = 5 * 1024 * 1024; // 5MB

    if (file_exists($logFile) && filesize($logFile) > $maxSize) {
        rename($logFile, $logFile . '.bak');
    }

    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $logMessage = "[$timestamp] [$type] [IP: $ip] [UA: $userAgent] $message" . PHP_EOL;
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

/**
 * Função para log de acesso
 */
function logAccess($message, $data = []) {
    $logFile = 'access.log';
    $maxSize = 5 * 1024 * 1024; // 5MB

    if (file_exists($logFile) && filesize($logFile) > $maxSize) {
        rename($logFile, $logFile . '.bak');
    }

    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $sessionId = session_id();
    $userInfo = isset($_SESSION['user_email']) ? $_SESSION['user_email'] : 'anonimo';
    
    $logMessage = "[$timestamp] [ACCESS] [IP: $ip] [SESSION: $sessionId] [USER: $userInfo] [UA: $userAgent] $message";
    
    if (!empty($data)) {
        $logMessage .= " [DATA: " . json_encode($data) . "]";
    }
    
    $logMessage .= PHP_EOL;
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

/**
 * Função para sanitizar dados de entrada
 */
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Função para validar email
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Função para validar telefone
 */
function validatePhone($phone) {
    // Remove caracteres não numéricos
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // Verifica se tem entre 10 e 15 dígitos (Brasil + internacional)
    return strlen($phone) >= 10 && strlen($phone) <= 15;
}

/**
 * Função para formatar telefone
 */
function formatPhone($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    if (strlen($phone) == 11) {
        // Formato (XX) XXXXX-XXXX
        return preg_replace('/(\d{2})(\d{5})(\d{4})/', '($1) $2-$3', $phone);
    } elseif (strlen($phone) == 10) {
        // Formato (XX) XXXX-XXXX
        return preg_replace('/(\d{2})(\d{4})(\d{4})/', '($1) $2-$3', $phone);
    }
    
    return $phone;
}

/**
 * Função para gerar token CSRF
 */
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Função para verificar token CSRF
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Função para enviar resposta JSON
 */
function jsonResponse($success, $message, $data = []) {
    header('Content-Type: application/json');
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $data));
    exit;
}

/**
 * Função para paginar resultados
 */
function paginateResults($page = 1, $limit = 20, $total) {
    $offset = ($page - 1) * $limit;
    $totalPages = ceil($total / $limit);
    
    return [
        'current_page' => $page,
        'per_page' => $limit,
        'total' => $total,
        'total_pages' => $totalPages,
        'offset' => $offset,
        'has_next' => $page < $totalPages,
        'has_prev' => $page > 1
    ];
}

/**
 * Função para validar e limpar dados de currículo
 */
function validateCurriculumData($data) {
    $errors = [];
    
    // Validações básicas
    if (empty($data['nome'])) {
        $errors[] = "Nome é obrigatório";
    }
    
    if (empty($data['email']) || !validateEmail($data['email'])) {
        $errors[] = "Email inválido";
    }
    
    if (empty($data['telefone']) || !validatePhone($data['telefone'])) {
        $errors[] = "Telefone inválido";
    }
    
    if (empty($data['cidade'])) {
        $errors[] = "Cidade é obrigatória";
    }
    
    // Validar idade mínima
    if (!empty($data['data_nascimento'])) {
        $birthDate = new DateTime($data['data_nascimento']);
        $today = new DateTime();
        $age = $today->diff($birthDate)->y;
        
        if ($age < 14) {
            $errors[] = "Idade mínima para cadastro é 14 anos";
        }
    }
    
    return $errors;
}

/**
 * Função para converter array para CSV
 */
function arrayToCsv($data, $filename = 'export.csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    
    // Adicionar BOM para UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Escrever cabeçalhos
    if (!empty($data)) {
        fputcsv($output, array_keys($data[0]));
        
        // Escrever dados
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
    }
    
    fclose($output);
    exit;
}

/**
 * Função para formatar data para exibição
 */
function formatDate($date, $format = 'd/m/Y H:i') {
    if (empty($date)) return '-';
    
    $dateTime = new DateTime($date);
    return $dateTime->format($format);
}

/**
 * Função para calcular idade
 */
function calculateAge($birthDate) {
    if (empty($birthDate)) return '-';
    
    $birth = new DateTime($birthDate);
    $today = new DateTime();
    $age = $today->diff($birth);
    
    return $age->y . ' anos';
}
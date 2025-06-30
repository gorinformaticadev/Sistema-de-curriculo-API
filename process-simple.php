<?php
header('Content-Type: application/json; charset=utf-8');

// --- CONFIGURAÇÃO E FUNÇÕES GLOBAIS ---

error_reporting(E_ALL);
ini_set('display_errors', 0); // Erros não devem ser exibidos em produção

// Carregar configuração
function loadConfig() {
    $configFile = 'config.json';
    if (!file_exists($configFile)) {
        throw new Exception("Arquivo de configuração não encontrado.");
    }
    return json_decode(file_get_contents($configFile), true);
}

$config = loadConfig();

// Função para log de erros
function logError($message, $type = 'ERROR') {
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $logMessage = "[$timestamp] [$type] [IP: $ip] $message" . PHP_EOL;
    file_put_contents('error.log', $logMessage, FILE_APPEND | LOCK_EX);
}

// --- FUNÇÕES DE PROCESSAMENTO ---

// Função para sanitizar dados
function sanitizeInput($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

// Função para upload de arquivo
function uploadFile($file, $allowedTypes, $prefix = '') {
    $uploadDir = 'uploads/';
    $maxFileSize = 8 * 1024 * 1024; // 8MB

    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Erro no upload do arquivo: ' . $file['error']);
    }
    
    if ($file['size'] > $maxFileSize) {
        throw new Exception('Arquivo muito grande. Máximo 8MB');
    }
    
    $fileInfo = pathinfo($file['name']);
    $extension = strtolower($fileInfo['extension']);
    
    if (!in_array($extension, $allowedTypes)) {
        throw new Exception('Tipo de arquivo não permitido: ' . $extension);
    }
    
    $fileName = $prefix . '_' . uniqid() . '.' . $extension;
    $filePath = $uploadDir . $fileName;
    
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        throw new Exception('Erro ao salvar arquivo no servidor');
    }
    
    return $fileName;
}

// --- FUNÇÕES DA API WHAPICHAT ---

// Função para enviar mensagem de texto via API
function sendApiTextMessage($token, $number, $message) {
    $url = 'https://app.whapichat.com.br:443/backend/api/messages/send';
    $data = ['number' => $number, 'body' => $message, 'saveOnTicket' => true];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token
    ]);
    
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode !== 200) {
        logError("API (Texto): Falha ao enviar. Status: $httpcode, Resposta: $response");
    } else {
        logError("API (Texto): Mensagem enviada com sucesso para $number.", 'SUCCESS');
    }
    return $httpcode === 200;
}

// Função para enviar arquivo via API
function sendApiMediaMessage($token, $number, $filePath, $fileName) {
    $url = 'https://app.whapichat.com.br:443/backend/api/messages/send';
    
    if (!file_exists($filePath) || !is_readable($filePath)) {
        logError("API (Media): Arquivo não encontrado ou ilegível: $filePath");
        return false;
    }

    $cFile = new CURLFile($filePath, mime_content_type($filePath), $fileName);
    $data = [
        'number' => $number,
        'medias' => $cFile,
        'saveOnTicket' => true
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: multipart/form-data',
        'Authorization: Bearer ' . $token
    ]);

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode !== 200) {
        logError("API (Media): Falha ao enviar $fileName. Status: $httpcode, Resposta: $response");
    } else {
        logError("API (Media): Arquivo $fileName enviado com sucesso para $number.", 'SUCCESS');
    }
    return $httpcode === 200;
}

// --- PROCESSAMENTO PRINCIPAL DO FORMULÁRIO ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        logError("=== INÍCIO DO PROCESSAMENTO (MODO API) ===", 'INFO');
        
        // Validar dados e arquivos (mesma lógica de antes)
        $requiredFields = ['name', 'birthDate', 'maritalStatus', 'phone', 'address', 'city', 'state', 'education', 'isStudying', 'hasCourses', 'hasExperience', 'motivation'];
        foreach ($requiredFields as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Campo obrigatório não preenchido: $field");
            }
        }
        if (!isset($_FILES['resume']) || !isset($_FILES['photo'])) {
            throw new Exception('Arquivos obrigatórios não enviados');
        }

        // Sanitizar dados
        $data = [];
        foreach ($_POST as $key => $value) {
            if (!is_array($value)) {
                $data[$key] = sanitizeInput($value);
            }
        }
        
        // Upload dos arquivos
        $resumeFile = uploadFile($_FILES['resume'], ['pdf'], 'curriculo');
        $photoFile = uploadFile($_FILES['photo'], ['jpg', 'jpeg', 'png', 'gif'], 'foto');
        logError("Uploads concluídos: $resumeFile, $photoFile", 'SUCCESS');

        // Salvar dados no log local
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'ip' => $_SERVER['REMOTE_ADDR'],
            'data' => $data,
            'files' => ['resume' => $resumeFile, 'photo' => $photoFile]
        ];
        file_put_contents('curriculos.log', json_encode($logData) . "\n", FILE_APPEND | LOCK_EX);
        logError("Dados salvos no log de currículos", 'SUCCESS');

        // Enviar notificações via API
        $apiToken = $config['apiToken'];
        $notificationNumber = $config['notificationNumber'];

        if (!empty($apiToken) && !empty($notificationNumber)) {
            logError("Iniciando envio de notificação via API para $notificationNumber", 'INFO');

            // 1. Enviar mensagem de texto com os dados
            $textMessage = "
*Novo Currículo Recebido* 📄

*Nome:* {$data['name']}
*Telefone:* {$data['phone']}
*Email:* " . ($data['email'] ?? 'N/A') . "
*Cidade:* {$data['city']}
*Motivação:* {$data['motivation']}

_Os arquivos (currículo e foto) serão enviados em seguida._
            ";
            sendApiTextMessage($apiToken, $notificationNumber, trim($textMessage));

            // 2. Enviar arquivo do currículo
            sendApiMediaMessage($apiToken, $notificationNumber, 'uploads/' . $resumeFile, $resumeFile);

            // 3. Enviar arquivo da foto
            sendApiMediaMessage($apiToken, $notificationNumber, 'uploads/' . $photoFile, $photoFile);

            logError("Notificações da API enviadas.", 'SUCCESS');
        } else {
            logError("API Token ou Número de Notificação não configurado. Notificação não enviada.", 'WARNING');
        }

        // Resposta de sucesso para o frontend
        echo json_encode([
            'success' => true,
            'message' => 'Currículo cadastrado com sucesso!'
        ]);

    } catch (Exception $e) {
        logError("ERRO NO PROCESSAMENTO: " . $e->getMessage());
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
}
?>

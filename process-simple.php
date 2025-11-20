<?php
header('Content-Type: application/json; charset=utf-8');

// Incluir o arquivo de conexão com o banco de dados
require_once 'db_connect.php';

// --- FUNÇÕES GLOBAIS ---

ini_set('display_errors', 0);
error_reporting(E_ALL);

function logError($message, $type = 'ERROR') {
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $logMessage = "[$timestamp] [$type] [IP: $ip] $message" . PHP_EOL;
    file_put_contents('error.log', $logMessage, FILE_APPEND | LOCK_EX);
}

// Carregar configuração do banco de dados
function loadConfigFromDB($pdo) {
    $config = [];
    $stmt = $pdo->query("SELECT chave, valor FROM config");
    while ($row = $stmt->fetch()) {
        $config[$row['chave']] = $row['valor'];
    }
    return $config;
}

// --- FUNÇÕES DE PROCESSAMENTO ---

function sanitizeInput($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

function cleanPhoneNumber($phone) {
    return preg_replace('/\D/', '', $phone);
}

function uploadFile($file, $allowedTypes, $prefix = '') {
    $uploadDir = 'uploads/';
    $maxFileSize = 15 * 1024 * 1024;


    if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
    if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception('Erro no upload: ' . $file['error']);
    if ($file['size'] > $maxFileSize) throw new Exception('Arquivo muito grande (Max 15MB)');
    
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedTypes)) throw new Exception('Tipo de arquivo não permitido: ' . $extension);
    
    $fileName = $prefix . '_' . uniqid() . '.' . $extension;
    $filePath = $uploadDir . $fileName;
    
    if (!move_uploaded_file($file['tmp_name'], $filePath)) throw new Exception('Erro ao salvar arquivo');
    
    return $fileName;
}

// --- FUNÇÕES DA API ---

function sendApiTextMessage($token, $url, $number, $message) {
    // Validação básica do número
    if (strlen($number) < 12 || !str_starts_with($number, '55')) {
        logError("API (Texto): Número de telefone inválido: $number");
        return false;
    }

    $data = ['number' => $number, 'body' => $message, 'saveOnTicket' => true];
    logError("API (Texto): Preparando para enviar JSON: " . json_encode($data), 'INFO');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token]
    ]);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpcode !== 200) logError("API (Texto): Falha. Status: $httpcode, Resposta: $response");
    return $httpcode === 200;
}

function sendApiMediaMessage($token, $url, $number, $filePath, $fileName) {
    // Validação básica do número
    if (strlen($number) < 12 || !str_starts_with($number, '55')) {
        logError("API (Media): Número de telefone inválido: $number");
        return false;
    }
    if (!file_exists($filePath)) {
        logError("API (Media): Arquivo não encontrado para envio: $filePath");
        return false;
    }

    $cFile = new CURLFile($filePath, mime_content_type($filePath), $fileName);
    $data = ['number' => $number, 'medias' => $cFile, 'saveOnTicket' => true];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_HTTPHEADER => ['Content-Type: multipart/form-data', 'Authorization: Bearer ' . $token]
    ]);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpcode !== 200) logError("API (Media): Falha ao enviar $fileName. Status: $httpcode, Resposta: $response");
    return $httpcode === 200;
}


// --- PROCESSAMENTO PRINCIPAL ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $config = loadConfigFromDB($pdo);

        // Sanitizar e coletar dados do POST
        $formData = [];
        $fields = ['name', 'birthDate', 'maritalStatus', 'email', 'facebook', 'instagram', 'address', 'city', 'state', 'education', 'isStudying', 'studyPeriod', 'hasCourses', 'courses', 'hasExperience', 'motivation', 'acceptTerms', 'workSchedule'];
        foreach ($fields as $field) {
            $formData[$field] = sanitizeInput($_POST[$field] ?? '');
        }
        
        // Coletar até 3 telefones e flags WhatsApp
        $phones = [];
        $phones_clean = [];
        $whatsapps = [];

        // Primeiro telefone sem número no nome do campo
        if (!empty($_POST['phone'])) {
            $phone_clean = cleanPhoneNumber($_POST['phone']);
            $phones[] = sanitizeInput($_POST['phone']);
            $phones_clean[] = $phone_clean;
            $whatsapps[] = (isset($_POST['isWhatsapp']) && $_POST['isWhatsapp'] === 'Sim') ? 'Sim' : 'Não';
        }

        // Demais telefones com sufixo numérico
        for ($i = 2; $i <= 3; $i++) {
            $phoneKey = "phone$i";
            $whatsappKey = "isWhatsapp$i";
            if (!empty($_POST[$phoneKey])) {
                $phone_clean = cleanPhoneNumber($_POST[$phoneKey]);
                $phones[] = sanitizeInput($_POST[$phoneKey]);
                $phones_clean[] = $phone_clean;
                $whatsapps[] = (isset($_POST[$whatsappKey]) && $_POST[$whatsappKey] === 'Sim') ? 'Sim' : 'Não';
            }
        }
        $formData['phone'] = implode(', ', $phones);
        $formData['isWhatsapp'] = implode(', ', $whatsapps);
        
        // Converter valores de texto para booleano (1/0) para o banco de dados, mantendo os textos originais para a notificação.
        // Para o banco, salvar 1 se algum dos números for WhatsApp, 0 caso contrário
        $isWhatsapp_db = in_array('Sim', $whatsapps) ? 1 : 0;
        $isStudying_db = ($formData['isStudying'] === 'Sim, estou!') ? 1 : 0;
        $hasCourses_db = ($formData['hasCourses'] === 'Sim') ? 1 : 0;
        $hasExperience_db = ($formData['hasExperience'] === 'Sim') ? 1 : 0;

        // Coletar experiências (simplificado)
        $experiences = [];
        for ($i = 1; $i <= 3; $i++) {
            if (!empty($_POST["company$i"])) {
                $experiences[] = [
                    'company' => sanitizeInput($_POST["company$i"]),
                    'position' => sanitizeInput($_POST["position$i"]),
                    'duration' => sanitizeInput($_POST["duration$i"]),
                ];
            }
        }
        $formData['experiences'] = json_encode($experiences);

        // Upload dos arquivos
        $resumeFile = uploadFile($_FILES['resume'], ['pdf'], 'curriculo');
        $photoFile = uploadFile($_FILES['photo'], ['jpg', 'jpeg', 'png', 'gif'], 'foto');

        // Inserir no banco de dados
        $sql = "INSERT INTO curriculos (nome, data_nascimento, estado_civil, telefone, is_whatsapp, email, facebook, instagram, endereco, cidade, estado, escolaridade, estudando, periodo_estudo, possui_cursos, cursos, possui_experiencia, experiencias, motivacao, arquivo_curriculo, arquivo_foto, ip_cadastro) 
                VALUES (:nome, :data_nascimento, :estado_civil, :telefone, :is_whatsapp, :email, :facebook, :instagram, :endereco, :cidade, :estado, :escolaridade, :estudando, :periodo_estudo, :possui_cursos, :cursos, :possui_experiencia, :experiencias, :motivacao, :arquivo_curriculo, :arquivo_foto, :ip_cadastro)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':nome' => addslashes($formData['name']),
            ':data_nascimento' => $formData['birthDate'],
            ':estado_civil' => addslashes($formData['maritalStatus']),
            ':telefone' => addslashes($formData['phone']), // concatenated phones
            ':is_whatsapp' => $isWhatsapp_db,
            ':email' => addslashes($formData['email']),
            ':facebook' => addslashes($formData['facebook']),
            ':instagram' => addslashes($formData['instagram']),
            ':endereco' => addslashes($formData['address']),
            ':cidade' => addslashes($formData['city']),
            ':estado' => addslashes($formData['state']),
            ':escolaridade' => addslashes($formData['education']),
            ':estudando' => $isStudying_db,
            ':periodo_estudo' => addslashes($formData['studyPeriod']),
            ':possui_cursos' => $hasCourses_db,
            ':cursos' => addslashes($formData['courses']),
            ':possui_experiencia' => $hasExperience_db,
            ':experiencias' => $formData['experiences'],
            ':motivacao' => addslashes($formData['motivation']),
            ':arquivo_curriculo' => $resumeFile,
            ':arquivo_foto' => $photoFile,
            ':ip_cadastro' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);

        // Enviar mensagem de conclusão para números WhatsApp do usuário
        if (!empty($config['api_token']) && !empty($config['api_url']) && !empty($config['completion_message'])) {
            $completionMessage = str_replace('{nome}', $formData['name'], $config['completion_message']);
            foreach ($phones_clean as $index => $phone) {
                if ($whatsapps[$index] === 'Sim') {
                    // Adicionar código do país se não tiver
                    if (!str_starts_with($phone, '55')) {
                        $phone = '55' . $phone;
                    }
                    $success = sendApiTextMessage($config['api_token'], $config['api_url'], $phone, $completionMessage);
                    if (!$success) {
                        logError("Falha ao enviar mensagem de conclusão para $phone", 'WARNING');
                    }
                }
            }
        }

        // Enviar notificações via API
        if (!empty($config['api_token']) && !empty($config['notification_number'])) {
            logError("Iniciando envio de notificação via API para {$config['notification_number']}", 'INFO');
            
            // Montar a mensagem completa com todos os campos
            $textMessage = "*Novo Currículo Recebido* 📄\n\n";
            $textMessage .= "*--- Dados Pessoais ---*\n";
            $textMessage .= "*Nome:* " . ($formData['name'] ?? 'N/A') . "\n";
            $textMessage .= "*Data de Nasc.:* " . ($formData['birthDate'] ? date('d/m/Y', strtotime($formData['birthDate'])) : 'N/A') . "\n";
            $textMessage .= "*Estado Civil:* " . ($formData['maritalStatus'] ?? 'N/A') . "\n\n";

            $textMessage .= "*--- Contato ---*\n";
            foreach ($phones as $index => $phone) {
                $waStatus = $whatsapps[$index] ?? 'Não';
                $textMessage .= "*Telefone " . ($index + 1) . ":* $phone\n";
                $textMessage .= "*É WhatsApp?:* $waStatus\n";
            }
            $textMessage .= "*Email:* " . ($formData['email'] ?? 'N/A') . "\n\n";

            $textMessage .= "*--- Endereço ---*\n";
            $textMessage .= "*Endereço:* " . ($formData['address'] ?? 'N/A') . "\n";
            $textMessage .= "*Cidade:* " . ($formData['city'] ?? 'N/A') . "\n";
            $textMessage .= "*Estado:* " . ($formData['state'] ?? 'N/A') . "\n\n";

            $textMessage .= "*--- Formação ---*\n";
            $textMessage .= "*Escolaridade:* " . ($formData['education'] ?? 'N/A') . "\n";
            $textMessage .= "*Está Estudando?:* " . ($formData['isStudying'] ?? 'N/A') . "\n";
            if (!empty($formData['studyPeriod'])) {
                $textMessage .= "*Período de Estudo:* " . $formData['studyPeriod'] . "\n";
            }
            $textMessage .= "*Possui Cursos?:* " . ($formData['hasCourses'] ?? 'N/A') . "\n";
            if (!empty($formData['courses'])) {
                $textMessage .= "*Cursos:* " . $formData['courses'] . "\n";
            }
            $textMessage .= "\n";

            $textMessage .= "*--- Experiência Profissional ---*\n";
            $textMessage .= "*Possui Experiência?:* " . ($formData['hasExperience'] ?? 'N/A') . "\n";
            $experiences = json_decode($formData['experiences'], true);
            if (!empty($experiences)) {
                foreach ($experiences as $i => $exp) {
                    $textMessage .= "*Empresa " . ($i + 1) . ":* " . ($exp['company'] ?? 'N/A') . "\n";
                    $textMessage .= "*Cargo " . ($i + 1) . ":* " . ($exp['position'] ?? 'N/A') . "\n";
                    $textMessage .= "*Duração " . ($i + 1) . ":* " . ($exp['duration'] ?? 'N/A') . "\n";
                }
            }
            $textMessage .= "\n";

            $textMessage .= "*--- Objetivo ---*\n";
            $textMessage .= "*Motivação:* " . ($formData['motivation'] ?? 'N/A') . "\n\n";

            $textMessage .= "*--- Termos e Condições ---*\n";
            $textMessage .= "*Aceita os termos?:* " . ($formData['acceptTerms'] ?? 'N/A') . "\n";
            $textMessage .= "*Horário da vaga:* " . ($formData['workSchedule'] ?? 'N/A') . "\n\n";

            $textMessage .= "*--- Redes Sociais ---*\n";
            $textMessage .= "*Facebook:* " . ($formData['facebook'] ?? 'N/A') . "\n";
            $textMessage .= "*Instagram:* " . ($formData['instagram'] ?? 'N/A') . "\n\n";

            $textMessage .= "_Os arquivos (currículo e foto) serão enviados em seguida._";


            $textSuccess = sendApiTextMessage($config['api_token'], $config['api_url'], $config['notification_number'], trim($textMessage));
            if (!$textSuccess) {
                throw new Exception("Falha ao enviar notificação de texto via API. Verifique os logs.");
            }

            $resumeSuccess = sendApiMediaMessage($config['api_token'], $config['api_url'], $config['notification_number'], 'uploads/' . $resumeFile, $resumeFile);
             if (!$resumeSuccess) {
                logError("Falha ao enviar o PDF do currículo via API. Continuando...", 'WARNING');
            }

            $photoSuccess = sendApiMediaMessage($config['api_token'], $config['api_url'], $config['notification_number'], 'uploads/' . $photoFile, $photoFile);
            if (!$photoSuccess) {
                logError("Falha ao enviar a foto via API. Continuando...", 'WARNING');
            }
        } else {
            logError("API Token ou Número de Notificação não configurado. Notificação pulada.", 'WARNING');
        }

        // Enviar notificação por email usando mail() do servidor
        if (!empty($config['smtp_from']) && !empty($config['notification_email'])) {
            $emailSubject = "Novo Currículo Recebido - " . $formData['name'];
            $emailBody = str_replace("*", "", $textMessage); // Remove markdown for plain text
            $headers = "From: " . $config['smtp_from'] . "\r\n";
            $headers .= "Reply-To: " . $config['smtp_from'] . "\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $emailSuccess = mail($config['notification_email'], $emailSubject, $emailBody, $headers);
            if (!$emailSuccess) {
                logError("Falha ao enviar notificação por email", 'WARNING');
            }
        } else {
            logError("Email remetente ou destinatário não configurado. Notificação por email pulada.", 'WARNING');
        }

        echo json_encode(['success' => true, 'message' => 'Currículo cadastrado com sucesso!']);

    } catch (Exception $e) {
        logError("ERRO NO PROCESSAMENTO: " . $e->getMessage());
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
}
?>

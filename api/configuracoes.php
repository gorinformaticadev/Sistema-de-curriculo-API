<?php
/**
 * API para gerenciamento de configurações
 * Responsável por operações CRUD das configurações do sistema
 */

/**
 * Salva configurações da API
 */
function apiSaveApiConfig($pdo, $configData) {
    if (!isAdmin()) {
        logError("Tentativa de salvamento de configuração API sem permissão admin", 'WARNING');
        return jsonResponse(false, 'Acesso negado: permissões insuficientes.');
    }
    
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        return jsonResponse(false, 'Erro de validação de segurança (CSRF).');
    }
    
    $params = ['api_url', 'api_token', 'notification_number', 'completion_message'];
    $updated = 0;
    
    try {
        $stmt = $pdo->prepare("UPDATE config SET valor = ? WHERE chave = ?");
        
        foreach ($params as $param) {
            if (isset($_POST[$param])) {
                $value = sanitizeInput($_POST[$param]);
                $stmt->execute([$value, $param]);
                $updated++;
            }
        }
        
        logAccess("Configurações da API atualizadas", [
            'parametros_atualizados' => $updated,
            'atualizado_por' => $_SESSION['user_email']
        ]);
        
        return jsonResponse(true, "$updated configuração(ões) salva(s) com sucesso!");
        
    } catch (Exception $e) {
        logError('Erro ao salvar configurações da API: ' . $e->getMessage());
        return jsonResponse(false, 'Erro ao salvar configurações.');
    }
}

/**
 * Salva configurações de email
 */
function apiSaveEmailConfig($pdo, $configData) {
    if (!isAdmin()) {
        logError("Tentativa de salvamento de configuração de email sem permissão admin", 'WARNING');
        return jsonResponse(false, 'Acesso negado: permissões insuficientes.');
    }
    
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        return jsonResponse(false, 'Erro de validação de segurança (CSRF).');
    }
    
    $params = ['smtp_from', 'notification_email'];
    $updated = 0;
    
    try {
        $stmt = $pdo->prepare("UPDATE config SET valor = ? WHERE chave = ?");
        
        foreach ($params as $param) {
            if (isset($_POST[$param])) {
                $value = sanitizeInput($_POST[$param]);
                
                // Validar email se for campos de email
                if (in_array($param, ['smtp_from', 'notification_email']) && !validateEmail($value)) {
                    return jsonResponse(false, 'Email inválido: ' . $param);
                }
                
                $stmt->execute([$value, $param]);
                $updated++;
            }
        }
        
        logAccess("Configurações de email atualizadas", [
            'parametros_atualizados' => $updated,
            'atualizado_por' => $_SESSION['user_email']
        ]);
        
        return jsonResponse(true, "$updated configuração(ões) de email salva(s) com sucesso!");
        
    } catch (Exception $e) {
        logError('Erro ao salvar configurações de email: ' . $e->getMessage());
        return jsonResponse(false, 'Erro ao salvar configurações.');
    }
}

/**
 * Carrega todas as configurações
 */
function apiLoadConfig($pdo) {
    if (!isLoggedIn()) {
        return jsonResponse(false, 'Usuário não autenticado.');
    }
    
    try {
        $config = getConfig();
        
        // Não retornar dados sensíveis
        $safeConfig = [
            'api_url' => $config['api_url'] ?? '',
            'api_token' => $config['api_token'] ? '***configurado***' : '',
            'notification_number' => $config['notification_number'] ?? '',
            'completion_message' => $config['completion_message'] ?? '',
            'smtp_from' => $config['smtp_from'] ?? '',
            'notification_email' => $config['notification_email'] ?? ''
        ];
        
        return jsonResponse(true, 'Configurações carregadas com sucesso', ['config' => $safeConfig]);
        
    } catch (Exception $e) {
        logError('Erro ao carregar configurações: ' . $e->getMessage());
        return jsonResponse(false, 'Erro ao carregar configurações.');
    }
}

/**
 * Testa a configuração da API
 */
function apiTestApiConfig($pdo, $configData) {
    if (!isAdmin()) {
        logError("Tentativa de teste da API sem permissão admin", 'WARNING');
        return jsonResponse(false, 'Acesso negado: permissões insuficientes.');
    }
    
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        return jsonResponse(false, 'Erro de validação de segurança (CSRF).');
    }
    
    $number = sanitizeInput($_POST['number'] ?? '');
    $body = sanitizeInput($_POST['body'] ?? '');
    
    if (empty($number) || empty($body)) {
        return jsonResponse(false, 'Número e mensagem são obrigatórios.');
    }
    
    // Validar formato do número
    $number = preg_replace('/[^0-9]/', '', $number);
    if (strlen($number) < 10 || strlen($number) > 15) {
        return jsonResponse(false, 'Número de telefone inválido. Use o formato internacional com código do país.');
    }
    
    $config = getConfig();
    $token = $config['api_token'];
    $url = $config['api_url'];
    
    if (empty($token) || empty($url)) {
        return jsonResponse(false, 'URL ou Token da API não configurados.');
    }
    
    try {
        $data = [
            'number' => $number, 
            'body' => $body
        ];
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json', 
                'Authorization: Bearer ' . $token
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        $status = $httpcode === 200;
        $message = "Status HTTP: $httpcode\n";
        
        if ($curlError) {
            $message .= "Erro CURL: $curlError\n";
        }
        
        $message .= "Resposta: " . htmlspecialchars($response);
        
        logAccess("Teste da API WhapiChat", [
            'status_code' => $httpcode,
            'success' => $status,
            'destino' => $number,
            'curl_error' => $curlError ?: null
        ]);
        
        return jsonResponse($status, $message);
        
    } catch (Exception $e) {
        logError('Erro no teste da API: ' . $e->getMessage());
        return jsonResponse(false, 'Erro no teste da API: ' . $e->getMessage());
    }
}

/**
 * Valida configuração de email
 */
function apiTestEmailConfig($pdo, $configData) {
    if (!isAdmin()) {
        return jsonResponse(false, 'Acesso negado: permissões insuficientes.');
    }
    
    $email = sanitizeInput($_POST['test_email'] ?? '');
    
    if (empty($email) || !validateEmail($email)) {
        return jsonResponse(false, 'Email inválido.');
    }
    
    try {
        $config = getConfig();
        $smtpFrom = $config['smtp_from'] ?? '';
        
        if (empty($smtpFrom)) {
            return jsonResponse(false, 'Email remetente não configurado.');
        }
        
        // Simulação de teste de email - implementar envio real se necessário
        $testSubject = "Teste de Configuração - " . date('d/m/Y H:i:s');
        $testMessage = "Este é um email de teste das configurações do sistema.\n\nSe receber esta mensagem, a configuração está funcionando corretamente.";
        
        logAccess("Teste de configuração de email", [
            'email_destino' => $email,
            'email_remetente' => $smtpFrom
        ]);
        
        // Por enquanto, apenas logamos. Implementar envio real de email se necessário
        return jsonResponse(true, "Teste de email configurado com sucesso!\n\nRemetente: $smtpFrom\nDestino: $email\nAssunto: $testSubject");
        
    } catch (Exception $e) {
        logError('Erro no teste de email: ' . $e->getMessage());
        return jsonResponse(false, 'Erro no teste de configuração de email.');
    }
}

/**
 * Reseta configurações para valores padrão
 */
function apiResetConfig($pdo) {
    if (!isAdmin()) {
        return jsonResponse(false, 'Acesso negado: permissões insuficientes.');
    }
    
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        return jsonResponse(false, 'Erro de validação de segurança (CSRF).');
    }
    
    try {
        $defaultConfigs = [
            'api_url' => 'https://app.pluggor.com.br/api/messages/send',
            'notification_number' => '5500000000000',
            'completion_message' => 'Olá {nome}! Obrigado por se cadastrar no nosso sistema. Seu currículo foi recebido com sucesso e entraremos em contato em breve.',
            'smtp_host' => 'smtp.gmail.com',
            'smtp_port' => '587',
            'smtp_from' => 'noreply@gorinformatica.com.br',
            'notification_email' => 'rh@gorinformatica.com.br'
        ];
        
        $updated = 0;
        $stmt = $pdo->prepare("UPDATE config SET valor = ? WHERE chave = ?");
        
        foreach ($defaultConfigs as $key => $value) {
            $stmt->execute([$value, $key]);
            $updated++;
        }
        
        logAccess("Configurações resetadas para padrão", [
            'parametros_resetados' => $updated,
            'resetado_por' => $_SESSION['user_email']
        ]);
        
        return jsonResponse(true, "$updated configuração(ões) resetada(s) para valores padrão!");
        
    } catch (Exception $e) {
        logError('Erro ao resetar configurações: ' . $e->getMessage());
        return jsonResponse(false, 'Erro ao resetar configurações.');
    }
}

/**
 * Backup das configurações
 */
function apiBackupConfig($pdo) {
    if (!isAdmin()) {
        return jsonResponse(false, 'Acesso negado: permissões insuficientes.');
    }
    
    try {
        $stmt = $pdo->query("SELECT chave, valor FROM config ORDER BY chave");
        $config = $stmt->fetchAll();
        
        $backupData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'backup_by' => $_SESSION['user_email'],
            'config' => $config
        ];
        
        $filename = 'config_backup_' . date('Y-m-d_H-i-s') . '.json';
        $filepath = 'backups/' . $filename;
        
        // Criar diretório de backup se não existir
        if (!file_exists('backups')) {
            mkdir('backups', 0755, true);
        }
        
        file_put_contents($filepath, json_encode($backupData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        logAccess("Backup de configurações criado", [
            'arquivo' => $filepath,
            'total_configs' => count($config)
        ]);
        
        return jsonResponse(true, 'Backup criado com sucesso!', ['filename' => $filename]);
        
    } catch (Exception $e) {
        logError('Erro ao criar backup: ' . $e->getMessage());
        return jsonResponse(false, 'Erro ao criar backup das configurações.');
    }
}
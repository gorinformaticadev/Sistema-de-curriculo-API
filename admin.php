<?php
session_start();

// Incluir o arquivo de conexão com o banco de dados e funções de log
require_once 'db_connect.php';

// --- FUNÇÕES GLOBAIS ---

// Função para log de erros (pode ser movida para um arquivo de helpers no futuro)
if (!function_exists('logError')) {
    function logError($message, $type = 'ERROR') {
        $logFile = 'error.log';
        $maxSize = 5 * 1024 * 1024; // 5MB

        if (file_exists($logFile) && filesize($logFile) > $maxSize) {
            rename($logFile, $logFile . '.bak');
        }

        $timestamp = date('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $logMessage = "[$timestamp] [$type] [IP: $ip] $message" . PHP_EOL;
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
}

// Carregar configuração do banco de dados
if (!function_exists('loadConfigFromDB')) {
    function loadConfigFromDB($pdo) {
        $config = [];
        $stmt = $pdo->query("SELECT chave, valor FROM config");
        while ($row = $stmt->fetch()) {
            $config[$row['chave']] = $row['valor'];
        }
        return $config;
    }
}

$config = loadConfigFromDB($pdo);

// Garantir que configurações padrão existam
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
    'notification_email' => 'rh@gorinformatica.com.br',
    'system_version' => '',
    'system_version_info' => ''
];

foreach ($defaultConfigs as $key => $value) {
    if (!isset($config[$key])) {
        $stmt = $pdo->prepare("INSERT INTO config (chave, valor) VALUES (?, ?)");
        $stmt->execute([$key, $value]);
        $config[$key] = $value;
    }
}

// Verificar se é admin
if (!function_exists('isAdmin')) {
    function isAdmin() {
        return isset($_SESSION['admin']) && $_SESSION['admin'] === true;
    }
}

// Verificar tipo de usuário
if (!function_exists('getUserType')) {
    function getUserType() {
        return $_SESSION['user_type'] ?? 'admin'; // padrão admin para compatibilidade
    }
}

// Verificar se usuário pode acessar uma aba específica
if (!function_exists('canAccessTab')) {
    function canAccessTab($tab) {
        $userType = getUserType();
        if ($userType === 'admin') {
            return true; // admin acessa tudo
        } elseif ($userType === 'analisador') {
            return $tab === 'curriculos'; // analisador só acessa currículos
        }
        logError("Tentativa de acesso à aba '$tab' por usuário tipo '$userType' - acesso negado", 'WARNING');
        return false;
    }
}

// Verificar se usuário pode executar uma ação específica
if (!function_exists('canAccessAction')) {
    function canAccessAction($action) {
        $userType = getUserType();
        if ($userType === 'admin' || $userType === 'analisador') {
            return true; // admin e analisador podem tudo
        }
        return false;
    }
}

// --- PROCESSAMENTO DE AÇÕES (POST/GET) ---

// Login
if (isset($_POST['login'])) {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    logError("Tentativa de login para email: $email", 'INFO');
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        logError("Usuário encontrado: {$user['email']}, tipo: {$user['tipo']}, senha hash: " . substr($user['senha'], 0, 10) . "...", 'INFO');
        if (password_verify($password, $user['senha'])) {
            $_SESSION['admin'] = true;
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_type'] = $user['tipo'] ?? 'admin'; // compatibilidade
            logError("Login bem-sucedido para usuário {$user['email']} (tipo: {$_SESSION['user_type']})", 'INFO');
            header('Location: admin.php');
            exit;
        } else {
            logError("Senha incorreta para usuário: $email", 'WARNING');
        }
    } else {
        logError("Usuário não encontrado: $email", 'WARNING');
    }
    $error = 'Credenciais inválidas';
}

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// --- GERAÇÃO DE TOKEN CSRF ---
if (isAdmin() && empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}


// --- APIs INTERNAS (Ações do Painel) ---

// Atualizar credenciais do usuário (permitido para todos os usuários logados)
if (isset($_POST['action']) && $_POST['action'] === 'updateCredentials') {
    header('Content-Type: application/json');

    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Erro de validação de segurança (CSRF).']);
        exit;
    }

    // Log para depuração
    logError("Tentativa de atualização de credenciais. DADOS POST: " . json_encode($_POST) . " | SESSÃO: " . json_encode($_SESSION), 'INFO');

    $email = $_POST['email'] ?? null;
    $password = $_POST['password'] ?? null;
    $userId = $_SESSION['user_id'] ?? null;

    if (empty($userId)) {
        echo json_encode(['success' => false, 'message' => 'Erro: Sessão de usuário inválida.']);
        exit;
    }

    if (empty($email)) {
        echo json_encode(['success' => false, 'message' => 'O email não pode ser vazio.']);
        exit;
    }

    // Verificar se é admin ou se está alterando apenas a própria senha
    $userType = getUserType();
    if ($userType === 'analisador') {
        // Analisadores só podem alterar senha, não email
        if (empty($password)) {
            echo json_encode(['success' => false, 'message' => 'Analisadores só podem alterar a senha.']);
            exit;
        }
        // Manter o email atual
        $stmt = $pdo->prepare("SELECT email FROM usuarios WHERE id = ?");
        $stmt->execute([$userId]);
        $currentUser = $stmt->fetch();
        $email = $currentUser['email'];
    }

    if (!empty($password)) {
        // Atualiza email e senha
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE usuarios SET email = ?, senha = ? WHERE id = ?");
        $stmt->execute([$email, $newHash, $_SESSION['user_id']]);
    } else {
        // Atualiza apenas o email (apenas admin)
        $stmt = $pdo->prepare("UPDATE usuarios SET email = ? WHERE id = ?");
        $stmt->execute([$email, $_SESSION['user_id']]);
    }
    $_SESSION['user_email'] = $email; // Atualiza o email na sessão
    echo json_encode(['success' => true, 'message' => 'Credenciais atualizadas com sucesso!']);
    exit;
}

// --- APIs para usuários logados (incluindo analisadores) ---
// API para carregar currículos (disponível para admin e analisador)
if (isset($_GET['action']) && $_GET['action'] === 'getCurriculos' && canAccessAction('getCurriculos')) {
    header('Content-Type: application/json');

    // Parâmetros de filtro
    $statusFilter = $_GET['status'] ?? '';
    $showArchived = isset($_GET['show_archived']) && $_GET['show_archived'] === '1';

    // Construir query com filtros
    $where = [];
    $params = [];

    if ($statusFilter) {
        $where[] = "status = ?";
        $params[] = $statusFilter;
    } elseif (!$showArchived) {
        // Por padrão, não mostrar arquivados
        $where[] = "status != 'arquivado'";
    }

    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    $stmt = $pdo->prepare("SELECT id, nome, telefone, email, cidade, data_cadastro, status, visualizado, data_visualizacao, is_whatsapp FROM curriculos {$whereClause} ORDER BY data_cadastro DESC");
    $stmt->execute($params);
    $curriculos = $stmt->fetchAll();

    echo json_encode(['success' => true, 'curriculos' => $curriculos]);
    exit;
}

// API para buscar detalhes de um currículo específico (disponível para admin e analisador)
if (isset($_GET['action']) && $_GET['action'] === 'getCurriculoDetails' && isset($_GET['id']) && canAccessAction('getCurriculoDetails')) {
    header('Content-Type: application/json');
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'ID inválido.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM curriculos WHERE id = ?");
    $stmt->execute([$id]);
    $curriculo = $stmt->fetch();

    if ($curriculo) {
        // Marcar como visualizado se ainda não foi
        if (!$curriculo['visualizado']) {
            $updateStmt = $pdo->prepare("UPDATE curriculos SET visualizado = 1, data_visualizacao = NOW(), status = CASE WHEN status = 'pendente_novo' THEN 'pendente' ELSE status END WHERE id = ?");
            $updateStmt->execute([$id]);
            $curriculo['visualizado'] = 1;
            $curriculo['data_visualizacao'] = date('Y-m-d H:i:s');
            if ($curriculo['status'] === 'pendente_novo') {
                $curriculo['status'] = 'pendente';
            }
        }

        // Decodificar o JSON de experiências para um formato mais amigável
        if (!empty($curriculo['experiencias'])) {
            $curriculo['experiencias'] = json_decode($curriculo['experiencias'], true);
        }
        // Decodificar JSON de habilidades e referências
        if (!empty($curriculo['habilidades'])) {
            $habilidadesDecoded = json_decode($curriculo['habilidades'], true);
            $curriculo['habilidades'] = is_array($habilidadesDecoded) ? $habilidadesDecoded : [];
        } else {
            $curriculo['habilidades'] = [];
        }
        if (!empty($curriculo['referencias'])) {
            $referenciasDecoded = json_decode($curriculo['referencias'], true);
            $curriculo['referencias'] = is_array($referenciasDecoded) ? $referenciasDecoded : [];
        } else {
            $curriculo['referencias'] = [];
        }
        // Converter valores booleanos de volta para texto para exibição
        $curriculo['is_whatsapp'] = $curriculo['is_whatsapp'] ? 'Sim' : 'Não';
        $curriculo['possui_filhos'] = $curriculo['possui_filhos'] ? 'Sim' : 'Não';
        $curriculo['estudando'] = $curriculo['estudando'] ? 'Sim, estou!' : 'Não, não estou!';
        $curriculo['possui_cursos'] = $curriculo['possui_cursos'] ? 'Sim' : 'Não';
        $curriculo['possui_experiencia'] = $curriculo['possui_experiencia'] ? 'Sim' : 'Não';
        $curriculo['consentimento_lgpd'] = !empty($curriculo['consentimento_lgpd']) ? 'Sim' : 'Não';
        $curriculo['consentimento_banco_talentos'] = !empty($curriculo['consentimento_banco_talentos']) ? 'Sim' : 'Não';

        echo json_encode(['success' => true, 'curriculo' => $curriculo]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Currículo não encontrado.']);
    }
    exit;
}

// Verificar permissões para ações específicas
$currentAction = $_POST['action'] ?? $_GET['action'] ?? '';
if (!canAccessAction($currentAction)) {
    logError("Tentativa de acesso não autorizado à ação '$currentAction' por usuário tipo '" . getUserType() . "'", 'WARNING');
    echo json_encode(['success' => false, 'message' => 'Acesso negado: permissões insuficientes.']);
    exit;
}

// API para atualizar status do currículo
if (isset($_POST['action']) && $_POST['action'] === 'updateCurriculoStatus' && canAccessAction('updateCurriculoStatus')) {
    header('Content-Type: application/json');

    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Erro de validação de segurança (CSRF).']);
        exit;
    }

    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $status = $_POST['status'] ?? '';

    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'ID inválido.']);
        exit;
    }

    $validStatuses = ['pendente_novo', 'pendente', 'entrevista', 'teste', 'aprovado', 'classificado', 'banco_talentos', 'arquivado'];
    if (!in_array($status, $validStatuses)) {
        echo json_encode(['success' => false, 'message' => 'Status inválido.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("UPDATE curriculos SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);

        logError("Status do currículo ID {$id} alterado para '{$status}' pelo usuário {$_SESSION['user_email']}", 'INFO');
        echo json_encode(['success' => true, 'message' => 'Status atualizado com sucesso!']);
    } catch (PDOException $e) {
        logError('Erro ao atualizar status do currículo: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Erro ao atualizar status.']);
    }
    exit;
}

if (isAdmin()) {
    // Teste de envio da API
    if (isset($_POST['action']) && $_POST['action'] === 'testApiSend') {
        header('Content-Type: application/json');
        $number = $_POST['number'] ?? '';
        $body = $_POST['body'] ?? '';
        $token = $config['api_token'];
        $url = $config['api_url'];

        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            echo json_encode(['success' => false, 'message' => 'Erro de validação de segurança (CSRF).']);
            exit;
        }

        if (empty($token) || empty($url)) {
            echo json_encode(['success' => false, 'message' => 'URL ou Token da API não configurados.']);
            exit;
        }

        $data = ['number' => $number, 'body' => $body];
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

        logError("Teste de API: Status $httpcode, Resposta: $response", 'INFO');

        // A API Pluggor responde 200 ou 201 (Created) em caso de sucesso
        $envioOk = ($httpcode >= 200 && $httpcode < 300);
        echo json_encode([
            'success' => $envioOk,
            'message' => "Status: $httpcode\nResposta: " . htmlspecialchars($response)
        ]);
        exit;
    }

    // Salvar configurações gerais
    if (isset($_POST['action']) && $_POST['action'] === 'saveApiConfig') {
        header('Content-Type: application/json');

        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            echo json_encode(['success' => false, 'message' => 'Erro de validação de segurança (CSRF).']);
            exit;
        }

        $updated = 0;
        $params = ['api_url', 'api_token', 'notification_number', 'completion_message'];
        $stmt = $pdo->prepare("UPDATE config SET valor = ? WHERE chave = ?");
        foreach ($params as $param) {
            if (isset($_POST[$param])) {
                $stmt->execute([$_POST[$param], $param]);
                $updated++;
            }
        }
        echo json_encode(['success' => true, 'message' => "$updated configurações salvas!"]);
        exit;
    }

    // Salvar configurações de email
    if (isset($_POST['action']) && $_POST['action'] === 'saveEmailConfig') {
        header('Content-Type: application/json');

        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            echo json_encode(['success' => false, 'message' => 'Erro de validação de segurança (CSRF).']);
            exit;
        }

        $updated = 0;
        $params = ['smtp_from', 'notification_email'];
        $stmt = $pdo->prepare("UPDATE config SET valor = ? WHERE chave = ?");
        foreach ($params as $param) {
            if (isset($_POST[$param])) {
                $stmt->execute([$_POST[$param], $param]);
                $updated++;
            }
        }
        echo json_encode(['success' => true, 'message' => "$updated configurações salvas!"]);
        exit;
    }

    // Outras APIs (getCurriculos, getLogs, etc.) permanecem as mesmas por enquanto, mas precisarão ser adaptadas
    
    // API para limpar logs
    if (isset($_POST['action']) && $_POST['action'] === 'clearLogs') {
        header('Content-Type: application/json');

        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            echo json_encode(['success' => false, 'message' => 'Erro de validação de segurança (CSRF).']);
            exit;
        }

        if (file_exists('error.log')) {
            file_put_contents('error.log', '');
            logError("Logs limpos pelo administrador", 'INFO');
        }
        echo json_encode(['success' => true]);
        exit;
    }
    
    // API para carregar logs de erro
    if (isset($_GET['action']) && $_GET['action'] === 'getLogs') {
        header('Content-Type: application/json');
        $logs = [];
        if (file_exists('error.log')) {
            $lines = file('error.log', FILE_IGNORE_NEW_LINES);
            $logs = array_reverse(array_slice($lines, -100));
        }
        echo json_encode(['success' => true, 'logs' => $logs]);
        exit;
    }

    // API para carregar logs de acesso
    if (isset($_GET['action']) && $_GET['action'] === 'getAccessLogs') {
        header('Content-Type: application/json');
        $logs = [];
        if (file_exists('access.log')) {
            $lines = file('access.log', FILE_IGNORE_NEW_LINES);
            $logs = array_reverse(array_slice($lines, -100));
        }
        echo json_encode(['success' => true, 'logs' => $logs]);
        exit;
    }

    // API para limpar logs de acesso
    if (isset($_POST['action']) && $_POST['action'] === 'clearAccessLogs') {
        header('Content-Type: application/json');

        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            echo json_encode(['success' => false, 'message' => 'Erro de validação de segurança (CSRF).']);
            exit;
        }

        if (file_exists('access.log')) {
            file_put_contents('access.log', '');
            logError("Logs de acesso limpos pelo administrador", 'INFO');
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // API para limpar interações do formulário
    if (isset($_POST['action']) && $_POST['action'] === 'clearInteractions') {
        header('Content-Type: application/json');

        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            echo json_encode(['success' => false, 'message' => 'Erro de validação de segurança (CSRF).']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("TRUNCATE TABLE form_interactions");
            $stmt->execute();
            logError("Todas as interações foram limpas pelo administrador", 'WARNING');
            echo json_encode(['success' => true, 'message' => 'Todas as interações foram limpas com sucesso.']);
        } catch (PDOException $e) {
            logError('Erro ao limpar interações: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Erro ao limpar interações.']);
        }
        exit;
    }


    // API para deletar um currículo
    if (isset($_POST['action']) && $_POST['action'] === 'deleteCurriculo') {
        header('Content-Type: application/json');

        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            echo json_encode(['success' => false, 'message' => 'Erro de validação de segurança (CSRF).']);
            exit;
        }

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'ID inválido.']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            // 1. Buscar os nomes dos arquivos antes de deletar o registro
            $stmt = $pdo->prepare("SELECT arquivo_curriculo, arquivo_foto FROM curriculos WHERE id = ?");
            $stmt->execute([$id]);
            $files = $stmt->fetch();

            if (!$files) {
                throw new Exception('Currículo não encontrado no banco de dados.');
            }

            // 2. Deletar o registro do banco de dados
            $stmt = $pdo->prepare("DELETE FROM curriculos WHERE id = ?");
            $stmt->execute([$id]);
            
            // 3. Deletar os arquivos físicos
            $uploadDir = 'uploads/';
            if (!empty($files['arquivo_curriculo']) && file_exists($uploadDir . $files['arquivo_curriculo'])) {
                unlink($uploadDir . $files['arquivo_curriculo']);
            }
            if (!empty($files['arquivo_foto']) && file_exists($uploadDir . $files['arquivo_foto'])) {
                unlink($uploadDir . $files['arquivo_foto']);
            }
            
            $pdo->commit();
            logError("Currículo ID: $id deletado com sucesso pelo administrador.", 'INFO');
            echo json_encode(['success' => true, 'message' => 'Currículo deletado com sucesso.']);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            logError("Falha ao deletar currículo ID: $id. Erro: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Erro ao deletar o currículo: ' . $e->getMessage()]);
        }
        exit;
    }

    // API para buscar estatísticas de interações
    if (isset($_GET['action']) && $_GET['action'] === 'getInteractionStats') {
        header('Content-Type: application/json');
        
        try {
            // Total de sessões únicas
            $stmt = $pdo->query("SELECT COUNT(DISTINCT session_id) as total FROM form_interactions");
            $totalSessions = $stmt->fetchColumn();

            // Formulários completos: sessões com ação de submit real
            $stmt = $pdo->query("
                SELECT COUNT(DISTINCT session_id) as total 
                FROM form_interactions 
                WHERE acao IN ('form_submitted', 'form_submit_click')
            ");
            $completedForms = $stmt->fetchColumn();

            // Também contar por IP + janela de 5 min (fallback)
            $stmt = $pdo->query("
                SELECT COUNT(DISTINCT fi.session_id) as total
                FROM form_interactions fi
                INNER JOIN curriculos c 
                    ON c.ip_cadastro = fi.ip 
                    AND ABS(TIMESTAMPDIFF(MINUTE, fi.timestamp, c.data_cadastro)) <= 5
                WHERE fi.acao NOT IN ('form_access', 'form_abandoned')
            ");
            $completedByCurriculo = $stmt->fetchColumn();
            $completedForms = max($completedForms, $completedByCurriculo);

            // Abandonos
            $abandonedForms = $totalSessions - $completedForms;

            // Taxa de conversão
            $conversionRate = $totalSessions > 0 ? round(($completedForms / $totalSessions) * 100, 1) : 0;

            echo json_encode([
                'success' => true,
                'stats' => [
                    'totalSessions' => $totalSessions,
                    'completedForms' => $completedForms,
                    'abandonedForms' => $abandonedForms,
                    'conversionRate' => $conversionRate . '%'
                ]
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // API para buscar análise de abandono
    if (isset($_GET['action']) && $_GET['action'] === 'getAbandonmentAnalysis') {
        header('Content-Type: application/json');
        
        try {
            // Buscar último campo de cada sessão que NÃO completou o formulário
            $stmt = $pdo->query("
                SELECT 
                    ultimo_campo,
                    COUNT(*) as count
                FROM (
                    SELECT 
                        fi.session_id,
                        (SELECT fi2.ultimo_campo 
                         FROM form_interactions fi2 
                         WHERE fi2.session_id = fi.session_id 
                         AND fi2.ultimo_campo IS NOT NULL 
                         AND fi2.ultimo_campo != '' 
                         AND fi2.ultimo_campo NOT IN ('Acesso ao Formulário', 'form_access') 
                         ORDER BY fi2.id DESC LIMIT 1) as ultimo_campo
                    FROM form_interactions fi
                    WHERE fi.session_id NOT IN (
                        SELECT DISTINCT session_id 
                        FROM form_interactions 
                        WHERE acao IN ('form_submitted', 'form_submit_click')
                    )
                    GROUP BY fi.session_id
                ) as abandoned_sessions
                WHERE ultimo_campo IS NOT NULL AND ultimo_campo != ''
                GROUP BY ultimo_campo
                ORDER BY count DESC
                LIMIT 10
            ");
            
            $abandonmentData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data' => $abandonmentData
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // API para buscar sessões de interações
    if (isset($_GET['action']) && $_GET['action'] === 'getInteractionSessions') {
        header('Content-Type: application/json');
        
        $period = $_GET['period'] ?? 'week';
        $device = $_GET['device'] ?? '';
        $name = $_GET['name'] ?? '';

        try {
            // Construir query com filtros
            $where = ["1=1"];
            $params = [];

            // Filtro de período
            switch ($period) {
                case 'today':
                    $where[] = "timestamp >= DATE_SUB(NOW(), INTERVAL 1 DAY)";
                    break;
                case 'week':
                    $where[] = "timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                    break;
                case 'month':
                    $where[] = "timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
                    break;
            }

            // Filtro de dispositivo
            if ($device) {
                $where[] = "device = ?";
                $params[] = $device;
            }

            // Filtro de nome
            if ($name) {
                $where[] = "nome_completo LIKE ?";
                $params[] = "%$name%";
            }

            $whereClause = implode(" AND ", $where);

            // 1. Buscar sessões agrupadas (compatível com ONLY_FULL_GROUP_BY)
            $stmt = $pdo->prepare("
                SELECT 
                    session_id,
                    MAX(ip) as ip,
                    MAX(browser) as browser,
                    MAX(os) as os,
                    MAX(device) as device,
                    MAX(nome_completo) as nome_completo,
                    MAX(ultimo_campo) as ultimo_campo,
                    MIN(timestamp) as first_interaction,
                    MAX(timestamp) as last_interaction,
                    COUNT(*) as interaction_count
                FROM form_interactions
                WHERE $whereClause
                GROUP BY session_id
                ORDER BY last_interaction DESC
                LIMIT 50
            ");
            
            $stmt->execute($params);
            $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 2. Enriquecimento dos dados em PHP para obter nome real, último campo real e status
            foreach ($sessions as &$session) {
                // Nome completo real (se nulo no agrupador)
                if (empty($session['nome_completo'])) {
                    $stmtName = $pdo->prepare("SELECT nome_completo FROM form_interactions WHERE session_id = ? AND nome_completo IS NOT NULL AND nome_completo != '' ORDER BY id DESC LIMIT 1");
                    $stmtName->execute([$session['session_id']]);
                    $session['nome_completo'] = $stmtName->fetchColumn() ?: '';
                }

                // Último campo real (excluindo rótulos genéricos de acesso)
                $stmtField = $pdo->prepare("SELECT ultimo_campo FROM form_interactions WHERE session_id = ? AND ultimo_campo IS NOT NULL AND ultimo_campo != '' AND ultimo_campo NOT IN ('Acesso ao Formulário', 'form_access') ORDER BY id DESC LIMIT 1");
                $stmtField->execute([$session['session_id']]);
                $realLastField = $stmtField->fetchColumn();
                if ($realLastField) {
                    $session['ultimo_campo'] = $realLastField;
                }

                // 1. Verificar se existe currículo cadastrado próximo ao horário da sessão
                $stmtCur = $pdo->prepare("
                    SELECT id FROM curriculos 
                    WHERE ip_cadastro = ? 
                    AND ABS(TIMESTAMPDIFF(MINUTE, data_cadastro, ?)) <= 30
                    LIMIT 1
                ");
                $stmtCur->execute([$session['ip'], $session['last_interaction']]);
                $hasCurriculo = $stmtCur->rowCount() > 0;

                // 2. Verificar se houve clique no botão de finalizar (ação 'form_submitted')
                $stmtSub = $pdo->prepare("
                    SELECT 1 FROM form_interactions 
                    WHERE session_id = ? 
                    AND acao = 'form_submitted' 
                    LIMIT 1
                ");
                $stmtSub->execute([$session['session_id']]);
                $hasSubmitAction = $stmtSub->rowCount() > 0;

                $session['completed'] = $hasCurriculo || $hasSubmitAction;
            }

            echo json_encode([
                'success' => true,
                'sessions' => $sessions
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // API para listar usuários
    if (isset($_GET['action']) && $_GET['action'] === 'getUsers') {
        header('Content-Type: application/json');
        $stmt = $pdo->query("SELECT id, email, tipo, created_at FROM usuarios ORDER BY created_at DESC");
        $users = $stmt->fetchAll();
        echo json_encode(['success' => true, 'users' => $users]);
        exit;
    }

    // API para adicionar usuário
    if (isset($_POST['action']) && $_POST['action'] === 'addUser') {
        header('Content-Type: application/json');

        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            echo json_encode(['success' => false, 'message' => 'Erro de validação de segurança (CSRF).']);
            exit;
        }

        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        $tipo = $_POST['tipo'] ?? 'analisador';

        if (empty($email) || empty($password)) {
            echo json_encode(['success' => false, 'message' => 'Email e senha são obrigatórios.']);
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Email inválido.']);
            exit;
        }

        if (!in_array($tipo, ['admin', 'analisador'])) {
            echo json_encode(['success' => false, 'message' => 'Tipo de usuário inválido.']);
            exit;
        }

        try {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO usuarios (email, senha, tipo) VALUES (?, ?, ?)");
            $stmt->execute([$email, $hashedPassword, $tipo]);
            logError("Novo usuário criado: $email (tipo: $tipo) pelo admin {$_SESSION['user_email']}", 'INFO');
            echo json_encode(['success' => true, 'message' => 'Usuário criado com sucesso!']);
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) { // Duplicate entry
                echo json_encode(['success' => false, 'message' => 'Este email já está cadastrado.']);
            } else {
                logError('Erro ao criar usuário: ' . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Erro ao criar usuário.']);
            }
        }
        exit;
    }

    // API para deletar usuário
    if (isset($_POST['action']) && $_POST['action'] === 'deleteUser') {
        header('Content-Type: application/json');

        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            echo json_encode(['success' => false, 'message' => 'Erro de validação de segurança (CSRF).']);
            exit;
        }

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'ID inválido.']);
            exit;
        }

        // Não permitir deletar o próprio usuário
        if ($id == $_SESSION['user_id']) {
            echo json_encode(['success' => false, 'message' => 'Você não pode deletar seu próprio usuário.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
            $stmt->execute([$id]);
            logError("Usuário ID: $id deletado pelo admin {$_SESSION['user_email']}", 'WARNING');
            echo json_encode(['success' => true, 'message' => 'Usuário deletado com sucesso.']);
        } catch (PDOException $e) {
            logError('Erro ao deletar usuário: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Erro ao deletar usuário.']);
        }
        exit;
    }

    // API para buscar detalhes de uma sessão específica
    if (isset($_GET['action']) && $_GET['action'] === 'getSessionDetails') {
        header('Content-Type: application/json');

        $sessionId = $_GET['session_id'] ?? '';

        if (!$sessionId) {
            echo json_encode(['success' => false, 'message' => 'Session ID não fornecido']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT
                    ultimo_campo,
                    acao,
                    valor_campo,
                    timestamp
                FROM form_interactions
                WHERE session_id = ?
                AND acao != 'form_submitted'
                ORDER BY timestamp ASC
            ");

            $stmt->execute([$sessionId]);
            $interactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'interactions' => $interactions
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // API para enviar mensagem WhatsApp
    if (isset($_POST['action']) && $_POST['action'] === 'sendWhatsAppMessage' && canAccessAction('sendWhatsAppMessage')) {
        header('Content-Type: application/json');

        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            echo json_encode(['success' => false, 'message' => 'Erro de validação de segurança (CSRF).']);
            exit;
        }

        $curriculoId = filter_input(INPUT_POST, 'curriculo_id', FILTER_VALIDATE_INT);
        $mensagem = trim($_POST['mensagem'] ?? '');

        if (!$curriculoId) {
            echo json_encode(['success' => false, 'message' => 'ID do currículo inválido.']);
            exit;
        }

        if (empty($mensagem)) {
            echo json_encode(['success' => false, 'message' => 'Mensagem não pode estar vazia.']);
            exit;
        }

        try {
            // Buscar dados do currículo
            $stmt = $pdo->prepare("SELECT nome, telefone, is_whatsapp FROM curriculos WHERE id = ?");
            $stmt->execute([$curriculoId]);
            $curriculo = $stmt->fetch();

            if (!$curriculo) {
                echo json_encode(['success' => false, 'message' => 'Currículo não encontrado.']);
                exit;
            }

            if (!$curriculo['is_whatsapp']) {
                echo json_encode(['success' => false, 'message' => 'Este contato não possui WhatsApp cadastrado.']);
                exit;
            }

            // Preparar número (remover caracteres não numéricos e adicionar código do país se necessário)
            $numeroLimpo = preg_replace('/\D/', '', $curriculo['telefone']);
            if (strlen($numeroLimpo) == 11 && substr($numeroLimpo, 0, 1) == '0') {
                // Remove o 0 inicial se for número brasileiro
                $numeroLimpo = substr($numeroLimpo, 1);
            }
            if (strlen($numeroLimpo) == 10 || strlen($numeroLimpo) == 11) {
                // Adicionar código do Brasil se não tiver
                $numeroLimpo = '55' . $numeroLimpo;
            }

            // Enviar mensagem via API
            $token = $config['api_token'];
            $url = $config['api_url'];

            if (empty($token) || empty($url)) {
                echo json_encode(['success' => false, 'message' => 'Configuração da API WhatsApp não encontrada.']);
                exit;
            }

            $data = [
                'number' => $numeroLimpo,
                'body' => $mensagem
            ];

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $token
                ]
            ]);

            $response = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $statusEnvio = ($httpcode >= 200 && $httpcode < 300) ? 'enviado' : 'erro';

            // Salvar no histórico
            $stmt = $pdo->prepare("
                INSERT INTO whatsapp_messages
                (curriculo_id, numero_destino, mensagem, status_envio, resposta_api, enviado_por)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $curriculoId,
                $numeroLimpo,
                $mensagem,
                $statusEnvio,
                $response,
                $_SESSION['user_email'] ?? 'Sistema'
            ]);

            logError("WhatsApp enviado para {$curriculo['nome']} ({$numeroLimpo}): " . ($statusEnvio === 'enviado' ? 'Sucesso' : 'Erro'), 'INFO');

            echo json_encode([
                'success' => $statusEnvio === 'enviado',
                'message' => $statusEnvio === 'enviado' ? 'Mensagem enviada com sucesso!' : 'Erro ao enviar mensagem.',
                'response' => $response,
                'numero' => $numeroLimpo
            ]);

        } catch (Exception $e) {
            logError('Erro ao enviar WhatsApp: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Erro interno do servidor.']);
        }
        exit;
    }

    // API para buscar histórico de mensagens WhatsApp
    if (isset($_GET['action']) && $_GET['action'] === 'getWhatsAppHistory' && canAccessAction('getWhatsAppHistory')) {
        header('Content-Type: application/json');

        $curriculoId = filter_input(INPUT_GET, 'curriculo_id', FILTER_VALIDATE_INT);

        if (!$curriculoId) {
            echo json_encode(['success' => false, 'message' => 'ID do currículo inválido.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT
                    id,
                    mensagem,
                    status_envio,
                    resposta_api,
                    enviado_por,
                    data_envio
                FROM whatsapp_messages
                WHERE curriculo_id = ?
                ORDER BY data_envio DESC
                LIMIT 20
            ");
            $stmt->execute([$curriculoId]);
            $mensagens = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'mensagens' => $mensagens
            ]);

        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // API para buscar informações de contato
    if (isset($_GET['action']) && $_GET['action'] === 'getContactInfo' && canAccessAction('getContactInfo')) {
        header('Content-Type: application/json');

        $curriculoId = filter_input(INPUT_GET, 'curriculo_id', FILTER_VALIDATE_INT);

        if (!$curriculoId) {
            echo json_encode(['success' => false, 'message' => 'ID do currículo inválido.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT
                    id,
                    tipo_contato,
                    informacao,
                    observacoes,
                    registrado_por,
                    data_registro
                FROM curriculo_contatos
                WHERE curriculo_id = ?
                ORDER BY data_registro DESC
            ");
            $stmt->execute([$curriculoId]);
            $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'contacts' => $contacts
            ]);

        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // API para adicionar informação de contato
    if (isset($_POST['action']) && $_POST['action'] === 'addContactInfo' && canAccessAction('addContactInfo')) {
        header('Content-Type: application/json');

        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            echo json_encode(['success' => false, 'message' => 'Erro de validação de segurança (CSRF).']);
            exit;
        }

        $curriculoId = filter_input(INPUT_POST, 'curriculo_id', FILTER_VALIDATE_INT);
        $tipoContato = trim($_POST['tipo_contato'] ?? '');
        $informacao = trim($_POST['informacao'] ?? '');
        $observacoes = trim($_POST['observacoes'] ?? '');

        if (!$curriculoId) {
            echo json_encode(['success' => false, 'message' => 'ID do currículo inválido.']);
            exit;
        }

        if (empty($tipoContato) || empty($informacao)) {
            echo json_encode(['success' => false, 'message' => 'Tipo de contato e informação são obrigatórios.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO curriculo_contatos 
                (curriculo_id, tipo_contato, informacao, observacoes, registrado_por, data_registro)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $curriculoId,
                $tipoContato,
                $informacao,
                $observacoes,
                $_SESSION['user_email']
            ]);

            logError("Nova informação de contato adicionada ao currículo ID: $curriculoId por {$_SESSION['user_email']}", 'INFO');
            echo json_encode(['success' => true, 'message' => 'Informação adicionada com sucesso!']);

        } catch (Exception $e) {
            logError('Erro ao adicionar informação de contato: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Erro ao adicionar informação: ' . $e->getMessage()]);
        }
        exit;
    }

    // API para atualizar informação de contato
    if (isset($_POST['action']) && $_POST['action'] === 'updateContactInfo' && canAccessAction('updateContactInfo')) {
        header('Content-Type: application/json');

        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            echo json_encode(['success' => false, 'message' => 'Erro de validação de segurança (CSRF).']);
            exit;
        }

        $contactId = filter_input(INPUT_POST, 'contact_id', FILTER_VALIDATE_INT);
        $curriculoId = filter_input(INPUT_POST, 'curriculo_id', FILTER_VALIDATE_INT);
        $tipoContato = trim($_POST['tipo_contato'] ?? '');
        $informacao = trim($_POST['informacao'] ?? '');
        $observacoes = trim($_POST['observacoes'] ?? '');

        if (!$contactId || !$curriculoId) {
            echo json_encode(['success' => false, 'message' => 'IDs inválidos.']);
            exit;
        }

        if (empty($tipoContato) || empty($informacao)) {
            echo json_encode(['success' => false, 'message' => 'Tipo de contato e informação são obrigatórios.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE curriculo_contatos 
                SET tipo_contato = ?, informacao = ?, observacoes = ?
                WHERE id = ? AND curriculo_id = ?
            ");
            $stmt->execute([
                $tipoContato,
                $informacao,
                $observacoes,
                $contactId,
                $curriculoId
            ]);

            logError("Informação de contato ID: $contactId atualizada por {$_SESSION['user_email']}", 'INFO');
            echo json_encode(['success' => true, 'message' => 'Informação atualizada com sucesso!']);

        } catch (Exception $e) {
            logError('Erro ao atualizar informação de contato: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Erro ao atualizar informação: ' . $e->getMessage()]);
        }
        exit;
    }

    // API para deletar informação de contato
    if (isset($_POST['action']) && $_POST['action'] === 'deleteContactInfo' && canAccessAction('deleteContactInfo')) {
        header('Content-Type: application/json');

        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            echo json_encode(['success' => false, 'message' => 'Erro de validação de segurança (CSRF).']);
            exit;
        }

        $contactId = filter_input(INPUT_POST, 'contact_id', FILTER_VALIDATE_INT);

        if (!$contactId) {
            echo json_encode(['success' => false, 'message' => 'ID inválido.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM curriculo_contatos WHERE id = ?");
            $stmt->execute([$contactId]);

            logError("Informação de contato ID: $contactId deletada por {$_SESSION['user_email']}", 'INFO');
            echo json_encode(['success' => true, 'message' => 'Informação deletada com sucesso!']);

        } catch (Exception $e) {
            logError('Erro ao deletar informação de contato: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Erro ao deletar informação: ' . $e->getMessage()]);
        }
        exit;
    }

    // API para Upload de Atualização (.zip)
    if (isset($_POST['action']) && $_POST['action'] === 'uploadUpdate' && canAccessAction('uploadUpdate')) {
        header('Content-Type: application/json');
        
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            echo json_encode(['success' => false, 'message' => 'Erro de validação de segurança (CSRF).']);
            exit;
        }

        if (!isset($_FILES['update_zip']) || $_FILES['update_zip']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'Erro no upload do arquivo.']);
            exit;
        }

        $fileInfo = pathinfo($_FILES['update_zip']['name']);
        if (strtolower($fileInfo['extension']) !== 'zip') {
            echo json_encode(['success' => false, 'message' => 'Apenas arquivos .zip são permitidos.']);
            exit;
        }

        $uploadDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        
        $tempZip = $uploadDir . 'temp_update.zip';
        $tempExtractDir = $uploadDir . 'temp_update/';
        
        if (move_uploaded_file($_FILES['update_zip']['tmp_name'], $tempZip)) {
            // Extrair
            $zip = new ZipArchive;
            if ($zip->open($tempZip) === TRUE) {
                // Limpar diretório de extração se existir
                if (is_dir($tempExtractDir)) {
                    $files = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($tempExtractDir, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::CHILD_FIRST
                    );
                    foreach ($files as $fileinfo) {
                        $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                        $todo($fileinfo->getRealPath());
                    }
                    rmdir($tempExtractDir);
                }
                
                mkdir($tempExtractDir, 0755, true);
                $zip->extractTo($tempExtractDir);
                $zip->close();
                
                // Validar integridade
                if (file_exists($tempExtractDir . 'manifest.json')) {
                    $manifest = json_decode(file_get_contents($tempExtractDir . 'manifest.json'), true);
                    if ($manifest && isset($manifest['version'])) {
                        echo json_encode([
                            'success' => true, 
                            'message' => 'Upload e validação concluídos.',
                            'version' => $manifest['version'],
                            'notes' => $manifest['notes'] ?? 'Atualização padrão.'
                        ]);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Manifesto inválido no arquivo .zip.']);
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'O arquivo .zip não é uma atualização válida (faltando manifest.json).']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Não foi possível ler o arquivo .zip.']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Falha ao salvar o arquivo enviado.']);
        }
        exit;
    }

    // API para Aplicar Atualização
    if (isset($_POST['action']) && $_POST['action'] === 'applyUpdate' && canAccessAction('applyUpdate')) {
        header('Content-Type: application/json');
        
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            echo json_encode(['success' => false, 'message' => 'Erro de validação de segurança (CSRF).']);
            exit;
        }

        $baseDir = __DIR__;
        $tempExtractDir = $baseDir . '/uploads/temp_update/';
        $backupDir = $baseDir . '/backups/';
        
        if (!is_dir($tempExtractDir) || !file_exists($tempExtractDir . 'manifest.json')) {
            echo json_encode(['success' => false, 'message' => 'Arquivos de atualização não encontrados ou inválidos. Faça o upload novamente.']);
            exit;
        }

        try {
            // 1. Fazer Backup
            if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
            $backupFile = $backupDir . 'backup_' . date('Ymd_His') . '.zip';
            
            if (extension_loaded('zip')) {
                $zip = new ZipArchive();
                if ($zip->open($backupFile, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
                    $files = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::SELF_FIRST
                    );
                    foreach ($files as $file) {
                        $fileReal = realpath($file);
                        if (is_dir($fileReal)) continue;
                        
                        $relativePath = str_replace($baseDir . DIRECTORY_SEPARATOR, '', $fileReal);
                        $relativePath = str_replace('\\', '/', $relativePath);
                        
                        // Ignorar pastas e arquivos seguros
                        if (strpos($relativePath, 'backups/') === 0 || 
                            strpos($relativePath, 'uploads/') === 0 || 
                            strpos($relativePath, '.git/') === 0 ||
                            $relativePath === 'error.log' || 
                            $relativePath === 'access.log') {
                            continue;
                        }
                        $zip->addFile($fileReal, $relativePath);
                    }
                    $zip->close();
                }
            }

            // 2. Copiar novos arquivos recursivamente
            function copyUpdateFilesRecursive($src, $dst, $baseRoot) {
                $dir = opendir($src);
                @mkdir($dst, 0755, true);
                while (false !== ($file = readdir($dir))) {
                    if (($file != '.') && ($file != '..')) {
                        $srcFile = $src . '/' . $file;
                        $dstFile = $dst . '/' . $file;
                        
                        // Ignorar itens protegidos se estivermos na raiz
                        if ($dst === $baseRoot && in_array($file, ['db_connect.php', '.env', 'uploads', 'backups', '.git', 'error.log', 'access.log'])) {
                            continue;
                        }
                        
                        if (is_dir($srcFile)) {
                            copyUpdateFilesRecursive($srcFile, $dstFile, $baseRoot);
                        } else {
                            copy($srcFile, $dstFile);
                        }
                    }
                }
                closedir($dir);
            }
            
            // Lidar com extração caso os arquivos estejam soltos ou dentro de uma pasta raiz do zip
            $sourceDir = $tempExtractDir;
            
            copyUpdateFilesRecursive($sourceDir, $baseDir, $baseDir);

            // 3. Atualizar Banco de Dados
            $dbMessages = [];

            // 3.1 Executar update.sql se existir no pacote (instruções separadas por ;)
            if (file_exists($sourceDir . 'update.sql')) {
                $sql = file_get_contents($sourceDir . 'update.sql');
                if (!empty(trim($sql))) {
                    try {
                        $statements = array_values(array_filter(array_map('trim', explode(';', $sql))));
                        $executadas = 0;
                        foreach ($statements as $statement) {
                            $pdo->exec($statement);
                            $executadas++;
                        }
                        $dbMessages[] = "update.sql executado ({$executadas} instruções)";
                        logError("update.sql executado com sucesso ({$executadas} instruções).", 'INFO');
                    } catch (PDOException $e) {
                        $dbMessages[] = 'update.sql com erro';
                        logError('Erro ao rodar update.sql: ' . $e->getMessage(), 'ERROR');
                    }
                }
            }

            // 3.2 Executar migrações pendentes do sistema de migração versionada
            //     (em processo separado para garantir a versão nova do includes/migration.php)
            $migrationsApplied = 0;
            $migrationsMessage = 'banco já atualizado';
            if (function_exists('curl_init')) {
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $basePath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
                $basePath = rtrim($basePath, '/');
                $migrationUrl = $protocol . '://' . $host . $basePath . '/run_migrations.php';

                $ch = curl_init($migrationUrl);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => ['key' => 'internal'],
                    CURLOPT_TIMEOUT => 120,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false
                ]);
                $migrationResponse = curl_exec($ch);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($migrationResponse !== false) {
                    $migrationData = json_decode($migrationResponse, true);
                    if (is_array($migrationData) && !empty($migrationData['success'])) {
                        $migrationsApplied = count($migrationData['applied_migrations'] ?? []);
                        $migrationsMessage = $migrationsApplied > 0
                            ? "{$migrationsApplied} migração(ões) aplicada(s)"
                            : 'banco já atualizado';
                        $dbMessages[] = $migrationsMessage;
                        logError('Migrações executadas na instalação: ' . $migrationsMessage, 'INFO');
                    } else {
                        $errDetail = is_array($migrationData) && !empty($migrationData['errors'])
                            ? implode('; ', $migrationData['errors'])
                            : $migrationResponse;
                        $dbMessages[] = 'migrações com erro';
                        logError('Erro nas migrações durante a instalação: ' . $errDetail, 'ERROR');
                    }
                } else {
                    $dbMessages[] = 'migrações: falha de conexão interna';
                    logError('Falha ao chamar run_migrations.php: ' . $curlError, 'ERROR');
                }
            } elseif (class_exists('DatabaseMigration') && method_exists('DatabaseMigration', 'hasPendingMigrations')) {
                // Fallback in-processo (somente se a classe nova já estiver carregada)
                try {
                    $migration = new DatabaseMigration($pdo);
                    if ($migration->hasPendingMigrations()) {
                        $migrationResult = $migration->migrate();
                        if (!empty($migrationResult['success'])) {
                            $migrationsApplied = count($migrationResult['applied_migrations']);
                            $dbMessages[] = $migrationsApplied > 0 ? "{$migrationsApplied} migração(ões) aplicada(s)" : 'banco já atualizado';
                        } else {
                            $dbMessages[] = 'migrações com erro';
                            logError('Erro nas migrações: ' . implode(', ', $migrationResult['errors']), 'ERROR');
                        }
                    } else {
                        $dbMessages[] = 'banco já atualizado';
                    }
                } catch (Exception $migrationError) {
                    $dbMessages[] = 'migrações com erro';
                    logError('Erro ao executar migrações: ' . $migrationError->getMessage(), 'ERROR');
                }
            } else {
                $dbMessages[] = 'migrações não verificadas (curl indisponível)';
                logError('Migrações não executadas na instalação: curl indisponível e classe não carregada.', 'ERROR');
            }

            // 3.3 Registrar a versão instalada (lida do manifest.json do pacote)
            $installedVersion = '';
            if (file_exists($sourceDir . 'manifest.json')) {
                $manifestData = json_decode(file_get_contents($sourceDir . 'manifest.json'), true);
                $installedVersion = is_array($manifestData) ? ($manifestData['version'] ?? '') : '';
            }
            if ($installedVersion !== '') {
                try {
                    $stmtVersion = $pdo->prepare("SELECT id FROM config WHERE chave = 'system_version'");
                    $stmtVersion->execute();
                    if ($stmtVersion->fetch()) {
                        $pdo->prepare("UPDATE config SET valor = ? WHERE chave = 'system_version'")->execute([$installedVersion]);
                    } else {
                        $pdo->prepare("INSERT INTO config (chave, valor) VALUES ('system_version', ?)")->execute([$installedVersion]);
                    }
                    $infoVersao = 'Instalada em ' . date('d/m/Y H:i:s');
                    $stmtInfo = $pdo->prepare("SELECT id FROM config WHERE chave = 'system_version_info'");
                    $stmtInfo->execute();
                    if ($stmtInfo->fetch()) {
                        $pdo->prepare("UPDATE config SET valor = ? WHERE chave = 'system_version_info'")->execute([$infoVersao]);
                    } else {
                        $pdo->prepare("INSERT INTO config (chave, valor) VALUES ('system_version_info', ?)")->execute([$infoVersao]);
                    }
                    $dbMessages[] = "versão {$installedVersion} registrada";
                } catch (Exception $e) {
                    logError('Erro ao registrar versão instalada: ' . $e->getMessage(), 'ERROR');
                }
            }

            // 4. Limpar temporários
            function deleteDir($dirPath) {
                if (!is_dir($dirPath)) return;
                $files = scandir($dirPath);
                foreach ($files as $file) {
                    if ($file != '.' && $file != '..') {
                        $path = $dirPath . '/' . $file;
                        is_dir($path) ? deleteDir($path) : unlink($path);
                    }
                }
                rmdir($dirPath);
            }
            deleteDir($tempExtractDir);
            @unlink($baseDir . '/uploads/temp_update.zip');

            $dbSummary = !empty($dbMessages) ? ' Banco de dados: ' . implode(' | ', $dbMessages) . '.' : '';

            logError("Sistema atualizado com sucesso por {$_SESSION['user_email']}", 'INFO');
            echo json_encode(['success' => true, 'message' => 'Sistema atualizado com sucesso! O backup foi salvo em /backups.' . $dbSummary]);

        } catch (Exception $e) {
            logError('Erro durante a atualização: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Ocorreu um erro durante a atualização.']);
        }
        exit;
    }
}


// Verificar se está logado (página de login)
if (!isAdmin()) {
    // HTML da página de login (sem alterações)
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Admin - GOR INFORMÁTICA</title>
        <link href="styles.css" rel="stylesheet">
    </head>
    <body>
        <div class="container">
            <div class="login-form" style="max-width: 400px; margin: 100px auto; background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.1);">
                <h2>Área Administrativa</h2>
                <?php if (isset($error)): ?>
                    <div class="error-message" style="background: #f8d7da; color: #721c24; padding: 10px; border-radius: 5px; margin-bottom: 15px;"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label>Senha</label>
                        <input type="password" name="password" required>
                    </div>
                    <button type="submit" name="login" class="btn-primary">Entrar</button>
                </form>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// --- PAINEL ADMINISTRATIVO (HTML) ---
$stmt = $pdo->query("SELECT COUNT(*) as total FROM curriculos");
$totalCurriculos = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel Administrativo - GOR INFORMÁTICA</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="styles.css" rel="stylesheet">
    <style>
        /* Estilos do painel (sem grandes alterações, apenas ajustes) */
        .tabs { display: flex; border-bottom: 2px solid #e5e7eb; margin-bottom: 20px; flex-wrap: wrap; }
        .tab { padding: 12px 20px; background: #f9fafb; border: none; cursor: pointer; border-bottom: 3px solid transparent; transition: all 0.3s ease; font-size: 0.9rem; }
        .tab.active { background: white; border-bottom-color: #1e40af; color: #1e40af; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .log-container { background: #1a1a1a; color: #00ff00; padding: 15px; border-radius: 8px; font-family: 'Courier New', monospace; font-size: 0.85rem; max-height: 400px; overflow-y: auto; }
        .config-note { background: #e0f2fe; border: 1px solid #0288d1; border-radius: 8px; padding: 15px; margin-bottom: 20px; }
        .btn-remove {
            background-color: #ef4444; /* red-500 */
            color: white;
        }
        .btn-remove:hover {
            background-color: #dc2626; /* red-600 */
        }
        .btn-small { margin-left: 5px; }

        
        /* Estilos do Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.6);
        }
        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 20px 30px;
            border: 1px solid #888;
            width: 80%;
            max-width: 800px;
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            position: relative;
            animation: fadeIn 0.3s;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .modal-close {
            color: #aaa;
            position: absolute;
            top: 10px;
            right: 20px;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .modal-close:hover, .modal-close:focus { color: black; text-decoration: none; }
        #modalBody h3 { border-bottom: 2px solid #1e40af; padding-bottom: 5px; margin-top: 20px; color: #1e40af; }
        #modalBody p { margin: 5px 0 15px; line-height: 1.6; }
        #modalBody strong { display: inline-block; min-width: 180px; color: #333; }
        .modal-files a { display: inline-block; margin-right: 20px; text-decoration: none; background: #e0f2fe; color: #0c4a6e; padding: 8px 12px; border-radius: 6px; transition: background 0.3s; }
        .modal-files a:hover { background: #bae6fd; }
        .experience-block {
            border-left: 3px solid #e5e7eb;
            padding-left: 15px;
            margin-bottom: 15px;
        }
        .experience-block h4 {
            margin-top: 0;
        }
        .badge-skill {
            display: inline-block;
            background: #eff6ff;
            color: #1e40af;
            border: 1px solid #bfdbfe;
            border-radius: 999px;
            padding: 4px 12px;
            margin: 3px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        /* Estilos do Modal de Imagem (Lightbox) */
        .modal-content-image {
            margin: auto;
            display: block;
            max-width: 90%;
            max-height: 90vh;
            animation: zoomIn 0.3s;
        }
        .image-modal-close {
            position: absolute;
            top: 15px;
            right: 35px;
            color: #f1f1f1;
            font-size: 40px;
            font-weight: bold;
            transition: 0.3s;
            cursor: pointer;
        }
        .image-modal-close:hover,
        .image-modal-close:focus {
            color: #bbb;
        }

        /* Estilos do Modal de PDF */
        .modal-content-pdf {
            background-color: #fefefe;
            margin: 2% auto;
            padding: 0;
            border: 1px solid #888;
            width: 90%;
            height: 95vh;
            max-width: 1000px;
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            position: relative;
            display: flex;
            flex-direction: column;
        }
        .pdf-modal-close {
            position: absolute;
            top: 5px;
            right: 15px;
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            z-index: 10; /* Para ficar sobre o iframe */
        }
        #pdf-viewer {
            width: 100%;
            height: 100%;
            border-radius: 12px;
        }

        /* Estilos para Interações do Formulário */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        }
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
        }
        .stat-info h4 {
            margin: 0 0 5px 0;
            font-size: 0.9rem;
            color: #6b7280;
        }
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            color: #1f2937;
            margin: 0;
        }
        .filters-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .filters-section h4 {
            margin-top: 0;
            color: #1f2937;
        }
        .filters-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .filter-group label {
            font-size: 0.9rem;
            color: #6b7280;
            font-weight: 600;
        }
        .filter-group select,
        .filter-group input {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 0.9rem;
        }
        .abandonment-analysis {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .abandonment-analysis h4 {
            margin-top: 0;
            color: #1f2937;
        }
        .chart-container {
            max-width: 800px;
            margin: 20px auto;
        }
        .sessions-list {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .sessions-list h4 {
            margin-top: 0;
            color: #1f2937;
        }
        .session-card {
            background: #f9fafb;
            border-left: 4px solid #3b82f6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }
        .session-card:hover {
            background: #f3f4f6;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .session-card.completed {
            border-left-color: #10b981;
        }
        .session-card.abandoned {
            border-left-color: #ef4444;
        }
        .session-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .session-info {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        .session-info-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.9rem;
            color: #6b7280;
        }
        .session-info-item i {
            color: #3b82f6;
        }
        .session-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .session-badge.completed {
            background: #d1fae5;
            color: #065f46;
        }
        .session-badge.abandoned {
            background: #fee2e2;
            color: #991b1b;
        }
        .session-badge.in-progress {
            background: #fef3c7;
            color: #92400e;
        }
        .session-timeline {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #e5e7eb;
            background: #f9fafb;
            padding: 15px;
            border-radius: 8px;
        }
        .timeline-item {
            margin-bottom: 15px;
            padding: 12px;
            background: white;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            transition: all 0.3s ease;
        }
        .timeline-item:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-color: #3b82f6;
        }
        .timeline-time {
            background: #f3f4f6;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.85rem;
            color: #6b7280;
            font-family: 'Courier New', monospace;
        }
        .timeline-action {
            font-weight: 600;
            color: #1f2937;
            font-size: 0.95rem;
        }
        .timeline-field {
            color: #4b5563;
            font-size: 0.95rem;
        }
        .timeline-value-box {
            margin-top: 8px;
            padding: 10px;
            background: #f0fdf4;
            border-left: 3px solid #22c55e;
            border-radius: 4px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .timeline-value-box strong {
            color: #15803d;
            font-size: 0.9rem;
        }
        .timeline-value-box span {
            color: #166534;
            font-weight: 500;
        }

        /* Estilos para tipos de usuário */
        .user-type {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: capitalize;
        }
        .user-type.admin {
            background: #dbeafe;
            color: #1e40af;
        }
        .user-type.analisador {
            background: #d1fae5;
            color: #065f46;
        }
        .timeline-file-box {
            margin-top: 8px;
            padding: 10px;
            background: #dbeafe;
            border-left: 3px solid #3b82f6;
            border-radius: 4px;
        }
        .btn-expand {
            background: transparent;
            border: 1px solid #d1d5db;
            color: #6b7280;
            padding: 6px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.3s ease;
        }
        .btn-expand:hover {
            background: #f3f4f6;
            border-color: #9ca3af;
        }

        /* Estilos para badges de status */
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }
        .status-novo {
            background: #fef3c7;
            color: #92400e;
        }
        .status-pendente {
            background: #dbeafe;
            color: #1e40af;
        }
        .status-entrevista {
            background: #e0e7ff;
            color: #3730a3;
        }
        .status-teste {
            background: #ffedd5;
            color: #9a3412;
        }
        .status-aprovado {
            background: #dcfce7;
            color: #166534;
        }
        .status-classificado {
            background: #d1fae5;
            color: #065f46;
        }
        .status-banco {
            background: #f3f4f6;
            color: #374151;
        }
        .status-arquivado {
            background: #fee2e2;
            color: #991b1b;
        }
        .status-select {
            border: 1px solid #d1d5db;
            border-radius: 4px;
            background: white;
        }
    </style>
</head>
<body>
    <div class="container">
        <header class="header">
            <div class="header-content">
                <div class="logo"><i class="fas fa-building"></i><div><h1>GOR INFORMÁTICA</h1><p>Painel Administrativo</p></div></div>
                <a href="?logout=1" class="btn-secondary"><i class="fas fa-sign-out-alt"></i> Sair</a>
            </div>
        </header>

        <div class="admin-panel">
            <div class="panel-content">
                <?php
                // Aviso visível quando a API de envio (Pluggor) não está configurada
                $apiWarning = '';
                if (empty($config['api_token']) || empty($config['api_url'])) {
                    $apiWarning = 'A API de envio (Pluggor) não está configurada (token/URL ausentes). Novos currículos NÃO enviarão notificação no WhatsApp — nem texto, nem foto, nem PDF. Configure na aba "Configurações".';
                } elseif (empty($config['notification_number']) || in_array($config['notification_number'], ['5500000000000', '5561999999999'], true)) {
                    $apiWarning = 'O número de notificação do WhatsApp é o padrão de exemplo. A notificação de novos currículos (texto, foto e PDF) pode não chegar. Configure o número real na aba "Configurações".';
                }
                if ($apiWarning !== '' && (getUserType() === 'admin')) {
                    echo '<div style="background:#fef3c7;border:1px solid #f59e0b;border-left:5px solid #f59e0b;border-radius:8px;padding:14px 18px;margin-bottom:20px;color:#92400e;font-size:0.95rem;line-height:1.5;"><i class="fas fa-exclamation-triangle"></i> <strong>Atenção:</strong> ' . htmlspecialchars($apiWarning) . '</div>';
                }
                ?>
                <?php if (getUserType() === 'admin' || getUserType() === 'analisador'): ?>
                <div class="stats-card">
                    <div class="stat"><h3>Currículos Recebidos</h3><p class="stat-number"><?php echo $totalCurriculos; ?></p></div>
                    <i class="fas fa-users"></i>
                </div>

                <div class="tabs">
                      <button class="tab active" onclick="showTab('curriculos')"><i class="fas fa-list"></i> Currículos</button>
                      <?php if (getUserType() === 'admin'): ?>
                      <button class="tab" onclick="showTab('interactions')"><i class="fas fa-chart-line"></i> Interações do Formulário</button>
                      <button class="tab" onclick="showTab('users')"><i class="fas fa-users"></i> Usuários</button>
                      <button class="tab" onclick="showTab('config')"><i class="fas fa-cog"></i> Configurações</button>
                      <button class="tab" onclick="showTab('tests')"><i class="fas fa-vial"></i> Testes da API</button>
                      <button class="tab" onclick="showTab('logs')"><i class="fas fa-file-alt"></i> Logs do Sistema</button>
                      <button class="tab" onclick="showTab('access')"><i class="fas fa-eye"></i> Logs de Acesso</button>
                      <button class="tab" onclick="showTab('updates')"><i class="fas fa-upload"></i> Atualizações</button>
                      <?php endif; ?>
                  </div>
                <?php endif; ?>

                <!-- Tab Currículos -->
                <div id="curriculos-tab" class="tab-content active">
                    <h3><i class="fas fa-list"></i> Currículos Cadastrados</h3>

                    <!-- Filtros -->
                    <div class="filters-section" style="margin-bottom: 20px;">
                        <div class="filters-row">
                            <div class="filter-group">
                                <label>Status:</label>
                                <select id="statusFilter" onchange="loadCurriculos()">
                                    <option value="">Todos (exceto arquivados)</option>
                                    <option value="pendente_novo">Pendente - Novo</option>
                                    <option value="pendente">Pendente</option>
                                    <option value="entrevista">Em Entrevista</option>
                                    <option value="teste">Em Teste</option>
                                    <option value="aprovado">Aprovado</option>
                                    <option value="classificado">Classificado</option>
                                    <option value="banco_talentos">Banco de Talentos</option>
                                    <option value="arquivado">Arquivado</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label>
                                    <input type="checkbox" id="showArchived" onchange="loadCurriculos()"> Mostrar Arquivados
                                </label>
                            </div>
                            <button class="btn-secondary" onclick="loadCurriculos()"><i class="fas fa-sync-alt"></i> Atualizar</button>
                        </div>
                    </div>

                    <div class="table-container">
                        <table class="curriculos-table">
                            <thead><tr><th>Data/Hora</th><th>Nome</th><th>Telefone</th><th>Email</th><th>Cidade</th><th>Status</th><th>Contato</th><th>Ações</th></tr></thead>
                            <tbody id="curriculos-tbody">
                                <!-- Conteúdo carregado via JS -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Tab Interações do Formulário -->
                <?php if (getUserType() === 'admin' || getUserType() === 'analisador'): ?>
                <div id="interactions-tab" class="tab-content">
                    <h3><i class="fas fa-chart-line"></i> Análise de Interações do Formulário</h3>

                    <!-- Cards de Estatísticas -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon" style="background: #3b82f6;"><i class="fas fa-mouse-pointer"></i></div>
                            <div class="stat-info">
                                <h4>Total de Sessões</h4>
                                <p class="stat-value" id="totalSessions">-</p>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon" style="background: #10b981;"><i class="fas fa-check-circle"></i></div>
                            <div class="stat-info">
                                <h4>Formulários Completos</h4>
                                <p class="stat-value" id="completedForms">-</p>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon" style="background: #ef4444;"><i class="fas fa-times-circle"></i></div>
                            <div class="stat-info">
                                <h4>Abandonos</h4>
                                <p class="stat-value" id="abandonedForms">-</p>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon" style="background: #f59e0b;"><i class="fas fa-percentage"></i></div>
                            <div class="stat-info">
                                <h4>Taxa de Conversão</h4>
                                <p class="stat-value" id="conversionRate">-</p>
                            </div>
                        </div>
                    </div>

                    <!-- Filtros -->
                    <div class="filters-section">
                        <h4><i class="fas fa-filter"></i> Filtros</h4>
                        <div class="filters-row">
                            <div class="filter-group">
                                <label>Período:</label>
                                <select id="filterPeriod" onchange="loadInteractions()">
                                    <option value="today">Hoje</option>
                                    <option value="week" selected>Última Semana</option>
                                    <option value="month">Último Mês</option>
                                    <option value="all">Todos</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label>Dispositivo:</label>
                                <select id="filterDevice" onchange="loadInteractions()">
                                    <option value="">Todos</option>
                                    <option value="Desktop">Desktop</option>
                                    <option value="Mobile">Mobile</option>
                                    <option value="Tablet">Tablet</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label>Buscar por Nome:</label>
                                <input type="text" id="filterName" placeholder="Digite o nome..." onkeyup="loadInteractions()">
                            </div>
                            <button class="btn-secondary" onclick="loadInteractions()"><i class="fas fa-sync-alt"></i> Atualizar</button>
                            <button class="btn-remove" onclick="clearInteractions()" style="background-color: #ef4444;"><i class="fas fa-trash"></i> Limpar Tudo</button>
                        </div>
                    </div>

                    <!-- Campo Mais Abandonado -->
                    <div class="abandonment-analysis">
                        <h4><i class="fas fa-exclamation-triangle"></i> Campos com Maior Abandono</h4>
                        <div id="abandonmentChart" class="chart-container">
                            <canvas id="abandonmentCanvas"></canvas>
                        </div>
                    </div>

                    <!-- Lista de Sessões -->
                    <div class="sessions-list">
                        <h4><i class="fas fa-users"></i> Sessões Recentes</h4>
                        <div id="sessionsContainer">
                            <p>Carregando sessões...</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Tab Usuários -->
                <div id="users-tab" class="tab-content">
                    <h3><i class="fas fa-users"></i> Gerenciar Usuários</h3>
                    <div style="margin-bottom: 20px;">
                        <button class="btn-primary" onclick="showAddUserModal()"><i class="fas fa-plus"></i> Adicionar Usuário</button>
                    </div>
                    <div class="table-container">
                        <table class="curriculos-table">
                            <thead><tr><th>Email</th><th>Tipo</th><th>Data de Criação</th><th>Ações</th></tr></thead>
                            <tbody id="users-tbody">
                                <!-- Conteúdo carregado via JS -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Tab Configurações -->
                <?php if (getUserType() === 'admin'): ?>
                <div id="config-tab" class="tab-content">
                    <div class="config-note">
                        <h4><i class="fas fa-key"></i> Configurações Gerais</h4>
                        <p>Gerencie as configurações da API e suas credenciais de acesso.</p>
                    </div>
                    
                    <form id="apiConfigForm" class="config-form">
                        <h3>Configuração da API WhapiChat</h3>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <div class="form-group">
                            <label>URL da API</label>
                            <input type="text" id="apiUrl" name="api_url" value="<?php echo htmlspecialchars($config['api_url']); ?>">
                        </div>
                        <div class="form-group">
                            <label>Token "Bearer"</label>
                            <input type="text" id="apiToken" name="api_token" value="<?php echo htmlspecialchars($config['api_token']); ?>">
                        </div>
                        <div class="form-group">
                            <label>Número para Notificações</label>
                            <input type="text" id="notificationNumber" name="notification_number" value="<?php echo htmlspecialchars($config['notification_number']); ?>">
                        </div>
                        <div class="form-group">
                            <label>Mensagem de Conclusão do Cadastro</label>
                            <textarea id="completionMessage" name="completion_message" rows="4" placeholder="Digite a mensagem que será enviada ao usuário após o cadastro. Use {nome} para substituir pelo nome do usuário."><?php echo htmlspecialchars($config['completion_message'] ?? ''); ?></textarea>
                        </div>
                        <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Salvar Configurações</button>
                        <div id="configApiSuccess" class="success-message" style="display: none;"></div>
                    </form>

                    <hr style="margin: 40px 0;">

                    <form id="emailConfigForm" class="config-form">
                        <h3>Configuração de Email</h3>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <div class="form-group">
                            <label>Email Remetente</label>
                            <input type="email" id="smtpFrom" name="smtp_from" value="<?php echo htmlspecialchars($config['smtp_from'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Email para Notificações</label>
                            <input type="email" id="notificationEmail" name="notification_email" value="<?php echo htmlspecialchars($config['notification_email'] ?? ''); ?>" required>
                        </div>
                        <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Salvar Configurações de Email</button>
                        <div id="configEmailSuccess" class="success-message" style="display: none;"></div>
                    </form>

                    <hr style="margin: 40px 0;">

                    <form id="credentialsForm" class="config-form">
                        <h3>Credenciais de Acesso</h3>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <div class="form-group">
                            <label>Email de Login</label>
                            <input type="email" id="adminEmail" name="email" value="<?php echo htmlspecialchars($_SESSION['user_email']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Nova Senha (deixe em branco para não alterar)</label>
                            <input type="password" id="adminPassword" name="password">
                        </div>
                        <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Atualizar Credenciais</button>
                        <div id="configCredentialsSuccess" class="success-message" style="display: none;"></div>
                    </form>
                </div>
                <?php endif; ?>

                <!-- Tab Testes -->
                <?php if (getUserType() === 'admin'): ?>
                <div id="tests-tab" class="tab-content">
                    <div class="config-note">
                        <h4><i class="fas fa-vial"></i> Testar Envio de Mensagem</h4>
                        <p>Use esta ferramenta para verificar se suas configurações da API estão corretas, enviando uma mensagem de teste.</p>
                    </div>
                    <form id="apiTestForm" class="config-form">
                        <h3>Teste de Mensagem de Texto</h3>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <div class="form-group">
                            <label>Número de Destino (com código do país)</label>
                            <input type="text" id="testNumber" name="number" placeholder="5585999999999" required>
                        </div>
                        <div class="form-group">
                            <label>Mensagem</label>
                            <textarea id="testBody" name="body" rows="3" required>Olá! Isto é uma mensagem de teste do sistema de currículos.</textarea>
                        </div>
                        <button type="submit" class="btn-primary"><i class="fas fa-paper-plane"></i> Enviar Mensagem de Teste</button>
                        <div id="testResult" class="success-message" style="display: none; margin-top: 15px; white-space: pre-wrap; text-align: left;"></div>
                    </form>
                </div>
                <?php endif; ?>

                <!-- Tab Logs -->
                <?php if (getUserType() === 'admin'): ?>
                <div id="logs-tab" class="tab-content">
                    <div class="form-section">
                        <h3><i class="fas fa-file-alt"></i> Logs do Sistema</h3>
                        <div style="margin-bottom: 15px;">
                            <button onclick="loadLogs()" class="btn-secondary">
                                <i class="fas fa-sync-alt"></i> Atualizar Logs
                            </button>
                            <button onclick="clearLogs()" class="btn-remove" style="background-color: #ef4444;">
                                <i class="fas fa-trash"></i> Limpar Logs
                            </button>
                        </div>
                        <div id="logsContainer" class="log-container">
                            Carregando logs...
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Tab Access Logs -->
                <?php if (getUserType() === 'admin'): ?>
                <div id="access-tab" class="tab-content">
                    <div class="form-section">
                        <h3><i class="fas fa-eye"></i> Logs de Acesso ao Formulário</h3>
                        <div style="margin-bottom: 15px;">
                            <button onclick="loadAccessLogs()" class="btn-secondary">
                                <i class="fas fa-sync-alt"></i> Atualizar Logs de Acesso
                            </button>
                            <button onclick="clearAccessLogs()" class="btn-remove" style="background-color: #ef4444;">
                                <i class="fas fa-trash"></i> Limpar Logs de Acesso
                            </button>
                        </div>
                        <div id="accessLogsContainer" class="log-container">
                            Carregando logs de acesso...
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Tab Atualizações -->
                <?php if (getUserType() === 'admin'): ?>
                <div id="updates-tab" class="tab-content">
                    <div class="form-section">
                        <h3><i class="fas fa-upload"></i> Atualização do Sistema (.zip)</h3>
                        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-left:5px solid #1e40af;border-radius:8px;padding:14px 18px;margin-bottom:15px;">
                            <i class="fas fa-tag"></i> <strong>Versão instalada:</strong> <?php echo htmlspecialchars(!empty($config['system_version']) ? $config['system_version'] : 'Não registrada'); ?>
                            <?php if (!empty($config['system_version_info'])): ?><br><small style="color:#1e3a8a;"><?php echo htmlspecialchars($config['system_version_info']); ?></small><?php endif; ?>
                        </div>
                        <p>Faça upload de um pacote de atualização oficial para instalar novas funcionalidades. Um backup será feito automaticamente antes da instalação.</p>
                        
                        <form id="uploadUpdateForm" style="margin-top: 20px;">
                            <input type="hidden" name="action" value="uploadUpdate">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <div class="form-group">
                                <input type="file" name="update_zip" accept=".zip" required>
                            </div>
                            <button type="submit" class="btn-primary" id="btnUploadUpdate"><i class="fas fa-upload"></i> Enviar Pacote de Atualização</button>
                            <div id="uploadUpdateStatus" style="margin-top: 15px;"></div>
                        </form>
                        
                        <div id="applyUpdateSection" style="display: none; margin-top: 30px; padding: 20px; border: 1px solid #10b981; border-radius: 8px; background-color: #ecfdf5;">
                            <h4 style="color: #065f46;"><i class="fas fa-check-circle"></i> Atualização Validada!</h4>
                            <p id="updateVersionInfo" style="margin: 10px 0; color: #047857;"></p>
                            <p style="font-size: 0.9em; color: #064e3b; margin-bottom: 15px;">Todos os dados, banco de dados e arquivos de currículos (uploads) não serão afetados. Um backup será gerado automaticamente.</p>
                            
                            <form id="applyUpdateForm">
                                <input type="hidden" name="action" value="applyUpdate">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <button type="submit" class="btn-primary" style="background-color: #10b981;" id="btnApplyUpdate"><i class="fas fa-play"></i> Instalar Atualização Agora</button>
                                <div id="applyUpdateStatus" style="margin-top: 15px;"></div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <!-- Modal para Adicionar Usuário -->
    <div id="addUserModal" class="modal">
        <div class="modal-content">
            <span class="modal-close" onclick="document.getElementById('addUserModal').style.display='none'">&times;</span>
            <h2>Adicionar Novo Usuário</h2>
            <form id="addUserForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" required>
                </div>
                <div class="form-group">
                    <label>Senha</label>
                    <input type="password" name="password" required>
                </div>
                <div class="form-group">
                    <label>Tipo de Usuário</label>
                    <select name="tipo" required>
                        <option value="analisador">Analisador</option>
                        <option value="admin">Administrador</option>
                    </select>
                </div>
                <button type="submit" class="btn-primary">Criar Usuário</button>
                <div id="addUserSuccess" class="success-message" style="display: none; margin-top: 15px;"></div>
            </form>
        </div>
    </div>

    <!-- Modal para Visualizar Currículo -->
    <div id="curriculoModal" class="modal">
        <div class="modal-content">
            <span class="modal-close">&times;</span>
            <h2>Detalhes do Currículo</h2>
            <div id="modalBody">
                <!-- Conteúdo carregado via JS -->
            </div>
        </div>
    </div>

    <!-- Modal para Visualizar Foto -->
    <div id="imageModal" class="modal">
        <span class="modal-close image-modal-close">&times;</span>
        <img class="modal-content-image" id="modalImage">
    </div>

    <!-- Modal para Visualizar PDF -->
    <div id="pdfModal" class="modal">
        <div class="modal-content-pdf">
            <span class="modal-close pdf-modal-close">&times;</span>
            <iframe id="pdf-viewer" src="" frameborder="0"></iframe>
        </div>
    </div>

    <!-- Modal para Envio Rápido de WhatsApp -->
    <div id="whatsappModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <span class="modal-close" onclick="document.getElementById('whatsappModal').style.display='none'">&times;</span>
            <h2><i class="fab fa-whatsapp" style="color: #25d366;"></i> Enviar WhatsApp</h2>
            <div id="whatsappModalContent">
                <!-- Conteúdo será carregado dinamicamente -->
            </div>
        </div>
    </div>


    <script>
        // Tipo de usuário atual
        const userType = '<?php echo getUserType(); ?>';

        // Função para verificar se usuário pode acessar uma aba
        function canAccessTab(tabName) {
            if (userType === 'admin') {
                return true; // admin acessa tudo
            } else if (userType === 'analisador') {
                return tabName === 'curriculos' || tabName === 'interactions'; // analisador acessa currículos e interações
            }
            return false;
        }

        function showTab(tabName) {
            // Verificar se usuário pode acessar a aba
            if (!canAccessTab(tabName)) {
                alert('Acesso negado: você não tem permissão para acessar esta seção.');
                return;
            }

            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.getElementById(tabName + '-tab').classList.add('active');
            event.currentTarget.classList.add('active');

            if (tabName === 'logs') {
                loadLogs();
            } else if (tabName === 'access') {
                loadAccessLogs();
            } else if (tabName === 'interactions') {
                loadInteractionStats();
                loadAbandonmentAnalysis();
                loadInteractions();
            } else if (tabName === 'users') {
                loadUsers();
            }
        }

        // Carregar currículos via fetch
        function loadCurriculos() {
            // Verificar se usuário pode carregar currículos
            if (userType !== 'admin' && userType !== 'analisador') {
                return;
            }

            // Obter filtros
            const statusFilter = document.getElementById('statusFilter')?.value || '';
            const showArchived = document.getElementById('showArchived')?.checked ? '1' : '0';

            // Construir URL com parâmetros
            const params = new URLSearchParams({
                action: 'getCurriculos'
            });

            if (statusFilter) {
                params.append('status', statusFilter);
            }
            if (showArchived === '1') {
                params.append('show_archived', '1');
            }

            fetch('admin.php?' + params)
                .then(res => res.json())
                .then(data => {
                    const tbody = document.getElementById('curriculos-tbody');
                    if (data.success && data.curriculos.length > 0) {
                        tbody.innerHTML = data.curriculos.map(c => {
                            const statusLabels = {
                                'pendente_novo': '<span class="status-badge status-novo">Pendente - Novo</span>',
                                'pendente': '<span class="status-badge status-pendente">Pendente</span>',
                                'entrevista': '<span class="status-badge status-entrevista">Em Entrevista</span>',
                                'teste': '<span class="status-badge status-teste">Em Teste</span>',
                                'aprovado': '<span class="status-badge status-aprovado">Aprovado</span>',
                                'classificado': '<span class="status-badge status-classificado">Classificado</span>',
                                'banco_talentos': '<span class="status-badge status-banco">Banco de Talentos</span>',
                                'arquivado': '<span class="status-badge status-arquivado">Arquivado</span>'
                            };

                            const statusHtml = statusLabels[c.status] || c.status;

                            return `
                                <tr>
                                    <td data-label="Data/Hora">${new Date(c.data_cadastro).toLocaleString('pt-BR')}</td>
                                    <td data-label="Nome">${c.nome}</td>
                                    <td data-label="Telefone">${c.telefone}</td>
                                    <td data-label="Email">${c.email || 'N/A'}</td>
                                    <td data-label="Cidade">${c.cidade}</td>
                                    <td data-label="Status">${statusHtml}</td>
                                    <td data-label="Contato" style="text-align: right;">
                                        <button class="btn-small" onclick="openContactInfoModal(${c.id}, '${c.nome.replace(/'/g, "\\'")}')" style="background: #3b82f6; color: white; border: none; padding: 6px 10px; border-radius: 4px; cursor: pointer;" title="Informações de Contato">
                                            <i class="fas fa-address-book"></i>
                                        </button>
                                    </td>
                                    <td data-label="Ações">
                                        <button class="btn-primary btn-small" onclick="viewCurriculo(${c.id})"><i class="fas fa-eye"></i> Ver</button>
                                        <select class="status-select" onchange="changeStatus(${c.id}, this.value)" style="margin-left: 5px; padding: 2px 5px; font-size: 0.8rem;">
                                            <option value="pendente_novo" ${c.status === 'pendente_novo' ? 'selected' : ''}>Pendente - Novo</option>
                                            <option value="pendente" ${c.status === 'pendente' ? 'selected' : ''}>Pendente</option>
                                            <option value="entrevista" ${c.status === 'entrevista' ? 'selected' : ''}>Em Entrevista</option>
                                            <option value="teste" ${c.status === 'teste' ? 'selected' : ''}>Em Teste</option>
                                            <option value="aprovado" ${c.status === 'aprovado' ? 'selected' : ''}>Aprovado</option>
                                            <option value="classificado" ${c.status === 'classificado' ? 'selected' : ''}>Classificado</option>
                                            <option value="banco_talentos" ${c.status === 'banco_talentos' ? 'selected' : ''}>Banco de Talentos</option>
                                            <option value="arquivado" ${c.status === 'arquivado' ? 'selected' : ''}>Arquivado</option>
                                        </select>
                                        ${c.is_whatsapp ? `<button class="btn-small" onclick="openWhatsAppModal(${c.id}, '${c.nome}', '${c.telefone}')" style="margin-left: 5px; background: #25d366; color: white; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer;" title="Enviar WhatsApp"><i class="fab fa-whatsapp"></i></button>` : ''}
                                        ${userType === 'admin' ? `<button class="btn-remove btn-small" onclick="deleteCurriculo(${c.id}, this)" style="margin-left: 5px;"><i class="fas fa-trash"></i> Deletar</button>` : ''}
                                    </td>
                                </tr>
                            `;
                        }).join('');
                    } else {
                        tbody.innerHTML = '<tr><td colspan="8">Nenhum currículo encontrado.</td></tr>';
                    }
                })
                .catch(err => {
                    console.error('Erro ao carregar currículos:', err);
                    const tbody = document.getElementById('curriculos-tbody');
                    tbody.innerHTML = '<tr><td colspan="8">Erro ao carregar currículos.</td></tr>';
                });
        }

        // Função para alterar status do currículo
        function changeStatus(curriculoId, newStatus) {
            const formData = new FormData();
            formData.append('action', 'updateCurriculoStatus');
            formData.append('id', curriculoId);
            formData.append('status', newStatus);

            // Pegar token CSRF
            const csrfToken = document.querySelector('input[name="csrf_token"]');
            if (csrfToken) {
                formData.append('csrf_token', csrfToken.value);
            }

            fetch('admin.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        // Recarregar lista para atualizar visual
                        loadCurriculos();
                    } else {
                        alert('Erro: ' + data.message);
                        // Recarregar para reverter mudança
                        loadCurriculos();
                    }
                })
                .catch(err => {
                    console.error('Erro ao alterar status:', err);
                    alert('Erro ao alterar status do currículo.');
                    loadCurriculos();
                });
        }

        // Função para alterar status do currículo a partir do modal
        function changeStatusFromModal(curriculoId, newStatus) {
            const statusMessage = document.getElementById('statusUpdateMessage');
            const statusSelect = document.getElementById('curriculoStatus');

            // Desabilitar select durante a atualização
            statusSelect.disabled = true;
            statusMessage.textContent = 'Atualizando...';
            statusMessage.style.color = '#6b7280';

            const formData = new FormData();
            formData.append('action', 'updateCurriculoStatus');
            formData.append('id', curriculoId);
            formData.append('status', newStatus);

            // Pegar token CSRF
            const csrfToken = document.querySelector('input[name="csrf_token"]');
            if (csrfToken) {
                formData.append('csrf_token', csrfToken.value);
            }

            fetch('admin.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        statusMessage.textContent = 'Status atualizado com sucesso!';
                        statusMessage.style.color = '#10b981';
                        // Recarregar lista para atualizar visual
                        loadCurriculos();
                        // Reabilitar select após 2 segundos
                        setTimeout(() => {
                            statusSelect.disabled = false;
                            statusMessage.textContent = '';
                        }, 2000);
                    } else {
                        statusMessage.textContent = 'Erro: ' + data.message;
                        statusMessage.style.color = '#ef4444';
                        statusSelect.disabled = false;
                        // Reverter seleção em caso de erro
                        // Como não sabemos o status anterior, vamos recarregar o modal
                        setTimeout(() => {
                            viewCurriculo(curriculoId);
                        }, 2000);
                    }
                })
                .catch(err => {
                    console.error('Erro ao alterar status:', err);
                    statusMessage.textContent = 'Erro de conexão';
                    statusMessage.style.color = '#ef4444';
                    statusSelect.disabled = false;
                });
        }
        
        // Salvar Config API (apenas para admin)
        const apiConfigForm = document.getElementById('apiConfigForm');
        if (apiConfigForm) {
            apiConfigForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                formData.append('action', 'saveApiConfig');

                // Adicionar o token CSRF ao FormData para o fetch
                const csrfToken = document.querySelector('#apiConfigForm input[name="csrf_token"]').value;
                if (csrfToken) {
                    formData.append('csrf_token', csrfToken);
                }

                fetch('admin.php', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => {
                        const msgDiv = document.getElementById('configApiSuccess');
                        msgDiv.textContent = data.message;
                        msgDiv.style.display = 'block';
                        setTimeout(() => msgDiv.style.display = 'none', 3000);
                    });
            });
        }

        // Salvar Config Email (apenas para admin)
        const emailConfigForm = document.getElementById('emailConfigForm');
        if (emailConfigForm) {
            emailConfigForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                formData.append('action', 'saveEmailConfig');

                // Adicionar o token CSRF ao FormData para o fetch
                const csrfToken = document.querySelector('#emailConfigForm input[name="csrf_token"]').value;
                if (csrfToken) {
                    formData.append('csrf_token', csrfToken);
                }

                fetch('admin.php', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => {
                        const msgDiv = document.getElementById('configEmailSuccess');
                        msgDiv.textContent = data.message;
                        msgDiv.style.display = 'block';
                        setTimeout(() => msgDiv.style.display = 'none', 3000);
                    });
            });
        }

        // Salvar Credenciais (apenas para admin)
        const credentialsForm = document.getElementById('credentialsForm');
        if (credentialsForm) {
            credentialsForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                formData.append('action', 'updateCredentials');

                // Adicionar o token CSRF ao FormData para o fetch
                const csrfToken = document.querySelector('#credentialsForm input[name="csrf_token"]').value;
                if (csrfToken) {
                    formData.append('csrf_token', csrfToken);
                }

                fetch('admin.php', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => {
                        const msgDiv = document.getElementById('configCredentialsSuccess');
                        msgDiv.textContent = data.message;
                        msgDiv.style.display = 'block';
                        setTimeout(() => msgDiv.style.display = 'none', 3000);
                    });
            });
        }

        function loadLogs() {
            const container = document.getElementById('logsContainer');
            if (!container) return; // Só existe para admin

            container.innerHTML = 'Carregando...';
            fetch('admin.php?action=getLogs')
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.logs.length > 0) {
                        container.innerHTML = data.logs.map(log => {
                            let className = 'log-line';
                            if (log.includes('[ERROR]')) className += ' log-error';
                            else if (log.includes('[SUCCESS]')) className += ' log-success';
                            else if (log.includes('[WARNING]')) className += ' log-warning';
                            else if (log.includes('[INFO]')) className += ' log-info';
                            return `<div class="${className}">${log.replace(/</g, "<").replace(/>/g, ">")}</div>`;
                        }).join('');
                    } else {
                        container.innerHTML = '<div class="log-line">Nenhum log encontrado.</div>';
                    }
                })
                .catch(err => container.innerHTML = '<div class="log-line log-error">Erro ao carregar logs.</div>');
        }

        function clearLogs() {
            if (!confirm('Tem certeza que deseja limpar todos os logs? Esta ação não pode ser desfeita.')) {
                return;
            }
            const formData = new FormData();
            formData.append('action', 'clearLogs');

            // Adicionar o token CSRF ao FormData para o fetch
            const csrfToken = document.querySelector('#apiConfigForm input[name="csrf_token"]').value; // Pode pegar de qualquer form
            if (csrfToken) {
                formData.append('csrf_token', csrfToken);
            }
            fetch('admin.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if(data.success) {
                        loadLogs();
                    } else {
                        alert('Falha ao limpar os logs.');
                    }
                });
        }

        function loadAccessLogs() {
            const container = document.getElementById('accessLogsContainer');
            if (!container) return; // Só existe para admin

            container.innerHTML = 'Carregando...';
            fetch('admin.php?action=getAccessLogs')
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.logs.length > 0) {
                        container.innerHTML = data.logs.map(log => {
                            let className = 'log-line';
                            if (log.includes('[ACCESS]')) className += ' log-info';
                            return `<div class="${className}">${log.replace(/</g, "<").replace(/>/g, ">")}</div>`;
                        }).join('');
                    } else {
                        container.innerHTML = '<div class="log-line">Nenhum log de acesso encontrado.</div>';
                    }
                })
                .catch(err => container.innerHTML = '<div class="log-line log-error">Erro ao carregar logs de acesso.</div>');
        }

        function clearAccessLogs() {
            if (!confirm('Tem certeza que deseja limpar todos os logs de acesso? Esta ação não pode ser desfeita.')) {
                return;
            }
            const formData = new FormData();
            formData.append('action', 'clearAccessLogs');

            // Adicionar o token CSRF ao FormData para o fetch
            const csrfToken = document.querySelector('#apiConfigForm input[name="csrf_token"]').value;
            if (csrfToken) {
                formData.append('csrf_token', csrfToken);
            }
            fetch('admin.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if(data.success) {
                        loadAccessLogs();
                    } else {
                        alert('Falha ao limpar os logs de acesso.');
                    }
                });
        }

        // Teste da API (apenas para admin)
        const apiTestForm = document.getElementById('apiTestForm');
        if (apiTestForm) {
            apiTestForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const resultDiv = document.getElementById('testResult');
                resultDiv.style.display = 'block';
                resultDiv.textContent = 'Enviando...';

                const formData = new FormData(this);
                formData.append('action', 'testApiSend');

                // Adicionar o token CSRF ao FormData para o fetch
                const csrfToken = document.querySelector('#apiTestForm input[name="csrf_token"]').value;
                if (csrfToken) {
                    formData.append('csrf_token', csrfToken);
                }

                fetch('admin.php', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => {
                        resultDiv.textContent = data.message;
                    })
                    .catch(err => {
                        resultDiv.textContent = 'Erro na requisição: ' + err;
                    });
            });
        }

        // --- MODAL LOGIC ---
        const modal = document.getElementById('curriculoModal');
        const modalBody = document.getElementById('modalBody');
        const closeModalBtn = document.querySelector('.modal-close');
        
        const imageModal = document.getElementById('imageModal');
        const modalImage = document.getElementById('modalImage');
        const pdfModal = document.getElementById('pdfModal');
        const pdfViewer = document.getElementById('pdf-viewer');

        closeModalBtn.onclick = function() {
            modal.style.display = "none";
        }

        document.querySelector('.image-modal-close').onclick = () => imageModal.style.display = "none";
        document.querySelector('.pdf-modal-close').onclick = () => {
            pdfModal.style.display = "none";
            pdfViewer.src = ""; // Limpa o src para parar o carregamento do PDF
        };

        window.onclick = function(event) {
            if (event.target == modal) {
                modal.style.display = "none";
            }
            if (event.target == imageModal) {
                imageModal.style.display = "none";
            }
            if (event.target == pdfModal) {
                pdfModal.style.display = "none";
                pdfViewer.src = ""; // Limpa o src
            }
            if (event.target == document.getElementById('whatsappModal')) {
                document.getElementById('whatsappModal').style.display = "none";
            }
        }

        function showImageModal(src) { imageModal.style.display = "block"; modalImage.src = src; }
        function showPdfModal(src) { pdfModal.style.display = "block"; pdfViewer.src = src; }
        
        function viewCurriculo(id) {
            modalBody.innerHTML = '<p>Carregando detalhes...</p>';
            modal.style.display = 'block';

            fetch(`admin.php?action=getCurriculoDetails&id=${id}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        const c = data.curriculo;
                        let experiencesHtml = '<p>Nenhuma experiência informada.</p>';
                        if (c.experiencias && c.experiencias.length > 0) {
                            experiencesHtml = c.experiencias.map((exp, index) => `
                                <div class="experience-block">
                                    <h4>Experiência ${index + 1}</h4>
                                    <p><strong>Empresa:</strong> ${exp.company || 'N/A'}</p>
                                    <p><strong>Cargo:</strong> ${exp.position || 'N/A'}</p>
                                    <p><strong>Duração:</strong> ${exp.duration || 'N/A'}</p>
                                    ${exp.atividades ? `<p><strong>Principais atividades:</strong> ${exp.atividades}</p>` : ''}
                                    ${exp.empregado_atual === 'Sim' ? `<p><strong>Trabalha atualmente:</strong> Sim</p>${exp.motivo_saida_atual ? `<p><strong>Por que está saindo:</strong> ${exp.motivo_saida_atual}</p>` : ''}` : (exp.motivo_saida ? `<p><strong>Motivo da saída:</strong> ${exp.motivo_saida}</p>` : '')}
                                </div>
                            `).join('');
                        }

                        // Habilidades
                        let habilidadesHtml = '<p>Nenhuma habilidade informada.</p>';
                        if (c.habilidades && c.habilidades.length > 0) {
                            habilidadesHtml = c.habilidades.map(h => `<span class="badge-skill">${h}</span>`).join(' ');
                        }

                        // Referências
                        let referenciasHtml = '<p>Nenhuma referência informada.</p>';
                        if (c.referencias && c.referencias.length > 0) {
                            referenciasHtml = c.referencias.map((ref, index) => `
                                <div class="experience-block">
                                    <h4>Referência ${index + 1}</h4>
                                    <p><strong>Nome:</strong> ${ref.nome || 'N/A'}</p>
                                    ${ref.empresa ? `<p><strong>Empresa:</strong> ${ref.empresa}</p>` : ''}
                                    ${ref.cargo ? `<p><strong>Cargo:</strong> ${ref.cargo}</p>` : ''}
                                    ${ref.telefone ? `<p><strong>Telefone:</strong> ${ref.telefone}</p>` : ''}
                                </div>
                            `).join('');
                        }

                        // Disponibilidade
                        let disponibilidadeHtml = '';
                        if (c.disponibilidade_inicio) {
                            disponibilidadeHtml = `
                                <p><strong>Pode começar:</strong> ${c.disponibilidade_inicio}${c.disponibilidade_inicio === 'Outra data' && c.disponibilidade_outra_data ? ' (' + new Date(c.disponibilidade_outra_data + 'T00:00:00').toLocaleDateString('pt-BR') + ')' : ''}</p>
                                ${c.disponibilidade_sabados ? `<p><strong>Disponibilidade aos sábados:</strong> ${c.disponibilidade_sabados}</p>` : ''}
                                ${c.disponibilidade_horas_extras ? `<p><strong>Disponibilidade para horas extras:</strong> ${c.disponibilidade_horas_extras}</p>` : ''}
                            `;
                        }

                        const statusOptions = [
                            {value: 'pendente_novo', label: 'Pendente - Novo'},
                            {value: 'pendente', label: 'Pendente'},
                            {value: 'classificado', label: 'Classificado'},
                            {value: 'arquivado', label: 'Arquivado'}
                        ];

                        const statusSelect = statusOptions.map(option =>
                            `<option value="${option.value}" ${c.status === option.value ? 'selected' : ''}>${option.label}</option>`
                        ).join('');

                        // Verificar se há foto para exibir no cabeçalho
                        let photoHeader = '';
                        if (c.arquivo_foto) {
                            photoHeader = `
                                <div style="text-align: center; margin-bottom: 20px; padding: 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 12px; color: white;">
                                    <img src="uploads/${encodeURIComponent(c.arquivo_foto)}" alt="Foto 3x4" style="width: 120px; height: 160px; object-fit: cover; border: 4px solid white; border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.2);">
                                    <h2 style="margin: 15px 0 5px 0; font-size: 1.5rem;">${c.nome}</h2>
                                    <p style="margin: 0; opacity: 0.9;">${c.email || 'Email não informado'}</p>
                                </div>
                            `;
                        }

                        modalBody.innerHTML = `
                            ${photoHeader}

                            <div style="margin-bottom: 20px; padding: 15px; background: #f8fafc; border-radius: 8px; border-left: 4px solid #3b82f6;">
                                <h4 style="margin: 0 0 10px 0; color: #1e40af;"><i class="fas fa-tag"></i> Status do Currículo</h4>
                                <select id="curriculoStatus" onchange="changeStatusFromModal(${c.id}, this.value)" style="padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 14px;">
                                    ${statusSelect}
                                </select>
                                <span id="statusUpdateMessage" style="margin-left: 10px; font-size: 14px;"></span>
                            </div>

                            ${c.status === 'classificado' && c.is_whatsapp ? `
                            <div style="margin-bottom: 20px; padding: 15px; background: #f0f9ff; border-radius: 8px; border-left: 4px solid #0ea5e9;">
                                <h4 style="margin: 0 0 15px 0; color: #0c4a6e;"><i class="fab fa-whatsapp"></i> Enviar Mensagem WhatsApp</h4>

                                <div style="margin-bottom: 15px;">
                                    <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #374151;">Mensagem:</label>
                                    <textarea id="whatsappMessage" rows="4" placeholder="Digite a mensagem para enviar via WhatsApp..." style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; font-family: inherit; resize: vertical;">Olá *${c.nome}*!

Seu currículo foi classificado e estamos interessados em seu perfil.
Gostaríamos de agendar uma conversa para discutir oportunidades.
Estaria disponível para uma chamada nos próximos dias?

Atenciosamente,
Equipe de RH</textarea>
                                </div>

                                <div style="display: flex; gap: 10px; align-items: center;">
                                    <button onclick="sendWhatsAppMessage(${c.id})" style="background: #25d366; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: 600;">
                                        <i class="fab fa-whatsapp"></i> Enviar WhatsApp
                                    </button>
                                    <button onclick="loadWhatsAppHistory(${c.id})" style="background: #6b7280; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer;">
                                        <i class="fas fa-history"></i> Ver Histórico
                                    </button>
                                    <span id="whatsappSendMessage" style="font-size: 14px;"></span>
                                </div>

                                <div id="whatsappHistory" style="margin-top: 15px; display: none;">
                                    <h5 style="margin: 0 0 10px 0; color: #374151;"><i class="fas fa-list"></i> Histórico de Mensagens</h5>
                                    <div id="whatsappHistoryContent" style="max-height: 200px; overflow-y: auto; background: white; border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px;"></div>
                                </div>
                            </div>
                            ` : ''}

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                                <div>
                                    <h3><i class="fas fa-user"></i> Dados Pessoais</h3>
                                    <p><strong>Nome:</strong> ${c.nome}</p>
                                    <p><strong>Data de Nascimento:</strong> ${new Date(c.data_nascimento + 'T00:00:00').toLocaleDateString('pt-BR')}</p>
                                    <p><strong>Estado Civil:</strong> ${c.estado_civil || 'Não informado'}</p>
                                    <p><strong>Possui Filhos?:</strong> ${c.possui_filhos || 'Não informado'}</p>
                                    <p><strong>Status:</strong> ${c.status || 'Não definido'}</p>
                                    <p><strong>Visualizado:</strong> ${c.visualizado ? 'Sim' : 'Não'}</p>
                                    ${c.data_visualizacao ? `<p><strong>Data da Visualização:</strong> ${new Date(c.data_visualizacao).toLocaleString('pt-BR')}</p>` : ''}
                                </div>

                                <div>
                                    <h3><i class="fas fa-phone"></i> Contato</h3>
                                    <p><strong>Telefone:</strong> ${c.telefone || 'Não informado'}</p>
                                    <p><strong>É WhatsApp?:</strong> ${c.is_whatsapp ? 'Sim' : 'Não'}</p>
                                    <p><strong>Email:</strong> ${c.email || 'Não informado'}</p>
                                    ${c.facebook ? `<p><strong>Facebook:</strong> ${c.facebook}</p>` : ''}
                                    ${c.instagram ? `<p><strong>Instagram:</strong> ${c.instagram}</p>` : ''}
                                </div>
                            </div>

                            <h3><i class="fas fa-map-marker-alt"></i> Endereço</h3>
                            <p><strong>Endereço:</strong> ${c.endereco || 'Não informado'}</p>
                            <p><strong>Cidade:</strong> ${c.cidade || 'Não informado'}</p>
                            <p><strong>Estado:</strong> ${c.estado || 'Não informado'}</p>

                            <h3><i class="fas fa-calendar-check"></i> Disponibilidade para Contratação</h3>
                            <div style="background: #f8fafc; padding: 15px; border-radius: 8px;">
                                ${disponibilidadeHtml || '<p>Não informada</p>'}
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
                                <div>
                                    <h3><i class="fas fa-money-bill-wave"></i> Pretensão Salarial</h3>
                                    <p>${c.pretensao_salarial || 'Não informada'}</p>
                                </div>
                                <div>
                                    <h3><i class="fas fa-laptop"></i> Conhecimento em Informática</h3>
                                    <p>${c.conhecimento_informatica || 'Não informado'}</p>
                                </div>
                            </div>

                            <h3><i class="fas fa-star"></i> Habilidades</h3>
                            <div style="background: #f8fafc; padding: 15px; border-radius: 8px;">
                                ${habilidadesHtml}
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                                <div>
                                    <h3><i class="fas fa-graduation-cap"></i> Formação</h3>
                                    <p><strong>Escolaridade:</strong> ${c.escolaridade || 'Não informado'}</p>
                                    <p><strong>Está Estudando?:</strong> ${c.estudando ? 'Sim' : 'Não'}</p>
                                    ${c.periodo_estudo ? `<p><strong>Período de Estudo:</strong> ${c.periodo_estudo}</p>` : ''}
                                    ${c.curso_atual ? `<p><strong>Curso Atual:</strong> ${c.curso_atual}</p>` : ''}
                                    ${c.instituicao_curso ? `<p><strong>Instituição:</strong> ${c.instituicao_curso}</p>` : ''}
                                    ${c.situacao_curso ? `<p><strong>Situação do Curso:</strong> ${c.situacao_curso}</p>` : ''}
                                    ${c.ano_conclusao_curso ? `<p><strong>Ano Conclusão/Previsão:</strong> ${c.ano_conclusao_curso}</p>` : ''}
                                    <p><strong>Possui Cursos?:</strong> ${c.possui_cursos ? 'Sim' : 'Não'}</p>
                                    ${c.cursos ? `<p><strong>Cursos:</strong><br><div style="background: #f8fafc; padding: 10px; border-radius: 6px; margin-top: 5px;">${c.cursos.replace(/\n/g, '<br>')}</div></p>` : ''}
                                </div>

                                <div>
                                    <h3><i class="fas fa-briefcase"></i> Experiência Profissional</h3>
                                    <p><strong>Possui Experiência?:</strong> ${c.possui_experiencia ? 'Sim' : 'Não'}</p>
                                    ${experiencesHtml}
                                </div>
                            </div>

                            ${c.expectativa_primeiro_emprego ? `
                            <h3><i class="fas fa-seedling"></i> Expectativa Primeiro Emprego</h3>
                            <div style="background: #f8fafc; padding: 15px; border-radius: 8px; border-left: 4px solid #10b981;">
                                ${c.expectativa_primeiro_emprego.replace(/\n/g, '<br>')}
                            </div>
                            ` : ''}

                            ${c.como_conheceu ? `
                            <h3><i class="fas fa-info-circle"></i> Informações Complementares</h3>
                            <div style="background: #f8fafc; padding: 15px; border-radius: 8px;">
                                <p><strong>Como conheceu a vaga:</strong> ${c.como_conheceu}${c.como_conheceu === 'Outro' && c.como_conheceu_outro ? ' - ' + c.como_conheceu_outro : ''}</p>
                                <p><strong>Possui referência profissional:</strong> ${c.referencias && c.referencias.length > 0 ? 'Sim' : 'Não'}</p>
                                ${c.referencias && c.referencias.length > 0 ? `<h4 style="margin: 10px 0 5px 0;"><i class="fas fa-user-friends"></i> Referências:</h4>${referenciasHtml}` : ''}
                            </div>
                            ` : ''}

                            <h3><i class="fas fa-target"></i> Objetivo</h3>
                            <div style="background: #f8fafc; padding: 15px; border-radius: 8px; border-left: 4px solid #3b82f6;">
                                ${c.motivacao ? c.motivacao.replace(/\n/g, '<br>') : 'Não informado'}
                            </div>

                            <h3><i class="fas fa-file-alt"></i> Arquivos Anexados</h3>
                            <div class="modal-files" style="display: flex; gap: 20px; flex-wrap: wrap;">
                                ${c.arquivo_curriculo ? `<a href="javascript:void(0);" onclick="showPdfModal('uploads/' + encodeURIComponent('${c.arquivo_curriculo}'))" style="display: inline-flex; align-items: center; gap: 8px; background: #dc2626; color: white; padding: 10px 15px; border-radius: 6px; text-decoration: none;"><i class="fas fa-file-pdf"></i> Ver Currículo (PDF)</a>` : '<span style="color: #6b7280;">Nenhum currículo anexado</span>'}
                                ${c.arquivo_foto ? `<a href="javascript:void(0);" onclick="showImageModal('uploads/' + encodeURIComponent('${c.arquivo_foto}'))" style="display: inline-flex; align-items: center; gap: 8px; background: #059669; color: white; padding: 10px 15px; border-radius: 6px; text-decoration: none;"><i class="fas fa-camera"></i> Ver Foto</a>` : '<span style="color: #6b7280;">Nenhuma foto anexada</span>'}
                            </div>

                            <hr style="margin-top: 30px;">
                            <div style="background: #f8fafc; padding: 15px; border-radius: 8px; font-size: 0.9rem; color: #6b7280;">
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                                    <div><strong>IP do Cadastro:</strong> ${c.ip_cadastro || 'Não registrado'}</div>
                                    <div><strong>Data do Cadastro:</strong> ${new Date(c.data_cadastro).toLocaleString('pt-BR')}</div>
                                    ${c.data_visualizacao ? `<div><strong>Primeira Visualização:</strong> ${new Date(c.data_visualizacao).toLocaleString('pt-BR')}</div>` : ''}
                                    <div><strong>ID do Registro:</strong> #${c.id}</div>
                                    <div><strong>Aceitou Termos:</strong> ${c.aceitou_termos ? 'Sim' : 'Não'}</div>
                                    ${c.horario_vaga ? `<div><strong>Horário da Vaga:</strong> ${c.horario_vaga}</div>` : ''}
                                    <div><strong>Consentimento LGPD:</strong> ${c.consentimento_lgpd || 'Não'}</div>
                                    <div><strong>Banco de Talentos:</strong> ${c.consentimento_banco_talentos || 'Não'}</div>
                                </div>
                            </div>
                        `;
                    } else {
                        modalBody.innerHTML = `<p class="error-message">${data.message}</p>`;
                    }
                })
                .catch(err => {
                    modalBody.innerHTML = `<p class="error-message">Erro ao carregar os dados: ${err}</p>`;
                });
        }

        function deleteCurriculo(id, element) {
            if (!confirm('Tem certeza que deseja deletar este currículo?\n\nEsta ação também removerá os arquivos (PDF e foto) associados e não pode ser desfeita.')) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'deleteCurriculo');
            formData.append('id', id);

            // Pegar o token CSRF de um dos formulários existentes
            const csrfToken = document.querySelector('input[name="csrf_token"]').value;
            if (csrfToken) {
                formData.append('csrf_token', csrfToken);
            }

            fetch('admin.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        // Remove a linha da tabela suavemente
                        const row = element.closest('tr');
                        row.style.transition = 'opacity 0.5s';
                        row.style.opacity = '0';
                        setTimeout(() => row.remove(), 500);

                        // Atualiza o contador
                        const countElement = document.querySelector('.stat-number');
                        countElement.textContent = parseInt(countElement.textContent) - 1;
                    } else {
                        alert('Erro: ' + data.message);
                    }
                })
                .catch(err => alert('Ocorreu um erro de comunicação com o servidor.'));
        }

        // --- FUNÇÕES DE INTERAÇÕES DO FORMULÁRIO ---
        
        function loadInteractionStats() {
            fetch('admin.php?action=getInteractionStats')
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('totalSessions').textContent = data.stats.totalSessions;
                        document.getElementById('completedForms').textContent = data.stats.completedForms;
                        document.getElementById('abandonedForms').textContent = data.stats.abandonedForms;
                        document.getElementById('conversionRate').textContent = data.stats.conversionRate;
                    }
                })
                .catch(err => console.error('Erro ao carregar estatísticas:', err));
        }

        function loadAbandonmentAnalysis() {
            fetch('admin.php?action=getAbandonmentAnalysis')
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.data.length > 0) {
                        renderAbandonmentChart(data.data);
                    }
                })
                .catch(err => console.error('Erro ao carregar análise de abandono:', err));
        }

        function renderAbandonmentChart(data) {
            const container = document.getElementById('abandonmentChart');
            const maxCount = Math.max(...data.map(d => d.count));
            
            let html = '<div style="padding: 20px;">';
            data.forEach(item => {
                const percentage = (item.count / maxCount) * 100;
                const barColor = percentage > 70 ? '#ef4444' : percentage > 40 ? '#f59e0b' : '#3b82f6';
                
                html += `
                    <div style="margin-bottom: 15px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                            <span style="font-weight: 600; color: #1f2937;">${item.ultimo_campo || 'Campo desconhecido'}</span>
                            <span style="color: #6b7280;">${item.count} abandonos</span>
                        </div>
                        <div style="background: #e5e7eb; height: 24px; border-radius: 12px; overflow: hidden;">
                            <div style="background: ${barColor}; height: 100%; width: ${percentage}%; transition: width 0.5s ease;"></div>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            
            container.innerHTML = html;
        }

        function clearInteractions() {
            if (!confirm('Tem certeza que deseja limpar TODAS as interações? Esta ação não pode ser desfeita.')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'clearInteractions');

            // Tentar pegar token CSRF de algum form existente na página
            const csrfTokenInput = document.querySelector('input[name="csrf_token"]');
            if (csrfTokenInput) {
                formData.append('csrf_token', csrfTokenInput.value);
            }

            fetch('admin.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        loadInteractions();
                        loadInteractionStats();
                        loadAbandonmentAnalysis();
                    } else {
                        alert('Erro: ' + data.message);
                    }
                })
                .catch(err => alert('Erro ao processar a solicitação.'));
        }

        function loadInteractions() {
            const period = document.getElementById('filterPeriod').value;
            const device = document.getElementById('filterDevice').value;
            const name = document.getElementById('filterName').value;

            const params = new URLSearchParams({
                action: 'getInteractionSessions',
                period: period,
                device: device,
                name: name
            });

            const container = document.getElementById('sessionsContainer');
            container.innerHTML = '<p>Carregando sessões...</p>';

            fetch(`admin.php?${params}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.sessions.length > 0) {
                        renderSessions(data.sessions);
                    } else {
                        container.innerHTML = '<p style="text-align: center; color: #6b7280;">Nenhuma sessão encontrada com os filtros aplicados.</p>';
                    }
                })
                .catch(err => {
                    console.error('Erro ao carregar sessões:', err);
                    container.innerHTML = '<p style="color: #ef4444;">Erro ao carregar sessões.</p>';
                });
        }

        function renderSessions(sessions) {
            const container = document.getElementById('sessionsContainer');
            
            let html = '';
            sessions.forEach(session => {
                const status = session.completed ? 'completed' : 'abandoned';
                const statusText = session.completed ? 'Completo' : 'Abandonado';
                const statusIcon = session.completed ? 'fa-check-circle' : 'fa-times-circle';
                
                const firstTime = new Date(session.first_interaction).toLocaleString('pt-BR');
                const lastTime = new Date(session.last_interaction).toLocaleString('pt-BR');
                
                const deviceIcon = session.device === 'Mobile' ? 'fa-mobile-alt' : 
                                  session.device === 'Tablet' ? 'fa-tablet-alt' : 'fa-desktop';
                
	                html += `
	                    <div class="session-card ${status}">
	                        <div class="session-header">
	                            <div>
	                                <strong style="font-size: 1.1rem; color: #1e40af;">${session.nome_completo || 'Usuário não identificado'}</strong>
	                                <span class="session-badge ${status}">
	                                    <i class="fas ${statusIcon}"></i> ${statusText}
	                                </span>
	                            </div>
	                            <button class="btn-expand" onclick="toggleSessionDetails('${session.session_id}', this)">
	                                <i class="fas fa-chevron-down"></i> Ver Linha do Tempo
	                            </button>
	                        </div>
	                        <div class="session-info">
	                            <div class="session-info-item" title="Endereço IP">
	                                <i class="fas fa-network-wired"></i>
	                                <span>IP: ${session.ip}</span>
	                            </div>
	                            <div class="session-info-item" title="Dispositivo e Sistema">
	                                <i class="fas ${deviceIcon}"></i>
	                                <span>${session.device} - ${session.os}</span>
	                            </div>
	                            <div class="session-info-item" title="Navegador">
	                                <i class="fas fa-globe"></i>
	                                <span>${session.browser}</span>
	                            </div>
	                            <div class="session-info-item" title="Data e Hora">
	                                <i class="fas fa-clock"></i>
	                                <span>${lastTime}</span>
	                            </div>
	                            <div class="session-info-item" title="Interações">
	                                <i class="fas fa-mouse-pointer"></i>
	                                <span>${session.interaction_count} interações</span>
	                            </div>
	                        </div>
	                        <div style="margin-top: 10px; padding: 12px; background: ${session.completed ? '#f0fdf4' : '#fef3c7'}; border-radius: 8px; border-left: 4px solid ${session.completed ? '#22c55e' : '#f59e0b'};">
	                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 0.95rem;">
	                                <div>
	                                    <i class="fas fa-step-forward" style="color: #6b7280;"></i>
	                                    <strong>Último campo:</strong> <span style="color: #1f2937; font-weight: 600;">${session.ultimo_campo || 'Nenhum'}</span>
	                                </div>
	                                <div>
	                                    <i class="fas fa-hourglass-half" style="color: #6b7280;"></i>
	                                    <strong>Duração:</strong> ${Math.round((new Date(session.last_interaction) - new Date(session.first_interaction)) / 1000 / 60)} min
	                                </div>
	                            </div>
	                        </div>
	                        <div class="session-timeline" id="timeline-${session.session_id}" style="display: none;">
	                            <p style="text-align: center; color: #6b7280;">Carregando linha do tempo...</p>
	                        </div>
	                    </div>
	                `;
            });
            
            container.innerHTML = html;
        }

        function toggleSessionDetails(sessionId, button) {
            const timeline = document.getElementById('timeline-' + sessionId);
            const icon = button.querySelector('i');
            
            if (timeline.style.display === 'none') {
                // Expandir
                timeline.style.display = 'block';
                icon.classList.remove('fa-chevron-down');
                icon.classList.add('fa-chevron-up');
                button.innerHTML = '<i class="fas fa-chevron-up"></i> Ocultar Detalhes';
                
                // Carregar detalhes se ainda não foram carregados
                if (timeline.innerHTML.includes('Carregando')) {
                    loadSessionDetails(sessionId);
                }
            } else {
                // Recolher
                timeline.style.display = 'none';
                icon.classList.remove('fa-chevron-up');
                icon.classList.add('fa-chevron-down');
                button.innerHTML = '<i class="fas fa-chevron-down"></i> Ver Detalhes';
            }
        }

        function loadSessionDetails(sessionId) {
            fetch(`admin.php?action=getSessionDetails&session_id=${sessionId}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.interactions.length > 0) {
                        renderTimeline(sessionId, data.interactions);
                    } else {
                        document.getElementById('timeline-' + sessionId).innerHTML = 
                            '<p style="text-align: center; color: #6b7280;">Nenhuma interação detalhada encontrada.</p>';
                    }
                })
                .catch(err => {
                    console.error('Erro ao carregar detalhes da sessão:', err);
                    document.getElementById('timeline-' + sessionId).innerHTML = 
                        '<p style="color: #ef4444;">Erro ao carregar detalhes.</p>';
                });
        }

        function renderTimeline(sessionId, interactions) {
            const timeline = document.getElementById('timeline-' + sessionId);
            
            const actionLabels = {
                'focus': '👁️ Focou em',
                'blur': '👋 Saiu de',
                'change': '✏️ Alterou',
                'select': '✅ Selecionou',
                'check': '☑️ Marcou',
                'file_selected': '📎 Anexou arquivo em',
                'form_access': '🌐 Acessou o formulário',
                'form_abandoned': '🚪 Abandonou o formulário',
	                'form_submitted': '✅ Finalizou o cadastro',
	                'form_submit_click': '🖱️ Clicou em finalizar',
	                'update_state': '🔄 Atualização de estado'
	            };
            
            let html = '<h5 style="margin-top: 0; color: #1f2937;"><i class="fas fa-history"></i> Timeline de Interações</h5>';
            html += '<div style="background: #f9fafb; padding: 10px; border-radius: 6px; margin-bottom: 10px; font-size: 0.85rem; color: #6b7280;">';
            html += '<i class="fas fa-info-circle"></i> Mostrando todos os valores digitados e selecionados pelo usuário';
            html += '</div>';
            
            interactions.forEach(interaction => {
                const time = new Date(interaction.timestamp).toLocaleTimeString('pt-BR');
                const action = actionLabels[interaction.acao] || interaction.acao;
                const fieldName = interaction.ultimo_campo || 'campo desconhecido';
                
                // Formatar o valor do campo de forma mais destacada
                let valueDisplay = '';
                if (interaction.valor_campo) {
                    const value = interaction.valor_campo;
                    
                    // Verificar se é um arquivo anexado
                    if (value.includes('Arquivo anexado:')) {
                        valueDisplay = `<div style="margin-top: 5px; padding: 8px; background: #dbeafe; border-left: 3px solid #3b82f6; border-radius: 4px; font-size: 0.9rem;">
                            <i class="fas fa-paperclip"></i> ${value}
                        </div>`;
                    } else {
                        // Valor normal - destacar em um box
                        valueDisplay = `<div style="margin-top: 5px; padding: 8px; background: #f0fdf4; border-left: 3px solid #22c55e; border-radius: 4px;">
                            <strong style="color: #15803d;">Valor:</strong> <span style="color: #166534; font-weight: 500;">${escapeHtml(value)}</span>
                        </div>`;
                    }
                }
                
                html += `
                    <div class="timeline-item" style="margin-bottom: 15px; padding: 12px; background: white; border-radius: 8px; border: 1px solid #e5e7eb;">
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 5px;">
                            <span class="timeline-time" style="background: #f3f4f6; padding: 4px 8px; border-radius: 4px; font-size: 0.85rem; color: #6b7280;">${time}</span>
                            <span class="timeline-action" style="font-weight: 600; color: #1f2937;">${action}</span>
                        </div>
                        <div style="margin-left: 10px;">
                            <span class="timeline-field" style="color: #4b5563; font-size: 0.95rem;">📋 ${fieldName}</span>
                            ${valueDisplay}
                        </div>
                    </div>
                `;
            });
            
            timeline.innerHTML = html;
        }
        
        // Função auxiliar para escapar HTML e prevenir XSS
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // --- FUNÇÕES DE GERENCIAMENTO DE USUÁRIOS ---

        function showAddUserModal() {
            const modal = document.getElementById('addUserModal');
            const form = document.getElementById('addUserForm');
            const success = document.getElementById('addUserSuccess');
            if (modal && form && success) {
                modal.style.display = 'block';
                form.reset();
                success.style.display = 'none';
            }
        }

        // Carregar usuários
        function loadUsers() {
            const tbody = document.getElementById('users-tbody');
            if (!tbody) return; // Só existe para admin

            fetch('admin.php?action=getUsers')
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.users.length > 0) {
                        tbody.innerHTML = data.users.map(u => `
                            <tr>
                                <td>${u.email}</td>
                                <td><span class="user-type ${u.tipo}">${u.tipo === 'admin' ? 'Administrador' : 'Analisador'}</span></td>
                                <td>${new Date(u.created_at).toLocaleString('pt-BR')}</td>
                                <td>
                                    ${u.id != <?php echo $_SESSION['user_id']; ?> ? `<button class="btn-remove btn-small" onclick="deleteUser(${u.id}, this)"><i class="fas fa-trash"></i> Deletar</button>` : '<em>Você</em>'}
                                </td>
                            </tr>
                        `).join('');
                    } else {
                        tbody.innerHTML = '<tr><td colspan="4">Nenhum usuário encontrado.</td></tr>';
                    }
                });
        }

        // Formulário de adicionar usuário (apenas para admin)
        const addUserForm = document.getElementById('addUserForm');
        if (addUserForm) {
            addUserForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                formData.append('action', 'addUser');

                fetch('admin.php', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => {
                        const msgDiv = document.getElementById('addUserSuccess');
                        msgDiv.textContent = data.message;
                        msgDiv.style.display = 'block';
                        if (data.success) {
                            loadUsers();
                            setTimeout(() => {
                                document.getElementById('addUserModal').style.display = 'none';
                            }, 2000);
                        }
                    });
            });
        }

        function deleteUser(id, element) {
            if (!confirm('Tem certeza que deseja deletar este usuário? Esta ação não pode ser desfeita.')) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'deleteUser');
            formData.append('id', id);
            formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

            fetch('admin.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        const row = element.closest('tr');
                        row.style.transition = 'opacity 0.5s';
                        row.style.opacity = '0';
                        setTimeout(() => row.remove(), 500);
                    } else {
                        alert('Erro: ' + data.message);
                    }
                })
                .catch(err => alert('Erro de comunicação com o servidor.'));
        }

        // Função para abrir modal de WhatsApp
        function openWhatsAppModal(curriculoId, nome, telefone) {
            const modal = document.getElementById('whatsappModal');
            const content = document.getElementById('whatsappModalContent');

            content.innerHTML = `
                <div style="text-align: center; margin-bottom: 20px;">
                    <i class="fab fa-whatsapp" style="font-size: 3rem; color: #25d366; margin-bottom: 10px;"></i>
                    <h3 style="margin: 0; color: #1f2937;">Enviar WhatsApp para ${nome}</h3>
                    <p style="margin: 5px 0 0 0; color: #6b7280;">${telefone}</p>
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #374151;">Mensagem:</label>
                    <textarea id="quickWhatsAppMessage" rows="4" placeholder="Digite sua mensagem..." style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 8px; font-family: inherit; resize: vertical;">Olá *${nome}*!

Gostaríamos de conversar sobre seu currículo.
Podemos agendar uma conversa?

Atenciosamente,
Equipe de RH</textarea>
                </div>

                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button onclick="document.getElementById('whatsappModal').style.display='none'" style="background: #6b7280; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer;">
                        Cancelar
                    </button>
                    <button onclick="sendQuickWhatsAppMessage(${curriculoId})" style="background: #25d366; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: 600;">
                        <i class="fab fa-whatsapp"></i> Enviar
                    </button>
                </div>

                <div id="quickWhatsAppMessageStatus" style="margin-top: 15px; text-align: center;"></div>
            `;

            modal.style.display = 'block';
        }

        // Função para enviar mensagem rápida via WhatsApp
        function sendQuickWhatsAppMessage(curriculoId) {
            const messageTextarea = document.getElementById('quickWhatsAppMessage');
            const statusDiv = document.getElementById('quickWhatsAppMessageStatus');
            const mensagem = messageTextarea.value.trim();

            if (!mensagem) {
                statusDiv.textContent = 'Digite uma mensagem antes de enviar.';
                statusDiv.style.color = '#ef4444';
                return;
            }

            // Desabilitar textarea e botão durante o envio
            messageTextarea.disabled = true;
            const sendButton = event.target;
            sendButton.disabled = true;
            sendButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...';

            statusDiv.textContent = 'Enviando mensagem...';
            statusDiv.style.color = '#6b7280';

            const formData = new FormData();
            formData.append('action', 'sendWhatsAppMessage');
            formData.append('curriculo_id', curriculoId);
            formData.append('mensagem', mensagem);

            // Pegar token CSRF
            const csrfToken = document.querySelector('input[name="csrf_token"]');
            if (csrfToken) {
                formData.append('csrf_token', csrfToken.value);
            }

            fetch('admin.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        statusDiv.textContent = 'Mensagem enviada com sucesso!';
                        statusDiv.style.color = '#10b981';
                        setTimeout(() => {
                            document.getElementById('whatsappModal').style.display = 'none';
                        }, 2000);
                    } else {
                        statusDiv.textContent = data.message || 'Erro ao enviar mensagem.';
                        statusDiv.style.color = '#ef4444';
                        // Reabilitar controles em caso de erro
                        messageTextarea.disabled = false;
                        sendButton.disabled = false;
                        sendButton.innerHTML = '<i class="fab fa-whatsapp"></i> Enviar';
                    }
                })
                .catch(err => {
                    console.error('Erro ao enviar WhatsApp:', err);
                    statusDiv.textContent = 'Erro de conexão.';
                    statusDiv.style.color = '#ef4444';
                    messageTextarea.disabled = false;
                    sendButton.disabled = false;
                    sendButton.innerHTML = '<i class="fab fa-whatsapp"></i> Enviar';
                });
        }

        // Função para enviar mensagem WhatsApp
        function sendWhatsAppMessage(curriculoId) {
            const messageTextarea = document.getElementById('whatsappMessage');
            const sendMessage = document.getElementById('whatsappSendMessage');
            const mensagem = messageTextarea.value.trim();

            if (!mensagem) {
                sendMessage.textContent = 'Digite uma mensagem antes de enviar.';
                sendMessage.style.color = '#ef4444';
                return;
            }

            // Desabilitar textarea e botão durante o envio
            messageTextarea.disabled = true;
            const sendButton = event.target;
            sendButton.disabled = true;
            sendButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...';

            sendMessage.textContent = 'Enviando mensagem...';
            sendMessage.style.color = '#6b7280';

            const formData = new FormData();
            formData.append('action', 'sendWhatsAppMessage');
            formData.append('curriculo_id', curriculoId);
            formData.append('mensagem', mensagem);

            // Pegar token CSRF
            const csrfToken = document.querySelector('input[name="csrf_token"]');
            if (csrfToken) {
                formData.append('csrf_token', csrfToken.value);
            }

            fetch('admin.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        sendMessage.textContent = 'Mensagem enviada com sucesso!';
                        sendMessage.style.color = '#10b981';
                        messageTextarea.value = ''; // Limpar textarea
                        loadWhatsAppHistory(curriculoId); // Recarregar histórico
                    } else {
                        sendMessage.textContent = data.message || 'Erro ao enviar mensagem.';
                        sendMessage.style.color = '#ef4444';
                    }
                })
                .catch(err => {
                    console.error('Erro ao enviar WhatsApp:', err);
                    sendMessage.textContent = 'Erro de conexão.';
                    sendMessage.style.color = '#ef4444';
                })
                .finally(() => {
                    // Reabilitar controles
                    messageTextarea.disabled = false;
                    sendButton.disabled = false;
                    sendButton.innerHTML = '<i class="fab fa-whatsapp"></i> Enviar WhatsApp';
                });
        }

        // Função para carregar histórico de mensagens WhatsApp
        function loadWhatsAppHistory(curriculoId) {
            const historyDiv = document.getElementById('whatsappHistory');
            const historyContent = document.getElementById('whatsappHistoryContent');

            historyContent.innerHTML = '<p style="text-align: center; color: #6b7280;"><i class="fas fa-spinner fa-spin"></i> Carregando...</p>';
            historyDiv.style.display = 'block';

            fetch(`admin.php?action=getWhatsAppHistory&curriculo_id=${curriculoId}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.mensagens.length > 0) {
                        let html = '';
                        data.mensagens.forEach(msg => {
                            const statusIcon = msg.status_envio === 'enviado' ? 'fa-check-circle' : 'fa-times-circle';
                            const statusColor = msg.status_envio === 'enviado' ? '#10b981' : '#ef4444';
                            const dataFormatada = new Date(msg.data_envio).toLocaleString('pt-BR');

                            html += `
                                <div style="margin-bottom: 15px; padding: 12px; background: #f8fafc; border-radius: 8px; border-left: 3px solid ${statusColor};">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                        <span style="font-weight: 600; color: ${statusColor};">
                                            <i class="fas ${statusIcon}"></i> ${msg.status_envio === 'enviado' ? 'Enviada' : 'Erro'}
                                        </span>
                                        <small style="color: #6b7280;">${dataFormatada}</small>
                                    </div>
                                    <div style="margin-bottom: 5px;">
                                        <strong>Por:</strong> ${msg.enviado_por}
                                    </div>
                                    <div style="background: white; padding: 8px; border-radius: 4px; border: 1px solid #e5e7eb; font-family: 'Segoe UI', sans-serif;">
                                        ${msg.mensagem.replace(/\n/g, '<br>')}
                                    </div>
                                </div>
                            `;
                        });
                        historyContent.innerHTML = html;
                    } else {
                        historyContent.innerHTML = '<p style="text-align: center; color: #6b7280;">Nenhuma mensagem enviada ainda.</p>';
                    }
                })
                .catch(err => {
                    console.error('Erro ao carregar histórico:', err);
                    historyContent.innerHTML = '<p style="color: #ef4444;">Erro ao carregar histórico.</p>';
                });
        }

        // Função para abrir modal de informações de contato
        function openContactInfoModal(curriculoId, nome) {
            const modal = document.getElementById('contactInfoModal');
            const modalContent = document.getElementById('contactInfoModalContent');

            modalContent.innerHTML = `
                <div style="text-align: center; margin-bottom: 20px;">
                    <i class="fas fa-address-book" style="font-size: 3rem; color: #3b82f6; margin-bottom: 10px;"></i>
                    <h3 style="margin: 0; color: #1f2937;">Informações de Contato</h3>
                    <p style="margin: 5px 0 0 0; color: #6b7280;">${nome}</p>
                </div>

                <div id="contactInfoContent" style="margin-bottom: 20px;">
                    <p style="text-align: center; color: #6b7280;"><i class="fas fa-spinner fa-spin"></i> Carregando...</p>
                </div>

                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button onclick="document.getElementById('contactInfoModal').style.display='none'" style="background: #6b7280; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer;">
                        Fechar
                    </button>
                    <button onclick="showAddContactForm(${curriculoId})" style="background: #3b82f6; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: 600;">
                        <i class="fas fa-plus"></i> Adicionar Informação
                    </button>
                </div>
            `;

            modal.style.display = 'block';
            loadContactInfo(curriculoId);
        }

        // Função para carregar informações de contato
        function loadContactInfo(curriculoId) {
            fetch(`admin.php?action=getContactInfo&curriculo_id=${curriculoId}`)
                .then(res => res.json())
                .then(data => {
                    const contentDiv = document.getElementById('contactInfoContent');
                    
                    if (data.success && data.contacts.length > 0) {
                        let html = '<div style="max-height: 400px; overflow-y: auto;">';
                        data.contacts.forEach(contact => {
                            const dataFormatada = new Date(contact.data_registro).toLocaleString('pt-BR');
                            html += `
                                <div style="margin-bottom: 15px; padding: 15px; background: #f8fafc; border-radius: 8px; border-left: 3px solid #3b82f6;">
                                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                                        <div style="flex: 1;">
                                            <div style="font-weight: 600; color: #1f2937; margin-bottom: 5px;">
                                                <i class="fas fa-tag"></i> ${contact.tipo_contato}
                                            </div>
                                            <div style="color: #374151; margin-bottom: 8px;">
                                                ${contact.informacao.replace(/\n/g, '<br>')}
                                            </div>
                                            ${contact.observacoes ? `
                                                <div style="color: #6b7280; font-size: 0.9rem; font-style: italic;">
                                                    <i class="fas fa-comment"></i> ${contact.observacoes}
                                                </div>
                                            ` : ''}
                                            <div style="color: #9ca3af; font-size: 0.85rem; margin-top: 8px;">
                                                <i class="fas fa-clock"></i> ${dataFormatada} | <i class="fas fa-user"></i> ${contact.registrado_por}
                                            </div>
                                        </div>
                                        <div style="display: flex; gap: 5px;">
                                            <button onclick="editContactInfo(${contact.id}, ${curriculoId})" style="background: #f59e0b; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 0.85rem;">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button onclick="deleteContactInfo(${contact.id}, ${curriculoId})" style="background: #ef4444; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 0.85rem;">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            `;
                        });
                        html += '</div>';
                        contentDiv.innerHTML = html;
                    } else {
                        contentDiv.innerHTML = '<p style="text-align: center; color: #6b7280; padding: 20px;">Nenhuma informação de contato registrada ainda.</p>';
                    }
                })
                .catch(err => {
                    console.error('Erro ao carregar informações:', err);
                    document.getElementById('contactInfoContent').innerHTML = '<p style="color: #ef4444; text-align: center;">Erro ao carregar informações.</p>';
                });
        }

        // Função para mostrar formulário de adicionar contato
        function showAddContactForm(curriculoId) {
            const contentDiv = document.getElementById('contactInfoContent');
            contentDiv.innerHTML = `
                <div style="background: white; padding: 20px; border-radius: 8px; border: 2px solid #3b82f6;">
                    <h4 style="margin-top: 0; color: #1f2937;"><i class="fas fa-plus-circle"></i> Nova Informação de Contato</h4>
                    
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #374151;">Tipo de Contato:</label>
                        <select id="newContactType" style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px;">
                            <option value="Telefone Adicional">Telefone Adicional</option>
                            <option value="Email Adicional">Email Adicional</option>
                            <option value="WhatsApp">WhatsApp</option>
                            <option value="LinkedIn">LinkedIn</option>
                            <option value="Endereço">Endereço</option>
                            <option value="Contato de Emergência">Contato de Emergência</option>
                            <option value="Referência">Referência</option>
                            <option value="Outro">Outro</option>
                        </select>
                    </div>

                    <div style="margin-bottom: 15px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #374151;">Informação:</label>
                        <textarea id="newContactInfo" rows="3" placeholder="Digite a informação..." style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; font-family: inherit; resize: vertical;"></textarea>
                    </div>

                    <div style="margin-bottom: 15px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #374151;">Observações (opcional):</label>
                        <textarea id="newContactObs" rows="2" placeholder="Observações adicionais..." style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; font-family: inherit; resize: vertical;"></textarea>
                    </div>

                    <div style="display: flex; gap: 10px; justify-content: flex-end;">
                        <button onclick="loadContactInfo(${curriculoId})" style="background: #6b7280; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer;">
                            Cancelar
                        </button>
                        <button onclick="saveContactInfo(${curriculoId})" style="background: #10b981; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: 600;">
                            <i class="fas fa-save"></i> Salvar
                        </button>
                    </div>

                    <div id="contactFormStatus" style="margin-top: 15px; text-align: center;"></div>
                </div>
            `;
        }

        // Função para salvar informação de contato
        function saveContactInfo(curriculoId, contactId = null) {
            const tipo = document.getElementById('newContactType').value;
            const informacao = document.getElementById('newContactInfo').value.trim();
            const observacoes = document.getElementById('newContactObs').value.trim();
            const statusDiv = document.getElementById('contactFormStatus');

            if (!informacao) {
                statusDiv.textContent = 'Por favor, preencha a informação.';
                statusDiv.style.color = '#ef4444';
                return;
            }

            statusDiv.textContent = 'Salvando...';
            statusDiv.style.color = '#6b7280';

            const formData = new FormData();
            formData.append('action', contactId ? 'updateContactInfo' : 'addContactInfo');
            formData.append('curriculo_id', curriculoId);
            formData.append('tipo_contato', tipo);
            formData.append('informacao', informacao);
            formData.append('observacoes', observacoes);
            if (contactId) formData.append('contact_id', contactId);

            const csrfToken = document.querySelector('input[name="csrf_token"]');
            if (csrfToken) {
                formData.append('csrf_token', csrfToken.value);
            }

            fetch('admin.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        statusDiv.textContent = 'Salvo com sucesso!';
                        statusDiv.style.color = '#10b981';
                        setTimeout(() => loadContactInfo(curriculoId), 1000);
                    } else {
                        statusDiv.textContent = data.message || 'Erro ao salvar.';
                        statusDiv.style.color = '#ef4444';
                    }
                })
                .catch(err => {
                    console.error('Erro ao salvar:', err);
                    statusDiv.textContent = 'Erro de conexão.';
                    statusDiv.style.color = '#ef4444';
                });
        }

        // Função para editar informação de contato
        function editContactInfo(contactId, curriculoId) {
            fetch(`admin.php?action=getContactInfo&curriculo_id=${curriculoId}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        const contact = data.contacts.find(c => c.id == contactId);
                        if (contact) {
                            const contentDiv = document.getElementById('contactInfoContent');
                            contentDiv.innerHTML = `
                                <div style="background: white; padding: 20px; border-radius: 8px; border: 2px solid #f59e0b;">
                                    <h4 style="margin-top: 0; color: #1f2937;"><i class="fas fa-edit"></i> Editar Informação de Contato</h4>
                                    
                                    <div style="margin-bottom: 15px;">
                                        <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #374151;">Tipo de Contato:</label>
                                        <select id="newContactType" style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px;">
                                            <option value="Telefone Adicional" ${contact.tipo_contato === 'Telefone Adicional' ? 'selected' : ''}>Telefone Adicional</option>
                                            <option value="Email Adicional" ${contact.tipo_contato === 'Email Adicional' ? 'selected' : ''}>Email Adicional</option>
                                            <option value="WhatsApp" ${contact.tipo_contato === 'WhatsApp' ? 'selected' : ''}>WhatsApp</option>
                                            <option value="LinkedIn" ${contact.tipo_contato === 'LinkedIn' ? 'selected' : ''}>LinkedIn</option>
                                            <option value="Endereço" ${contact.tipo_contato === 'Endereço' ? 'selected' : ''}>Endereço</option>
                                            <option value="Contato de Emergência" ${contact.tipo_contato === 'Contato de Emergência' ? 'selected' : ''}>Contato de Emergência</option>
                                            <option value="Referência" ${contact.tipo_contato === 'Referência' ? 'selected' : ''}>Referência</option>
                                            <option value="Outro" ${contact.tipo_contato === 'Outro' ? 'selected' : ''}>Outro</option>
                                        </select>
                                    </div>

                                    <div style="margin-bottom: 15px;">
                                        <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #374151;">Informação:</label>
                                        <textarea id="newContactInfo" rows="3" placeholder="Digite a informação..." style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; font-family: inherit; resize: vertical;">${contact.informacao}</textarea>
                                    </div>

                                    <div style="margin-bottom: 15px;">
                                        <label style="display: block; margin-bottom: 5px; font-weight: 600; color: #374151;">Observações (opcional):</label>
                                        <textarea id="newContactObs" rows="2" placeholder="Observações adicionais..." style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; font-family: inherit; resize: vertical;">${contact.observacoes || ''}</textarea>
                                    </div>

                                    <div style="display: flex; gap: 10px; justify-content: flex-end;">
                                        <button onclick="loadContactInfo(${curriculoId})" style="background: #6b7280; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer;">
                                            Cancelar
                                        </button>
                                        <button onclick="saveContactInfo(${curriculoId}, ${contactId})" style="background: #10b981; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: 600;">
                                            <i class="fas fa-save"></i> Atualizar
                                        </button>
                                    </div>

                                    <div id="contactFormStatus" style="margin-top: 15px; text-align: center;"></div>
                                </div>
                            `;
                        }
                    }
                })
                .catch(err => console.error('Erro ao carregar contato:', err));
        }

        // Função para deletar informação de contato
        function deleteContactInfo(contactId, curriculoId) {
            if (!confirm('Tem certeza que deseja deletar esta informação de contato?')) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'deleteContactInfo');
            formData.append('contact_id', contactId);

            const csrfToken = document.querySelector('input[name="csrf_token"]');
            if (csrfToken) {
                formData.append('csrf_token', csrfToken.value);
            }

            fetch('admin.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        loadContactInfo(curriculoId);
                    } else {
                        alert(data.message || 'Erro ao deletar.');
                    }
                })
                .catch(err => {
                    console.error('Erro ao deletar:', err);
                    alert('Erro de conexão.');
                });
        }

        // Carregamento inicial
        document.addEventListener('DOMContentLoaded', function() {
            if (userType === 'admin' || userType === 'analisador') {
                loadCurriculos();
            }
            if (userType === 'admin') {
                loadUsers();
                // Carregar estatísticas de interações apenas para admin
                if (document.getElementById('interactions-tab')) {
                    loadInteractionStats();
                    loadAbandonmentAnalysis();
                    loadInteractions();
                }
            }
        });
        // Lógica de Atualização (Upload e Apply)
        if (document.getElementById('uploadUpdateForm')) {
            document.getElementById('uploadUpdateForm').addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                const statusDiv = document.getElementById('uploadUpdateStatus');
                const btn = document.getElementById('btnUploadUpdate');
                
                statusDiv.innerHTML = '<span style="color: #4b5563;"><i class="fas fa-spinner fa-spin"></i> Enviando e validando arquivo...</span>';
                btn.disabled = true;

                fetch('admin.php', { method: 'POST', body: formData })
                .then(r => r.text())
                .then(text => {
                    try {
                        const data = JSON.parse(text);
                        if (data.success) {
                            statusDiv.innerHTML = `<span style="color: #10b981;">${data.message}</span>`;
                            document.getElementById('applyUpdateSection').style.display = 'block';
                            document.getElementById('updateVersionInfo').innerHTML = `<strong>Versão Pronta:</strong> ${data.version}<br><strong>Notas:</strong> ${data.notes}`;
                        } else {
                            statusDiv.innerHTML = `<span style="color: #ef4444;">${data.message}</span>`;
                            btn.disabled = false;
                        }
                    } catch (e) {
                        console.error('Resposta do servidor não é JSON:', text);
                        statusDiv.innerHTML = `<span style="color: #ef4444;">Erro interno do servidor. Pressione F12 e olhe o console para ver o erro do PHP. (Ou o arquivo é maior que o permitido).</span>`;
                        btn.disabled = false;
                    }
                })
                .catch(err => {
                    statusDiv.innerHTML = `<span style="color: #ef4444;">Erro de conexão ao enviar o arquivo.</span>`;
                    btn.disabled = false;
                });
            });

            document.getElementById('applyUpdateForm').addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                const statusDiv = document.getElementById('applyUpdateStatus');
                const btn = document.getElementById('btnApplyUpdate');
                
                statusDiv.innerHTML = '<span style="color: #4b5563;"><i class="fas fa-spinner fa-spin"></i> Criando backup e instalando atualização. Isso pode demorar alguns minutos...</span>';
                btn.disabled = true;

                fetch('admin.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        statusDiv.innerHTML = `<span style="color: #10b981;">${data.message} Recarregando painel...</span>`;
                        setTimeout(() => window.location.reload(), 3000);
                    } else {
                        statusDiv.innerHTML = `<span style="color: #ef4444;">${data.message}</span>`;
                        btn.disabled = false;
                    }
                })
                .catch(err => {
                    statusDiv.innerHTML = `<span style="color: #ef4444;">Erro de conexão ao aplicar a atualização.</span>`;
                    btn.disabled = false;
                });
            });
        }
    </script>

    <!-- Modal de Informações de Contato -->
    <div id="contactInfoModal" class="modal">
        <div class="modal-content" style="max-width: 700px;">
            <span class="close" onclick="document.getElementById('contactInfoModal').style.display='none'">&times;</span>
            <div id="contactInfoModalContent"></div>
        </div>
    </div>
</body>
</html>

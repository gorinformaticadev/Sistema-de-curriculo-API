<?php
session_start();

// Incluir o arquivo de conexão com o banco de dados e funções de log
require_once 'db_connect.php';

// --- FUNÇÕES GLOBAIS ---

// Função para log de erros (pode ser movida para um arquivo de helpers no futuro)
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

// Carregar configuração do banco de dados
function loadConfigFromDB($pdo) {
    $config = [];
    $stmt = $pdo->query("SELECT chave, valor FROM config");
    while ($row = $stmt->fetch()) {
        $config[$row['chave']] = $row['valor'];
    }
    return $config;
}

$config = loadConfigFromDB($pdo);

// Garantir que configurações padrão existam
$defaultConfigs = [
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

foreach ($defaultConfigs as $key => $value) {
    if (!isset($config[$key])) {
        $stmt = $pdo->prepare("INSERT INTO config (chave, valor) VALUES (?, ?)");
        $stmt->execute([$key, $value]);
        $config[$key] = $value;
    }
}

// Verificar se é admin
function isAdmin() {
    return isset($_SESSION['admin']) && $_SESSION['admin'] === true;
}

// Verificar tipo de usuário
function getUserType() {
    return $_SESSION['user_type'] ?? 'admin'; // padrão admin para compatibilidade
}

// Verificar se usuário pode acessar uma aba específica
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

// Verificar se usuário pode executar uma ação específica
function canAccessAction($action) {
    $userType = getUserType();
    if ($userType === 'admin' || $userType === 'analisador') {
        return true; // admin e analisador podem tudo
    }
    return false;
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

    $stmt = $pdo->prepare("SELECT id, nome, telefone, email, cidade, data_cadastro, status, visualizado, data_visualizacao FROM curriculos {$whereClause} ORDER BY data_cadastro DESC");
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
        // Converter valores booleanos de volta para texto para exibição
        $curriculo['is_whatsapp'] = $curriculo['is_whatsapp'] ? 'Sim' : 'Não';
        $curriculo['estudando'] = $curriculo['estudando'] ? 'Sim, estou!' : 'Não, não estou!';
        $curriculo['possui_cursos'] = $curriculo['possui_cursos'] ? 'Sim' : 'Não';
        $curriculo['possui_experiencia'] = $curriculo['possui_experiencia'] ? 'Sim' : 'Não';

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

    $validStatuses = ['pendente_novo', 'pendente', 'classificado', 'arquivado'];
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

        $data = ['number' => $number, 'body' => $body, 'saveOnTicket' => true];
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

        echo json_encode([
            'success' => $httpcode === 200,
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

            // Formulários completos (sessões que têm registro na tabela curriculos)
            $stmt = $pdo->query("
                SELECT COUNT(DISTINCT fi.session_id) as total 
                FROM form_interactions fi
                INNER JOIN curriculos c ON DATE(fi.timestamp) = DATE(c.data_cadastro)
            ");
            $completedForms = $stmt->fetchColumn();

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
            // Buscar último campo de cada sessão que não completou o formulário
            $stmt = $pdo->query("
                SELECT 
                    ultimo_campo,
                    COUNT(*) as count
                FROM (
                    SELECT 
                        session_id,
                        ultimo_campo,
                        MAX(timestamp) as last_time
                    FROM form_interactions
                    WHERE ultimo_campo IS NOT NULL
                    GROUP BY session_id
                ) as last_interactions
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
                    $where[] = "DATE(timestamp) = CURDATE()";
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

            // Buscar sessões agrupadas
            $stmt = $pdo->prepare("
                SELECT 
                    session_id,
                    ip,
                    browser,
                    os,
                    device,
                    nome_completo,
                    ultimo_campo,
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

            // Para cada sessão, verificar se foi completada
            foreach ($sessions as &$session) {
                // 1. Verificar se existe currículo cadastrado próximo ao horário da sessão
                $stmt = $pdo->prepare("
                    SELECT id FROM curriculos 
                    WHERE ip_cadastro = ? 
                    AND ABS(TIMESTAMPDIFF(MINUTE, data_cadastro, ?)) <= 30
                    LIMIT 1
                ");
                $stmt->execute([$session['ip'], $session['last_interaction']]);
                $hasCurriculo = $stmt->rowCount() > 0;

                // 2. Verificar se houve clique no botão de finalizar (ação 'form_submitted')
                $stmt2 = $pdo->prepare("
                    SELECT 1 FROM form_interactions 
                    WHERE session_id = ? 
                    AND acao = 'form_submitted' 
                    LIMIT 1
                ");
                $stmt2->execute([$session['session_id']]);
                $hasSubmitAction = $stmt2->rowCount() > 0;

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
        .status-classificado {
            background: #d1fae5;
            color: #065f46;
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
                                    <option value="classificado">Classificado</option>
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

                    <table class="curriculos-table">
                        <thead><tr><th>Data/Hora</th><th>Nome</th><th>Telefone</th><th>Email</th><th>Cidade</th><th>Status</th><th>Ações</th></tr></thead>
                        <tbody id="curriculos-tbody">
                            <!-- Conteúdo carregado via JS -->
                        </tbody>
                    </table>
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
                    <table class="curriculos-table">
                        <thead><tr><th>Email</th><th>Tipo</th><th>Data de Criação</th><th>Ações</th></tr></thead>
                        <tbody id="users-tbody">
                            <!-- Conteúdo carregado via JS -->
                        </tbody>
                    </table>
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
                                'classificado': '<span class="status-badge status-classificado">Classificado</span>',
                                'arquivado': '<span class="status-badge status-arquivado">Arquivado</span>'
                            };

                            const statusHtml = statusLabels[c.status] || c.status;

                            return `
                                <tr>
                                    <td>${new Date(c.data_cadastro).toLocaleString('pt-BR')}</td>
                                    <td>${c.nome}</td>
                                    <td>${c.telefone}</td>
                                    <td>${c.email || 'N/A'}</td>
                                    <td>${c.cidade}</td>
                                    <td>${statusHtml}</td>
                                    <td>
                                        <button class="btn-primary btn-small" onclick="viewCurriculo(${c.id})"><i class="fas fa-eye"></i> Ver</button>
                                        <select class="status-select" onchange="changeStatus(${c.id}, this.value)" style="margin-left: 5px; padding: 2px 5px; font-size: 0.8rem;">
                                            <option value="pendente_novo" ${c.status === 'pendente_novo' ? 'selected' : ''}>Pendente - Novo</option>
                                            <option value="pendente" ${c.status === 'pendente' ? 'selected' : ''}>Pendente</option>
                                            <option value="classificado" ${c.status === 'classificado' ? 'selected' : ''}>Classificado</option>
                                            <option value="arquivado" ${c.status === 'arquivado' ? 'selected' : ''}>Arquivado</option>
                                        </select>
                                        ${userType === 'admin' ? `<button class="btn-remove btn-small" onclick="deleteCurriculo(${c.id}, this)" style="margin-left: 5px;"><i class="fas fa-trash"></i> Deletar</button>` : ''}
                                    </td>
                                </tr>
                            `;
                        }).join('');
                    } else {
                        tbody.innerHTML = '<tr><td colspan="7">Nenhum currículo encontrado.</td></tr>';
                    }
                })
                .catch(err => {
                    console.error('Erro ao carregar currículos:', err);
                    const tbody = document.getElementById('curriculos-tbody');
                    tbody.innerHTML = '<tr><td colspan="7">Erro ao carregar currículos.</td></tr>';
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
                                </div>
                            `).join('');
                        }

                        modalBody.innerHTML = `
                            <h3><i class="fas fa-user"></i> Dados Pessoais</h3>
                            <p><strong>Nome:</strong> ${c.nome}</p>
                            <p><strong>Data de Nascimento:</strong> ${new Date(c.data_nascimento + 'T00:00:00').toLocaleDateString('pt-BR')}</p>
                            <p><strong>Estado Civil:</strong> ${c.estado_civil}</p>

                            <h3><i class="fas fa-phone"></i> Contato</h3>
                            <p><strong>Telefone:</strong> ${c.telefone}</p>
                            <p><strong>É WhatsApp?:</strong> ${c.is_whatsapp}</p>
                            <p><strong>Email:</strong> ${c.email || 'Não informado'}</p>
                            <p><strong>Facebook:</strong> ${c.facebook || 'Não informado'}</p>
                            <p><strong>Instagram:</strong> ${c.instagram || 'Não informado'}</p>

                            <h3><i class="fas fa-map-marker-alt"></i> Endereço</h3>
                            <p><strong>Endereço:</strong> ${c.endereco}</p>
                            <p><strong>Cidade:</strong> ${c.cidade}</p>
                            <p><strong>Estado:</strong> ${c.estado}</p>

                            <h3><i class="fas fa-graduation-cap"></i> Formação</h3>
                            <p><strong>Escolaridade:</strong> ${c.escolaridade}</p>
                            <p><strong>Está Estudando?:</strong> ${c.estudando}</p>
                            ${c.periodo_estudo ? `<p><strong>Período de Estudo:</strong> ${c.periodo_estudo}</p>` : ''}
                            <p><strong>Possui Cursos?:</strong> ${c.possui_cursos}</p>
                            ${c.cursos ? `<p><strong>Cursos:</strong><br>${c.cursos.replace(/\n/g, '<br>')}</p>` : ''}

                            <h3><i class="fas fa-briefcase"></i> Experiência Profissional</h3>
                            ${experiencesHtml}

                            <h3><i class="fas fa-target"></i> Objetivo</h3>
                            <p>${c.motivacao.replace(/\n/g, '<br>')}</p>

                            <h3><i class="fas fa-file-alt"></i> Arquivos</h3>
                            <div class="modal-files">
                                <a href="javascript:void(0);" onclick="showPdfModal('uploads/' + encodeURIComponent('${c.arquivo_curriculo}'))"><i class="fas fa-file-pdf"></i> Ver Currículo (PDF)</a>
                                <a href="javascript:void(0);" onclick="showImageModal('uploads/' + encodeURIComponent('${c.arquivo_foto}'))"><i class="fas fa-camera"></i> Ver Foto</a>
                            </div>

                            <hr style="margin-top: 20px;">
                            <p><small>IP do Cadastro: ${c.ip_cadastro} | Data: ${new Date(c.data_cadastro).toLocaleString('pt-BR')}</small></p>
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
                                <strong>${session.nome_completo || 'Nome não informado'}</strong>
                                <span class="session-badge ${status}">
                                    <i class="fas ${statusIcon}"></i> ${statusText}
                                </span>
                            </div>
                            <button class="btn-expand" onclick="toggleSessionDetails('${session.session_id}', this)">
                                <i class="fas fa-chevron-down"></i> Ver Detalhes
                            </button>
                        </div>
                        <div class="session-info">
                            <div class="session-info-item">
                                <i class="fas fa-network-wired"></i>
                                <span>IP: ${session.ip}</span>
                            </div>
                            <div class="session-info-item">
                                <i class="fas ${deviceIcon}"></i>
                                <span>${session.device} - ${session.os}</span>
                            </div>
                            <div class="session-info-item">
                                <i class="fas fa-browser"></i>
                                <span>${session.browser}</span>
                            </div>
                            <div class="session-info-item">
                                <i class="fas fa-clock"></i>
                                <span>${firstTime}</span>
                            </div>
                            <div class="session-info-item">
                                <i class="fas fa-mouse-pointer"></i>
                                <span>${session.interaction_count} interações</span>
                            </div>
                        </div>
                        ${!session.completed ? `
                            <div style="margin-top: 10px; padding: 10px; background: #fef3c7; border-radius: 6px; font-size: 0.9rem;">
                                <i class="fas fa-exclamation-triangle" style="color: #f59e0b;"></i>
                                <strong>Último campo interagido:</strong> ${session.ultimo_campo || 'Desconhecido'}
                            </div>
                        ` : ''}
                        <div class="session-timeline" id="timeline-${session.session_id}" style="display: none;">
                            <p style="text-align: center; color: #6b7280;">Carregando timeline...</p>
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
                'file_selected': '📎 Anexou arquivo em'
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
    </script>
</body>
</html>

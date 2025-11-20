<?php
session_start();

// Incluir o arquivo de conexão com o banco de dados e funções de log
require_once 'db_connect.php';

// --- FUNÇÕES GLOBAIS ---

// Função para log de erros (pode ser movida para um arquivo de helpers no futuro)
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

// --- PROCESSAMENTO DE AÇÕES (POST/GET) ---

// Login
if (isset($_POST['login'])) {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['senha'])) {
        $_SESSION['admin'] = true;
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        header('Location: admin.php');
        exit;
    } else {
        $error = 'Credenciais inválidas';
    }
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

    // Atualizar credenciais do usuário
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

        if (!empty($password)) {
            // Atualiza email e senha
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE usuarios SET email = ?, senha = ? WHERE id = ?");
            $stmt->execute([$email, $newHash, $_SESSION['user_id']]);
        } else {
            // Atualiza apenas o email
            $stmt = $pdo->prepare("UPDATE usuarios SET email = ? WHERE id = ?");
            $stmt->execute([$email, $_SESSION['user_id']]);
        }
        $_SESSION['user_email'] = $email; // Atualiza o email na sessão
        echo json_encode(['success' => true, 'message' => 'Credenciais atualizadas com sucesso!']);
        exit;
    }
    
    // Outras APIs (getCurriculos, getLogs, etc.) permanecem as mesmas por enquanto, mas precisarão ser adaptadas
    // API para carregar currículos
    if (isset($_GET['action']) && $_GET['action'] === 'getCurriculos') {
        header('Content-Type: application/json');
        $stmt = $pdo->query("SELECT id, nome, telefone, email, cidade, data_cadastro FROM curriculos ORDER BY data_cadastro DESC");
        $curriculos = $stmt->fetchAll();
        echo json_encode(['success' => true, 'curriculos' => $curriculos]);
        exit;
    }
    
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

    // API para buscar detalhes de um currículo específico
    if (isset($_GET['action']) && $_GET['action'] === 'getCurriculoDetails' && isset($_GET['id'])) {
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
                <div class="stats-card">
                    <div class="stat"><h3>Currículos Recebidos</h3><p class="stat-number"><?php echo $totalCurriculos; ?></p></div>
                    <i class="fas fa-users"></i>
                </div>

                <div class="tabs">
                    <button class="tab active" onclick="showTab('curriculos')"><i class="fas fa-list"></i> Currículos</button>
                    <button class="tab" onclick="showTab('config')"><i class="fas fa-cog"></i> Configurações</button>
                    <button class="tab" onclick="showTab('tests')"><i class="fas fa-vial"></i> Testes da API</button>
                    <button class="tab" onclick="showTab('logs')"><i class="fas fa-file-alt"></i> Logs do Sistema</button>
                    <button class="tab" onclick="showTab('access')"><i class="fas fa-eye"></i> Logs de Acesso</button>
                </div>

                <!-- Tab Currículos -->
                <div id="curriculos-tab" class="tab-content active">
                    <h3><i class="fas fa-list"></i> Currículos Cadastrados</h3>
                    <table class="curriculos-table">
                        <thead><tr><th>Data/Hora</th><th>Nome</th><th>Telefone</th><th>Email</th><th>Cidade</th><th>Ações</th></tr></thead>
                        <tbody id="curriculos-tbody">
                            <!-- Conteúdo carregado via JS -->
                        </tbody>
                    </table>
                </div>

                <!-- Tab Configurações -->
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

                <!-- Tab Testes -->
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

                <!-- Tab Logs -->
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

                <!-- Tab Access Logs -->
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
            </div>
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
        function showTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.getElementById(tabName + '-tab').classList.add('active');
            event.currentTarget.classList.add('active');

            if (tabName === 'logs') {
                loadLogs();
            } else if (tabName === 'access') {
                loadAccessLogs();
            }
        }

        // Carregar currículos via fetch
        function loadCurriculos() {
            fetch('admin.php?action=getCurriculos')
                .then(res => res.json())
                .then(data => {
                    const tbody = document.getElementById('curriculos-tbody');
                    if (data.success && data.curriculos.length > 0) {
                        tbody.innerHTML = data.curriculos.map(c => `
                            <tr>
                                <td>${new Date(c.data_cadastro).toLocaleString('pt-BR')}</td>
                                <td>${c.nome}</td>
                                <td>${c.telefone}</td>
                                <td>${c.email || 'N/A'}</td>
                                <td>${c.cidade}</td>
                                <td>
                                    <button class="btn-primary btn-small" onclick="viewCurriculo(${c.id})"><i class="fas fa-eye"></i> Ver</button>
                                    <button class="btn-remove btn-small" onclick="deleteCurriculo(${c.id}, this)"><i class="fas fa-trash"></i> Deletar</button>
                                </td>
                            </tr>
                        `).join('');
                    } else {
                        tbody.innerHTML = '<tr><td colspan="6">Nenhum currículo encontrado.</td></tr>';
                    }
                });
        }
        
        // Salvar Config API
        document.getElementById('apiConfigForm').addEventListener('submit', function(e) {
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

        // Salvar Config Email
        document.getElementById('emailConfigForm').addEventListener('submit', function(e) {
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

        // Salvar Credenciais
        document.getElementById('credentialsForm').addEventListener('submit', function(e) {
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

        function loadLogs() {
            const container = document.getElementById('logsContainer');
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

        // Teste da API
        document.getElementById('apiTestForm').addEventListener('submit', function(e) {
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

        // Carregamento inicial
        document.addEventListener('DOMContentLoaded', function() {
            loadCurriculos();
        });
    </script>
</body>
</html>

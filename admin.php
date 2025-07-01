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
        $params = ['api_url', 'api_token', 'notification_number'];
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
                        <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Salvar Configurações da API</button>
                        <div id="configApiSuccess" class="success-message" style="display: none;"></div>
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
            </div>
        </div>
    </div>

    <!-- Modal para visualizar currículo (precisará ser adaptado) -->

    <script>
        function showTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.getElementById(tabName + '-tab').classList.add('active');
            event.currentTarget.classList.add('active');

            if (tabName === 'logs') {
                loadLogs();
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
                                    <button class="btn-primary btn-small">Ver</button>
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

        // Carregamento inicial
        document.addEventListener('DOMContentLoaded', function() {
            loadCurriculos();
        });
    </script>
</body>
</html>

<?php
session_start();

// --- CONFIGURAÇÃO E FUNÇÕES GLOBAIS ---

// Carregar configuração
function loadConfig() {
    $configFile = 'config.json';
    if (!file_exists($configFile)) {
        // Tenta criar um arquivo de configuração padrão se não existir
        $defaultConfig = [
            'apiToken' => '',
            'authorizedEmails' => ['rh@gorinformatica.com.br'],
            'adminPasswordHash' => password_hash('Gor103Dmas@', PASSWORD_DEFAULT)
        ];
        file_put_contents($configFile, json_encode($defaultConfig, JSON_PRETTY_PRINT));
        return $defaultConfig;
    }
    return json_decode(file_get_contents($configFile), true);
}

// Salvar configuração
function saveConfig($config) {
    file_put_contents('config.json', json_encode($config, JSON_PRETTY_PRINT));
}

$config = loadConfig();

// Verificar se é admin
function isAdmin() {
    return isset($_SESSION['admin']) && $_SESSION['admin'] === true;
}

// Função para log de erros
function logError($message, $type = 'ERROR') {
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $logMessage = "[$timestamp] [$type] [IP: $ip] $message" . PHP_EOL;
    file_put_contents('error.log', $logMessage, FILE_APPEND | LOCK_EX);
}

// --- PROCESSAMENTO DE AÇÕES (POST/GET) ---

// Login
if (isset($_POST['login'])) {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    // Usar o primeiro email autorizado como usuário principal para login
    if ($email === $config['authorizedEmails'][0] && password_verify($password, $config['adminPasswordHash'])) {
        $_SESSION['admin'] = true;
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

// API para salvar configuração da API
if (isset($_POST['action']) && $_POST['action'] === 'saveApiConfig') {
    if (!isAdmin()) { exit; }
    header('Content-Type: application/json');
    
    $config['apiToken'] = $_POST['apiToken'] ?? '';
    saveConfig($config);
    
    echo json_encode(['success' => true, 'message' => 'Token salvo com sucesso!']);
    exit;
}

// API para teste de envio de mensagem
if (isset($_POST['action']) && $_POST['action'] === 'testApiSend') {
    if (!isAdmin()) { exit; }
    header('Content-Type: application/json');
    
    $number = $_POST['number'] ?? '';
    $body = $_POST['body'] ?? '';
    $token = $config['apiToken'];

    if (empty($token)) {
        echo json_encode(['success' => false, 'message' => 'Token da API não configurado.']);
        exit;
    }

    $url = 'https://app.whapichat.com.br:443/backend/api/messages/send';
    $data = ['number' => $number, 'body' => $body, 'saveOnTicket' => true, 'linkPreview' => true];
    
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

    logError("Teste de API: Status $httpcode, Resposta: $response", 'INFO');

    echo json_encode([
        'success' => $httpcode === 200,
        'message' => "Status: $httpcode, Resposta: " . htmlspecialchars($response)
    ]);
    exit;
}

// API para carregar currículos
if (isset($_GET['action']) && $_GET['action'] === 'getCurriculos') {
    header('Content-Type: application/json');
    
    $curriculos = [];
    if (file_exists('curriculos.log')) {
        $lines = file('curriculos.log', FILE_IGNORE_NEW_LINES);
        foreach ($lines as $line) {
            if (!empty($line)) {
                $curriculos[] = json_decode($line, true);
            }
        }
        $curriculos = array_reverse($curriculos); // Mais recentes primeiro
    }
    
    echo json_encode([
        'success' => true,
        'curriculos' => $curriculos
    ]);
    exit;
}

// API para carregar logs de erro
if (isset($_GET['action']) && $_GET['action'] === 'getLogs') {
    header('Content-Type: application/json');
    
    $logs = [];
    if (file_exists('error.log')) {
        $lines = file('error.log', FILE_IGNORE_NEW_LINES);
        $logs = array_reverse(array_slice($lines, -100)); // Últimos 100 logs
    }
    
    echo json_encode([
        'success' => true,
        'logs' => $logs
    ]);
    exit;
}

// API para limpar logs
if (isset($_POST['action']) && $_POST['action'] === 'clearLogs') {
    header('Content-Type: application/json');
    
    if (file_exists('error.log')) {
        file_put_contents('error.log', '');
        logError("Logs limpos pelo administrador", 'INFO');
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Logs limpos com sucesso!'
    ]);
    exit;
}

// Verificar se está logado
if (!isAdmin()) {
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
                    <div class="error-message"><?php echo $error; ?></div>
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

// Carregar currículos do log
$curriculos = [];
if (file_exists('curriculos.log')) {
    $lines = file('curriculos.log', FILE_IGNORE_NEW_LINES);
    foreach ($lines as $line) {
        if (!empty($line)) {
            $curriculos[] = json_decode($line, true);
        }
    }
    $curriculos = array_reverse($curriculos); // Mais recentes primeiro
}

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
        .tabs {
            display: flex;
            border-bottom: 2px solid #e5e7eb;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .tab {
            padding: 12px 20px;
            background: #f9fafb;
            border: none;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }
        .tab.active {
            background: white;
            border-bottom-color: #1e40af;
            color: #1e40af;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .log-container {
            background: #1a1a1a;
            color: #00ff00;
            padding: 15px;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 0.85rem;
            max-height: 400px;
            overflow-y: auto;
        }
        .log-line {
            margin-bottom: 5px;
            word-wrap: break-word;
        }
        .log-error {
            color: #ff6b6b;
        }
        .log-success {
            color: #51cf66;
        }
        .log-warning {
            color: #ffd43b;
        }
        .log-info {
            color: #74c0fc;
        }
        .tools-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .tool-card {
            background: white;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
        }
        .tool-card:hover {
            border-color: #1e40af;
            transform: translateY(-2px);
        }
        .tool-icon {
            font-size: 2rem;
            color: #1e40af;
            margin-bottom: 15px;
        }
        .tool-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 10px;
        }
        .tool-description {
            color: #6b7280;
            font-size: 0.9rem;
            margin-bottom: 15px;
        }
        .config-note {
            background: #e0f2fe;
            border: 1px solid #0288d1;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .config-note h4 {
            color: #01579b;
            margin-bottom: 10px;
        }
        .config-note ul {
            margin-left: 20px;
            color: #374151;
        }
        .config-note li {
            margin-bottom: 5px;
        }
        .hosting-info {
            background: #f0f9ff;
            border: 1px solid #0ea5e9;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .hosting-info h4 {
            color: #0369a1;
            margin-bottom: 15px;
        }
        .email-examples {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        .email-example {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 10px;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <header class="header">
            <div class="header-content">
                <div class="logo">
                    <i class="fas fa-building"></i>
                    <div>
                        <h1>GOR INFORMÁTICA</h1>
                        <p>Painel Administrativo</p>
                    </div>
                </div>
                <a href="?logout=1" class="btn-secondary">
                    <i class="fas fa-sign-out-alt"></i>
                    Sair
                </a>
            </div>
        </header>

        <div class="admin-panel" style="display: block;">
            <div class="panel-content">
                <div class="stats-card">
                    <div class="stat">
                        <h3>Currículos Recebidos</h3>
                        <p class="stat-number"><?php echo count($curriculos); ?></p>
                    </div>
                    <i class="fas fa-users"></i>
                </div>

                <div class="hosting-info" style="border-color: #7c3aed; background: #f5f3ff;">
                    <h4 style="color: #6d28d9;">🚀 Sistema Configurado para API WhapiChat</h4>
                    <p>O sistema agora envia notificações de novos currículos diretamente para o seu WhatsApp através da API WhapiChat.</p>
                </div>

                <div class="tabs">
                    <button class="tab active" onclick="showTab('curriculos')">
                        <i class="fas fa-list"></i> Currículos
                    </button>
                    <button class="tab" onclick="showTab('config')">
                        <i class="fas fa-cog"></i> Configuração da API
                    </button>
                    <button class="tab" onclick="showTab('tools')">
                        <i class="fas fa-tools"></i> Testes da API
                    </button>
>>>>>>>
                    <button class="tab" onclick="showTab('logs')">
                        <i class="fas fa-file-alt"></i> Logs do Sistema
                    </button>
                </div>
                    <button class="tab" onclick="showTab('logs')">
                        <i class="fas fa-file-alt"></i> Logs do Sistema
                    </button>
                </div>

                <!-- Tab Currículos -->
                <div id="curriculos-tab" class="tab-content active">
                    <div class="form-section">
                        <h3><i class="fas fa-list"></i> Currículos Cadastrados</h3>
                        
                        <?php if (empty($curriculos)): ?>
                            <p>Nenhum currículo cadastrado ainda.</p>
                        <?php else: ?>
                            <table class="curriculos-table">
                                <thead>
                                    <tr>
                                        <th>Data/Hora</th>
                                        <th>Nome</th>
                                        <th>Telefone</th>
                                        <th>Email</th>
                                        <th>Cidade</th>
                                        <th>Status</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($curriculos as $index => $curriculo): ?>
                                        <tr>
                                            <td><?php echo date('d/m/Y H:i', strtotime($curriculo['timestamp'])); ?></td>
                                            <td><?php echo htmlspecialchars($curriculo['data']['name']); ?></td>
                                            <td><?php echo htmlspecialchars($curriculo['data']['phone']); ?></td>
                                            <td><?php echo htmlspecialchars($curriculo['data']['email'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($curriculo['data']['city']); ?></td>
                                            <td><span class="status-badge status-new">Novo</span></td>
                                            <td>
                                                <button onclick="viewCurriculo(<?php echo $index; ?>)" class="btn-primary btn-small">
                                                    <i class="fas fa-eye"></i> Ver
                                                </button>
                                                <?php if (isset($curriculo['files']['resume'])): ?>
                                                    <a href="uploads/<?php echo $curriculo['files']['resume']; ?>" target="_blank" class="btn-secondary btn-small">
                                                        <i class="fas fa-file-pdf"></i> PDF
                                                    </a>
                                                <?php endif; ?>
                                                <?php if (isset($curriculo['files']['photo'])): ?>
                                                    <a href="uploads/<?php echo $curriculo['files']['photo']; ?>" target="_blank" class="btn-secondary btn-small">
                                                        <i class="fas fa-image"></i> Foto
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Tab Configurações -->
                <div id="config-tab" class="tab-content">
                    <div class="config-note">
                        <h4><i class="fas fa-key"></i> Configuração da API WhapiChat</h4>
                        <p>Insira o token da sua conexão WhapiChat para habilitar o envio de mensagens.</p>
                        <ul>
                            <li>Acesse o menu "Conexões" no seu painel WhapiChat.</li>
                            <li>Clique no botão "Editar" da conexão desejada.</li>
                            <li>Copie o token e cole no campo abaixo.</li>
                        </ul>
                    </div>
                    
                    <form id="apiConfigForm" class="config-form">
                        <h3><i class="fas fa-key"></i> Token da API</h3>
                        
                        <div class="form-group">
                            <label>Token "Bearer"</label>
                            <input type="text" id="apiToken" value="<?php echo htmlspecialchars($config['apiToken']); ?>" style="width: 100%;">
                            <small>Este token é secreto e usado para autenticar suas requisições.</small>
                        </div>
                        
                        <button type="submit" class="btn-primary">
                            <i class="fas fa-save"></i>
                            Salvar Token
                        </button>
                        
                        <div id="configSuccess" class="success-message" style="display: none;"></div>
                    </form>
                </div>

                <!-- Tab Ferramentas de Teste da API -->
                <div id="tools-tab" class="tab-content">
                    <div class="form-section">
                        <h3><i class="fas fa-vial"></i> Testar API WhapiChat</h3>
                        <p>Use esta ferramenta para verificar se o seu token está funcionando corretamente.</p>
                        
                        <form id="apiTestForm" class="config-form">
                            <h4>Teste de Mensagem de Texto</h4>
                            <div class="form-group">
                                <label>Número (com código do país, ex: 5585...)</label>
                                <input type="text" id="testNumber" placeholder="5585999999999" required>
                            </div>
                            <div class="form-group">
                                <label>Mensagem</label>
                                <textarea id="testBody" rows="3" required>Olá! Isto é uma mensagem de teste do sistema de currículos.</textarea>
                            </div>
                            <button type="submit" class="btn-primary">
                                <i class="fas fa-paper-plane"></i> Enviar Teste
                            </button>
                        </form>
                        
                        <div id="testResult" class="success-message" style="display: none; margin-top: 15px; white-space: pre-wrap;"></div>

                        <div class="hosting-info" style="margin-top: 30px;">
                            <h4>📋 Informações do Servidor</h4>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                <div><strong>PHP Version:</strong> <?php echo phpversion(); ?></div>
                                <div><strong>cURL:</strong> <?php echo extension_loaded('curl') ? '✅ Disponível' : '❌ Não disponível'; ?></div>
                                <div><strong>OpenSSL:</strong> <?php echo extension_loaded('openssl') ? '✅ Disponível' : '❌ Não disponível'; ?></div>
                                <div><strong>Sistema:</strong> <?php echo php_uname('s'); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
>>>>>>>

                <!-- Tab Logs -->
                <div id="logs-tab" class="tab-content">
                    <div class="form-section">
                        <h3><i class="fas fa-file-alt"></i> Logs do Sistema</h3>
                        <div style="margin-bottom: 15px;">
                            <button onclick="loadLogs()" class="btn-secondary">
                                <i class="fas fa-refresh"></i> Atualizar Logs
                            </button>
                            <button onclick="clearLogs()" class="btn-remove">
                                <i class="fas fa-trash"></i> Limpar Logs
                            </button>
                        </div>
                        <div id="logsContainer" class="log-container">
                            <div class="log-line">Carregando logs...</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para visualizar currículo -->
    <div id="curriculoModal" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <span class="close">&times;</span>
            <div id="curriculoContent"></div>
        </div>
    </div>

    <script>
        const curriculos = <?php echo json_encode($curriculos); ?>;
        
        function showTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');
            event.target.classList.add('active');
            
            // Load logs if logs tab is selected
            if (tabName === 'logs') {
                loadLogs();
            }
        }
        
        function showServerInfo() {
            const info = document.getElementById('serverInfo');
            info.style.display = info.style.display === 'none' ? 'block' : 'none';
        }
        
        function loadLogs() {
            fetch('admin.php?action=getLogs')
                .then(response => response.json())
                .then(data => {
                    const container = document.getElementById('logsContainer');
                    if (data.success && data.logs.length > 0) {
                        container.innerHTML = data.logs.map(log => {
                            let className = 'log-line';
                            if (log.includes('[ERROR]')) className += ' log-error';
                            else if (log.includes('[SUCCESS]')) className += ' log-success';
                            else if (log.includes('[WARNING]')) className += ' log-warning';
                            else if (log.includes('[INFO]')) className += ' log-info';
                            
                            return `<div class="${className}">${log}</div>`;
                        }).join('');
                    } else {
                        container.innerHTML = '<div class="log-line">Nenhum log encontrado.</div>';
                    }
                    container.scrollTop = container.scrollHeight;
                })
                .catch(error => {
                    document.getElementById('logsContainer').innerHTML = '<div class="log-line log-error">Erro ao carregar logs.</div>';
                });
        }
        
        function clearLogs() {
            if (confirm('Tem certeza que deseja limpar todos os logs?')) {
                const formData = new FormData();
                formData.append('action', 'clearLogs');
                
                fetch('admin.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        loadLogs();
                        alert('Logs limpos com sucesso!');
                    } else {
                        alert('Erro ao limpar logs');
                    }
                })
                .catch(error => {
                    alert('Erro ao limpar logs');
                });
            }
        }
        
        function viewCurriculo(index) {
            const curriculo = curriculos[index];
            const data = curriculo.data;
            
            let content = `
                <h2>Currículo de ${data.name}</h2>
                <div style="padding: 20px;">
                    <h3>📋 Dados Pessoais</h3>
                    <p><strong>Nome:</strong> ${data.name}</p>
                    <p><strong>Data de Nascimento:</strong> ${data.birthDate}</p>
                    <p><strong>Estado Civil:</strong> ${data.maritalStatus}</p>
                    <p><strong>Telefone:</strong> ${data.phone}</p>
                    <p><strong>WhatsApp:</strong> ${data.isWhatsapp || 'N/A'}</p>
                    <p><strong>Email:</strong> ${data.email || 'N/A'}</p>
                    
                    <h3>🏠 Endereço</h3>
                    <p><strong>Endereço:</strong> ${data.address}</p>
                    <p><strong>Cidade:</strong> ${data.city}</p>
                    <p><strong>Estado:</strong> ${data.state}</p>
                    
                    <h3>🎓 Formação</h3>
                    <p><strong>Escolaridade:</strong> ${data.education}</p>
                    <p><strong>Estudando:</strong> ${data.isStudying}</p>
                    ${data.studyPeriod ? `<p><strong>Período:</strong> ${data.studyPeriod}</p>` : ''}
                    <p><strong>Possui Cursos:</strong> ${data.hasCourses}</p>
                    ${data.courses ? `<p><strong>Cursos:</strong> ${data.courses}</p>` : ''}
                    
                    <h3>💼 Experiência</h3>
                    <p><strong>Possui Experiência:</strong> ${data.hasExperience}</p>
                    ${data.company1 ? `
                        <p><strong>Empresa:</strong> ${data.company1}</p>
                        <p><strong>Cargo:</strong> ${data.position1}</p>
                        <p><strong>Tempo:</strong> ${data.duration1}</p>
                    ` : ''}
                    
                    <h3>🎯 Objetivo</h3>
                    <p><strong>Motivação:</strong> ${data.motivation}</p>
                    
                    <h3>📎 Arquivos</h3>
                    ${curriculo.files.resume ? `<p><a href="uploads/${curriculo.files.resume}" target="_blank">📄 Currículo PDF</a></p>` : ''}
                    ${curriculo.files.photo ? `<p><a href="uploads/${curriculo.files.photo}" target="_blank">📸 Foto</a></p>` : ''}
                </div>
            `;
            
            document.getElementById('curriculoContent').innerHTML = content;
            document.getElementById('curriculoModal').style.display = 'block';
        }
        
        // Fechar modal
        document.querySelector('.close').addEventListener('click', function() {
            document.getElementById('curriculoModal').style.display = 'none';
        });
        
        window.addEventListener('click', function(e) {
            const modal = document.getElementById('curriculoModal');
            if (e.target === modal) {
                modal.style.display = 'none';
            }
        });

        // API Config form
        document.getElementById('apiConfigForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const token = document.getElementById('apiToken').value;
            const successDiv = document.getElementById('configSuccess');
            
            const formData = new FormData();
            formData.append('action', 'saveApiConfig');
            formData.append('apiToken', token);

            fetch('admin.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    successDiv.textContent = data.message;
                    successDiv.style.display = 'block';
                    setTimeout(() => { successDiv.style.display = 'none'; }, 3000);
                })
                .catch(err => {
                    successDiv.textContent = 'Erro ao salvar.';
                    successDiv.style.display = 'block';
                });
        });

        // API Test form
        document.getElementById('apiTestForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const number = document.getElementById('testNumber').value;
            const body = document.getElementById('testBody').value;
            const resultDiv = document.getElementById('testResult');
            
            const formData = new FormData();
            formData.append('action', 'testApiSend');
            formData.append('number', number);
            formData.append('body', body);

            resultDiv.textContent = 'Enviando...';
            resultDiv.style.display = 'block';

            fetch('admin.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    resultDiv.textContent = data.message;
                })
                .catch(err => {
                    resultDiv.textContent = 'Erro na requisição: ' + err;
                });
        });
    </script>
</body>
</html>

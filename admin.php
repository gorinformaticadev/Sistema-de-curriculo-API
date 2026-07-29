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

        // Filtros
        $statusFilter = $_GET['status'] ?? '';
        $searchQuery = trim($_GET['search'] ?? '');

        $sql = "SELECT id, nome, telefone, email, cidade, data_cadastro, status, notes FROM curriculos WHERE 1=1";
        $params = [];

        if ($statusFilter && $statusFilter !== 'all') {
            $sql .= " AND status = ?";
            $params[] = $statusFilter;
        }

        if ($searchQuery) {
            $sql .= " AND (nome LIKE ? OR email LIKE ? OR telefone LIKE ? OR cidade LIKE ?)";
            $searchParam = "%$searchQuery%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }

        $sql .= " ORDER BY data_cadastro DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $curriculos = $stmt->fetchAll();
        echo json_encode(['success' => true, 'curriculos' => $curriculos]);
        exit;
    }

    // API para exportar currículos em CSV
    if (isset($_GET['action']) && $_GET['action'] === 'exportCurriculosCSV') {
        $statusFilter = $_GET['status'] ?? '';
        $searchQuery = trim($_GET['search'] ?? '');

        $sql = "SELECT id, nome, telefone, email, cidade, data_cadastro, status FROM curriculos WHERE 1=1";
        $params = [];

        if ($statusFilter && $statusFilter !== 'all') {
            $sql .= " AND status = ?";
            $params[] = $statusFilter;
        }

        if ($searchQuery) {
            $sql .= " AND (nome LIKE ? OR email LIKE ? OR telefone LIKE ? OR cidade LIKE ?)";
            $searchParam = "%$searchQuery%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }

        $sql .= " ORDER BY data_cadastro DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $curriculos = $stmt->fetchAll();

        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=curriculos_' . date('Y-m-d_H-i-s') . '.csv');

        // Open output stream
        $output = fopen('php://output', 'w');

        // Add UTF-8 BOM for Excel compatibility
        fputs($output, "\xEF\xBB\xBF");

        // Header row
        fputcsv($output, ['ID', 'Nome', 'Telefone', 'Email', 'Cidade', 'Data Cadastro', 'Status'], ';');

        // Data rows
        foreach ($curriculos as $c) {
            $statusLabel = match($c['status']) {
                'pending' => 'Pendente',
                'reviewing' => 'Em Análise',
                'interview_scheduled' => 'Entrevista Agendada',
                'interview_done' => 'Entrevista Realizada',
                'approved' => 'Aprovado',
                'rejected' => 'Reprovado',
                'archived' => 'Arquivado',
                default => $c['status']
            };

            fputcsv($output, [
                $c['id'],
                $c['nome'],
                $c['telefone'],
                $c['email'] ?? '',
                $c['cidade'],
                date('d/m/Y H:i', strtotime($c['data_cadastro'])),
                $statusLabel
            ], ';');
        }

        fclose($output);
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

            // Status label mapping
            $statusLabels = [
                'pending' => 'Pendente',
                'reviewing' => 'Em Análise',
                'interview_scheduled' => 'Entrevista Agendada',
                'interview_done' => 'Entrevista Realizada',
                'approved' => 'Aprovado',
                'rejected' => 'Reprovado',
                'archived' => 'Arquivado'
            ];
            $curriculo['status_label'] = $statusLabels[$curriculo['status']] ?? $curriculo['status'];

            echo json_encode(['success' => true, 'curriculo' => $curriculo]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Currículo não encontrado.']);
        }
        exit;
    }

    // API para atualizar status do currículo
    if (isset($_POST['action']) && $_POST['action'] === 'updateCurriculoStatus') {
        header('Content-Type: application/json');

        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            echo json_encode(['success' => false, 'message' => 'Erro de validação de segurança (CSRF).']);
            exit;
        }

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $newStatus = $_POST['status'] ?? '';

        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'ID inválido.']);
            exit;
        }

        $allowedStatuses = ['pending', 'reviewing', 'interview_scheduled', 'interview_done', 'approved', 'rejected', 'archived'];
        if (!in_array($newStatus, $allowedStatuses)) {
            echo json_encode(['success' => false, 'message' => 'Status inválido.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("UPDATE curriculos SET status = ?, status_updated_at = NOW() WHERE id = ?");
            $stmt->execute([$newStatus, $id]);
            logError("Currículo ID: $id atualizado para status: $newStatus", 'INFO');
            echo json_encode(['success' => true, 'message' => 'Status atualizado com sucesso!']);
        } catch (Exception $e) {
            logError("Falha ao atualizar status do currículo ID: $id. Erro: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Erro ao atualizar status: ' . $e->getMessage()]);
        }
        exit;
    }

    // API para adicionar observação ao currículo
    if (isset($_POST['action']) && $_POST['action'] === 'addCurriculoNote') {
        header('Content-Type: application/json');

        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            echo json_encode(['success' => false, 'message' => 'Erro de validação de segurança (CSRF).']);
            exit;
        }

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $note = trim($_POST['note'] ?? '');

        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'ID inválido.']);
            exit;
        }

        if (empty($note)) {
            echo json_encode(['success' => false, 'message' => 'A observação não pode estar vazia.']);
            exit;
        }

        try {
            // Append note to existing notes with timestamp
            $stmt = $pdo->prepare("SELECT notes FROM curriculos WHERE id = ?");
            $stmt->execute([$id]);
            $existingNotes = $stmt->fetchColumn();

            $timestamp = date('d/m/Y H:i');
            $newNote = ($existingNotes ? $existingNotes . "\n\n" : '') . "[$timestamp] $note";

            $stmt = $pdo->prepare("UPDATE curriculos SET notes = ? WHERE id = ?");
            $stmt->execute([$newNote, $id]);
            logError("Observação adicionada ao currículo ID: $id", 'INFO');
            echo json_encode(['success' => true, 'message' => 'Observação adicionada com sucesso!']);
        } catch (Exception $e) {
            logError("Falha ao adicionar observação ao currículo ID: $id. Erro: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Erro ao adicionar observação: ' . $e->getMessage()]);
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
            <div class="login-form admin-login-box">
                <h2>Área Administrativa</h2>
                <?php if (isset($error)): ?>
                    <div class="error-message admin-error"><?php echo $error; ?></div>
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
        .tabs { display: flex; border-bottom: 2px solid #e5e7eb; margin-bottom: 20px; flex-wrap: wrap; gap: 5px; }
        .tab { padding: 12px 16px; background: #f9fafb; border: none; cursor: pointer; border-bottom: 3px solid transparent; transition: all 0.3s ease; font-size: 0.9rem; flex: 1; min-width: 120px; text-align: center; }
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

        /* Admin Login Box */
        .admin-login-box {
            max-width: 400px;
            margin: 60px auto;
            background: white;
            padding: 30px 20px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }

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
            padding: 20px;
        }
        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 100%;
            max-width: 800px;
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            position: relative;
            animation: fadeIn 0.3s;
            max-height: 85vh;
            overflow-y: auto;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .modal-close {
            color: #aaa;
            position: absolute;
            top: 10px;
            right: 15px;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s ease;
        }
        .modal-close:hover, .modal-close:focus { color: black; text-decoration: none; background: #f3f4f6; }
        #modalBody h3 { border-bottom: 2px solid #1e40af; padding-bottom: 5px; margin-top: 20px; color: #1e40af; font-size: 1.1rem; }
        #modalBody p { margin: 5px 0 15px; line-height: 1.6; }
        #modalBody strong { display: inline-block; min-width: 140px; color: #333; }
        .modal-files a { display: inline-block; margin-right: 15px; text-decoration: none; background: #e0f2fe; color: #0c4a6e; padding: 8px 12px; border-radius: 6px; transition: background 0.3s; font-size: 0.9rem; }
        .modal-files a:hover { background: #bae6fd; }
        .experience-block {
            border-left: 3px solid #e5e7eb;
            padding-left: 15px;
            margin-bottom: 15px;
        }
        .experience-block h4 {
            margin-top: 0;
            font-size: 1rem;
        }

        /* Estilos do Modal de Imagem (Lightbox) */
        .modal-content-image {
            margin: auto;
            display: block;
            max-width: 95%;
            max-height: 85vh;
            animation: zoomIn 0.3s;
        }
        .image-modal-close {
            position: absolute;
            top: 10px;
            right: 20px;
            color: #f1f1f1;
            font-size: 36px;
            font-weight: bold;
            transition: 0.3s;
            cursor: pointer;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
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
            width: 95%;
            height: 90vh;
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
            right: 10px;
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            z-index: 10; /* Para ficar sobre o iframe */
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
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
                    <button class="tab" onclick="showTab('updates')"><i class="fas fa-sync-alt"></i> Atualizações</button>
                </div>

                <!-- Tab Currículos -->
                <div id="curriculos-tab" class="tab-content active">
                    <h3><i class="fas fa-list"></i> Currículos Cadastrados</h3>

                    <!-- Filtros e Ações -->
                    <div class="curriculos-filters">
                        <div class="filter-group">
                            <label for="statusFilter">Filtrar por Status:</label>
                            <select id="statusFilter" onchange="loadCurriculos()">
                                <option value="all">Todos</option>
                                <option value="pending">Pendente</option>
                                <option value="reviewing">Em Análise</option>
                                <option value="interview_scheduled">Entrevista Agendada</option>
                                <option value="interview_done">Entrevista Realizada</option>
                                <option value="approved">Aprovado</option>
                                <option value="rejected">Reprovado</option>
                                <option value="archived">Arquivado</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="searchInput">Buscar:</label>
                            <input type="text" id="searchInput" placeholder="Nome, email, telefone ou cidade..." oninput="loadCurriculos()">
                        </div>
                        <div class="filter-actions">
                            <button onclick="exportCSV()" class="btn-secondary btn-small">
                                <i class="fas fa-download"></i> Exportar CSV
                            </button>
                        </div>
                    </div>

                    <!-- Ações em Massa -->
                    <div id="bulkActions" class="bulk-actions" style="display: none;">
                        <span id="selectedCount">0 selecionado(s)</span>
                        <div class="bulk-buttons">
                            <select id="bulkStatusSelect" class="bulk-status-select">
                                <option value="">Alterar Status...</option>
                                <option value="pending">Pendente</option>
                                <option value="reviewing">Em Análise</option>
                                <option value="interview_scheduled">Entrevista Agendada</option>
                                <option value="interview_done">Entrevista Realizada</option>
                                <option value="approved">Aprovado</option>
                                <option value="rejected">Reprovado</option>
                                <option value="archived">Arquivado</option>
                            </select>
                            <button onclick="bulkUpdateStatus()" class="btn-primary btn-small">Aplicar</button>
                            <button onclick="bulkDelete()" class="btn-remove btn-small">Deletar Selecionados</button>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="curriculos-table">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="selectAll" onchange="toggleSelectAll()"></th>
                                    <th>Data/Hora</th>
                                    <th>Nome</th>
                                    <th>Telefone</th>
                                    <th>Email</th>
                                    <th>Cidade</th>
                                    <th>Status</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody id="curriculos-tbody">
                                <!-- Conteúdo carregado via JS -->
                            </tbody>
                        </table>
                    </div>
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

                    <hr class="admin-hr">

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
                        <div id="testResult" class="success-message admin-test-result"></div>
                    </form>
                </div>

                <!-- Tab Atualizações -->
                <div id="updates-tab" class="tab-content">
                    <div class="form-section">
                        <h3><i class="fas fa-sync-alt"></i> Atualizar Sistema</h3>

                        <div class="update-info">
                            <p><i class="fas fa-info-circle"></i> Use esta ferramenta para atualizar o sistema enviando um arquivo ZIP contendo os novos arquivos.</p>
                            <p><strong>Importante:</strong> O sistema fará um backup automático antes de atualizar. Em caso de erro, o rollback será automático.</p>
                        </div>

                        <form id="updateForm" class="update-form">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                            <div class="form-group">
                                <label class="required">Arquivo de Atualização (ZIP)</label>
                                <div class="file-upload-area" id="dropZone">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    <p>Arraste o arquivo ZIP aqui ou clique para selecionar</p>
                                    <input type="file" id="updateZip" name="update_zip" accept=".zip" required>
                                </div>
                                <small>O arquivo ZIP deve conter os arquivos atualizados do sistema. Tamanho máximo: 50MB.</small>
                            </div>

                            <div class="form-group">
                                <label>Diretório de Backup (opcional)</label>
                                <input type="text" id="backupDir" name="backup_dir" placeholder="Deixe em branco para usar o padrão (backups/update_YYYY-MM-DD_HH-MM-SS)">
                            </div>

                            <button type="button" id="validateBtn" class="btn-primary">
                                <i class="fas fa-check-circle"></i> Validar Arquivo
                            </button>
                            <button type="button" id="updateBtn" class="btn-submit" style="display: none;">
                                <i class="fas fa-sync-alt"></i> Confirmar e Atualizar
                            </button>
                        </form>

                        <div id="validationResult" class="update-result" style="display: none;"></div>
                        <div id="updateProgress" class="update-progress" style="display: none;"></div>
                    </div>
                </div>

                <!-- Tab Logs -->
                <div id="logs-tab" class="tab-content">
                    <div class="form-section">
                        <h3><i class="fas fa-file-alt"></i> Logs do Sistema</h3>
                        <div class="admin-button-group">
                            <button onclick="loadLogs()" class="btn-secondary">
                                <i class="fas fa-sync-alt"></i> Atualizar Logs
                            </button>
                            <button onclick="clearLogs()" class="btn-remove">
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
            }
        }

        // Carregar currículos via fetch
        function loadCurriculos() {
            const statusFilter = document.getElementById('statusFilter').value;
            const searchQuery = document.getElementById('searchInput').value;

            let url = `admin.php?action=getCurriculos&status=${encodeURIComponent(statusFilter)}&search=${encodeURIComponent(searchQuery)}`;

            fetch(url)
                .then(res => res.json())
                .then(data => {
                    const tbody = document.getElementById('curriculos-tbody');
                    if (data.success && data.curriculos.length > 0) {
                        tbody.innerHTML = data.curriculos.map(c => {
                            const statusClass = `status-${c.status}`;
                            const statusLabel = {
                                'pending': 'Pendente',
                                'reviewing': 'Em Análise',
                                'interview_scheduled': 'Entrevista Agendada',
                                'interview_done': 'Entrevista Realizada',
                                'approved': 'Aprovado',
                                'rejected': 'Reprovado',
                                'archived': 'Arquivado'
                            }[c.status] || c.status;

                            return `
                                <tr>
                                    <td><input type="checkbox" class="curriculo-checkbox" value="${c.id}" onchange="updateBulkActions()"></td>
                                    <td>${new Date(c.data_cadastro).toLocaleString('pt-BR')}</td>
                                    <td>${c.nome}</td>
                                    <td>${c.telefone}</td>
                                    <td>${c.email || 'N/A'}</td>
                                    <td>${c.cidade}</td>
                                    <td><span class="status-badge ${statusClass}">${statusLabel}</span></td>
                                    <td>
                                        <button class="btn-primary btn-small" onclick="viewCurriculo(${c.id})" title="Ver"><i class="fas fa-eye"></i></button>
                                        <button class="btn-secondary btn-small" onclick="quickStatusChange(${c.id})" title="Alterar Status"><i class="fas fa-exchange-alt"></i></button>
                                        <button class="btn-remove btn-small" onclick="deleteCurriculo(${c.id}, this)" title="Deletar"><i class="fas fa-trash"></i></button>
                                    </td>
                                </tr>
                            `;
                        }).join('');
                    } else {
                        tbody.innerHTML = '<tr><td colspan="8">Nenhum currículo encontrado.</td></tr>';
                    }
                });
        }

        // Selecionar/Deselecionar todos
        function toggleSelectAll() {
            const selectAllCheckbox = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.curriculo-checkbox');
            checkboxes.forEach(cb => cb.checked = selectAllCheckbox.checked);
            updateBulkActions();
        }

        // Atualizar ações em massa
        function updateBulkActions() {
            const checkboxes = document.querySelectorAll('.curriculo-checkbox:checked');
            const bulkActions = document.getElementById('bulkActions');
            const selectedCount = document.getElementById('selectedCount');

            if (checkboxes.length > 0) {
                bulkActions.style.display = 'flex';
                selectedCount.textContent = `${checkboxes.length} selecionado(s)`;
            } else {
                bulkActions.style.display = 'none';
            }
        }

        // Alterar status em massa
        function bulkUpdateStatus() {
            const statusSelect = document.getElementById('bulkStatusSelect');
            const newStatus = statusSelect.value;

            if (!newStatus) {
                alert('Selecione um status.');
                return;
            }

            const checkboxes = document.querySelectorAll('.curriculo-checkbox:checked');
            if (checkboxes.length === 0) {
                alert('Selecione pelo menos um currículo.');
                return;
            }

            if (!confirm(`Deseja alterar o status de ${checkboxes.length} currículo(s) para "${statusSelect.options[statusSelect.selectedIndex].text}"?`)) {
                return;
            }

            const csrfToken = document.querySelector('input[name="csrf_token"]').value;

            const promises = Array.from(checkboxes).map(cb => {
                const formData = new FormData();
                formData.append('action', 'updateCurriculoStatus');
                formData.append('id', cb.value);
                formData.append('status', newStatus);
                formData.append('csrf_token', csrfToken);

                return fetch('admin.php', { method: 'POST', body: formData })
                    .then(res => res.json());
            });

            Promise.all(promises)
                .then(results => {
                    const successCount = results.filter(r => r.success).length;
                    alert(`${successCount} de ${checkboxes.length} currículo(s) atualizado(s) com sucesso!`);
                    statusSelect.value = '';
                    document.getElementById('selectAll').checked = false;
                    updateBulkActions();
                    loadCurriculos();
                })
                .catch(err => alert('Erro ao atualizar status: ' + err));
        }

        // Deletar em massa
        function bulkDelete() {
            const checkboxes = document.querySelectorAll('.curriculo-checkbox:checked');
            if (checkboxes.length === 0) {
                alert('Selecione pelo menos um currículo.');
                return;
            }

            if (!confirm(`Deseja deletar ${checkboxes.length} currículo(s)? Esta ação não pode ser desfeita.`)) {
                return;
            }

            const csrfToken = document.querySelector('input[name="csrf_token"]').value;

            const promises = Array.from(checkboxes).map(cb => {
                const formData = new FormData();
                formData.append('action', 'deleteCurriculo');
                formData.append('id', cb.value);
                formData.append('csrf_token', csrfToken);

                return fetch('admin.php', { method: 'POST', body: formData })
                    .then(res => res.json());
            });

            Promise.all(promises)
                .then(results => {
                    const successCount = results.filter(r => r.success).length;
                    alert(`${successCount} de ${checkboxes.length} currículo(s) deletado(s) com sucesso!`);
                    document.getElementById('selectAll').checked = false;
                    updateBulkActions();
                    loadCurriculos();
                })
                .catch(err => alert('Erro ao deletar currículos: ' + err));
        }

        // Alteração rápida de status
        function quickStatusChange(id) {
            const newStatus = prompt(
                'Digite o novo status:\n\n' +
                'pending - Pendente\n' +
                'reviewing - Em Análise\n' +
                'interview_scheduled - Entrevista Agendada\n' +
                'interview_done - Entrevista Realizada\n' +
                'approved - Aprovado\n' +
                'rejected - Reprovado\n' +
                'archived - Arquivado'
            );

            if (!newStatus) return;

            const allowedStatuses = ['pending', 'reviewing', 'interview_scheduled', 'interview_done', 'approved', 'rejected', 'archived'];
            if (!allowedStatuses.includes(newStatus)) {
                alert('Status inválido!');
                return;
            }

            const csrfToken = document.querySelector('input[name="csrf_token"]').value;
            const formData = new FormData();
            formData.append('action', 'updateCurriculoStatus');
            formData.append('id', id);
            formData.append('status', newStatus);
            formData.append('csrf_token', csrfToken);

            fetch('admin.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        alert('Status atualizado com sucesso!');
                        loadCurriculos();
                    } else {
                        alert('Erro: ' + data.message);
                    }
                })
                .catch(err => alert('Erro ao atualizar status: ' + err));
        }

        // Exportar CSV
        function exportCSV() {
            const statusFilter = document.getElementById('statusFilter').value;
            const searchQuery = document.getElementById('searchInput').value;
            const url = `admin.php?action=exportCurriculosCSV&status=${encodeURIComponent(statusFilter)}&search=${encodeURIComponent(searchQuery)}`;
            window.location.href = url;
        }

        // Adicionar observação
        function addNote(id) {
            const noteInput = document.getElementById('noteInput');
            const note = noteInput.value.trim();

            if (!note) {
                alert('Digite uma observação antes de enviar.');
                return;
            }

            const csrfToken = document.querySelector('input[name="csrf_token"]').value;
            const formData = new FormData();
            formData.append('action', 'addCurriculoNote');
            formData.append('id', id);
            formData.append('note', note);
            formData.append('csrf_token', csrfToken);

            fetch('admin.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        alert('Observação adicionada com sucesso!');
                        noteInput.value = '';
                        // Recarregar detalhes para mostrar a nova observação
                        viewCurriculo(id);
                    } else {
                        alert('Erro: ' + data.message);
                    }
                })
                .catch(err => alert('Erro ao adicionar observação: ' + err));
        }
        
        // ============================================
        // SISTEMA DE ATUALIZAÇÃO
        // ============================================
        let updateTempPath = null;

        // Drag and drop para upload
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('updateZip');

        if (dropZone && fileInput) {
            dropZone.addEventListener('click', () => fileInput.click());

            dropZone.addEventListener('dragover', (e) => {
                e.preventDefault();
                dropZone.classList.add('drag-over');
            });

            dropZone.addEventListener('dragleave', () => {
                dropZone.classList.remove('drag-over');
            });

            dropZone.addEventListener('drop', (e) => {
                e.preventDefault();
                dropZone.classList.remove('drag-over');
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    fileInput.files = files;
                    dropZone.querySelector('p').textContent = files[0].name;
                }
            });

            fileInput.addEventListener('change', () => {
                if (fileInput.files.length > 0) {
                    dropZone.querySelector('p').textContent = fileInput.files[0].name;
                }
            });
        }

        // Validar arquivo ZIP
        document.getElementById('validateBtn').addEventListener('click', function() {
            const fileInput = document.getElementById('updateZip');
            const validationResult = document.getElementById('validationResult');
            const updateBtn = document.getElementById('updateBtn');

            if (!fileInput.files || fileInput.files.length === 0) {
                alert('Selecione um arquivo ZIP para validar.');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'validateZip');
            formData.append('update_zip', fileInput.files[0]);
            formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

            validationResult.style.display = 'block';
            validationResult.innerHTML = '<div class="update-loading"><i class="fas fa-spinner fa-spin"></i> Validando arquivo...</div>';
            updateBtn.style.display = 'none';

            fetch('update.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        updateTempPath = data.temp_path;
                        validationResult.innerHTML = `
                            <div class="update-success">
                                <h4><i class="fas fa-check-circle"></i> ${data.message}</h4>
                                <p><strong>Arquivos encontrados:</strong> ${data.files_count}</p>
                                <p><strong>Script de atualização:</strong> ${data.has_update_script ? '✅ Sim' : '❌ Não'}</p>
                                ${data.has_update_script ? `<div class="update-script-preview"><strong>Preview do script SQL:</strong><pre>${data.update_script_preview}</pre></div>` : ''}
                                <p class="update-warning"><i class="fas fa-exclamation-triangle"></i> <strong>Atenção:</strong> Esta ação irá modificar arquivos do sistema. Certifique-se de que o ZIP contém a versão correta.</p>
                            </div>
                        `;
                        updateBtn.style.display = 'inline-flex';
                    } else {
                        validationResult.innerHTML = `<div class="update-error"><i class="fas fa-times-circle"></i> ${data.message}</div>`;
                        updateBtn.style.display = 'none';
                    }
                })
                .catch(err => {
                    validationResult.innerHTML = `<div class="update-error"><i class="fas fa-times-circle"></i> Erro na validação: ${err}</div>`;
                    updateBtn.style.display = 'none';
                });
        });

        // Executar atualização
        document.getElementById('updateBtn').addEventListener('click', function() {
            if (!updateTempPath) {
                alert('Valide o arquivo ZIP antes de atualizar.');
                return;
            }

            if (!confirm('Deseja realmente atualizar o sistema?\n\nUm backup automático será criado antes da atualização.\nEm caso de erro, o rollback será automático.')) {
                return;
            }

            const backupDir = document.getElementById('backupDir').value;
            const updateProgress = document.getElementById('updateProgress');
            const updateBtn = document.getElementById('updateBtn');
            const validateBtn = document.getElementById('validateBtn');

            updateBtn.disabled = true;
            validateBtn.disabled = true;
            updateProgress.style.display = 'block';
            updateProgress.innerHTML = '<div class="update-loading"><i class="fas fa-spinner fa-spin"></i> Atualizando sistema... Por favor, aguarde.</div>';

            const formData = new FormData();
            formData.append('action', 'executeUpdate');
            formData.append('temp_path', updateTempPath);
            formData.append('backup_dir', backupDir);
            formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

            fetch('update.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    updateProgress.innerHTML = `
                        <div class="update-result ${data.success ? 'update-success' : 'update-error'}">
                            <h4><i class="fas fa-${data.success ? 'check-circle' : 'times-circle'}"></i> ${data.message}</h4>
                            <div class="update-logs">
                                ${data.logs.map(log => `<div class="update-log-entry">${log}</div>`).join('')}
                            </div>
                            ${data.backup_path ? `<p><strong>Backup salvo em:</strong> ${data.backup_path}</p>` : ''}
                            ${data.errors && data.errors.length > 0 ? `<div class="update-errors"><strong>Erros:</strong><ul>${data.errors.map(e => `<li>${e}</li>`).join('')}</ul></div>` : ''}
                        </div>
                    `;

                    if (data.success) {
                        updateBtn.style.display = 'none';
                        validateBtn.style.display = 'none';
                    }
                })
                .catch(err => {
                    updateProgress.innerHTML = `<div class="update-error"><i class="fas fa-times-circle"></i> Erro na atualização: ${err}</div>`;
                    updateBtn.disabled = false;
                    validateBtn.disabled = false;
                });
        });

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

                        const statusClass = `status-${c.status}`;

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
                                <a href="javascript:void(0);" onclick="showPdfModal('uploads/${c.arquivo_curriculo}')"><i class="fas fa-file-pdf"></i> Ver Currículo (PDF)</a>
                                <a href="javascript:void(0);" onclick="showImageModal('uploads/${c.arquivo_foto}')"><i class="fas fa-camera"></i> Ver Foto</a>
                            </div>

                            <h3><i class="fas fa-info-circle"></i> Status do Processo</h3>
                            <p><strong>Status Atual:</strong> <span class="status-badge ${statusClass}">${c.status_label}</span></p>

                            <h3><i class="fas fa-sticky-note"></i> Observações</h3>
                            <div class="notes-section">
                                ${c.notes ? `<div class="notes-content">${c.notes.replace(/\n/g, '<br>')}</div>` : '<p class="no-notes">Nenhuma observação registrada.</p>'}
                                <div class="add-note-form">
                                    <textarea id="noteInput" placeholder="Adicionar nova observação..." rows="3"></textarea>
                                    <button onclick="addNote(${c.id})" class="btn-primary btn-small">Adicionar Observação</button>
                                </div>
                            </div>

                            <hr class="admin-modal-hr">
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

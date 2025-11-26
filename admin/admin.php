<?php
session_start();

// Incluir todas as dependências
require_once '../config/database.php';
require_once '../includes/helpers.php';
require_once '../includes/auth.php';

// Inicializar conexão com banco
$pdo = getPDO();
$config = getConfig();

// Processar autenticação se houver tentativa de login
if (isset($_POST['login'])) {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $result = processLogin($email, $password, $pdo);
    
    if ($result['success']) {
        header('Location: admin.php');
        exit;
    } else {
        $error = $result['message'];
    }
}

// Processar logout
if (isset($_GET['logout'])) {
    processLogout();
}

// Gerar token CSRF se logado e não existir
if (isAdmin() && empty($_SESSION['csrf_token'])) {
    generateCSRFToken();
}

// Processar ações da API
if (isset($_POST['action'])) {
    $action = $_POST['action'];
    
    // Incluir API específica baseada na ação
    $apiFiles = [
        'getCurriculos' => '../api/curriculos.php',
        'getCurriculoDetails' => '../api/curriculos.php',
        'deleteCurriculo' => '../api/curriculos.php',
        'searchCurriculos' => '../api/curriculos.php',
        'exportCurriculos' => '../api/curriculos.php',
        'getCurriculoStats' => '../api/curriculos.php',
        'getUsers' => '../api/usuarios.php',
        'addUser' => '../api/usuarios.php',
        'deleteUser' => '../api/usuarios.php',
        'updateCredentials' => '../api/usuarios.php',
        'getUserProfile' => '../api/usuarios.php',
        'changePassword' => '../api/usuarios.php',
        'resetUserPassword' => '../api/usuarios.php',
        'toggleUserStatus' => '../api/usuarios.php',
        'saveApiConfig' => '../api/configuracoes.php',
        'saveEmailConfig' => '../api/configuracoes.php',
        'testApiSend' => '../api/configuracoes.php',
        'testEmailConfig' => '../api/configuracoes.php',
        'resetConfig' => '../api/configuracoes.php',
        'backupConfig' => '../api/configuracoes.php',
        'clearLogs' => '../api/logs.php',
        'clearAccessLogs' => '../api/logs.php',
        'searchLogs' => '../api/logs.php',
        'exportLogs' => '../api/logs.php',
        'getLogStats' => '../api/logs.php',
        'compressOldLogs' => '../api/logs.php',
        'clearInteractions' => '../api/interacoes.php',
        'exportInteractions' => '../api/interacoes.php',
        'getDeviceAnalysis' => '../api/interacoes.php',
        'getTimeAnalysis' => '../api/interacoes.php'
    ];
    
    if (isset($apiFiles[$action]) && file_exists($apiFiles[$action])) {
        require_once $apiFiles[$action];
        
        // Mapear ações para funções
        $actionMap = [
            // Currículos
            'getCurriculos' => 'apiGetCurriculos',
            'getCurriculoDetails' => 'apiGetCurriculoDetails',
            'deleteCurriculo' => 'apiDeleteCurriculo',
            'searchCurriculos' => 'apiSearchCurriculos',
            'exportCurriculos' => 'apiExportCurriculos',
            'getCurriculoStats' => 'apiGetCurriculoStats',
            
            // Usuários
            'getUsers' => 'apiGetUsers',
            'addUser' => 'apiAddUser',
            'deleteUser' => 'apiDeleteUser',
            'updateCredentials' => 'apiUpdateCredentials',
            'getUserProfile' => 'apiGetUserProfile',
            'changePassword' => 'apiChangePassword',
            'resetUserPassword' => 'apiResetUserPassword',
            'toggleUserStatus' => 'apiToggleUserStatus',
            
            // Configurações
            'saveApiConfig' => 'apiSaveApiConfig',
            'saveEmailConfig' => 'apiSaveEmailConfig',
            'testApiSend' => 'apiTestApiConfig',
            'testEmailConfig' => 'apiTestEmailConfig',
            'resetConfig' => 'apiResetConfig',
            'backupConfig' => 'apiBackupConfig',
            
            // Logs
            'clearLogs' => 'apiClearLogs',
            'clearAccessLogs' => 'apiClearAccessLogs',
            'searchLogs' => 'apiSearchLogs',
            'exportLogs' => 'apiExportLogs',
            'getLogStats' => 'apiGetLogStats',
            'compressOldLogs' => 'apiCompressOldLogs',
            
            // Interações
            'clearInteractions' => 'apiClearInteractions',
            'exportInteractions' => 'apiExportInteractions',
            'getDeviceAnalysis' => 'apiGetDeviceAnalysis',
            'getTimeAnalysis' => 'apiGetTimeAnalysis'
        ];
        
        if (isset($actionMap[$action]) && function_exists($actionMap[$action])) {
            $actionMap[$action]($pdo, $config);
        } else {
            jsonResponse(false, 'Ação não reconhecida.');
        }
        exit;
    }
}

// Processar ações GET da API
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    // Funções GET apenas para currículos e usuários (ações de leitura)
    if (in_array($action, ['getCurriculos', 'getCurriculoDetails', 'getUsers'])) {
        if (isset($apiFiles[$action]) && file_exists($apiFiles[$action])) {
            require_once $apiFiles[$action];
            
            if (isset($actionMap[$action]) && function_exists($actionMap[$action])) {
                $actionMap[$action]($pdo, $config);
            }
            exit;
        }
    }
    
    // Funções específicas GET para outras APIs
    switch ($action) {
        case 'getLogs':
            require_once '../api/logs.php';
            apiGetLogs($pdo);
            break;
        case 'getAccessLogs':
            require_once '../api/logs.php';
            apiGetAccessLogs($pdo);
            break;
        case 'getInteractionStats':
            require_once '../api/interacoes.php';
            apiGetInteractionStats($pdo);
            break;
        case 'getAbandonmentAnalysis':
            require_once '../api/interacoes.php';
            apiGetAbandonmentAnalysis($pdo);
            break;
        case 'getInteractionSessions':
            require_once '../api/interacoes.php';
            apiGetInteractionSessions($pdo);
            break;
        case 'getSessionDetails':
            require_once '../api/interacoes.php';
            apiGetSessionDetails($pdo);
            break;
        default:
            jsonResponse(false, 'Ação GET não reconhecida.');
    }
    exit;
}

// Verificar se está logado (página de login)
if (!isAdmin()) {
    include 'login.php';
    exit;
}

// Obter estatísticas básicas
$stmt = $pdo->query("SELECT COUNT(*) as total FROM curriculos");
$totalCurriculos = $stmt->fetchColumn();

include 'dashboard.php';
?>
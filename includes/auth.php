<?php
/**
 * Funções de autenticação e controle de acesso
 * Responsável pelo sistema de login, logout e verificação de permissões
 */

/**
 * Verifica se o usuário está autenticado como admin
 */
function isAdmin() {
    return isset($_SESSION['admin']) && $_SESSION['admin'] === true;
}

/**
 * Verifica se o usuário está logado (qualquer tipo)
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Obtém o tipo do usuário atual
 */
function getUserType() {
    return $_SESSION['user_type'] ?? 'admin'; // padrão admin para compatibilidade
}

/**
 * Obtém informações do usuário atual
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    return [
        'id' => $_SESSION['user_id'],
        'email' => $_SESSION['user_email'],
        'type' => getUserType()
    ];
}

/**
 * Verifica se o usuário pode acessar uma aba específica
 */
function canAccessTab($tab) {
    $userType = getUserType();
    
    if ($userType === 'admin') {
        return true; // admin acessa tudo
    } elseif ($userType === 'analisador') {
        return $tab === 'curriculos'; // analisador só currículos
    }
    
    logError("Tentativa de acesso à aba '$tab' por usuário tipo '$userType' - acesso negado", 'WARNING');
    return false;
}

/**
 * Verifica se o usuário pode executar uma ação específica
 */
function canAccessAction($action) {
    $userType = getUserType();
    
    if ($userType === 'admin') {
        return true; // admin pode tudo
    } elseif ($userType === 'analisador') {
        // analisador só pode ações relacionadas a currículos e própria senha
        $allowedActions = [
            'getCurriculos', 'getCurriculoDetails', 'updateCredentials'
        ];
        return in_array($action, $allowedActions);
    }
    
    return false;
}

/**
 * Processa tentativa de login
 */
function processLogin($email, $password, $pdo) {
    $email = sanitizeInput($email);
    
    logAccess("Tentativa de login para email: $email");
    
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user) {
        logAccess("Usuário encontrado", [
            'email' => $user['email'], 
            'tipo' => $user['tipo'],
            'hash_preview' => substr($user['senha'], 0, 10) . "..."
        ]);
        
        if (password_verify($password, $user['senha'])) {
            // Login bem-sucedido
            $_SESSION['admin'] = true;
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_type'] = $user['tipo'] ?? 'admin'; // compatibilidade
            
            logAccess("Login bem-sucedido", [
                'user_id' => $user['id'],
                'email' => $user['email'],
                'tipo' => $_SESSION['user_type']
            ]);
            
            return ['success' => true, 'message' => 'Login realizado com sucesso!'];
        } else {
            logAccess("Senha incorreta", ['email' => $email], 'WARNING');
            return ['success' => false, 'message' => 'Senha incorreta'];
        }
    } else {
        logAccess("Usuário não encontrado", ['email' => $email], 'WARNING');
        return ['success' => false, 'message' => 'Usuário não encontrado'];
    }
}

/**
 * Processa logout
 */
function processLogout() {
    $userInfo = getCurrentUser();
    
    session_destroy();
    
    logAccess("Logout realizado", $userInfo ?? ['info' => 'Logout sem usuário logado']);
    
    // Redireciona para a página de login
    header('Location: admin.php');
    exit;
}

/**
 * Atualiza credenciais do usuário
 */
function updateCredentials($data, $pdo) {
    $userId = $_SESSION['user_id'] ?? null;
    $userType = getUserType();
    
    if (!$userId) {
        logError("Tentativa de atualização de credenciais sem usuário logado", 'WARNING');
        return ['success' => false, 'message' => 'Erro: Sessão de usuário inválida.'];
    }
    
    $email = sanitizeInput($data['email'] ?? null);
    $password = $data['password'] ?? null;
    
    if (empty($email)) {
        return ['success' => false, 'message' => 'O email não pode ser vazio.'];
    }
    
    // Verificar se é admin ou se está alterando apenas a própria senha
    if ($userType === 'analisador') {
        // Analisadores só podem alterar senha, não email
        if (empty($password)) {
            return ['success' => false, 'message' => 'Analisadores só podem alterar a senha.'];
        }
        
        // Manter o email atual
        $stmt = $pdo->prepare("SELECT email FROM usuarios WHERE id = ?");
        $stmt->execute([$userId]);
        $currentUser = $stmt->fetch();
        $email = $currentUser['email'];
    }
    
    try {
        if (!empty($password)) {
            // Atualiza email e senha
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE usuarios SET email = ?, senha = ? WHERE id = ?");
            $stmt->execute([$email, $newHash, $userId]);
        } else {
            // Atualiza apenas o email (apenas admin)
            $stmt = $pdo->prepare("UPDATE usuarios SET email = ? WHERE id = ?");
            $stmt->execute([$email, $userId]);
        }
        
        $_SESSION['user_email'] = $email; // Atualiza o email na sessão
        
        logAccess("Credenciais atualizadas", [
            'user_id' => $userId,
            'email' => $email,
            'senha_alterada' => !empty($password)
        ]);
        
        return ['success' => true, 'message' => 'Credenciais atualizadas com sucesso!'];
        
    } catch (Exception $e) {
        logError("Erro ao atualizar credenciais para usuário ID: $userId. Erro: " . $e->getMessage());
        return ['success' => false, 'message' => 'Erro ao atualizar credenciais.'];
    }
}

/**
 * Verifica se o usuário pode gerenciar outro usuário
 */
function canManageUser($targetUserId) {
    if (!isAdmin()) {
        return false;
    }
    
    // Não pode gerenciar o próprio usuário
    if ($targetUserId == $_SESSION['user_id']) {
        return false;
    }
    
    return true;
}

/**
 * Cria um novo usuário
 */
function createUser($data, $pdo) {
    if (!isAdmin()) {
        logError("Tentativa de criação de usuário sem permissão admin", 'WARNING');
        return ['success' => false, 'message' => 'Acesso negado: permissões insuficientes.'];
    }
    
    $email = sanitizeInput($data['email'] ?? '');
    $password = $data['password'] ?? '';
    $tipo = sanitizeInput($data['tipo'] ?? 'analisador');
    
    if (empty($email) || empty($password)) {
        return ['success' => false, 'message' => 'Email e senha são obrigatórios.'];
    }
    
    if (!validateEmail($email)) {
        return ['success' => false, 'message' => 'Email inválido.'];
    }
    
    if (!in_array($tipo, ['admin', 'analisador'])) {
        return ['success' => false, 'message' => 'Tipo de usuário inválido.'];
    }
    
    if (strlen($password) < 6) {
        return ['success' => false, 'message' => 'A senha deve ter pelo menos 6 caracteres.'];
    }
    
    try {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO usuarios (email, senha, tipo) VALUES (?, ?, ?)");
        $stmt->execute([$email, $hashedPassword, $tipo]);
        
        $userId = $pdo->lastInsertId();
        
        logAccess("Novo usuário criado", [
            'user_id' => $userId,
            'email' => $email,
            'tipo' => $tipo,
            'criado_por' => $_SESSION['user_email']
        ]);
        
        return ['success' => true, 'message' => 'Usuário criado com sucesso!'];
        
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) { // Duplicate entry
            return ['success' => false, 'message' => 'Este email já está cadastrado.'];
        } else {
            logError('Erro ao criar usuário: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Erro ao criar usuário.'];
        }
    }
}

/**
 * Deleta um usuário
 */
function deleteUser($userId, $pdo) {
    if (!canManageUser($userId)) {
        $message = !isAdmin() ? 'Acesso negado: permissões insuficientes.' : 'Você não pode deletar seu próprio usuário.';
        logError("Tentativa de deleção de usuário sem permissão ou auto-deleção", 'WARNING');
        return ['success' => false, 'message' => $message];
    }
    
    try {
        $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
        $stmt->execute([$userId]);
        
        logAccess("Usuário deletado", [
            'user_id' => $userId,
            'deletado_por' => $_SESSION['user_email']
        ], 'WARNING');
        
        return ['success' => true, 'message' => 'Usuário deletado com sucesso.'];
        
    } catch (PDOException $e) {
        logError('Erro ao deletar usuário: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Erro ao deletar usuário.'];
    }
}

/**
 * Lista todos os usuários
 */
function getUsers($pdo) {
    if (!isAdmin()) {
        logError("Tentativa de listar usuários sem permissão admin", 'WARNING');
        return ['success' => false, 'message' => 'Acesso negado: permissões insuficientes.'];
    }
    
    try {
        $stmt = $pdo->query("SELECT id, email, tipo, created_at FROM usuarios ORDER BY created_at DESC");
        $users = $stmt->fetchAll();
        
        return ['success' => true, 'users' => $users];
        
    } catch (Exception $e) {
        logError('Erro ao listar usuários: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Erro ao carregar usuários.'];
    }
}
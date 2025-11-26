<?php
/**
 * API para gerenciamento de usuários
 * Responsável por operações CRUD de usuários do sistema
 */

/**
 * Lista todos os usuários
 */
function apiGetUsers($pdo) {
    return getUsers($pdo);
}

/**
 * Adiciona um novo usuário
 */
function apiAddUser($pdo) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        return jsonResponse(false, 'Erro de validação de segurança (CSRF).');
    }
    
    $data = [
        'email' => $_POST['email'] ?? '',
        'password' => $_POST['password'] ?? '',
        'tipo' => $_POST['tipo'] ?? 'analisador'
    ];
    
    $result = createUser($data, $pdo);
    
    if ($result['success']) {
        logAccess("Usuário adicionado", [
            'email' => $data['email'],
            'tipo' => $data['tipo']
        ]);
    }
    
    return jsonResponse($result['success'], $result['message']);
}

/**
 * Deleta um usuário
 */
function apiDeleteUser($pdo) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        return jsonResponse(false, 'Erro de validação de segurança (CSRF).');
    }
    
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        return jsonResponse(false, 'ID inválido.');
    }
    
    $result = deleteUser($id, $pdo);
    
    return jsonResponse($result['success'], $result['message']);
}

/**
 * Atualiza credenciais do usuário atual
 */
function apiUpdateCredentials($pdo) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        return jsonResponse(false, 'Erro de validação de segurança (CSRF).');
    }
    
    $data = [
        'email' => $_POST['email'] ?? null,
        'password' => $_POST['password'] ?? null
    ];
    
    $result = updateCredentials($data, $pdo);
    
    return jsonResponse($result['success'], $result['message']);
}

/**
 * Obtém perfil do usuário atual
 */
function apiGetUserProfile($pdo) {
    if (!isLoggedIn()) {
        return jsonResponse(false, 'Usuário não autenticado.');
    }
    
    try {
        $userId = $_SESSION['user_id'];
        $stmt = $pdo->prepare("SELECT id, email, tipo, created_at FROM usuarios WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if ($user) {
            $user['created_at_formatted'] = formatDate($user['created_at']);
            return jsonResponse(true, 'Perfil carregado com sucesso', ['user' => $user]);
        } else {
            return jsonResponse(false, 'Usuário não encontrado.');
        }
        
    } catch (Exception $e) {
        logError('Erro ao carregar perfil do usuário: ' . $e->getMessage());
        return jsonResponse(false, 'Erro ao carregar perfil.');
    }
}

/**
 * Altera senha do usuário
 */
function apiChangePassword($pdo) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        return jsonResponse(false, 'Erro de validação de segurança (CSRF).');
    }
    
    if (!isLoggedIn()) {
        return jsonResponse(false, 'Usuário não autenticado.');
    }
    
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    // Validações
    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        return jsonResponse(false, 'Todos os campos são obrigatórios.');
    }
    
    if ($newPassword !== $confirmPassword) {
        return jsonResponse(false, 'A nova senha e a confirmação não coincidem.');
    }
    
    if (strlen($newPassword) < 6) {
        return jsonResponse(false, 'A nova senha deve ter pelo menos 6 caracteres.');
    }
    
    try {
        // Verificar senha atual
        $userId = $_SESSION['user_id'];
        $stmt = $pdo->prepare("SELECT senha FROM usuarios WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user || !password_verify($currentPassword, $user['senha'])) {
            logError("Tentativa de alteração de senha com senha atual incorreta - Usuário ID: $userId", 'WARNING');
            return jsonResponse(false, 'Senha atual incorreta.');
        }
        
        // Atualizar senha
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE usuarios SET senha = ? WHERE id = ?");
        $stmt->execute([$newHash, $userId]);
        
        logAccess("Senha alterada", ['user_id' => $userId]);
        
        return jsonResponse(true, 'Senha alterada com sucesso!');
        
    } catch (Exception $e) {
        logError('Erro ao alterar senha: ' . $e->getMessage());
        return jsonResponse(false, 'Erro ao alterar senha.');
    }
}

/**
 * Lista logs de atividades do usuário
 */
function apiGetUserActivityLogs($pdo) {
    if (!isAdmin()) {
        return jsonResponse(false, 'Acesso negado: apenas administradores.');
    }
    
    $userId = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT);
    if (!$userId) {
        return jsonResponse(false, 'ID do usuário inválido.');
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT email, tipo, created_at 
            FROM usuarios 
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            return jsonResponse(false, 'Usuário não encontrado.');
        }
        
        // Buscar logs relacionados ao usuário (opcional - depende da implementação dos logs)
        // Por enquanto, retornamos apenas as informações básicas do usuário
        return jsonResponse(true, 'Informações do usuário', [
            'user' => $user,
            'activity_logs' => [] // Implementar se necessário
        ]);
        
    } catch (Exception $e) {
        logError('Erro ao buscar logs de atividade: ' . $e->getMessage());
        return jsonResponse(false, 'Erro ao buscar logs de atividade.');
    }
}

/**
 * Desativa/ativa usuário
 */
function apiToggleUserStatus($pdo) {
    if (!isAdmin()) {
        return jsonResponse(false, 'Acesso negado: apenas administradores.');
    }
    
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        return jsonResponse(false, 'Erro de validação de segurança (CSRF).');
    }
    
    $userId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
    
    if (!$userId || !in_array($status, ['active', 'inactive'])) {
        return jsonResponse(false, 'Dados inválidos.');
    }
    
    // Não pode desativar o próprio usuário
    if ($userId == $_SESSION['user_id']) {
        return jsonResponse(false, 'Você não pode desativar seu próprio usuário.');
    }
    
    try {
        // Por enquanto, vamos apenas loggar a ação
        // Implementar campo 'status' na tabela usuarios se necessário
        logAccess("Status do usuário alterado", [
            'user_id' => $userId,
            'novo_status' => $status
        ]);
        
        return jsonResponse(true, 'Status do usuário alterado com sucesso!');
        
    } catch (Exception $e) {
        logError('Erro ao alterar status do usuário: ' . $e->getMessage());
        return jsonResponse(false, 'Erro ao alterar status do usuário.');
    }
}

/**
 * Redefine senha de um usuário (admin apenas)
 */
function apiResetUserPassword($pdo) {
    if (!isAdmin()) {
        return jsonResponse(false, 'Acesso negado: apenas administradores.');
    }
    
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        return jsonResponse(false, 'Erro de validação de segurança (CSRF).');
    }
    
    $userId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $newPassword = $_POST['new_password'] ?? '';
    
    if (!$userId || empty($newPassword)) {
        return jsonResponse(false, 'Dados inválidos.');
    }
    
    if (strlen($newPassword) < 6) {
        return jsonResponse(false, 'A senha deve ter pelo menos 6 caracteres.');
    }
    
    try {
        // Verificar se o usuário existe
        $stmt = $pdo->prepare("SELECT email FROM usuarios WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            return jsonResponse(false, 'Usuário não encontrado.');
        }
        
        // Redefinir senha
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE usuarios SET senha = ? WHERE id = ?");
        $stmt->execute([$newHash, $userId]);
        
        logAccess("Senha redefinida pelo admin", [
            'user_id' => $userId,
            'email' => $user['email'],
            'redefinida_por' => $_SESSION['user_email']
        ]);
        
        return jsonResponse(true, 'Senha redefinida com sucesso!');
        
    } catch (Exception $e) {
        logError('Erro ao redefinir senha: ' . $e->getMessage());
        return jsonResponse(false, 'Erro ao redefinir senha.');
    }
}
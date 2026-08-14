<?php
/**
 * Endpoint público de verificação de duplicidade
 * Verifica se já existe currículo cadastrado com os mesmos dados
 * (nome + data de nascimento, email ou telefone).
 * Retorna apenas existência/quantidade — nenhum dado pessoal.
 */
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once 'db_connect.php';

$name = trim($_POST['name'] ?? '');
$birthDate = trim($_POST['birthDate'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = preg_replace('/\D/', '', $_POST['phone'] ?? '');

// Sem dados mínimos, não há como verificar
if (strlen($name) < 3 || empty($birthDate)) {
    echo json_encode(['success' => true, 'exists' => false, 'total' => 0, 'message' => '']);
    exit;
}

$where = [];
$params = [];

// Nome + data de nascimento (identificação principal)
$where[] = "(nome = ? AND data_nascimento = ?)";
$params[] = $name;
$params[] = $birthDate;

// Email (se informado e válido)
if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $where[] = "(email = ?)";
    $params[] = $email;
}

// Telefone (se informado)
if (!empty($phone)) {
    $where[] = "(telefone LIKE ?)";
    $params[] = '%' . $phone . '%';
}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM curriculos WHERE " . implode(' OR ', $where));
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'exists' => $total > 0,
        'total' => $total,
        'message' => $total > 0 ? 'Você já possui um currículo cadastrado.' : ''
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('[CHECK_DUPLICATE_ERROR] ' . $e->getMessage());
    echo json_encode(['success' => false, 'exists' => false, 'total' => 0, 'message' => '']);
}

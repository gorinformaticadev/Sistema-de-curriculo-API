<?php
/**
 * Endpoint para registrar interações do formulário
 */

header('Content-Type: application/json; charset=utf-8');

require_once 'db_connect.php';

// Função para detectar navegador
function detectBrowser($userAgent) {
    if (strpos($userAgent, 'Firefox') !== false) return 'Firefox';
    if (strpos($userAgent, 'Chrome') !== false && strpos($userAgent, 'Edg') === false) return 'Chrome';
    if (strpos($userAgent, 'Safari') !== false && strpos($userAgent, 'Chrome') === false) return 'Safari';
    if (strpos($userAgent, 'Edg') !== false) return 'Edge';
    if (strpos($userAgent, 'Opera') !== false || strpos($userAgent, 'OPR') !== false) return 'Opera';
    if (strpos($userAgent, 'MSIE') !== false || strpos($userAgent, 'Trident') !== false) return 'Internet Explorer';
    return 'Desconhecido';
}

// Função para detectar sistema operacional
function detectOS($userAgent) {
    if (strpos($userAgent, 'Windows NT 10.0') !== false) return 'Windows 10';
    if (strpos($userAgent, 'Windows NT 6.3') !== false) return 'Windows 8.1';
    if (strpos($userAgent, 'Windows NT 6.2') !== false) return 'Windows 8';
    if (strpos($userAgent, 'Windows NT 6.1') !== false) return 'Windows 7';
    if (strpos($userAgent, 'Windows') !== false) return 'Windows';
    if (strpos($userAgent, 'Mac OS X') !== false) return 'macOS';
    if (strpos($userAgent, 'Linux') !== false) return 'Linux';
    if (strpos($userAgent, 'Android') !== false) return 'Android';
    if (strpos($userAgent, 'iPhone') !== false || strpos($userAgent, 'iPad') !== false) return 'iOS';
    return 'Desconhecido';
}

// Função para detectar tipo de dispositivo
function detectDevice($userAgent) {
    if (strpos($userAgent, 'Mobile') !== false || strpos($userAgent, 'Android') !== false) return 'Mobile';
    if (strpos($userAgent, 'Tablet') !== false || strpos($userAgent, 'iPad') !== false) return 'Tablet';
    return 'Desktop';
}

try {
    // Obter dados do POST
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data || !isset($data['interactions'])) {
        throw new Exception('Dados inválidos');
    }

    $interactions = $data['interactions'];
    $lastField = $data['lastField'] ?? null;
    $userName = $data['userName'] ?? null;

    // Obter informações do cliente
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $browser = detectBrowser($userAgent);
    $os = detectOS($userAgent);
    $device = detectDevice($userAgent);

    // Preparar statement para inserção
    $stmt = $pdo->prepare("
        INSERT INTO form_interactions 
        (session_id, ip, user_agent, browser, os, device, nome_completo, ultimo_campo, acao, valor_campo, timestamp) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $insertedCount = 0;

    foreach ($interactions as $interaction) {
        $sessionId = $interaction['sessionId'] ?? 'unknown';
        $fieldLabel = $interaction['fieldLabel'] ?? $interaction['fieldName'] ?? 'unknown';
        $action = $interaction['action'] ?? 'unknown';
        $fieldValue = $interaction['fieldValue'] ?? '';
        $timestamp = $interaction['timestamp'] ?? date('Y-m-d H:i:s');

        // Converter timestamp ISO para MySQL datetime
        $timestamp = date('Y-m-d H:i:s', strtotime($timestamp));

        $stmt->execute([
            $sessionId,
            $ip,
            $userAgent,
            $browser,
            $os,
            $device,
            $userName,
            $fieldLabel,
            $action,
            $fieldValue,
            $timestamp
        ]);

        $insertedCount++;
    }

    echo json_encode([
        'success' => true,
        'message' => "$insertedCount interações registradas com sucesso",
        'inserted' => $insertedCount
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Erro ao registrar interações: ' . $e->getMessage()
    ]);
}
?>

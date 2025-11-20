<?php
// Log access to the form
$timestamp = date('d/m/Y H:i:s');
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

$logMessage = "[$timestamp] [ACCESS] [IP: $ip] Formulário acessado | User-Agent: $userAgent" . PHP_EOL;
file_put_contents('access.log', $logMessage, FILE_APPEND | LOCK_EX);

// Return empty response
header('Content-Type: application/json');
echo json_encode(['logged' => true]);
?>
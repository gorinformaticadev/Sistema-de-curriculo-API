<?php
// Log access to the form
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$timestamp = date('d/m/Y H:i:s');
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

$logMessage = "[$timestamp] [ACCESS] [IP: $ip] Formulário acessado | User-Agent: $userAgent" . PHP_EOL;
file_put_contents('access.log', $logMessage, FILE_APPEND | LOCK_EX);

// Return response
echo json_encode(['logged' => true]);
?>
<?php
/**
 * Endpoint interno para executar migrações pendentes do banco de dados.
 * Chamado pelo atualizador do painel admin após a instalação dos arquivos.
 *
 * SEGURANÇA: aceita apenas requisições POST originadas do próprio servidor
 * (127.0.0.1, ::1 ou o IP do servidor) — não é acessível pela internet.
 */

// Aceita apenas POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'errors' => ['Método não permitido.']]);
    exit;
}

// Aceita apenas chamadas locais (do próprio servidor)
$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
$serverAddr = $_SERVER['SERVER_ADDR'] ?? '';
$allowed = ['127.0.0.1', '::1'];
if ($serverAddr !== '') {
    $allowed[] = $serverAddr;
}
if (!in_array($remoteAddr, $allowed, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'errors' => ['Acesso negado: endpoint restrito ao próprio servidor.']]);
    exit;
}

require_once 'db_connect.php';
require_once __DIR__ . '/includes/migration.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $migration = new DatabaseMigration($pdo);
    if ($migration->hasPendingMigrations()) {
        $result = $migration->migrate();
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'success' => true,
            'applied_migrations' => [],
            'errors' => [],
            'message' => 'Nenhuma migração pendente.'
        ], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'errors' => [$e->getMessage()]], JSON_UNESCAPED_UNICODE);
}

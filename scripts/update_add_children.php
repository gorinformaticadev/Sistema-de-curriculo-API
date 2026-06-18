<?php
/**
 * Script para adicionar coluna 'possui_filhos' à tabela curriculos
 * Execute este script uma vez no servidor para aplicar a alteração
 */

require_once '../db_connect.php';

try {
    // Verificar se a coluna já existe
    $stmt = $pdo->query("SHOW COLUMNS FROM curriculos LIKE 'possui_filhos'");
    if ($stmt->rowCount() > 0) {
        echo "A coluna 'possui_filhos' já existe. Nenhuma alteração necessária.";
        exit;
    }

    // Adicionar coluna
    $pdo->exec("ALTER TABLE curriculos ADD COLUMN possui_filhos TINYINT(1) DEFAULT 0 AFTER estado_civil");
    
    echo "✅ Coluna 'possui_filhos' adicionada com sucesso à tabela curriculos!";
    
} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage();
}

<?php
/**
 * Script de Atualização do Banco de Dados
 * Adiciona a tabela de interações do formulário sem perder dados existentes
 */

require_once 'db_connect.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // Verificar se a tabela form_interactions já existe
    $stmt = $pdo->query("SHOW TABLES LIKE 'form_interactions'");
    $tableExists = $stmt->rowCount() > 0;

    if (!$tableExists) {
        // Criar tabela de interações do formulário
        $sql = "
        CREATE TABLE `form_interactions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `session_id` VARCHAR(255) NOT NULL,
            `ip` VARCHAR(45) NOT NULL,
            `user_agent` TEXT,
            `browser` VARCHAR(100),
            `os` VARCHAR(100),
            `device` VARCHAR(50),
            `nome_completo` VARCHAR(255) DEFAULT NULL,
            `ultimo_campo` VARCHAR(100),
            `acao` VARCHAR(50),
            `valor_campo` TEXT,
            `timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_session` (`session_id`),
            INDEX `idx_ip` (`ip`),
            INDEX `idx_timestamp` (`timestamp`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        $pdo->exec($sql);
        
        echo json_encode([
            'success' => true,
            'message' => 'Tabela form_interactions criada com sucesso!',
            'action' => 'created'
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'message' => 'Tabela form_interactions já existe. Nenhuma alteração necessária.',
            'action' => 'exists'
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erro ao atualizar banco de dados: ' . $e->getMessage()
    ]);
}
?>

<?php
/**
 * Script de migração para adicionar colunas de status e observações
 * à tabela curriculos em bancos de dados existentes.
 *
 * Execute este script uma vez e depois pode removê-lo ou renomeá-lo.
 */

require_once 'db_connect.php';

try {
    echo "<h2>Migração: Adicionando colunas de status e observações</h2>\n";

    // Verificar se as colunas já existem
    $stmt = $pdo->query("SHOW COLUMNS FROM curriculos LIKE 'status'");
    $statusExists = $stmt->rowCount() > 0;

    if (!$statusExists) {
        // Adicionar coluna status
        $pdo->exec("
            ALTER TABLE curriculos
            ADD COLUMN status ENUM('pending', 'reviewing', 'interview_scheduled', 'interview_done', 'approved', 'rejected', 'archived') DEFAULT 'pending'
        ");
        echo "<p style='color: green;'>✓ Coluna 'status' adicionada com sucesso.</p>\n";
    } else {
        echo "<p style='color: orange;'>⚠ Coluna 'status' já existe.</p>\n";
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM curriculos LIKE 'status_updated_at'");
    $statusUpdatedAtExists = $stmt->rowCount() > 0;

    if (!$statusUpdatedAtExists) {
        // Adicionar coluna status_updated_at
        $pdo->exec("
            ALTER TABLE curriculos
            ADD COLUMN status_updated_at TIMESTAMP NULL DEFAULT NULL
        ");
        echo "<p style='color: green;'>✓ Coluna 'status_updated_at' adicionada com sucesso.</p>\n";
    } else {
        echo "<p style='color: orange;'>⚠ Coluna 'status_updated_at' já existe.</p>\n";
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM curriculos LIKE 'notes'");
    $notesExists = $stmt->rowCount() > 0;

    if (!$notesExists) {
        // Adicionar coluna notes
        $pdo->exec("
            ALTER TABLE curriculos
            ADD COLUMN notes TEXT
        ");
        echo "<p style='color: green;'>✓ Coluna 'notes' adicionada com sucesso.</p>\n";
    } else {
        echo "<p style='color: orange;'>⚠ Coluna 'notes' já existe.</p>\n";
    }

    echo "<h3 style='color: green;'>Migração concluída com sucesso!</h3>\n";
    echo "<p>Por segurança, remova ou renomeie este arquivo após a execução.</p>\n";

} catch (PDOException $e) {
    echo "<p style='color: red;'>✗ Erro durante a migração: " . htmlspecialchars($e->getMessage()) . "</p>\n";
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Migração - Sistema de Currículos</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            margin: 0;
            padding: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .container {
            background: white;
            padding: 30px 40px;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.1);
            max-width: 600px;
            width: 100%;
        }
        h2 { color: #333; margin-bottom: 20px; }
        h3 { color: #155724; margin-top: 20px; }
        p { margin: 10px 0; line-height: 1.6; }
        a {
            display: inline-block;
            margin-top: 20px;
            text-decoration: none;
            color: #007bff;
            font-weight: 600;
        }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Resultado da Migração</h2>
        <p>Verifique as mensagens acima para confirmar o status de cada alteração.</p>
        <a href="admin.php">Voltar para o Painel Administrativo</a>
    </div>
</body>
</html>

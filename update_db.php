<?php
require_once 'db_connect.php';

try {
    $pdo->exec("ALTER TABLE curriculos MODIFY COLUMN status ENUM('pendente_novo', 'pendente', 'entrevista', 'teste', 'aprovado', 'banco_talentos', 'arquivado') DEFAULT 'pendente_novo'");
    echo "Database updated successfully.\n";
} catch (PDOException $e) {
    echo "Error updating database: " . $e->getMessage() . "\n";
}

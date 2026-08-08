<?php
require_once 'db_connect.php';
session_start();

if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    die("Acesso negado. Você precisa estar logado como administrador para atualizar o banco de dados.");
}

try {
    $pdo->exec("ALTER TABLE curriculos MODIFY COLUMN status ENUM('pendente_novo', 'pendente', 'entrevista', 'teste', 'aprovado', 'banco_talentos', 'arquivado') DEFAULT 'pendente_novo'");
    echo "Database updated successfully.\n";
} catch (PDOException $e) {
    echo "Error updating database: " . $e->getMessage() . "\n";
}

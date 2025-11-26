<?php
require_once 'db_connect.php';

try {
    $stmt = $pdo->query("DESCRIBE curriculos");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Colunas da tabela curriculos:\n";
    foreach ($columns as $column) {
        echo "- {$column['Field']}: {$column['Type']} " . ($column['Null'] === 'NO' ? 'NOT NULL' : 'NULL') . " " . ($column['Default'] ? "DEFAULT '{$column['Default']}'" : '') . "\n";
    }

    // Verificar se existem currículos
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM curriculos");
    $result = $stmt->fetch();
    echo "\nTotal de currículos: {$result['total']}\n";

} catch (Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
?>
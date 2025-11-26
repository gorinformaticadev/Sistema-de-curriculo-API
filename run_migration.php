<?php
require_once 'db_connect.php';
require_once 'includes/migration.php';

$migration = new DatabaseMigration($pdo);
$result = $migration->migrate();

echo 'Migração executada: ' . ($result['success'] ? 'Sucesso' : 'Falha') . PHP_EOL;

if (!$result['success']) {
    print_r($result['errors']);
} else {
    echo 'Versão atual: ' . $result['current_version'] . PHP_EOL;
    echo 'Migrações aplicadas: ' . count($result['applied_migrations']) . PHP_EOL;
}
?>
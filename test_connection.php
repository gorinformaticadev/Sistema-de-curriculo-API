<?php
echo "Testando conexão...\n";

try {
    $pdo = new PDO('mysql:host=localhost;dbname=curriculos', 'root', '');
    echo "✅ Conexão direta OK\n";

    $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios");
    $result = $stmt->fetch();
    echo "✅ Query OK - Usuários: {$result['total']}\n";

} catch (Exception $e) {
    echo "❌ Erro direto: " . $e->getMessage() . "\n";
}

echo "\nTestando db_connect.php...\n";

try {
    require_once 'db_connect.php';
    echo "✅ db_connect.php carregado\n";

    $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios");
    $result = $stmt->fetch();
    echo "✅ Query via db_connect OK - Usuários: {$result['total']}\n";

} catch (Exception $e) {
    echo "❌ Erro db_connect: " . $e->getMessage() . "\n";
}
?>
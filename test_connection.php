<?php
// Desativar limites de buffer para ver o resultado em tempo real
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/html; charset=utf-8');

echo "<h2>🔍 Diagnóstico de Servidor & Banco de Dados</h2>";

// 1. Testar versão do PHP e Extensões
echo "<h3>1. Ambiente PHP</h3>";
echo "<strong>Versão do PHP:</strong> " . PHP_VERSION . "<br>";
echo "<strong>PDO MySQL instalado:</strong> " . (extension_loaded('pdo_mysql') ? '✅ Sim' : '❌ Não') . "<br>";
echo "<strong>OpenSSL instalado:</strong> " . (extension_loaded('openssl') ? '✅ Sim' : '❌ Não') . "<br>";
echo "<strong>cURL instalado:</strong> " . (extension_loaded('curl') ? '✅ Sim' : '❌ Não') . "<br>";

// 2. Testar inclusão do db_connect.php
echo "<h3>2. Teste de Conexão PDO</h3>";
try {
    require_once 'db_connect.php';
    echo "✅ <strong>db_connect.php carregado com sucesso!</strong><br>";
    echo "<strong>Host:</strong> " . DB_HOST . "<br>";
    echo "<strong>Banco:</strong> " . DB_NAME . "<br>";
    echo "<strong>Usuário:</strong> " . DB_USER . "<br>";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM curriculos");
    echo "✅ <strong>Total de Currículos:</strong> " . $stmt->fetchColumn() . "<br>";

    $stmt2 = $pdo->query("SELECT COUNT(*) FROM form_interactions");
    echo "✅ <strong>Total de Interações:</strong> " . $stmt2->fetchColumn() . "<br>";

    $stmt3 = $pdo->query("SELECT COUNT(*) FROM usuarios");
    echo "✅ <strong>Total de Usuários Admin:</strong> " . $stmt3->fetchColumn() . "<br>";

} catch (Throwable $t) {
    echo "❌ <strong style='color:red;'>ERRO CRÍTICO:</strong> " . htmlspecialchars($t->getMessage()) . "<br>";
    echo "<strong>Arquivo:</strong> " . $t->getFile() . " (linha " . $t->getLine() . ")<br>";
    echo "<pre>" . htmlspecialchars($t->getTraceAsString()) . "</pre>";
}

// 3. Testar error.log
echo "<h3>3. Últimas Linhas do error.log</h3>";
if (file_exists('error.log')) {
    $lines = file('error.log');
    $lastLines = array_slice($lines, -10);
    echo "<pre style='background:#f4f4f4; padding:10px; border:1px solid #ccc;'>" . htmlspecialchars(implode("", $lastLines)) . "</pre>";
} else {
    echo "Nenhum error.log encontrado ainda.<br>";
}

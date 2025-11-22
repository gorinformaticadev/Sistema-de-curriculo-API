<?php
/**
 * Script de Teste do Sistema
 * Verifica se todas as tabelas e arquivos necessários estão presentes
 */

header('Content-Type: text/html; charset=utf-8');

echo "<!DOCTYPE html>
<html lang='pt-BR'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Teste do Sistema</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }
        .success { color: #10b981; }
        .error { color: #ef4444; }
        .warning { color: #f59e0b; }
        .test-item { padding: 10px; margin: 10px 0; border-left: 4px solid #e5e7eb; background: #f9fafb; }
        .test-item.success { border-left-color: #10b981; }
        .test-item.error { border-left-color: #ef4444; }
        .test-item.warning { border-left-color: #f59e0b; }
        h1 { color: #1f2937; }
        h2 { color: #4b5563; margin-top: 30px; }
    </style>
</head>
<body>
    <h1>🔍 Teste do Sistema de Currículos</h1>
";

$allOk = true;

// Teste 1: Verificar arquivos essenciais
echo "<h2>📁 Verificação de Arquivos</h2>";

$requiredFiles = [
    'index.html' => 'Formulário principal',
    'admin.php' => 'Painel administrativo',
    'db_connect.php' => 'Conexão com banco de dados',
    'process-simple.php' => 'Processamento do formulário',
    'log_interaction.php' => 'Log de interações',
    'form-tracker.js' => 'Sistema de rastreamento',
    'script.js' => 'Scripts do formulário',
    'styles.css' => 'Estilos'
];

foreach ($requiredFiles as $file => $description) {
    if (file_exists($file)) {
        echo "<div class='test-item success'>✅ <strong>$file</strong> - $description</div>";
    } else {
        echo "<div class='test-item error'>❌ <strong>$file</strong> - $description (FALTANDO)</div>";
        $allOk = false;
    }
}

// Teste 2: Verificar diretório de uploads
echo "<h2>📂 Verificação de Diretórios</h2>";

if (is_dir('uploads')) {
    if (is_writable('uploads')) {
        echo "<div class='test-item success'>✅ Diretório <strong>uploads/</strong> existe e tem permissão de escrita</div>";
    } else {
        echo "<div class='test-item warning'>⚠️ Diretório <strong>uploads/</strong> existe mas não tem permissão de escrita</div>";
        echo "<div class='test-item warning'>Execute: <code>chmod 755 uploads/</code></div>";
    }
} else {
    echo "<div class='test-item warning'>⚠️ Diretório <strong>uploads/</strong> não existe. Será criado automaticamente no primeiro upload.</div>";
}

// Teste 3: Verificar conexão com banco de dados
echo "<h2>🗄️ Verificação do Banco de Dados</h2>";

try {
    require_once 'db_connect.php';
    echo "<div class='test-item success'>✅ Conexão com banco de dados estabelecida</div>";
    
    // Verificar tabelas
    $requiredTables = [
        'usuarios' => 'Usuários administrativos',
        'config' => 'Configurações do sistema',
        'curriculos' => 'Currículos cadastrados',
        'form_interactions' => 'Interações do formulário (NOVO)'
    ];
    
    foreach ($requiredTables as $table => $description) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            // Contar registros
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM $table");
            $count = $stmt->fetchColumn();
            echo "<div class='test-item success'>✅ Tabela <strong>$table</strong> - $description ($count registros)</div>";
        } else {
            echo "<div class='test-item error'>❌ Tabela <strong>$table</strong> - $description (FALTANDO)</div>";
            $allOk = false;
        }
    }
    
} catch (Exception $e) {
    echo "<div class='test-item error'>❌ Erro ao conectar com banco de dados: " . htmlspecialchars($e->getMessage()) . "</div>";
    echo "<div class='test-item warning'>⚠️ Execute o instalador: <a href='install.php'>install.php</a></div>";
    $allOk = false;
}

// Teste 4: Verificar versão do PHP
echo "<h2>🐘 Verificação do PHP</h2>";

$phpVersion = phpversion();
if (version_compare($phpVersion, '7.4.0', '>=')) {
    echo "<div class='test-item success'>✅ PHP $phpVersion (compatível)</div>";
} else {
    echo "<div class='test-item error'>❌ PHP $phpVersion (requer 7.4 ou superior)</div>";
    $allOk = false;
}

// Verificar extensões necessárias
$requiredExtensions = ['pdo', 'pdo_mysql', 'curl', 'json', 'mbstring'];
foreach ($requiredExtensions as $ext) {
    if (extension_loaded($ext)) {
        echo "<div class='test-item success'>✅ Extensão <strong>$ext</strong> carregada</div>";
    } else {
        echo "<div class='test-item error'>❌ Extensão <strong>$ext</strong> não encontrada</div>";
        $allOk = false;
    }
}

// Resultado final
echo "<h2>📊 Resultado Final</h2>";

if ($allOk) {
    echo "<div class='test-item success' style='font-size: 1.2em; padding: 20px;'>
        ✅ <strong>Sistema OK!</strong> Todos os testes passaram com sucesso.
        <br><br>
        <a href='index.html' style='color: #3b82f6;'>→ Acessar Formulário</a> | 
        <a href='admin.php' style='color: #3b82f6;'>→ Acessar Painel Admin</a>
    </div>";
} else {
    echo "<div class='test-item error' style='font-size: 1.2em; padding: 20px;'>
        ❌ <strong>Problemas Encontrados!</strong> Corrija os erros acima antes de usar o sistema.
        <br><br>
        <a href='install.php' style='color: #3b82f6;'>→ Executar Instalador</a>
    </div>";
}

echo "
    <hr style='margin: 40px 0;'>
    <p style='text-align: center; color: #6b7280; font-size: 0.9em;'>
        Sistema de Currículos - GOR INFORMÁTICA<br>
        <a href='INSTALACAO.md' style='color: #3b82f6;'>📖 Ver Documentação</a>
    </p>
</body>
</html>
";
?>

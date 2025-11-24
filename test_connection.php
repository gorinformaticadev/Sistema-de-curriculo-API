<?php
/**
 * Arquivo de teste para diagnosticar problemas de conexão
 * Acesse este arquivo diretamente no navegador para verificar o status do sistema
 */

header('Content-Type: application/json; charset=utf-8');

$diagnostics = [
    'timestamp' => date('Y-m-d H:i:s'),
    'php_version' => phpversion(),
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Desconhecido',
    'tests' => []
];

// Teste 1: Verificar se o arquivo process-simple.php existe
$diagnostics['tests']['process_simple_exists'] = [
    'name' => 'Arquivo process-simple.php existe',
    'status' => file_exists('process-simple.php') ? 'OK' : 'FALHA',
    'message' => file_exists('process-simple.php') ? 'Arquivo encontrado' : 'Arquivo não encontrado'
];

// Teste 2: Verificar se o arquivo db_connect.php existe
$diagnostics['tests']['db_connect_exists'] = [
    'name' => 'Arquivo db_connect.php existe',
    'status' => file_exists('db_connect.php') ? 'OK' : 'FALHA',
    'message' => file_exists('db_connect.php') ? 'Arquivo encontrado' : 'Arquivo não encontrado'
];

// Teste 3: Verificar se a pasta uploads existe e tem permissão de escrita
$uploadDir = 'uploads/';
$diagnostics['tests']['uploads_directory'] = [
    'name' => 'Diretório uploads',
    'status' => (is_dir($uploadDir) && is_writable($uploadDir)) ? 'OK' : 'FALHA',
    'message' => is_dir($uploadDir) 
        ? (is_writable($uploadDir) ? 'Diretório existe e tem permissão de escrita' : 'Diretório existe mas não tem permissão de escrita')
        : 'Diretório não existe'
];

// Teste 4: Verificar extensões PHP necessárias
$requiredExtensions = ['pdo', 'pdo_mysql', 'curl', 'json', 'fileinfo'];
foreach ($requiredExtensions as $ext) {
    $diagnostics['tests']["extension_$ext"] = [
        'name' => "Extensão PHP: $ext",
        'status' => extension_loaded($ext) ? 'OK' : 'FALHA',
        'message' => extension_loaded($ext) ? 'Extensão carregada' : 'Extensão não encontrada'
    ];
}

// Teste 5: Tentar conectar ao banco de dados
try {
    require_once 'db_connect.php';
    $diagnostics['tests']['database_connection'] = [
        'name' => 'Conexão com banco de dados',
        'status' => 'OK',
        'message' => 'Conexão estabelecida com sucesso'
    ];
    
    // Teste 6: Verificar se a tabela curriculos existe
    $stmt = $pdo->query("SHOW TABLES LIKE 'curriculos'");
    $tableExists = $stmt->rowCount() > 0;
    $diagnostics['tests']['table_curriculos'] = [
        'name' => 'Tabela curriculos',
        'status' => $tableExists ? 'OK' : 'FALHA',
        'message' => $tableExists ? 'Tabela existe' : 'Tabela não encontrada'
    ];
    
    // Teste 7: Verificar se a tabela config existe
    $stmt = $pdo->query("SHOW TABLES LIKE 'config'");
    $configExists = $stmt->rowCount() > 0;
    $diagnostics['tests']['table_config'] = [
        'name' => 'Tabela config',
        'status' => $configExists ? 'OK' : 'FALHA',
        'message' => $configExists ? 'Tabela existe' : 'Tabela não encontrada'
    ];
    
} catch (Exception $e) {
    $diagnostics['tests']['database_connection'] = [
        'name' => 'Conexão com banco de dados',
        'status' => 'FALHA',
        'message' => 'Erro: ' . $e->getMessage()
    ];
}

// Teste 8: Verificar configurações de upload
$diagnostics['tests']['upload_settings'] = [
    'name' => 'Configurações de upload PHP',
    'status' => 'INFO',
    'message' => [
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'max_execution_time' => ini_get('max_execution_time'),
        'memory_limit' => ini_get('memory_limit')
    ]
];

// Contar testes com falha
$failedTests = 0;
$totalTests = 0;
foreach ($diagnostics['tests'] as $test) {
    if ($test['status'] !== 'INFO') {
        $totalTests++;
        if ($test['status'] === 'FALHA') {
            $failedTests++;
        }
    }
}

$diagnostics['summary'] = [
    'total_tests' => $totalTests,
    'passed' => $totalTests - $failedTests,
    'failed' => $failedTests,
    'overall_status' => $failedTests === 0 ? 'SISTEMA OK' : 'PROBLEMAS DETECTADOS'
];

echo json_encode($diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>

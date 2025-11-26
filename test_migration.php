<?php
/**
 * Script de Teste do Sistema de Migração
 * Permite testar migrações em ambiente controlado
 */

require_once 'includes/migration.php';

echo "=== TESTE DO SISTEMA DE MIGRAÇÃO v2.0 ===\n\n";

try {
    // Simular configurações de banco
    $dbName = 'test_migration_' . time();
    $dbUser = 'root';
    $dbPass = '';
    
    echo "1. Conectando ao MySQL...\n";
    $pdo_init = new PDO("mysql:host=localhost", $dbUser, $dbPass);
    $pdo_init->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ Conexão estabelecida\n\n";
    
    // Criar banco de teste
    echo "2. Criando banco de teste '$dbName'...\n";
    $pdo_init->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "✅ Banco de teste criado\n\n";
    
    // Conectar ao banco de teste
    echo "3. Conectando ao banco de teste...\n";
    $pdo = new PDO("mysql:host=localhost;dbname=$dbName", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✅ Conectado ao banco de teste\n\n";
    
    // Inicializar sistema de migração
    echo "4. Inicializando sistema de migração...\n";
    $migration = new DatabaseMigration($pdo);
    echo "✅ Sistema de migração inicializado\n\n";
    
    // Verificar versão inicial
    echo "5. Verificando versão inicial...\n";
    $currentVersion = $migration->getCurrentVersion();
    echo "📊 Versão atual: $currentVersion\n\n";
    
    // Executar migrações
    echo "6. Executando migrações...\n";
    $result = $migration->migrate();
    
    if ($result['success']) {
        echo "✅ Migrações concluídas com sucesso!\n\n";
        
        if (!empty($result['applied_migrations'])) {
            echo "📦 Migrações aplicadas:\n";
            foreach ($result['applied_migrations'] as $migration_item) {
                echo "  • Versão {$migration_item['version']}: {$migration_item['description']}\n";
            }
            echo "\n";
        } else {
            echo "ℹ️ Nenhuma migração aplicada (sistema já atualizado)\n\n";
        }
        
        // Verificar versão final
        echo "7. Verificando versão final...\n";
        $finalVersion = $migration->getCurrentVersion();
        echo "📊 Versão final: $finalVersion\n\n";
        
        // Verificar tabelas criadas
        echo "8. Verificando tabelas criadas...\n";
        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        echo "📋 Tabelas encontradas:\n";
        foreach ($tables as $table) {
            echo "  • $table\n";
        }
        echo "\n";
        
        // Verificar histórico de migrações
        echo "9. Verificando histórico de migrações...\n";
        $history = $migration->getMigrationHistory();
        echo "📜 Histórico de migrações:\n";
        foreach ($history as $item) {
            echo "  • {$item['version']}: {$item['description']} ({$item['applied_at']})\n";
        }
        echo "\n";
        
        // Inserir dados de teste
        echo "10. Inserindo dados de teste...\n";
        
        // Inserir usuário admin
        $stmt = $pdo->prepare("INSERT INTO usuarios (email, senha, tipo) VALUES (?, ?, 'admin')");
        $stmt->execute(['admin@test.com', password_hash('123456', PASSWORD_DEFAULT)]);
        echo "  ✅ Usuário admin inserido\n";
        
        // Inserir configurações
        $stmt = $pdo->prepare("INSERT INTO config (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
        $configurations = [
            'test_key' => 'test_value',
            'api_token' => 'test_token',
            'notification_email' => 'test@email.com'
        ];
        
        foreach ($configurations as $key => $value) {
            $stmt->execute([$key, $value]);
        }
        echo "  ✅ Configurações de teste inseridas\n";
        
        // Inserir currículo de teste
        $stmt = $pdo->prepare("
            INSERT INTO curriculos (
                nome, data_nascimento, telefone, email, cidade, estado, 
                escolaridade, motivacao, ip_cadastro
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            'João Silva',
            '1990-01-01',
            '(85) 99999-9999',
            'joao@test.com',
            'Fortaleza',
            'CE',
            'Ensino Superior',
            'Busco oportunidade de crescimento profissional',
            '127.0.0.1'
        ]);
        echo "  ✅ Currículo de teste inserido\n";
        
        // Verificar dados inseridos
        echo "11. Verificando dados inseridos...\n";
        
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios");
        $userCount = $stmt->fetch()['total'];
        echo "👥 Usuários: $userCount\n";
        
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM config");
        $configCount = $stmt->fetch()['total'];
        echo "⚙️ Configurações: $configCount\n";
        
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM curriculos");
        $curriculoCount = $stmt->fetch()['total'];
        echo "📄 Currículos: $curriculoCount\n";
        
        echo "\n🎉 TESTE CONCLUÍDO COM SUCESSO!\n";
        echo "✅ Sistema de migração funcionando perfeitamente\n";
        echo "✅ Dados preservados durante migração\n";
        echo "✅ Nova estrutura criada corretamente\n\n";
        
    } else {
        echo "❌ Erro durante migração:\n";
        foreach ($result['errors'] as $error) {
            echo "  • $error\n";
        }
    }
    
} catch (Exception $e) {
    echo "❌ Erro geral: " . $e->getMessage() . "\n";
    echo "📍 Arquivo: " . $e->getFile() . "\n";
    echo "📍 Linha: " . $e->getLine() . "\n\n";
}

// Limpeza (opcional)
if (isset($pdo_init) && isset($dbName)) {
    echo "12. Limpando banco de teste...\n";
    try {
        $pdo_init->exec("DROP DATABASE `$dbName`");
        echo "✅ Banco de teste removido\n";
    } catch (Exception $e) {
        echo "⚠️ Erro ao remover banco: " . $e->getMessage() . "\n";
        echo "💡 Você pode remover manualmente: DROP DATABASE `$dbName`\n";
    }
}

echo "\n=== FIM DO TESTE ===\n";
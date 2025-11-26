<?php
// update_db.php
// Script para atualizar a estrutura do banco de dados sem perda de dados

header('Content-Type: text/html; charset=utf-8');

// Incluir conexão com o banco de dados
// Nota: db_connect.php pode ter um die() se falhar, o que é aceitável aqui.
// Mas precisamos garantir que ele não tente enviar JSON se acessado diretamente pelo navegador neste contexto.
// O db_connect.php atual verifica acesso direto, mas aqui estamos incluindo-o.

// Hack para evitar que o db_connect.php encerre a execução se falhar a conexão (ele tem um exit)
// Vamos tentar incluir, mas o ideal é que o db_connect.php seja robusto.
// O db_connect.php atual usa 'exit' em caso de erro. Vamos assumir que a conexão funciona ou o usuário verá o erro do db_connect.

require_once 'db_connect.php';

echo "<h1>Atualização do Banco de Dados</h1>";
echo "<p>Iniciando verificação da estrutura do banco de dados...</p>";

try {
    // 1. Tabela 'usuarios'
    echo "<h2>Verificando tabela 'usuarios'...</h2>";
    $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL UNIQUE,
        senha VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "<p>✅ Tabela 'usuarios' verificada/criada.</p>";

    // Verificar colunas da tabela 'usuarios'
    checkAndAddColumn($pdo, 'usuarios', 'email', 'VARCHAR(255) NOT NULL UNIQUE');
    checkAndAddColumn($pdo, 'usuarios', 'senha', 'VARCHAR(255) NOT NULL');
    checkAndAddColumn($pdo, 'usuarios', 'tipo', "ENUM('admin', 'analisador') DEFAULT 'admin'");
    checkAndAddColumn($pdo, 'usuarios', 'created_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP');

    // Inserir usuário admin padrão se não existir
    $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios");
    if ($stmt->fetchColumn() == 0) {
        $email = 'admin@gorinformatica.com.br';
        $senha = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO usuarios (email, senha) VALUES (?, ?)");
        $stmt->execute([$email, $senha]);
        echo "<p>✅ Usuário admin padrão criado ($email / admin123).</p>";
    } else {
        echo "<p>ℹ️ Usuários já existem na tabela.</p>";
    }

    // 2. Tabela 'config'
    echo "<h2>Verificando tabela 'config'...</h2>";
    $pdo->exec("CREATE TABLE IF NOT EXISTS config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        chave VARCHAR(50) NOT NULL UNIQUE,
        valor TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    echo "<p>✅ Tabela 'config' verificada/criada.</p>";

    // Verificar colunas da tabela 'config'
    checkAndAddColumn($pdo, 'config', 'chave', 'VARCHAR(50) NOT NULL UNIQUE');
    checkAndAddColumn($pdo, 'config', 'valor', 'TEXT');
    checkAndAddColumn($pdo, 'config', 'updated_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

    // Inserir configurações padrão se não existirem
    $defaultConfigs = [
        'api_token' => '',
        'api_url' => 'https://app.whapichat.com.br:443/backend/api/messages/send',
        'notification_number' => '5500000000000',
        'completion_message' => 'Olá {nome}! Obrigado por se cadastrar no nosso sistema. Seu currículo foi recebido com sucesso e entraremos em contato em breve.',
        'smtp_host' => 'smtp.gmail.com',
        'smtp_port' => '587',
        'smtp_user' => '',
        'smtp_pass' => '',
        'smtp_from' => 'noreply@gorinformatica.com.br',
        'notification_email' => 'rh@gorinformatica.com.br'
    ];

    foreach ($defaultConfigs as $key => $value) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM config WHERE chave = ?");
        $stmt->execute([$key]);
        if ($stmt->fetchColumn() == 0) {
            $stmt = $pdo->prepare("INSERT INTO config (chave, valor) VALUES (?, ?)");
            $stmt->execute([$key, $value]);
            echo "<p>✅ Configuração '$key' inserida.</p>";
        }
    }

    // 3. Tabela 'curriculos'
    echo "<h2>Verificando tabela 'curriculos'...</h2>";
    $pdo->exec("CREATE TABLE IF NOT EXISTS curriculos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(255) NOT NULL,
        data_nascimento DATE NOT NULL,
        estado_civil VARCHAR(50),
        telefone VARCHAR(20) NOT NULL,
        is_whatsapp BOOLEAN DEFAULT 0,
        email VARCHAR(255),
        facebook VARCHAR(255),
        instagram VARCHAR(255),
        endereco TEXT,
        cidade VARCHAR(100),
        estado VARCHAR(2),
        escolaridade VARCHAR(100),
        estudando BOOLEAN DEFAULT 0,
        periodo_estudo VARCHAR(50),
        possui_cursos BOOLEAN DEFAULT 0,
        cursos TEXT,
        possui_experiencia BOOLEAN DEFAULT 0,
        experiencias TEXT,
        motivacao TEXT,
        arquivo_curriculo VARCHAR(255) NOT NULL,
        arquivo_foto VARCHAR(255) NOT NULL,
        aceitou_termos BOOLEAN DEFAULT 1,
        horario_vaga VARCHAR(50),
        ip_cadastro VARCHAR(45),
        data_cadastro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "<p>✅ Tabela 'curriculos' verificada/criada.</p>";

    // Verificar colunas da tabela 'curriculos'
    checkAndAddColumn($pdo, 'curriculos', 'nome', 'VARCHAR(255) NOT NULL');
    checkAndAddColumn($pdo, 'curriculos', 'data_nascimento', 'DATE NOT NULL');
    checkAndAddColumn($pdo, 'curriculos', 'estado_civil', 'VARCHAR(50)');
    checkAndAddColumn($pdo, 'curriculos', 'telefone', 'VARCHAR(20) NOT NULL');
    checkAndAddColumn($pdo, 'curriculos', 'is_whatsapp', 'BOOLEAN DEFAULT 0');
    checkAndAddColumn($pdo, 'curriculos', 'email', 'VARCHAR(255)');
    checkAndAddColumn($pdo, 'curriculos', 'facebook', 'VARCHAR(255)');
    checkAndAddColumn($pdo, 'curriculos', 'instagram', 'VARCHAR(255)');
    checkAndAddColumn($pdo, 'curriculos', 'endereco', 'TEXT');
    checkAndAddColumn($pdo, 'curriculos', 'cidade', 'VARCHAR(100)');
    checkAndAddColumn($pdo, 'curriculos', 'estado', 'VARCHAR(2)');
    checkAndAddColumn($pdo, 'curriculos', 'escolaridade', 'VARCHAR(100)');
    checkAndAddColumn($pdo, 'curriculos', 'estudando', 'BOOLEAN DEFAULT 0');
    checkAndAddColumn($pdo, 'curriculos', 'periodo_estudo', 'VARCHAR(50)');
    checkAndAddColumn($pdo, 'curriculos', 'possui_cursos', 'BOOLEAN DEFAULT 0');
    checkAndAddColumn($pdo, 'curriculos', 'cursos', 'TEXT');
    checkAndAddColumn($pdo, 'curriculos', 'possui_experiencia', 'BOOLEAN DEFAULT 0');
    checkAndAddColumn($pdo, 'curriculos', 'experiencias', 'TEXT');
    checkAndAddColumn($pdo, 'curriculos', 'motivacao', 'TEXT');
    checkAndAddColumn($pdo, 'curriculos', 'arquivo_curriculo', 'VARCHAR(255) NOT NULL');
    checkAndAddColumn($pdo, 'curriculos', 'arquivo_foto', 'VARCHAR(255) NOT NULL');
    checkAndAddColumn($pdo, 'curriculos', 'aceitou_termos', 'BOOLEAN DEFAULT 1');
    checkAndAddColumn($pdo, 'curriculos', 'horario_vaga', 'VARCHAR(50)');
    checkAndAddColumn($pdo, 'curriculos', 'ip_cadastro', 'VARCHAR(45)');
    checkAndAddColumn($pdo, 'curriculos', 'data_cadastro', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP');

    // 4. Tabela 'form_interactions'
    echo "<h2>Verificando tabela 'form_interactions'...</h2>";
    $pdo->exec("CREATE TABLE IF NOT EXISTS form_interactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id VARCHAR(255) NOT NULL,
        ip VARCHAR(45),
        user_agent TEXT,
        browser VARCHAR(50),
        os VARCHAR(50),
        device VARCHAR(50),
        nome_completo VARCHAR(255),
        ultimo_campo VARCHAR(255),
        acao VARCHAR(50),
        valor_campo TEXT,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    echo "<p>✅ Tabela 'form_interactions' verificada/criada.</p>";

    // Verificar colunas da tabela 'form_interactions'
    checkAndAddColumn($pdo, 'form_interactions', 'session_id', 'VARCHAR(255) NOT NULL');
    checkAndAddColumn($pdo, 'form_interactions', 'ip', 'VARCHAR(45)');
    checkAndAddColumn($pdo, 'form_interactions', 'user_agent', 'TEXT');
    checkAndAddColumn($pdo, 'form_interactions', 'browser', 'VARCHAR(50)');
    checkAndAddColumn($pdo, 'form_interactions', 'os', 'VARCHAR(50)');
    checkAndAddColumn($pdo, 'form_interactions', 'device', 'VARCHAR(50)');
    checkAndAddColumn($pdo, 'form_interactions', 'nome_completo', 'VARCHAR(255)');
    checkAndAddColumn($pdo, 'form_interactions', 'ultimo_campo', 'VARCHAR(255)');
    checkAndAddColumn($pdo, 'form_interactions', 'acao', 'VARCHAR(50)');
    checkAndAddColumn($pdo, 'form_interactions', 'valor_campo', 'TEXT');
    checkAndAddColumn($pdo, 'form_interactions', 'timestamp', 'DATETIME DEFAULT CURRENT_TIMESTAMP');

    echo "<hr>";
    echo "<h3>🎉 Atualização concluída com sucesso!</h3>";
    echo "<p>O banco de dados está atualizado e pronto para uso.</p>";
    echo "<p><a href='admin.php'>Ir para o Painel Administrativo</a></p>";

} catch (PDOException $e) {
    echo "<h2 style='color: red;'>❌ Erro durante a atualização:</h2>";
    echo "<p>" . $e->getMessage() . "</p>";
}

/**
 * Função auxiliar para verificar se uma coluna existe e adicioná-la se não existir
 */
function checkAndAddColumn($pdo, $table, $column, $definition) {
    try {
        // Usar information_schema é mais seguro com prepared statements do que SHOW COLUMNS
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = ? 
            AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        
        if ($stmt->fetchColumn() == 0) {
            // Coluna não existe, adicionar
            // ALTER TABLE não suporta prepared statements para nomes de coluna/tabela, 
            // mas aqui as variáveis vêm de strings hardcoded no script, então é seguro interpolar.
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            echo "<p>➕ Coluna '$column' adicionada à tabela '$table'.</p>";
        } else {
            // echo "<p>ℹ️ Coluna '$column' já existe na tabela '$table'.</p>";
        }
    } catch (PDOException $e) {
        echo "<p style='color: orange;'>⚠️ Erro ao verificar coluna '$column' em '$table': " . $e->getMessage() . "</p>";
    }
}
?>

<?php
/**
 * Instalador Inteligente com Sistema de Migração
 * Permite atualizações seguras sem perda de dados
 */

require_once 'includes/migration.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $installMode = $_POST['install_mode'] ?? 'install';
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? 'root');
    $dbPass = trim($_POST['db_pass'] ?? '');
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $adminPass = trim($_POST['admin_pass'] ?? '');

    $errors = [];
    $warnings = [];
    $success = false;
    $steps = [];
    $migrationResult = null;

    if (!$dbName) {
        $errors[] = "Nome do banco de dados é obrigatório.";
    }
    
    // Validações específicas para instalação completa
    if ($installMode === 'install') {
        if (!$adminEmail) {
            $errors[] = "Email do usuário admin é obrigatório.";
        }
        if (!$adminPass) {
            $errors[] = "Senha do usuário admin é obrigatória.";
        }
    }

    if (count($errors) === 0) {
        try {
            // Conectar ao MySQL sem especificar banco
            $pdo_init = new PDO("mysql:host=localhost", $dbUser, $dbPass);
            $pdo_init->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            if ($installMode === 'install') {
                // Verificar se banco já existe
                $stmt = $pdo_init->query("SHOW DATABASES LIKE '$dbName'");
                if ($stmt->rowCount() > 0) {
                    $warnings[] = "O banco de dados '$dbName' já existe. Em modo de instalação, será recriado.";
                }
                
                // Criar banco de dados
                $pdo_init->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
                $steps[] = "✅ Banco de dados '$dbName' verificado/criado com sucesso.";
            } else {
                // Modo atualização - verificar se banco existe
                $stmt = $pdo_init->query("SHOW DATABASES LIKE '$dbName'");
                if ($stmt->rowCount() == 0) {
                    throw new Exception("O banco de dados '$dbName' não existe. Para uma nova instalação, selecione 'Nova Instalação'.");
                }
                $steps[] = "✅ Banco de dados existente '$dbName' verificado.";
            }

            // Conectar ao banco de dados específico
            $pdo = new PDO("mysql:host=localhost;dbname=$dbName", $dbUser, $dbPass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Executar migrações se estiver em modo update
            if ($installMode === 'update') {
                $migration = new DatabaseMigration($pdo);
                $currentVersion = $migration->getCurrentVersion();
                
                $steps[] = "📊 Versão atual do banco: $currentVersion";
                $steps[] = "🔄 Iniciando processo de migração...";
                
                try {
                    $migrationResult = $migration->migrate();
                    
                    if ($migrationResult['success']) {
                        $steps[] = "✅ Migrações concluídas com sucesso!";
                        
                        if (!empty($migrationResult['applied_migrations'])) {
                            foreach ($migrationResult['applied_migrations'] as $migration) {
                                $steps[] = "  📦 Versão {$migration['version']}: {$migration['description']}";
                            }
                        } else {
                            $steps[] = "ℹ️ Sistema já está na versão mais recente.";
                        }
                    } else {
                        throw new Exception("Erro durante as migrações: " . implode(", ", $migrationResult['errors']));
                    }
                    
                } catch (Exception $e) {
                    $steps[] = "❌ Erro durante migração: " . $e->getMessage();
                    throw $e;
                }
                
            } else {
                // Modo instalação - criar estrutura inicial
                $migration = new DatabaseMigration($pdo);
                $migrationResult = $migration->migrate();
                $steps[] = "✅ Estrutura inicial do banco criada.";
            }

            // Inserir usuário admin (apenas em modo install)
            if ($installMode === 'install' && $adminEmail && $adminPass) {
                $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
                $stmt->execute([$adminEmail]);
                if ($stmt->rowCount() == 0) {
                    $senha_hash = password_hash($adminPass, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO usuarios (email, senha, tipo) VALUES (?, ?, 'admin')");
                    $stmt->execute([$adminEmail, $senha_hash]);
                    $steps[] = "👤 Usuário administrador '$adminEmail' criado com sucesso.";
                } else {
                    $steps[] = "ℹ️ Usuário administrador '$adminEmail' já existe.";
                }
            }

            // Inserir configurações padrão se não existirem
            $configs_iniciais = [
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

            $stmt_check = $pdo->prepare("SELECT id FROM config WHERE chave = ?");
            $stmt_insert = $pdo->prepare("INSERT INTO config (chave, valor) VALUES (?, ?)");
            $configs_added = 0;

            foreach ($configs_iniciais as $chave => $valor) {
                $stmt_check->execute([$chave]);
                if ($stmt_check->rowCount() == 0) {
                    $stmt_insert->execute([$chave, $valor]);
                    $configs_added++;
                }
            }
            
            if ($configs_added > 0) {
                $steps[] = "⚙️ $configs_added configuração(ões) padrão inserida(s).";
            } else {
                $steps[] = "ℹ️ Configurações padrão já existem.";
            }

            // Atualizar arquivo de conexão se existir e for gravável
            $dbConnectPath = __DIR__ . '/db_connect.php';
            if (file_exists($dbConnectPath) && is_writable($dbConnectPath)) {
                $dbConnectContent = file_get_contents($dbConnectPath);
                $updated = false;

                // Substituir configurações mantendo outras linhas intactas
                $patterns = [
                    "/define\('DB_USER',\s*'[^']*'\);/" => "define('DB_USER', '" . addslashes($dbUser) . "');",
                    "/define\('DB_PASS',\s*'[^']*'\);/" => "define('DB_PASS', '" . addslashes($dbPass) . "');",
                    "/define\('DB_NAME',\s*'[^']*'\);/" => "define('DB_NAME', '" . addslashes($dbName) . "');"
                ];

                foreach ($patterns as $pattern => $replacement) {
                    if (preg_match($pattern, $dbConnectContent)) {
                        $dbConnectContent = preg_replace($pattern, $replacement, $dbConnectContent);
                        $updated = true;
                    }
                }

                if ($updated) {
                    file_put_contents($dbConnectPath, $dbConnectContent);
                    $steps[] = "🔧 Arquivo db_connect.php atualizado com as novas configurações.";
                }
            } else {
                $steps[] = "⚠️ Arquivo db_connect.php não encontrado ou sem permissão de escrita.";
            }

            // Informações sobre o sistema atualizado
            if ($installMode === 'update' && $migrationResult) {
                $steps[] = "📋 Versão final do banco: " . $migrationResult['current_version'];
                if (!empty($migrationResult['applied_migrations'])) {
                    $steps[] = "🚀 Sistema atualizado para a versão mais recente!";
                } else {
                    $steps[] = "✅ Sistema já estava atualizado.";
                }
            }

            $success = true;

        } catch (PDOException $e) {
            $errors[] = "Erro de banco de dados: " . $e->getMessage();
            $steps[] = "❌ Falha na operação: " . $e->getMessage();
        } catch (Exception $e) {
            $errors[] = "Erro durante a operação: " . $e->getMessage();
            $steps[] = "❌ Erro: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Instalador do Sistema de Currículos v2.0</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15);
            width: 100%;
            max-width: 500px;
            padding: 40px;
            position: relative;
            overflow: hidden;
        }
        
        .container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2);
        }
        
        h1 {
            text-align: center;
            color: #2d3748;
            margin-bottom: 30px;
            font-size: 1.8rem;
            font-weight: 700;
        }
        
        .subtitle {
            text-align: center;
            color: #718096;
            margin-bottom: 30px;
            font-size: 0.95rem;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #4a5568;
            font-size: 0.9rem;
        }
        
        input[type="text"],
        input[type="email"],
        input[type="password"],
        select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: #fafafa;
        }
        
        input:focus,
        select:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .mode-selector {
            background: #f7fafc;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 2px solid #e2e8f0;
        }
        
        .mode-option {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
            cursor: pointer;
        }
        
        .mode-option:last-child {
            margin-bottom: 0;
        }
        
        .mode-option input[type="radio"] {
            margin-right: 10px;
            transform: scale(1.2);
        }
        
        .mode-option .option-content {
            flex: 1;
        }
        
        .mode-option .option-title {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 4px;
        }
        
        .mode-option .option-desc {
            font-size: 0.85rem;
            color: #718096;
        }
        
        button {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 8px;
            color: white;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
        }
        
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
        }
        
        button:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid;
        }
        
        .alert-error {
            background: #fed7d7;
            color: #c53030;
            border-left-color: #c53030;
        }
        
        .alert-success {
            background: #c6f6d5;
            color: #22543d;
            border-left-color: #38a169;
        }
        
        .alert-warning {
            background: #fefcbf;
            color: #744210;
            border-left-color: #d69e2e;
        }
        
        .steps {
            max-height: 300px;
            overflow-y: auto;
            margin: 20px 0;
        }
        
        .steps ul {
            list-style: none;
            padding: 0;
        }
        
        .steps li {
            margin-bottom: 8px;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
            font-size: 0.9rem;
        }
        
        .back-link {
            display: inline-block;
            margin-top: 15px;
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
        
        .back-link:hover {
            text-decoration: underline;
        }
        
        .version-info {
            background: #f7fafc;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.85rem;
            color: #4a5568;
            text-align: center;
        }
        
        .hidden {
            display: none;
        }
        
        .footer-note {
            text-align: center;
            margin-top: 30px;
            color: #a0aec0;
            font-size: 0.8rem;
        }
        
        .footer-note code {
            background: #f1f5f9;
            padding: 2px 6px;
            border-radius: 4px;
            color: #4a5568;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 Instalador do Sistema</h1>
        <div class="subtitle">Versão 2.0 - Com Sistema de Migração Inteligente</div>

<?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <h3>❌ Erros Encontrados:</h3>
            <ul style="margin-top: 10px;">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
            <a href="install.php" class="back-link">← Voltar</a>
        </div>
    <?php elseif ($success): ?>
        <div class="alert alert-success">
            <h2>🎉 <?php echo $installMode === 'install' ? 'Instalação' : 'Atualização'; ?> Concluída!</h2>
            <div class="steps">
                <h4>Etapas Realizadas:</h4>
                <ul>
                    <?php foreach ($steps as $step): ?>
                        <li><?php echo htmlspecialchars($step); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="version-info">
                <?php if ($migrationResult): ?>
                    <strong>Versão Final:</strong> <?php echo htmlspecialchars($migrationResult['current_version']); ?>
                    <?php if (!empty($migrationResult['applied_migrations'])): ?>
                        <br><small>🚀 Sistema atualizado com sucesso!</small>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <p><strong>Próximos passos:</strong></p>
            <ul style="margin-top: 10px; padding-left: 20px;">
                <?php if ($installMode === 'install'): ?>
                    <li>Acesse o painel administrativo em <a href="admin.php" style="color: #667eea;">admin.php</a></li>
                    <li>Configure as APIs e SMTP nas configurações</li>
                <?php else: ?>
                    <li>Acesse o painel administrativo em <a href="admin.php" style="color: #667eea;">admin.php</a></li>
                    <li>Verifique se todas as funcionalidades estão funcionando</li>
                <?php endif; ?>
                <li>Por segurança, remova ou renomeie o arquivo <code>install.php</code></li>
            </ul>
        </div>
    <?php endif; ?>
<?php else: ?>
    <form method="POST" action="install.php" id="installForm">
        <div class="version-info">
            <strong>💡 Dica:</strong> Use "Atualizar Sistema" para manter todos os dados existentes
        </div>
        
        <div class="form-group">
            <label>Modo de Instalação:</label>
            <div class="mode-selector">
                <label class="mode-option">
                    <input type="radio" name="install_mode" value="install" id="installRadio" onchange="toggleMode()" />
                    <div class="option-content">
                        <div class="option-title">🆕 Nova Instalação</div>
                        <div class="option-desc">Cria tudo do zero. Remove dados existentes!</div>
                    </div>
                </label>
                <label class="mode-option">
                    <input type="radio" name="install_mode" value="update" id="updateRadio" onchange="toggleMode()" checked />
                    <div class="option-content">
                        <div class="option-title">🔄 Atualizar Sistema</div>
                        <div class="option-desc">Mantém dados existentes. Recomendado!</div>
                    </div>
                </label>
            </div>
        </div>

        <div class="form-group">
            <label for="db_name">Nome do Banco de Dados:</label>
            <input type="text" id="db_name" name="db_name" required 
                   value="<?php echo htmlspecialchars($_POST['db_name'] ?? 'gor_informatica'); ?>" />
        </div>

        <div class="form-group">
            <label for="db_user">Usuário do Banco de Dados:</label>
            <input type="text" id="db_user" name="db_user" value="root" required />
        </div>

        <div class="form-group">
            <label for="db_pass">Senha do Banco de Dados:</label>
            <input type="password" id="db_pass" name="db_pass" />
        </div>

        <div id="adminFields">
            <div class="form-group">
                <label for="admin_email">Email do Usuário Admin:</label>
                <input type="email" id="admin_email" name="admin_email" />
            </div>

            <div class="form-group">
                <label for="admin_pass">Senha do Usuário Admin:</label>
                <input type="password" id="admin_pass" name="admin_pass" />
            </div>
        </div>

        <button type="submit">
            <span id="buttonText">🚀 Instalar Sistema</span>
        </button>
    </form>

    <div class="footer-note">
        <p>✨ Sistema de Migração v2.0 - Atualizações sem perda de dados</p>
        <p>Por segurança, remova o arquivo <code>install.php</code> após a instalação</p>
    </div>

    <script>
        function toggleMode() {
            const installMode = document.querySelector('input[name="install_mode"]:checked').value;
            const adminFields = document.getElementById('adminFields');
            const adminEmail = document.getElementById('admin_email');
            const adminPass = document.getElementById('admin_pass');
            const buttonText = document.getElementById('buttonText');
            
            if (installMode === 'update') {
                adminFields.style.display = 'none';
                adminEmail.removeAttribute('required');
                adminPass.removeAttribute('required');
                buttonText.textContent = '🔄 Atualizar Sistema';
            } else {
                adminFields.style.display = 'block';
                adminEmail.setAttribute('required', 'required');
                adminPass.setAttribute('required', 'required');
                buttonText.textContent = '🚀 Instalar Sistema';
            }
        }
        
        // Inicializar modo
        toggleMode();
    </script>
<?php endif; ?>
    </div>
</body>
</html>

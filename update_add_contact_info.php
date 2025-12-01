<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atualização do Banco de Dados</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            margin: 0;
        }
        .container {
            max-width: 800px;
            margin: 50px auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 40px;
        }
        h1 {
            color: #1f2937;
            margin-bottom: 10px;
        }
        h2 {
            color: #374151;
            margin-top: 30px;
        }
        .success {
            background: #d1fae5;
            border-left: 4px solid #10b981;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            color: #065f46;
        }
        .error {
            background: #fee2e2;
            border-left: 4px solid #ef4444;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            color: #991b1b;
        }
        .info {
            background: #dbeafe;
            border-left: 4px solid #3b82f6;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            color: #1e40af;
        }
        ul {
            line-height: 1.8;
            color: #6b7280;
        }
        .btn {
            display: inline-block;
            background: #3b82f6;
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            margin-top: 20px;
            transition: all 0.3s ease;
        }
        .btn:hover {
            background: #2563eb;
            transform: translateY(-2px);
        }
        .icon {
            font-size: 3rem;
            margin-bottom: 20px;
        }
        .icon.success { color: #10b981; }
        .icon.error { color: #ef4444; }
        code {
            background: #f3f4f6;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
        }
    </style>
</head>
<body>
    <div class="container">
        <div style="text-align: center;">
            <i class="fas fa-database icon"></i>
            <h1>Atualização do Banco de Dados</h1>
            <p style="color: #6b7280;">Instalação da tabela de informações de contato</p>
        </div>

        <?php
        /**
         * Script para adicionar a tabela de informações de contato dos currículos
         * Execute este arquivo uma vez para criar a nova tabela
         */

        require_once 'db_connect.php';

        try {
            echo "<div class='info'>";
            echo "<strong><i class='fas fa-info-circle'></i> Iniciando atualização...</strong><br>";
            echo "Criando tabela <code>curriculo_contatos</code> no banco de dados.";
            echo "</div>";
            
            // Verificar se a tabela já existe
            $checkTable = $pdo->query("SHOW TABLES LIKE 'curriculo_contatos'");
            if ($checkTable->rowCount() > 0) {
                echo "<div class='info'>";
                echo "<strong><i class='fas fa-check-circle'></i> Tabela já existe!</strong><br>";
                echo "A tabela <code>curriculo_contatos</code> já está criada no banco de dados.";
                echo "</div>";
            } else {
                // Criar tabela curriculo_contatos
                $sql = "CREATE TABLE IF NOT EXISTS curriculo_contatos (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    curriculo_id INT NOT NULL,
                    tipo_contato VARCHAR(100) NOT NULL,
                    informacao TEXT NOT NULL,
                    observacoes TEXT,
                    registrado_por VARCHAR(255) NOT NULL,
                    data_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (curriculo_id) REFERENCES curriculos(id) ON DELETE CASCADE,
                    INDEX idx_curriculo_id (curriculo_id),
                    INDEX idx_data_registro (data_registro)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
                
                $pdo->exec($sql);
                
                echo "<div class='success'>";
                echo "<div style='text-align: center;'>";
                echo "<i class='fas fa-check-circle icon success'></i>";
                echo "</div>";
                echo "<strong>✓ Tabela 'curriculo_contatos' criada com sucesso!</strong>";
                echo "</div>";
            }
            
            echo "<h2><i class='fas fa-table'></i> Estrutura da Tabela</h2>";
            echo "<ul>";
            echo "<li><strong>id:</strong> Identificador único (auto incremento)</li>";
            echo "<li><strong>curriculo_id:</strong> Referência ao currículo (chave estrangeira)</li>";
            echo "<li><strong>tipo_contato:</strong> Tipo de informação (Telefone, Email, WhatsApp, LinkedIn, etc.)</li>";
            echo "<li><strong>informacao:</strong> A informação de contato em si</li>";
            echo "<li><strong>observacoes:</strong> Observações adicionais (opcional)</li>";
            echo "<li><strong>registrado_por:</strong> Email do usuário que registrou</li>";
            echo "<li><strong>data_registro:</strong> Data e hora do registro (automático)</li>";
            echo "</ul>";
            
            echo "<div class='info'>";
            echo "<strong><i class='fas fa-shield-alt'></i> Recursos de Segurança:</strong><br>";
            echo "• Chave estrangeira com <code>ON DELETE CASCADE</code><br>";
            echo "• Índices para otimização de consultas<br>";
            echo "• Charset UTF-8 para suporte a caracteres especiais";
            echo "</div>";
            
            echo "<hr style='margin: 30px 0; border: none; border-top: 2px solid #e5e7eb;'>";
            
            echo "<div class='success' style='text-align: center;'>";
            echo "<h2 style='margin: 0;'><i class='fas fa-check-double'></i> Atualização Concluída!</h2>";
            echo "<p style='margin: 10px 0 0 0;'>O sistema está pronto para usar a nova funcionalidade.</p>";
            echo "</div>";
            
            echo "<div style='text-align: center;'>";
            echo "<a href='admin.php' class='btn'><i class='fas fa-arrow-left'></i> Voltar para o Painel Administrativo</a>";
            echo "<a href='INSTALACAO_CONTATOS.html' class='btn' style='background: #10b981; margin-left: 10px;'><i class='fas fa-book'></i> Ver Guia de Uso</a>";
            echo "</div>";
            
        } catch (PDOException $e) {
            echo "<div class='error'>";
            echo "<div style='text-align: center;'>";
            echo "<i class='fas fa-times-circle icon error'></i>";
            echo "</div>";
            echo "<strong>✗ Erro ao criar tabela:</strong><br><br>";
            echo "<code>" . htmlspecialchars($e->getMessage()) . "</code>";
            echo "</div>";
            
            echo "<div class='info'>";
            echo "<strong><i class='fas fa-lightbulb'></i> Possíveis soluções:</strong><br>";
            echo "• Verifique se o banco de dados está acessível<br>";
            echo "• Confirme que você tem permissões adequadas<br>";
            echo "• Verifique se a tabela <code>curriculos</code> existe (necessária para a chave estrangeira)<br>";
            echo "• Tente executar o arquivo <code>create_contact_table.sql</code> diretamente no phpMyAdmin";
            echo "</div>";
            
            echo "<div style='text-align: center;'>";
            echo "<a href='admin.php' class='btn' style='background: #6b7280;'><i class='fas fa-arrow-left'></i> Voltar</a>";
            echo "</div>";
        }
        ?>
    </div>
</body>
</html>

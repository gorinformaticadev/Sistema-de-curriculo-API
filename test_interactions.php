<?php
/**
 * Teste para verificar se as interações estão sendo salvas com valores reais
 */

require_once 'db_connect.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teste de Interações - Debug</title>
    <style>
        body {
            font-family: 'Courier New', monospace;
            background: #1a1a1a;
            color: #00ff00;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: #2a2a2a;
            padding: 20px;
            border-radius: 10px;
        }
        h1 {
            color: #00ff00;
            border-bottom: 2px solid #00ff00;
            padding-bottom: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            border: 1px solid #444;
            padding: 10px;
            text-align: left;
        }
        th {
            background: #333;
            color: #00ff00;
            font-weight: bold;
        }
        tr:nth-child(even) {
            background: #252525;
        }
        .empty {
            color: #ff6b6b;
            font-style: italic;
        }
        .filled {
            color: #51cf66;
            font-weight: bold;
        }
        .info {
            background: #1e3a8a;
            color: #bfdbfe;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .warning {
            background: #991b1b;
            color: #fecaca;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .success {
            background: #065f46;
            color: #a7f3d0;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Debug: Verificação de Interações no Banco de Dados</h1>
        
        <?php
        try {
            // Verificar se a tabela existe
            $stmt = $pdo->query("SHOW TABLES LIKE 'form_interactions'");
            if ($stmt->rowCount() === 0) {
                echo '<div class="warning">❌ ERRO: Tabela form_interactions não existe!</div>';
                exit;
            }
            
            echo '<div class="success">✅ Tabela form_interactions encontrada</div>';
            
            // Contar total de interações
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM form_interactions");
            $total = $stmt->fetchColumn();
            
            echo "<div class='info'>📊 Total de interações no banco: <strong>$total</strong></div>";
            
            if ($total === 0) {
                echo '<div class="warning">⚠️ Nenhuma interação encontrada. Preencha o formulário primeiro!</div>';
                exit;
            }
            
            // Buscar últimas 50 interações
            $stmt = $pdo->query("
                SELECT 
                    id,
                    session_id,
                    nome_completo,
                    ultimo_campo,
                    acao,
                    valor_campo,
                    timestamp
                FROM form_interactions
                ORDER BY timestamp DESC
                LIMIT 50
            ");
            
            $interactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Contar quantas têm valor_campo preenchido
            $withValue = 0;
            $withoutValue = 0;
            
            foreach ($interactions as $int) {
                if (!empty($int['valor_campo'])) {
                    $withValue++;
                } else {
                    $withoutValue++;
                }
            }
            
            echo "<div class='info'>";
            echo "✅ Com valor preenchido: <strong>$withValue</strong><br>";
            echo "❌ Sem valor (vazio): <strong>$withoutValue</strong>";
            echo "</div>";
            
            if ($withValue === 0) {
                echo '<div class="warning">';
                echo '⚠️ PROBLEMA DETECTADO: Nenhuma interação tem valor_campo preenchido!<br><br>';
                echo '<strong>Possíveis causas:</strong><br>';
                echo '1. O form-tracker.js não está capturando os valores<br>';
                echo '2. O log_interaction.php não está salvando os valores<br>';
                echo '3. Os dados foram salvos antes da atualização<br><br>';
                echo '<strong>Solução:</strong> Limpe as interações antigas e preencha o formulário novamente.';
                echo '</div>';
            }
            
            // Exibir tabela
            echo '<h2>📋 Últimas 50 Interações</h2>';
            echo '<table>';
            echo '<tr>';
            echo '<th>ID</th>';
            echo '<th>Session ID</th>';
            echo '<th>Nome</th>';
            echo '<th>Campo</th>';
            echo '<th>Ação</th>';
            echo '<th>Valor</th>';
            echo '<th>Data/Hora</th>';
            echo '</tr>';
            
            foreach ($interactions as $int) {
                $valueClass = empty($int['valor_campo']) ? 'empty' : 'filled';
                $valueDisplay = empty($int['valor_campo']) ? '(vazio)' : htmlspecialchars($int['valor_campo']);
                
                echo '<tr>';
                echo '<td>' . $int['id'] . '</td>';
                echo '<td>' . substr($int['session_id'], 0, 20) . '...</td>';
                echo '<td>' . ($int['nome_completo'] ?? '(não informado)') . '</td>';
                echo '<td>' . htmlspecialchars($int['ultimo_campo']) . '</td>';
                echo '<td>' . $int['acao'] . '</td>';
                echo '<td class="' . $valueClass . '">' . $valueDisplay . '</td>';
                echo '<td>' . date('d/m/Y H:i:s', strtotime($int['timestamp'])) . '</td>';
                echo '</tr>';
            }
            
            echo '</table>';
            
            // Verificar estrutura da tabela
            echo '<h2>🔧 Estrutura da Tabela</h2>';
            $stmt = $pdo->query("DESCRIBE form_interactions");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo '<table>';
            echo '<tr><th>Campo</th><th>Tipo</th><th>Null</th><th>Key</th><th>Default</th></tr>';
            foreach ($columns as $col) {
                echo '<tr>';
                echo '<td>' . $col['Field'] . '</td>';
                echo '<td>' . $col['Type'] . '</td>';
                echo '<td>' . $col['Null'] . '</td>';
                echo '<td>' . $col['Key'] . '</td>';
                echo '<td>' . ($col['Default'] ?? 'NULL') . '</td>';
                echo '</tr>';
            }
            echo '</table>';
            
        } catch (Exception $e) {
            echo '<div class="warning">❌ ERRO: ' . $e->getMessage() . '</div>';
        }
        ?>
        
        <div class="info" style="margin-top: 30px;">
            <strong>📝 Como testar:</strong><br>
            1. Abra o formulário (index.html)<br>
            2. Preencha alguns campos com dados de teste<br>
            3. Recarregue esta página<br>
            4. Verifique se os valores aparecem na coluna "Valor"<br>
            5. Se aparecer "(vazio)", há um problema no código
        </div>
    </div>
</body>
</html>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel Administrativo - GOR INFORMÁTICA</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../styles.css" rel="stylesheet">
    <style>
        /* Estilos específicos do painel */
        .admin-dashboard {
            min-height: 100vh;
            background: #f8fafc;
        }
        
        .dashboard-header {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 20px 0;
            margin-bottom: 30px;
        }
        
        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .logo i {
            font-size: 2rem;
            color: #1e40af;
        }
        
        .logo h1 {
            margin: 0;
            color: #1f2937;
            font-size: 1.5rem;
        }
        
        .logo p {
            margin: 0;
            color: #6b7280;
            font-size: 0.9rem;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
            color: #6b7280;
            font-size: 0.9rem;
        }
        
        .user-type-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: capitalize;
        }
        
        .user-type-badge.admin {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .user-type-badge.analisador {
            background: #d1fae5;
            color: #065f46;
        }
        
        .main-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        }
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        
        .stat-title {
            font-size: 0.9rem;
            color: #6b7280;
            font-weight: 600;
            margin: 0;
        }
        
        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            color: #1f2937;
            margin: 0;
        }
        
        .tabs-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .tabs-header {
            display: flex;
            border-bottom: 2px solid #e5e7eb;
            overflow-x: auto;
        }
        
        .tab-btn {
            padding: 16px 24px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            color: #6b7280;
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .tab-btn:hover {
            background: #f9fafb;
            color: #374151;
        }
        
        .tab-btn.active {
            color: #1e40af;
            border-bottom-color: #1e40af;
            background: white;
        }
        
        .tab-content {
            display: none;
            padding: 30px;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 25px;
            color: #1f2937;
            font-size: 1.3rem;
        }
        
        .section-title i {
            color: #1e40af;
        }
        
        /* Estilos responsivos */
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .user-info {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .tabs-header {
                flex-direction: column;
            }
            
            .tab-btn {
                justify-content: center;
            }
        }
        
        /* Loading spinner */
        .loading {
            text-align: center;
            padding: 40px;
            color: #6b7280;
        }
        
        .spinner {
            border: 3px solid #f3f4f6;
            border-top: 3px solid #1e40af;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body class="admin-dashboard">
    <header class="dashboard-header">
        <div class="header-content">
            <div class="logo">
                <i class="fas fa-building"></i>
                <div>
                    <h1>GOR INFORMÁTICA</h1>
                    <p>Painel Administrativo</p>
                </div>
            </div>
            
            <div class="user-info">
                <span>
                    <i class="fas fa-user"></i>
                    <?php echo htmlspecialchars($_SESSION['user_email']); ?>
                </span>
                <span class="user-type-badge <?php echo getUserType(); ?>">
                    <?php echo getUserType() === 'admin' ? 'Administrador' : 'Analisador'; ?>
                </span>
                <a href="?logout=1" class="btn-secondary" style="padding: 8px 16px; text-decoration: none;">
                    <i class="fas fa-sign-out-alt"></i> Sair
                </a>
            </div>
        </div>
    </header>

    <div class="main-container">
        <!-- Cards de Estatísticas -->
        <div class="dashboard-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <h3 class="stat-title">Currículos Recebidos</h3>
                    <div class="stat-icon" style="background: #3b82f6;">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                <p class="stat-value"><?php echo number_format($totalCurriculos); ?></p>
            </div>
            
            <?php if (getUserType() === 'admin'): ?>
            <div class="stat-card">
                <div class="stat-header">
                    <h3 class="stat-title">Usuários do Sistema</h3>
                    <div class="stat-icon" style="background: #10b981;">
                        <i class="fas fa-user-cog"></i>
                    </div>
                </div>
                <p class="stat-value" id="usersCount">-</p>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <h3 class="stat-title">Sessões Ativas</h3>
                    <div class="stat-icon" style="background: #f59e0b;">
                        <i class="fas fa-chart-line"></i>
                    </div>
                </div>
                <p class="stat-value" id="activeSessions">-</p>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <h3 class="stat-title">Taxa de Conversão</h3>
                    <div class="stat-icon" style="background: #8b5cf6;">
                        <i class="fas fa-percentage"></i>
                    </div>
                </div>
                <p class="stat-value" id="conversionRate">-</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Sistema de Abas -->
        <div class="tabs-container">
            <div class="tabs-header">
                <button class="tab-btn active" onclick="showTab('curriculos')" data-tab="curriculos">
                    <i class="fas fa-list"></i> Currículos
                </button>
                
                <?php if (getUserType() === 'admin'): ?>
                <button class="tab-btn" onclick="showTab('interactions')" data-tab="interactions">
                    <i class="fas fa-chart-line"></i> Interações do Formulário
                </button>
                
                <button class="tab-btn" onclick="showTab('users')" data-tab="users">
                    <i class="fas fa-users"></i> Usuários
                </button>
                
                <button class="tab-btn" onclick="showTab('config')" data-tab="config">
                    <i class="fas fa-cog"></i> Configurações
                </button>
                
                <button class="tab-btn" onclick="showTab('tests')" data-tab="tests">
                    <i class="fas fa-vial"></i> Testes da API
                </button>
                
                <button class="tab-btn" onclick="showTab('logs')" data-tab="logs">
                    <i class="fas fa-file-alt"></i> Logs do Sistema
                </button>
                
                <button class="tab-btn" onclick="showTab('access')" data-tab="access">
                    <i class="fas fa-eye"></i> Logs de Acesso
                </button>
                <?php endif; ?>
            </div>

            <!-- Conteúdo das Abas -->
            <div id="curriculos-tab" class="tab-content active">
                <h3 class="section-title">
                    <i class="fas fa-list"></i> Currículos Cadastrados
                </h3>
                <div id="curriculosContent">
                    <!-- Conteúdo será carregado via JavaScript -->
                </div>
            </div>

            <?php if (getUserType() === 'admin'): ?>
            <div id="interactions-tab" class="tab-content">
                <h3 class="section-title">
                    <i class="fas fa-chart-line"></i> Análise de Interações do Formulário
                </h3>
                <div id="interactionsContent">
                    <!-- Conteúdo será carregado via JavaScript -->
                </div>
            </div>

            <div id="users-tab" class="tab-content">
                <h3 class="section-title">
                    <i class="fas fa-users"></i> Gerenciar Usuários
                </h3>
                <div id="usersContent">
                    <!-- Conteúdo será carregado via JavaScript -->
                </div>
            </div>

            <div id="config-tab" class="tab-content">
                <h3 class="section-title">
                    <i class="fas fa-cog"></i> Configurações do Sistema
                </h3>
                <div id="configContent">
                    <!-- Conteúdo será carregado via JavaScript -->
                </div>
            </div>

            <div id="tests-tab" class="tab-content">
                <h3 class="section-title">
                    <i class="fas fa-vial"></i> Testes da API
                </h3>
                <div id="testsContent">
                    <!-- Conteúdo será carregado via JavaScript -->
                </div>
            </div>

            <div id="logs-tab" class="tab-content">
                <h3 class="section-title">
                    <i class="fas fa-file-alt"></i> Logs do Sistema
                </h3>
                <div id="logsContent">
                    <!-- Conteúdo será carregado via JavaScript -->
                </div>
            </div>

            <div id="access-tab" class="tab-content">
                <h3 class="section-title">
                    <i class="fas fa-eye"></i> Logs de Acesso ao Formulário
                </h3>
                <div id="accessContent">
                    <!-- Conteúdo será carregado via JavaScript -->
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Variáveis globais
        const userType = '<?php echo getUserType(); ?>';
        const csrfToken = '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>';

        // Função para verificar permissões
        function canAccessTab(tabName) {
            if (userType === 'admin') return true;
            return tabName === 'curriculos';
        }

        // Sistema de abas
        function showTab(tabName) {
            // Verificar permissão
            if (!canAccessTab(tabName)) {
                alert('Acesso negado: você não tem permissão para acessar esta seção.');
                return;
            }

            // Atualizar estado visual das abas
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
                if (btn.dataset.tab === tabName) {
                    btn.classList.add('active');
                }
            });

            // Mostrar conteúdo da aba
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            document.getElementById(tabName + '-tab').classList.add('active');

            // Carregar conteúdo específico da aba
            loadTabContent(tabName);
        }

        // Carregar conteúdo das abas
        function loadTabContent(tabName) {
            switch(tabName) {
                case 'curriculos':
                    loadCurriculos();
                    break;
                case 'interactions':
                    if (userType === 'admin') {
                        loadInteractions();
                    }
                    break;
                case 'users':
                    if (userType === 'admin') {
                        loadUsers();
                    }
                    break;
                case 'config':
                    if (userType === 'admin') {
                        loadConfig();
                    }
                    break;
                case 'tests':
                    if (userType === 'admin') {
                        loadTests();
                    }
                    break;
                case 'logs':
                    if (userType === 'admin') {
                        loadLogs();
                    }
                    break;
                case 'access':
                    if (userType === 'admin') {
                        loadAccessLogs();
                    }
                    break;
            }
        }

        // Mostrar loading
        function showLoading(containerId, message = 'Carregando...') {
            const container = document.getElementById(containerId);
            if (container) {
                container.innerHTML = `
                    <div class="loading">
                        <div class="spinner"></div>
                        <p>${message}</p>
                    </div>
                `;
            }
        }

        // Funções de carregamento (placeholder - implementar conforme necessário)
        function loadCurriculos() {
            showLoading('curriculosContent');
            
            fetch('admin.php?action=getCurriculos')
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('curriculosContent').innerHTML = `
                            <div class="table-container">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Data/Hora</th>
                                            <th>Nome</th>
                                            <th>Telefone</th>
                                            <th>Email</th>
                                            <th>Cidade</th>
                                            <th>Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${data.curriculos.map(c => `
                                            <tr>
                                                <td data-label="Data/Hora">${new Date(c.data_cadastro).toLocaleString('pt-BR')}</td>
                                                <td data-label="Nome">${c.nome}</td>
                                                <td data-label="Telefone">${c.telefone}</td>
                                                <td data-label="Email">${c.email || 'N/A'}</td>
                                                <td data-label="Cidade">${c.cidade}</td>
                                                <td data-label="Ações">
                                                    <button class="btn-small btn-primary" onclick="viewCurriculo(${c.id})">
                                                        <i class="fas fa-eye"></i> Ver
                                                    </button>
                                                    ${userType === 'admin' ? `
                                                    <button class="btn-small btn-remove" onclick="deleteCurriculo(${c.id}, this)">
                                                        <i class="fas fa-trash"></i> Deletar
                                                    </button>
                                                    ` : ''}
                                                </td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        `;
                    } else {
                        document.getElementById('curriculosContent').innerHTML = 
                            '<p class="error-message">Erro ao carregar currículos: ' + data.message + '</p>';
                    }
                })
                .catch(err => {
                    document.getElementById('curriculosContent').innerHTML = 
                        '<p class="error-message">Erro de comunicação com o servidor.</p>';
                });
        }

        // Outras funções de carregamento seguem o mesmo padrão...
        // (implementar conforme necessário)

        // Inicialização
        document.addEventListener('DOMContentLoaded', function() {
            // Carregar conteúdo inicial
            loadTabContent('curriculos');
            
            // Se for admin, carregar estatísticas
            if (userType === 'admin') {
                loadDashboardStats();
            }
        });

        // Função para carregar estatísticas do dashboard
        function loadDashboardStats() {
            // Implementar carregamento das estatísticas
            fetch('admin.php?action=getCurriculoStats')
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        // Atualizar contadores
                        document.getElementById('usersCount').textContent = '-'; // Implementar
                        document.getElementById('activeSessions').textContent = data.stats?.total || '-';
                        document.getElementById('conversionRate').textContent = data.stats?.conversionRate || '-';
                    }
                })
                .catch(console.error);
        }
    </script>
</body>
</html>
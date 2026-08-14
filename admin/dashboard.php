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
        
        /* Modal de Detalhes do Currículo */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        
        .modal-container {
            background: white;
            border-radius: 12px;
            width: 90%;
            max-width: 800px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.3);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 25px;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .modal-header h2 {
            margin: 0;
            color: #1e40af;
            font-size: 1.3rem;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 2rem;
            cursor: pointer;
            color: #6b7280;
            line-height: 1;
        }
        
        .modal-close:hover { color: #dc2626; }
        
        .modal-body {
            padding: 20px 25px;
        }
        
        .modal-body h3 {
            color: #1e40af;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 6px;
            margin: 20px 0 10px 0;
        }
        
        .modal-body h3:first-child { margin-top: 0; }
        
        .modal-body p {
            margin: 4px 0;
            line-height: 1.6;
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

    <!-- Modal de Detalhes do Currículo -->
    <div id="curriculoModal" class="modal-overlay" style="display: none;">
        <div class="modal-container">
            <div class="modal-header">
                <h2><i class="fas fa-file-alt"></i> Detalhes do Currículo</h2>
                <button class="modal-close" onclick="document.getElementById('curriculoModal').style.display='none'">&times;</button>
            </div>
            <div id="modalBody" class="modal-body" style="max-height: 70vh; overflow-y: auto;"></div>
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

        // ============================================
        // MODAL DE DETALHES DO CURRÍCULO (todos os dados)
        // ============================================
        function viewCurriculo(id) {
            const modal = document.getElementById('curriculoModal');
            const modalBody = document.getElementById('modalBody');
            modalBody.innerHTML = '<p>Carregando detalhes...</p>';
            modal.style.display = 'flex';

            fetch('admin.php?action=getCurriculoDetails&id=' + id)
                .then(res => res.json())
                .then(data => {
                    if (!data.success) {
                        modalBody.innerHTML = '<p class="error-message">' + data.message + '</p>';
                        return;
                    }
                    const c = data.curriculo;

                    // Habilidades
                    let habilidadesHtml = 'Não informadas';
                    if (c.habilidades && c.habilidades.length > 0) {
                        habilidadesHtml = c.habilidades.map(h => `<span style="display:inline-block;background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;border-radius:999px;padding:4px 12px;margin:3px;font-size:0.85rem;font-weight:600;">${h}</span>`).join('');
                    }

                    // Experiências
                    let experienciasHtml = '<p>Nenhuma experiência informada.</p>';
                    if (c.experiencias && c.experiencias.length > 0) {
                        experienciasHtml = c.experiencias.map((exp, i) => `
                            <div style="border-left:3px solid #e5e7eb;padding-left:12px;margin-bottom:12px;">
                                <h4 style="margin:0 0 6px 0;">Experiência ${i + 1}</h4>
                                <p><strong>Empresa:</strong> ${exp.company || 'N/A'}</p>
                                <p><strong>Cargo/Função:</strong> ${exp.position || 'N/A'}</p>
                                <p><strong>Tempo:</strong> ${exp.duration || 'N/A'}</p>
                                ${exp.atividades ? `<p><strong>Principais atividades:</strong> ${exp.atividades}</p>` : ''}
                                ${exp.empregado_atual === 'Sim' ? `<p><strong>Trabalha atualmente:</strong> Sim</p>${exp.motivo_saida_atual ? `<p><strong>Por que está saindo:</strong> ${exp.motivo_saida_atual}</p>` : ''}` : (exp.motivo_saida ? `<p><strong>Motivo da saída:</strong> ${exp.motivo_saida}</p>` : '')}
                            </div>
                        `).join('');
                    }

                    // Referências
                    let referenciasHtml = '';
                    if (c.referencias && c.referencias.length > 0) {
                        referenciasHtml = c.referencias.map((ref, i) => `
                            <div style="border-left:3px solid #e5e7eb;padding-left:12px;margin-bottom:10px;">
                                <p><strong>Nome:</strong> ${ref.nome || 'N/A'}</p>
                                ${ref.empresa ? `<p><strong>Empresa:</strong> ${ref.empresa}</p>` : ''}
                                ${ref.cargo ? `<p><strong>Cargo:</strong> ${ref.cargo}</p>` : ''}
                                ${ref.telefone ? `<p><strong>Telefone:</strong> ${ref.telefone}</p>` : ''}
                            </div>
                        `).join('');
                    }

                    modalBody.innerHTML = `
                        <h3><i class="fas fa-user"></i> Dados Pessoais</h3>
                        <p><strong>Nome:</strong> ${c.nome || 'N/A'}</p>
                        <p><strong>Data de Nascimento:</strong> ${c.data_nascimento ? new Date(c.data_nascimento + 'T00:00:00').toLocaleDateString('pt-BR') : 'N/A'}</p>
                        <p><strong>Estado Civil:</strong> ${c.estado_civil || 'Não informado'}</p>
                        <p><strong>Possui Filhos:</strong> ${c.possui_filhos || 'Não'}</p>

                        <h3><i class="fas fa-phone"></i> Contato</h3>
                        <p><strong>Telefone(s):</strong> ${c.telefone || 'Não informado'}</p>
                        <p><strong>É WhatsApp:</strong> ${c.is_whatsapp || 'Não'}</p>
                        <p><strong>Email:</strong> ${c.email || 'Não informado'}</p>
                        ${c.facebook ? `<p><strong>Facebook:</strong> ${c.facebook}</p>` : ''}
                        ${c.instagram ? `<p><strong>Instagram:</strong> ${c.instagram}</p>` : ''}

                        <h3><i class="fas fa-map-marker-alt"></i> Endereço</h3>
                        <p><strong>Endereço:</strong> ${c.endereco || 'Não informado'}</p>
                        <p><strong>Cidade:</strong> ${c.cidade || 'Não informado'}</p>
                        <p><strong>Estado:</strong> ${c.estado || 'Não informado'}</p>

                        <h3><i class="fas fa-calendar-check"></i> Disponibilidade</h3>
                        <p><strong>Pode começar:</strong> ${c.disponibilidade_inicio || 'Não informado'}${c.disponibilidade_inicio === 'Outra data' && c.disponibilidade_outra_data ? ' (' + new Date(c.disponibilidade_outra_data + 'T00:00:00').toLocaleDateString('pt-BR') + ')' : ''}</p>
                        <p><strong>Sábados:</strong> ${c.disponibilidade_sabados || 'Não informado'}</p>
                        <p><strong>Horas extras:</strong> ${c.disponibilidade_horas_extras || 'Não informado'}</p>

                        <h3><i class="fas fa-money-bill-wave"></i> Pretensão Salarial</h3>
                        <p>${c.pretensao_salarial || 'Não informada'}</p>

                        <h3><i class="fas fa-graduation-cap"></i> Formação</h3>
                        <p><strong>Escolaridade:</strong> ${c.escolaridade || 'Não informado'}</p>
                        <p><strong>Está Estudando:</strong> ${c.estudando || 'Não'}</p>
                        ${c.periodo_estudo ? `<p><strong>Período de Estudo:</strong> ${c.periodo_estudo}</p>` : ''}
                        ${c.curso_atual ? `<p><strong>Curso Atual:</strong> ${c.curso_atual}</p>` : ''}
                        ${c.instituicao_curso ? `<p><strong>Instituição:</strong> ${c.instituicao_curso}</p>` : ''}
                        ${c.situacao_curso ? `<p><strong>Situação do Curso:</strong> ${c.situacao_curso}</p>` : ''}
                        ${c.ano_conclusao_curso ? `<p><strong>Ano Conclusão/Previsão:</strong> ${c.ano_conclusao_curso}</p>` : ''}
                        <p><strong>Possui Cursos:</strong> ${c.possui_cursos || 'Não'}</p>
                        ${c.cursos ? `<p><strong>Cursos:</strong><br>${c.cursos.replace(/\n/g, '<br>')}</p>` : ''}

                        <h3><i class="fas fa-star"></i> Habilidades</h3>
                        <p>${habilidadesHtml}</p>
                        <p><strong>Conhecimento em Informática:</strong> ${c.conhecimento_informatica || 'Não informado'}</p>

                        <h3><i class="fas fa-briefcase"></i> Experiência Profissional</h3>
                        <p><strong>Possui Experiência:</strong> ${c.possui_experiencia || 'Não'}</p>
                        ${experienciasHtml}
                        ${c.expectativa_primeiro_emprego ? `<p><strong>Expectativa 1º Emprego:</strong> ${c.expectativa_primeiro_emprego}</p>` : ''}

                        <h3><i class="fas fa-info-circle"></i> Informações Complementares</h3>
                        <p><strong>Como conheceu a vaga:</strong> ${c.como_conheceu || 'Não informado'}${c.como_conheceu === 'Outro' && c.como_conheceu_outro ? ' - ' + c.como_conheceu_outro : ''}</p>
                        <p><strong>Possui referência:</strong> ${c.referencias && c.referencias.length > 0 ? 'Sim' : 'Não'}</p>
                        ${referenciasHtml}

                        <h3><i class="fas fa-target"></i> Objetivo</h3>
                        <p>${c.motivacao ? c.motivacao.replace(/\n/g, '<br>') : 'Não informado'}</p>

                        <h3><i class="fas fa-file-alt"></i> Arquivos</h3>
                        ${c.arquivo_curriculo ? `<p><a href="../uploads/${encodeURIComponent(c.arquivo_curriculo)}" target="_blank" style="color:#dc2626;"><i class="fas fa-file-pdf"></i> Currículo (PDF)</a></p>` : '<p>Nenhum currículo anexado</p>'}
                        ${c.arquivo_foto ? `<p><a href="../uploads/${encodeURIComponent(c.arquivo_foto)}" target="_blank" style="color:#059669;"><i class="fas fa-camera"></i> Foto</a></p>` : '<p>Nenhuma foto anexada</p>'}

                        <hr>
                        <p style="font-size:0.85rem;color:#6b7280;"><strong>Status:</strong> ${c.status || 'Não definido'} | <strong>Visualizado:</strong> ${c.visualizado ? 'Sim' : 'Não'} | <strong>Aceitou Termos:</strong> ${c.aceitou_termos ? 'Sim' : 'Não'} | <strong>LGPD:</strong> ${c.consentimento_lgpd || 'Não'} | <strong>Banco de Talentos:</strong> ${c.consentimento_banco_talentos || 'Não'} | <strong>IP:</strong> ${c.ip_cadastro || 'N/A'} | <strong>Cadastro:</strong> ${new Date(c.data_cadastro).toLocaleString('pt-BR')}</p>
                    `;
                })
                .catch(err => {
                    modalBody.innerHTML = '<p class="error-message">Erro ao carregar os dados: ' + err + '</p>';
                });
        }

        function deleteCurriculo(id, element) {
            if (!confirm('Tem certeza que deseja deletar este currículo?\n\nEsta ação também removerá os arquivos associados e não pode ser desfeita.')) {
                return;
            }
            const formData = new FormData();
            formData.append('action', 'deleteCurriculo');
            formData.append('id', id);
            formData.append('csrf_token', csrfToken);

            fetch('admin.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        const row = element.closest('tr');
                        if (row) row.remove();
                    } else {
                        alert('Erro: ' + data.message);
                    }
                })
                .catch(err => alert('Ocorreu um erro de comunicação com o servidor.'));
        }

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
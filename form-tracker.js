/**
 * Form Tracker - Sistema de rastreamento de interações do formulário
 * Captura e registra todas as interações do usuário com o formulário
 */

class FormTracker {
    constructor() {
        this.sessionId = this.generateSessionId();
        this.lastField = null;
        this.userName = null;
        this.interactions = [];
        this.sendInterval = 5000; // Enviar dados a cada 5 segundos
        this.init();
    }

    generateSessionId() {
        // Verificar se já existe um sessionId no sessionStorage
        let sessionId = sessionStorage.getItem('form_session_id');
        if (!sessionId) {
            sessionId = 'session_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            sessionStorage.setItem('form_session_id', sessionId);
        }
        return sessionId;
    }

    init() {
        // console.log('📊 Form Tracker iniciado - Session ID:', this.sessionId);

        // Ler as interações dos cookies
        const interactionsCookie = this.getCookie('formInteractions');
        if (interactionsCookie) {
            try {
                const interactionsData = JSON.parse(interactionsCookie);
                this.interactions = interactionsData.interactions;
                this.lastField = interactionsData.lastField;
                this.userName = interactionsData.userName;
            } catch (error) {
                // console.error('❌ Erro ao ler as interações dos cookies:', error);
            }
        }

        // Rastrear todos os inputs, selects e textareas
        this.trackFormFields();

        // Exibir o timeline de interações (apenas no console)
        this.displayInteractionTimeline();

        // Adicionar o aviso de cookies
        this.addCookieNotice();

        // Enviar dados periodicamente
        setInterval(() => this.sendInteractions(), this.sendInterval);

        // Enviar dados antes de sair da página
        window.addEventListener('beforeunload', () => this.sendInteractions(true));
    }

    // Função para obter um cookie
    getCookie(name) {
        let nameEQ = name + "=";
        let ca = document.cookie.split(';');
        for (let i = 0; i < ca.length; i++) {
            let c = ca[i];
            while (c.charAt(0) === ' ') c = c.substring(1, c.length);
            if (c.indexOf(nameEQ) === 0) return c.substring(nameEQ.length, c.length);
        }
        return null;
    }

    trackFormFields() {
        const form = document.getElementById('curriculumForm');
        if (!form) return;

        // Rastrear inputs de texto
        form.querySelectorAll('input[type="text"], input[type="email"], input[type="tel"], input[type="date"]').forEach(input => {
            input.addEventListener('focus', (e) => {
                if (e.target.name !== 'name') this.logInteraction(e.target, 'focus');
            });
            input.addEventListener('blur', (e) => {
                if (e.target.name !== 'name') this.logInteraction(e.target, 'blur');
            });
            input.addEventListener('change', (e) => {
                if (e.target.name !== 'name') this.logInteraction(e.target, 'change');
            });
            // Removido evento 'input' para evitar excesso de logs (cada tecla)
        });

        // Rastrear textareas
        form.querySelectorAll('textarea').forEach(textarea => {
            textarea.addEventListener('focus', (e) => this.logInteraction(e.target, 'focus'));
            textarea.addEventListener('blur', (e) => this.logInteraction(e.target, 'blur'));
            textarea.addEventListener('change', (e) => this.logInteraction(e.target, 'change'));
        });

        // Rastrear selects
        form.querySelectorAll('select').forEach(select => {
            select.addEventListener('focus', (e) => this.logInteraction(e.target, 'focus'));
            select.addEventListener('change', (e) => this.logInteraction(e.target, 'change'));
        });

        // Rastrear radio buttons
        form.querySelectorAll('input[type="radio"]').forEach(radio => {
            radio.addEventListener('change', (e) => this.logInteraction(e.target, 'select'));
        });

        // Rastrear checkboxes
        form.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
            checkbox.addEventListener('change', (e) => this.logInteraction(e.target, 'check'));
        });

        // Rastrear file inputs
        form.querySelectorAll('input[type="file"]').forEach(fileInput => {
            fileInput.addEventListener('change', (e) => this.logInteraction(e.target, 'file_selected'));
        });

        // Capturar nome completo quando preenchido
        const nameInput = form.querySelector('input[name="name"]');
        if (nameInput) {
            // Removido focus/blur e input para o campo nome para limpar logs
            // Apenas capturamos change (ao sair do campo/finalizar edição)
            nameInput.addEventListener('change', (e) => {
                this.logInteraction(e.target, 'change');
            });
        }
    }

    logInteraction(element, action) {
        const fieldName = element.name || element.id || 'unknown';
        const fieldLabel = this.getFieldLabel(element);

        // Atualizar último campo
        this.lastField = fieldLabel || fieldName;

        // Obter valor do campo (sem dados sensíveis completos, exceto nome)
        let fieldValue = '';
        if (element.name === 'name') {
            fieldValue = element.value;
            this.userName = element.value; // Atualiza o nome do usuário na sessão
            // console.log('✅ Nome Completo capturado:', fieldValue, 'Ação:', action);
        } else if (action === 'change' || action === 'select' || action === 'check' || action === 'input') {
            // Capturar valor para inputs de texto também, se não for sensível (ajuste conforme necessidade)
            // O usuário pediu para ver o que foi digitado no input name, que já está coberto acima.
            // Para outros campos, mantemos a lógica de privacidade ou expandimos se necessário.

            if (element.type === 'radio' || element.type === 'checkbox') {
                fieldValue = element.value;
            } else if (element.type === 'file') {
                fieldValue = element.files.length > 0 ? 'arquivo_selecionado' : 'nenhum_arquivo';
            } else if (element.value) {
                // Para outros campos de texto, podemos salvar o valor se não for sensível
                // Por enquanto, mantemos a lógica original para outros campos, mas garantimos que 'name' tenha o valor
                fieldValue = element.value.length > 0 ? 'preenchido' : 'vazio';
            }
        }

        const interaction = {
            sessionId: this.sessionId,
            fieldName: fieldName,
            fieldLabel: fieldLabel,
            action: action,
            fieldValue: fieldValue,
            userName: this.userName,
            timestamp: new Date().toISOString()
        };

        this.interactions.push(interaction);

        // Formatar a mensagem de log
        let logMessage = `📝 ${new Date().toLocaleTimeString()} ${action} ${fieldLabel}`;
        if (fieldValue) {
            logMessage += ` Valor: ${fieldValue}`;
        }

        // console.log(logMessage);
    }

    getFieldLabel(element) {
        // Tentar encontrar o label associado
        const label = element.closest('.form-group')?.querySelector('label');
        if (label) {
            return label.textContent.trim().replace(/\*/g, '').trim();
        }

        // Tentar pelo atributo name
        const nameMap = {
            'name': 'Nome Completo',
            'birthDate': 'Data de Nascimento',
            'maritalStatus': 'Estado Civil',
            'phone': 'Telefone',
            'isWhatsapp': 'É WhatsApp?',
            'email': 'Email',
            'facebook': 'Facebook',
            'instagram': 'Instagram',
            'address': 'Endereço',
            'city': 'Cidade',
            'state': 'Estado',
            'education': 'Escolaridade',
            'isStudying': 'Está Estudando?',
            'studyPeriod': 'Período de Estudo',
            'hasCourses': 'Possui Cursos?',
            'courses': 'Cursos',
            'hasExperience': 'Possui Experiência?',
            'motivation': 'Motivação',
            'resume': 'Currículo (PDF)',
            'photo': 'Foto',
            'acceptTerms': 'Aceita os Termos',
            'workSchedule': 'Horário da Vaga'
        };

        return nameMap[element.name] || element.name;
    }

    async sendInteractions(isBeforeUnload = false) {
        if (this.interactions.length === 0) return;

        const dataToSend = {
            interactions: [...this.interactions],
            lastField: this.lastField,
            userName: this.userName
        };

        // Limpar array de interações
        this.interactions = [];

        try {
            if (isBeforeUnload) {
                // Usar sendBeacon para envio garantido antes de sair
                const blob = new Blob([JSON.stringify(dataToSend)], { type: 'application/json' });
                navigator.sendBeacon('log_interaction.php', blob);
            } else {
                // Envio normal via fetch
                const response = await fetch('log_interaction.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(dataToSend)
                });

                // Reiniciar sessão se a página perder o foco
                window.addEventListener('blur', function () {
                    sessionStorage.removeItem('form_session_id');
                    // console.log('🔄 Sessão reiniciada devido à perda de foco.');
                });

                if (response.ok) {
                    const result = await response.json();
                    // console.log('✅ Interações enviadas:', result);
                } else {
                    // console.error('❌ Erro ao enviar interações:', response.status);
                }
            }
        } catch (error) {
            // console.error('❌ Erro ao enviar interações:', error);
        }

        // Salvar as interações em cookies
        this.setCookie('formInteractions', JSON.stringify(dataToSend), 30); // Expira em 30 dias
    }

    // Função para definir um cookie
    setCookie(name, value, days) {
        let expires = "";
        if (days) {
            let date = new Date();
            date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
            expires = "; expires=" + date.toUTCString();
        }
        document.cookie = name + "=" + (value || "") + expires + "; path=/";
    }

    displayInteractionTimeline() {
        return;
    }

    addCookieNotice() {
        // Criar o elemento para o aviso de cookies
        const cookieNoticeDiv = document.createElement('div');
        cookieNoticeDiv.id = 'cookieNotice';
        cookieNoticeDiv.style.cssText = `
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            background-color: #333;
            color: #fff;
            padding: 10px;
            text-align: center;
            z-index: 1000;
        `;
        cookieNoticeDiv.textContent = 'Este site usa cookies para melhorar a sua experiência. Ao continuar a navegar, você concorda com o uso de cookies.';

        // Criar o botão para aceitar os cookies
        const acceptButton = document.createElement('button');
        acceptButton.textContent = 'Aceitar';
        acceptButton.style.cssText = `
            background-color: #4CAF50;
            color: white;
            padding: 5px 10px;
            margin-left: 10px;
            border: none;
            cursor: pointer;
        `;
        acceptButton.addEventListener('click', () => {
            // Remover o aviso de cookies
            cookieNoticeDiv.style.display = 'none';
        });

        // Adicionar o botão ao aviso de cookies
        cookieNoticeDiv.appendChild(acceptButton);

        // Adicionar o aviso de cookies ao body da página
        document.body.appendChild(cookieNoticeDiv);
    }
}

// Inicializar o tracker quando o DOM estiver pronto
document.addEventListener('DOMContentLoaded', function () {
    window.formTracker = new FormTracker();
});

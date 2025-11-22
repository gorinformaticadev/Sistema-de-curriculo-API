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
        console.log('📊 Form Tracker iniciado - Session ID:', this.sessionId);
        
        // Rastrear todos os inputs, selects e textareas
        this.trackFormFields();
        
        // Enviar dados periodicamente
        setInterval(() => this.sendInteractions(), this.sendInterval);
        
        // Enviar dados antes de sair da página
        window.addEventListener('beforeunload', () => this.sendInteractions(true));

        // Exibir o timeline de interações
        this.displayInteractionTimeline();
    }

    trackFormFields() {
        const form = document.getElementById('curriculumForm');
        if (!form) return;

        // Rastrear inputs de texto
        form.querySelectorAll('input[type="text"], input[type="email"], input[type="tel"], input[type="date"]').forEach(input => {
            input.addEventListener('focus', (e) => this.logInteraction(e.target, 'focus'));
            input.addEventListener('blur', (e) => this.logInteraction(e.target, 'blur'));
            input.addEventListener('change', (e) => this.logInteraction(e.target, 'change'));
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
            nameInput.addEventListener('focus', (e) => {
                this.logInteraction(e.target, 'focus');
            });
            nameInput.addEventListener('input', (e) => {
                this.logInteraction(e.target, 'input');
            });
        }
    }

    logInteraction(element, action) {
        const fieldName = element.name || element.id || 'unknown';
        const fieldLabel = this.getFieldLabel(element);
        
        // Atualizar último campo
        this.lastField = fieldLabel || fieldName;

        // Obter valor do campo (sem dados sensíveis completos)
        let fieldValue = '';
        if (element.name === 'name') {
            fieldValue = element.value;
            console.log('✅ Nome Completo capturado:', fieldValue, 'Ação:', action);
        } else if (action === 'change' || action === 'select' || action === 'check') {
            if (element.type === 'radio' || element.type === 'checkbox') {
                fieldValue = element.value;
            } else if (element.type === 'file') {
                fieldValue = element.files.length > 0 ? 'arquivo_selecionado' : 'nenhum_arquivo';
            } else if (element.value) {
                // Para outros campos de texto, apenas indicar que foi preenchido
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

        console.log(logMessage);
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
                window.addEventListener('blur', function() {
                    sessionStorage.removeItem('form_session_id');
                    console.log('🔄 Sessão reiniciada devido à perda de foco.');
                });

                if (response.ok) {
                    const result = await response.json();
                    console.log('✅ Interações enviadas:', result);
                } else {
                    console.error('❌ Erro ao enviar interações:', response.status);
                }
            }
        } catch (error) {
            console.error('❌ Erro ao enviar interações:', error);
        }
    }

    displayInteractionTimeline() {
        // Criar o elemento para o timeline
        const timelineDiv = document.createElement('div');
        timelineDiv.id = 'interactionTimeline';
        timelineDiv.style.cssText = `
            position: fixed;
            top: 0;
            right: 0;
            width: 300px;
            height: 100%;
            background-color: #f0f0f0;
            padding: 10px;
            overflow-y: auto;
            z-index: 1000;
            font-size: 12px;
        `;

        // Adicionar um título ao timeline
        const title = document.createElement('h3');
        title.textContent = 'Timeline de Interações';
        timelineDiv.appendChild(title);

        // Adicionar as interações ao timeline
        this.interactions.forEach(interaction => {
            const interactionDiv = document.createElement('div');
            interactionDiv.style.marginBottom = '5px';

            let interactionText = `${new Date(interaction.timestamp).toLocaleTimeString()} - ${interaction.action} - `;
            if (interaction.fieldName === 'name') {
                interactionText += `${interaction.fieldValue}`;
            } else {
                interactionText += `${interaction.fieldLabel || interaction.fieldName}`;
            }
            if (interaction.fieldValue) {
                interactionText += ` - ${interaction.fieldValue}`;
            }

            interactionDiv.textContent = interactionText
            timelineDiv.appendChild(interactionDiv);
        });

        // Adicionar o timeline ao body da página
        document.body.appendChild(timelineDiv);
    }
}

// Inicializar o tracker quando o DOM estiver pronto
document.addEventListener('DOMContentLoaded', function() {
    window.formTracker = new FormTracker();
});

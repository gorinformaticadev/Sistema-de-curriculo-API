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
        this.formSubmitted = false; // Rastrear se o formulário foi enviado com sucesso
        this.blurListenerAdded = false; // Evitar registrar múltiplos listeners de blur
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
        console.log('📊 FormTracker iniciado - Session ID:', this.sessionId);

        // Ler as interações dos cookies
        const interactionsCookie = this.getCookie('formInteractions');
        if (interactionsCookie) {
            try {
                const interactionsData = JSON.parse(interactionsCookie);
                this.interactions = interactionsData.interactions || [];
                this.lastField = interactionsData.lastField;
                this.userName = interactionsData.userName;
            } catch (error) {
                // console.error('❌ Erro ao ler as interações dos cookies:', error);
            }
        }

        // Rastrear acesso ao formulário (registrar no banco de dados)
        this.trackFormAccess();

        // Rastrear todos os inputs, selects e textareas
        this.trackFormFields();

        // Exibir o timeline de interações (apenas no console)
        this.displayInteractionTimeline();

        // Adicionar o aviso de cookies
        this.addCookieNotice();

        // Enviar dados periodicamente
        setInterval(() => this.sendInteractions(), this.sendInterval);

        // Registrar listener de blur UMA ÚNICA VEZ (não dentro de sendInteractions)
        if (!this.blurListenerAdded) {
            this.blurListenerAdded = true;
            window.addEventListener('blur', () => {
                sessionStorage.removeItem('form_session_id');
                // console.log('🔄 Sessão reiniciada devido à perda de foco.');
            });
        }

        // Enviar dados antes de sair da página (inclui detecção de abandono)
        window.addEventListener('beforeunload', () => this.handlePageExit());
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

    /**
     * Registra o acesso ao formulário na tabela form_interactions
     * Isso garante que a aba "Interações do Formulário" no admin tenha dados de acesso
     */
    trackFormAccess() {
        console.log('🌐 Registrando acesso ao formulário...');
        const accessInteraction = {
            sessionId: this.sessionId,
            fieldName: 'form_access',
            fieldLabel: 'Acesso ao Formulário',
            action: 'form_access',
            fieldValue: 'Página do formulário acessada',
            userName: this.userName,
            timestamp: new Date().toISOString()
        };

        this.interactions.push(accessInteraction);

        // Enviar imediatamente para garantir registro
        this.sendInteractions();
    }

    /**
     * Marca o formulário como enviado com sucesso
     * Chamado pelo script.js após resposta de sucesso
     */
    markFormSubmitted() {
        this.formSubmitted = true;
    }

    /**
     * Handler de saída da página - detecta abandono e envia interações pendentes
     */
    handlePageExit() {
        // Se o formulário NÃO foi enviado com sucesso, registrar como abandono
        if (!this.formSubmitted) {
            const abandonInteraction = {
                sessionId: this.sessionId,
                fieldName: 'form_abandoned',
                fieldLabel: 'Formulário Abandonado',
                action: 'form_abandoned',
                fieldValue: this.lastField || 'Nenhum campo preenchido',
                userName: this.userName,
                timestamp: new Date().toISOString()
            };
            this.interactions.push(abandonInteraction);
        }

        // Sempre tentar enviar interações pendentes ao sair
        if (this.interactions.length > 0) {
            const dataToSend = {
                interactions: [...this.interactions],
                lastField: this.lastField,
                userName: this.userName
            };
            this.interactions = [];
            const blob = new Blob([JSON.stringify(dataToSend)], { type: 'application/json' });
            navigator.sendBeacon('log_interaction.php', blob);
        }

        // Salvar estado nos cookies para recuperação
        const dataToSave = {
            interactions: [],
            lastField: this.lastField,
            userName: this.userName
        };
        this.setCookie('formInteractions', JSON.stringify(dataToSave), 30);
    }

    trackFormFields() {
        const form = document.getElementById('curriculumForm');
        if (!form) return;

        // Rastrear inputs de texto - usar BLUR para garantir captura quando sair do campo
        form.querySelectorAll('input[type="text"], input[type="email"], input[type="tel"], input[type="date"]').forEach(input => {
            // Usar blur ao invés de change para garantir que capture quando o usuário sair do campo
            input.addEventListener('blur', (e) => {
                // Só registrar se tiver valor
                if (e.target.value && e.target.value.trim() !== '') {
                    this.logInteraction(e.target, 'change');
                }
            });
        });

        // Rastrear textareas - usar BLUR
        form.querySelectorAll('textarea').forEach(textarea => {
            textarea.addEventListener('blur', (e) => {
                if (e.target.value && e.target.value.trim() !== '') {
                    this.logInteraction(e.target, 'change');
                }
            });
        });

        // Rastrear selects - usar CHANGE (funciona bem para selects)
        form.querySelectorAll('select').forEach(select => {
            select.addEventListener('change', (e) => {
                if (e.target.value) {
                    this.logInteraction(e.target, 'change');
                }
            });
        });

        // Rastrear radio buttons - usar CHANGE
        form.querySelectorAll('input[type="radio"]').forEach(radio => {
            radio.addEventListener('change', (e) => {
                this.logInteraction(e.target, 'select');
            });
        });

        // Rastrear checkboxes - usar CHANGE
        form.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
            checkbox.addEventListener('change', (e) => {
                this.logInteraction(e.target, 'check');
            });
        });

        // Rastrear file inputs - usar CHANGE
        form.querySelectorAll('input[type="file"]').forEach(fileInput => {
            fileInput.addEventListener('change', (e) => {
                this.logInteraction(e.target, 'file_selected');
            });
        });

        // Rastrear botão de finalizar cadastro (usar evento 'submit' do form para garantir captura)
        if (form) {
            form.addEventListener('submit', (e) => {
                this.logInteraction(form.querySelector('button[type="submit"]') || e.target, 'form_submitted');
            });
        }
        // Também rastrear click no botão como fallback
        const submitBtn = form ? form.querySelector('button[type="submit"]') : null;
        if (submitBtn) {
            submitBtn.addEventListener('click', (e) => {
                if (!this.formSubmitted) {
                    this.logInteraction(e.target, 'form_submit_click');
                }
            });
        }
    }

    logInteraction(element, action) {
        const fieldName = element.name || element.id || 'unknown';
        const fieldLabel = this.getFieldLabel(element);

        // Atualizar último campo
        this.lastField = fieldLabel || fieldName;

        // Obter valor REAL do campo
        let fieldValue = '';
        
        if (element.type === 'file') {
            // Para arquivos, apenas indicar que foi anexado
            if (element.files.length > 0) {
                const file = element.files[0];
                fieldValue = `Arquivo anexado: ${file.name} (${(file.size / 1024).toFixed(1)}KB)`;
            } else {
                fieldValue = 'Nenhum arquivo selecionado';
            }
        } else if (element.type === 'radio' || element.type === 'checkbox') {
            // Para radio e checkbox, capturar o valor selecionado
            if (element.checked) {
                fieldValue = element.value;
            }
        } else if (element.tagName === 'SELECT') {
            // Para selects, capturar o texto da opção selecionada
            fieldValue = element.options[element.selectedIndex]?.text || element.value;
        } else if (element.tagName === 'TEXTAREA' || element.type === 'text' || element.type === 'email' || element.type === 'tel' || element.type === 'date') {
            // Para todos os outros campos de texto, capturar o valor real
            fieldValue = element.value || '';
        } else {
            // Fallback para outros tipos
            fieldValue = element.value || '';
        }

        // Atualizar nome do usuário se for o campo de nome
        if (element.name === 'name' && fieldValue) {
            this.userName = fieldValue;
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
        let logMessage = `📝 ${new Date().toLocaleTimeString()} - ${action.toUpperCase()} - ${fieldLabel}`;
        if (fieldValue) {
            // Limitar tamanho do valor no log do console para não poluir
            const displayValue = fieldValue.length > 50 ? fieldValue.substring(0, 50) + '...' : fieldValue;
            logMessage += ` → ${displayValue}`;
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
            'hasChildren': 'Possui Filhos?',
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

                if (response.ok) {
                    try {
                        const result = await response.json();
                        if (result.errors && result.errors.length > 0) {
                            console.warn('⚠️ Interações enviadas com erros:', result.errors);
                        }
                    } catch (jsonErr) {
                        console.warn('⚠️ Resposta não-JSON do servidor de interações');
                    }
                } else {
                    // Tentar ler a mensagem de erro do servidor
                    try {
                        const errorData = await response.json();
                        console.error('❌ Erro ao registrar interações:', errorData.message || response.status);
                    } catch (e) {
                        console.error('❌ Erro HTTP ao enviar interações:', response.status, response.statusText);
                    }
                }
            }
        } catch (error) {
            console.error('❌ Erro de rede ao enviar interações:', error.message);
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

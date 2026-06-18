// Global variables
let contactCount = 1;
let experienceCount = 1;

// DOM Elements
const curriculumForm = document.getElementById('curriculumForm');
const newRegistrationBtn = document.getElementById('newRegistration');

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    logAccess();
    initializeEventListeners();
    setupFormLogic();
});

// Event Listeners
function initializeEventListeners() {
    // Curriculum form
    curriculumForm.addEventListener('submit', handleCurriculumSubmit);

    // New registration button
    newRegistrationBtn.addEventListener('click', () => {
        document.getElementById('successMessage').style.display = 'none';
        document.getElementById('mainForm').style.display = 'block';
        curriculumForm.reset();
        resetFormSections();
        resetDynamicFields();
    });

    // Add contact button
    document.getElementById('addContactBtn').addEventListener('click', addContact);

    // Add experience button
    document.getElementById('addExperienceBtn').addEventListener('click', addExperience);
}

// Add Contact
function addContact() {
    if (contactCount >= 3) {
        alert('Você pode adicionar no máximo 3 números de telefone.');
        return;
    }
    contactCount++;
    const container = document.getElementById('contactsContainer');
    
    const contactDiv = document.createElement('div');
    contactDiv.className = 'contact-item';
    contactDiv.setAttribute('data-contact', contactCount);
    
    contactDiv.innerHTML = `
        <div class="form-row">
            <div class="form-group">
                <label>📞 Telefone ${contactCount}</label>
                <input type="tel" name="phone${contactCount}" placeholder="(00)00000-0000" pattern="\\(\\d{2}\\)\\d{4,5}-\\d{4}">
                <small>**Adicione preferencialmente um número de WhatsApp!</small>
            </div>
            <div class="form-group">
                <label>Este número é WhatsApp?</label>
                <div class="radio-group">
                    <label class="radio-label">
                        <input type="radio" name="isWhatsapp${contactCount}" value="Sim">
                        <span>Sim</span>
                    </label>
                    <label class="radio-label">
                        <input type="radio" name="isWhatsapp${contactCount}" value="Não">
                        <span>Não</span>
                    </label>
                </div>
            </div>
            <div class="form-group">
                <button type="button" class="btn-remove" onclick="removeContact(${contactCount})">
                    <i class="fas fa-trash"></i>
                    Remover
                </button>
            </div>
        </div>
    `;
    
    container.appendChild(contactDiv);
    
    // Apply phone mask to new input
    const phoneInput = contactDiv.querySelector('input[type="tel"]');
    phoneInput.addEventListener('input', formatPhone);
}

// Remove Contact
function removeContact(contactId) {
    const contactDiv = document.querySelector(`[data-contact="${contactId}"]`);
    if (contactDiv) {
        contactDiv.remove();
    }
}

// Add Experience
function addExperience() {
    if (experienceCount >= 3) {
        alert('Você pode adicionar no máximo 3 empresas.');
        return;
    }
    experienceCount++;
    const container = document.getElementById('experiencesContainer');
    
    const experienceDiv = document.createElement('div');
    experienceDiv.className = 'experience-card';
    experienceDiv.setAttribute('data-experience', experienceCount);
    
    experienceDiv.innerHTML = `
        <h4>Empresa ${experienceCount} <button type="button" class="btn-remove" onclick="removeExperience(${experienceCount})"><i class="fas fa-trash"></i> Remover</button></h4>
        <div class="form-row">
            <div class="form-group">
                <label>Nome da Empresa</label>
                <input type="text" name="company${experienceCount}" placeholder="Nome da empresa">
            </div>
            <div class="form-group">
                <label>Cargo/Função</label>
                <input type="text" name="position${experienceCount}" placeholder="Cargo exercido">
            </div>
            <div class="form-group">
                <label>Tempo de Trabalho</label>
                <input type="text" name="duration${experienceCount}" placeholder="Ex: 1 ano, 6 meses">
            </div>
        </div>
    `;
    
    container.appendChild(experienceDiv);
}

// Remove Experience
function removeExperience(experienceId) {
    const experienceDiv = document.querySelector(`[data-experience="${experienceId}"]`);
    if (experienceDiv) {
        experienceDiv.remove();
    }
}

// Reset Dynamic Fields
function resetDynamicFields() {
    // Reset contacts
    contactCount = 1;
    const contactsContainer = document.getElementById('contactsContainer');
    const extraContacts = contactsContainer.querySelectorAll('[data-contact]:not([data-contact="1"])');
    extraContacts.forEach(contact => contact.remove());
    
    // Reset experiences
    experienceCount = 1;
    const experiencesContainer = document.getElementById('experiencesContainer');
    const extraExperiences = experiencesContainer.querySelectorAll('[data-experience]:not([data-experience="1"])');
    extraExperiences.forEach(experience => experience.remove());
    
    // Hide add buttons
    document.getElementById('addContactBtn').style.display = 'none';
    document.getElementById('addExperienceBtn').style.display = 'none';
}

// Form Logic Setup
function setupFormLogic() {
    const form = curriculumForm;
    
    // Terms acceptance logic
    const acceptTermsRadios = form.querySelectorAll('input[name="acceptTerms"]');
    const workScheduleSection = document.getElementById('workScheduleSection');
    const rejectMessage = document.getElementById('rejectMessage');
    const personalDataSection = document.getElementById('personalDataSection');
    
    acceptTermsRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.value === 'sim, aceito') {
                showSection(workScheduleSection);
                hideSection(rejectMessage);
            } else {
                hideSection(workScheduleSection);
                showSection(rejectMessage);
                hideAllSectionsAfter('workScheduleSection');
            }
        });
    });
    
    // Work schedule logic
    const workScheduleRadios = form.querySelectorAll('input[name="workSchedule"]');
    workScheduleRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.value) {
                showSection(personalDataSection);
            }
        });
    });
    
    // Marital status logic
    const maritalStatusSelect = form.querySelector('select[name="maritalStatus"]');
    const contactSection = document.getElementById('contactSection');
    
    maritalStatusSelect.addEventListener('change', function() {
        if (this.value) {
            showSection(contactSection);
        }
    });
    
    // WhatsApp logic
    const whatsappRadios = form.querySelectorAll('input[name="isWhatsapp"]');
    const addressSection = document.getElementById('addressSection');
    
    whatsappRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.value) {
                showSection(addressSection);
                document.getElementById('addContactBtn').style.display = 'inline-block';
            }
        });
    });
    
    // Address logic
    const addressInputs = ['address', 'city', 'state'];
    const educationSection = document.getElementById('educationSection');
    
    addressInputs.forEach(inputName => {
        const input = form.querySelector(`input[name="${inputName}"]`);
        input.addEventListener('input', function() {
            if (checkAllAddressFields()) {
                showSection(educationSection);
            }
        });
    });
    
    // Education logic
    const educationSelect = form.querySelector('select[name="education"]');
    const isStudyingRadios = form.querySelectorAll('input[name="isStudying"]');
    const studyPeriodSection = document.getElementById('studyPeriodSection');
    const coursesSection = document.getElementById('coursesSection');
    
    isStudyingRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.value === 'Sim, estou!') {
                showSection(studyPeriodSection);
                hideSection(coursesSection);
            } else if (this.value === 'Não, não estou!') {
                hideSection(studyPeriodSection);
                showSection(coursesSection);
            }
        });
    });
    
    // Study period logic
    const studyPeriodSelect = form.querySelector('select[name="studyPeriod"]');
    studyPeriodSelect.addEventListener('change', function() {
        if (this.value) {
            showSection(coursesSection);
        }
    });
    
    // Courses logic
    const hasCoursesRadios = form.querySelectorAll('input[name="hasCourses"]');
    const coursesDetailSection = document.getElementById('coursesDetailSection');
    const experienceSection = document.getElementById('experienceSection');
    
    hasCoursesRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.value === 'Sim') {
                showSection(coursesDetailSection);
            } else {
                hideSection(coursesDetailSection);
                showSection(experienceSection);
            }
        });
    });
    
    // Courses detail logic
    const coursesTextarea = form.querySelector('textarea[name="courses"]');
    if (coursesTextarea) {
        coursesTextarea.addEventListener('input', function() {
            if (this.value.trim()) {
                showSection(experienceSection);
            }
        });
    }
    
    // Experience logic
    const hasExperienceRadios = form.querySelectorAll('input[name="hasExperience"]');
    const experienceDetailSection = document.getElementById('experienceDetailSection');
    const objectiveSection = document.getElementById('objectiveSection');
    
    hasExperienceRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.value === 'Sim') {
                showSection(experienceDetailSection);
                document.getElementById('addExperienceBtn').style.display = 'inline-block';
            } else {
                hideSection(experienceDetailSection);
                showSection(objectiveSection);
                document.getElementById('addExperienceBtn').style.display = 'none';
            }
        });
    });
    
    // Experience detail logic
    const experienceInputs = ['company1', 'position1', 'duration1'];
    experienceInputs.forEach(inputName => {
        const input = form.querySelector(`input[name="${inputName}"]`);
        if (input) {
            input.addEventListener('input', function() {
                if (checkAllExperienceFields()) {
                    showSection(objectiveSection);
                }
            });
        }
    });
    
    // Objective logic
    const motivationTextarea = form.querySelector('textarea[name="motivation"]');
    const filesSection = document.getElementById('filesSection');
    
    if (motivationTextarea) {
        motivationTextarea.addEventListener('input', function() {
            if (this.value.trim()) {
                showSection(filesSection);
            }
        });
    }
}

// Helper functions
function showSection(section) {
    if (section) {
        section.style.display = 'block';
        setTimeout(() => {
            section.classList.add('show');
        }, 10);
    }
}

function hideSection(section) {
    if (section) {
        section.classList.remove('show');
        setTimeout(() => {
            section.style.display = 'none';
        }, 300);
    }
}

function hideAllSectionsAfter(sectionId) {
    const sections = [
        'personalDataSection',
        'contactSection', 
        'addressSection',
        'educationSection',
        'experienceSection',
        'objectiveSection',
        'filesSection'
    ];
    
    let startHiding = false;
    sections.forEach(id => {
        if (startHiding) {
            const section = document.getElementById(id);
            hideSection(section);
        }
        if (id === sectionId) {
            startHiding = true;
        }
    });
}

function checkAllAddressFields() {
    const address = curriculumForm.querySelector('input[name="address"]').value;
    const city = curriculumForm.querySelector('input[name="city"]').value;
    const state = curriculumForm.querySelector('input[name="state"]').value;
    
    return address && city && state;
}

function checkAllExperienceFields() {
    const company1 = curriculumForm.querySelector('input[name="company1"]').value;
    const position1 = curriculumForm.querySelector('input[name="position1"]').value;
    const duration1 = curriculumForm.querySelector('input[name="duration1"]').value;
    
    return company1 && position1 && duration1;
}

function resetFormSections() {
    const sections = [
        'workScheduleSection',
        'rejectMessage',
        'personalDataSection',
        'contactSection',
        'addressSection',
        'educationSection',
        'studyPeriodSection',
        'coursesSection',
        'coursesDetailSection',
        'experienceSection',
        'experienceDetailSection',
        'objectiveSection',
        'filesSection'
    ];
    
    sections.forEach(id => {
        const section = document.getElementById(id);
        if (section) {
            hideSection(section);
        }
    });
}

// Handle Curriculum Form Submission
function handleCurriculumSubmit(e) {
    // --- HONEYPOT ANTI-SPAM CHECK ---
    var websiteField = document.querySelector('input[name="website"]');
    if (websiteField && websiteField.value.trim() !== '') {
        return;
    }
    e.preventDefault();
    
    console.log('📝 Iniciando envio do currículo (MODO API)...');
    
    const formData = new FormData(curriculumForm);
    const name = formData.get('name');
    
    // Verificar se os arquivos foram anexados
    const resumeFile = formData.get('resume');
    const photoFile = formData.get('photo');
    
    // Client-side validation for file size and type
    const maxFileSize = 15 * 1024 * 1024; // 15MB
    if (!resumeFile || resumeFile.size === 0) {
        alert('❌ Por favor, anexe o currículo em PDF.');
        return;
    }
    if (resumeFile.size > maxFileSize) {
        alert('❌ O currículo é muito grande. Tamanho máximo permitido: 15MB.');
        return;
    }
    if (!resumeFile.name.toLowerCase().endsWith('.pdf')) {
        alert('❌ O currículo deve ser um arquivo PDF.');
        return;
    }
    
    if (!photoFile || photoFile.size === 0) {
        alert('❌ Por favor, anexe uma foto.');
        return;
    }
    if (photoFile.size > maxFileSize) {
        alert('❌ A foto é muito grande. Tamanho máximo permitido: 15MB.');
        return;
    }
    const allowedPhotoExtensions = ['jpg', 'jpeg', 'png', 'gif'];
    const photoExtension = photoFile.name.split('.').pop().toLowerCase();
    if (!allowedPhotoExtensions.includes(photoExtension)) {
        alert('❌ A foto deve ser um arquivo JPG, JPEG, PNG ou GIF.');
        return;
    }
    
    console.log('📎 Arquivos verificados:', {
        curriculo: resumeFile.name + ' (' + (resumeFile.size / 1024).toFixed(1) + 'KB)',
        foto: photoFile.name + ' (' + (photoFile.size / 1024).toFixed(1) + 'KB)'
    });
    
    // Show loading
    const submitBtn = curriculumForm.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando currículo...';
    submitBtn.disabled = true;
    
    console.log('🚀 Enviando dados para process-simple.php...');
    
    // Determinar o caminho correto do arquivo PHP
    const phpPath = window.location.pathname.includes('/') 
        ? window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/') + 1) + 'process-simple.php'
        : 'process-simple.php';
    
    console.log('📍 Caminho do PHP:', phpPath);
    
    // Criar um timeout manual para a requisição
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 120000); // 120 segundos (2 minutos)
    
    // Send to PHP
    fetch(phpPath, {
        method: 'POST',
        body: formData,
        signal: controller.signal,
        // Adicionar headers para melhor compatibilidade
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        clearTimeout(timeoutId);
        console.log('📡 Resposta recebida:', response.status, response.statusText);
        
        if (!response.ok) {
            // Tentar obter mais detalhes do erro
            return response.text().then(text => {
                console.error('📄 Resposta de erro:', text);
                throw new Error(`Erro HTTP ${response.status}: ${response.statusText}. Detalhes: ${text.substring(0, 200)}`);
            });
        }
        
        return response.text().then(text => {
            console.log('📄 Resposta bruta:', text);
            
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('❌ Erro ao parsear JSON:', e);
                console.error('📄 Conteúdo recebido:', text);
                // Mostrar os primeiros 300 caracteres da resposta para ajudar no diagnóstico
                const preview = text.substring(0, 300);
                throw new Error('Resposta inválida do servidor (HTML ou erro PHP detectado).\n\nInício da resposta:\n' + preview);
            }
        });
    })
    .then(data => {
        console.log('✅ Dados processados:', data);
        
        if (data.success) {
            // Show success message
            document.getElementById('successText').textContent = 
                `${name}, seu currículo foi cadastrado com sucesso e será analisado pela nossa equipe de RH.`;
            
            document.getElementById('mainForm').style.display = 'none';
            document.getElementById('successMessage').style.display = 'flex';
            
            console.log('🎉 Currículo enviado com sucesso!');
        } else {
            console.error('❌ Erro retornado pelo servidor:', data.message);
            alert('❌ Erro ao enviar currículo: ' + data.message + '\n\nSe o problema persistir, entre em contato pelo WhatsApp (61) 3359-7358.');
        }
    })
    .catch(error => {
        clearTimeout(timeoutId);
        console.error('💥 Erro crítico na requisição fetch:', error);
        
        let errorMessage = '';
        let userMessage = '';
        
        // Identificar o tipo de erro
        if (error.name === 'AbortError') {
            errorMessage = 'Tempo limite excedido (timeout)';
            userMessage = '⏱️ O envio está demorando muito. Isso pode acontecer se:\n\n' +
                         '• Sua conexão está lenta\n' +
                         '• Os arquivos são muito grandes\n' +
                         '• O servidor está sobrecarregado\n\n' +
                         'Tente novamente com uma conexão melhor ou arquivos menores.';
        } else if (error.message.includes('Failed to fetch') || error.message.includes('NetworkError')) {
            errorMessage = 'Falha na conexão de rede';
            userMessage = '🌐 Não foi possível conectar ao servidor. Verifique:\n\n' +
                         '• Sua conexão com a internet está funcionando?\n' +
                         '• O servidor está online?\n' +
                         '• Há algum firewall ou antivírus bloqueando?\n\n' +
                         'Tente novamente em alguns instantes.';
        } else if (error.message.includes('HTTP')) {
            errorMessage = error.message;
            userMessage = '⚠️ Erro no servidor:\n\n' + error.message + '\n\n' +
                         'Entre em contato com o suporte informando este erro.';
        } else {
            errorMessage = error.message;
            userMessage = '❌ Erro ao processar sua solicitação:\n\n' + error.message;
        }
        
        console.error('📋 Diagnóstico:', errorMessage);
        alert(userMessage + '\n\n📞 Suporte: WhatsApp (61) 3359-7358');
    })
    .finally(() => {
        // Reset button
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
        console.log('🔄 Botão resetado');
    });
}

// Phone number formatting
function formatPhone(e) {
    let value = e.target.value.replace(/\D/g, '');
    if (value.length >= 11) {
        value = value.replace(/(\d{2})(\d{5})(\d{4})/, '($1)$2-$3');
    } else if (value.length >= 7) {
        value = value.replace(/(\d{2})(\d{4})(\d{0,4})/, '($1)$2-$3');
    } else if (value.length >= 3) {
        value = value.replace(/(\d{2})(\d{0,5})/, '($1)$2');
    }
    e.target.value = value;
}

// Apply phone formatting to existing phone inputs
document.addEventListener('input', function(e) {
    if (e.target.type === 'tel') {
        formatPhone(e);
    }
});

// Log Access
function logAccess() {
    fetch('log_access.php', { method: 'POST' })
        .catch(err => console.error('Erro ao logar acesso:', err));
}

// Debug: Log when script loads
console.log('📜 Script.js carregado com sucesso (MODO API).');

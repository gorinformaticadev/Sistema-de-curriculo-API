// ============ POLYFILLS DE COMPATIBILIDADE (Android/iOS/navegadores antigos) ============

// Array.prototype.includes (Chrome <47, Safari <9)
if (!Array.prototype.includes) {
    Array.prototype.includes = function(search) {
        return this.indexOf(search) !== -1;
    };
}

// String.prototype.includes (Chrome <41)
if (!String.prototype.includes) {
    String.prototype.includes = function(search, start) {
        return this.indexOf(search, start || 0) !== -1;
    };
}

// NodeList.forEach (Chrome <51)
if (window.NodeList && !NodeList.prototype.forEach) {
    NodeList.prototype.forEach = Array.prototype.forEach;
}

// Promise.prototype.finally (Chrome <63, Safari <11.1, Firefox <58)
if (window.Promise && typeof Promise.prototype.finally !== 'function') {
    Promise.prototype.finally = function(callback) {
        var P = this.constructor;
        return this.then(
            function(value) { return P.resolve(callback()).then(function() { return value; }); },
            function(reason) { return P.resolve(callback()).then(function() { throw reason; }); }
        );
    };
}

// fetch via XMLHttpRequest (Chrome <42, Safari <10.1, Firefox <39, navegadores antigos)
if (typeof window.fetch !== 'function') {
    window.fetch = function(url, options) {
        options = options || {};
        return new Promise(function(resolve, reject) {
            var xhr = new XMLHttpRequest();
            xhr.open(options.method || 'GET', url, true);
            if (options.headers) {
                Object.keys(options.headers).forEach(function(key) {
                    xhr.setRequestHeader(key, options.headers[key]);
                });
            }
            xhr.onload = function() {
                resolve({
                    ok: xhr.status >= 200 && xhr.status < 300,
                    status: xhr.status,
                    statusText: xhr.statusText,
                    text: function() { return Promise.resolve(xhr.responseText); },
                    json: function() {
                        return new Promise(function(resolveJson, rejectJson) {
                            try { resolveJson(JSON.parse(xhr.responseText)); }
                            catch (err) { rejectJson(err); }
                        });
                    }
                });
            };
            xhr.onerror = function() { reject(new Error('NetworkError')); };
            xhr.ontimeout = function() { reject(new Error('NetworkError')); };
            xhr.send(options.body || null);
        });
    };
}

// Scroll suave com fallback para navegadores antigos
function scrollToElement(el) {
    if (!el) return;
    try {
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    } catch (err) {
        el.scrollIntoView(true);
    }
}

// Global variables
let contactCount = 1;
let experienceCount = 1;
let referenceCount = 1;

// DOM Elements
const curriculumForm = document.getElementById('curriculumForm');
const newRegistrationBtn = document.getElementById('newRegistration');

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    logAccess();
    initializeEventListeners();
    setupFormLogic();
    // Sincroniza a visibilidade das seções com os dados já preenchidos
    // (o navegador restaura valores ao atualizar a página)
    syncFormVisibility();
});

// Event Listeners
function initializeEventListeners() {
    // Curriculum form
    curriculumForm.addEventListener('submit', handleCurriculumSubmit);

    // New registration button
    newRegistrationBtn.addEventListener('click', () => {
        document.getElementById('successMessage').style.display = 'none';
        document.getElementById('mainForm').style.display = 'block';
        // Remover aviso de confirmação anterior
        const oldWarning = document.getElementById('confirmationWarning');
        if (oldWarning) oldWarning.remove();
        curriculumForm.reset();
        resetFormSections();
        resetDynamicFields();
        
        // Resetar o tracker para nova sessão
        if (window.formTracker) {
            window.formTracker.formSubmitted = false;
            window.formTracker.interactions = [];
            window.formTracker.lastField = null;
            window.formTracker.userName = null;
            // Gerar nova sessão
            sessionStorage.removeItem('form_session_id');
            window.formTracker.sessionId = window.formTracker.generateSessionId();
            // Registrar novo acesso
            window.formTracker.trackFormAccess();
        }
    });

    // Add contact button
    document.getElementById('addContactBtn').addEventListener('click', addContact);

    // Add experience button
    document.getElementById('addExperienceBtn').addEventListener('click', addExperience);

    // Add reference button
    document.getElementById('addReferenceBtn').addEventListener('click', addReference);

    // Continue salary button -> mostra documentos e consentimento final (com o botão de envio)
    document.getElementById('continueSalaryBtn').addEventListener('click', function() {
        showSection(document.getElementById('filesSection'));
        showSection(document.getElementById('finalConsentSection'));
    });

    // Pretensão "A combinar"
    const aCombinarCheckbox = document.getElementById('pretensaoACombinar');
    const pretensaoInput = document.getElementById('pretensaoSalarial');
    aCombinarCheckbox.addEventListener('change', function() {
        if (this.checked) {
            pretensaoInput.disabled = true;
            pretensaoInput.value = '';
        } else {
            pretensaoInput.disabled = false;
        }
    });

    // Delegated: empregado atual controla "Motivo da saída" e o bloco "por que está saindo"
    document.addEventListener('change', function(e) {
        if (e.target.matches('select[data-current-job]')) {
            const id = e.target.getAttribute('data-current-job');
            const reasonBlock = document.querySelector(`[data-current-reason="${id}"]`);
            const motivoSaidaGroup = document.querySelector(`[data-motivo-saida="${id}"]`);
            
            if (e.target.value === 'Sim') {
                // Ainda trabalha lá: oculta "Motivo da saída" e mostra "por que está saindo"
                if (reasonBlock) reasonBlock.style.display = 'block';
                if (motivoSaidaGroup) {
                    motivoSaidaGroup.style.display = 'none';
                    const motivoInput = motivoSaidaGroup.querySelector('input');
                    if (motivoInput) motivoInput.value = '';
                }
            } else {
                // Já saiu: mostra "Motivo da saída" e oculta o bloco atual
                if (reasonBlock) reasonBlock.style.display = 'none';
                if (motivoSaidaGroup) motivoSaidaGroup.style.display = 'block';
            }
        }
    });
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

// Add Experience (sem limite rígido de 3 — várias experiências permitidas)
function addExperience() {
    if (experienceCount >= 10) {
        alert('Você pode adicionar no máximo 10 experiências. Caso precise, inclua as demais no currículo em PDF.');
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
        <div class="form-group">
            <label>Principais atividades</label>
            <textarea name="atividades${experienceCount}" rows="2" placeholder="Descreva brevemente o que você fazia"></textarea>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Você ainda trabalha nesta empresa?</label>
                <select name="empregadoAtual${experienceCount}" data-current-job="${experienceCount}">
                    <option value="Não">Não</option>
                    <option value="Sim">Sim, ainda trabalho lá</option>
                </select>
            </div>
            <div class="form-group" data-motivo-saida="${experienceCount}">
                <label>Motivo da saída</label>
                <input type="text" name="motivoSaida${experienceCount}" placeholder="Ex.: busca de novos desafios">
            </div>
        </div>
        <div class="form-group current-job-reason" data-current-reason="${experienceCount}" style="display: none;">
            <label>Se você ainda está na empresa, por que está saindo dela?</label>
            <textarea name="motivoSaidaAtual${experienceCount}" rows="2" placeholder="Conte o motivo de estar procurando outra oportunidade"></textarea>
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

// Add Reference (máximo 2)
function addReference() {
    if (referenceCount >= 2) {
        alert('Você pode adicionar no máximo 2 referências profissionais.');
        return;
    }
    referenceCount++;
    const container = document.getElementById('referencesContainer');
    
    const referenceDiv = document.createElement('div');
    referenceDiv.className = 'reference-card';
    referenceDiv.setAttribute('data-reference', referenceCount);
    
    referenceDiv.innerHTML = `
        <h4>Referência ${referenceCount} <button type="button" class="btn-remove" onclick="removeReference(${referenceCount})"><i class="fas fa-trash"></i> Remover</button></h4>
        <div class="form-row">
            <div class="form-group">
                <label>Nome</label>
                <input type="text" name="refNome${referenceCount}" placeholder="Nome da pessoa">
            </div>
            <div class="form-group">
                <label>Empresa</label>
                <input type="text" name="refEmpresa${referenceCount}" placeholder="Empresa onde trabalhou">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Cargo</label>
                <input type="text" name="refCargo${referenceCount}" placeholder="Cargo da pessoa">
            </div>
            <div class="form-group">
                <label>Telefone</label>
                <input type="tel" name="refTelefone${referenceCount}" placeholder="(00)00000-0000">
            </div>
        </div>
    `;
    
    container.appendChild(referenceDiv);
    
    const phoneInput = referenceDiv.querySelector('input[type="tel"]');
    phoneInput.addEventListener('input', formatPhone);
}

// Remove Reference
function removeReference(referenceId) {
    const referenceDiv = document.querySelector(`[data-reference="${referenceId}"]`);
    if (referenceDiv) {
        referenceDiv.remove();
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
    
    // Reset references
    referenceCount = 1;
    const referencesContainer = document.getElementById('referencesContainer');
    const extraReferences = referencesContainer.querySelectorAll('[data-reference]:not([data-reference="1"])');
    extraReferences.forEach(reference => reference.remove());
    
    // Hide add buttons
    document.getElementById('addContactBtn').style.display = 'none';
}

// Form Logic Setup
function setupFormLogic() {
    const form = curriculumForm;
    
    // --- 1. CONSENTIMENTO LGPD ---
    const consentimentoLgpdCheckbox = form.querySelector('input[name="consentimentoLgpd"]');
    const personalDataSection = document.getElementById('personalDataSection');
    
    consentimentoLgpdCheckbox.addEventListener('change', function() {
        if (this.checked) {
            showSection(personalDataSection);
        } else {
            hideAllSectionsAfter('lgpdSection');
        }
    });
    
    // --- 2. DADOS PESSOAIS -> CONTATO ---
    const hasChildrenRadios = form.querySelectorAll('input[name="hasChildren"]');
    const contactSection = document.getElementById('contactSection');
    
    hasChildrenRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.value) {
                showSection(contactSection);
            }
        });
    });
    
    // --- 3. CONTATO -> ENDEREÇO ---
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
    
    // --- 4. ENDEREÇO -> DISPONIBILIDADE ---
    const addressInputs = ['address', 'city', 'state'];
    const availabilitySection = document.getElementById('availabilitySection');
    
    addressInputs.forEach(inputName => {
        const input = form.querySelector(`input[name="${inputName}"]`);
        input.addEventListener('input', function() {
            if (checkAllAddressFields()) {
                showSection(availabilitySection);
            }
        });
    });
    
    // --- 5. DISPONIBILIDADE -> FORMAÇÃO ---
    const disponibilidadeInicioRadios = form.querySelectorAll('input[name="disponibilidadeInicio"]');
    const availabilityDateSection = document.getElementById('availabilityDateSection');
    const disponibilidadeOutraDataInput = form.querySelector('input[name="disponibilidadeOutraData"]');
    const disponibilidadeSabadosRadios = form.querySelectorAll('input[name="disponibilidadeSabados"]');
    const disponibilidadeHorasExtrasRadios = form.querySelectorAll('input[name="disponibilidadeHorasExtras"]');
    const educationSection = document.getElementById('educationSection');
    
    disponibilidadeInicioRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.value === 'Outra data') {
                showSection(availabilityDateSection);
            } else {
                hideSection(availabilityDateSection);
            }
            checkAvailabilityFields();
        });
    });
    
    if (disponibilidadeOutraDataInput) {
        disponibilidadeOutraDataInput.addEventListener('input', checkAvailabilityFields);
    }
    
    disponibilidadeSabadosRadios.forEach(radio => {
        radio.addEventListener('change', checkAvailabilityFields);
    });
    
    disponibilidadeHorasExtrasRadios.forEach(radio => {
        radio.addEventListener('change', checkAvailabilityFields);
    });
    
    // --- 6. FORMAÇÃO -> CURSOS ---
    const isStudyingRadios = form.querySelectorAll('input[name="isStudying"]');
    const studyDetailSection = document.getElementById('studyDetailSection');
    const coursesSection = document.getElementById('coursesSection');
    
    isStudyingRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.value === 'Sim, estou!') {
                showSection(studyDetailSection);
                hideSection(coursesSection);
            } else if (this.value === 'Não, não estou!') {
                hideSection(studyDetailSection);
                showSection(coursesSection);
            }
        });
    });
    
    // Detalhes de estudo (período, curso, instituição, situação, ano)
    const studyDetailInputs = ['studyPeriod', 'cursoAtual', 'instituicaoCurso', 'anoConclusaoCurso'];
    studyDetailInputs.forEach(inputName => {
        const input = form.querySelector(`input[name="${inputName}"], select[name="${inputName}"]`);
        if (input) {
            input.addEventListener('input', checkStudyDetailFields);
            input.addEventListener('change', checkStudyDetailFields);
        }
    });
    // Detalhes de estudo (período, curso, instituição, situação, ano)
    const situacaoCursoRadios = form.querySelectorAll('input[name="situacaoCurso"]');
    const anoConclusaoSection = document.getElementById('anoConclusaoSection');
    
    situacaoCursoRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            // "Ano de conclusão ou previsão" só aparece quando "Em andamento" estiver marcado
            if (this.value === 'Em andamento') {
                showSection(anoConclusaoSection);
            } else {
                hideSection(anoConclusaoSection);
                const anoInput = form.querySelector('input[name="anoConclusaoCurso"]');
                if (anoInput) anoInput.value = '';
            }
            checkStudyDetailFields();
        });
    });
    
    // --- 7. CURSOS -> HABILIDADES ---
    const hasCoursesRadios = form.querySelectorAll('input[name="hasCourses"]');
    const coursesDetailSection = document.getElementById('coursesDetailSection');
    const skillsSection = document.getElementById('skillsSection');
    
    hasCoursesRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.value === 'Sim') {
                showSection(coursesDetailSection);
            } else {
                hideSection(coursesDetailSection);
                showSection(skillsSection);
            }
        });
    });
    
    const coursesTextarea = form.querySelector('textarea[name="courses"]');
    if (coursesTextarea) {
        coursesTextarea.addEventListener('input', function() {
            if (this.value.trim()) {
                showSection(skillsSection);
            }
        });
    }
    
    // --- 8. HABILIDADES -> EXPERIÊNCIA ---
    const habilidadeCheckboxes = form.querySelectorAll('input[name="habilidades[]"]');
    const habilidadeOutraCheckbox = document.getElementById('habilidadeOutraCheckbox');
    const habilidadeOutraSection = document.getElementById('habilidadeOutraSection');
    const habilidadeOutraInput = form.querySelector('input[name="habilidadeOutra"]');
    const conhecimentoInformaticaRadios = form.querySelectorAll('input[name="conhecimentoInformatica"]');
    const experienceSection = document.getElementById('experienceSection');
    
    habilidadeCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            if (this.id === 'habilidadeOutraCheckbox') {
                if (this.checked) {
                    showSection(habilidadeOutraSection);
                } else {
                    hideSection(habilidadeOutraSection);
                    if (habilidadeOutraInput) habilidadeOutraInput.value = '';
                }
            }
            checkSkillsFields();
        });
    });
    
    if (habilidadeOutraInput) {
        habilidadeOutraInput.addEventListener('input', checkSkillsFields);
    }
    
    conhecimentoInformaticaRadios.forEach(radio => {
        radio.addEventListener('change', checkSkillsFields);
    });
    
    // --- 9. EXPERIÊNCIA -> INFORMAÇÕES COMPLEMENTARES ---
    const hasExperienceRadios = form.querySelectorAll('input[name="hasExperience"]');
    const experienceDetailSection = document.getElementById('experienceDetailSection');
    const firstJobSection = document.getElementById('firstJobSection');
    const additionalInfoSection = document.getElementById('additionalInfoSection');
    
    hasExperienceRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.value === 'Sim') {
                showSection(experienceDetailSection);
                hideSection(firstJobSection);
            } else {
                hideSection(experienceDetailSection);
                showSection(firstJobSection);
            }
        });
    });
    
    // Primeira experiência (obrigatória) libera informações complementares
    const experienceInputs = ['company1', 'position1', 'duration1'];
    experienceInputs.forEach(inputName => {
        const input = form.querySelector(`input[name="${inputName}"]`);
        if (input) {
            input.addEventListener('input', function() {
                if (checkAllExperienceFields()) {
                    showSection(additionalInfoSection);
                }
            });
        }
    });
    
    // Primeiro emprego: expectativa libera informações complementares
    const expectativaTextarea = form.querySelector('textarea[name="expectativaPrimeiroEmprego"]');
    if (expectativaTextarea) {
        expectativaTextarea.addEventListener('input', function() {
            if (this.value.trim()) {
                showSection(additionalInfoSection);
            }
        });
    }
    
    // --- 10. INFORMAÇÕES COMPLEMENTARES -> OBJETIVO ---
    const comoConheceuRadios = form.querySelectorAll('input[name="comoConheceu"]');
    const comoConheceuOutroSection = document.getElementById('comoConheceuOutroSection');
    const comoConheceuOutroInput = form.querySelector('input[name="comoConheceuOutro"]');
    const objectiveSection = document.getElementById('objectiveSection');
    
    comoConheceuRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.value === 'Outro') {
                showSection(comoConheceuOutroSection);
            } else {
                hideSection(comoConheceuOutroSection);
                if (comoConheceuOutroInput) comoConheceuOutroInput.value = '';
            }
            checkAdditionalInfoFields();
        });
    });
    
    if (comoConheceuOutroInput) {
        comoConheceuOutroInput.addEventListener('input', checkAdditionalInfoFields);
    }
    
    // Referências (opcional)
    const possuiReferenciaRadios = form.querySelectorAll('input[name="possuiReferencia"]');
    const referencesSection = document.getElementById('referencesSection');
    
    possuiReferenciaRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.value === 'Sim') {
                showSection(referencesSection);
            } else {
                hideSection(referencesSection);
            }
        });
    });
    
    // --- 11. OBJETIVO -> PRETENSÃO SALARIAL ---
    const motivationTextarea = form.querySelector('textarea[name="motivation"]');
    const salarySection = document.getElementById('salarySection');
    
    if (motivationTextarea) {
        motivationTextarea.addEventListener('input', function() {
            if (this.value.trim()) {
                showSection(salarySection);
            }
        });
    }
    
    // --- 15. CONSENTIMENTO FINAL ---
    const acceptTermsRadios = form.querySelectorAll('input[name="acceptTerms"]');
    const rejectMessage = document.getElementById('rejectMessage');
    
    acceptTermsRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.value === 'sim, aceito') {
                hideSection(rejectMessage);
            } else {
                showSection(rejectMessage);
            }
        });
    });
}

// Sincroniza a visibilidade das seções com os valores já preenchidos.
// Necessário porque o navegador restaura dados do formulário ao atualizar
// a página, mas os gatilhos progressivos só disparam em eventos input/change.
function syncFormVisibility() {
    const form = curriculumForm;
    
    // 1. Consentimento LGPD -> Dados Pessoais
    const consentimentoLgpd = form.querySelector('input[name="consentimentoLgpd"]');
    if (consentimentoLgpd && consentimentoLgpd.checked) {
        showSection(document.getElementById('personalDataSection'));
    }
    
    // 2. Possui filhos -> Contato
    if (radioIsChecked('hasChildren')) {
        showSection(document.getElementById('contactSection'));
    }
    
    // 3. WhatsApp -> Endereço
    if (radioIsChecked('isWhatsapp')) {
        showSection(document.getElementById('addressSection'));
        document.getElementById('addContactBtn').style.display = 'inline-block';
    }
    
    // 4. Endereço preenchido -> Disponibilidade
    if (checkAllAddressFields()) {
        showSection(document.getElementById('availabilitySection'));
    }
    
    // 5. Disponibilidade -> Formação
    if (radioValue('disponibilidadeInicio') === 'Outra data') {
        showSection(document.getElementById('availabilityDateSection'));
    }
    checkAvailabilityFields();
    
    // 6. Formação -> Cursos
    const isStudyingValue = radioValue('isStudying');
    if (isStudyingValue === 'Sim, estou!') {
        showSection(document.getElementById('studyDetailSection'));
        // Ano de conclusão só aparece com "Em andamento"
        if (radioValue('situacaoCurso') === 'Em andamento') {
            showSection(document.getElementById('anoConclusaoSection'));
        }
        checkStudyDetailFields();
    } else if (isStudyingValue === 'Não, não estou!') {
        showSection(document.getElementById('coursesSection'));
    }
    
    // 7. Cursos -> Habilidades
    const hasCoursesValue = radioValue('hasCourses');
    if (hasCoursesValue === 'Sim') {
        showSection(document.getElementById('coursesDetailSection'));
        if (fieldHasValue('courses')) {
            showSection(document.getElementById('skillsSection'));
        }
    } else if (hasCoursesValue === 'Não') {
        showSection(document.getElementById('skillsSection'));
    }
    
    // 8. Habilidades -> Experiência
    const habilidadeOutraCheckbox = document.getElementById('habilidadeOutraCheckbox');
    if (habilidadeOutraCheckbox && habilidadeOutraCheckbox.checked) {
        showSection(document.getElementById('habilidadeOutraSection'));
    }
    checkSkillsFields();
    
    // 9. Experiência -> Informações Complementares
    const hasExperienceValue = radioValue('hasExperience');
    if (hasExperienceValue === 'Sim') {
        showSection(document.getElementById('experienceDetailSection'));
        if (checkAllExperienceFields()) {
            showSection(document.getElementById('additionalInfoSection'));
        }
    } else if (hasExperienceValue === 'Não, procuro primeiro emprego') {
        showSection(document.getElementById('firstJobSection'));
        if (fieldHasValue('expectativaPrimeiroEmprego')) {
            showSection(document.getElementById('additionalInfoSection'));
        }
    }
    
    // 10. Complementares -> Objetivo
    if (radioValue('comoConheceu') === 'Outro') {
        showSection(document.getElementById('comoConheceuOutroSection'));
    }
    checkAdditionalInfoFields();
    
    // Referências profissionais
    if (radioValue('possuiReferencia') === 'Sim') {
        showSection(document.getElementById('referencesSection'));
    }
    
    // 11. Objetivo -> Pretensão Salarial
    if (fieldHasValue('motivation')) {
        showSection(document.getElementById('salarySection'));
    }
    
    // Estado visual dos campos condicionais
    const aCombinar = document.getElementById('pretensaoACombinar');
    const pretensaoInput = document.getElementById('pretensaoSalarial');
    if (aCombinar && pretensaoInput) {
        pretensaoInput.disabled = aCombinar.checked;
    }
    
    // Estado "ainda trabalha lá?" da experiência 1:
    // "Sim" oculta "Motivo da saída" e mostra o bloco "por que está saindo"
    const currentJobSelect = form.querySelector('select[data-current-job="1"]');
    if (currentJobSelect) {
        const reasonBlock = form.querySelector('[data-current-reason="1"]');
        const motivoSaidaGroup = form.querySelector('[data-motivo-saida="1"]');
        if (currentJobSelect.value === 'Sim') {
            if (reasonBlock) reasonBlock.style.display = 'block';
            if (motivoSaidaGroup) motivoSaidaGroup.style.display = 'none';
        } else {
            if (reasonBlock) reasonBlock.style.display = 'none';
            if (motivoSaidaGroup) motivoSaidaGroup.style.display = 'block';
        }
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
        'lgpdSection',
        'personalDataSection',
        'contactSection', 
        'addressSection',
        'availabilitySection',
        'availabilityDateSection',
        'educationSection',
        'studyDetailSection',
        'anoConclusaoSection',
        'coursesSection',
        'coursesDetailSection',
        'skillsSection',
        'habilidadeOutraSection',
        'experienceSection',
        'experienceDetailSection',
        'firstJobSection',
        'additionalInfoSection',
        'comoConheceuOutroSection',
        'referencesSection',
        'objectiveSection',
        'salarySection',
        'filesSection',
        'finalConsentSection'
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

function checkAvailabilityFields() {
    const inicio = curriculumForm.querySelector('input[name="disponibilidadeInicio"]:checked');
    const sabados = curriculumForm.querySelector('input[name="disponibilidadeSabados"]:checked');
    const horasExtras = curriculumForm.querySelector('input[name="disponibilidadeHorasExtras"]:checked');
    
    if (!inicio || !sabados || !horasExtras) return false;
    
    // Se "Outra data", a data deve estar preenchida
    if (inicio.value === 'Outra data') {
        const outraData = curriculumForm.querySelector('input[name="disponibilidadeOutraData"]');
        if (!outraData || !outraData.value) return false;
    }
    
    showSection(document.getElementById('educationSection'));
    return true;
}

function checkStudyDetailFields() {
    const studyPeriod = curriculumForm.querySelector('select[name="studyPeriod"]');
    const cursoAtual = curriculumForm.querySelector('input[name="cursoAtual"]');
    const instituicaoCurso = curriculumForm.querySelector('input[name="instituicaoCurso"]');
    const situacaoCurso = curriculumForm.querySelector('input[name="situacaoCurso"]:checked');
    const anoConclusaoCurso = curriculumForm.querySelector('input[name="anoConclusaoCurso"]');
    
    if (!studyPeriod || !cursoAtual || !instituicaoCurso || !situacaoCurso || !anoConclusaoCurso) return false;
    
    // O ano só é obrigatório quando o curso está "Em andamento"
    const anoObrigatorio = situacaoCurso.value === 'Em andamento';
    
    if (studyPeriod.value && cursoAtual.value.trim() && instituicaoCurso.value.trim() 
        && situacaoCurso.value && (!anoObrigatorio || anoConclusaoCurso.value.trim())) {
        showSection(document.getElementById('coursesSection'));
        return true;
    }
    return false;
}

function checkSkillsFields() {
    const checkedHabilidades = curriculumForm.querySelectorAll('input[name="habilidades[]"]:checked');
    const informatica = curriculumForm.querySelector('input[name="conhecimentoInformatica"]:checked');
    
    if (checkedHabilidades.length === 0 || !informatica) return false;
    
    // Se "Outra" foi marcada, o campo de descrição deve estar preenchido
    const outraChecked = document.getElementById('habilidadeOutraCheckbox');
    if (outraChecked && outraChecked.checked) {
        const outraInput = curriculumForm.querySelector('input[name="habilidadeOutra"]');
        if (!outraInput || !outraInput.value.trim()) return false;
    }
    
    showSection(document.getElementById('experienceSection'));
    return true;
}

function checkAllExperienceFields() {
    const company1 = curriculumForm.querySelector('input[name="company1"]').value;
    const position1 = curriculumForm.querySelector('input[name="position1"]').value;
    const duration1 = curriculumForm.querySelector('input[name="duration1"]').value;
    
    return company1 && position1 && duration1;
}

function checkAdditionalInfoFields() {
    const comoConheceu = curriculumForm.querySelector('input[name="comoConheceu"]:checked');
    if (!comoConheceu) return false;
    
    if (comoConheceu.value === 'Outro') {
        const outroInput = curriculumForm.querySelector('input[name="comoConheceuOutro"]');
        if (!outroInput || !outroInput.value.trim()) return false;
    }
    
    showSection(document.getElementById('objectiveSection'));
    return true;
}

function resetFormSections() {
    const sections = [
        'rejectMessage',
        'personalDataSection',
        'contactSection',
        'addressSection',
        'availabilitySection',
        'availabilityDateSection',
        'educationSection',
        'studyDetailSection',
        'anoConclusaoSection',
        'coursesSection',
        'coursesDetailSection',
        'skillsSection',
        'habilidadeOutraSection',
        'experienceSection',
        'experienceDetailSection',
        'firstJobSection',
        'additionalInfoSection',
        'comoConheceuOutroSection',
        'referencesSection',
        'objectiveSection',
        'salarySection',
        'filesSection',
        'finalConsentSection'
    ];
    
    sections.forEach(id => {
        const section = document.getElementById(id);
        if (section) {
            hideSection(section);
        }
    });
    
    // Limpar erros de validação
    clearValidationErrors();
}
// --- VALIDAÇÃO DO FORMULÁRIO (aviso no final + destaque nos campos) ---

function isSectionVisible(sectionId) {
    const section = document.getElementById(sectionId);
    return !!section && section.style.display !== 'none';
}

function fieldHasValue(name) {
    const el = curriculumForm.querySelector(`[name="${name}"]`);
    return !!el && el.value && el.value.trim() !== '';
}

function radioIsChecked(name) {
    return !!curriculumForm.querySelector(`[name="${name}"]:checked`);
}

function radioValue(name) {
    const el = curriculumForm.querySelector(`[name="${name}"]:checked`);
    return el ? el.value : '';
}

function markFieldError(el) {
    if (!el) return;
    const group = el.closest('.form-group') || el;
    group.classList.add('field-error');
}

function clearValidationErrors() {
    curriculumForm.querySelectorAll('.field-error').forEach(el => el.classList.remove('field-error'));
    const summary = document.getElementById('validationSummary');
    if (summary) summary.style.display = 'none';
}

function showValidationSummary(errors) {
    const summary = document.getElementById('validationSummary');
    const list = document.getElementById('validationList');
    if (!summary || !list) return;
    list.innerHTML = errors.map(msg => `<li>${msg}</li>`).join('');
    summary.style.display = 'block';
    setTimeout(function() {
        scrollToElement(summary);
    }, 100);
}

function validateForm() {
    const errors = [];
    const maxFileSize = 15 * 1024 * 1024; // 15MB
    const addError = (name, msg) => {
        markFieldError(curriculumForm.querySelector(`[name="${name}"]`));
        errors.push(msg);
    };

    // 1. Consentimento LGPD (sempre obrigatório)
    const consentimentoLgpd = curriculumForm.querySelector('input[name="consentimentoLgpd"]');
    if (consentimentoLgpd && !consentimentoLgpd.checked) {
        addError('consentimentoLgpd', 'Aceite o consentimento para tratamento dos seus dados pessoais (LGPD).');
    }

    // 2. Dados Pessoais
    if (isSectionVisible('personalDataSection')) {
        if (!fieldHasValue('name')) addError('name', 'Preencha o nome completo.');
        if (!fieldHasValue('birthDate')) addError('birthDate', 'Informe a data de nascimento.');
        if (!fieldHasValue('maritalStatus')) addError('maritalStatus', 'Selecione o estado civil.');
        if (!radioIsChecked('hasChildren')) addError('hasChildren', 'Informe se possui filhos.');
    }

    // 3. Contato
    if (isSectionVisible('contactSection')) {
        const phoneEl = curriculumForm.querySelector('input[name="phone"]');
        if (!fieldHasValue('phone')) {
            addError('phone', 'Informe o telefone.');
        } else if (phoneEl && !phoneEl.checkValidity()) {
            addError('phone', 'Telefone em formato inválido. Use o padrão (00)00000-0000.');
        }
    }

    // 4. Endereço
    if (isSectionVisible('addressSection')) {
        if (!fieldHasValue('address')) addError('address', 'Informe o endereço completo.');
        if (!fieldHasValue('city')) addError('city', 'Informe a cidade.');
        if (!fieldHasValue('state')) addError('state', 'Informe o estado.');
    }

    // 5. Disponibilidade
    if (isSectionVisible('availabilitySection')) {
        if (!radioIsChecked('disponibilidadeInicio')) {
            addError('disponibilidadeInicio', 'Informe quando você poderia começar.');
        } else if (radioValue('disponibilidadeInicio') === 'Outra data' && !fieldHasValue('disponibilidadeOutraData')) {
            addError('disponibilidadeOutraData', 'Informe a data em que você poderia começar.');
        }
        if (!radioIsChecked('disponibilidadeSabados')) addError('disponibilidadeSabados', 'Informe sua disponibilidade para trabalhar aos sábados.');
        if (!radioIsChecked('disponibilidadeHorasExtras')) addError('disponibilidadeHorasExtras', 'Informe sua disponibilidade para horas extras.');
    }

    // 6. Formação
    if (isSectionVisible('educationSection')) {
        if (!fieldHasValue('education')) addError('education', 'Selecione a escolaridade.');
        if (!radioIsChecked('isStudying')) {
            addError('isStudying', 'Informe se está estudando.');
        } else if (radioValue('isStudying') === 'Sim, estou!') {
            if (!fieldHasValue('studyPeriod')) addError('studyPeriod', 'Selecione o período de estudo.');
            if (!fieldHasValue('cursoAtual')) addError('cursoAtual', 'Informe o curso que você está fazendo.');
            if (!fieldHasValue('instituicaoCurso')) addError('instituicaoCurso', 'Informe a instituição de ensino.');
            if (!radioIsChecked('situacaoCurso')) addError('situacaoCurso', 'Informe a situação do curso.');
            // Ano só é obrigatório quando o curso está "Em andamento"
            if (radioValue('situacaoCurso') === 'Em andamento' && !fieldHasValue('anoConclusaoCurso')) {
                addError('anoConclusaoCurso', 'Informe o ano de conclusão ou previsão.');
            }
        }
    }

    // 7. Cursos e Certificações
    if (isSectionVisible('coursesSection')) {
        if (!radioIsChecked('hasCourses')) {
            addError('hasCourses', 'Informe se possui algum curso.');
        } else if (radioValue('hasCourses') === 'Sim' && !fieldHasValue('courses')) {
            addError('courses', 'Descreva os cursos que você possui.');
        }
    }

    // 8. Habilidades
    if (isSectionVisible('skillsSection')) {
        const checkedHabilidades = curriculumForm.querySelectorAll('input[name="habilidades[]"]:checked');
        if (checkedHabilidades.length === 0) {
            markFieldError(document.getElementById('habilidadesGrid'));
            errors.push('Selecione pelo menos uma habilidade.');
        } else {
            const outraChecked = document.getElementById('habilidadeOutraCheckbox');
            if (outraChecked && outraChecked.checked && !fieldHasValue('habilidadeOutra')) {
                addError('habilidadeOutra', 'Descreva a outra habilidade.');
            }
        }
        if (!radioIsChecked('conhecimentoInformatica')) addError('conhecimentoInformatica', 'Informe seu conhecimento em informática.');
    }

    // 9. Experiência Profissional
    if (isSectionVisible('experienceSection')) {
        if (!radioIsChecked('hasExperience')) {
            addError('hasExperience', 'Informe se possui experiência profissional.');
        } else if (radioValue('hasExperience') === 'Sim') {
            if (!fieldHasValue('company1')) addError('company1', 'Informe o nome da empresa (Experiência 1).');
            if (!fieldHasValue('position1')) addError('position1', 'Informe o cargo/função (Experiência 1).');
            if (!fieldHasValue('duration1')) addError('duration1', 'Informe o tempo de trabalho (Experiência 1).');
        } else if (!fieldHasValue('expectativaPrimeiroEmprego')) {
            addError('expectativaPrimeiroEmprego', 'Conte o que você espera aprender no seu primeiro emprego.');
        }
    }

    // 10. Informações Complementares
    if (isSectionVisible('additionalInfoSection')) {
        if (!radioIsChecked('comoConheceu')) {
            addError('comoConheceu', 'Informe como ficou sabendo desta oportunidade.');
        } else if (radioValue('comoConheceu') === 'Outro' && !fieldHasValue('comoConheceuOutro')) {
            addError('comoConheceuOutro', 'Especifique como ficou sabendo da oportunidade.');
        }
    }

    // 11. Objetivo Profissional
    if (isSectionVisible('objectiveSection') && !fieldHasValue('motivation')) {
        addError('motivation', 'Conte por que você gostaria de trabalhar na nossa empresa.');
    }

    // 13/14. Currículo e Foto
    if (isSectionVisible('filesSection')) {
        const resume = curriculumForm.querySelector('input[name="resume"]');
        const photo = curriculumForm.querySelector('input[name="photo"]');
        if (!resume.files || resume.files.length === 0) {
            addError('resume', 'Anexe o currículo em PDF.');
        } else {
            const file = resume.files[0];
            if (!file.name.toLowerCase().endsWith('.pdf')) {
                addError('resume', 'O currículo deve ser um arquivo PDF.');
            } else if (file.size > maxFileSize) {
                addError('resume', 'O currículo é muito grande. Tamanho máximo: 15MB.');
            }
        }
        if (!photo.files || photo.files.length === 0) {
            addError('photo', 'Anexe uma foto.');
        } else {
            const file = photo.files[0];
            const allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'heic', 'heif'];
            const extension = file.name.split('.').pop().toLowerCase();
            if (!allowedExtensions.includes(extension)) {
                addError('photo', 'A foto deve ser JPG, JPEG, PNG, GIF ou HEIC (iPhone).');
            } else if (file.size > maxFileSize) {
                addError('photo', 'A foto é muito grande. Tamanho máximo: 15MB.');
            }
        }
    }

    // 15. Consentimento Final
    if (isSectionVisible('finalConsentSection')) {
        if (!radioIsChecked('acceptTerms')) {
            addError('acceptTerms', 'Aceite as condições para enviar o currículo.');
        } else if (radioValue('acceptTerms') !== 'sim, aceito') {
            addError('acceptTerms', 'Para enviar, é necessário marcar "Sim, declaro que li e aceito as condições".');
        }
    }

    return errors;
}

// Remove o destaque de erro quando o usuário corrige o campo
['input', 'change'].forEach(eventType => {
    document.addEventListener(eventType, function(e) {
        const group = e.target.closest('.form-group');
        if (group && group.classList.contains('field-error')) {
            group.classList.remove('field-error');
        }
        const summary = document.getElementById('validationSummary');
        if (summary && summary.style.display === 'block') {
            summary.style.display = 'none';
        }
    });
});

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
    
    // Limpar erros anteriores e validar todos os campos obrigatórios
    clearValidationErrors();
    const validationErrors = validateForm();
    if (validationErrors.length > 0) {
        console.log('⚠️ Validação bloqueou o envio:', validationErrors);
        showValidationSummary(validationErrors);
        return;
    }
    
    const resumeFile = formData.get('resume');
    const photoFile = formData.get('photo');
    
    console.log('📎 Arquivos verificados:', {
        curriculo: resumeFile.name + ' (' + (resumeFile.size / 1024).toFixed(1) + 'KB)',
        foto: photoFile.name + ' (' + (photoFile.size / 1024).toFixed(1) + 'KB)'
    });
    
    // --- VERIFICAÇÃO DE DUPLICIDADE (apenas avisa, NÃO bloqueia) ---
    checkDuplicate(formData)
        .then(function(exists) {
            if (exists) {
                const continuar = confirm(
                    '⚠️ Você já possui um currículo cadastrado em nosso sistema.\n\n' +
                    'Só é necessário cadastrar novamente se houver alterações de dados.\n\n' +
                    'Deseja continuar e enviar o cadastro mesmo assim?'
                );
                if (!continuar) {
                    console.log('🚫 Usuário cancelou o envio após o aviso de currículo já cadastrado.');
                    return;
                }
            }
            sendCurriculum(formData, name, resumeFile, photoFile);
        })
        .catch(function(dupError) {
            console.warn('⚠️ Não foi possível verificar duplicidade. Prosseguindo com o envio.', dupError);
            sendCurriculum(formData, name, resumeFile, photoFile);
        });
}

// Verifica duplicidade via endpoint (compatível com navegadores antigos)
function checkDuplicate(formData) {
    return new Promise(function(resolve, reject) {
        try {
            const dupFormData = new FormData();
            dupFormData.append('name', formData.get('name'));
            dupFormData.append('birthDate', formData.get('birthDate'));
            dupFormData.append('email', formData.get('email') || '');
            dupFormData.append('phone', formData.get('phone') || '');
            
            const basePath = window.location.pathname.indexOf('/') !== -1
                ? window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/') + 1)
                : '';
            
            fetch(basePath + 'check_duplicate.php?v=' + Date.now(), {
                method: 'POST',
                body: dupFormData
            })
                .then(function(response) { return response.json(); })
                .then(function(data) { resolve(!!(data.success && data.exists)); })
                .catch(function(err) { reject(err); });
        } catch (err) {
            reject(err);
        }
    });
}

// Envia o formulário para o servidor (código de envio original)
function sendCurriculum(formData, name, resumeFile, photoFile) {
    // Show loading
    const submitBtn = curriculumForm.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando currículo...';
    submitBtn.disabled = true;
    
    console.log('🚀 Enviando dados para process-simple.php...');
    
    // Determinar o caminho correto do arquivo PHP com cache-busting
    const phpPath = window.location.pathname.includes('/') 
        ? window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/') + 1) + 'process-simple.php?v=' + Date.now()
        : 'process-simple.php?v=' + Date.now();
    
    console.log('📍 Caminho do PHP:', phpPath);
    
    // Criar um timeout manual para a requisição (apenas se AbortController existir)
    let controller = null;
    let timeoutId = null;
    if (typeof AbortController !== 'undefined') {
        controller = new AbortController();
        timeoutId = setTimeout(function() { controller.abort(); }, 120000); // 120 segundos (2 minutos)
    }
    
    // Send to PHP
    const fetchOptions = {
        method: 'POST',
        body: formData,
        // Adicionar headers para melhor compatibilidade
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    };
    if (controller) {
        fetchOptions.signal = controller.signal;
    }
    
    fetch(phpPath, fetchOptions)
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
            
            // 1. Tentar parsear diretamente
            try {
                return JSON.parse(text);
            } catch (e) {
                console.warn('⚠️ JSON.parse direto falhou, tentando alternativas...');
                console.log('📄 Conteúdo completo:', text);
            }
            
            // 2. Tentar com trim (pode haver espaços/BOM antes ou depois)
            try {
                return JSON.parse(text.trim());
            } catch (e) {
                // continuar
            }
            
            // 3. Extrair JSON válido contando chaves (ignora lixo depois do JSON)
            const firstBrace = text.indexOf('{');
            if (firstBrace !== -1) {
                let depth = 0;
                let inString = false;
                let escape = false;
                for (let i = firstBrace; i < text.length; i++) {
                    const ch = text[i];
                    if (escape) { escape = false; continue; }
                    if (ch === '\\') { escape = true; continue; }
                    if (ch === '"') { inString = !inString; continue; }
                    if (inString) continue;
                    if (ch === '{') depth++;
                    if (ch === '}') depth--;
                    if (depth === 0) {
                        const jsonStr = text.substring(firstBrace, i + 1);
                        try {
                            const parsed = JSON.parse(jsonStr);
                            console.log('✅ JSON extraído por contagem de chaves:', parsed);
                            return parsed;
                        } catch (e) {
                            break;
                        }
                    }
                }
            }
            
            // 4. Último recurso: detectar success/fail pelo texto bruto
            const isSuccess = text.includes('"success":true') || text.includes('"success": true');
            const isFailure = text.includes('"success":false') || text.includes('"success": false');
            
            if (isSuccess) {
                console.log('✅ Sucesso detectado pelo texto bruto');
                return { success: true, message: 'Currículo cadastrado com sucesso!' };
            }
            if (isFailure) {
                // Extrair a mensagem de erro do texto
                const msgMatch = text.match(/"message"\s*:\s*"([^"\\]*(?:\\.[^"\\]*)*)"/);
                const msg = msgMatch ? msgMatch[1].replace(/\\"/g, '"').replace(/\\n/g, '\n') : 'Erro desconhecido no servidor.';
                console.log('❌ Falha detectada pelo texto bruto:', msg);
                return { success: false, message: msg };
            }
            
            const preview = text.substring(0, 300);
            throw new Error('Resposta inválida do servidor.\n\nInício da resposta:\n' + preview);
        });
    })
    .then(data => {
        console.log('✅ Dados processados:', data);
        
        if (data.success) {
            // Marcar formulário como enviado para o tracker (evita registrar como abandono)
            if (window.formTracker) {
                window.formTracker.markFormSubmitted();
                // Limpar dados de sessão do tracker para novo cadastro
                sessionStorage.removeItem('form_session_id');
            }

            // Show success message
            const successTextEl = document.getElementById('successText');
            successTextEl.textContent = 
                `${name}, seu currículo foi cadastrado com sucesso e será analisado pela nossa equipe de RH.`;
            
            // Aviso informativo: confirmação via WhatsApp não enviada (não é erro)
            const oldWarning = document.getElementById('confirmationWarning');
            if (oldWarning) oldWarning.remove();
            if (data.notification_warning) {
                const warningDiv = document.createElement('div');
                warningDiv.id = 'confirmationWarning';
                warningDiv.style.cssText = 'background:#fef3c7;border:1px solid #f59e0b;border-radius:8px;padding:12px 15px;margin-top:20px;color:#92400e;font-size:0.95rem;text-align:left;';
                const icon = document.createElement('i');
                icon.className = 'fas fa-exclamation-triangle';
                warningDiv.appendChild(icon);
                warningDiv.appendChild(document.createTextNode(' '));
                const msgSpan = document.createElement('span');
                msgSpan.textContent = data.notification_warning;
                warningDiv.appendChild(msgSpan);
                successTextEl.insertAdjacentElement('afterend', warningDiv);
            }
            
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

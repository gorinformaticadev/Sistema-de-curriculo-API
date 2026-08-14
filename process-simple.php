﻿﻿﻿﻿﻿﻿﻿﻿﻿﻿﻿﻿<?php
// Buffer de saída para evitar que HTML/warnings corrompam a resposta JSON
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Incluir o arquivo de conexão com o banco de dados
require_once 'db_connect.php';

// --- FUNÇÕES GLOBAIS ---

ini_set('display_errors', 0);
error_reporting(E_ALL);

function logError($message, $type = 'ERROR') {
    $logFile = 'error.log';
    $maxSize = 5 * 1024 * 1024; // 5MB

    if (file_exists($logFile) && filesize($logFile) > $maxSize) {
        rename($logFile, $logFile . '.bak');
    }

    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $logMessage = "[$timestamp] [$type] [IP: $ip] $message" . PHP_EOL;
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

// Função para enviar JSON de forma segura (limpa buffer antes)
function sendJson($data, $httpCode = 200) {
    // Limpar TODOS os níveis de buffer para evitar conteúdo extra após o JSON
    while (ob_get_level()) {
        ob_end_clean();
    }
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Carregar configuração do banco de dados
function loadConfigFromDB($pdo) {
    $config = [];
    $stmt = $pdo->query("SELECT chave, valor FROM config");
    while ($row = $stmt->fetch()) {
        $config[$row['chave']] = $row['valor'];
    }
    return $config;
}

// --- FUNÇÕES DE PROCESSAMENTO ---

function sanitizeInput($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

function cleanPhoneNumber($phone) {
    return preg_replace('/\D/', '', $phone);
}

function uploadFile($file, $allowedTypes, $prefix = '') {
    $uploadDir = 'uploads/';
    $maxFileSize = 15 * 1024 * 1024;


    if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
    if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception('Erro no upload: ' . $file['error']);
    if ($file['size'] > $maxFileSize) throw new Exception('Arquivo muito grande (Max 15MB)');
    
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedTypes)) throw new Exception('Tipo de arquivo não permitido: ' . $extension);
    
    $fileName = $prefix . '_' . uniqid() . '.' . $extension;
    $filePath = $uploadDir . $fileName;
    
    if (!move_uploaded_file($file['tmp_name'], $filePath)) throw new Exception('Erro ao salvar arquivo');
    
    return $fileName;
}

// --- FUNÇÕES DA API ---

function sendApiTextMessage($token, $url, $number, $message) {
    // Validação básica do número
    if (strlen($number) < 12 || !str_starts_with($number, '55')) {
        logError("API (Texto): Número de telefone inválido: $number");
        return false;
    }

    $data = ['number' => $number, 'body' => $message, 'saveOnTicket' => true];
    logError("API (Texto): Preparando para enviar JSON: " . json_encode($data), 'INFO');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token]
    ]);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpcode !== 200) logError("API (Texto): Falha. Status: $httpcode, Resposta: $response");
    return $httpcode === 200;
}

function sendApiMediaMessage($token, $url, $number, $filePath, $fileName) {
    // Validação básica do número
    if (strlen($number) < 12 || !str_starts_with($number, '55')) {
        logError("API (Media): Número de telefone inválido: $number");
        return false;
    }
    if (!file_exists($filePath)) {
        logError("API (Media): Arquivo não encontrado para envio: $filePath");
        return false;
    }

    $cFile = new CURLFile($filePath, mime_content_type($filePath), $fileName);
    $data = ['number' => $number, 'medias' => $cFile, 'saveOnTicket' => true];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_HTTPHEADER => ['Content-Type: multipart/form-data', 'Authorization: Bearer ' . $token]
    ]);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpcode !== 200) logError("API (Media): Falha ao enviar $fileName. Status: $httpcode, Resposta: $response");
    return $httpcode === 200;
}


// --- PROCESSAMENTO PRINCIPAL ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // --- VERIFICACAO HONEYPOT ANTI-SPAM ---
        if (!empty($_POST['website'])) {
            logError('Honeypot triggered - possible bot submission', 'SECURITY');
            sendJson(['success' => true, 'message' => 'Curriculo cadastrado com sucesso!']);
        }

        $config = loadConfigFromDB($pdo);

        // Sanitizar e coletar dados do POST
        $formData = [];
        $fields = ['name', 'birthDate', 'maritalStatus', 'hasChildren', 'email', 'facebook', 'instagram', 'address', 'city', 'state', 'education', 'isStudying', 'studyPeriod', 'hasCourses', 'courses', 'hasExperience', 'motivation', 'acceptTerms', 'workSchedule', 'disponibilidadeInicio', 'disponibilidadeOutraData', 'disponibilidadeSabados', 'disponibilidadeHorasExtras', 'pretensaoSalarial', 'conhecimentoInformatica', 'expectativaPrimeiroEmprego', 'cursoAtual', 'instituicaoCurso', 'situacaoCurso', 'anoConclusaoCurso', 'comoConheceu', 'comoConheceuOutro', 'possuiReferencia'];
        foreach ($fields as $field) {
            $formData[$field] = sanitizeInput($_POST[$field] ?? '');
        }
        
        // Coletar até 3 telefones e flags WhatsApp
        $phones = [];
        $phones_clean = [];
        $whatsapps = [];

        // Primeiro telefone sem número no nome do campo
        if (!empty($_POST['phone'])) {
            $phone_clean = cleanPhoneNumber($_POST['phone']);
            $phones[] = sanitizeInput($_POST['phone']);
            $phones_clean[] = $phone_clean;
            $whatsapps[] = (isset($_POST['isWhatsapp']) && $_POST['isWhatsapp'] === 'Sim') ? 'Sim' : 'Não';
        }

        // Demais telefones com sufixo numérico
        for ($i = 2; $i <= 3; $i++) {
            $phoneKey = "phone$i";
            $whatsappKey = "isWhatsapp$i";
            if (!empty($_POST[$phoneKey])) {
                $phone_clean = cleanPhoneNumber($_POST[$phoneKey]);
                $phones[] = sanitizeInput($_POST[$phoneKey]);
                $phones_clean[] = $phone_clean;
                $whatsapps[] = (isset($_POST[$whatsappKey]) && $_POST[$whatsappKey] === 'Sim') ? 'Sim' : 'Não';
            }
        }
        $formData['phone'] = implode(', ', $phones);
        $formData['isWhatsapp'] = implode(', ', $whatsapps);
        
        // Converter valores de texto para booleano (1/0) para o banco de dados, mantendo os textos originais para a notificação.
        // Para o banco, salvar 1 se algum dos números for WhatsApp, 0 caso contrário
        $isWhatsapp_db = in_array('Sim', $whatsapps) ? 1 : 0;
        $isStudying_db = ($formData['isStudying'] === 'Sim, estou!') ? 1 : 0;
        $hasCourses_db = ($formData['hasCourses'] === 'Sim') ? 1 : 0;
        $hasExperience_db = ($formData['hasExperience'] === 'Sim') ? 1 : 0;
        $consentimentoLgpd_db = !empty($_POST['consentimentoLgpd']) ? 1 : 0;
        $consentimentoBancoTalentos_db = !empty($_POST['consentimentoBancoTalentos']) ? 1 : 0;

        // Validação LGPD: consentimento obrigatório para armazenar dados pessoais
        if (!$consentimentoLgpd_db) {
            sendJson(['success' => false, 'message' => 'É necessário aceitar o consentimento para tratamento dos seus dados pessoais (LGPD).'], 400);
        }

        // Validação do consentimento final
        if ($formData['acceptTerms'] !== 'sim, aceito') {
            sendJson(['success' => false, 'message' => 'É necessário aceitar as condições para enviar o currículo.'], 400);
        }

        // Coletar experiências (detalhadas, sem limite rígido de 3)
        $experiences = [];
        for ($i = 1; $i <= 10; $i++) {
            if (!empty($_POST["company$i"])) {
                $empregadoAtual = sanitizeInput($_POST["empregadoAtual$i"] ?? '');
                $experiences[] = [
                    'company' => sanitizeInput($_POST["company$i"]),
                    'position' => sanitizeInput($_POST["position$i"]),
                    'duration' => sanitizeInput($_POST["duration$i"]),
                    'atividades' => sanitizeInput($_POST["atividades$i"] ?? ''),
                    'empregado_atual' => ($empregadoAtual === 'Sim') ? 'Sim' : 'Não',
                    'motivo_saida' => sanitizeInput($_POST["motivoSaida$i"] ?? ''),
                    'motivo_saida_atual' => sanitizeInput($_POST["motivoSaidaAtual$i"] ?? ''),
                ];
            }
        }
        $formData['experiences'] = json_encode($experiences);

        // Coletar habilidades (seleção múltipla + campo "Outra")
        $habilidades = [];
        if (!empty($_POST['habilidades']) && is_array($_POST['habilidades'])) {
            foreach ($_POST['habilidades'] as $habilidade) {
                $habilidade = sanitizeInput($habilidade);
                if ($habilidade === 'Outra' && !empty($_POST['habilidadeOutra'])) {
                    $habilidades[] = sanitizeInput($_POST['habilidadeOutra']);
                } elseif ($habilidade !== '') {
                    $habilidades[] = $habilidade;
                }
            }
        }
        $formData['habilidades'] = json_encode(array_values(array_unique($habilidades)));

        // Validação: ao menos uma habilidade deve ser informada
        if (empty($habilidades)) {
            sendJson(['success' => false, 'message' => 'Selecione pelo menos uma habilidade para continuar.'], 400);
        }

        // Coletar referências profissionais (opcional, até 2)
        $referencias = [];
        for ($i = 1; $i <= 2; $i++) {
            if (!empty($_POST["refNome$i"])) {
                $referencias[] = [
                    'nome' => sanitizeInput($_POST["refNome$i"]),
                    'empresa' => sanitizeInput($_POST["refEmpresa$i"] ?? ''),
                    'cargo' => sanitizeInput($_POST["refCargo$i"] ?? ''),
                    'telefone' => sanitizeInput($_POST["refTelefone$i"] ?? ''),
                ];
            }
        }
        $formData['referencias'] = json_encode($referencias);

        // Pretensão salarial: "A combinar" substitui o valor digitado
        if (!empty($_POST['pretensaoACombinar'])) {
            $formData['pretensaoSalarial'] = 'A combinar';
        }

        // Upload dos arquivos
        $resumeFile = uploadFile($_FILES['resume'], ['pdf'], 'curriculo');
        $photoFile = uploadFile($_FILES['photo'], ['jpg', 'jpeg', 'png', 'gif', 'heic', 'heif'], 'foto');

        // --- VERIFICACAO DE DUPLICATA (apenas aviso, NÃO bloqueia o envio) ---
        // O aviso visual é feito no formulário antes do envio; aqui apenas registramos
        // a ocorrência para a notificação enviada ao RH.
        $primeiroTelefone = !empty($phones_clean) ? $phones_clean[0] : '';
        $possivelDuplicata = false;
        if (!empty($formData['name']) && !empty($formData['birthDate'])) {
            $dupWhere = [];
            $dupParams = [];
            $dupWhere[] = "(nome = ? AND data_nascimento = ?)";
            $dupParams[] = $formData['name'];
            $dupParams[] = $formData['birthDate'];
            if (!empty($formData['email']) && filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
                $dupWhere[] = "(email = ?)";
                $dupParams[] = $formData['email'];
            }
            if (!empty($primeiroTelefone)) {
                $dupWhere[] = "(telefone LIKE ?)";
                $dupParams[] = '%' . $primeiroTelefone . '%';
            }
            $dupStmt = $pdo->prepare("SELECT COUNT(*) FROM curriculos WHERE " . implode(' OR ', $dupWhere));
            $dupStmt->execute($dupParams);
            $possivelDuplicata = ((int)$dupStmt->fetchColumn()) > 0;
            if ($possivelDuplicata) {
                logError('DUPLICATE_WARNING: Curriculo possivelmente duplicado para ' . $formData['name'] . ' (envio permitido apos aviso ao candidato)', 'WARNING');
            }
        }

        // Inserir no banco de dados
        $sql = "INSERT INTO curriculos (nome, data_nascimento, estado_civil, possui_filhos, telefone, is_whatsapp, email, facebook, instagram, endereco, cidade, estado, escolaridade, estudando, periodo_estudo, possui_cursos, cursos, possui_experiencia, experiencias, motivacao, arquivo_curriculo, arquivo_foto, ip_cadastro, disponibilidade_inicio, disponibilidade_outra_data, disponibilidade_sabados, disponibilidade_horas_extras, pretensao_salarial, habilidades, conhecimento_informatica, expectativa_primeiro_emprego, curso_atual, instituicao_curso, situacao_curso, ano_conclusao_curso, como_conheceu, como_conheceu_outro, referencias, consentimento_lgpd, consentimento_banco_talentos) 
                VALUES (:nome, :data_nascimento, :estado_civil, :possui_filhos, :telefone, :is_whatsapp, :email, :facebook, :instagram, :endereco, :cidade, :estado, :escolaridade, :estudando, :periodo_estudo, :possui_cursos, :cursos, :possui_experiencia, :experiencias, :motivacao, :arquivo_curriculo, :arquivo_foto, :ip_cadastro, :disponibilidade_inicio, :disponibilidade_outra_data, :disponibilidade_sabados, :disponibilidade_horas_extras, :pretensao_salarial, :habilidades, :conhecimento_informatica, :expectativa_primeiro_emprego, :curso_atual, :instituicao_curso, :situacao_curso, :ano_conclusao_curso, :como_conheceu, :como_conheceu_outro, :referencias, :consentimento_lgpd, :consentimento_banco_talentos)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':nome' => $formData['name'],
            ':data_nascimento' => $formData['birthDate'],
            ':estado_civil' => $formData['maritalStatus'],
            ':possui_filhos' => ($formData['hasChildren'] === 'Sim') ? 1 : 0,
            ':telefone' => $formData['phone'], // concatenated phones
            ':is_whatsapp' => $isWhatsapp_db,
            ':email' => $formData['email'],
            ':facebook' => $formData['facebook'],
            ':instagram' => $formData['instagram'],
            ':endereco' => $formData['address'],
            ':cidade' => $formData['city'],
            ':estado' => $formData['state'],
            ':escolaridade' => $formData['education'],
            ':estudando' => $isStudying_db,
            ':periodo_estudo' => $formData['studyPeriod'],
            ':possui_cursos' => $hasCourses_db,
            ':cursos' => $formData['courses'],
            ':possui_experiencia' => $hasExperience_db,
            ':experiencias' => $formData['experiences'],
            ':motivacao' => $formData['motivation'],
            ':arquivo_curriculo' => $resumeFile,
            ':arquivo_foto' => $photoFile,
            ':ip_cadastro' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ':disponibilidade_inicio' => $formData['disponibilidadeInicio'] ?: null,
            ':disponibilidade_outra_data' => ($formData['disponibilidadeInicio'] === 'Outra data' && !empty($formData['disponibilidadeOutraData'])) ? $formData['disponibilidadeOutraData'] : null,
            ':disponibilidade_sabados' => $formData['disponibilidadeSabados'] ?: null,
            ':disponibilidade_horas_extras' => $formData['disponibilidadeHorasExtras'] ?: null,
            ':pretensao_salarial' => $formData['pretensaoSalarial'] ?: null,
            ':habilidades' => $formData['habilidades'],
            ':conhecimento_informatica' => $formData['conhecimentoInformatica'] ?: null,
            ':expectativa_primeiro_emprego' => $formData['expectativaPrimeiroEmprego'] ?: null,
            ':curso_atual' => $formData['cursoAtual'] ?: null,
            ':instituicao_curso' => $formData['instituicaoCurso'] ?: null,
            ':situacao_curso' => $formData['situacaoCurso'] ?: null,
            ':ano_conclusao_curso' => $formData['anoConclusaoCurso'] ?: null,
            ':como_conheceu' => $formData['comoConheceu'] ?: null,
            ':como_conheceu_outro' => $formData['comoConheceuOutro'] ?: null,
            ':referencias' => $formData['referencias'],
            ':consentimento_lgpd' => $consentimentoLgpd_db,
            ':consentimento_banco_talentos' => $consentimentoBancoTalentos_db
        ]);

        // Enviar mensagem de conclusão para números WhatsApp do usuário
        if (!empty($config['api_token']) && !empty($config['api_url']) && !empty($config['completion_message'])) {
            $completionMessage = str_replace('{nome}', $formData['name'], $config['completion_message']);
            foreach ($phones_clean as $index => $phone) {
                if ($whatsapps[$index] === 'Sim') {
                    // Adicionar código do país se não tiver
                    if (!str_starts_with($phone, '55')) {
                        $phone = '55' . $phone;
                    }
                    $success = sendApiTextMessage($config['api_token'], $config['api_url'], $phone, $completionMessage);
                    if (!$success) {
                        logError("Falha ao enviar mensagem de conclusão para $phone", 'WARNING');
                    }
                }
            }
        }

        // Enviar notificações via API
        if (!empty($config['api_token']) && !empty($config['notification_number'])) {
            logError("Iniciando envio de notificação via API para {$config['notification_number']}", 'INFO');
            
            // Montar a mensagem completa com todos os campos
            $textMessage = "*Novo Currículo Recebido* 📄\n\n";
            $textMessage .= "*--- Dados Pessoais ---*\n";
            $textMessage .= "*Nome:* " . ($formData['name'] ?? 'N/A') . "\n";
            $textMessage .= "*Data de Nasc.:* " . ($formData['birthDate'] ? date('d/m/Y', strtotime($formData['birthDate'])) : 'N/A') . "\n";
            $textMessage .= "*Estado Civil:* " . ($formData['maritalStatus'] ?? 'N/A') . "\n";
            $textMessage .= "*Possui Filhos?:* " . ($formData['hasChildren'] ?? 'N/A') . "\n\n";

            $textMessage .= "*--- Contato ---*\n";
            foreach ($phones as $index => $phone) {
                $waStatus = $whatsapps[$index] ?? 'Não';
                $textMessage .= "*Telefone " . ($index + 1) . ":* $phone\n";
                $textMessage .= "*É WhatsApp?:* $waStatus\n";
            }
            $textMessage .= "*Email:* " . ($formData['email'] ?? 'N/A') . "\n\n";

            $textMessage .= "*--- Endereço ---*\n";
            $textMessage .= "*Endereço:* " . ($formData['address'] ?? 'N/A') . "\n";
            $textMessage .= "*Cidade:* " . ($formData['city'] ?? 'N/A') . "\n";
            $textMessage .= "*Estado:* " . ($formData['state'] ?? 'N/A') . "\n\n";

            $textMessage .= "*--- Formação ---*\n";
            $textMessage .= "*Escolaridade:* " . ($formData['education'] ?? 'N/A') . "\n";
            $textMessage .= "*Está Estudando?:* " . ($formData['isStudying'] ?? 'N/A') . "\n";
            if (!empty($formData['studyPeriod'])) {
                $textMessage .= "*Período de Estudo:* " . $formData['studyPeriod'] . "\n";
            }
            if (!empty($formData['cursoAtual'])) {
                $textMessage .= "*Curso Atual:* " . $formData['cursoAtual'] . "\n";
                $textMessage .= "*Instituição:* " . $formData['instituicaoCurso'] . "\n";
                $textMessage .= "*Situação:* " . $formData['situacaoCurso'] . "\n";
                $textMessage .= "*Ano Conclusão/Previsão:* " . $formData['anoConclusaoCurso'] . "\n";
            }
            $textMessage .= "*Possui Cursos?:* " . ($formData['hasCourses'] ?? 'N/A') . "\n";
            if (!empty($formData['courses'])) {
                $textMessage .= "*Cursos:* " . $formData['courses'] . "\n";
            }
            $textMessage .= "\n";

            $textMessage .= "*--- Habilidades ---*\n";
            $habilidadesArray = json_decode($formData['habilidades'], true) ?: [];
            $textMessage .= "*Habilidades:* " . (!empty($habilidadesArray) ? implode(', ', $habilidadesArray) : 'N/A') . "\n";
            $textMessage .= "*Conhecimento em Informática:* " . ($formData['conhecimentoInformatica'] ?? 'N/A') . "\n\n";

            $textMessage .= "*--- Disponibilidade ---*\n";
            $textMessage .= "*Pode começar:* " . ($formData['disponibilidadeInicio'] ?? 'N/A') . "\n";
            if ($formData['disponibilidadeInicio'] === 'Outra data' && !empty($formData['disponibilidadeOutraData'])) {
                $textMessage .= "*Data:* " . date('d/m/Y', strtotime($formData['disponibilidadeOutraData'])) . "\n";
            }
            $textMessage .= "*Sábados:* " . ($formData['disponibilidadeSabados'] ?? 'N/A') . "\n";
            $textMessage .= "*Horas Extras:* " . ($formData['disponibilidadeHorasExtras'] ?? 'N/A') . "\n\n";

            $textMessage .= "*--- Pretensão Salarial ---*\n";
            $textMessage .= "*Pretensão:* " . (!empty($formData['pretensaoSalarial']) ? $formData['pretensaoSalarial'] : 'Não informada') . "\n\n";

            $textMessage .= "*--- Experiência Profissional ---*\n";
            $textMessage .= "*Possui Experiência?:* " . ($formData['hasExperience'] ?? 'N/A') . "\n";
            $experiences = json_decode($formData['experiences'], true);
            if (!empty($experiences)) {
                foreach ($experiences as $i => $exp) {
                    $textMessage .= "*Empresa " . ($i + 1) . ":* " . ($exp['company'] ?? 'N/A') . "\n";
                    $textMessage .= "*Cargo " . ($i + 1) . ":* " . ($exp['position'] ?? 'N/A') . "\n";
                    $textMessage .= "*Duração " . ($i + 1) . ":* " . ($exp['duration'] ?? 'N/A') . "\n";
                    if (!empty($exp['atividades'])) {
                        $textMessage .= "*Atividades " . ($i + 1) . ":* " . $exp['atividades'] . "\n";
                    }
                    if (!empty($exp['empregado_atual']) && $exp['empregado_atual'] === 'Sim') {
                        $textMessage .= "*Trabalha atualmente " . ($i + 1) . ":* Sim\n";
                        if (!empty($exp['motivo_saida_atual'])) {
                            $textMessage .= "*Por que está saindo:* " . $exp['motivo_saida_atual'] . "\n";
                        }
                    } elseif (!empty($exp['motivo_saida'])) {
                        $textMessage .= "*Motivo da saída " . ($i + 1) . ":* " . $exp['motivo_saida'] . "\n";
                    }
                }
            }
            if (!empty($formData['expectativaPrimeiroEmprego'])) {
                $textMessage .= "*Expectativa 1º Emprego:* " . $formData['expectativaPrimeiroEmprego'] . "\n";
            }
            $textMessage .= "\n";

            $textMessage .= "*--- Informações Complementares ---*\n";
            $textMessage .= "*Como conheceu:* " . ($formData['comoConheceu'] ?? 'N/A') . "\n";
            if ($formData['comoConheceu'] === 'Outro' && !empty($formData['comoConheceuOutro'])) {
                $textMessage .= "*Detalhe:* " . $formData['comoConheceuOutro'] . "\n";
            }
            $referenciasArray = json_decode($formData['referencias'], true) ?: [];
            if (!empty($referenciasArray)) {
                foreach ($referenciasArray as $i => $ref) {
                    $textMessage .= "*Referência " . ($i + 1) . ":* " . ($ref['nome'] ?? '') . " - " . ($ref['empresa'] ?? '') . " - " . ($ref['cargo'] ?? '') . " - " . ($ref['telefone'] ?? '') . "\n";
                }
            }
            $textMessage .= "\n";

            $textMessage .= "*--- Objetivo ---*\n";
            $textMessage .= "*Motivação:* " . ($formData['motivation'] ?? 'N/A') . "\n\n";

            $textMessage .= "*--- Termos e Condições ---*\n";
            $textMessage .= "*Aceita os termos?:* " . ($formData['acceptTerms'] ?? 'N/A') . "\n";
            $textMessage .= "*Consentimento LGPD:* " . ($consentimentoLgpd_db ? 'Sim' : 'Não') . "\n";
            $textMessage .= "*Banco de Talentos:* " . ($consentimentoBancoTalentos_db ? 'Sim' : 'Não') . "\n";
            $textMessage .= "*Possível duplicata:* " . ($possivelDuplicata ? 'Sim ⚠️ (já existe cadastro com mesmo nome/data)' : 'Não') . "\n\n";

            $textMessage .= "*--- Redes Sociais ---*\n";
            $textMessage .= "*Facebook:* " . ($formData['facebook'] ?? 'N/A') . "\n";
            $textMessage .= "*Instagram:* " . ($formData['instagram'] ?? 'N/A') . "\n\n";

            $textMessage .= "_Os arquivos (currículo e foto) serão enviados em seguida._";

            // Enviar FOTO primeiro
            $photoSuccess = sendApiMediaMessage($config['api_token'], $config['api_url'], $config['notification_number'], 'uploads/' . $photoFile, $photoFile);
            if (!$photoSuccess) {
                logError("Falha ao enviar a foto via API. Continuando...", 'WARNING');
            }

            // Enviar TEXTO com dados depois
            $textSuccess = sendApiTextMessage($config['api_token'], $config['api_url'], $config['notification_number'], trim($textMessage));
            if (!$textSuccess) {
                throw new Exception("Falha ao enviar notificação de texto via API. Verifique os logs.");
            }

            // Enviar PDF do curriculo por ultimo
            $resumeSuccess = sendApiMediaMessage($config['api_token'], $config['api_url'], $config['notification_number'], 'uploads/' . $resumeFile, $resumeFile);
             if (!$resumeSuccess) {
                logError("Falha ao enviar o PDF do currículo via API. Continuando...", 'WARNING');
            }



        } else {
            logError("API Token ou Número de Notificação não configurado. Notificação pulada.", 'WARNING');
        }

        // Enviar notificação por email usando mail() do servidor
        if (!empty($config['smtp_from']) && !empty($config['notification_email'])) {
            $emailSubject = "Novo Currículo Recebido - " . $formData['name'];
            $emailBody = str_replace("*", "", $textMessage); // Remove markdown for plain text
            $headers = "From: " . $config['smtp_from'] . "\r\n";
            $headers .= "Reply-To: " . $config['smtp_from'] . "\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $emailSuccess = mail($config['notification_email'], $emailSubject, $emailBody, $headers);
            if (!$emailSuccess) {
                logError("Falha ao enviar notificação por email", 'WARNING');
            }
        } else {
            logError("Email remetente ou destinatário não configurado. Notificação por email pulada.", 'WARNING');
        }

        sendJson(['success' => true, 'message' => 'Currículo cadastrado com sucesso!']);

    } catch (Exception $e) {
        logError("ERRO NO PROCESSAMENTO: " . $e->getMessage());
        sendJson(['success' => false, 'message' => $e->getMessage()], 400);
    }
} else {
    sendJson(['success' => false, 'message' => 'Método não permitido'], 405);
}

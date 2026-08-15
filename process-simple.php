﻿﻿﻿﻿﻿﻿﻿﻿﻿﻿﻿﻿﻿﻿﻿﻿﻿﻿﻿﻿﻿﻿<?php
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
    
    // Descriptografa o token da API e migra tokens legados para criptografia
    if (!empty($config['api_token']) && strpos($config['api_token'], 'enc:v1:') !== 0) {
        $plain = $config['api_token'];
        $encrypted = tokenEncrypt($plain);
        $upd = $pdo->prepare("UPDATE config SET valor = ? WHERE chave = 'api_token'");
        $upd->execute([$encrypted]);
        $config['api_token'] = $plain;
    } elseif (!empty($config['api_token'])) {
        $config['api_token'] = tokenDecrypt($config['api_token']);
    }
    
    return $config;
}

// --- FUNÇÕES DE PROCESSAMENTO ---

/**
 * AUTO-REPARO: garante que todas as colunas novas da tabela curriculos
 * existam antes do INSERT. Se o banco estiver desatualizado (migrações
 * não executadas), adiciona as colunas faltantes na hora.
 */
function ensureCurriculosColumns($pdo) {
    $columns = [
        'possui_filhos' => "TINYINT(1) DEFAULT 0",
        'disponibilidade_inicio' => "VARCHAR(50) DEFAULT NULL",
        'disponibilidade_outra_data' => "DATE DEFAULT NULL",
        'disponibilidade_sabados' => "VARCHAR(50) DEFAULT NULL",
        'disponibilidade_horas_extras' => "VARCHAR(50) DEFAULT NULL",
        'pretensao_salarial' => "VARCHAR(50) DEFAULT NULL",
        'habilidades' => "TEXT DEFAULT NULL",
        'conhecimento_informatica' => "VARCHAR(50) DEFAULT NULL",
        'expectativa_primeiro_emprego' => "TEXT DEFAULT NULL",
        'curso_atual' => "VARCHAR(255) DEFAULT NULL",
        'instituicao_curso' => "VARCHAR(255) DEFAULT NULL",
        'situacao_curso' => "VARCHAR(20) DEFAULT NULL",
        'ano_conclusao_curso' => "VARCHAR(10) DEFAULT NULL",
        'como_conheceu' => "VARCHAR(50) DEFAULT NULL",
        'como_conheceu_outro' => "VARCHAR(255) DEFAULT NULL",
        'referencias' => "TEXT DEFAULT NULL",
        'consentimento_lgpd' => "TINYINT(1) DEFAULT 0",
        'consentimento_banco_talentos' => "TINYINT(1) DEFAULT 0"
    ];
    
    foreach ($columns as $column => $definition) {
        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                 AND table_name = 'curriculos'
                 AND column_name = ?"
            );
            $stmt->execute([$column]);
            if ((int)$stmt->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE curriculos ADD COLUMN `{$column}` {$definition}");
                logError("AUTO_REPAIR: Coluna '{$column}' adicionada à tabela curriculos.", 'WARNING');
            }
        } catch (Exception $e) {
            logError("AUTO_REPAIR: Falha ao garantir coluna '{$column}': " . $e->getMessage(), 'ERROR');
        }
    }
}

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

// --- FUNÇÕES DA API (Pluggor) ---

/**
 * Envia mensagem de texto via API Pluggor.
 * POST {number, body} | Authorization: Bearer <token>
 * number: somente dígitos (8 a 15) com DDI + DDD
 * body: 1 a 4096 caracteres
 * Sucesso: HTTP 200/201 com {"success":true,"messageId":"..."}
 */
function sendApiTextMessage($token, $url, $number, $message) {
    // Validação do número: somente dígitos, 8 a 15 caracteres (com DDI + DDD)
    if (!preg_match('/^\d{8,15}$/', $number)) {
        logError("API (Texto): Número de telefone inválido: $number");
        return false;
    }

    // Validação do corpo: 1 a 4096 caracteres
    $bodyLength = strlen($message);
    if ($bodyLength < 1 || $bodyLength > 4096) {
        logError("API (Texto): Mensagem com tamanho inválido ($bodyLength caracteres).");
        return false;
    }

    $data = ['number' => $number, 'body' => $message];
    logError("API (Texto): Preparando para enviar JSON: " . json_encode($data), 'INFO');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // A API Pluggor responde 200 ou 201 (Created) em caso de sucesso
    if ($httpcode >= 200 && $httpcode < 300) {
        return true;
    }

    // Mapear erros conhecidos da API Pluggor para o log
    $errorDetail = '';
    $responseData = json_decode($response, true);
    if (is_array($responseData) && !empty($responseData['error'])) {
        $errorDetail = $responseData['error'];
        if (is_array($errorDetail)) { $errorDetail = json_encode($errorDetail); }
    }
    logError("API (Texto): Falha. Status: $httpcode, Erro: $errorDetail, Curl: $curlError, Resposta: " . substr((string)$response, 0, 500));
    return false;
}

/**
 * Envia mídia (foto/currículo) via API Pluggor.
 * MESMO endpoint do texto: POST /api/messages/send com multipart/form-data.
 * Campos: number, file, caption (opcional).
 * O tipo da mídia é identificado automaticamente pelo conteúdo.
 * Imagens: PNG, JPEG, GIF, WEBP | Documentos: PDF, DOCX, XLSX
 * Limite de 10 MB por arquivo.
 * Sucesso: HTTP 200/201 com {"success":true,"messageId":"..."}
 */
function sendApiMediaMessage($token, $url, $number, $filePath, $fileName, $caption = '') {
    // Validação do número: somente dígitos, 8 a 15 caracteres (com DDI + DDD)
    if (!preg_match('/^\d{8,15}$/', $number)) {
        logError("API (Media): Número de telefone inválido: $number");
        return false;
    }
    if (!file_exists($filePath)) {
        logError("API (Media): Arquivo não encontrado para envio: $filePath");
        return false;
    }

    // Tipos suportados pela API Pluggor (identificados pelo conteúdo)
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $allowedMedia = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'pdf', 'docx', 'xlsx'];
    if (!in_array($extension, $allowedMedia, true)) {
        logError("API (Media): Tipo não suportado pela API Pluggor (.$extension) para $fileName. Envio de mídia ignorado — arquivo segue salvo na plataforma.", 'WARNING');
        return false;
    }

    // Limite de 10 MB por arquivo
    if (filesize($filePath) > 10 * 1024 * 1024) {
        logError("API (Media): Arquivo $fileName excede 10MB. Envio de mídia ignorado — arquivo segue salvo na plataforma.", 'WARNING');
        return false;
    }

    // multipart: number + file + caption (o tipo é detectado pelo conteúdo)
    // MESMO endpoint do envio de texto: /api/messages/send
    $cFile = new CURLFile($filePath, mime_content_type($filePath), $fileName);
    $data = [
        'number' => $number,
        'file' => $cFile,
        'caption' => $caption
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_HTTPHEADER => ['Content-Type: multipart/form-data', 'Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // A API Pluggor responde 200 ou 201 (Created) em caso de sucesso
    if ($httpcode >= 200 && $httpcode < 300) {
        return true;
    }

    $errorDetail = '';
    $responseData = json_decode($response, true);
    if (is_array($responseData) && !empty($responseData['error'])) {
        $errorDetail = $responseData['error'];
        if (is_array($errorDetail)) { $errorDetail = json_encode($errorDetail); }
    }
    logError("API (Media): Falha ao enviar $fileName. Status: $httpcode, Erro: $errorDetail, Curl: $curlError, Resposta: " . substr((string)$response, 0, 500));
    return false;
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

        // --- AUTO-REPARO DA ESTRUTURA DO BANCO ---
        // Garante que as colunas novas existam antes do INSERT,
        // mesmo se as migrações ainda não tiverem sido aplicadas.
        ensureCurriculosColumns($pdo);

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
        // (falha aqui NÃO invalida o cadastro: apenas aviso informativo)
        $confirmacaoEnviada = true; // sem número WhatsApp informado, nada a enviar
        $possuiNumeroWhatsApp = false;
        foreach ($whatsapps as $wa) {
            if ($wa === 'Sim') { $possuiNumeroWhatsApp = true; break; }
        }
        if ($possuiNumeroWhatsApp) {
            $confirmacaoEnviada = false;
            if (!empty($config['api_token']) && !empty($config['api_url']) && !empty($config['completion_message'])) {
                $completionMessage = str_replace('{nome}', $formData['name'], $config['completion_message']);
                foreach ($phones_clean as $index => $phone) {
                    if ($whatsapps[$index] === 'Sim') {
                        // Adicionar código do país se não tiver
                        if (!str_starts_with($phone, '55')) {
                            $phone = '55' . $phone;
                        }
                        $success = sendApiTextMessage($config['api_token'], $config['api_url'], $phone, $completionMessage);
                        if ($success) {
                            $confirmacaoEnviada = true;
                        } else {
                            logError("Falha ao enviar mensagem de conclusão para $phone", 'WARNING');
                        }
                    }
                }
            } else {
                logError('API de WhatsApp não configurada. Mensagem de conclusão não enviada.', 'WARNING');
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
            $textMessage .= "*Possui referência profissional:* " . (!empty($referenciasArray) ? 'Sim' : 'Não') . "\n";
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

            // Enviar TEXTO com dados depois (falha NÃO invalida o cadastro)
            $textSuccess = sendApiTextMessage($config['api_token'], $config['api_url'], $config['notification_number'], trim($textMessage));
            if (!$textSuccess) {
                logError("Falha ao enviar notificação de texto via API. Currículo já salvo — apenas aviso interno.", 'WARNING');
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

        // Cadastro SEMPRE retorna sucesso (já está salvo no banco).
        // Se a confirmação via WhatsApp não foi enviada, avisa de forma informativa.
        if ($confirmacaoEnviada) {
            sendJson(['success' => true, 'message' => 'Currículo cadastrado com sucesso!']);
        } else {
            sendJson([
                'success' => true,
                'message' => 'Currículo cadastrado com sucesso!',
                'notification_warning' => 'Não foi possível enviar a mensagem de confirmação para o seu WhatsApp. Caso queira confirmar o envio do seu currículo, entre em contato conosco pelo WhatsApp (61) 3359-7358.'
            ]);
        }

    } catch (Exception $e) {
        logError("ERRO NO PROCESSAMENTO: " . $e->getMessage());
        sendJson(['success' => false, 'message' => $e->getMessage()], 400);
    }
} else {
    sendJson(['success' => false, 'message' => 'Método não permitido'], 405);
}

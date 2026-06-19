<?php
/**
 * API para gerenciamento de interações do formulário
 * Responsável por análises de comportamento e estatísticas do formulário
 */

/**
 * Busca estatísticas de interações
 */
function apiGetInteractionStats($pdo) {
    if (!isAdmin()) {
        logError("Tentativa de acesso às estatísticas de interações sem permissão admin", 'WARNING');
        return jsonResponse(false, 'Acesso negado: permissões insuficientes.');
    }
    
    try {
        // Total de sessões únicas (todas as sessões, incluindo as que só têm form_access)
        $stmt = $pdo->query("
            SELECT COUNT(DISTINCT session_id) as total 
            FROM form_interactions
        ");
        $totalSessions = $stmt->fetchColumn();

        // Formulários completos: apenas sessões com ação de submit real
        // (form_submitted = formulário enviado com sucesso, form_submit_click = botão clicado)
        $stmt = $pdo->query("
            SELECT COUNT(DISTINCT session_id) as total 
            FROM form_interactions
            WHERE acao IN ('form_submitted', 'form_submit_click')
        ");
        $completedForms = $stmt->fetchColumn();

        // Também contar currículos cadastrados relacionados por IP + janela de 5 min
        // (pegar currículos que foram inseridos mas cujo form_submitted pode não ter sido registrado)
        $stmt = $pdo->query("
            SELECT COUNT(DISTINCT fi.session_id) as total
            FROM form_interactions fi
            INNER JOIN curriculos c 
                ON c.ip_cadastro = fi.ip 
                AND ABS(TIMESTAMPDIFF(MINUTE, fi.timestamp, c.data_cadastro)) <= 5
            WHERE fi.acao NOT IN ('form_access', 'form_abandoned')
        ");
        $completedByCurriculo = $stmt->fetchColumn();

        // Usar o maior valor, mas sem inflar artificialmente
        $completedForms = max($completedForms, $completedByCurriculo);

        // Abandonos: sessões que NÃO completaram
        $abandonedForms = $totalSessions - $completedForms;
        if ($abandonedForms < 0) $abandonedForms = 0;

        // Taxa de conversão
        $conversionRate = $totalSessions > 0 ? round(($completedForms / $totalSessions) * 100, 1) : 0;

        return jsonResponse(true, 'Estatísticas carregadas com sucesso', [
            'stats' => [
                'totalSessions' => $totalSessions,
                'completedForms' => $completedForms,
                'abandonedForms' => $abandonedForms,
                'conversionRate' => $conversionRate . '%'
            ]
        ]);
        
    } catch (Exception $e) {
        logError('Erro ao buscar estatísticas de interações: ' . $e->getMessage());
        return jsonResponse(false, 'Erro ao carregar estatísticas.');
    }
}

/**
 * Busca análise de abandono
 */
function apiGetAbandonmentAnalysis($pdo) {
    if (!isAdmin()) {
        return jsonResponse(false, 'Acesso negado: permissões insuficientes.');
    }
    
    try {
        // Buscar último campo de cada sessão que NÃO completou o formulário
        // (sessões sem ação form_submitted ou form_submit_click)
        $stmt = $pdo->query("
            SELECT 
                ultimo_campo,
                COUNT(*) as count
            FROM (
                SELECT 
                    fi.session_id,
                    fi.ultimo_campo,
                    MAX(fi.timestamp) as last_time
                FROM form_interactions fi
                WHERE fi.ultimo_campo IS NOT NULL
                AND fi.ultimo_campo != ''
                AND fi.session_id NOT IN (
                    SELECT DISTINCT session_id 
                    FROM form_interactions 
                    WHERE acao IN ('form_submitted', 'form_submit_click')
                )
                GROUP BY fi.session_id
            ) as abandoned_sessions
            GROUP BY ultimo_campo
            ORDER BY count DESC
            LIMIT 10
        ");
        
        $abandonmentData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return jsonResponse(true, 'Análise de abandono carregada', ['data' => $abandonmentData]);
        
    } catch (Exception $e) {
        logError('Erro na análise de abandono: ' . $e->getMessage());
        return jsonResponse(false, 'Erro na análise de abandono.');
    }
}

/**
 * Busca sessões de interações
 */
function apiGetInteractionSessions($pdo) {
    if (!isAdmin()) {
        return jsonResponse(false, 'Acesso negado: permissões insuficientes.');
    }
    
    $period = filter_input(INPUT_GET, 'period', FILTER_SANITIZE_STRING) ?: 'week';
    $device = filter_input(INPUT_GET, 'device', FILTER_SANITIZE_STRING);
    $name = filter_input(INPUT_GET, 'name', FILTER_SANITIZE_STRING);
    
    try {
        // Construir query com filtros
        $where = ["1=1"];
        $params = [];

        // Filtro de período
        switch ($period) {
            case 'today':
                $where[] = "DATE(timestamp) = CURDATE()";
                break;
            case 'week':
                $where[] = "timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                break;
            case 'month':
                $where[] = "timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
                break;
            case 'all':
                // Sem filtro de data
                break;
        }

        // Filtro de dispositivo
        if (!empty($device)) {
            $where[] = "device = ?";
            $params[] = $device;
        }

        // Filtro de nome
        if (!empty($name)) {
            $where[] = "nome_completo LIKE ?";
            $params[] = "%$name%";
        }

        $whereClause = implode(" AND ", $where);

        // Buscar sessões agrupadas
        $stmt = $pdo->prepare("
            SELECT 
                session_id,
                ip,
                browser,
                os,
                device,
                nome_completo,
                ultimo_campo,
                MIN(timestamp) as first_interaction,
                MAX(timestamp) as last_interaction,
                COUNT(*) as interaction_count
            FROM form_interactions
            WHERE $whereClause
            GROUP BY session_id
            ORDER BY last_interaction DESC
            LIMIT 50
        ");
        
        $stmt->execute($params);
        $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Para cada sessão, verificar se foi completada
        foreach ($sessions as &$session) {
            // 1. Verificar se houve ação de submit (indicador mais confiável)
            $stmt2 = $pdo->prepare("
                SELECT 1 FROM form_interactions 
                WHERE session_id = ? 
                AND acao IN ('form_submitted', 'form_submit_click') 
                LIMIT 1
            ");
            $stmt2->execute([$session['session_id']]);
            $hasSubmitAction = $stmt2->rowCount() > 0;

            // 2. Verificar se existe currículo cadastrado com mesmo IP e janela de 5 min
            $stmt = $pdo->prepare("
                SELECT id FROM curriculos 
                WHERE ip_cadastro = ? 
                AND ABS(TIMESTAMPDIFF(MINUTE, data_cadastro, ?)) <= 5
                LIMIT 1
            ");
            $stmt->execute([$session['ip'], $session['last_interaction']]);
            $hasCurriculo = $stmt->rowCount() > 0;

            $session['completed'] = $hasSubmitAction || $hasCurriculo;
        }

        return jsonResponse(true, 'Sessões carregadas com sucesso', ['sessions' => $sessions]);
        
    } catch (Exception $e) {
        logError('Erro ao buscar sessões de interações: ' . $e->getMessage());
        return jsonResponse(false, 'Erro ao carregar sessões.');
    }
}

/**
 * Busca detalhes de uma sessão específica
 */
function apiGetSessionDetails($pdo) {
    if (!isAdmin()) {
        return jsonResponse(false, 'Acesso negado: permissões insuficientes.');
    }
    
    $sessionId = filter_input(INPUT_GET, 'session_id', FILTER_SANITIZE_STRING);
    
    if (!$sessionId) {
        return jsonResponse(false, 'Session ID não fornecido.');
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                ultimo_campo,
                acao,
                valor_campo,
                timestamp
            FROM form_interactions
            WHERE session_id = ?
            ORDER BY timestamp ASC
        ");
        
        $stmt->execute([$sessionId]);
        $interactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return jsonResponse(true, 'Detalhes da sessão carregados', ['interactions' => $interactions]);
        
    } catch (Exception $e) {
        logError('Erro ao buscar detalhes da sessão: ' . $e->getMessage());
        return jsonResponse(false, 'Erro ao carregar detalhes da sessão.');
    }
}

/**
 * Limpa todas as interações do formulário
 */
function apiClearInteractions($pdo) {
    if (!isAdmin()) {
        logError("Tentativa de limpeza de interações sem permissão admin", 'WARNING');
        return jsonResponse(false, 'Acesso negado: permissões insuficientes.');
    }
    
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        return jsonResponse(false, 'Erro de validação de segurança (CSRF).');
    }
    
    try {
        $stmt = $pdo->prepare("TRUNCATE TABLE form_interactions");
        $stmt->execute();
        
        logError("Todas as interações foram limpas pelo administrador", 'WARNING');
        
        return jsonResponse(true, 'Todas as interações foram limpas com sucesso.');
        
    } catch (PDOException $e) {
        logError('Erro ao limpar interações: ' . $e->getMessage());
        return jsonResponse(false, 'Erro ao limpar interações.');
    }
}

/**
 * Exporta dados de interações
 */
function apiExportInteractions($pdo) {
    if (!isAdmin()) {
        return jsonResponse(false, 'Acesso negado: permissões insuficientes.');
    }
    
    $period = filter_input(INPUT_GET, 'period', FILTER_SANITIZE_STRING) ?: 'all';
    $format = filter_input(INPUT_GET, 'format', FILTER_SANITIZE_STRING) ?: 'csv';
    
    try {
        // Construir query com filtro de período
        $where = ["1=1"];
        
        switch ($period) {
            case 'today':
                $where[] = "DATE(timestamp) = CURDATE()";
                break;
            case 'week':
                $where[] = "timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                break;
            case 'month':
                $where[] = "timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
                break;
        }
        
        $whereClause = implode(" AND ", $where);
        
        $stmt = $pdo->prepare("
            SELECT 
                session_id,
                ip,
                browser,
                os,
                device,
                nome_completo,
                acao,
                ultimo_campo,
                valor_campo,
                timestamp
            FROM form_interactions
            WHERE $whereClause
            ORDER BY timestamp DESC
        ");
        
        $stmt->execute();
        $interactions = $stmt->fetchAll();
        
        if (empty($interactions)) {
            return jsonResponse(false, 'Nenhuma interação encontrada para exportar.');
        }
        
        if ($format === 'json') {
            // Exportar como JSON
            $filename = 'interacoes_export_' . date('Y-m-d_H-i-s') . '.json';
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');
            
            echo json_encode($interactions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
        } else {
            // Exportar como CSV
            $filename = 'interacoes_export_' . date('Y-m-d_H-i-s') . '.csv';
            arrayToCsv($interactions, $filename);
        }
        
        logAccess("Dados de interações exportados", [
            'total_registros' => count($interactions),
            'periodo' => $period,
            'formato' => $format
        ]);
        
    } catch (Exception $e) {
        logError('Erro ao exportar interações: ' . $e->getMessage());
        return jsonResponse(false, 'Erro ao exportar dados de interações.');
    }
}

/**
 * Obtém análise de comportamento por dispositivo
 */
function apiGetDeviceAnalysis($pdo) {
    if (!isAdmin()) {
        return jsonResponse(false, 'Acesso negado: permissões insuficientes.');
    }
    
    try {
        // Análise por dispositivo
        $stmt = $pdo->query("
            SELECT 
                device,
                COUNT(DISTINCT session_id) as total_sessions,
                COUNT(DISTINCT CASE WHEN acao IN ('form_submitted', 'form_submit_click') THEN session_id END) as completed_sessions,
                ROUND(
                    COUNT(DISTINCT CASE WHEN acao IN ('form_submitted', 'form_submit_click') THEN session_id END) * 100.0 
                    / NULLIF(COUNT(DISTINCT session_id), 0), 2
                ) as conversion_rate
            FROM form_interactions
            GROUP BY device
            ORDER BY total_sessions DESC
        ");
        
        $deviceData = $stmt->fetchAll();
        
        // Análise por navegador
        $stmt = $pdo->query("
            SELECT 
                browser,
                COUNT(DISTINCT session_id) as total_sessions,
                COUNT(DISTINCT CASE WHEN acao IN ('form_submitted', 'form_submit_click') THEN session_id END) as completed_sessions,
                ROUND(
                    COUNT(DISTINCT CASE WHEN acao IN ('form_submitted', 'form_submit_click') THEN session_id END) * 100.0 
                    / NULLIF(COUNT(DISTINCT session_id), 0), 2
                ) as conversion_rate
            FROM form_interactions
            GROUP BY browser
            ORDER BY total_sessions DESC
            LIMIT 10
        ");
        
        $browserData = $stmt->fetchAll();
        
        return jsonResponse(true, 'Análise de dispositivos carregada', [
            'devices' => $deviceData,
            'browsers' => $browserData
        ]);
        
    } catch (Exception $e) {
        logError('Erro na análise de dispositivos: ' . $e->getMessage());
        return jsonResponse(false, 'Erro na análise de dispositivos.');
    }
}

/**
 * Obtém análise de tempo de preenchimento
 */
function apiGetTimeAnalysis($pdo) {
    if (!isAdmin()) {
        return jsonResponse(false, 'Acesso negado: permissões insuficientes.');
    }
    
    try {
        // Tempo médio de preenchimento por sessão completa
        $stmt = $pdo->query("
            SELECT 
                session_id,
                MIN(timestamp) as start_time,
                MAX(timestamp) as end_time,
                TIMESTAMPDIFF(SECOND, MIN(timestamp), MAX(timestamp)) as duration_seconds
            FROM form_interactions
            WHERE session_id IN (
                SELECT DISTINCT session_id 
                FROM form_interactions 
                WHERE acao IN ('form_submitted', 'form_submit_click')
            )
            GROUP BY session_id
            HAVING duration_seconds > 0
            ORDER BY duration_seconds DESC
        ");
        
        $timeData = $stmt->fetchAll();
        
        if (empty($timeData)) {
            return jsonResponse(true, 'Dados de tempo', ['times' => []]);
        }
        
        // Calcular estatísticas
        $durations = array_column($timeData, 'duration_seconds');
        $avgDuration = round(array_sum($durations) / count($durations));
        $minDuration = min($durations);
        $maxDuration = max($durations);
        
        // Converter para formato mais legível
        foreach ($timeData as &$time) {
            $time['duration_formatted'] = gmdate('H:i:s', $time['duration_seconds']);
        }
        
        return jsonResponse(true, 'Análise de tempo carregada', [
            'times' => $timeData,
            'stats' => [
                'average_seconds' => $avgDuration,
                'average_formatted' => gmdate('H:i:s', $avgDuration),
                'min_seconds' => $minDuration,
                'min_formatted' => gmdate('H:i:s', $minDuration),
                'max_seconds' => $maxDuration,
                'max_formatted' => gmdate('H:i:s', $maxDuration)
            ]
        ]);
        
    } catch (Exception $e) {
        logError('Erro na análise de tempo: ' . $e->getMessage());
        return jsonResponse(false, 'Erro na análise de tempo.');
    }
}
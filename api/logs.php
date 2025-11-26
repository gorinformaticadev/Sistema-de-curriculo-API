<?php
/**
 * API para gerenciamento de logs
 * Responsável por operações relacionadas aos logs do sistema
 */

/**
 * Carrega logs de erro
 */
function apiGetLogs($pdo) {
    if (!isAdmin()) {
        logError("Tentativa de acesso aos logs sem permissão admin", 'WARNING');
        return jsonResponse(false, 'Acesso negado: permissões insuficientes.');
    }
    
    $logs = [];
    $logFile = 'error.log';
    
    try {
        if (file_exists($logFile)) {
            $lines = file($logFile, FILE_IGNORE_NEW_LINES);
            $logs = array_reverse(array_slice($lines, -100)); // Últimos 100 logs
        }
        
        return jsonResponse(true, 'Logs carregados com sucesso', ['logs' => $logs]);
        
    } catch (Exception $e) {
        logError('Erro ao carregar logs: ' . $e->getMessage());
        return jsonResponse(false, 'Erro ao carregar logs.');
    }
}

/**
 * Carrega logs de acesso
 */
function apiGetAccessLogs($pdo) {
    if (!isAdmin()) {
        logError("Tentativa de acesso aos logs de acesso sem permissão admin", 'WARNING');
        return jsonResponse(false, 'Acesso negado: permissões insuficientes.');
    }
    
    $logs = [];
    $logFile = 'access.log';
    
    try {
        if (file_exists($logFile)) {
            $lines = file($logFile, FILE_IGNORE_NEW_LINES);
            $logs = array_reverse(array_slice($lines, -100)); // Últimos 100 logs
        }
        
        return jsonResponse(true, 'Logs de acesso carregados com sucesso', ['logs' => $logs]);
        
    } catch (Exception $e) {
        logError('Erro ao carregar logs de acesso: ' . $e->getMessage());
        return jsonResponse(false, 'Erro ao carregar logs de acesso.');
    }
}

/**
 * Limpa logs de erro
 */
function apiClearLogs($pdo) {
    if (!isAdmin()) {
        logError("Tentativa de limpeza de logs sem permissão admin", 'WARNING');
        return jsonResponse(false, 'Acesso negado: permissões insuficientes.');
    }
    
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        return jsonResponse(false, 'Erro de validação de segurança (CSRF).');
    }
    
    $logFile = 'error.log';
    
    try {
        if (file_exists($logFile)) {
            file_put_contents($logFile, '');
            
            logError("Logs limpos pelo administrador", 'INFO');
            
            return jsonResponse(true, 'Logs de erro limpos com sucesso!');
        } else {
            return jsonResponse(false, 'Arquivo de logs não encontrado.');
        }
        
    } catch (Exception $e) {
        logError('Erro ao limpar logs: ' . $e->getMessage());
        return jsonResponse(false, 'Erro ao limpar logs.');
    }
}

/**
 * Limpa logs de acesso
 */
function apiClearAccessLogs($pdo) {
    if (!isAdmin()) {
        logError("Tentativa de limpeza de logs de acesso sem permissão admin", 'WARNING');
        return jsonResponse(false, 'Acesso negado: permissões insuficientes.');
    }
    
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        return jsonResponse(false, 'Erro de validação de segurança (CSRF).');
    }
    
    $logFile = 'access.log';
    
    try {
        if (file_exists($logFile)) {
            file_put_contents($logFile, '');
            
            logAccess("Logs de acesso limpos pelo administrador");
            
            return jsonResponse(true, 'Logs de acesso limpos com sucesso!');
        } else {
            return jsonResponse(false, 'Arquivo de logs de acesso não encontrado.');
        }
        
    } catch (Exception $e) {
        logError('Erro ao limpar logs de acesso: ' . $e->getMessage());
        return jsonResponse(false, 'Erro ao limpar logs de acesso.');
    }
}

/**
 * Busca logs por filtros
 */
function apiSearchLogs($pdo) {
    if (!isAdmin()) {
        return jsonResponse(false, 'Acesso negado: permissões insuficientes.');
    }
    
    $type = filter_input(INPUT_GET, 'type', FILTER_SANITIZE_STRING) ?: 'error'; // error, access
    $level = filter_input(INPUT_GET, 'level', FILTER_SANITIZE_STRING); // ERROR, WARNING, INFO, etc.
    $search = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING);
    $date = filter_input(INPUT_GET, 'date', FILTER_SANITIZE_STRING); // YYYY-MM-DD
    $limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT) ?: 50;
    
    try {
        $logFile = $type === 'access' ? 'access.log' : 'error.log';
        
        if (!file_exists($logFile)) {
            return jsonResponse(true, 'Arquivo de log não encontrado', ['logs' => []]);
        }
        
        $lines = file($logFile, FILE_IGNORE_NEW_LINES);
        $filteredLogs = [];
        
        foreach (array_reverse($lines) as $line) {
            // Filtro por nível (apenas para error.log)
            if ($type === 'error' && !empty($level)) {
                if (!strpos($line, "[$level]")) {
                    continue;
                }
            }
            
            // Filtro por data
            if (!empty($date)) {
                if (!strpos($line, $date)) {
                    continue;
                }
            }
            
            // Filtro por texto
            if (!empty($search)) {
                if (!stripos($line, $search)) {
                    continue;
                }
            }
            
            $filteredLogs[] = $line;
            
            if (count($filteredLogs) >= $limit) {
                break;
            }
        }
        
        return jsonResponse(true, 'Busca realizada com sucesso', ['logs' => $filteredLogs]);
        
    } catch (Exception $e) {
        logError('Erro na busca de logs: ' . $e->getMessage());
        return jsonResponse(false, 'Erro na busca de logs.');
    }
}

/**
 * Exporta logs para arquivo
 */
function apiExportLogs($pdo) {
    if (!isAdmin()) {
        return jsonResponse(false, 'Acesso negado: permissões insuficientes.');
    }
    
    $type = filter_input(INPUT_GET, 'type', FILTER_SANITIZE_STRING) ?: 'error';
    $level = filter_input(INPUT_GET, 'level', FILTER_SANITIZE_STRING);
    $date = filter_input(INPUT_GET, 'date', FILTER_SANITIZE_STRING);
    
    try {
        $logFile = $type === 'access' ? 'access.log' : 'error.log';
        
        if (!file_exists($logFile)) {
            return jsonResponse(false, 'Arquivo de log não encontrado.');
        }
        
        $lines = file($logFile, FILE_IGNORE_NEW_LINES);
        $filteredLogs = [];
        
        foreach (array_reverse($lines) as $line) {
            // Aplicar os mesmos filtros da busca
            if (!empty($level) && !strpos($line, "[$level]")) {
                continue;
            }
            
            if (!empty($date) && !strpos($line, $date)) {
                continue;
            }
            
            $filteredLogs[] = $line;
        }
        
        // Gerar CSV
        $csvData = [];
        foreach ($filteredLogs as $index => $line) {
            // Parsear linha de log
            $csvData[] = [
                'data' => $index + 1,
                'log' => $line
            ];
        }
        
        $filename = "logs_{$type}_export_" . date('Y-m-d_H-i-s') . '.csv';
        arrayToCsv($csvData, $filename);
        
        logAccess("Logs exportados", [
            'tipo' => $type,
            'filtro_nivel' => $level ?: 'todos',
            'filtro_data' => $date ?: 'todas',
            'total_logs' => count($filteredLogs)
        ]);
        
    } catch (Exception $e) {
        logError('Erro ao exportar logs: ' . $e->getMessage());
        return jsonResponse(false, 'Erro ao exportar logs.');
    }
}

/**
 * Obtém estatísticas dos logs
 */
function apiGetLogStats($pdo) {
    if (!isAdmin()) {
        return jsonResponse(false, 'Acesso negado: permissões insuficientes.');
    }
    
    try {
        $stats = [
            'error_logs' => 0,
            'access_logs' => 0,
            'last_errors' => [],
            'log_levels' => [],
            'daily_activity' => []
        ];
        
        // Estatísticas de error.log
        if (file_exists('error.log')) {
            $errorLines = file('error.log', FILE_IGNORE_NEW_LINES);
            $stats['error_logs'] = count($errorLines);
            
            // Últimos erros
            $stats['last_errors'] = array_slice(array_reverse($errorLines), 0, 10);
            
            // Contagem por nível
            foreach ($errorLines as $line) {
                if (preg_match('/\[(ERROR|WARNING|INFO|SUCCESS)\]/', $line, $matches)) {
                    $level = $matches[1];
                    $stats['log_levels'][$level] = ($stats['log_levels'][$level] ?? 0) + 1;
                }
            }
        }
        
        // Estatísticas de access.log
        if (file_exists('access.log')) {
            $accessLines = file('access.log', FILE_IGNORE_NEW_LINES);
            $stats['access_logs'] = count($accessLines);
        }
        
        // Atividade diária (últimos 7 dias)
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $count = 0;
            
            // Contar acessos no dia
            if (file_exists('access.log')) {
                $accessLines = file('access.log', FILE_IGNORE_NEW_LINES);
                foreach ($accessLines as $line) {
                    if (strpos($line, $date) !== false) {
                        $count++;
                    }
                }
            }
            
            $stats['daily_activity'][] = [
                'date' => $date,
                'access_count' => $count
            ];
        }
        
        return jsonResponse(true, 'Estatísticas dos logs carregadas', ['stats' => $stats]);
        
    } catch (Exception $e) {
        logError('Erro ao obter estatísticas dos logs: ' . $e->getMessage());
        return jsonResponse(false, 'Erro ao carregar estatísticas dos logs.');
    }
}

/**
 * Comprime logs antigos
 */
function apiCompressOldLogs($pdo) {
    if (!isAdmin()) {
        return jsonResponse(false, 'Acesso negado: permissões insuficientes.');
    }
    
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        return jsonResponse(false, 'Erro de validação de segurança (CSRF).');
    }
    
    try {
        $compressedFiles = [];
        
        // Comprimir error.log se muito grande
        if (file_exists('error.log') && filesize('error.log') > 1024 * 1024) { // 1MB
            $backupName = 'error_' . date('Y-m-d_H-i-s') . '.log.bz2';
            $backupPath = 'backups/' . $backupName;
            
            if (!file_exists('backups')) {
                mkdir('backups', 0755, true);
            }
            
            // Usar comando bzip2 se disponível, senão copiar arquivo
            if (function_exists('bzopen')) {
                $bz = bzopen($backupPath, 'w');
                $content = file_get_contents('error.log');
                bzwrite($bz, $content);
                bzclose($bz);
                file_put_contents('error.log', ''); // Limpar arquivo original
                $compressedFiles[] = $backupName;
            } else {
                // Fallback: copiar e limpar
                copy('error.log', $backupPath);
                file_put_contents('error.log', '');
                $compressedFiles[] = $backupName . ' (copy)';
            }
        }
        
        // Fazer o mesmo para access.log
        if (file_exists('access.log') && filesize('access.log') > 1024 * 1024) { // 1MB
            $backupName = 'access_' . date('Y-m-d_H-i-s') . '.log.bz2';
            $backupPath = 'backups/' . $backupName;
            
            if (function_exists('bzopen')) {
                $bz = bzopen($backupPath, 'w');
                $content = file_get_contents('access.log');
                bzwrite($bz, $content);
                bzclose($bz);
                file_put_contents('access.log', '');
                $compressedFiles[] = $backupName;
            } else {
                copy('access.log', $backupPath);
                file_put_contents('access.log', '');
                $compressedFiles[] = $backupName . ' (copy)';
            }
        }
        
        if (!empty($compressedFiles)) {
            logAccess("Logs antigos comprimidos", [
                'arquivos_comprimidos' => $compressedFiles
            ]);
            
            return jsonResponse(true, 'Logs antigos comprimidos com sucesso!', [
                'compressed_files' => $compressedFiles
            ]);
        } else {
            return jsonResponse(false, 'Nenhum log encontrado para comprimir (tamanho menor que 1MB).');
        }
        
    } catch (Exception $e) {
        logError('Erro ao comprimir logs: ' . $e->getMessage());
        return jsonResponse(false, 'Erro ao comprimir logs antigos.');
    }
}
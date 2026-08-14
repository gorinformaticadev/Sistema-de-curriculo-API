<?php
/**
 * API para gerenciamento de currículos
 * Responsável por operações CRUD e visualização de currículos
 */

/**
 * Lista todos os currículos
 */
function apiGetCurriculos($pdo) {
    if (!canAccessAction('getCurriculos')) {
        logError("Tentativa de acesso não autorizado à ação 'getCurriculos'", 'WARNING');
        return jsonResponse(false, 'Acesso negado: permissões insuficientes.');
    }
    
    try {
        $stmt = $pdo->query("SELECT id, nome, telefone, email, cidade, data_cadastro FROM curriculos ORDER BY data_cadastro DESC");
        $curriculos = $stmt->fetchAll();
        
        return jsonResponse(true, 'Currículos carregados com sucesso', ['curriculos' => $curriculos]);
        
    } catch (Exception $e) {
        logError('Erro ao carregar currículos: ' . $e->getMessage());
        return jsonResponse(false, 'Erro ao carregar currículos.');
    }
}

/**
 * Obtém detalhes de um currículo específico
 */
function apiGetCurriculoDetails($pdo) {
    if (!canAccessAction('getCurriculoDetails')) {
        logError("Tentativa de acesso não autorizado à ação 'getCurriculoDetails'", 'WARNING');
        return jsonResponse(false, 'Acesso negado: permissões insuficientes.');
    }
    
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        return jsonResponse(false, 'ID inválido.');
    }
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM curriculos WHERE id = ?");
        $stmt->execute([$id]);
        $curriculo = $stmt->fetch();
        
        if ($curriculo) {
            // Decodificar o JSON de experiências para um formato mais amigável
            if (!empty($curriculo['experiencias'])) {
                $curriculo['experiencias'] = json_decode($curriculo['experiencias'], true);
            }
            
            // Decodificar JSON de habilidades e referências
            if (!empty($curriculo['habilidades'])) {
                $habilidadesDecoded = json_decode($curriculo['habilidades'], true);
                $curriculo['habilidades'] = is_array($habilidadesDecoded) ? $habilidadesDecoded : [];
            } else {
                $curriculo['habilidades'] = [];
            }
            if (!empty($curriculo['referencias'])) {
                $referenciasDecoded = json_decode($curriculo['referencias'], true);
                $curriculo['referencias'] = is_array($referenciasDecoded) ? $referenciasDecoded : [];
            } else {
                $curriculo['referencias'] = [];
            }
            
            // Converter valores booleanos de volta para texto para exibição
            $curriculo['is_whatsapp'] = $curriculo['is_whatsapp'] ? 'Sim' : 'Não';
            $curriculo['possui_filhos'] = $curriculo['possui_filhos'] ? 'Sim' : 'Não';
            $curriculo['estudando'] = $curriculo['estudando'] ? 'Sim, estou!' : 'Não, não estou!';
            $curriculo['possui_cursos'] = $curriculo['possui_cursos'] ? 'Sim' : 'Não';
            $curriculo['possui_experiencia'] = $curriculo['possui_experiencia'] ? 'Sim' : 'Não';
            $curriculo['consentimento_lgpd'] = !empty($curriculo['consentimento_lgpd']) ? 'Sim' : 'Não';
            $curriculo['consentimento_banco_talentos'] = !empty($curriculo['consentimento_banco_talentos']) ? 'Sim' : 'Não';
            
            logAccess("Currículo visualizado", [
                'curriculo_id' => $id,
                'nome' => $curriculo['nome']
            ]);
            
            return jsonResponse(true, 'Currículo carregado com sucesso', ['curriculo' => $curriculo]);
        } else {
            return jsonResponse(false, 'Currículo não encontrado.');
        }
        
    } catch (Exception $e) {
        logError("Erro ao carregar detalhes do currículo ID: $id - " . $e->getMessage());
        return jsonResponse(false, 'Erro ao carregar detalhes do currículo.');
    }
}

/**
 * Deleta um currículo
 */
function apiDeleteCurriculo($pdo) {
    if (!isAdmin()) {
        logError("Tentativa de deleção de currículo sem permissão admin", 'WARNING');
        return jsonResponse(false, 'Acesso negado: permissões insuficientes.');
    }
    
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        return jsonResponse(false, 'Erro de validação de segurança (CSRF).');
    }
    
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        return jsonResponse(false, 'ID inválido.');
    }
    
    try {
        $pdo->beginTransaction();
        
        // 1. Buscar os nomes dos arquivos antes de deletar o registro
        $stmt = $pdo->prepare("SELECT arquivo_curriculo, arquivo_foto, nome FROM curriculos WHERE id = ?");
        $stmt->execute([$id]);
        $files = $stmt->fetch();
        
        if (!$files) {
            throw new Exception('Currículo não encontrado no banco de dados.');
        }
        
        // 2. Deletar o registro do banco de dados
        $stmt = $pdo->prepare("DELETE FROM curriculos WHERE id = ?");
        $stmt->execute([$id]);
        
        // 3. Deletar os arquivos físicos
        $uploadDir = 'uploads/';
        $deletedFiles = [];
        
        if (!empty($files['arquivo_curriculo']) && file_exists($uploadDir . $files['arquivo_curriculo'])) {
            unlink($uploadDir . $files['arquivo_curriculo']);
            $deletedFiles[] = $files['arquivo_curriculo'];
        }
        
        if (!empty($files['arquivo_foto']) && file_exists($uploadDir . $files['arquivo_foto'])) {
            unlink($uploadDir . $files['arquivo_foto']);
            $deletedFiles[] = $files['arquivo_foto'];
        }
        
        $pdo->commit();
        
        logAccess("Currículo deletado", [
            'curriculo_id' => $id,
            'nome' => $files['nome'],
            'arquivos_deletados' => $deletedFiles
        ]);
        
        return jsonResponse(true, 'Currículo deletado com sucesso.');
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        logError("Falha ao deletar currículo ID: $id. Erro: " . $e->getMessage());
        return jsonResponse(false, 'Erro ao deletar o currículo: ' . $e->getMessage());
    }
}

/**
 * Busca currículos com filtros
 */
function apiSearchCurriculos($pdo) {
    if (!canAccessAction('getCurriculos')) {
        return jsonResponse(false, 'Acesso negado: permissões insuficientes.');
    }
    
    $search = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING) ?? '';
    $limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT) ?: 50;
    $offset = filter_input(INPUT_GET, 'offset', FILTER_VALIDATE_INT) ?: 0;
    
    try {
        $where = [];
        $params = [];
        
        // Filtro de busca
        if (!empty($search)) {
            $where[] = "(nome LIKE ? OR email LIKE ? OR telefone LIKE ? OR cidade LIKE ?)";
            $searchTerm = "%$search%";
            $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm];
        }
        
        $whereClause = empty($where) ? '1=1' : implode(' AND ', $where);
        
        // Contar total
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM curriculos WHERE $whereClause");
        $countStmt->execute($params);
        $total = $countStmt->fetchColumn();
        
        // Buscar resultados
        $stmt = $pdo->prepare("
            SELECT id, nome, telefone, email, cidade, data_cadastro 
            FROM curriculos 
            WHERE $whereClause 
            ORDER BY data_cadastro DESC 
            LIMIT ? OFFSET ?
        ");
        
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $curriculos = $stmt->fetchAll();
        
        // Paginação
        $pagination = paginateResults(
            floor($offset / $limit) + 1, 
            $limit, 
            $total
        );
        
        return jsonResponse(true, 'Busca realizada com sucesso', [
            'curriculos' => $curriculos,
            'pagination' => $pagination
        ]);
        
    } catch (Exception $e) {
        logError('Erro na busca de currículos: ' . $e->getMessage());
        return jsonResponse(false, 'Erro ao realizar busca.');
    }
}

/**
 * Exporta currículos para CSV
 */
function apiExportCurriculos($pdo) {
    if (!isAdmin()) {
        return jsonResponse(false, 'Acesso negado: permissões insuficientes.');
    }
    
    try {
        $stmt = $pdo->query("SELECT * FROM curriculos ORDER BY data_cadastro DESC");
        $curriculos = $stmt->fetchAll();
        
        if (empty($curriculos)) {
            return jsonResponse(false, 'Nenhum currículo encontrado para exportar.');
        }
        
        // Formatar dados para exportação
        $exportData = [];
        foreach ($curriculos as $curriculo) {
            $habilidadesArray = !empty($curriculo['habilidades']) ? json_decode($curriculo['habilidades'], true) : [];
            $referenciasArray = !empty($curriculo['referencias']) ? json_decode($curriculo['referencias'], true) : [];
            $referenciasTexto = [];
            if (is_array($referenciasArray)) {
                foreach ($referenciasArray as $ref) {
                    $referenciasTexto[] = trim(($ref['nome'] ?? '') . ' | ' . ($ref['empresa'] ?? '') . ' | ' . ($ref['cargo'] ?? '') . ' | ' . ($ref['telefone'] ?? ''), " |");
                }
            }
            $exportData[] = [
                'ID' => $curriculo['id'],
                'Nome' => $curriculo['nome'],
                'Email' => $curriculo['email'],
                'Telefone' => $curriculo['telefone'],
                'Cidade' => $curriculo['cidade'],
                'Estado' => $curriculo['estado'],
                'Estado_Civil' => $curriculo['estado_civil'],
                'Possui_Filhos' => $curriculo['possui_filhos'] ? 'Sim' : 'Não',
                'Escolaridade' => $curriculo['escolaridade'],
                'Curso_Atual' => $curriculo['curso_atual'] ?? '',
                'Instituicao' => $curriculo['instituicao_curso'] ?? '',
                'Situacao_Curso' => $curriculo['situacao_curso'] ?? '',
                'Ano_Conclusao' => $curriculo['ano_conclusao_curso'] ?? '',
                'Habilidades' => is_array($habilidadesArray) ? implode('; ', $habilidadesArray) : '',
                'Conhecimento_Informatica' => $curriculo['conhecimento_informatica'] ?? '',
                'Disponibilidade_Inicio' => $curriculo['disponibilidade_inicio'] ?? '',
                'Disponibilidade_Sabados' => $curriculo['disponibilidade_sabados'] ?? '',
                'Disponibilidade_Horas_Extras' => $curriculo['disponibilidade_horas_extras'] ?? '',
                'Pretensao_Salarial' => $curriculo['pretensao_salarial'] ?? '',
                'Como_Conheceu' => $curriculo['como_conheceu'] ?? '',
                'Referencias' => implode(' // ', $referenciasTexto),
                'Consentimento_LGPD' => !empty($curriculo['consentimento_lgpd']) ? 'Sim' : 'Não',
                'Banco_Talentos' => !empty($curriculo['consentimento_banco_talentos']) ? 'Sim' : 'Não',
                'Data_Cadastro' => formatDate($curriculo['data_cadastro']),
                'IP_Cadastro' => $curriculo['ip_cadastro']
            ];
        }
        
        logAccess("Currículos exportados", [
            'total_exportados' => count($exportData),
            'exportado_por' => $_SESSION['user_email']
        ]);
        
        // Gerar CSV
        $filename = 'curriculos_export_' . date('Y-m-d_H-i-s') . '.csv';
        arrayToCsv($exportData, $filename);
        
    } catch (Exception $e) {
        logError('Erro ao exportar currículos: ' . $e->getMessage());
        return jsonResponse(false, 'Erro ao exportar currículos.');
    }
}

/**
 * Obtém estatísticas dos currículos
 */
function apiGetCurriculoStats($pdo) {
    if (!isAdmin()) {
        return jsonResponse(false, 'Acesso negado: permissões insuficientes.');
    }
    
    try {
        // Total de currículos
        $stmt = $pdo->query("SELECT COUNT(*) FROM curriculos");
        $total = $stmt->fetchColumn();
        
        // Currículos por mês (últimos 6 meses)
        $stmt = $pdo->query("
            SELECT 
                DATE_FORMAT(data_cadastro, '%Y-%m') as mes,
                COUNT(*) as total
            FROM curriculos 
            WHERE data_cadastro >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(data_cadastro, '%Y-%m')
            ORDER BY mes DESC
        ");
        $porMes = $stmt->fetchAll();
        
        // Currículos por cidade (top 10)
        $stmt = $pdo->query("
            SELECT cidade, COUNT(*) as total
            FROM curriculos 
            GROUP BY cidade
            ORDER BY total DESC
            LIMIT 10
        ");
        $porCidade = $stmt->fetchAll();
        
        return jsonResponse(true, 'Estatísticas carregadas com sucesso', [
            'total' => $total,
            'por_mes' => $porMes,
            'por_cidade' => $porCidade
        ]);
        
    } catch (Exception $e) {
        logError('Erro ao buscar estatísticas de currículos: ' . $e->getMessage());
        return jsonResponse(false, 'Erro ao carregar estatísticas.');
    }
}
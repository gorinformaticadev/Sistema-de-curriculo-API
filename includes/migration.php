<?php
/**
 * Sistema de Migração do Banco de Dados
 * Gerencia atualizações do banco sem perda de dados
 */

/**
 * Função helper para criar índice apenas se não existir
 * MySQL não suporta CREATE INDEX IF NOT EXISTS
 */
function createIndexIfNotExists($pdo, $indexName, $tableName, $columnName) {
    try {
        // Verificar se o índice já existe
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
            AND table_name = ?
            AND index_name = ?
        ");
        $stmt->execute([$tableName, $indexName]);

        if ($stmt->fetch()['count'] == 0) {
            // Índice não existe, criar
            $pdo->exec("CREATE INDEX {$indexName} ON {$tableName}({$columnName})");
        }
    } catch (Exception $e) {
        // Ignorar erros (índice já existe ou outro problema)
    }
}

class DatabaseMigration {
    private $pdo;
    private $versionTable = 'db_version';
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->createVersionTable();
    }
    
    /**
     * Cria a tabela de versionamento se não existir
     */
    private function createVersionTable() {
        $sql = "
            CREATE TABLE IF NOT EXISTS `{$this->versionTable}` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `version` VARCHAR(20) NOT NULL UNIQUE,
                `description` TEXT,
                `applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `script_hash` VARCHAR(64)
            ) ENGINE=InnoDB;
        ";
        $this->pdo->exec($sql);
    }
    
    /**
     * Obtém a versão atual do banco de dados
     */
    public function getCurrentVersion() {
        try {
            $stmt = $this->pdo->query("SELECT version FROM {$this->versionTable} ORDER BY applied_at DESC LIMIT 1");
            $result = $stmt->fetch();
            return $result ? $result['version'] : '0.0.0';
        } catch (Exception $e) {
            return '0.0.0';
        }
    }
    
    /**
     * Verifica se existem migrações pendentes sem executá-las
     * Consulta barata (1 SELECT) usada a cada carregamento para decidir se executa migrate()
     */
    public function hasPendingMigrations() {
        $currentVersion = $this->getCurrentVersion();
        $availableMigrations = $this->getAvailableMigrations();
        
        try {
            $stmt = $this->pdo->query("SELECT version FROM {$this->versionTable}");
            $applied = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            // Sem histórico de versões: considera que há pendências
            return true;
        }
        
        $appliedMap = array_flip($applied);
        foreach ($availableMigrations as $version => $migration) {
            if (!isset($appliedMap[$version]) && compareVersions($currentVersion, $version, '<')) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Obtém todas as migrações disponíveis
     */
    public function getAvailableMigrations() {
        return [
            '1.0.0' => [
                'description' => 'Estrutura inicial do sistema',
                'script' => function($pdo) {
                    // Criar todas as tabelas básicas
                    $sqls = [
                        'usuarios' => "
                            CREATE TABLE IF NOT EXISTS `usuarios` (
                                `id` INT AUTO_INCREMENT PRIMARY KEY,
                                `email` VARCHAR(255) NOT NULL UNIQUE,
                                `senha` VARCHAR(255) NOT NULL,
                                `tipo` ENUM('admin', 'analisador') DEFAULT 'admin',
                                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                INDEX `idx_email` (`email`)
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                        ",
                        'config' => "
                            CREATE TABLE IF NOT EXISTS `config` (
                                `id` INT AUTO_INCREMENT PRIMARY KEY,
                                `chave` VARCHAR(255) NOT NULL UNIQUE,
                                `valor` TEXT,
                                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                        ",
                        'curriculos' => "
                            CREATE TABLE IF NOT EXISTS `curriculos` (
                                `id` INT AUTO_INCREMENT PRIMARY KEY,
                                `nome` VARCHAR(255) NOT NULL,
                                `data_nascimento` DATE NOT NULL,
                                `estado_civil` VARCHAR(50),
                                `telefone` VARCHAR(255),
                                `is_whatsapp` TINYINT(1) DEFAULT 0,
                                `email` VARCHAR(255),
                                `facebook` VARCHAR(255),
                                `instagram` VARCHAR(255),
                                `endereco` TEXT,
                                `cidade` VARCHAR(255),
                                `estado` VARCHAR(255),
                                `escolaridade` VARCHAR(255),
                                `estudando` TINYINT(1) DEFAULT 0,
                                `periodo_estudo` VARCHAR(50),
                                `possui_cursos` TINYINT(1) DEFAULT 0,
                                `cursos` TEXT,
                                `possui_experiencia` TINYINT(1) DEFAULT 0,
                                `experiencias` TEXT,
                                `motivacao` TEXT,
                                `arquivo_curriculo` VARCHAR(255),
                                `arquivo_foto` VARCHAR(255),
                                `ip_cadastro` VARCHAR(45),
                                `data_cadastro` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                INDEX `idx_nome` (`nome`),
                                INDEX `idx_email` (`email`),
                                INDEX `idx_data_cadastro` (`data_cadastro`)
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                        "
                    ];
                    
                    foreach ($sqls as $table => $sql) {
                        $pdo->exec($sql);
                    }
                    
                    return "Estrutura inicial criada com sucesso";
                }
            ],
            '1.1.0' => [
                'description' => 'Adição da tabela de interações do formulário',
                'script' => function($pdo) {
                    $sql = "
                        CREATE TABLE IF NOT EXISTS `form_interactions` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `session_id` VARCHAR(255) NOT NULL,
                            `ip` VARCHAR(45) NOT NULL,
                            `user_agent` TEXT,
                            `browser` VARCHAR(100),
                            `os` VARCHAR(100),
                            `device` VARCHAR(50),
                            `nome_completo` VARCHAR(255) DEFAULT NULL,
                            `ultimo_campo` VARCHAR(100),
                            `acao` VARCHAR(50),
                            `valor_campo` TEXT,
                            `timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            INDEX `idx_session` (`session_id`),
                            INDEX `idx_ip` (`ip`),
                            INDEX `idx_timestamp` (`timestamp`),
                            INDEX `idx_device` (`device`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                    ";
                    $pdo->exec($sql);
                    
                    return "Tabela de interações criada com sucesso";
                }
            ],
            '1.2.0' => [
                'description' => 'Melhorias nas configurações e índices',
                'script' => function($pdo) {
                    // Adicionar coluna tipo à tabela usuarios se não existir
                    try {
                        $pdo->exec("ALTER TABLE usuarios ADD COLUMN tipo ENUM('admin', 'analisador') DEFAULT 'admin' AFTER senha");
                        $pdo->exec("UPDATE usuarios SET tipo = 'admin' WHERE tipo IS NULL");
                        $pdo->exec("ALTER TABLE usuarios MODIFY COLUMN tipo ENUM('admin', 'analisador') NOT NULL DEFAULT 'admin'");
                    } catch (Exception $e) {
                        // Coluna já existe ou erro não crítico
                    }
                    
                    // Adicionar índices faltantes
                    createIndexIfNotExists($pdo, 'idx_config_chave', 'config', 'chave');
                    createIndexIfNotExists($pdo, 'idx_curriculos_cidade', 'curriculos', 'cidade');
                    createIndexIfNotExists($pdo, 'idx_curriculos_estado', 'curriculos', 'estado');
                    
                    return "Índices e melhorias aplicadas com sucesso";
                }
            ],
            '2.0.0' => [
                'description' => 'Reorganização completa do sistema',
                'script' => function($pdo) {
                    // Atualizar estrutura para compatibilidade com nova versão

                    // Verificar e adicionar colunas que podem estar faltando
                    $columnsToAdd = [
                        'usuarios' => [
                            'tipo' => "ENUM('admin', 'analisador') DEFAULT 'admin'",
                            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
                        ],
                        'config' => [
                            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
                            'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
                        ],
                        'curriculos' => [
                            'INDEXes' => true
                        ]
                    ];

                    // Aplicar alterações de estrutura
                    foreach ($columnsToAdd as $table => $columns) {
                        foreach ($columns as $column => $definition) {
                            if ($column === 'INDEXes') continue;

                            try {
                                $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
                            } catch (Exception $e) {
                                // Coluna já existe, ignorar
                            }
                        }
                    }

                    // Garantir que todos os índices existem
                    $indices = [
                        'usuarios' => ['idx_email'],
                        'config' => ['idx_config_chave'],
                        'curriculos' => ['idx_nome', 'idx_email', 'idx_data_cadastro', 'idx_cidade', 'idx_estado'],
                        'form_interactions' => ['idx_session', 'idx_ip', 'idx_timestamp', 'idx_device']
                    ];

                    foreach ($indices as $table => $indexes) {
                        foreach ($indexes as $index) {
                            try {
                                // Extrair nome da coluna do índice (assumindo padrão idx_tabela_coluna)
                                $parts = explode('_', $index);
                                if (count($parts) >= 3) {
                                    $column = end($parts);
                                    createIndexIfNotExists($pdo, $index, $table, $column);
                                }
                            } catch (Exception $e) {
                                // Índice já existe, ignorar
                            }
                        }
                    }

                    return "Sistema atualizado para versão 2.0 com sucesso";
                }
            ],
            '2.1.0' => [
                'description' => 'Sistema de status para currículos',
                'script' => function($pdo) {
                    // Adicionar coluna de status à tabela curriculos
                    try {
                        $pdo->exec("ALTER TABLE curriculos ADD COLUMN status ENUM('pendente_novo', 'pendente', 'classificado', 'arquivado') DEFAULT 'pendente_novo' AFTER data_cadastro");
                        $pdo->exec("ALTER TABLE curriculos ADD COLUMN visualizado TINYINT(1) DEFAULT 0 AFTER status");
                        $pdo->exec("ALTER TABLE curriculos ADD COLUMN data_visualizacao TIMESTAMP NULL AFTER visualizado");

                        // Criar índices para os novos campos
                        createIndexIfNotExists($pdo, 'idx_curriculos_status', 'curriculos', 'status');
                        createIndexIfNotExists($pdo, 'idx_curriculos_visualizado', 'curriculos', 'visualizado');

                        return "Sistema de status para currículos implementado com sucesso";
                    } catch (Exception $e) {
                        // Se a coluna já existe, apenas retornar sucesso
                        return "Sistema de status já estava implementado";
                    }
                }
            ],
            '2.2.0' => [
                'description' => 'Sistema de mensagens WhatsApp',
                'script' => function($pdo) {
                    // Criar tabela para histórico de mensagens WhatsApp
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS whatsapp_messages (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `curriculo_id` INT NOT NULL,
                            `numero_destino` VARCHAR(20) NOT NULL,
                            `mensagem` TEXT NOT NULL,
                            `status_envio` ENUM('enviado', 'erro', 'pendente') DEFAULT 'pendente',
                            `resposta_api` TEXT,
                            `enviado_por` VARCHAR(255),
                            `data_envio` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            FOREIGN KEY (`curriculo_id`) REFERENCES `curriculos`(`id`) ON DELETE CASCADE,
                            INDEX `idx_curriculo_id` (`curriculo_id`),
                            INDEX `idx_data_envio` (`data_envio`),
                            INDEX `idx_status_envio` (`status_envio`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                    ");

                    return "Sistema de mensagens WhatsApp implementado com sucesso";
                }
            ],
            '2.3.0' => [
                'description' => 'Adicionar campo Possui Filhos aos currículos',
                'script' => function($pdo) {
                    try {
                        $pdo->exec("ALTER TABLE curriculos ADD COLUMN possui_filhos TINYINT(1) DEFAULT 0 AFTER estado_civil");
                        return "Coluna 'possui_filhos' adicionada com sucesso";
                    } catch (Exception $e) {
                        // Coluna já existe, ignorar
                        return "Coluna 'possui_filhos' já existia";
                    }
                }
            ],
            '3.1.0' => [
                'description' => 'Corrigir tabela form_interactions: adicionar coluna user_agent e garantir estrutura completa',
                'script' => function($pdo) {
                    $results = [];
                    
                    // 1. Garantir que a tabela existe
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS `form_interactions` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `session_id` VARCHAR(255) NOT NULL,
                            `ip` VARCHAR(45) NOT NULL,
                            `user_agent` TEXT,
                            `browser` VARCHAR(100),
                            `os` VARCHAR(100),
                            `device` VARCHAR(50),
                            `nome_completo` VARCHAR(255) DEFAULT NULL,
                            `ultimo_campo` VARCHAR(100),
                            `acao` VARCHAR(50),
                            `valor_campo` TEXT,
                            `timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            INDEX `idx_session` (`session_id`),
                            INDEX `idx_ip` (`ip`),
                            INDEX `idx_timestamp` (`timestamp`),
                            INDEX `idx_device` (`device`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                    ");
                    $results[] = 'Tabela form_interactions verificada';
                    
                    // 2. Adicionar coluna user_agent se não existir
                    try {
                        $stmt = $pdo->prepare("
                            SELECT COUNT(*) as count 
                            FROM information_schema.columns 
                            WHERE table_schema = DATABASE() 
                            AND table_name = 'form_interactions' 
                            AND column_name = 'user_agent'
                        ");
                        $stmt->execute();
                        $exists = $stmt->fetch()['count'] > 0;
                        
                        if (!$exists) {
                            $pdo->exec("ALTER TABLE form_interactions ADD COLUMN `user_agent` TEXT AFTER `ip`");
                            $results[] = 'Coluna user_agent adicionada';
                        } else {
                            $results[] = 'Coluna user_agent já existia';
                        }
                    } catch (Exception $e) {
                        $results[] = 'Erro ao verificar user_agent: ' . $e->getMessage();
                    }
                    
                    return implode(' | ', $results);
                }
            ],
            '3.2.0' => [
                'description' => 'Melhorias no formulário: disponibilidade, pretensão salarial, habilidades, informática, experiências detalhadas, referências e consentimento LGPD',
                'script' => function($pdo) {
                    $results = [];
                    
                    // Novas colunas da tabela curriculos (todas opcionais para não afetar dados existentes)
                    $newColumns = [
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
                    
                    foreach ($newColumns as $column => $definition) {
                        try {
                            $stmt = $pdo->prepare("
                                SELECT COUNT(*) as count 
                                FROM information_schema.columns 
                                WHERE table_schema = DATABASE() 
                                AND table_name = 'curriculos' 
                                AND column_name = ?
                            ");
                            $stmt->execute([$column]);
                            $exists = $stmt->fetch()['count'] > 0;
                            
                            if (!$exists) {
                                $pdo->exec("ALTER TABLE curriculos ADD COLUMN `{$column}` {$definition}");
                                $results[] = "Coluna '{$column}' adicionada";
                            } else {
                                $results[] = "Coluna '{$column}' já existia";
                            }
                        } catch (Exception $e) {
                            $results[] = "Erro ao adicionar coluna '{$column}': " . $e->getMessage();
                        }
                    }
                    
                    return implode(' | ', $results);
                }
            ]
        ];
    }
    
    /**
     * Executa todas as migrações necessárias
     */
    public function migrate() {
        $currentVersion = $this->getCurrentVersion();
        $availableMigrations = $this->getAvailableMigrations();
        $appliedMigrations = [];
        $errors = [];
        
        try {
            $this->pdo->beginTransaction();
            
            // Ordenar migrações por versão
            uksort($availableMigrations, 'compareVersions');
            
            foreach ($availableMigrations as $version => $migration) {
                // Verificar se esta migração já foi aplicada
                if ($this->isMigrationApplied($version)) {
                    continue;
                }
                
                // Verificar se a migração deve ser aplicada (versão atual < versão da migração)
                if (compareVersions($currentVersion, $version, '>=')) {
                    continue;
                }
                
                try {
                    // Executar migração
                    $result = call_user_func($migration['script'], $this->pdo);
                    
                    // Registrar migração como aplicada
                    $this->markMigrationAsApplied($version, $migration['description']);
                    
                    $appliedMigrations[] = [
                        'version' => $version,
                        'description' => $migration['description'],
                        'result' => $result
                    ];
                    
                } catch (Exception $e) {
                    $errors[] = "Erro na migração {$version}: " . $e->getMessage();
                    throw $e;
                }
            }
            
            if ($this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
            
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        
        return [
            'current_version' => $currentVersion,
            'applied_migrations' => $appliedMigrations,
            'errors' => $errors,
            'success' => empty($errors)
        ];
    }
    
    /**
     * Verifica se uma migração já foi aplicada
     */
    private function isMigrationApplied($version) {
        try {
            $stmt = $this->pdo->prepare("SELECT id FROM {$this->versionTable} WHERE version = ?");
            $stmt->execute([$version]);
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Marca uma migração como aplicada
     */
    private function markMigrationAsApplied($version, $description) {
        $scriptHash = hash('sha256', $version . $description);
        $stmt = $this->pdo->prepare("INSERT INTO {$this->versionTable} (version, description, script_hash) VALUES (?, ?, ?)");
        $stmt->execute([$version, $description, $scriptHash]);
    }
    
    /**
     * Obtém o histórico de migrações aplicadas
     */
    public function getMigrationHistory() {
        try {
            $stmt = $this->pdo->query("SELECT version, description, applied_at FROM {$this->versionTable} ORDER BY applied_at DESC");
            return $stmt->fetchAll();
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Força uma migração (útil para recuperação)
     */
    public function forceMigration($version, $description = 'Migração forçada') {
        $this->markMigrationAsApplied($version, $description);
    }
}

/**
 * Função para comparar versões corretamente
 * Evita conflito com a função nativa version_compare() do PHP
 */
function compareVersions($version1, $version2, $operator = null) {
    // Converter para array de números
    $v1 = array_map('intval', explode('.', $version1));
    $v2 = array_map('intval', explode('.', $version2));
    
    // Padronizar tamanhos
    $max = max(count($v1), count($v2));
    $v1 = array_pad($v1, $max, 0);
    $v2 = array_pad($v2, $max, 0);
    
    // Comparar
    for ($i = 0; $i < $max; $i++) {
        if ($v1[$i] < $v2[$i]) {
            $result = -1;
            break;
        } elseif ($v1[$i] > $v2[$i]) {
            $result = 1;
            break;
        }
    }
    
    if (!isset($result)) {
        $result = 0;
    }
    
    if ($operator === null) {
        return $result;
    }
    
    switch ($operator) {
        case '<':
        case 'lt':
            return $result < 0;
        case '<=':
        case 'le':
            return $result <= 0;
        case '>':
        case 'gt':
            return $result > 0;
        case '>=':
        case 'ge':
            return $result >= 0;
        case '==':
        case '=':
        case 'eq':
            return $result == 0;
        case '!=':
        case '<>':
        case 'ne':
            return $result != 0;
        default:
            return false;
    }
}
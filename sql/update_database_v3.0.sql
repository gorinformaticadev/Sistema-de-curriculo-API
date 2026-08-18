-- ======================================================
-- SCRIPT DE ATUALIZAÇÃO DO BANCO DE DADOS - VERSÃO v3.0
-- GOR INFORMÁTICA - SISTEMA DE CURRÍCULOS
-- Executar este script no phpMyAdmin ou MySQL do servidor
-- ======================================================

SET FOREIGN_KEY_CHECKS=0;

-- 1. Tabela db_version
CREATE TABLE IF NOT EXISTS `db_version` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `version` VARCHAR(20) NOT NULL UNIQUE,
  `description` TEXT,
  `applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `script_hash` VARCHAR(64)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabela usuarios
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `senha` VARCHAR(255) NOT NULL,
  `tipo` ENUM('admin', 'analisador') NOT NULL DEFAULT 'admin',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Tabela config
CREATE TABLE IF NOT EXISTS `config` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `chave` VARCHAR(255) NOT NULL UNIQUE,
  `valor` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_config_chave` (`chave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Tabela curriculos
CREATE TABLE IF NOT EXISTS `curriculos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nome` VARCHAR(255) NOT NULL,
  `data_nascimento` DATE NOT NULL,
  `estado_civil` VARCHAR(50) DEFAULT NULL,
  `possui_filhos` TINYINT(1) DEFAULT 0,
  `telefone` VARCHAR(255) DEFAULT NULL,
  `is_whatsapp` TINYINT(1) DEFAULT 0,
  `email` VARCHAR(255) DEFAULT NULL,
  `facebook` VARCHAR(255) DEFAULT NULL,
  `instagram` VARCHAR(255) DEFAULT NULL,
  `endereco` TEXT DEFAULT NULL,
  `cidade` VARCHAR(255) DEFAULT NULL,
  `estado` VARCHAR(255) DEFAULT NULL,
  `escolaridade` VARCHAR(255) DEFAULT NULL,
  `estudando` TINYINT(1) DEFAULT 0,
  `periodo_estudo` VARCHAR(50) DEFAULT NULL,
  `possui_cursos` TINYINT(1) DEFAULT 0,
  `cursos` TEXT DEFAULT NULL,
  `possui_experiencia` TINYINT(1) DEFAULT 0,
  `experiencias` TEXT DEFAULT NULL,
  `motivacao` TEXT DEFAULT NULL,
  `arquivo_curriculo` VARCHAR(255) DEFAULT NULL,
  `arquivo_foto` VARCHAR(255) DEFAULT NULL,
  `ip_cadastro` VARCHAR(45) DEFAULT NULL,
  `data_cadastro` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `status` ENUM('pendente_novo', 'pendente', 'entrevista', 'teste', 'aprovado', 'banco_talentos', 'arquivado') DEFAULT 'pendente_novo',
  `visualizado` TINYINT(1) DEFAULT 0,
  `data_visualizacao` TIMESTAMP NULL DEFAULT NULL,
  `disponibilidade_inicio` VARCHAR(50) DEFAULT NULL,
  `disponibilidade_outra_data` DATE DEFAULT NULL,
  `disponibilidade_sabados` VARCHAR(50) DEFAULT NULL,
  `disponibilidade_horas_extras` VARCHAR(50) DEFAULT NULL,
  `pretensao_salarial` VARCHAR(50) DEFAULT NULL,
  `habilidades` TEXT DEFAULT NULL,
  `conhecimento_informatica` VARCHAR(50) DEFAULT NULL,
  `expectativa_primeiro_emprego` TEXT DEFAULT NULL,
  `curso_atual` VARCHAR(255) DEFAULT NULL,
  `instituicao_curso` VARCHAR(255) DEFAULT NULL,
  `situacao_curso` VARCHAR(20) DEFAULT NULL,
  `ano_conclusao_curso` VARCHAR(10) DEFAULT NULL,
  `como_conheceu` VARCHAR(50) DEFAULT NULL,
  `como_conheceu_outro` VARCHAR(255) DEFAULT NULL,
  `referencias` TEXT DEFAULT NULL,
  `consentimento_lgpd` TINYINT(1) DEFAULT 0,
  `consentimento_banco_talentos` TINYINT(1) DEFAULT 0,
  INDEX `idx_nome` (`nome`),
  INDEX `idx_email` (`email`),
  INDEX `idx_data_cadastro` (`data_cadastro`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Tabela form_interactions
CREATE TABLE IF NOT EXISTS `form_interactions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `session_id` VARCHAR(255) NOT NULL,
  `ip` VARCHAR(45) NOT NULL,
  `user_agent` TEXT DEFAULT NULL,
  `browser` VARCHAR(100) DEFAULT NULL,
  `os` VARCHAR(100) DEFAULT NULL,
  `device` VARCHAR(50) DEFAULT NULL,
  `nome_completo` VARCHAR(255) DEFAULT NULL,
  `ultimo_campo` VARCHAR(100) DEFAULT NULL,
  `acao` VARCHAR(50) DEFAULT NULL,
  `valor_campo` TEXT DEFAULT NULL,
  `timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_session` (`session_id`),
  INDEX `idx_ip` (`ip`),
  INDEX `idx_timestamp` (`timestamp`),
  INDEX `idx_device` (`device`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Tabela whatsapp_messages
CREATE TABLE IF NOT EXISTS `whatsapp_messages` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `curriculo_id` INT NOT NULL,
  `numero_destino` VARCHAR(20) NOT NULL,
  `mensagem` TEXT NOT NULL,
  `status_envio` ENUM('enviado', 'erro', 'pendente') DEFAULT 'pendente',
  `resposta_api` TEXT,
  `enviado_por` VARCHAR(255),
  `data_envio` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_curriculo_id` (`curriculo_id`),
  INDEX `idx_data_envio` (`data_envio`),
  INDEX `idx_status_envio` (`status_envio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================================
-- GARANTIR COLUNAS EM TABELAS JÁ EXISTENTES (PROCEDURE SEGURA)
-- ======================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS `AddCol`$$
CREATE PROCEDURE `AddCol`(
    IN param_table VARCHAR(64),
    IN param_column VARCHAR(64),
    IN param_datatype VARCHAR(255)
)
BEGIN
    IF NOT EXISTS (
        SELECT NULL 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = param_table
        AND COLUMN_NAME = param_column
    ) THEN
        SET @s = CONCAT('ALTER TABLE `', param_table, '` ADD COLUMN `', param_column, '` ', param_datatype);
        PREPARE stmt FROM @s;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

-- Adicionar colunas faltantes em curriculos
CALL AddCol('curriculos', 'possui_filhos', 'TINYINT(1) DEFAULT 0');
CALL AddCol('curriculos', 'status', "ENUM('pendente_novo', 'pendente', 'entrevista', 'teste', 'aprovado', 'banco_talentos', 'arquivado') DEFAULT 'pendente_novo'");
CALL AddCol('curriculos', 'visualizado', 'TINYINT(1) DEFAULT 0');
CALL AddCol('curriculos', 'data_visualizacao', 'TIMESTAMP NULL DEFAULT NULL');
CALL AddCol('curriculos', 'disponibilidade_inicio', 'VARCHAR(50) DEFAULT NULL');
CALL AddCol('curriculos', 'disponibilidade_outra_data', 'DATE DEFAULT NULL');
CALL AddCol('curriculos', 'disponibilidade_sabados', 'VARCHAR(50) DEFAULT NULL');
CALL AddCol('curriculos', 'disponibilidade_horas_extras', 'VARCHAR(50) DEFAULT NULL');
CALL AddCol('curriculos', 'pretensao_salarial', 'VARCHAR(50) DEFAULT NULL');
CALL AddCol('curriculos', 'habilidades', 'TEXT DEFAULT NULL');
CALL AddCol('curriculos', 'conhecimento_informatica', 'VARCHAR(50) DEFAULT NULL');
CALL AddCol('curriculos', 'expectativa_primeiro_emprego', 'TEXT DEFAULT NULL');
CALL AddCol('curriculos', 'curso_atual', 'VARCHAR(255) DEFAULT NULL');
CALL AddCol('curriculos', 'instituicao_curso', 'VARCHAR(255) DEFAULT NULL');
CALL AddCol('curriculos', 'situacao_curso', 'VARCHAR(20) DEFAULT NULL');
CALL AddCol('curriculos', 'ano_conclusao_curso', 'VARCHAR(10) DEFAULT NULL');
CALL AddCol('curriculos', 'como_conheceu', 'VARCHAR(50) DEFAULT NULL');
CALL AddCol('curriculos', 'como_conheceu_outro', 'VARCHAR(255) DEFAULT NULL');
CALL AddCol('curriculos', 'referencias', 'TEXT DEFAULT NULL');
CALL AddCol('curriculos', 'consentimento_lgpd', 'TINYINT(1) DEFAULT 0');
CALL AddCol('curriculos', 'consentimento_banco_talentos', 'TINYINT(1) DEFAULT 0');

-- Adicionar colunas faltantes em form_interactions
CALL AddCol('form_interactions', 'user_agent', 'TEXT DEFAULT NULL');

-- Adicionar colunas faltantes em usuarios
CALL AddCol('usuarios', 'tipo', "ENUM('admin', 'analisador') NOT NULL DEFAULT 'admin'");

-- Remover procedure temporária
DROP PROCEDURE IF EXISTS `AddCol`;

-- Inserir registros de versão
INSERT IGNORE INTO `db_version` (`version`, `description`) VALUES ('3.2.0', 'Atualização completa do banco de dados v3.0');

SET FOREIGN_KEY_CHECKS=1;

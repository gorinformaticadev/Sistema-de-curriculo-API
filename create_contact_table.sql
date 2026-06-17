-- Script SQL para criar a tabela de informações de contato
-- Execute este script no seu banco de dados MySQL/MariaDB

-- Criar tabela curriculo_contatos
CREATE TABLE IF NOT EXISTS `curriculo_contatos` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `curriculo_id` INT NOT NULL,
    `tipo_contato` VARCHAR(100) NOT NULL,
    `informacao` TEXT NOT NULL,
    `observacoes` TEXT,
    `registrado_por` VARCHAR(255) NOT NULL,
    `data_registro` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`curriculo_id`) REFERENCES `curriculos`(`id`) ON DELETE CASCADE,
    INDEX `idx_curriculo_id` (`curriculo_id`),
    INDEX `idx_data_registro` (`data_registro`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verificar se a tabela foi criada
SELECT 'Tabela curriculo_contatos criada com sucesso!' AS status;

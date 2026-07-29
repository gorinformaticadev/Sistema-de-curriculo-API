<?php
/**
 * Sistema de Atualização Automática
 * Processa upload de ZIP, faz backup, atualiza arquivos e executa migrations
 */

session_start();
require_once 'db_connect.php';

// Verificar se é admin
function isAdmin() {
    return isset($_SESSION['admin']) && $_SESSION['admin'] === true;
}

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acesso negado.']);
    exit;
}

// Verificar token CSRF
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Erro de validação de segurança (CSRF).']);
    exit;
}

$action = $_POST['action'] ?? '';

// Validar arquivo ZIP
if ($action === 'validateZip') {
    if (!isset($_FILES['update_zip']) || $_FILES['update_zip']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Erro no upload do arquivo.']);
        exit;
    }

    $zipFile = $_FILES['update_zip']['tmp_name'];
    $zipSize = $_FILES['update_zip']['size'];

    // Verificar tamanho máximo (50MB)
    if ($zipSize > 50 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'Arquivo muito grande. Tamanho máximo: 50MB.']);
        exit;
    }

    // Verificar se é um ZIP válido
    if (!is_uploaded_file($zipFile) && !file_exists($zipFile)) {
        echo json_encode(['success' => false, 'message' => 'Arquivo inválido.']);
        exit;
    }

    $zip = new ZipArchive();
    $res = $zip->open($zipFile);

    if ($res !== TRUE) {
        echo json_encode(['success' => false, 'message' => 'Arquivo ZIP inválido ou corrompido.']);
        exit;
    }

    // Listar arquivos no ZIP
    $files = [];
    $hasUpdateScript = false;
    $updateScriptContent = '';

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $filename = $stat['name'];

        // Ignorar diretórios e arquivos ocultos
        if (substr($filename, -1) === '/' || substr($filename, 1) === '.') {
            continue;
        }

        $files[] = $filename;

        // Verificar se há script de atualização
        if ($filename === 'update.sql' || $filename === 'migrations/update.sql') {
            $hasUpdateScript = true;
            $updateScriptContent = $zip->getFromIndex($i);
        }
    }

    $zip->close();

    // Verificar se há arquivos para atualizar
    if (empty($files)) {
        echo json_encode(['success' => false, 'message' => 'O ZIP está vazio ou não contém arquivos válidos.']);
        exit;
    }

    // Preparar resposta
    $response = [
        'success' => true,
        'message' => 'ZIP válido!',
        'files_count' => count($files),
        'files' => $files,
        'has_update_script' => $hasUpdateScript,
        'update_script_preview' => $hasUpdateScript ? substr($updateScriptContent, 0, 500) . '...' : ''
    ];

    // Salvar o ZIP temporariamente para uso posterior
    $tempDir = sys_get_temp_dir() . '/update_' . uniqid();
    mkdir($tempDir, 0700, true);
    $tempZipPath = $tempDir . '/update.zip';
    move_uploaded_file($zipFile, $tempZipPath);
    $response['temp_path'] = $tempZipPath;

    echo json_encode($response);
    exit;
}

// Executar atualização
if ($action === 'executeUpdate') {
    $tempZipPath = $_POST['temp_path'] ?? '';
    $backupDir = $_POST['backup_dir'] ?? '';

    if (!file_exists($tempZipPath)) {
        echo json_encode(['success' => false, 'message' => 'Arquivo de atualização não encontrado. Por favor, faça o upload novamente.']);
        exit;
    }

    $logs = [];
    $errors = [];

    try {
        // 1. Criar diretório de backup
        $backupPath = $backupDir ?: 'backups/update_' . date('Y-m-d_H-i-s');
        if (!file_exists($backupPath)) {
            mkdir($backupPath, 0700, true);
        }

        $logs[] = "📦 Backup criado em: $backupPath";

        // 2. Fazer backup dos arquivos atuais
        $rootFiles = ['index.html', 'admin.php', 'styles.css', 'script.js', 'install.php', 'process-simple.php', 'db_connect.php'];
        foreach ($rootFiles as $file) {
            if (file_exists($file)) {
                copy($file, $backupPath . '/' . $file);
            }
        }

        // Backup de diretórios
        $dirsToBackup = ['uploads/', '.bolt/'];
        foreach ($dirsToBackup as $dir) {
            if (file_exists($dir)) {
                $backupSubDir = $backupPath . '/' . rtrim($dir, '/');
                if (!file_exists($backupSubDir)) {
                    mkdir($backupSubDir, 0700, true);
                }
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                foreach ($files as $file) {
                    $target = $backupSubDir . '/' . $file->getFilename();
                    if ($file->isDir()) {
                        if (!file_exists($target)) {
                            mkdir($target, 0700, true);
                        }
                    } else {
                        copy($file->getPathname(), $target);
                    }
                }
            }
        }

        $logs[] = "✅ Backup concluído com sucesso.";

        // 3. Extrair ZIP
        $zip = new ZipArchive();
        $res = $zip->open($tempZipPath);

        if ($res !== TRUE) {
            throw new Exception('Erro ao abrir arquivo ZIP.');
        }

        $extractPath = dirname($tempZipPath) . '/extracted';
        if (!file_exists($extractPath)) {
            mkdir($extractPath, 0700, true);
        }

        $zip->extractTo($extractPath);
        $zip->close();

        $logs[] = "📂 Arquivos extraídos com sucesso.";

        // 4. Substituir arquivos
        $updatedFiles = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($extractPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $relativePath = substr($file->getPathname(), strlen($extractPath) + 1);
            $targetPath = $relativePath;

            // Ignorar diretórios de backup e temporários
            if (strpos($relativePath, 'backups/') === 0 || strpos($relativePath, 'temp/') === 0) {
                continue;
            }

            // Proteger pasta uploads - não sobrescrever a menos que seja explicitamente desejado
            if (strpos($relativePath, 'uploads/') === 0) {
                $logs[] = "⚠️ Pasta 'uploads/' preservada (não sobrescrita).";
                continue;
            }

            if ($file->isDir()) {
                if (!file_exists($targetPath)) {
                    mkdir($targetPath, 0700, true);
                }
            } else {
                // Criar diretório se não existir
                $dir = dirname($targetPath);
                if ($dir !== '.' && !file_exists($dir)) {
                    mkdir($dir, 0700, true);
                }

                copy($file->getPathname(), $targetPath);
                $updatedFiles++;
            }
        }

        $logs[] = "🔄 $updatedFiles arquivo(s) atualizado(s).";

        // 5. Executar script de atualização do banco de dados se existir
        $updateSqlPath = $extractPath . '/update.sql';
        if (file_exists($updateSqlPath)) {
            $sql = file_get_contents($updateSqlPath);

            // Dividir por comandos SQL (separados por ;)
            $statements = array_filter(array_map('trim', explode(';', $sql)));

            $executedMigrations = 0;
            foreach ($statements as $statement) {
                if (!empty($statement)) {
                    try {
                        $pdo->exec($statement);
                        $executedMigrations++;
                    } catch (PDOException $e) {
                        $errors[] = "Erro na migration: " . $e->getMessage();
                    }
                }
            }

            $logs[] = "🗄️ $executedMigrations comando(s) SQL executado(s).";
        }

        // 6. Limpar arquivos temporários
        if (file_exists($tempZipPath)) {
            unlink($tempZipPath);
        }
        if (file_exists($extractPath)) {
            $this->deleteDirectory($extractPath);
        }

        // 7. Salvar log da atualização
        $logFile = 'update.log';
        $logEntry = "[" . date('Y-m-d H:i:s') . "] Atualização realizada por admin. Arquivos: $updatedFiles, Migrations: $executedMigrations\n";
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

        $logs[] = "🎉 Atualização concluída com sucesso!";

        echo json_encode([
            'success' => true,
            'message' => 'Atualização realizada com sucesso!',
            'logs' => $logs,
            'errors' => $errors,
            'backup_path' => $backupPath
        ]);

    } catch (Exception $e) {
        // Rollback em caso de erro
        $logs[] = "❌ Erro: " . $e->getMessage();
        $logs[] = "🔄 Iniciando rollback...";

        try {
            // Restaurar backup
            if (file_exists($backupPath)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($backupPath, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ($iterator as $file) {
                    $relativePath = substr($file->getPathname(), strlen($backupPath) + 1);
                    $targetPath = $relativePath;

                    if ($file->isDir()) {
                        if (!file_exists($targetPath)) {
                            mkdir($targetPath, 0700, true);
                        }
                    } else {
                        $dir = dirname($targetPath);
                        if ($dir !== '.' && !file_exists($dir)) {
                            mkdir($dir, 0700, true);
                        }
                        copy($file->getPathname(), $targetPath);
                    }
                }

                $logs[] = "✅ Rollback concluído. Arquivos restaurados do backup.";
            }
        } catch (Exception $rollbackError) {
            $logs[] = "⚠️ Erro no rollback: " . $rollbackError->getMessage();
        }

        echo json_encode([
            'success' => false,
            'message' => 'Erro durante a atualização: ' . $e->getMessage(),
            'logs' => $logs,
            'errors' => $errors
        ]);
    }

    exit;
}

// Função auxiliar para deletar diretório recursivamente
function deleteDirectory($dir) {
    if (!file_exists($dir)) {
        return true;
    }

    if (!is_dir($dir)) {
        return unlink($dir);
    }

    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        if (!deleteDirectory($dir . '/' . $item)) {
            return false;
        }
    }

    return rmdir($dir);
}

// Se não for uma ação válida, retornar erro
echo json_encode(['success' => false, 'message' => 'Ação inválida.']);
exit;

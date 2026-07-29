<?php
// Script para gerar pacotes de atualização automaticamente.
// Execute no terminal: php build_update.php 1.1.0 "Notas da versão"

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$version = $argv[1] ?? (isset($_GET['v']) ? $_GET['v'] : '1.1.0');
$notes = $argv[2] ?? (isset($_GET['n']) ? $_GET['n'] : 'Atualização de rotina e melhorias do sistema.');

$buildDir = __DIR__ . '/builds';
if (!is_dir($buildDir)) {
    mkdir($buildDir, 0777, true);
}

$zipName = $buildDir . "/update_v{$version}.zip";

$manifest = [
    'version' => $version,
    'notes' => $notes,
    'date' => date('Y-m-d H:i:s')
];

file_put_contents('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$zip = new ZipArchive();
if ($zip->open($zipName, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
    $baseDir = __DIR__;
    
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    $count = 0;
    foreach ($files as $file) {
        $fileReal = realpath($file);
        if (is_dir($fileReal)) continue;
        
        $relativePath = str_replace($baseDir . DIRECTORY_SEPARATOR, '', $fileReal);
        $relativePath = str_replace('\\', '/', $relativePath);
        
        // Ignorar arquivos sensíveis ou locais que não devem ir no pacote
        if (
            strpos($relativePath, 'backups/') === 0 || 
            strpos($relativePath, 'uploads/') === 0 || 
            strpos($relativePath, 'builds/') === 0 || 
            strpos($relativePath, '.git/') === 0 ||
            strpos($relativePath, '.vscode/') === 0 ||
            $relativePath === 'db_connect.php' ||
            $relativePath === '.env' ||
            $relativePath === 'error.log' ||
            $relativePath === 'access.log' ||
            $relativePath === 'build_update.php'
        ) {
            continue;
        }
        
        $zip->addFile($fileReal, $relativePath);
        $count++;
    }
    
    // Adicionar o manifest que acabamos de gerar
    $zip->addFile($baseDir . '/manifest.json', 'manifest.json');
    $zip->close();
    
    // Apagar o manifest da pasta raiz (já está dentro do zip)
    unlink($baseDir . '/manifest.json'); 
    
    echo "========================================\n";
    echo " PACOTE DE ATUALIZACAO CRIADO COM SUCESSO!\n";
    echo "========================================\n";
    echo " Arquivo: builds/update_v{$version}.zip\n";
    echo " Total de arquivos inclusos: {$count}\n";
    echo "========================================\n";
} else {
    echo "Erro ao criar o arquivo .zip. Verifique as permissões da pasta.\n";
}

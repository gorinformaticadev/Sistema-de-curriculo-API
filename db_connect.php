<?php
// Impedir acesso direto ao arquivo
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    die('Acesso direto não permitido.');
}

// Configurações do Banco de Dados (substitua com suas credenciais)
define('DB_HOST', 'localhost');
define('DB_USER', 'root');      // Usuário padrão do XAMPP
define('DB_PASS', '');          // Senha padrão do XAMPP é vazia
define('DB_NAME', 'curriculos_db'); // Nome do banco de dados que vamos criar

// String de Conexão (DSN)
$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

// Opções do PDO
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Lançar exceções em erros
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Retornar arrays associativos
    PDO::ATTR_EMULATE_PREPARES   => false,                  // Usar prepared statements nativos
];

try {
    // Cria a instância do PDO
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    // Em um ambiente de produção, você não deve exibir o erro detalhado.
    // Em vez disso, logue o erro e mostre uma mensagem genérica.
    logError('Falha na conexão com o banco de dados: ' . $e->getMessage());
    die('Erro: Não foi possível conectar ao banco de dados. Verifique os logs para mais detalhes.');
}

// A variável $pdo agora está disponível para ser usada nos scripts que incluírem este arquivo.
?>

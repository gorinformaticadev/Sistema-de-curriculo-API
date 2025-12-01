<?php
/**
 * Proteção contra listagem de diretório
 * Redireciona para a página de login
 */
header('HTTP/1.0 403 Forbidden');
header('Location: ../admin.php');
exit('Acesso negado');

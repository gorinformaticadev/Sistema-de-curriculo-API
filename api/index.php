<?php
/**
 * Proteção contra listagem de diretório
 * Redireciona para a página inicial
 */
header('HTTP/1.0 403 Forbidden');
header('Location: ../index.html');
exit('Acesso negado');

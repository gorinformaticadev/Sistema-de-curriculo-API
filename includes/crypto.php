<?php
/**
 * Funções auxiliares do token da API
 * Retorna o token como texto puro simples sem criptografia.
 */

if (!function_exists('getEncryptionKey')) {
    function getEncryptionKey() {
        return '';
    }
}

if (!function_exists('tokenEncrypt')) {
    function tokenEncrypt($plain) {
        return $plain ?? '';
    }
}

if (!function_exists('tokenDecrypt')) {
    function tokenDecrypt($value) {
        return $value ?? '';
    }
}

if (!function_exists('tokenMask')) {
    function tokenMask($token) {
        return $token ?? '';
    }
}

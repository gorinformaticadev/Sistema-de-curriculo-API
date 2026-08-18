<?php
/**
 * Criptografia do token da API (Bearer)
 * O token é armazenado no banco criptografado com AES-256-CBC.
 * A chave é derivada de APP_KEY (gerada automaticamente no .env se ausente).
 *
 * Formato do valor criptografado: enc:v1:<base64(iv + ciphertext)>
 * Tokens legados (texto puro) continuam funcionando e são migrados
 * automaticamente para criptografia no próximo carregamento.
 */

if (!function_exists('getEncryptionKey')) {
    function getEncryptionKey() {
        $envKey = getenv('APP_KEY');
        if ($envKey === false || $envKey === '' || $envKey === null) {
            // Tenta ler diretamente do arquivo .env (alguns setups não expõem getenv)
            $envFile = dirname(__DIR__) . '/.env';
            if (file_exists($envFile)) {
                $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    if (strpos(trim($line), 'APP_KEY=') === 0) {
                        $envKey = trim(substr(trim($line), strlen('APP_KEY=')));
                        break;
                    }
                }
            }
        }
        if (empty($envKey)) {
            // Gera e persiste uma chave no .env para os próximos carregamentos
            $envKey = bin2hex(random_bytes(32));
            $envFile = dirname(__DIR__) . '/.env';
            @file_put_contents($envFile, "\nAPP_KEY=" . $envKey . "\n", FILE_APPEND | LOCK_EX);
            putenv('APP_KEY=' . $envKey);
        }
        return hash('sha256', $envKey);
    }
}

if (!function_exists('tokenEncrypt')) {
    function tokenEncrypt($plain) {
        if ($plain === null || $plain === '') {
            return '';
        }
        // Já criptografado: não faz nada
        if (strpos($plain, 'enc:v1:') === 0) {
            return $plain;
        }
        if (!function_exists('openssl_encrypt') || !function_exists('openssl_random_pseudo_bytes')) {
            return $plain;
        }
        $key = getEncryptionKey();
        $iv = @openssl_random_pseudo_bytes(16);
        if ($iv === false || strlen($iv) < 16) {
            return $plain;
        }
        $cipher = @openssl_encrypt($plain, 'AES-256-CBC', $key, 0, $iv);
        if ($cipher === false) {
            // Falha de criptografia: mantém texto puro
            return $plain;
        }
        return 'enc:v1:' . base64_encode($iv . $cipher);
    }
}

if (!function_exists('tokenDecrypt')) {
    function tokenDecrypt($value) {
        if ($value === null || $value === '') {
            return '';
        }
        // Legado (texto puro): retorna como está
        if (strpos($value, 'enc:v1:') !== 0) {
            return $value;
        }
        if (!function_exists('openssl_decrypt')) {
            return $value;
        }
        $data = base64_decode(substr($value, strlen('enc:v1:')));
        if ($data === false || strlen($data) < 17) {
            return $value;
        }
        $iv = substr($data, 0, 16);
        $cipher = substr($data, 16);
        $plain = @openssl_decrypt($cipher, 'AES-256-CBC', getEncryptionKey(), 0, $iv);
        return ($plain === false) ? $value : $plain;
    }
}

if (!function_exists('tokenMask')) {
    function tokenMask($token) {
        if ($token === null || $token === '') {
            return '';
        }
        if (strlen($token) <= 10) {
            return $token;
        }
        return substr($token, 0, 8) . '••••••••••';
    }
}

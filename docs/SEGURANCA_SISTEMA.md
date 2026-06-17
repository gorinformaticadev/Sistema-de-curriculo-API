# Documentação de Segurança do Sistema

## Proteções Implementadas

Este documento descreve todas as medidas de segurança implementadas no sistema de currículos.

---

## 1. Proteção contra Listagem de Diretórios

### .htaccess Principal
- **Localização:** Raiz do projeto
- **Proteção:** `Options -Indexes`
- **Efeito:** Impede que usuários vejam a lista de arquivos em qualquer diretório

### Arquivos index.php de Proteção
Criados em todos os diretórios sensíveis:
- `/admin/index.php`
- `/uploads/index.php`
- `/backups/index.php`
- `/config/index.php`
- `/api/index.php`
- `/includes/index.php`

**Função:** Redireciona qualquer tentativa de acesso direto ao diretório

---

## 2. Proteção de Arquivos Sensíveis

### Arquivos Bloqueados
Extensões bloqueadas para acesso externo:
- `.log` - Arquivos de log
- `.md` - Documentação markdown
- `.sql` - Scripts SQL
- `.json` - Arquivos de configuração
- `.bak`, `.old`, `.backup` - Arquivos de backup
- `.txt` - Arquivos de texto (exceto robots.txt)
- `.env`, `.ini`, `.conf` - Arquivos de configuração

### Arquivos de Configuração
- `db_connect.php` - Bloqueado (exceto localhost)
- `.htaccess` - Bloqueado
- `.htpasswd` - Bloqueado
- `.env` - Bloqueado

### Proteção Git
- Todos os arquivos `.git*` são bloqueados

---

## 3. Proteção do Diretório de Uploads

### .htaccess Específico
**Localização:** `/uploads/.htaccess`

**Proteções:**
1. **Desabilita execução de PHP:** `php_flag engine off`
2. **Bloqueia scripts:** PHP, Python, Perl, ASP, CGI, executáveis
3. **Permite apenas:** JPG, JPEG, PNG, GIF, PDF
4. **Headers de segurança:**
   - PDFs forçam download
   - Previne MIME sniffing

**Tipos de arquivo bloqueados:**
```
.php, .php3, .php4, .php5, .phtml
.pl, .py, .jsp, .asp, .sh, .cgi
.exe, .bat, .com
```

**Tipos de arquivo permitidos:**
```
.jpg, .jpeg, .png, .gif, .pdf
```

---

## 4. Proteção do Diretório de Backups

### .htaccess Específico
**Localização:** `/backups/.htaccess`

**Proteções:**
1. **Bloqueia TODOS os acessos externos**
2. **Permite apenas localhost** (127.0.0.1 e ::1)
3. **Uso:** Apenas scripts internos podem acessar

---

## 5. Headers de Segurança HTTP

### X-Frame-Options
```
X-Frame-Options: SAMEORIGIN
```
**Proteção:** Previne clickjacking

### X-Content-Type-Options
```
X-Content-Type-Options: nosniff
```
**Proteção:** Previne MIME type sniffing

### X-XSS-Protection
```
X-XSS-Protection: 1; mode=block
```
**Proteção:** Ativa proteção XSS do navegador

### Referrer-Policy
```
Referrer-Policy: strict-origin-when-cross-origin
```
**Proteção:** Controla informações de referência

### Content-Security-Policy
```
Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdnjs.cloudflare.com; ...
```
**Proteção:** Controla recursos que podem ser carregados

---

## 6. Proteção contra Ataques

### SQL Injection
Bloqueio de query strings suspeitas contendo:
- `union`, `select`, `insert`, `drop`, `delete`, `update`
- `cast`, `create`, `char`, `convert`, `alter`, `declare`
- `exec`, `script`, `set`, `md5`, `benchmark`

### User Agents Suspeitos
Bloqueio de bots e scanners:
- User agents vazios
- `curl`, `wget`, `libwww-perl`
- `python`, `nikto`, `scan`
- `HTTrack`, `email`, `harvest`

### XSS (Cross-Site Scripting)
Bloqueio de query strings com:
- Tags `<script>`
- Tentativas de injeção de código

---

## 7. Proteção contra Hotlinking

**Função:** Impede que outros sites usem suas imagens/PDFs diretamente

**Configuração:** Permite apenas:
- Acesso direto (sem referrer)
- Acesso do próprio domínio

**Nota:** Ajuste a linha no .htaccess:
```apache
RewriteCond %{HTTP_REFERER} !^https?://(www\.)?seu-dominio\.com [NC]
```

---

## 8. Limites de Upload

### Configurações PHP
```
upload_max_filesize = 10M
post_max_size = 10M
```

**Recomendação:** Ajuste conforme necessário para currículos

---

## 9. Compressão e Cache

### GZIP Compression
Comprime automaticamente:
- HTML, CSS, JavaScript
- JSON, XML

### Cache de Arquivos Estáticos
- **Imagens:** 1 mês
- **CSS/JS:** 1 semana
- **PDFs:** 1 mês

---

## 10. HTTPS (SSL/TLS)

### Configuração
No `.htaccess`, há uma seção comentada para forçar HTTPS:

```apache
# <IfModule mod_rewrite.c>
#     RewriteEngine On
#     RewriteCond %{HTTPS} off
#     RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
# </IfModule>
```

**Para ativar:**
1. Obtenha um certificado SSL (Let's Encrypt é gratuito)
2. Descomente as linhas acima
3. Teste o redirecionamento

---

## 11. Proteção em Nível de Código

### db_connect.php
```php
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    die('Acesso direto não permitido.');
}
```
**Proteção:** Impede execução direta do arquivo

### Tokens CSRF
Todas as operações POST usam tokens CSRF:
```php
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Erro de validação de segurança (CSRF).']);
    exit;
}
```

### Prepared Statements
Todas as queries usam prepared statements do PDO:
```php
$stmt = $pdo->prepare("SELECT * FROM curriculos WHERE id = ?");
$stmt->execute([$id]);
```
**Proteção:** Previne SQL injection

### Validação de Entrada
```php
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$email = filter_var($email, FILTER_VALIDATE_EMAIL);
```

### Sanitização de Saída
```php
echo htmlspecialchars($nome);
```

---

## 12. Logs de Auditoria

### error.log
Registra:
- Erros do sistema
- Tentativas de login
- Operações administrativas
- Erros de segurança

### access.log
Registra:
- Acessos ao formulário
- IPs dos visitantes
- Timestamps

---

## 13. Permissões de Usuário

### Tipos de Usuário
1. **Admin:** Acesso total
2. **Analisador:** Acesso apenas a currículos

### Verificação de Permissões
```php
function canAccessAction($action) {
    $userType = getUserType();
    if ($userType === 'admin' || $userType === 'analisador') {
        return true;
    }
    return false;
}
```

---

## 14. Checklist de Segurança

### Antes de Colocar em Produção

- [ ] Alterar credenciais do banco de dados em `db_connect.php`
- [ ] Criar usuários admin com senhas fortes
- [ ] Ajustar domínio no .htaccess (proteção hotlinking)
- [ ] Ativar HTTPS e descomentar redirecionamento
- [ ] Verificar permissões de diretórios (755 para pastas, 644 para arquivos)
- [ ] Testar upload de arquivos
- [ ] Verificar logs de erro
- [ ] Fazer backup do banco de dados
- [ ] Testar todas as funcionalidades
- [ ] Verificar se diretórios não listam arquivos

### Manutenção Regular

- [ ] Limpar logs antigos periodicamente
- [ ] Revisar logs de erro semanalmente
- [ ] Atualizar senhas trimestralmente
- [ ] Fazer backups regulares
- [ ] Monitorar tentativas de acesso suspeitas
- [ ] Atualizar dependências (PHP, MySQL)

---

## 15. Contato em Caso de Problemas

Se encontrar problemas de segurança:

1. **Verifique os logs:** `error.log` e `access.log`
2. **Teste permissões:** Arquivos devem ser 644, diretórios 755
3. **Verifique .htaccess:** Certifique-se que está ativo
4. **Teste mod_rewrite:** Necessário para muitas proteções

### Comandos Úteis (SSH)

```bash
# Verificar permissões
ls -la

# Corrigir permissões de arquivos
find . -type f -exec chmod 644 {} \;

# Corrigir permissões de diretórios
find . -type d -exec chmod 755 {} \;

# Ver logs em tempo real
tail -f error.log
```

---

## 16. Recursos Adicionais

### Ferramentas de Teste
- **SSL Labs:** https://www.ssllabs.com/ssltest/
- **Security Headers:** https://securityheaders.com/
- **Observatory:** https://observatory.mozilla.org/

### Documentação
- **OWASP Top 10:** https://owasp.org/www-project-top-ten/
- **PHP Security:** https://www.php.net/manual/en/security.php
- **Apache Security:** https://httpd.apache.org/docs/2.4/misc/security_tips.html

---

## Conclusão

Este sistema implementa múltiplas camadas de segurança para proteger:
- Dados dos candidatos
- Arquivos enviados
- Credenciais de acesso
- Configurações do sistema

**Importante:** A segurança é um processo contínuo. Mantenha o sistema atualizado e monitore regularmente.

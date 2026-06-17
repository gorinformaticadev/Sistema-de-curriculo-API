# 🔧 Solução para o Erro "Failed to fetch"

## 📋 Problema Identificado

O erro **"Failed to fetch"** ocorre quando o navegador não consegue se comunicar com o servidor PHP. Este documento explica as causas e soluções.

---

## 🎯 Melhorias Implementadas

### 1. **Script.js Atualizado**
- ✅ Melhor tratamento de erros de rede
- ✅ Timeout configurável (2 minutos)
- ✅ Mensagens de erro mais descritivas
- ✅ Detecção automática do caminho correto do PHP
- ✅ Diagnóstico detalhado de problemas

### 2. **Arquivos de Diagnóstico Criados**
- ✅ `test_connection.php` - Testa a configuração do servidor
- ✅ `test_diagnostico.html` - Interface visual para diagnóstico

---

## 🔍 Como Diagnosticar o Problema

### Passo 1: Executar o Diagnóstico

1. Abra o navegador e acesse:
   ```
   http://localhost/test_diagnostico.html
   ```
   (ou substitua `localhost` pelo endereço do seu servidor)

2. O sistema irá executar automaticamente os seguintes testes:
   - ✓ Verificar se `process-simple.php` existe
   - ✓ Verificar se `db_connect.php` existe
   - ✓ Verificar permissões da pasta `uploads/`
   - ✓ Verificar extensões PHP necessárias
   - ✓ Testar conexão com banco de dados
   - ✓ Verificar tabelas do banco
   - ✓ Verificar configurações de upload

3. Anote quais testes falharam

---

## 🛠️ Soluções por Tipo de Erro

### ❌ Erro: "Arquivo process-simple.php não encontrado"

**Causa:** O arquivo PHP não está no local correto ou o caminho está errado.

**Solução:**
1. Verifique se o arquivo `process-simple.php` está na mesma pasta que `index.html`
2. Verifique as permissões do arquivo (deve ser legível pelo servidor web)
3. Se estiver usando subpastas, ajuste o caminho no código

---

### ❌ Erro: "Falha na conexão com banco de dados"

**Causa:** Credenciais incorretas ou banco de dados não existe.

**Solução:**
1. Abra o arquivo `db_connect.php`
2. Verifique as credenciais:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   define('DB_NAME', 'cur');
   ```
3. Certifique-se de que o banco de dados `cur` existe
4. Execute o arquivo `install.php` para criar o banco se necessário

---

### ❌ Erro: "Diretório uploads não tem permissão de escrita"

**Causa:** O servidor web não tem permissão para salvar arquivos na pasta uploads.

**Solução Windows (XAMPP):**
1. Clique com botão direito na pasta `uploads`
2. Propriedades → Segurança
3. Adicione permissões de escrita para o usuário do servidor

**Solução Linux:**
```bash
chmod 777 uploads/
```

---

### ❌ Erro: "Extensão PHP não encontrada"

**Causa:** Extensões PHP necessárias não estão habilitadas.

**Solução (XAMPP):**
1. Abra o arquivo `php.ini` (em `C:\xampp\php\php.ini`)
2. Procure e descomente (remova o `;`) as seguintes linhas:
   ```ini
   extension=pdo_mysql
   extension=curl
   extension=fileinfo
   ```
3. Reinicie o Apache

---

### ❌ Erro: "Tempo limite excedido (timeout)"

**Causa:** Arquivos muito grandes ou conexão lenta.

**Solução:**
1. Reduza o tamanho dos arquivos (comprima PDFs e imagens)
2. Aumente os limites no `php.ini`:
   ```ini
   upload_max_filesize = 20M
   post_max_size = 25M
   max_execution_time = 300
   memory_limit = 256M
   ```
3. Reinicie o Apache

---

### ❌ Erro: "Não foi possível conectar ao servidor"

**Causa:** Servidor web não está rodando ou firewall bloqueando.

**Solução:**
1. Verifique se o Apache está rodando (XAMPP Control Panel)
2. Teste acessando: `http://localhost/`
3. Desative temporariamente o firewall/antivírus para testar
4. Verifique se não há outro programa usando a porta 80

---

## 🧪 Teste Rápido de Conexão

Execute este teste simples no console do navegador (F12):

```javascript
fetch('process-simple.php', {
    method: 'POST',
    body: new FormData()
})
.then(response => {
    console.log('Status:', response.status);
    return response.text();
})
.then(text => console.log('Resposta:', text))
.catch(error => console.error('Erro:', error));
```

**Resultados esperados:**
- ✅ Status 200 ou 400 = Servidor está respondendo
- ❌ "Failed to fetch" = Problema de conexão com servidor
- ❌ Status 404 = Arquivo não encontrado
- ❌ Status 500 = Erro interno do servidor

---

## 📱 Testando em Dispositivos Móveis

Se o erro ocorre apenas em celulares:

1. **Verifique a conexão:**
   - O celular está na mesma rede que o servidor?
   - Se estiver usando `localhost`, troque pelo IP do servidor (ex: `192.168.1.100`)

2. **Teste HTTPS:**
   - Alguns navegadores móveis bloqueiam requisições HTTP
   - Configure SSL/HTTPS no servidor

3. **Verifique o firewall:**
   - Libere a porta 80 (HTTP) ou 443 (HTTPS) no firewall do Windows

---

## 🔐 Verificação de Segurança

Após resolver o problema, verifique:

1. ✅ A pasta `uploads/` tem o arquivo `.htaccess` para segurança
2. ✅ O arquivo `db_connect.php` não é acessível diretamente pelo navegador
3. ✅ As credenciais do banco estão seguras
4. ✅ Os logs de erro estão sendo gerados corretamente

---

## 📞 Suporte

Se o problema persistir após seguir todas as etapas:

1. Verifique o arquivo `error.log` na raiz do projeto
2. Anote a mensagem de erro completa
3. Entre em contato com o suporte técnico informando:
   - Mensagem de erro completa
   - Resultado do diagnóstico (`test_diagnostico.html`)
   - Versão do PHP e servidor web
   - Sistema operacional

**WhatsApp:** (61) 3359-7358

---

## 📝 Checklist de Verificação

Antes de entrar em contato com o suporte, verifique:

- [ ] O servidor web (Apache/XAMPP) está rodando
- [ ] O banco de dados MySQL está ativo
- [ ] O arquivo `process-simple.php` existe e está acessível
- [ ] A pasta `uploads/` existe e tem permissão de escrita
- [ ] As extensões PHP necessárias estão habilitadas
- [ ] O banco de dados `cur` existe e tem as tabelas necessárias
- [ ] Executei o diagnóstico em `test_diagnostico.html`
- [ ] Verifiquei o arquivo `error.log`

---

## 🎉 Teste Final

Após aplicar as correções:

1. Acesse `test_diagnostico.html` - todos os testes devem passar ✅
2. Acesse `index.html` e tente enviar um currículo de teste
3. Verifique se a mensagem de sucesso aparece
4. Confirme se os arquivos foram salvos na pasta `uploads/`
5. Verifique se o registro foi criado no banco de dados

---

**Última atualização:** 24/11/2025
**Versão:** 2.1

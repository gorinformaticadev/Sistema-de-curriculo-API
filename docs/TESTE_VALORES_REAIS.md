# 🧪 Como Testar a Captura de Valores Reais

## ⚠️ Problema Identificado

Se você está vendo **"Valor: preenchido"** ao invés dos valores reais, siga este guia.

---

## 🔍 Passo 1: Diagnóstico

### Abra o arquivo de teste:
```
http://localhost/test_interactions.php
```

Este arquivo irá mostrar:
- ✅ Se a tabela existe
- ✅ Quantas interações estão salvas
- ✅ Quantas têm valores reais vs vazios
- ✅ Últimas 50 interações com os valores

### O que verificar:

**Se mostrar "0 com valor preenchido":**
- ❌ As interações antigas foram salvas sem valores
- ✅ Solução: Limpar e testar novamente

**Se mostrar valores preenchidos:**
- ✅ O sistema está funcionando
- ❌ Pode ser cache do navegador

---

## 🧹 Passo 2: Limpar Dados Antigos

### Opção 1: Pelo Painel Admin
1. Acesse `admin.php`
2. Faça login
3. Vá para aba "Interações do Formulário"
4. Clique em "Limpar Todas as Interações"

### Opção 2: Pelo Banco de Dados
Execute no MySQL:
```sql
TRUNCATE TABLE form_interactions;
```

---

## 🧪 Passo 3: Teste Completo

### 1. Limpe o Cache do Navegador
- **Chrome/Edge:** Ctrl + Shift + Delete
- **Firefox:** Ctrl + Shift + Delete
- Marque "Cookies" e "Cache"
- Clique em "Limpar dados"

### 2. Abra o Console do Navegador
- Pressione **F12**
- Vá para aba "Console"
- Deixe aberto para ver os logs

### 3. Abra o Formulário
```
http://localhost/index.html
```

### 4. Preencha os Campos
Preencha pelo menos:
- ✅ Nome Completo: "João da Silva Teste"
- ✅ Data de Nascimento: Qualquer data
- ✅ Estado Civil: Selecione uma opção
- ✅ Telefone: (61)99999-9999
- ✅ Email: teste@email.com

### 5. Verifique o Console
Você deve ver logs como:
```
📝 14:23:15 - CHANGE - Nome Completo → João da Silva Teste
📝 14:23:45 - CHANGE - Estado Civil → Solteiro
📝 14:24:10 - CHANGE - Telefone → (61)99999-9999
```

**Se NÃO ver os valores:**
- ❌ Há um problema no JavaScript
- Veja a seção "Solução de Problemas"

**Se VER os valores:**
- ✅ O JavaScript está funcionando
- Continue para o próximo passo

### 6. Aguarde 5 Segundos
O sistema envia os dados a cada 5 segundos automaticamente.

Você deve ver no console:
```
✅ Interações enviadas: {success: true, ...}
```

### 7. Verifique o Banco de Dados
Abra novamente:
```
http://localhost/test_interactions.php
```

Você deve ver:
- ✅ Novas interações listadas
- ✅ Coluna "Valor" com os dados reais
- ✅ "João da Silva Teste" aparecendo

### 8. Verifique no Painel Admin
1. Acesse `admin.php`
2. Vá para "Interações do Formulário"
3. Clique em "Ver Detalhes" na sua sessão
4. Você deve ver os valores reais em boxes coloridos

---

## 🐛 Solução de Problemas

### Problema 1: Console não mostra os valores

**Causa:** JavaScript não está capturando

**Solução:**
1. Verifique se `form-tracker.js` está carregando:
   - No console, digite: `window.formTracker`
   - Deve retornar um objeto, não `undefined`

2. Se retornar `undefined`:
   - Verifique se o arquivo existe
   - Verifique se está sendo incluído no HTML:
   ```html
   <script src="form-tracker.js"></script>
   ```

3. Recarregue a página com Ctrl + F5 (força reload)

---

### Problema 2: Console mostra valores mas banco está vazio

**Causa:** PHP não está salvando

**Solução:**
1. Verifique se `log_interaction.php` existe
2. Teste manualmente:
   ```javascript
   fetch('log_interaction.php', {
       method: 'POST',
       headers: {'Content-Type': 'application/json'},
       body: JSON.stringify({
           interactions: [{
               sessionId: 'test',
               fieldLabel: 'Teste',
               action: 'change',
               fieldValue: 'Valor de Teste',
               timestamp: new Date().toISOString()
           }],
           lastField: 'Teste',
           userName: 'Teste'
       })
   })
   .then(r => r.json())
   .then(d => console.log(d));
   ```

3. Verifique o resultado no console
4. Verifique `test_interactions.php` novamente

---

### Problema 3: Valores aparecem no banco mas não no admin

**Causa:** Query SQL ou JavaScript do admin

**Solução:**
1. Abra o console do navegador no painel admin
2. Clique em "Ver Detalhes" de uma sessão
3. Veja se há erros no console
4. Verifique a resposta da API:
   - Aba "Network" (Rede)
   - Procure por `getSessionDetails`
   - Clique e veja a resposta
   - Deve conter `valor_campo` com os valores

---

### Problema 4: Ainda mostra "preenchido"

**Causa:** Dados antigos no banco

**Solução:**
1. Limpe TODAS as interações antigas
2. Feche TODOS os navegadores
3. Abra um navegador em modo anônimo
4. Teste novamente do zero

---

## 📊 Teste de Validação Final

Execute este checklist:

- [ ] Console mostra valores reais nos logs
- [ ] `test_interactions.php` mostra valores na coluna "Valor"
- [ ] Painel admin mostra valores em boxes coloridos
- [ ] Não há erros no console do navegador
- [ ] Não há erros no `error.log` do servidor

**Se TODOS estiverem marcados:** ✅ Sistema funcionando!

**Se ALGUM falhar:** ❌ Veja a seção correspondente acima

---

## 🔧 Verificação Técnica

### Estrutura da Tabela
Execute no MySQL:
```sql
DESCRIBE form_interactions;
```

Deve ter a coluna:
```
valor_campo | text | YES | | NULL |
```

### Teste Manual de Inserção
Execute no MySQL:
```sql
INSERT INTO form_interactions 
(session_id, ip, ultimo_campo, acao, valor_campo, timestamp) 
VALUES 
('test123', '127.0.0.1', 'Teste', 'change', 'Valor Real de Teste', NOW());
```

Depois verifique:
```sql
SELECT * FROM form_interactions WHERE session_id = 'test123';
```

Deve mostrar "Valor Real de Teste" na coluna `valor_campo`.

---

## 📞 Ainda com Problemas?

Se após seguir TODOS os passos ainda não funcionar:

1. **Capture evidências:**
   - Screenshot do console com os logs
   - Screenshot do `test_interactions.php`
   - Screenshot do painel admin
   - Conteúdo do arquivo `error.log`

2. **Informações do ambiente:**
   - Versão do PHP: `<?php echo phpversion(); ?>`
   - Versão do MySQL
   - Navegador e versão
   - Sistema operacional

3. **Entre em contato:**
   - WhatsApp: (61) 3359-7358
   - Email: suporte@gorinformatica.com.br

---

## ✅ Checklist de Arquivos Atualizados

Certifique-se de que estes arquivos estão atualizados:

- [ ] `form-tracker.js` - Versão com captura de valores reais
- [ ] `log_interaction.php` - Salva `valor_campo`
- [ ] `admin.php` - Exibe valores em boxes coloridos
- [ ] `test_interactions.php` - Arquivo de diagnóstico

**Data da última atualização:** 24/11/2025

---

**Desenvolvido para GOR INFORMÁTICA**

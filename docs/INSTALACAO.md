# 📋 Sistema de Currículos - Guia de Instalação e Atualização

## 🆕 Nova Instalação

### Pré-requisitos
- PHP 7.4 ou superior
- MySQL 5.7 ou superior
- Servidor web (Apache/Nginx)

### Passos para Instalação

1. **Faça upload dos arquivos** para o servidor web

2. **Acesse o instalador** através do navegador:
   ```
   http://seu-dominio.com/install.php
   ```

3. **Preencha os dados solicitados:**
   - Modo de Instalação: **Nova Instalação**
   - Nome do Banco de Dados
   - Usuário do Banco de Dados (padrão: root)
   - Senha do Banco de Dados
   - Email do Usuário Admin
   - Senha do Usuário Admin

4. **Clique em "Instalar"** e aguarde a conclusão

5. **IMPORTANTE:** Após a instalação, **delete ou renomeie o arquivo `install.php`** por segurança

6. **Acesse o painel administrativo:**
   ```
   http://seu-dominio.com/admin.php
   ```

---

## 🔄 Atualização do Sistema

### Quando Atualizar?
- Ao adicionar novas funcionalidades
- Ao atualizar a estrutura do banco de dados
- Ao aplicar correções que requerem mudanças no banco

### Passos para Atualização

1. **Faça backup do banco de dados** antes de atualizar:
   ```sql
   mysqldump -u usuario -p nome_banco > backup.sql
   ```

2. **Faça upload dos novos arquivos** para o servidor (sobrescreva os antigos)

3. **Acesse o instalador:**
   ```
   http://seu-dominio.com/install.php
   ```

4. **Selecione o modo de atualização:**
   - Modo de Instalação: **Atualizar Sistema (Manter dados existentes)**
   - Nome do Banco de Dados (o mesmo existente)
   - Usuário do Banco de Dados
   - Senha do Banco de Dados
   - **NÃO é necessário** informar email/senha de admin

5. **Clique em "Instalar"** - O sistema irá:
   - Verificar tabelas existentes
   - Criar novas tabelas (se necessário)
   - Adicionar novas colunas (se necessário)
   - **Manter todos os dados existentes**

6. **Delete o arquivo `install.php`** após a atualização

---

## 🎯 Novos Recursos - Sistema de Rastreamento de Interações

### O que foi adicionado?

Esta atualização inclui um sistema completo de rastreamento de interações do formulário:

#### 📊 Dashboard de Análise
- Total de sessões de usuários
- Formulários completos vs abandonados
- Taxa de conversão em tempo real
- Gráfico de campos com maior abandono

#### 🔍 Rastreamento Detalhado
- IP do visitante
- Sistema operacional e navegador
- Tipo de dispositivo (Desktop/Mobile/Tablet)
- Nome completo (quando preenchido)
- Último campo interagido antes do abandono
- Timeline completa de interações

#### 📈 Análise de Abandono
- Identifica em qual campo os usuários mais abandonam o formulário
- Ajuda a identificar problemas de usabilidade
- Permite otimizar o formulário para aumentar conversões

### Como Acessar?

1. Faça login no painel administrativo
2. Clique na aba **"Interações do Formulário"**
3. Use os filtros para analisar períodos específicos

---

## 🛠️ Configurações Pós-Instalação

### 1. Configurar API do WhatsApp
- Acesse: Admin → Configurações
- Preencha: URL da API, Token Bearer, Número para Notificações
- Configure a mensagem de conclusão do cadastro

### 2. Configurar Email
- Acesse: Admin → Configurações → Configuração de Email
- Preencha: Email Remetente e Email para Notificações

### 3. Testar Envios
- Acesse: Admin → Testes da API
- Envie uma mensagem de teste para verificar a configuração

---

## 📁 Estrutura de Arquivos

```
/
├── index.html              # Formulário público
├── admin.php              # Painel administrativo
├── install.php            # Instalador/Atualizador
├── db_connect.php         # Conexão com banco de dados
├── process-simple.php     # Processamento do formulário
├── log_access.php         # Log de acessos simples
├── log_interaction.php    # Log de interações detalhadas (NOVO)
├── form-tracker.js        # Sistema de rastreamento (NOVO)
├── script.js              # Scripts do formulário
├── styles.css             # Estilos
├── update_database.php    # Script de atualização manual (NOVO)
└── uploads/               # Diretório de arquivos enviados
```

---

## 🔒 Segurança

### Recomendações:
1. **Delete `install.php`** após instalação/atualização
2. Use senhas fortes para o admin
3. Configure HTTPS no servidor
4. Mantenha o PHP atualizado
5. Faça backups regulares do banco de dados

---

## 🆘 Solução de Problemas

### Erro: "Tabela já existe"
- **Solução:** Use o modo "Atualizar Sistema" ao invés de "Nova Instalação"

### Erro: "Permissão negada ao criar diretório uploads"
- **Solução:** Configure permissões: `chmod 755 uploads/`

### Logs de interação não aparecem
- **Solução:** Verifique se a tabela `form_interactions` foi criada
- Execute manualmente: `php update_database.php`

### Erro ao enviar formulário
- **Solução:** Verifique os logs em `error.log`
- Verifique permissões do diretório `uploads/`

---

## 📞 Suporte

Para dúvidas ou problemas:
- Verifique os logs do sistema em: Admin → Logs do Sistema
- Verifique os logs de acesso em: Admin → Logs de Acesso
- Consulte a documentação técnica

---

## 📝 Changelog

### Versão 2.0 (Atual)
- ✅ Sistema de rastreamento de interações do formulário
- ✅ Dashboard visual de análise de conversão
- ✅ Análise de abandono por campo
- ✅ Timeline detalhada de interações
- ✅ Filtros por período, dispositivo e nome
- ✅ Instalador com modo de atualização
- ✅ Detecção automática de navegador, SO e dispositivo

### Versão 1.0
- Sistema básico de cadastro de currículos
- Painel administrativo
- Integração com API WhatsApp
- Notificações por email
- Upload de arquivos (PDF e foto)

---

**Desenvolvido para GOR INFORMÁTICA** 🚀

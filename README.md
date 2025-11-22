# 📚 Sistema de Currículos - GOR INFORMÁTICA

## 🎯 Versão 2.0 - Sistema de Rastreamento de Interações

Sistema completo de cadastro de currículos com rastreamento inteligente de interações, análise de abandono e dashboard visual para otimização da taxa de conversão.

---

## 🚀 Início Rápido

### Para Atualizar o Sistema Existente

1. **Faça backup do banco de dados**
2. **Faça upload dos novos arquivos**
3. **Acesse:** [install.php](install.php)
4. **Selecione:** "Atualizar Sistema (Manter dados existentes)"
5. **Execute a atualização**
6. **Delete install.php**

**Tempo estimado:** 10 minutos  
**Guia detalhado:** [INICIO_RAPIDO.md](INICIO_RAPIDO.md)

### Para Nova Instalação

1. **Configure o servidor** (PHP 7.4+, MySQL 5.7+)
2. **Faça upload dos arquivos**
3. **Acesse:** [install.php](install.php)
4. **Selecione:** "Nova Instalação"
5. **Preencha os dados** e execute
6. **Delete install.php**

**Guia completo:** [INSTALACAO.md](INSTALACAO.md)

---

## 📖 Documentação

### 📋 Guias de Instalação
- **[INICIO_RAPIDO.md](INICIO_RAPIDO.md)** - Atualização em 3 passos (10 min)
- **[INSTALACAO.md](INSTALACAO.md)** - Guia completo de instalação e atualização
- **[CHECKLIST_ATUALIZACAO.html](CHECKLIST_ATUALIZACAO.html)** - Checklist interativo passo a passo

### 📊 Informações Técnicas
- **[ATUALIZACAO_v2.0.md](ATUALIZACAO_v2.0.md)** - Resumo técnico completo das mudanças
- **[PLANO_DE_TAREFAS.html](PLANO_DE_TAREFAS.html)** - Plano visual de implementação
- **[RESUMO_EXECUTIVO.md](RESUMO_EXECUTIVO.md)** - Visão executiva do projeto

### 🔧 Ferramentas
- **[test_system.php](test_system.php)** - Diagnóstico completo do sistema
- **[update_database.php](update_database.php)** - Script de atualização manual do banco

---

## ✨ Novos Recursos v2.0

### 📊 Dashboard de Análise
- **4 Cards de Métricas:** Sessões, Completos, Abandonos, Taxa de Conversão
- **Gráfico de Abandono:** Identifica campos problemáticos
- **Filtros Avançados:** Por período, dispositivo e nome
- **Timeline Detalhada:** Veja cada interação do usuário

### 🔍 Rastreamento Inteligente
- **Captura Automática:** Todas as interações são registradas
- **Detecção de Dispositivo:** Desktop, Mobile ou Tablet
- **Identificação de Navegador:** Chrome, Firefox, Safari, etc.
- **Sistema Operacional:** Windows, macOS, Linux, Android, iOS
- **Último Campo:** Identifica onde o usuário abandonou

### 📈 Análise de Conversão
- **Taxa de Conversão:** Percentual de formulários completos
- **Análise por Dispositivo:** Compare Desktop vs Mobile
- **Pontos de Abandono:** Veja onde os usuários desistem
- **Otimização Baseada em Dados:** Melhore continuamente

---

## 📁 Estrutura de Arquivos

### Arquivos Principais
```
index.html              # Formulário público
admin.php              # Painel administrativo
process-simple.php     # Processamento do formulário
db_connect.php         # Conexão com banco de dados
```

### Novos Arquivos v2.0
```
form-tracker.js        # Sistema de rastreamento (NOVO)
log_interaction.php    # Endpoint de logging (NOVO)
update_database.php    # Script de atualização (NOVO)
test_system.php        # Diagnóstico do sistema (NOVO)
```

### Documentação
```
README.md                      # Este arquivo
INSTALACAO.md                  # Guia de instalação
INICIO_RAPIDO.md              # Guia rápido
ATUALIZACAO_v2.0.md           # Resumo técnico
RESUMO_EXECUTIVO.md           # Visão executiva
PLANO_DE_TAREFAS.html         # Plano visual
CHECKLIST_ATUALIZACAO.html    # Checklist interativo
```

---

## 🗄️ Banco de Dados

### Tabelas Existentes
- `usuarios` - Usuários administrativos
- `config` - Configurações do sistema
- `curriculos` - Currículos cadastrados

### Nova Tabela v2.0
- `form_interactions` - Interações do formulário (rastreamento)

**Estrutura completa:** Ver [ATUALIZACAO_v2.0.md](ATUALIZACAO_v2.0.md#nova-estrutura-do-banco-de-dados)

---

## 🎯 Funcionalidades

### Formulário Público
- ✅ Cadastro completo de currículos
- ✅ Upload de PDF e foto
- ✅ Validação em tempo real
- ✅ Interface responsiva
- ✅ **Rastreamento de interações (NOVO)**

### Painel Administrativo
- ✅ Visualização de currículos
- ✅ Configuração de API WhatsApp
- ✅ Configuração de email
- ✅ Logs do sistema
- ✅ Logs de acesso
- ✅ **Dashboard de interações (NOVO)**
- ✅ **Análise de abandono (NOVO)**
- ✅ **Timeline de sessões (NOVO)**

### Notificações
- ✅ WhatsApp via API
- ✅ Email automático
- ✅ Mensagem de conclusão personalizável

---

## 📊 Métricas Rastreadas

### Por Sessão
- Duração total
- Número de interações
- Campos visitados
- Ponto de abandono
- Dispositivo usado

### Por Campo
- Taxa de abandono
- Tempo de preenchimento
- Número de tentativas

### Geral
- Taxa de conversão
- Conversão por dispositivo
- Tendências temporais

---

## 🔒 Segurança e Privacidade

### Dados Coletados
- ✅ Metadados de interação (foco, mudança)
- ✅ Nome do campo (não o valor)
- ✅ Informações técnicas (IP, navegador, SO)
- ✅ Nome completo (quando preenchido)

### Dados NÃO Coletados
- ❌ Valores completos de campos
- ❌ Senhas ou dados sensíveis
- ❌ Informações além do necessário

### Conformidade
- ✅ Dados armazenados localmente
- ✅ Sem compartilhamento com terceiros
- ✅ Logs podem ser limpos
- ✅ Conforme LGPD

---

## 🛠️ Requisitos do Sistema

### Servidor
- PHP 7.4 ou superior
- MySQL 5.7 ou superior
- Apache ou Nginx

### Extensões PHP
- PDO
- PDO_MySQL
- cURL
- JSON
- mbstring

### Navegadores Suportados
- Chrome (recomendado)
- Firefox
- Safari
- Edge
- Opera

---

## 📞 Suporte

### Diagnóstico
1. Execute [test_system.php](test_system.php)
2. Verifique Admin → Logs do Sistema
3. Consulte a documentação

### Documentação
- **Instalação:** [INSTALACAO.md](INSTALACAO.md)
- **Atualização:** [INICIO_RAPIDO.md](INICIO_RAPIDO.md)
- **Técnico:** [ATUALIZACAO_v2.0.md](ATUALIZACAO_v2.0.md)
- **Executivo:** [RESUMO_EXECUTIVO.md](RESUMO_EXECUTIVO.md)

### Solução de Problemas
Ver seção "Solução de Problemas" em [INSTALACAO.md](INSTALACAO.md)

---

## 📈 Roadmap

### Versão Atual (2.0)
- ✅ Sistema de rastreamento de interações
- ✅ Dashboard visual de análise
- ✅ Análise de abandono por campo
- ✅ Timeline detalhada de sessões
- ✅ Instalador com modo de atualização

### Futuras Melhorias (Sugestões)
- 📊 Exportação de relatórios em PDF/CSV
- 📧 Alertas automáticos de abandono
- 🎨 Testes A/B de formulários
- 📱 App mobile para RH
- 🤖 Sugestões automáticas de otimização

---

## 📝 Changelog

### v2.0 (Novembro 2024)
- ✨ Sistema de rastreamento de interações
- ✨ Dashboard visual moderno
- ✨ Análise de abandono
- ✨ Timeline de sessões
- ✨ Instalador com modo atualização
- ✨ Detecção automática de navegador/SO/dispositivo
- 📚 Documentação completa

### v1.0 (Inicial)
- ✅ Sistema básico de cadastro
- ✅ Painel administrativo
- ✅ Integração WhatsApp
- ✅ Notificações por email
- ✅ Upload de arquivos

---

## 👥 Créditos

**Desenvolvido para:** GOR INFORMÁTICA  
**Versão:** 2.0  
**Data:** Novembro 2024  
**Status:** ✅ Pronto para Produção

---

## 📄 Licença

Sistema proprietário desenvolvido exclusivamente para GOR INFORMÁTICA.  
Todos os direitos reservados.

---

## 🎯 Próximos Passos

1. ✅ Leia o [INICIO_RAPIDO.md](INICIO_RAPIDO.md)
2. ✅ Execute a atualização usando [install.php](install.php)
3. ✅ Teste com [test_system.php](test_system.php)
4. ✅ Acesse o novo dashboard em [admin.php](admin.php)
5. ✅ Comece a analisar as interações!

---

**Dúvidas?** Consulte a documentação completa ou execute o diagnóstico do sistema.

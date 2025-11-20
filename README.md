# Sistema de Cadastro de Currículos - GOR INFORMÁTICA

Sistema completo para cadastro de currículos com área administrativa.

## Características

- **Frontend**: HTML5, CSS3, JavaScript puro
- **Backend**: PHP puro
- **Banco de Dados**: MySQL
- **Upload**: Suporte a PDF (currículo) e imagens (foto)
- **Email**: Envio automático de notificações
- **Responsivo**: Funciona em desktop e mobile

## Instalação

1. Faça upload de todos os arquivos para seu servidor web
2. Certifique-se de que o PHP está habilitado
3. Crie a pasta `uploads/` com permissões de escrita (chmod 777)
4. Acesse `index.html` no navegador

## Configuração

### Área Administrativa
- **URL**: `admin.php` ou clique em "Administração" na página principal
- **Usuário**: `rh@gorinformatica.com.br`
- **Senha**: `Gor103Dmas@`

### Configuração de Email
1. Acesse a área administrativa
2. Configure o servidor SMTP
3. Defina o email destinatário

## Estrutura de Arquivos

```
/
├── index.html          # Página principal
├── styles.css          # Estilos CSS
├── script.js           # JavaScript
├── process.php         # Processamento do formulário
├── admin.php           # Área administrativa
├── uploads/            # Pasta para arquivos enviados
├── curriculos.log      # Log dos currículos (criado automaticamente)
└── README.md           # Este arquivo
```

## Funcionalidades

### Formulário Principal
- Validação em tempo real
- Campos condicionais (aparecem conforme preenchimento)
- Upload de currículo (PDF) e foto
- Validação de tipos de arquivo e tamanho
- Interface responsiva

### Área Administrativa
- Login seguro
- Visualização de currículos cadastrados
- Download de arquivos enviados
- Configuração de email
- Estatísticas básicas

## Segurança

- Validação de dados no frontend e backend
- Sanitização de inputs
- Verificação de tipos de arquivo
- Limite de tamanho de upload (8MB)
- Sessões PHP para área administrativa

## Personalização

### Cores e Estilos
Edite o arquivo `styles.css` para personalizar:
- Cores da marca
- Fontes
- Espaçamentos
- Responsividade

### Campos do Formulário
Para adicionar/remover campos:
1. Edite `index.html` (estrutura)
2. Edite `script.js` (lógica condicional)
3. Edite `process.php` (processamento)

### Email Template
Edite a função `sendEmail()` em `process.php` para personalizar o template do email.

## Requisitos do Servidor

- PHP 7.0 ou superior
- Função `mail()` habilitada (ou configurar SMTP)
- Permissões de escrita na pasta `uploads/`
- Suporte a upload de arquivos

## Suporte

Para dúvidas ou problemas:
- Verifique os logs de erro do PHP
- Certifique-se de que as permissões estão corretas
- Teste o envio de email com configurações SMTP válidas

## Licença

Este sistema foi desenvolvido especificamente para GOR INFORMÁTICA.
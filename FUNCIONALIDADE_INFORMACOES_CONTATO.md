# Funcionalidade: Informações de Contato dos Currículos

## Descrição

Foi adicionada uma nova funcionalidade ao sistema de currículos que permite registrar, visualizar e editar informações adicionais de contato para cada candidato.

## Características

### 1. Ícone na Listagem
- Um novo ícone de "caderneta de endereços" (<i class="fas fa-address-book"></i>) foi adicionado na coluna "Contato" da listagem de currículos
- O ícone abre um modal com todas as informações de contato registradas para aquele candidato

### 2. Modal de Informações de Contato
O modal permite:
- **Visualizar** todas as informações de contato registradas
- **Adicionar** novas informações
- **Editar** informações existentes
- **Deletar** informações que não são mais necessárias

### 3. Tipos de Contato Disponíveis
- Telefone Adicional
- Email Adicional
- WhatsApp
- LinkedIn
- Endereço
- Contato de Emergência
- Referência
- Outro (personalizado)

### 4. Campos de Informação
Cada registro de contato contém:
- **Tipo de Contato**: Categoria da informação
- **Informação**: O dado em si (telefone, email, endereço, etc.)
- **Observações**: Campo opcional para notas adicionais
- **Registrado por**: Email do usuário que adicionou a informação
- **Data de Registro**: Data e hora em que foi adicionada

## Instalação

### Passo 1: Executar Script de Atualização do Banco de Dados

Execute o arquivo `update_add_contact_info.php` no seu navegador:

```
http://seu-dominio.com/update_add_contact_info.php
```

Este script irá criar a tabela `curriculo_contatos` no banco de dados.

### Passo 2: Verificar Instalação

Após executar o script, você verá uma mensagem de sucesso confirmando que a tabela foi criada.

### Passo 3: Usar a Funcionalidade

1. Acesse o painel administrativo (`admin.php`)
2. Na aba "Currículos", você verá uma nova coluna "Contato"
3. Clique no ícone azul de caderneta para abrir o modal de informações
4. Adicione, edite ou visualize as informações de contato

## Estrutura do Banco de Dados

### Tabela: curriculo_contatos

```sql
CREATE TABLE curriculo_contatos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    curriculo_id INT NOT NULL,
    tipo_contato VARCHAR(100) NOT NULL,
    informacao TEXT NOT NULL,
    observacoes TEXT,
    registrado_por VARCHAR(255) NOT NULL,
    data_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (curriculo_id) REFERENCES curriculos(id) ON DELETE CASCADE
);
```

## APIs Adicionadas

### 1. GET /admin.php?action=getContactInfo
Busca todas as informações de contato de um currículo específico.

**Parâmetros:**
- `curriculo_id` (int): ID do currículo

**Resposta:**
```json
{
    "success": true,
    "contacts": [
        {
            "id": 1,
            "tipo_contato": "Telefone Adicional",
            "informacao": "(11) 98765-4321",
            "observacoes": "Telefone da mãe",
            "registrado_por": "admin@empresa.com",
            "data_registro": "2024-12-01 10:30:00"
        }
    ]
}
```

### 2. POST /admin.php (action=addContactInfo)
Adiciona uma nova informação de contato.

**Parâmetros:**
- `curriculo_id` (int): ID do currículo
- `tipo_contato` (string): Tipo da informação
- `informacao` (string): A informação em si
- `observacoes` (string, opcional): Observações adicionais
- `csrf_token` (string): Token de segurança

### 3. POST /admin.php (action=updateContactInfo)
Atualiza uma informação de contato existente.

**Parâmetros:**
- `contact_id` (int): ID da informação de contato
- `curriculo_id` (int): ID do currículo
- `tipo_contato` (string): Tipo da informação
- `informacao` (string): A informação em si
- `observacoes` (string, opcional): Observações adicionais
- `csrf_token` (string): Token de segurança

### 4. POST /admin.php (action=deleteContactInfo)
Deleta uma informação de contato.

**Parâmetros:**
- `contact_id` (int): ID da informação de contato
- `csrf_token` (string): Token de segurança

## Permissões

- **Administradores**: Acesso completo (visualizar, adicionar, editar, deletar)
- **Analisadores**: Acesso completo (visualizar, adicionar, editar, deletar)

## Segurança

- Todas as operações de modificação (adicionar, editar, deletar) requerem token CSRF
- Validação de IDs e dados de entrada
- Logs de todas as operações realizadas
- Relacionamento com chave estrangeira garante integridade referencial

## Exemplos de Uso

### Adicionar Telefone Adicional
1. Clique no ícone de caderneta na listagem
2. Clique em "Adicionar Informação"
3. Selecione "Telefone Adicional"
4. Digite o número: "(11) 98765-4321"
5. Adicione observação: "Telefone da mãe"
6. Clique em "Salvar"

### Adicionar LinkedIn
1. Clique no ícone de caderneta
2. Clique em "Adicionar Informação"
3. Selecione "LinkedIn"
4. Digite: "https://linkedin.com/in/nome-candidato"
5. Clique em "Salvar"

### Adicionar Referência
1. Clique no ícone de caderneta
2. Clique em "Adicionar Informação"
3. Selecione "Referência"
4. Digite: "João Silva - Ex-gerente na Empresa XYZ - (11) 99999-9999"
5. Adicione observação: "Trabalhou junto por 3 anos"
6. Clique em "Salvar"

## Benefícios

1. **Centralização**: Todas as informações de contato em um só lugar
2. **Histórico**: Registro de quem adicionou cada informação e quando
3. **Flexibilidade**: Suporta diversos tipos de contato
4. **Organização**: Facilita o acompanhamento de candidatos
5. **Rastreabilidade**: Logs de todas as operações

## Notas Técnicas

- A tabela usa `ON DELETE CASCADE`, então ao deletar um currículo, todas as suas informações de contato são automaticamente removidas
- Índices foram criados para otimizar consultas por `curriculo_id` e `data_registro`
- O campo `informacao` é do tipo TEXT para suportar informações longas (como endereços completos)
- Charset UTF-8 para suportar caracteres especiais

## Suporte

Em caso de problemas:
1. Verifique se o script de atualização foi executado com sucesso
2. Confirme que a tabela `curriculo_contatos` existe no banco de dados
3. Verifique os logs do sistema em `error.log`
4. Certifique-se de que o usuário tem permissões adequadas

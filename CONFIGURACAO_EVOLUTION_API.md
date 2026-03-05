# Configuração Evolution API - MultiLife Care

## Credenciais Fornecidas

```
Base URL: http://31.97.83.150:8080
Manager Link: http://31.97.83.150:8080/manager/
API Token: d0b9eb5a5fe4993598b4ef2b51b98d3d4fa8209e464e9988cf6fe86f9c1a194e
```

## Passos para Configuração

### 1. Rodar Migrations

Execute as seguintes migrations no banco de dados **NA ORDEM**:

```bash
# 1. Migration para tabela inbound_emails
migrations/2026-03-04_0037_inbound_emails.sql

# 2. Migration para configuração padrão Evolution API (IMPORTANTE!)
migrations/2026-03-04_0038_evolution_api_default_config.sql
```

**Nota:** A migration 0038 já configura automaticamente as credenciais da Evolution API.

### 2. Configurar Evolution API no Sistema

**OPÇÃO 1: Configuração Automática (Recomendado)**

Execute a migration que já contém as credenciais pré-configuradas:

```bash
# Execute no banco de dados:
migrations/2026-03-04_0038_evolution_api_default_config.sql
```

As seguintes configurações serão aplicadas automaticamente:
- **Base URL:** http://31.97.83.150:8080
- **API Key:** d0b9eb5a5fe4993598b4ef2b51b98d3d4fa8209e464e9988cf6fe86f9c1a194e
- **Instance:** multilife

**OPÇÃO 2: Configuração Manual**

1. Acesse: **Configurações** (`/admin_settings.php`)
2. Localize a seção **"Evolution API"**
3. Preencha os campos:

```
Evolution - Base URL: http://31.97.83.150:8080
Evolution - API Key: d0b9eb5a5fe4993598b4ef2b51b98d3d4fa8209e464e9988cf6fe86f9c1a194e
Evolution - Instance: multilife
```

4. Clique em **"Salvar Configurações"**

### 3. Acessar Central WhatsApp

1. No menu lateral, clique em **"WhatsApp"**
2. Você verá 3 opções:
   - **Instâncias** - Conectar e gerenciar instâncias
   - **Grupos** - Gerenciar grupos WhatsApp
   - **Mensagens** - Histórico de mensagens enviadas

### 4. Conectar Instância WhatsApp

1. Clique em **"Instâncias"** (`/whatsapp_instances.php`)
2. Clique no botão **"Gerar QR Code para Conectar"**
3. Escaneie o QR Code com o WhatsApp do celular:
   - Abra WhatsApp no celular
   - Toque em **⋮** (menu) > **Aparelhos conectados**
   - Toque em **"Conectar um aparelho"**
   - Escaneie o QR Code exibido na tela

4. Após conectar, atualize a página para ver o status **"✓ Conectado"**

## Estrutura de Arquivos Criados

### Páginas WhatsApp

- `whatsapp_hub.php` - Central de gerenciamento WhatsApp
- `whatsapp_instances.php` - Gestão de instâncias e QR Code
- `whatsapp_groups_list.php` - Lista de grupos (já existia)
- `whatsapp_messages_log.php` - Histórico de mensagens

### Migration

- `migrations/2026-03-04_0037_inbound_emails.sql` - Tabela para e-mails recebidos

### API

- `app/evolution_api_v1.php` - Métodos adicionados:
  - `getConnectionStatus()` - Verifica status da conexão
  - `generateQrCode()` - Gera QR Code para conexão

## Funcionalidades Disponíveis

### No Menu WhatsApp

1. **Instâncias**
   - Visualizar status da conexão
   - Gerar QR Code
   - Conectar/desconectar instância
   - Ver configurações da API

2. **Grupos**
   - Listar grupos cadastrados
   - Criar novos grupos
   - Editar grupos existentes
   - Gerenciar membros

3. **Mensagens**
   - Ver histórico de mensagens enviadas
   - Logs de sucesso/erro
   - Payload e resposta da API

## Troubleshooting

### Erro: "Evolution API não configurada"

**Solução:** Configure as credenciais em `/admin_settings.php` conforme passo 2.

### Erro: "Table 'inbound_emails' doesn't exist"

**Solução:** Execute a migration `2026-03-04_0037_inbound_emails.sql`.

### QR Code não aparece

**Possíveis causas:**
1. API não configurada corretamente
2. Base URL incorreta
3. API Key inválida
4. Instância já conectada

**Solução:** Verifique as configurações e tente novamente.

## Notas Importantes

- As configurações de API/Token devem ser feitas em **Configurações** (`/admin_settings.php`)
- Todas as outras configurações de WhatsApp ficam no menu **WhatsApp**
- O QR Code expira após alguns minutos - gere um novo se necessário
- Apenas uma instância pode estar conectada por vez com o mesmo número

## Links Úteis

- Manager Evolution API: http://31.97.83.150:8080/manager/
- Documentação Evolution API: https://doc.evolution-api.com/

# Evolution API - Rotas Implementadas

Este documento lista todas as rotas da Evolution API utilizadas no sistema e seu status de implementação.

## 📡 Configuração Base

- **Base URL:** Configurável em `admin_settings` → `evolution.base_url`
- **API Key:** Configurável em `admin_settings` → `evolution.api_key`
- **Instance:** Configurável em `admin_settings` → `evolution.instance`

---

## ✅ Rotas Implementadas e Funcionais

### 1. Envio de Mensagens

**Endpoint:** `POST /message/sendText/{instance}`

**Arquivo:** `chat_web.php` (linha ~74)

**Payload:**
```json
{
  "number": "5511999999999@s.whatsapp.net",
  "text": "Mensagem de texto"
}
```

**Status:** ✅ Funcional

---

### 2. Buscar Perfil do Contato

**Endpoint:** `POST /chat/fetchProfile/{instance}`

**Arquivos:** 
- `chat_web.php` (linha ~175)
- `chat_webhook.php` (linha ~130)

**Payload:**
```json
{
  "number": "5511999999999@s.whatsapp.net"
}
```

**Resposta:**
```json
{
  "wuid": "5511999999999@s.whatsapp.net",
  "numberExists": true,
  "picture": "https://...",
  "status": {
    "status": "...",
    "setAt": "..."
  }
}
```

**Status:** ✅ Funcional

---

### 3. Buscar Todos os Grupos

**Endpoint:** `GET /group/fetchAllGroups/{instance}`

**Arquivos:**
- `chat_web.php` (linha ~317)
- `chat_sync_evolution.php` (linha ~27)

**Headers:**
```
apikey: {API_KEY}
```

**Resposta:**
```json
[
  {
    "id": "120363...@g.us",
    "subject": "Nome do Grupo",
    "picture": "https://...",
    "participants": [...]
  }
]
```

**Status:** ✅ Funcional

---

### 4. Criar Grupo

**Endpoint:** `POST /group/create/{instance}`

**Arquivo:** `chat_web.php` (linha ~264)

**Payload:**
```json
{
  "subject": "Nome do Grupo",
  "participants": [
    "5511999999999@s.whatsapp.net",
    "5511888888888@s.whatsapp.net"
  ]
}
```

**Status:** ✅ Funcional

---

### 5. Adicionar/Remover Participantes de Grupo

**Endpoint:** `POST /group/updateParticipant/{instance}`

**Arquivo:** `chat_groups.php` (linha ~60, ~90)

**Payload (Adicionar):**
```json
{
  "groupJid": "120363...@g.us",
  "action": "add",
  "participants": ["5511999999999@s.whatsapp.net"]
}
```

**Payload (Remover):**
```json
{
  "groupJid": "120363...@g.us",
  "action": "remove",
  "participants": ["5511999999999@s.whatsapp.net"]
}
```

**Status:** ✅ Funcional

---

## 🔄 Webhook

**Endpoint Configurado:** `https://seu-dominio.com/chat_webhook.php`

**Arquivo:** `chat_webhook.php`

**Eventos Recebidos:**
- `messages.upsert` - Nova mensagem recebida
- `messages.update` - Status de mensagem atualizado

**Payload de Exemplo:**
```json
{
  "event": "messages.upsert",
  "instance": "multilife",
  "data": {
    "key": {
      "remoteJid": "5511999999999@s.whatsapp.net",
      "fromMe": false,
      "id": "..."
    },
    "message": {
      "conversation": "Texto da mensagem"
    }
  }
}
```

**Status:** ✅ Funcional

---

## 📋 Endpoints Internos do Sistema

### 1. Sincronização de Grupos

**Rota:** `POST|GET /chat_sync_evolution.php`

**Função:** Busca todos os grupos da Evolution API e salva no banco

**Resposta:**
```json
{
  "success": true,
  "count": 15
}
```

---

### 2. Atualizar Status do Chat

**Rota:** `POST /chat_update_status.php`

**Payload:**
```json
{
  "chat_id": "5511999999999@s.whatsapp.net",
  "status": "atendendo|aguardando|resolvido"
}
```

---

### 3. Salvar Informações de Captação

**Rota:** `POST /chat_save_capture.php`

**Payload:**
```json
{
  "chat_id": "5511999999999@s.whatsapp.net",
  "capture_type": "paciente|profissional|empresa|parceiro",
  "capture_notes": "Observações..."
}
```

---

## 🔧 Troubleshooting

### Erro 404 nas Rotas

**Problema:** Endpoints retornam 404

**Soluções:**
1. Verificar se arquivos PHP existem no diretório raiz
2. Verificar configuração do servidor web (Apache/Nginx)
3. Verificar permissões de arquivo (644 para .php)
4. Verificar se `mod_rewrite` está ativado (Apache)

### Erro de Autenticação na API

**Problema:** API retorna 401/403

**Soluções:**
1. Verificar `evolution.api_key` em configurações
2. Verificar se API Key está correta no painel Evolution
3. Verificar se instância está ativa

### Webhook Não Recebe Mensagens

**Problema:** Mensagens não aparecem no sistema

**Soluções:**
1. Verificar URL do webhook no painel Evolution
2. Verificar logs do servidor (`error_log`)
3. Testar webhook manualmente com cURL
4. Verificar se `chat_webhook.php` tem permissões corretas

---

## 📚 Documentação Oficial

**Evolution API:** https://doc.evolution-api.com/

**Endpoints Principais:**
- `/message/*` - Envio de mensagens
- `/chat/*` - Informações de chats
- `/group/*` - Gerenciamento de grupos
- `/instance/*` - Gerenciamento de instâncias

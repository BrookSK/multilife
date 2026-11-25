# Rotas do serviço WhatsApp usadas pelo sistema

O sistema chama o serviço **ml-baileys-service** (Node + Baileys) com o mesmo
contrato que a Evolution v2 tinha. Cliente PHP: `app/whatsapp_api.php`
(classe `WhatsAppApi`; helpers `wa_api_base_url()`, `wa_api_key()`,
`wa_default_instance()`).

- **Base URL:** `admin_settings` → `baileys.base_url`
- **API Key:** `admin_settings` → `baileys.api_key` (header `apikey`)
- **Instância padrão:** `admin_settings` → `baileys.instance`

A lista completa de rotas e eventos está em `ml-baileys-service/README.md`.

## Principais usos no sistema

| Uso | Rota | Onde |
|---|---|---|
| Enviar texto (privado e grupo) | `POST /message/sendText/{inst}` `{number, text}` | chat, captação, notificações |
| Enviar mídia | `POST /message/sendMedia/{inst}` | `chat_send_media.php`, modelos de mensagem |
| Nota de voz | `POST /message/sendWhatsAppAudio/{inst}` | `chat_send_media.php` |
| Criar grupo | `POST /group/create/{inst}` | captação, `chat_web.php`, `whatsapp_groups_create_api_post.php` |
| Membros do grupo | `GET /group/participants/{inst}?groupJid=`, `POST /group/updateParticipant/{inst}` | captação, telas de grupo |
| Só admins enviam | `POST /group/updateSetting/{inst}` `{groupJid, action:"announcement"}` | captação, cron de grupos |
| Lista de grupos | `GET /group/fetchAllGroups/{inst}?getParticipants=` | sincronização de grupos |
| Histórico de conversa | `POST /chat/findMessages/{inst}` | sincronização do chat, reações, LID |
| Baixar mídia | `POST /chat/getBase64FromMediaMessage/{inst}` | webhook e sincronização |
| Perfil / foto | `POST /chat/fetchProfile/{inst}` | chat |
| QR Code / código | `GET /instance/connect/{inst}[?number=]` | telas de conexão |
| Estado | `GET /instance/connectionState/{inst}` | telas de conexão, captação |
| Webhook | `POST /webhook/set/{inst}`, `GET /webhook/find/{inst}` | conexão, Webhook Debug |
| Monitor | `GET /status`, `POST /heal/{inst}`, `POST /redispatch/{inst}`, `GET /status/{inst}/history` | `app/whatsapp_watchdog.php`, Sincronizar, Monitor |

## Webhook recebido

`POST /chat_webhook.php` (fila em `app/whatsapp_webhook_inbox.php`, regras em
`app/whatsapp_webhook_handler.php`). Eventos: `messages.upsert`, `send.message`,
`messages.delete`, `contacts.upsert`, `connection.update`, `qrcode.updated`.

```json
{
  "event": "messages.upsert",
  "instance": "multilife",
  "data": {
    "key": { "remoteJid": "5511999999999@s.whatsapp.net", "fromMe": false, "id": "3EB0..." },
    "pushName": "Fulano",
    "message": { "conversation": "Texto da mensagem" },
    "messageType": "conversation",
    "messageTimestamp": 1764000000
  }
}
```

Quando o contato vem só pelo LID (`@lid`), a chave traz o número real em
`remoteJidAlt`/`senderPn` (privado) ou `participantAlt`/`participantPn` (grupo).

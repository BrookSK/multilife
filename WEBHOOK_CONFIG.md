# Webhook do WhatsApp (receber mensagens)

## 1. URL

O serviço WhatsApp (ml-baileys-service) entrega os eventos em:

```
https://seu-dominio.com/chat_webhook.php
```

Defina `app.public_base_url` em Configurações para o sistema montar essa URL.

## 2. Como fica configurado

O sistema grava o webhook da instância sozinho ao conectar o número. Para
conferir ou reaplicar: `/admin_webhook_debug.php` → "Configuração do webhook" →
**Aplicar padrão**. Padrão: um único endereço, base64 ligado (a mídia vem junto)
e os eventos `MESSAGES_UPSERT`, `SEND_MESSAGE`, `MESSAGES_DELETE`,
`CONTACTS_UPSERT`, `CONNECTION_UPDATE`, `QRCODE_UPDATED`.

Pela API do serviço (mesmo formato da Evolution v2):

```bash
curl -X POST http://IP-DO-SERVICO:8090/webhook/set/NOME_DA_INSTANCIA \
  -H "apikey: SUA_API_KEY" -H "Content-Type: application/json" \
  -d '{"webhook":{"enabled":true,"url":"https://seu-dominio.com/chat_webhook.php","byEvents":false,"base64":true,
       "events":["MESSAGES_UPSERT","SEND_MESSAGE","MESSAGES_DELETE","CONTACTS_UPSERT","CONNECTION_UPDATE","QRCODE_UPDATED"]}}'
```

Instância sem webhook próprio usa o `DEFAULT_WEBHOOK_URL` do `.env` do serviço.

## 3. Testar

Envie uma mensagem para o número conectado e confira:

1. `/admin_webhook_debug.php` → "Mensagens recebidas pelo webhook (rastreio)";
2. tabela `chat_messages`;
3. o chat do sistema.

## 4. Se não chegar

- URL pública acessível (SSL, firewall) e `post_max_size` ≥ 64M (`.user.ini`);
- veredito do número em Configurações → WhatsApp Conexão → Instâncias;
- logs do serviço na VPS: `pm2 logs ml-baileys` (linhas "webhook não entregue");
- mensagens que chegaram ao serviço e não ao sistema: botão **Sincronizar**.

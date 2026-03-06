# Configuração do Webhook para Receber Mensagens

## 1. URL do Webhook

Configure o webhook na Evolution API para apontar para:

```
https://seu-dominio.com/chat_webhook.php
```

## 2. Configurar na Evolution API

### Via API:

```bash
curl -X POST https://sua-evolution-api.com/webhook/set/NOME_DA_INSTANCIA \
  -H "apikey: SUA_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "url": "https://seu-dominio.com/chat_webhook.php",
    "webhook_by_events": true,
    "events": [
      "messages.upsert"
    ]
  }'
```

### Via Interface da Evolution API:

1. Acesse o painel da Evolution API
2. Vá em **Configurações da Instância**
3. Seção **Webhook**
4. Configure:
   - **URL**: `https://seu-dominio.com/chat_webhook.php`
   - **Events**: Marque `messages.upsert`
   - **Webhook by Events**: Ativado

## 3. Testar Webhook

Envie uma mensagem para o número do WhatsApp conectado e verifique:

1. Logs do servidor (erro.log do Apache/Nginx)
2. Banco de dados - tabela `chat_messages`
3. Interface do chat - mensagem deve aparecer

## 4. Verificar Logs

```bash
tail -f /var/log/apache2/error.log
# ou
tail -f /var/log/nginx/error.log
```

Procure por:
- `=== WEBHOOK CHAMADO ===`
- `Mensagem recebida de:`
- `Mensagem salva no banco com sucesso`

## 5. Troubleshooting

**Webhook não é chamado:**
- Verifique se URL está acessível publicamente
- Verifique firewall/SSL
- Confirme configuração na Evolution API

**Mensagens não aparecem:**
- Verifique logs do webhook
- Confirme que tabela `chat_messages` existe
- Verifique permissões do banco de dados

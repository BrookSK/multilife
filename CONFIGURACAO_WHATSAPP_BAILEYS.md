# Configuração do WhatsApp (serviço Baileys)

O WhatsApp do Multilife passa pelo serviço próprio **ml-baileys-service**
(Node + Baileys), pasta `ml-baileys-service/` deste repositório. Ele substitui a
Evolution API e fala o mesmo contrato de API e de webhook, então o resto do
sistema não mudou de comportamento.

**Passo a passo para colocar no ar sem rodar comando:**
`CONFIGURAR_WHATSAPP_NA_HOSPEDAGEM.md`. Detalhes técnicos do serviço:
`ml-baileys-service/README.md`.

## 1. No sistema (Configurações → WhatsApp Conexão → Credenciais)

| Campo | Valor |
|---|---|
| Endereço do serviço | Vazio = automático (`https://whatsapp.<endereço do sistema>`, conferido sozinho em https e http). Preencha só se o serviço estiver em outro lugar (ex.: VPS). |
| Token (API Key) | Gerado sozinho quando o serviço roda na mesma hospedagem. Numa VPS: o `API_KEY` do `.env` dela. |
| Nome da Instância | WhatsApp padrão das mensagens automáticas (avisos, aprovações, cron) |
| Guardar o histórico das mensagens por (dias) | `0` = para sempre (padrão) |

O monitor de conexão (veredito "Suspeita de queda", reconexão sem QR e o botão
"Sincronizar") usa a mesma URL e chave. Não há outra configuração.

Chaves em `admin_settings`: `baileys.base_url`, `baileys.api_key`,
`baileys.instance`, `baileys.message_retention_days`, `baileys.watchdog_auto_heal`,
`baileys.admin_phone`, `baileys.webhook_token`; geradas pelo sistema:
`baileys.detected_url`, `baileys.keepalive_at`. Nenhuma migration é obrigatória
(`migrations/2026-11-24_0001_whatsapp_baileys.sql` só copia os valores antigos).

## 2. Conectar cada número

1. Configurações → WhatsApp Conexão → Instâncias → **Reconectar** (ou "Meu WhatsApp").
2. Ler o QR Code com o celular do número, ou usar o **código de pareamento**
   (Webhook Debug → "Reconectar WhatsApp travado", informando o número).
3. Ao conectar, o sistema grava o webhook da instância (`/chat_webhook.php`, base64
   ligado). Conferir em `/admin_webhook_debug.php`.

A sessão da Evolution não é aproveitada: cada número precisa ser pareado de novo
uma vez. Antes, no celular, apague o aparelho antigo em "Aparelhos conectados".

## 3. Conferências

| Onde | O que ver |
|---|---|
| `/admin_webhook_debug.php` | "Serviço WhatsApp: ml-baileys-service …", webhook de cada número, rastreio do que chegou |
| Configurações → WhatsApp Conexão → Instâncias | Veredito do monitor por número |
| `/admin_whatsapp_monitor.php` | Gráfico de 72 h: minutos sem receber mensagens por número |
| Logs Técnicos (`provider=whatsapp`) | Chamadas do sistema ao serviço |

## 4. Problemas comuns

| Sintoma | Causa provável |
|---|---|
| "Serviço WhatsApp (Baileys) não configurado" | Endereço do sistema (`app.public_base_url`) vazio, ou instância padrão vazia |
| HTTP 401 | Serviço numa VPS com `API_KEY` diferente da chave em Credenciais |
| Sem resposta / timeout | Veja a tabela "Se algo não funcionar" em `CONFIGURAR_WHATSAPP_NA_HOSPEDAGEM.md` |
| "Connection Closed" ao enviar | O número não está conectado: Reconectar e ler o QR Code |
| 400 `exists:false` | O número de destino não tem WhatsApp |
| Mensagens não chegam no chat | Webhook da instância (Webhook Debug → "Aplicar padrão") e o `app.public_base_url` |

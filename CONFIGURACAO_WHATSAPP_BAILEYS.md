# Configuração do WhatsApp (serviço Baileys)

O WhatsApp do Multilife passa pelo serviço próprio **ml-baileys-service**
(Node + Baileys), pasta `ml-baileys-service/` deste repositório. Ele substitui a
Evolution API e fala o mesmo contrato de API e de webhook, então o resto do
sistema não mudou de comportamento.

Instalação e operação do serviço: `ml-baileys-service/README.md`.

## 1. No sistema (Configurações → WhatsApp Conexão → Credenciais)

| Campo | Valor |
|---|---|
| Base URL | URL do serviço, sem barra no final (ex.: `http://177.104.185.7:8090`) |
| Token (API Key) | `API_KEY` do `.env` do serviço |
| Nome da Instância | WhatsApp padrão das mensagens automáticas (avisos, aprovações, cron) |

O monitor de conexão (veredito "Suspeita de queda", reconexão sem QR e o botão
"Sincronizar") usa a mesma URL e chave. Não há outra configuração.

Chaves em `admin_settings`: `baileys.base_url`, `baileys.api_key`,
`baileys.instance`, `baileys.watchdog_auto_heal`, `baileys.admin_phone`,
`baileys.webhook_token`. Migration opcional:
`migrations/2026-11-24_0001_whatsapp_baileys.sql` (copia os valores antigos).

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
| "Serviço WhatsApp (Baileys) não configurado" | URL ou API Key vazias em Credenciais |
| HTTP 401 | API Key diferente da `API_KEY` do `.env` do serviço |
| Sem resposta / timeout | Serviço parado (`pm2 list`) ou porta bloqueada para o IP do site |
| "Connection Closed" ao enviar | O número não está conectado: Reconectar e ler o QR Code |
| 400 `exists:false` | O número de destino não tem WhatsApp |
| Mensagens não chegam no chat | Webhook da instância (Webhook Debug → "Aplicar padrão") e o `app.public_base_url` |

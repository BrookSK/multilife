# ml-baileys-service — WhatsApp do Multilife (Node + Baileys)

Conector WhatsApp enxuto que substitui a Evolution API no Multilife. Faz só o
que o sistema precisa: conexão, sessão, envio, eventos (webhook), QR Code /
código de pareamento e o monitor de conexão real. Todo o negócio continua no PHP.

Base técnica: documento `multilife-baileys-integracao.md` (modelo do cx-crm,
Baileys `7.0.0-rc.9`).

## Como o PHP conversa com ele

O contrato é o mesmo da Evolution v2. O PHP só troca a URL base:

| O que | Como |
|---|---|
| Autenticação | header `apikey: <API_KEY>` (o monitor também aceita `x-token`) |
| Envio | `POST /message/sendText/{instância}` `{ number, text }` (e `textMessage.text`) — devolve `key.id` |
| Mídia | `POST /message/sendMedia/{instância}` `{ number, mediatype, media (URL ou base64), fileName, caption }` |
| Áudio (nota de voz) | `POST /message/sendWhatsAppAudio/{instância}` (converte para ogg/opus com ffmpeg) |
| Grupos | `/group/create`, `/group/fetchAllGroups`, `/group/findGroupInfos`, `/group/participants`, `/group/updateParticipant`, `/group/updateSetting`, convites etc. |
| Conversas | `/chat/findMessages`, `/chat/findChats`, `/chat/findContacts`, `/chat/getBase64FromMediaMessage`, `/chat/fetchProfile`, `/chat/whatsappNumbers`, `/chat/markMessageAsRead` |
| Instâncias | `/instance/create`, `/instance/connect` (QR + `?number=` = código de pareamento), `/instance/connectionState`, `/instance/restart`, `/instance/logout`, `/instance/delete`, `/instance/fetchInstances` |
| Webhook | `POST /webhook/set/{instância}`, `GET /webhook/find/{instância}` |
| Monitor | `GET /status`, `GET /status/{instância}`, `GET /status/{instância}/history?hours=72`, `POST /heal/{instância}`, `POST /redispatch/{instância}` |

Eventos enviados ao webhook (mesmo formato da Evolution: `{event, instance, data, ...}`):
`messages.upsert`, `send.message`, `messages.update`, `messages.delete`,
`contacts.upsert/update`, `connection.update`, `qrcode.updated`,
`groups.*`, `presence.update`, `call`. A instância escolhe quais recebe
(lista `events` do `/webhook/set`). Com `base64: true`, a mídia vai em
`data.message.base64`.

## Decisões (lições da Evolution)

- **Versão do WhatsApp Web pinada** no boot (`fetchLatestBaileysVersion`); se a
  busca falhar, sobe com a versão padrão do Baileys.
- **Sessão = um JSON único por número** (`baileys_session`), gravado inteiro.
  Sem gravação parcial por chave, sem Redis, sem cache externo. `creds` grava na
  hora; mudanças de chaves são agrupadas em no máximo 1 gravação por segundo.
- **Webhook desacoplado**: o evento entra numa fila em memória e é entregue em
  paralelo limitado (timeout 10 s, 5 tentativas com espera crescente). O
  processamento do WhatsApp nunca espera o webhook.
- **Sem logout automático em silêncio**: o monitor detecta e avisa; o `heal`
  só reinicia o socket (sem QR) e só quando a instância está `silent`.
- Mensagens guardadas por 15 dias (`baileys_messages`) para `findMessages`,
  download de mídia, reenvio de mensagens que o WhatsApp pede de novo e o
  "Sincronizar" do painel.
- Número brasileiro: o envio confirma no WhatsApp (`onWhatsApp`) o JID real,
  com e sem o 9. Número sem WhatsApp = HTTP 400 `{exists:false}` (igual à Evolution).

## Instalação na VPS

Requisitos: Node 20+, MySQL/MariaDB, pm2, ffmpeg (recomendado).

```bash
cd /desenvolvimento/wwwroot
git clone https://github.com/BrookSK/multilife.git ml-baileys-src   # ou copie só a pasta ml-baileys-service
cd ml-baileys-src/ml-baileys-service
npm ci --omit=dev
cp .env.example .env    # preencha API_KEY, DB_*, PUBLIC_URL

# banco (exemplo)
mysql -e "CREATE DATABASE ml_baileys CHARACTER SET utf8mb4; CREATE USER 'ml_baileys'@'localhost' IDENTIFIED BY 'senha-forte'; GRANT ALL ON ml_baileys.* TO 'ml_baileys'@'localhost';"
# max_allowed_packet >= 64M no my.cnf

pm2 install pm2-logrotate
pm2 set pm2-logrotate:max_size 50M
pm2 set pm2-logrotate:retain 7
pm2 start ecosystem.config.cjs
pm2 save
```

Libere a porta (ex.: 8090) **só para o IP do servidor do Multilife** no firewall.

Teste: `curl -H "apikey: $API_KEY" http://127.0.0.1:8090/` deve responder
`clientName: ml-baileys-service`.

## Ligar no Multilife

1. Configurações → WhatsApp Conexão → Credenciais: **Base URL** do serviço,
   **Token (API Key)** = `API_KEY` do `.env`, **Instância** padrão.
2. Em cada WhatsApp: **Reconectar** → ler o QR Code (ou digitar o código de
   pareamento). A sessão da Evolution não serve no Baileys: todo número precisa
   ser pareado de novo uma vez. O sistema já grava o webhook da instância ao conectar.
3. Webhook Debug (`/admin_webhook_debug.php`): conferir "Serviço WhatsApp:
   ml-baileys-service" e o webhook de cada número.
4. Monitor (`/admin_whatsapp_monitor.php`): gráfico de 72 h de minutos sem
   receber mensagens por número.

## Piloto (recomendado pela doc antes de migrar todos)

Conecte primeiro só o número de testes e acompanhe 72 h no Monitor.
Critério: entrada (mensagens 1:1, grupos e reações) sem interrupção maior que
2 h. Se o silêncio de entrada crescer sem parar com o socket aberto, o problema
é o número/padrão de uso (H2) e não a stack.

## Operação

| Item | Comando |
|---|---|
| Processo | `pm2 list` → `ml-baileys` |
| Logs | `pm2 logs ml-baileys` (ou `logs/` na pasta) |
| Reiniciar (as sessões continuam válidas) | `pm2 restart ml-baileys` |
| Parar | `pm2 stop ml-baileys` |

Variáveis em `.env.example`. Um processo atende todos os números. Não rode
dois processos com o mesmo banco: o mesmo número conectado duas vezes derruba
a sessão (erro 440).

# ml-baileys-service — WhatsApp do Multilife (Node + Baileys)

Conector WhatsApp enxuto que substitui a Evolution API no Multilife. Faz só o
que o sistema precisa: conexão, sessão, envio, eventos (webhook), QR Code /
código de pareamento e o monitor de conexão real. Todo o negócio continua no PHP.

Base técnica: documento `multilife-baileys-integracao.md` (modelo do cx-crm,
Baileys `7.0.0-rc.9`).

## Duas formas de rodar

| Onde | Como | Configuração |
|---|---|---|
| **Na hospedagem do sistema** (painel Node.js do Plesk/cPanel) — recomendado | Guia `CONFIGURAR_WHATSAPP_NA_HOSPEDAGEM.md` na raiz do sistema. Só cliques no painel. | Automática: o PHP grava `runtime-config.php` |
| Numa VPS (pm2) | Seção "Instalação na VPS" abaixo | `.env` |

Nos dois casos **não precisa de `npm install`**: o serviço inteiro (com o
Baileys e as dependências) está em `dist/server.cjs`, versionado no git.
Arquivo de início: `app.js`.

## Como o PHP conversa com ele

O contrato é o mesmo da Evolution v2. O PHP só troca a URL base:

| O que | Como |
|---|---|
| Autenticação | header `apikey: <chave>` (o monitor também aceita `x-token`) |
| Envio | `POST /message/sendText/{instância}` `{ number, text }` (e `textMessage.text`) — devolve `key.id` |
| Mídia | `POST /message/sendMedia/{instância}` `{ number, mediatype, media (URL ou base64), fileName, caption }` |
| Áudio (nota de voz) | `POST /message/sendWhatsAppAudio/{instância}`: Ogg vai como está; WebM do navegador vira Ogg sem ffmpeg; outros formatos usam ffmpeg se houver, senão vão como áudio comum |
| Grupos | `/group/create`, `/group/fetchAllGroups`, `/group/findGroupInfos`, `/group/participants`, `/group/updateParticipant`, `/group/updateSetting`, convites etc. |
| Conversas | `/chat/findMessages`, `/chat/findChats`, `/chat/findContacts`, `/chat/getBase64FromMediaMessage`, `/chat/fetchProfile`, `/chat/whatsappNumbers`, `/chat/markMessageAsRead` |
| Instâncias | `/instance/create`, `/instance/connect` (QR + `?number=` = código de pareamento), `/instance/connectionState`, `/instance/restart`, `/instance/logout`, `/instance/delete`, `/instance/fetchInstances` |
| Webhook | `POST /webhook/set/{instância}`, `GET /webhook/find/{instância}` |
| Monitor | `GET /status`, `GET /status/{instância}`, `GET /status/{instância}/history?hours=72`, `POST /heal/{instância}`, `POST /redispatch/{instância}` |
| Situação | `GET /health` (sem chave), `GET /service/logs?lines=150` (últimas linhas do log) |

Eventos enviados ao webhook (mesmo formato da Evolution: `{event, instance, data, ...}`):
`messages.upsert`, `send.message`, `messages.update`, `messages.delete`,
`contacts.upsert/update`, `connection.update`, `qrcode.updated`,
`groups.*`, `presence.update`, `call`. A instância escolhe quais recebe
(lista `events` do `/webhook/set`). Com `base64: true`, a mídia vai em
`data.message.base64`.

## Configuração

Ordem: variáveis de ambiente / `.env` → `runtime-config.php` → padrão.

`runtime-config.php` é escrito pelo PHP (`wa_service_runtime_sync()` em
`app/whatsapp_api.php`) com: banco do sistema, chave da API (gerada pelo PHP),
URL pública do serviço, URL do webhook e prazo do histórico. A primeira linha é
um PHP que sai na hora (aberto pelo navegador, não mostra nada). O serviço relê o
arquivo a cada 30 s; se os dados do banco mudarem, reinicia sozinho.

**Histórico das mensagens:** guardado **para sempre** por padrão
(`message_retention_days = 0`). Prazo configurável no sistema em Configurações →
WhatsApp Conexão → Credenciais (ou `MESSAGE_RETENTION_DAYS` no `.env`).

**Log detalhado (`baileys_event_log`):** `src/events.js`. `ev(level, category,
event, {instance, jid, messageId, status, http, ms, summary, error, details})`
coloca a linha num buffer em memória. O buffer é gravado em lote a cada 2 s ou
200 linhas e guarda até 10.000 linhas se o banco cair (o descarte também é
registrado). O PHP grava na mesma tabela com `source = 'sistema'`
(`app/whatsapp_event_log.php`). A tela é `/admin_whatsapp_eventos.php`.
Prazo: `baileys.event_log_retention_days` (padrão 90, 0 = para sempre) ou
`EVENT_LOG_RETENTION_DAYS`. Nível debug: `baileys.event_log_debug` ou
`EVENT_LOG_DEBUG=0` para desligar.

O que é registrado:
- conexão, QR Code, pareamento, quedas com o código e o motivo, reconexões e reinícios;
- mensagens recebidas, enviadas pelo sistema e pelo celular, apagadas e editadas;
- reações, falhas de descriptografia e avisos do WhatsApp;
- mídia baixada ou falha, grupos e participantes, ligações;
- cada entrega ao webhook (ok, após tentativas, falha com HTTP e início da resposta,
  fila cheia, instância sem webhook);
- envios sem confirmação do servidor do WhatsApp em 2 min, número sem WhatsApp,
  troca do nono dígito;
- cada chamada da API (rota, HTTP, tempo, erro; as consultas do monitor só entram
  quando dão erro), chave inválida;
- situação de cada número a cada 5 min, memória do processo;
- avisos e erros internos do Baileys e erros não tratados do processo.

## Comportamento para rodar sem ninguém por perto

- O HTTP abre na hora; até ficar pronto, as rotas respondem 503 "iniciando"
  (o PHP espera e tenta de novo uma vez). `/health` responde sempre.
- **Um processo por banco**: trava `GET_LOCK` no MySQL. Só o processo dono da
  trava conecta os números; um segundo processo fica esperando (dois conectados
  com o mesmo número derrubam a sessão, erro 440).
- **Versão nova** (`dist/server.cjs` mudou por git pull): o processo encerra em
  até 1 min; o painel/pm2 sobe a nova na próxima requisição.
- **Auto-ping** na própria URL pública a cada minuto e acesso do PHP (cron da fila
  e telas abertas) a cada minuto: o painel não desliga o app parado e, depois de
  um reinício do servidor, o primeiro acesso liga o serviço, que reconecta os
  números com a sessão salva.

## Decisões (lições da Evolution)

- **Versão do WhatsApp Web pinada** no boot (`fetchLatestBaileysVersion`); se a
  busca falhar, sobe com a versão padrão do Baileys.
- **Sessão = um JSON único por número** (`baileys_session`), gravado inteiro.
  Sem gravação parcial por chave, sem Redis, sem cache externo.
- **Webhook desacoplado**: fila em memória, entrega em paralelo limitado
  (timeout 10 s, 5 tentativas com espera crescente). O WhatsApp nunca espera o webhook.
- **Sem logout automático em silêncio**: o monitor detecta e avisa; o `heal`
  só reinicia o socket (sem QR) e só quando a instância está `silent`.
- Número brasileiro: o envio confirma no WhatsApp (`onWhatsApp`) o JID real,
  com e sem o 9. Número sem WhatsApp = HTTP 400 `{exists:false}` (igual à Evolution).

## Desenvolvimento

```bash
npm install        # só na máquina de desenvolvimento
npm run dev        # roda src/ direto (precisa de .env)
npm run build      # gera dist/server.cjs — rodar e commitar depois de mudar src/
```

## Instalação na VPS (alternativa)

Requisitos: Node 20+, MySQL/MariaDB, pm2. ffmpeg é opcional.

```bash
git clone https://github.com/BrookSK/multilife.git && cd multilife/ml-baileys-service
cp .env.example .env    # preencha API_KEY, DB_*, PUBLIC_URL, DEFAULT_WEBHOOK_URL
pm2 install pm2-logrotate && pm2 set pm2-logrotate:max_size 50M && pm2 set pm2-logrotate:retain 7
pm2 start ecosystem.config.cjs && pm2 save
```

Libere a porta (ex.: 8090) só para o IP do servidor do Multilife. No sistema,
em Credenciais, preencha **Endereço do serviço** e **Token (API Key)**.

## Piloto (recomendado pela doc antes de migrar todos)

Conecte primeiro só o número de testes e acompanhe 72 h em
`/admin_whatsapp_monitor.php`. Critério: entrada (mensagens 1:1, grupos e
reações) sem interrupção maior que 2 h. Se o silêncio de entrada crescer sem
parar com o socket aberto, o problema é o número/padrão de uso (H2) e não a stack.

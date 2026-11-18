# Watchdog de Conexão Real — Evolution API v2 × Multilife

**Data:** 18/11/2026 · **Servidor:** Eveo (177.104.185.7) · **Processo pm2:** `evo-watchdog` · **Porta:** 8083

---

## 1. O problema que resolve

A Evolution API mostra a instância como **conectada (`open`)** lendo o estado em memória do processo. Quando o socket do WhatsApp morre pela metade (**zumbi**), a Evolution continua exibindo `open`, o CRM continua enviando (que aparentemente funciona), mas **nenhuma mensagem/reação/entrada chega** — o caso "reagi no grupo e não chegou no sistema".

O watchdog observa o **fluxo real de eventos** de cada instância e expõe um veredito confiável + uma ação de recuperação (`heal`) que o próprio sistema do multilife pode chamar, sem depender de ninguém acessando a VPS.

## 2. Como funciona

```
WhatsApp ──► Evolution v2 (:8082) ──► Socket.IO global (todos os eventos)
                                          │
                                          ▼ (escuta como cliente)
                                   evo-watchdog (:8083)
                                   • registra último evento por instância
                                   • poll de estado a cada 60s (open/close/connecting)
                                   • veredito por instância
                                          ▲
                                          │ GET /status/{nome} · POST /heal/{nome}
Multilife CRM (PHP) ──────────────────────┘
```

- Uma instância **saudável** gera eventos constantemente (acks, presence, upserts).
- Instância `open` **sem eventos há mais de 30 min** = zumbi suspeito (`silent`).
- O **heal** chama `POST /instance/restart/{nome}` da Evolution, que fecha o socket
  morto e recria a conexão usando a sessão salva — **sem escanear QR de novo**
  (desde que a sessão esteja válida).

## 3. Endpoints

Base: `http://177.104.185.7:8083` · Autenticação: header `x-token: <TOKEN>`
(também aceita `?token=` ou `Authorization: Bearer`).

> **TOKEN**: está no arquivo `/desenvolvimento/wwwroot/evo-watchdog/.token` na Eveo
> (`cat` como root). Compartilhar apenas com o time do multilife, fora de repositório.

### `GET /status` — todas as instâncias

```bash
curl -H "x-token: $TOKEN" http://177.104.185.7:8083/status
```

```json
{
  "generatedAt": "2026-11-18T12:48:44.252Z",
  "thresholds": { "silentSec": 1800 },
  "summary": { "healthy": 13, "dormant": 16, "awaiting_scan": 4 },
  "instances": [
    { "name": "ml-tania", "verdict": "healthy", "state": "open",
      "lastEventSecondsAgo": 39, "lastHealSecondsAgo": null }
  ]
}
```

### `GET /status/{instancia}` — uma instância

```bash
curl -H "x-token: $TOKEN" http://177.104.185.7:8083/status/ml-grace
```

### `POST /heal/{instancia}` — tentar reconexão

```bash
curl -X POST -H "x-token: $TOKEN" http://177.104.185.7:8083/heal/ml-grace
```

Respostas possíveis:
- Instância `silent` (zumbi): executa restart e devolve `{ ok: true, action: "restart", ... }`
- Instância saudável: **recusa** (`ok: false`, `"instância saudável — heal não aplicado"`) — não faz nada desnecessário
- `close` (dormente): recusa e informa que exige QR via manager
- `connecting`: recusa — aguardando escaneamento humano

## 4. Vereditos

| Veredito | Significado | Cor sugerida no painel |
|---|---|---|
| `healthy` | `open` + evento recente (≤30 min) | 🟢 Conectado (real) |
| `silent` | `open` mas **sem eventos >30 min** — zumbi suspeito | 🟠 Suspeita de queda → botão **Reconectar** |
| `warming_up` | `open`, watchdog acabou de subir (≤5 min sem dados) | ⚪ Monitorando |
| `awaiting_scan` | `connecting` — QR pendente de escaneamento | 🟡 Aguardando QR |
| `dormant` | `close` — desativada | ⚪ Desativada |
| `unknown` | não encontrada / Evolution fora do ar | 🔴 Sem dados |

O campo `lastEventSecondsAgo` (segundos desde o último evento) permite ao multilife
usar um critério próprio se quiser (ex.: alertar acima de 10 min em horário comercial).

## 5. Implementação no Multilife (PHP)

### 5.1 Funções prontas

```php
<?php
// Config: colocar o token em variável de ambiente, nunca no git
define('EVO_WATCHDOG_URL', 'http://177.104.185.7:8083');
define('EVO_WATCHDOG_TOKEN', getenv('EVO_WATCHDOG_TOKEN'));

function watchdog_status(string $instancia): ?object {
    $url = EVO_WATCHDOG_URL . '/status/' . urlencode($instancia);
    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'header'  => "x-token: " . EVO_WATCHDOG_TOKEN . "\r\n",
        'timeout' => 8,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    return $body ? json_decode($body) : null;
}

function watchdog_heal(string $instancia): ?object {
    $url = EVO_WATCHDOG_URL . '/heal/' . urlencode($instancia);
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "x-token: " . EVO_WATCHDOG_TOKEN . "\r\n",
        'timeout' => 30,
        'ignore_errors' => true, // lê corpo mesmo em 409
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    return $body ? json_decode($body) : null;
}
```

### 5.2 Badge de status (por instância conectada no painel)

```php
function badge_watchdog(string $instancia): string {
    $s = watchdog_status($instancia);
    if (!$s) return '<span class="badge cinza">Sem dados</span>';

    switch ($s->verdict) {
        case 'healthy':
            return '<span class="badge verde">Conectado</span>';
        case 'silent':
            return '<span class="badge laranja">Suspeita de queda '
                 . '<button onclick="reconectar(\'' . $instancia . '\')">Reconectar</button></span>';
        case 'awaiting_scan':
            return '<span class="badge amarelo">Aguardando QR</span>';
        case 'dormant':
            return '<span class="badge cinza">Desativada</span>';
        default:
            return '<span class="badge cinza">Monitorando</span>';
    }
}
```

### 5.3 Ação "Reconectar" (endpoint interno do painel)

```php
// POST /painel/whatsapp/reconectar { instancia: "ml-grace" }
$resp = watchdog_heal($_POST['instancia']);
if ($resp && $resp->ok) {
    echo json_encode(['ok' => true, 'msg' => 'Reconexão disparada. Reavalie em 2-5 min.']);
} else {
    $motivo = $resp->reason ?? ($resp->error ?? 'falha desconhecida');
    // Se o motivo for QR/dormente → alertar humano para escanear
    echo json_encode(['ok' => false, 'msg' => $motivo]);
}
```

### 5.4 Fluxo operacional recomendado

1. **Polling**: a cada **1–2 min** (cron do servidor ou job agendado) para as
   instâncias que o multilife usa (`ml-grace`, `ml-mania`, `ml-ana-carla`,
   `ml-maju`, `ml-tania`, ...). Com cache de 60s evita chamada a cada page load.
2. Ao detectar `silent` → **1 heal automático** (cooldown de 5 min entre heals
   da mesma instância — o campo `lastHealSecondsAgo` ajuda a respeitar).
3. Reavaliar após 5 min: se continuar `silent` → **alerta humano**
   (e-mail/WhatsApp interno): "conexão do número X quebrada, heal não resolveu —
   provável necessidade de escanear QR no manager".
4. `awaiting_scan` → avisar o dono do número para escanear
   (`http://177.104.185.7:8082/manager` → instância → Conectar).

### 5.5 Cuidados

- **Timeout do PHP**: usar ≤8s no status e ≤35s no heal.
- **Não expor o token** no frontend — as chamadas de heal devem passar pelo
  backend PHP do multilife.
- Se a Evolution inteira estiver fora (`unknown` em tudo), o problema é outro —
  avisar a equipe de infra.

## 6. Operação e manutenção (infra/Eveo)

| Item | Comando |
|---|---|
| Ver processo | `pm2 list` → `evo-watchdog` |
| Logs | `pm2 logs evo-watchdog` |
| Reiniciar | `pm2 restart evo-watchdog` |
| Desligar (rollback) | `pm2 stop evo-watchdog` — não afeta a Evolution |
| Código | `/desenvolvimento/wwwroot/evo-watchdog/watchdog.js` |
| Token | `/desenvolvimento/wwwroot/evo-watchdog/.token` |
| Ajustar limiar de silêncio | `SILENT_THRESHOLD_SEC` no pm2 (padrão 1800s) |

**Dependências**: nenhuma instalada — usa o `socket.io-client` já existente na
Evolution e o `fetch` nativo do Node 20. Consome ~75MB de RAM.

## 7. Limitações conhecidas

1. **Falso `silent` em números parados**: um número que não recebe NADA (nem
   presence/ack) por >30 min pode ser classificado `silent` mesmo são. Na
   prática, números ativos em atendimento geram eventos o tempo todo. Se
   incomodar, subir `SILENT_THRESHOLD_SEC` ou o multilife usar o
   `lastEventSecondsAgo` com critério próprio.
2. **Heal não resolve sessão invalidada**: se o WhatsApp deslogou o dispositivo
   (`device_removed`/401), o heal não cola — o caminho é QR. O watchdog
   identifica isso como `awaiting_scan` após o restart.
3. **Zumbi + heal falho**: casos raros de socket morto que não responde nem ao
   restart interno exigem reinício do processo Evolution (infra) — o alerta
   humano do fluxo 5.4 cobre essa escalada.

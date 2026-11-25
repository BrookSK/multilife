// Entrega dos eventos ao webhook do Multilife (contrato igual ao da Evolution v2).
//
// Regra principal (lição da Evolution): a entrega NUNCA bloqueia o processamento
// dos eventos do WhatsApp. Cada evento entra numa fila em memória por instância;
// os envios saem em paralelo limitado, com timeout de 10 s e até 5 tentativas
// com espera crescente. Fila cheia descarta os mais antigos (as mensagens ficam
// guardadas no banco e podem ser reenviadas pelo "Sincronizar").
import http from 'node:http';
import https from 'node:https';
import net from 'node:net';
import { config } from './config.js';
import { logger } from './logger.js';
import { ev } from './events.js';
import { nowIso, sleep } from './util.js';

// Erros de rede em que vale tentar o endereço interno do servidor
const ROUTE_ERRORS = new Set(['ECONNREFUSED', 'EHOSTUNREACH', 'ENETUNREACH', 'ETIMEDOUT', 'ENOTFOUND', 'EAI_AGAIN', 'ECONNRESET', 'TIMEOUT']);
const ROUTE_RECHECK_MS = 10 * 60 * 1000;
/** Rota que funcionou por domínio: host -> {ip, at}. ip null = DNS normal. */
const routes = new Map();

/**
 * POST JSON. Com `ip`, conecta nesse IP mas mantém o domínio da URL (SNI,
 * certificado e cabeçalho Host): é o mesmo site, só que pelo caminho interno.
 * @returns {Promise<{status:number, bodyStart:string}>}
 */
function postJson(url, body, headers, timeoutMs, ip = null) {
  return new Promise((resolve, reject) => {
    const u = new URL(url);
    const lib = u.protocol === 'https:' ? https : http;
    const family = ip && net.isIPv6(ip) ? 6 : 4;
    const req = lib.request(u, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body), ...(headers ?? {}) },
      agent: false,
      ...(ip ? {
        lookup: (_host, opts, cb) => (opts && opts.all ? cb(null, [{ address: ip, family }]) : cb(null, ip, family)),
        servername: u.hostname,
      } : {}),
    }, (res) => {
      const chunks = [];
      let size = 0;
      res.on('data', (c) => {
        if (size < 2000) chunks.push(c);
        size += c.length;
      });
      res.on('end', () => resolve({ status: res.statusCode ?? 0, bodyStart: Buffer.concat(chunks).toString('utf8', 0, 500) }));
      res.on('error', reject);
    });
    req.setTimeout(timeoutMs, () => {
      const e = new Error('timeout ' + timeoutMs + ' ms');
      e.code = 'TIMEOUT';
      req.destroy(e);
    });
    req.on('error', reject);
    req.end(body);
  });
}

const errText = (e) => (e.code === 'TIMEOUT' ? e.message : `${e.message}${e.code && !String(e.message).includes(e.code) ? ' (' + e.code + ')' : ''}`);

/**
 * Entrega uma vez, escolhendo o caminho: o que funcionou da última vez; se o
 * endereço público recusar, tenta os endereços internos e lembra o que deu certo.
 * @returns {Promise<{res?:{status:number, bodyStart:string}, error?:Error, via:string|null}>}
 */
async function postWithRoute(url, body, headers, timeoutMs, instanceName) {
  const host = new URL(url).hostname;
  const known = routes.get(host);
  const tryLocalFirst = known && known.ip && Date.now() - known.at < ROUTE_RECHECK_MS;
  const order = tryLocalFirst
    ? [known.ip, null, ...config.webhook.localIps.filter((ip) => ip !== known.ip)]
    : [null, ...config.webhook.localIps];
  if (net.isIP(host)) order.splice(1); // URL já é um IP: sem caminho alternativo
  let lastErr = null;
  const tried = [];
  for (let i = 0; i < order.length; i++) {
    const ip = order[i];
    try {
      const res = await postJson(url, body, headers, ip ? Math.min(timeoutMs, 8000) : timeoutMs, ip);
      const before = routes.get(host)?.ip ?? null;
      routes.set(host, { ip, at: Date.now() });
      if (before !== ip) {
        ev(ip ? 'warn' : 'info', 'webhook', ip ? 'rota_interna' : 'rota_publica_voltou', {
          instance: instanceName,
          http: res.status,
          summary: ip
            ? `O endereço público do sistema (${host}) recusou a conexão; as entregas passaram a ir pelo endereço interno do servidor (${ip}), com o mesmo domínio.`
            : `O endereço público do sistema (${host}) voltou a responder: entregas por ele de novo.`,
          details: { host, ip, erroAntes: lastErr ? errText(lastErr) : null },
        });
      }
      return { res, via: ip };
    } catch (e) {
      lastErr = e;
      tried.push((ip ? 'interno ' + ip : 'público') + ': ' + errText(e));
      // Só erro de rede justifica trocar de caminho (erro HTTP é resposta do site)
      if (!ROUTE_ERRORS.has(e.code)) break;
    }
  }
  if (lastErr && tried.length > 1) {
    const combined = new Error(tried.join(' | '));
    combined.code = lastErr.code;
    return { error: combined, via: null };
  }
  return { error: lastErr, via: null };
}

/** Eventos internos -> nome da Evolution usado na lista "events" do webhook. */
export const EVENT_NAMES = {
  'application.startup': 'APPLICATION_STARTUP',
  'qrcode.updated': 'QRCODE_UPDATED',
  'connection.update': 'CONNECTION_UPDATE',
  'messages.set': 'MESSAGES_SET',
  'messages.upsert': 'MESSAGES_UPSERT',
  'messages.update': 'MESSAGES_UPDATE',
  'messages.delete': 'MESSAGES_DELETE',
  'send.message': 'SEND_MESSAGE',
  'contacts.upsert': 'CONTACTS_UPSERT',
  'contacts.update': 'CONTACTS_UPDATE',
  'presence.update': 'PRESENCE_UPDATE',
  'chats.upsert': 'CHATS_UPSERT',
  'chats.update': 'CHATS_UPDATE',
  'chats.delete': 'CHATS_DELETE',
  'groups.upsert': 'GROUPS_UPSERT',
  'groups.update': 'GROUP_UPDATE',
  'group-participants.update': 'GROUP_PARTICIPANTS_UPDATE',
  call: 'CALL',
};

/** Eventos padrão quando a instância não escolheu (os que o Multilife usa). */
export const DEFAULT_EVENTS = ['MESSAGES_UPSERT', 'SEND_MESSAGE', 'MESSAGES_DELETE', 'CONTACTS_UPSERT', 'CONNECTION_UPDATE', 'QRCODE_UPDATED'];

/** Normaliza o corpo do POST /webhook/set (formatos v2.3, v2.1 e v1). */
export function normalizeWebhookConfig(body, current = null) {
  const src = body && typeof body.webhook === 'object' && body.webhook !== null ? body.webhook : (body ?? {});
  const pick = (...keys) => {
    for (const k of keys) if (src[k] !== undefined) return src[k];
    return undefined;
  };
  const events = pick('events');
  const cfg = {
    enabled: pick('enabled') === undefined ? true : !!pick('enabled'),
    url: String(pick('url', 'webhookUrl') ?? current?.url ?? '').trim(),
    byEvents: !!(pick('byEvents', 'webhookByEvents', 'webhook_by_events') ?? current?.byEvents ?? false),
    base64: !!(pick('base64', 'webhookBase64', 'webhook_base64') ?? current?.base64 ?? true),
    events: Array.isArray(events)
      ? events.map((e) => String(e).toUpperCase().replace(/\./g, '_').replace(/-/g, '_')).filter(Boolean)
      : (current?.events ?? DEFAULT_EVENTS),
    headers: typeof pick('headers') === 'object' && pick('headers') !== null ? pick('headers') : (current?.headers ?? {}),
  };
  return cfg;
}

const NOISY_EVENTS = new Set(['presence.update', 'contacts.upsert', 'contacts.update', 'chats.upsert', 'chats.update', 'chats.delete']);

/** Id e conversa da mensagem dentro do payload do evento (para o log). */
function idOf(d) {
  if (!d || typeof d !== 'object' || Array.isArray(d)) return null;
  return d.key?.id ?? d.keyId ?? d.messageId ?? (typeof d.id === 'string' && !d.id.includes('@') ? d.id : null);
}
function jidOf(d) {
  if (!d || typeof d !== 'object' || Array.isArray(d)) return null;
  return d.key?.remoteJid ?? d.remoteJid ?? null;
}

export class WebhookDispatcher {
  /**
   * @param {import('./instance.js').Instance} instance
   */
  constructor(instance) {
    this.instance = instance;
    this.queue = [];
    this.active = 0;
    this.stats = { delivered: 0, failed: 0, dropped: 0, lastError: null, lastErrorAt: null, lastDeliveredAt: null };
    this.log = logger.child({ instance: instance.name, mod: 'webhook' });
  }

  get config() {
    const c = this.instance.webhook;
    if (c && c.url) return c;
    if (config.webhook.defaultUrl) {
      return { enabled: true, byEvents: false, base64: true, events: DEFAULT_EVENTS, headers: {}, ...(c ?? {}), url: config.webhook.defaultUrl };
    }
    return c ?? null;
  }

  wants(event) {
    const c = this.config;
    if (!c || !c.enabled || !c.url) return false;
    const name = EVENT_NAMES[event] ?? event.toUpperCase().replace(/\./g, '_');
    return !c.events?.length || c.events.includes(name);
  }

  /** Envelope do evento (igual ao da Evolution). */
  envelope(event, data, url) {
    return {
      event,
      instance: this.instance.name,
      data,
      destination: url,
      date_time: nowIso(),
      sender: this.instance.ownerJid ?? null,
      server_url: config.publicUrl || null,
      apikey: this.instance.token,
    };
  }

  /**
   * Coloca o evento na fila. Não espera a entrega.
   * @param {object} [opts] prepare: função async chamada antes da entrega (ex.: anexar base64 da mídia)
   */
  enqueue(event, data, opts = {}) {
    if (!this.wants(event)) {
      const c = this.config;
      // Sem webhook (ou desligado): nada chega no sistema. Avisa no log a cada 10 min.
      if ((!c || !c.url || !c.enabled) && Date.now() - (this.noWebhookWarnAt ?? 0) > 600000) {
        this.noWebhookWarnAt = Date.now();
        ev('error', 'webhook', 'sem_webhook', {
          instance: this.instance.name,
          summary: !c || !c.url
            ? 'A instância não tem URL de webhook: os eventos do WhatsApp NÃO estão chegando no sistema'
            : 'O webhook da instância está desligado: os eventos do WhatsApp NÃO estão chegando no sistema',
          details: { evento: event },
        });
      }
      return false;
    }
    if (this.queue.length >= config.webhook.queueMax) {
      const lost = this.queue.shift();
      this.stats.dropped++;
      if (this.stats.dropped % 100 === 1) this.log.warn({ dropped: this.stats.dropped }, 'fila do webhook cheia: descartando os eventos mais antigos');
      ev('warn', 'webhook', 'descartado_fila_cheia', {
        instance: this.instance.name,
        messageId: idOf(lost?.data),
        status: lost?.event,
        summary: `Fila do webhook cheia (${config.webhook.queueMax}): evento ${lost?.event} descartado sem ir ao sistema (use Sincronizar)`,
        details: { descartadosAteAgora: this.stats.dropped },
      });
    }
    this.queue.push({ event, data, prepare: opts.prepare ?? null });
    this.pump();
    return true;
  }

  pump() {
    while (this.active < config.webhook.concurrency && this.queue.length) {
      const item = this.queue.shift();
      this.active++;
      this.deliver(item)
        .catch(() => {})
        .finally(() => {
          this.active--;
          this.pump();
        });
    }
  }

  /** Entrega com tentativas. Devolve {ok, status, error}. */
  async deliver(item, { retries = config.webhook.retries } = {}) {
    const c = this.config;
    if (!c || !c.url) return { ok: false, status: 0, error: 'webhook sem URL' };
    let data = item.data;
    if (item.prepare && c.base64) {
      try {
        data = (await item.prepare(data)) ?? data;
      } catch (e) {
        // O evento "midia/download_falhou" já foi registrado em attachBase64
        this.log.warn({ err: e.message }, 'falha ao preparar mídia (segue sem base64)');
      }
    }
    let url = c.url;
    if (c.byEvents) url = url.replace(/\/+$/, '') + '/' + item.event.replace(/\./g, '-').toLowerCase();
    const body = JSON.stringify(this.envelope(item.event, data, c.url));
    let last = { ok: false, status: 0, error: '' };
    const t0 = Date.now();
    let attempts = 0;
    let via = null;
    const errors = [];
    for (let attempt = 0; attempt < Math.max(1, retries); attempt++) {
      if (attempt > 0) await sleep(Math.min(30000, 1000 * 2 ** (attempt - 1)));
      attempts++;
      const { res, error, via: v } = await postWithRoute(url, body, c.headers, config.webhook.timeoutMs, this.instance.name);
      if (res) {
        const ok = res.status >= 200 && res.status < 300;
        via = v;
        last = { ok, status: res.status, error: ok ? '' : 'HTTP ' + res.status };
        // Corpo só interessa no erro (mostra o que o PHP respondeu)
        if (!ok) last.bodyStart = res.bodyStart;
        if (ok) break;
        errors.push(last.error);
        // Redirecionamento: o endereço do webhook está errado (ex.: http -> https)
        if (res.status >= 300 && res.status < 400) break;
        // 4xx (fora 408/429) não melhora tentando de novo
        if (res.status >= 400 && res.status < 500 && res.status !== 408 && res.status !== 429) break;
      } else {
        last = { ok: false, status: 0, error: errText(error) };
        errors.push(last.error);
      }
    }
    const ms = Date.now() - t0;
    const base = {
      instance: this.instance.name,
      jid: jidOf(data),
      messageId: idOf(data),
      status: item.event,
      http: last.status || undefined,
      ms,
    };
    if (last.ok) {
      this.stats.delivered++;
      this.stats.lastDeliveredAt = Date.now();
      // Presença/contatos/conversas chegam aos montes: só registra se precisou repetir
      if (attempts > 1 || !NOISY_EVENTS.has(item.event)) ev(attempts > 1 ? 'warn' : 'debug', 'webhook', attempts > 1 ? 'entregue_apos_tentativas' : 'entregue', {
        ...base,
        summary: `${item.event} entregue ao sistema (HTTP ${last.status}) em ${ms} ms${attempts > 1 ? ` depois de ${attempts} tentativas` : ''}`,
        details: { ...(attempts > 1 ? { tentativas: attempts, erros: errors } : {}), kb: Math.round(body.length / 1024), caminho: via ? 'interno ' + via : 'público' },
      });
    } else {
      this.stats.failed++;
      this.stats.lastError = `${item.event}: ${last.error}`;
      this.stats.lastErrorAt = Date.now();
      this.log.warn({ event: item.event, err: last.error }, 'webhook não entregue');
      ev('error', 'webhook', 'nao_entregue', {
        ...base,
        summary: `${item.event} NÃO chegou ao sistema depois de ${attempts} tentativa(s): ${last.error}`,
        error: last.error,
        details: {
          url, tentativas: attempts, erros: errors, kb: Math.round(body.length / 1024), respostaInicio: last.bodyStart ?? null,
          enderecosInternosTentados: config.webhook.localIps,
        },
      });
    }
    return last;
  }

  /** Entrega já (usada pelo Sincronizar, que precisa do resultado). */
  async deliverNow(event, data, retries = 2) {
    return this.deliver({ event, data, prepare: null }, { retries });
  }
}

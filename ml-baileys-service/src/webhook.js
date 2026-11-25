// Entrega dos eventos ao webhook do Multilife (contrato igual ao da Evolution v2).
//
// Regra principal (lição da Evolution): a entrega NUNCA bloqueia o processamento
// dos eventos do WhatsApp. Cada evento entra numa fila em memória por instância;
// os envios saem em paralelo limitado, com timeout de 10 s e até 5 tentativas
// com espera crescente. Fila cheia descarta os mais antigos (as mensagens ficam
// guardadas no banco e podem ser reenviadas pelo "Sincronizar").
import { config } from './config.js';
import { logger } from './logger.js';
import { ev } from './events.js';
import { nowIso, sleep } from './util.js';

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
    const errors = [];
    for (let attempt = 0; attempt < Math.max(1, retries); attempt++) {
      if (attempt > 0) await sleep(Math.min(30000, 1000 * 2 ** (attempt - 1)));
      attempts++;
      const ctrl = new AbortController();
      const t = setTimeout(() => ctrl.abort(), config.webhook.timeoutMs);
      try {
        const res = await fetch(url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', ...(c.headers ?? {}) },
          body,
          signal: ctrl.signal,
        });
        // Corpo só interessa no erro (mostra o que o PHP respondeu); consumir libera a conexão
        const buf = await res.arrayBuffer().catch(() => null);
        last = { ok: res.ok, status: res.status, error: res.ok ? '' : 'HTTP ' + res.status };
        if (!res.ok && buf) last.bodyStart = Buffer.from(buf).toString('utf8', 0, 500);
        if (res.ok) break;
        errors.push(last.error);
        // 4xx (fora 408/429) não melhora tentando de novo
        if (res.status >= 400 && res.status < 500 && res.status !== 408 && res.status !== 429) break;
      } catch (e) {
        const cause = e.cause?.code || e.cause?.message;
        last = { ok: false, status: 0, error: e.name === 'AbortError' ? 'timeout ' + config.webhook.timeoutMs + ' ms' : e.message + (cause ? ' (' + cause + ')' : '') };
        errors.push(last.error);
      } finally {
        clearTimeout(t);
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
        details: attempts > 1 ? { tentativas: attempts, erros: errors } : { kb: Math.round(body.length / 1024) },
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
        details: { url, tentativas: attempts, erros: errors, kb: Math.round(body.length / 1024), respostaInicio: last.bodyStart ?? null },
      });
    }
    return last;
  }

  /** Entrega já (usada pelo Sincronizar, que precisa do resultado). */
  async deliverNow(event, data, retries = 2) {
    return this.deliver({ event, data, prepare: null }, { retries });
  }
}

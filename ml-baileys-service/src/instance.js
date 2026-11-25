// Uma instância = um número de WhatsApp conectado (mesmo conceito da Evolution).
//
// Conexão, sessão, envio, eventos -> webhook, QR Code / código de pareamento e o
// monitor de conexão real (veredito healthy/silent/... que o PHP já consome).
import os from 'node:os';
import makeWASocket, { DisconnectReason, fetchLatestBaileysVersion, generateMessageIDV2, isJidStatusBroadcast, jidNormalizedUser } from 'baileys';
import QRCode from 'qrcode';
import { config } from './config.js';
import { exec } from './db.js';
import { baileysLogger, logger } from './logger.js';
import { deleteSession, hasLogin, useMysqlAuthState } from './authState.js';
import * as store from './store.js';
import { WebhookDispatcher } from './webhook.js';
import { downloadMedia, mediaContent } from './media.js';
import { toEvolutionMessage, toProtoMessage } from './format.js';
import {
  HttpError, MEDIA_TYPES, TtlCache, brVariants, isGroupJid, isLid, isPn, messageTypeOf, onlyDigits, sleep, toJid,
} from './util.js';

// Versão do WhatsApp Web: buscada e PINADA (diferença-chave para a Evolution).
// Se a busca falhar, sobe sem versão fixa (padrão do Baileys).
let waVersionCache = { v: null, at: 0 };
async function waVersion() {
  if (waVersionCache.v && Date.now() - waVersionCache.at < 6 * 3600 * 1000) return waVersionCache.v;
  try {
    const { version } = await fetchLatestBaileysVersion();
    waVersionCache = { v: version, at: Date.now() };
    logger.info({ version: version.join('.') }, 'versão do WhatsApp Web');
  } catch (e) {
    logger.warn({ err: e.message }, 'não foi possível buscar a versão do WhatsApp Web: usando a padrão do Baileys');
  }
  return waVersionCache.v;
}
export const currentWaVersion = () => waVersionCache.v;
export const warmWaVersion = () => waVersion();

const RECONNECT_DELAYS = [2, 5, 10, 20, 30, 60];
const IGNORED_TYPES = new Set(['senderKeyDistributionMessage', 'messageContextInfo', 'unknown']);
// protocolMessage que interessam ao PHP: apagar (REVOKE) e editar (MESSAGE_EDIT)
const KEPT_PROTOCOL_TYPES = new Set(['REVOKE', 'MESSAGE_EDIT', 0, 14]);

const parseJson = (s, d) => {
  if (!s) return d;
  try {
    return typeof s === 'string' ? JSON.parse(s) : s;
  } catch {
    return d;
  }
};

export class Instance {
  constructor(row) {
    this.name = row.name;
    this.token = row.token;
    this.ownerJid = row.owner_jid || null;
    this.profileName = row.profile_name || null;
    this.profilePicUrl = row.profile_pic_url || null;
    this.webhook = parseJson(row.webhook, null);
    this.settings = parseJson(row.settings, {});
    this.createdAt = row.created_at || null;
    this.statusReason = row.disconnect_code ?? null;
    this.lastDisconnectAt = row.disconnect_at ? Date.parse(row.disconnect_at.replace(' ', 'T')) || null : null;

    this.sock = null;
    this.auth = null;
    this.state = 'close';
    this.registered = false;
    this.qr = null;
    this.qrCount = 0;
    this.pairingCode = null;
    this.stopping = null;
    this.starting = null;
    this.reconnectTimer = null;
    this.reconnectAttempts = 0;
    this.qrWaiters = [];

    this.openedAt = null;
    this.lastEventAt = null;
    this.lastInboundAt = null;
    this.lastOutboundAt = null;
    this.lastHealAt = null;

    this.apiSent = new TtlCache(10 * 60 * 1000, 5000);
    this.groupCache = new TtlCache(5 * 60 * 1000, 2000);
    this.waCache = new TtlCache(24 * 3600 * 1000, 20000);
    this.retryCache = new TtlCache(10 * 60 * 1000, 10000);

    this.webhook_ = new WebhookDispatcher(this);
    this.log = logger.child({ instance: this.name });
  }

  get dispatcher() {
    return this.webhook_;
  }

  // ------------------------------------------------------------------
  // Ciclo de vida
  // ------------------------------------------------------------------

  async start() {
    if (this.sock) return;
    if (this.starting) return this.starting;
    this.starting = this._start().finally(() => {
      this.starting = null;
    });
    return this.starting;
  }

  async _start() {
    clearTimeout(this.reconnectTimer);
    this.reconnectTimer = null;
    this.stopping = null;
    this.auth = await useMysqlAuthState(this.name);
    this.registered = !!this.auth.state.creds?.me?.id;
    const version = await waVersion();
    const sock = makeWASocket({
      ...(version ? { version } : {}),
      auth: this.auth.state,
      logger: baileysLogger.child({ instance: this.name }),
      browser: [config.browserName, 'Chrome', os.release()],
      markOnlineOnConnect: this.settings.alwaysOnline !== false,
      shouldSyncHistoryMessage: () => false,
      syncFullHistory: false,
      emitOwnEvents: true,
      generateHighQualityLinkPreview: false,
      connectTimeoutMs: 60000,
      defaultQueryTimeoutMs: 60000,
      msgRetryCounterCache: this.retryCache,
      getMessage: async (key) => {
        const m = await store.getMessage(this.name, key.id, key.remoteJid).catch(() => null);
        return m?.message ?? undefined;
      },
      cachedGroupMetadata: async (jid) => this.groupCache.get(jid),
    });
    this.sock = sock;
    this.state = 'connecting';
    sock.ev.process(async (events) => {
      try {
        await this.onEvents(sock, events);
      } catch (e) {
        this.log.error({ err: e.message, stack: e.stack }, 'erro ao tratar eventos');
      }
    });
    this.log.info({ registered: this.registered }, 'conectando');
  }

  scheduleReconnect(immediate = false) {
    clearTimeout(this.reconnectTimer);
    const delay = immediate ? 300 : RECONNECT_DELAYS[Math.min(this.reconnectAttempts, RECONNECT_DELAYS.length - 1)] * 1000;
    this.reconnectAttempts++;
    this.log.info({ delayMs: delay, attempt: this.reconnectAttempts }, 'reconexão agendada');
    this.reconnectTimer = setTimeout(() => {
      this.reconnectTimer = null;
      this.start().catch((e) => {
        this.log.error({ err: e.message }, 'falha ao reconectar');
        this.scheduleReconnect();
      });
    }, delay);
  }

  /** Fecha o socket atual e reconecta com a sessão salva (sem QR Code). */
  async restart() {
    clearTimeout(this.reconnectTimer);
    const sock = this.sock;
    if (!sock) {
      if (!(await this.hasLogin())) throw new HttpError(400, `The "${this.name}" instance is not connected`);
      this.reconnectAttempts = 0;
      await this.start();
      return;
    }
    this.stopping = 'restart';
    await this.auth?.flush();
    try {
      sock.end(new Error('restart'));
    } catch {}
    // Socket zumbi que não emite "close": força a troca
    setTimeout(() => {
      if (this.sock === sock) {
        this.log.warn('socket não fechou no restart: forçando');
        this.sock = null;
        this.state = 'close';
        this.start().catch((e) => this.log.error({ err: e.message }, 'falha no restart forçado'));
      }
    }, 10000).unref();
  }

  async hasLogin() {
    if (this.registered) return true;
    return hasLogin(this.name);
  }

  /** Desconecta o aparelho do WhatsApp e apaga a sessão (vai pedir QR Code). */
  async logout() {
    clearTimeout(this.reconnectTimer);
    this.stopping = 'logout';
    const sock = this.sock;
    this.sock = null;
    if (sock) {
      try {
        if (this.state === 'open') await Promise.race([sock.logout(), sleep(8000)]);
        else sock.end(new Error('logout'));
      } catch (e) {
        this.log.warn({ err: e.message }, 'logout no WhatsApp falhou (a sessão é apagada mesmo assim)');
        try { sock.end(new Error('logout')); } catch {}
      }
    }
    this.auth?.discard();
    await deleteSession(this.name);
    this.state = 'close';
    this.registered = false;
    this.qr = null;
    this.pairingCode = null;
    this.openedAt = null;
    this.ownerJid = null;
    this.statusReason = DisconnectReason.loggedOut;
    this.lastDisconnectAt = Date.now();
    await this.saveMeta();
    this.dispatcher.enqueue('connection.update', this.connectionPayload('close', DisconnectReason.loggedOut));
    this.resolveQrWaiters();
  }

  /** Encerramento do processo: fecha sem deslogar (a sessão continua válida). */
  async shutdown() {
    clearTimeout(this.reconnectTimer);
    this.stopping = 'shutdown';
    await this.auth?.flush();
    try {
      this.sock?.end(undefined);
    } catch {}
    this.sock = null;
  }

  /** Antes de apagar a instância. */
  async destroy() {
    if (this.sock || (await this.hasLogin())) await this.logout().catch(() => {});
    this.stopping = 'delete';
    this.sock = null;
  }

  async saveMeta() {
    try {
      await exec(
        `UPDATE baileys_instances SET owner_jid = ?, profile_name = ?, profile_pic_url = ?, webhook = ?, settings = ?,
           disconnect_code = ?, disconnect_at = ? WHERE name = ?`,
        [
          this.ownerJid,
          this.profileName ? String(this.profileName).slice(0, 255) : null,
          this.profilePicUrl ? String(this.profilePicUrl).slice(0, 1000) : null,
          this.webhook ? JSON.stringify(this.webhook) : null,
          JSON.stringify(this.settings ?? {}),
          Number.isFinite(this.statusReason) ? this.statusReason : null,
          this.lastDisconnectAt ? new Date(this.lastDisconnectAt).toISOString().slice(0, 19).replace('T', ' ') : null,
          this.name,
        ],
      );
    } catch (e) {
      this.log.warn({ err: e.message }, 'falha ao gravar dados da instância');
    }
  }

  // ------------------------------------------------------------------
  // QR Code / pareamento
  // ------------------------------------------------------------------

  resolveQrWaiters() {
    const w = this.qrWaiters;
    this.qrWaiters = [];
    for (const r of w) r();
  }

  waitQr(ms) {
    if (this.qr || this.state === 'open' || !this.sock) return Promise.resolve();
    return new Promise((resolve) => {
      const t = setTimeout(resolve, ms);
      this.qrWaiters.push(() => {
        clearTimeout(t);
        resolve();
      });
    });
  }

  /**
   * GET /instance/connect: QR Code (e código de pareamento, se vier o número).
   * Já conectada: {instance:{instanceName, state:'open'}}.
   */
  async connect(number = '') {
    if (this.state === 'open' && this.sock) return { instance: { instanceName: this.name, state: 'open' } };
    if (!this.sock) {
      this.qrCount = 0;
      this.qr = null;
      this.pairingCode = null;
      this.reconnectAttempts = 0;
      await this.start();
    }
    await this.waitQr(this.registered ? 8000 : 15000);
    if (this.state === 'open') return { instance: { instanceName: this.name, state: 'open' } };
    const digits = onlyDigits(number);
    if (digits && !this.registered && this.sock && this.qr && !this.pairingCode) {
      try {
        this.pairingCode = await this.sock.requestPairingCode(digits);
        if (this.qr) this.qr.pairingCode = this.pairingCode;
        this.log.info('código de pareamento gerado');
      } catch (e) {
        this.log.warn({ err: e.message }, 'falha ao gerar código de pareamento');
      }
    }
    if (!this.qr) {
      return { pairingCode: this.pairingCode, code: null, base64: null, count: 0, instance: { instanceName: this.name, state: this.state } };
    }
    return { pairingCode: this.pairingCode ?? null, code: this.qr.code, base64: this.qr.base64, count: this.qr.count };
  }

  connectionPayload(state, statusReason) {
    return {
      instance: this.name,
      wuid: this.ownerJid,
      profileName: this.profileName,
      profilePictureUrl: this.profilePicUrl,
      state,
      statusReason: statusReason ?? 200,
    };
  }

  // ------------------------------------------------------------------
  // Eventos do Baileys
  // ------------------------------------------------------------------

  async onEvents(sock, events) {
    const current = sock === this.sock;

    if (events['connection.update']) {
      await this.onConnection(sock, events['connection.update']);
    }
    if (!current) return; // socket antigo (restart/logout): ignora o resto
    if (events['creds.update']) {
      this.registered = !!this.auth?.state.creds?.me?.id;
      await this.auth.saveCreds();
    }

    // Monitor: eventos que vêm de FORA (não o eco dos nossos envios)
    let activity = false;

    const upsert = events['messages.upsert'];
    if (upsert) {
      for (const msg of upsert.messages ?? []) {
        if (await this.onMessage(msg, upsert.type)) activity = true;
      }
    }

    const updates = events['messages.update'];
    if (updates) {
      for (const { key, update } of updates) {
        if (update.status !== undefined && update.status !== null) {
          if (Number(update.status) >= 3) activity = true;
          this.dispatcher.enqueue('messages.update', {
            messageId: key.id,
            keyId: key.id,
            remoteJid: key.remoteJid,
            fromMe: !!key.fromMe,
            participant: key.participant ?? null,
            status: ['ERROR', 'PENDING', 'SERVER_ACK', 'DELIVERY_ACK', 'READ', 'PLAYED'][Number(update.status)] ?? String(update.status),
            instanceId: this.name,
          });
        }
        // Mensagem apagada para todos
        if (update.message === null && Number(update.messageStubType) === 1) {
          activity = true;
          this.dispatcher.enqueue('messages.delete', { ...key, instanceId: this.name });
        }
      }
    }

    const deleted = events['messages.delete'];
    if (deleted && 'keys' in deleted) {
      activity = true;
      for (const key of deleted.keys) this.dispatcher.enqueue('messages.delete', { ...key, instanceId: this.name });
    }

    if (events['message-receipt.update']?.length) activity = true;
    if (events['messages.reaction']?.length) activity = true;

    if (events['presence.update']) {
      activity = true;
      this.dispatcher.enqueue('presence.update', events['presence.update']);
    }

    for (const ev of ['contacts.upsert', 'contacts.update']) {
      const list = events[ev];
      if (!list?.length) continue;
      activity = true;
      store.saveContacts(this.name, list).catch(() => {});
      this.dispatcher.enqueue(ev, list.map((c) => this.contactPayload(c)));
    }

    if (events['lid-mapping.update']) {
      const m = events['lid-mapping.update'];
      const pairs = Array.isArray(m) ? m : [m];
      store.saveContacts(this.name, pairs.filter((p) => p?.pn && p?.lid).map((p) => ({ id: jidNormalizedUser(p.pn), lid: p.lid, phoneNumber: jidNormalizedUser(p.pn) }))).catch(() => {});
    }

    for (const ev of ['chats.upsert', 'chats.update', 'chats.delete']) {
      if (events[ev]) {
        activity = true;
        this.dispatcher.enqueue(ev, events[ev]);
      }
    }

    if (events['groups.upsert']) {
      activity = true;
      for (const g of events['groups.upsert']) this.groupCache.set(g.id, g);
      this.dispatcher.enqueue('groups.upsert', events['groups.upsert']);
    }
    if (events['groups.update']) {
      activity = true;
      for (const g of events['groups.update']) if (g.id) this.groupCache.del(g.id);
      this.dispatcher.enqueue('groups.update', events['groups.update']);
    }
    if (events['group-participants.update']) {
      activity = true;
      const u = events['group-participants.update'];
      this.groupCache.del(u.id);
      this.dispatcher.enqueue('group-participants.update', u);
    }

    if (events.call) {
      activity = true;
      for (const call of events.call) await this.onCall(call);
      this.dispatcher.enqueue('call', events.call);
    }

    if (activity) this.lastEventAt = Date.now();
  }

  async onConnection(sock, u) {
    const { connection, lastDisconnect, qr } = u;
    const current = sock === this.sock;

    if (qr && current) {
      this.qrCount++;
      if (this.qrCount > config.qrLimit) {
        this.log.info({ count: this.qrCount - 1 }, 'QR Code não lido: desistindo (chame /instance/connect de novo)');
        this.stopping = 'qrlimit';
        try { sock.end(new Error('QR Code não lido')); } catch {}
        return;
      }
      let base64 = null;
      try {
        base64 = await QRCode.toDataURL(qr, { margin: 2, width: 320 });
      } catch (e) {
        this.log.warn({ err: e.message }, 'falha ao desenhar o QR Code');
      }
      this.state = 'connecting';
      this.qr = { code: qr, base64, count: this.qrCount, pairingCode: this.pairingCode ?? null };
      this.resolveQrWaiters();
      this.dispatcher.enqueue('qrcode.updated', {
        qrcode: { instance: this.name, pairingCode: this.pairingCode ?? null, code: qr, base64 },
      });
    }

    if (connection === 'connecting' && current) {
      this.state = 'connecting';
    }

    if (connection === 'open' && current) {
      this.state = 'open';
      this.openedAt = Date.now();
      this.reconnectAttempts = 0;
      this.qr = null;
      this.qrCount = 0;
      this.pairingCode = null;
      this.registered = true;
      this.statusReason = 200;
      this.ownerJid = sock.user?.id ? jidNormalizedUser(sock.user.id) : this.ownerJid;
      this.profileName = sock.user?.name || sock.user?.verifiedName || sock.user?.notify || this.profileName;
      this.resolveQrWaiters();
      await this.saveMeta();
      this.log.info({ owner: this.ownerJid }, 'conectado');
      this.dispatcher.enqueue('connection.update', this.connectionPayload('open', 200));
      if (this.ownerJid) {
        sock.profilePictureUrl(this.ownerJid, 'image')
          .then((url) => {
            if (url && url !== this.profilePicUrl) {
              this.profilePicUrl = url;
              this.saveMeta();
            }
          })
          .catch(() => {});
      }
    }

    if (connection === 'close' && current) {
      await this.onClose(lastDisconnect);
    }
  }

  async onClose(lastDisconnect) {
    const err = lastDisconnect?.error;
    const code = err?.output?.statusCode ?? err?.statusCode ?? null;
    const reason = this.stopping;
    this.sock = null;
    this.state = 'close';
    this.qr = null;
    this.openedAt = null;
    this.resolveQrWaiters();
    if (reason === 'shutdown' || reason === 'delete' || reason === 'logout') return;

    this.statusReason = code;
    this.lastDisconnectAt = Date.now();
    this.log.info({ code, reason, err: err?.message }, 'conexão fechada');

    if (code === DisconnectReason.loggedOut) {
      // Aparelho removido no celular: a sessão não vale mais
      this.auth?.discard();
      await deleteSession(this.name).catch(() => {});
      this.registered = false;
      this.ownerJid = null;
      await this.saveMeta();
      this.dispatcher.enqueue('connection.update', this.connectionPayload('close', code));
      return;
    }
    if (reason === 'restart' || code === DisconnectReason.restartRequired) {
      // Reinício pedido (ou o exigido logo depois de ler o QR Code)
      this.dispatcher.enqueue('connection.update', this.connectionPayload('connecting', code ?? 200));
      this.scheduleReconnect(true);
      return;
    }
    await this.saveMeta();
    this.dispatcher.enqueue('connection.update', this.connectionPayload('close', code ?? 500));
    if (reason === 'qrlimit' || !this.registered) return; // QR Code não lido: espera novo /connect
    if (code === DisconnectReason.connectionReplaced) {
      this.log.warn('outra sessão abriu com este número (440): não reconecta sozinho');
      return;
    }
    if (code === DisconnectReason.forbidden) {
      this.log.warn('WhatsApp recusou a conexão (403): não reconecta sozinho');
      return;
    }
    this.scheduleReconnect();
  }

  /** Uma mensagem do messages.upsert. Devolve true se conta como atividade de entrada. */
  async onMessage(msg, upsertType) {
    const key = msg?.key;
    if (!key?.remoteJid || !key.id) return false;
    if (isJidStatusBroadcast(key.remoteJid) || key.remoteJid.endsWith('@newsletter')) return false;
    if (!msg.message) return false;

    store.saveMessage(this.name, msg);

    const isEcho = this.apiSent.has(key.id);
    if (!key.fromMe) this.lastInboundAt = Date.now();

    // Nome do contato (pushName) para findContacts
    if (!key.fromMe && msg.pushName && !isGroupJid(key.remoteJid)) {
      const pn = isPn(key.remoteJid) ? key.remoteJid : (isPn(key.remoteJidAlt) ? key.remoteJidAlt : null);
      store.saveContacts(this.name, [{
        id: pn ?? key.remoteJid,
        lid: isLid(key.remoteJid) ? key.remoteJid : (isLid(key.remoteJidAlt) ? key.remoteJidAlt : null),
        phoneNumber: pn,
        notify: msg.pushName,
      }]).catch(() => {});
    }

    const type = messageTypeOf(msg.message);
    if (IGNORED_TYPES.has(type)) return !isEcho;
    if (type === 'protocolMessage') {
      const pt = msg.message.protocolMessage?.type ?? msg.message.ephemeralMessage?.message?.protocolMessage?.type;
      if (!KEPT_PROTOCOL_TYPES.has(pt)) return false;
    }
    if (isEcho) return false; // o envio pela API já gerou o SEND_MESSAGE
    if (this.settings.groupsIgnore && isGroupJid(key.remoteJid)) return true;

    if (this.settings.readMessages && !key.fromMe && upsertType === 'notify' && this.sock) {
      this.sock.readMessages([key]).catch(() => {});
    }

    const data = await toEvolutionMessage(msg, this.name, (lid) => this.lidToPn(lid));
    const needsMedia = MEDIA_TYPES.has(type) || !!mediaContent(msg.message);
    this.dispatcher.enqueue('messages.upsert', data, needsMedia ? { prepare: (d) => this.attachBase64(d, msg) } : {});
    return true;
  }

  /** Mídia em base64 dentro do message (como a Evolution com webhook base64 ligado). */
  async attachBase64(data, msg) {
    const media = await downloadMedia(this.sock, msg);
    if (!media) return data;
    // Corpo do webhook precisa caber no post_max_size do PHP (base64 = +33%)
    if (media.buffer.length > Math.min(config.maxMediaBytes, 45 * 1024 * 1024)) return data;
    return { ...data, message: { ...(data.message ?? {}), base64: media.buffer.toString('base64') } };
  }

  contactPayload(c) {
    const pn = isPn(c.phoneNumber) ? c.phoneNumber : (isPn(c.id) ? c.id : null);
    const lid = isLid(c.lid) ? c.lid : (isLid(c.id) ? c.id : null);
    return {
      id: pn ?? c.id,
      remoteJid: pn ?? c.id,
      lid,
      pushName: c.notify ?? c.verifiedName ?? c.name ?? null,
      name: c.name ?? null,
      profilePicUrl: c.imgUrl && c.imgUrl !== 'changed' ? c.imgUrl : null,
      instanceId: this.name,
    };
  }

  async onCall(call) {
    if (!this.settings.rejectCall || call.status !== 'offer' || !this.sock) return;
    try {
      await this.sock.rejectCall(call.id, call.from);
      if (this.settings.msgCall) await this.sock.sendMessage(call.from, { text: String(this.settings.msgCall) });
    } catch (e) {
      this.log.warn({ err: e.message }, 'falha ao recusar ligação');
    }
  }

  // ------------------------------------------------------------------
  // Envio
  // ------------------------------------------------------------------

  ensureOpen() {
    if (!this.sock || this.state !== 'open') {
      throw new HttpError(400, `Connection Closed: a instância "${this.name}" não está conectada (estado: ${this.state})`);
    }
    return this.sock;
  }

  /** Número do LID (mapa do Baileys; senão os contatos guardados). */
  async lidToPn(lid) {
    if (!isLid(lid)) return null;
    try {
      const pn = await this.sock?.signalRepository?.lidMapping?.getPNForLID(lid);
      if (pn) return jidNormalizedUser(pn);
    } catch {}
    return store.pnForLid(this.name, lid.replace(/:\d+@/, '@')).catch(() => null);
  }

  /**
   * JID de destino. Conversa privada: confirma no WhatsApp (e acerta o 9 do
   * celular brasileiro). Número sem WhatsApp = 400 {exists:false} (igual à Evolution).
   */
  async resolveJid(number) {
    const jid = toJid(number);
    if (!jid) throw new HttpError(400, 'number is required');
    if (!isPn(jid)) return jid;
    const digits = onlyDigits(jid);
    const cached = this.waCache.get(digits);
    if (cached) return cached;
    if (cached === false) throw new HttpError(400, [{ exists: false, jid, number: digits }]);
    const sock = this.ensureOpen();
    let res;
    try {
      res = await sock.onWhatsApp(...brVariants(digits));
    } catch (e) {
      this.log.warn({ err: e.message }, 'consulta onWhatsApp falhou: enviando para o número como veio');
      return jid;
    }
    const found = (res ?? []).find((r) => r.exists);
    if (found?.jid) {
      const real = jidNormalizedUser(found.jid);
      this.waCache.set(digits, real);
      return real;
    }
    this.waCache.set(digits, false, 10 * 60 * 1000);
    throw new HttpError(400, [{ exists: false, jid, number: digits }]);
  }

  /**
   * Vários números de uma vez (participantes de grupo): uma consulta só ao
   * WhatsApp. Número sem WhatsApp fica como veio (o WhatsApp devolve o erro
   * por participante).
   */
  async resolveJids(list) {
    const out = [];
    const pending = new Map(); // dígitos -> posição
    const sock = this.ensureOpen();
    for (const item of Array.isArray(list) ? list : [list]) {
      if (!item) continue;
      const jid = toJid(item);
      if (!isPn(jid)) {
        out.push(jid);
        continue;
      }
      const digits = onlyDigits(jid);
      const cached = this.waCache.get(digits);
      if (cached) {
        out.push(cached);
        continue;
      }
      pending.set(digits, out.length);
      out.push(jid);
    }
    if (pending.size) {
      const variants = [...pending.keys()].flatMap((d) => brVariants(d));
      try {
        const res = (await sock.onWhatsApp(...variants)) ?? [];
        const found = new Map(res.filter((r) => r.exists && r.jid).map((r) => [onlyDigits(r.jid.split('@')[0]), jidNormalizedUser(r.jid)]));
        for (const [digits, pos] of pending) {
          const hit = brVariants(digits).map((v) => found.get(v)).find(Boolean);
          if (hit) {
            out[pos] = hit;
            this.waCache.set(digits, hit);
          }
        }
      } catch (e) {
        this.log.warn({ err: e.message }, 'consulta onWhatsApp (lote) falhou: usando os números como vieram');
      }
    }
    return out;
  }

  async groupMeta(jid, force = false) {
    if (!force) {
      const c = this.groupCache.get(jid);
      if (c) return c;
    }
    const meta = await this.ensureOpen().groupMetadata(jid);
    this.groupCache.set(jid, meta);
    return meta;
  }

  /** Mensagem citada (quoted da Evolution) -> WAMessage. */
  async quotedFor(jid, quoted) {
    const id = quoted?.key?.id;
    if (!id) return undefined;
    const stored = await store.getMessage(this.name, id, quoted.key.remoteJid || jid).catch(() => null);
    if (stored) return stored;
    if (!quoted.message) return undefined;
    return {
      key: { remoteJid: quoted.key.remoteJid || jid, fromMe: !!quoted.key.fromMe, id, participant: quoted.key.participant },
      message: toProtoMessage(quoted.message),
    };
  }

  /**
   * Envia e devolve o registro no formato da Evolution.
   * opts: {delay, quoted, mentionsEveryOne, mentioned, presence}
   */
  async send(number, content, opts = {}) {
    const sock = this.ensureOpen();
    const jid = await this.resolveJid(number);

    if (isGroupJid(jid) && (opts.mentionsEveryOne || (Array.isArray(opts.mentioned) && opts.mentioned.length))) {
      let mentions = [];
      if (opts.mentionsEveryOne) {
        const meta = await this.groupMeta(jid).catch(() => null);
        mentions = (meta?.participants ?? []).map((p) => p.id);
      } else {
        mentions = opts.mentioned.map((m) => toJid(m)).filter(Boolean);
      }
      if (mentions.length && !content.react && !content.delete) content.mentions = mentions;
    } else if (Array.isArray(opts.mentioned) && opts.mentioned.length && !content.react) {
      content.mentions = opts.mentioned.map((m) => toJid(m)).filter(Boolean);
    }

    const delay = Math.min(Number(opts.delay) || 0, 25000);
    if (delay > 0) {
      try {
        await sock.sendPresenceUpdate(opts.presence || 'composing', jid);
      } catch {}
      await sleep(delay);
      try {
        await sock.sendPresenceUpdate('paused', jid);
      } catch {}
    }

    // Id gerado antes do envio: o eco (messages.upsert do próprio envio) pode
    // chegar antes de o sendMessage terminar e não pode ir de novo ao webhook.
    const messageId = generateMessageIDV2(sock.user?.id);
    this.apiSent.set(messageId, 1);
    const sendOpts = { messageId };
    const quoted = await this.quotedFor(jid, opts.quoted);
    if (quoted) sendOpts.quoted = quoted;
    if (opts.statusJidList) sendOpts.statusJidList = opts.statusJidList;

    const msg = await sock.sendMessage(jid, content, sendOpts);
    if (!msg) throw new HttpError(500, 'O WhatsApp não confirmou o envio');
    this.apiSent.set(msg.key.id, 1);
    this.lastOutboundAt = Date.now();
    store.saveMessage(this.name, msg);
    const rec = await toEvolutionMessage(msg, this.name, (lid) => this.lidToPn(lid));
    this.dispatcher.enqueue('send.message', rec);
    return rec;
  }

  // ------------------------------------------------------------------
  // Monitor de conexão real (watchdog)
  // ------------------------------------------------------------------

  statusInfo() {
    const now = Date.now();
    const sec = (t) => (t ? Math.max(0, Math.floor((now - t) / 1000)) : null);
    const m = config.monitor;
    let verdict;
    if (this.state === 'open' && this.openedAt) {
      const since = Math.max(this.lastEventAt ?? 0, this.openedAt);
      const quiet = (now - since) / 1000;
      const noEventSinceOpen = !this.lastEventAt || this.lastEventAt < this.openedAt;
      if (quiet > m.silentSec) verdict = 'silent';
      else if (noEventSinceOpen && (now - this.openedAt) / 1000 < m.warmupSec) verdict = 'warming_up';
      else verdict = 'healthy';
    } else if (this.state === 'connecting') {
      verdict = this.registered ? 'warming_up' : 'awaiting_scan';
    } else {
      verdict = 'dormant';
    }
    const inboundRef = Math.max(this.lastInboundAt ?? 0, this.openedAt ?? 0);
    return {
      name: this.name,
      number: this.ownerJid ? onlyDigits(this.ownerJid.split('@')[0]) : null,
      verdict,
      state: this.state,
      socket: this.state,
      lastEventSecondsAgo: sec(this.lastEventAt),
      lastHealSecondsAgo: sec(this.lastHealAt),
      lastInboundAt: this.lastInboundAt ? new Date(this.lastInboundAt).toISOString() : null,
      inboundSilentMinutes: this.state === 'open' && inboundRef ? Math.floor((now - inboundRef) / 60000) : null,
      lastOutboundAt: this.lastOutboundAt ? new Date(this.lastOutboundAt).toISOString() : null,
      uptime: this.openedAt ? sec(this.openedAt) : 0,
      statusReason: this.statusReason ?? null,
      webhook: {
        queued: this.dispatcher.queue.length,
        delivered: this.dispatcher.stats.delivered,
        failed: this.dispatcher.stats.failed,
        dropped: this.dispatcher.stats.dropped,
        lastError: this.dispatcher.stats.lastError,
      },
    };
  }

  /**
   * Reconexão do monitor (restart sem QR). Só age em "silent", a não ser com force.
   * @returns {{status:number, body:object}}
   */
  async heal(force = false) {
    const st = this.statusInfo();
    const base = { instance: this.name, verdict: st.verdict, state: st.state };
    if (st.verdict === 'dormant') {
      return { status: 409, body: { ok: false, ...base, reason: 'instância desconectada: exige leitura do QR Code' } };
    }
    if (st.verdict === 'awaiting_scan') {
      return { status: 409, body: { ok: false, ...base, reason: 'aguardando leitura do QR Code' } };
    }
    if (st.verdict !== 'silent' && !force) {
      return { status: 409, body: { ok: false, ...base, reason: 'instância saudável — heal não aplicado' } };
    }
    this.lastHealAt = Date.now();
    await this.restart();
    return { status: 200, body: { ok: true, action: 'restart', ...base } };
  }

  /** Formato do fetchInstances (v2) com apelidos usados pelo PHP. */
  toEvolution() {
    return {
      id: this.name,
      name: this.name,
      instanceName: this.name,
      connectionStatus: this.state,
      status: this.state,
      ownerJid: this.ownerJid,
      owner: this.ownerJid,
      number: this.ownerJid ? this.ownerJid.split('@')[0] : null,
      profileName: this.profileName,
      profilePicUrl: this.profilePicUrl,
      integration: 'WHATSAPP-BAILEYS',
      businessId: null,
      token: this.token,
      clientName: 'ml-baileys-service',
      disconnectionReasonCode: this.statusReason ?? null,
      disconnectionObject: null,
      disconnectionAt: this.lastDisconnectAt ? new Date(this.lastDisconnectAt).toISOString() : null,
      createdAt: this.createdAt,
      Setting: this.settingsView(),
      Webhook: this.webhookView(),
    };
  }

  settingsView() {
    const s = this.settings ?? {};
    return {
      rejectCall: !!s.rejectCall,
      msgCall: s.msgCall ?? '',
      groupsIgnore: !!s.groupsIgnore,
      alwaysOnline: s.alwaysOnline !== false,
      readMessages: !!s.readMessages,
      readStatus: !!s.readStatus,
      syncFullHistory: false,
    };
  }

  webhookView() {
    const w = this.dispatcher.config;
    if (!w) return null;
    return {
      id: this.name,
      url: w.url,
      headers: w.headers ?? {},
      enabled: !!w.enabled,
      events: w.events ?? [],
      webhookByEvents: !!w.byEvents,
      webhookBase64: !!w.base64,
      byEvents: !!w.byEvents,
      base64: !!w.base64,
      instanceId: this.name,
    };
  }
}

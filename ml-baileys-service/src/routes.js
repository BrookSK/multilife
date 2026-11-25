// Rotas REST com o MESMO contrato da Evolution v2 (caminhos, corpos e respostas)
// que o PHP do Multilife já usa, mais as rotas do monitor (/status, /heal, /redispatch).
import express from 'express';
import { config } from './config.js';
import { logger, recentLogs } from './logger.js';
import * as manager from './manager.js';
import * as store from './store.js';
import { normalizeWebhookConfig } from './webhook.js';
import { downloadMedia, prepareVoiceNote } from './media.js';
import { toEvolutionGroup, toEvolutionMessage } from './format.js';
import { currentWaVersion } from './instance.js';
import { query } from './db.js';
import { state } from './state.js';
import {
  HttpError, isGroupJid, isLid, isPn, mediaInput, onlyDigits, safeEqual, toJid,
} from './util.js';

const pkgVersion = '1.1.0';

/** Handler async: devolve JSON; erros viram o formato da Evolution. */
const wrap = (fn, okStatus = 200) => async (req, res) => {
  try {
    const out = await fn(req, res);
    if (res.headersSent) return;
    if (out && out.__status) {
      res.status(out.__status).json(out.body);
      return;
    }
    res.status(okStatus).json(out ?? {});
  } catch (e) {
    if (e instanceof HttpError) {
      res.status(e.status).json(e.toJSON());
      return;
    }
    const boomStatus = e?.output?.statusCode;
    const status = boomStatus && boomStatus >= 400 && boomStatus < 600 ? (boomStatus === 428 ? 400 : boomStatus) : 500;
    logger.warn({ path: req.path, err: e?.message }, 'erro na rota');
    res.status(status).json(new HttpError(status, e?.message || 'erro interno').toJSON());
  }
};

const param = (req, name) => req.body?.[name] ?? req.query?.[name];
const truthy = (v) => v === true || v === 'true' || v === '1' || v === 1;

/** Antes de ficar pronto (configuração, banco, trava de líder), só /health responde. */
function readyMiddleware(req, res, next) {
  if (req.path === '/health' || req.path === '/service/logs' || state.ready) return next();
  res.set('Retry-After', '5');
  res.status(503).json(new HttpError(503, 'Serviço WhatsApp iniciando: ' + state.phase).toJSON());
}

/** Chave da API: header apikey (Evolution), x-token / Bearer (watchdog) ou ?apikey/?token. */
function authMiddleware(req, res, next) {
  if (req.path === '/' || req.path === '/health') return next();
  const bearer = (req.get('authorization') || '').replace(/^Bearer\s+/i, '');
  const key = req.get('apikey') || req.get('x-token') || bearer || req.query.apikey || req.query.token || '';
  if (safeEqual(key, config.apiKey)) return next();
  // Token da própria instância (hash devolvido no /instance/create) só vale para ela
  const segs = req.path.split('/').map((s) => decodeURIComponent(s));
  for (const s of segs.slice(2, 4)) {
    const inst = manager.get(s);
    if (inst && safeEqual(key, inst.token)) return next();
  }
  res.status(401).json(new HttpError(401, 'Unauthorized').toJSON());
}

export function buildApp() {
  const app = express();
  app.disable('x-powered-by');
  app.use(express.json({ limit: '100mb' }));
  app.use((err, req, res, next) => {
    // JSON inválido no corpo
    if (err) {
      res.status(400).json(new HttpError(400, 'JSON inválido: ' + err.message).toJSON());
      return;
    }
    next();
  });
  // /health: sem chave (o sistema usa para saber se o serviço está no ar e para
  // mantê-lo ligado). Não mostra dados sensíveis.
  app.get('/health', (req, res) => {
    res.set('Cache-Control', 'no-store');
    res.json({
      ok: true,
      clientName: 'ml-baileys-service',
      version: pkgVersion,
      ready: state.ready,
      phase: state.phase,
      configured: config.apiKey !== '',
      configSource: config.source,
      leader: state.leader,
      instances: state.ready ? manager.list().length : null,
      messageRetentionDays: config.messageRetentionDays,
      uptimeSec: Math.floor((Date.now() - state.startedAt) / 1000),
      node: process.version,
      error: state.lastError,
    });
  });
  app.use(readyMiddleware);
  app.use(authMiddleware);

  app.get('/', (req, res) => {
    res.json({
      status: 200,
      message: 'Welcome to the ml-baileys-service (WhatsApp do Multilife)',
      version: pkgVersion,
      clientName: 'ml-baileys-service',
      provider: 'baileys',
      baileysVersion: '7.0.0-rc.9',
      waWebVersion: currentWaVersion()?.join('.') ?? null,
    });
  });

  instanceRoutes(app);
  webhookRoutes(app);
  messageRoutes(app);
  chatRoutes(app);
  groupRoutes(app);
  monitorRoutes(app);

  app.use((req, res) => {
    res.status(404).json(new HttpError(404, `Cannot ${req.method} ${req.path}`).toJSON());
  });
  return app;
}

// ----------------------------------------------------------------------
// Instâncias
// ----------------------------------------------------------------------

function instanceRoutes(app) {
  app.post('/instance/create', wrap(async (req) => manager.create(req.body ?? {}), 201));

  app.get('/instance/fetchInstances', wrap(async (req) => {
    const name = req.query.instanceName || req.query.instanceId;
    if (name) {
      const inst = manager.get(name);
      return inst ? [inst.toEvolution()] : [];
    }
    return manager.list().map((i) => i.toEvolution());
  }));

  app.get('/instance/connect/:instance', wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    return inst.connect(String(req.query.number ?? ''));
  }));

  app.get('/instance/connectionState/:instance', wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    // "state" na raiz: telas antigas liam assim
    return { instance: { instanceName: inst.name, state: inst.state, owner: inst.ownerJid }, state: inst.state };
  }));

  const restart = wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    await inst.restart();
    return { instance: { instanceName: inst.name, state: inst.state }, status: 'SUCCESS', error: false };
  });
  app.post('/instance/restart/:instance', restart);
  app.put('/instance/restart/:instance', restart);

  app.delete('/instance/logout/:instance', wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    await inst.logout();
    return { status: 'SUCCESS', error: false, response: { message: 'Instance logged out' } };
  }));

  app.delete('/instance/delete/:instance', wrap(async (req) => {
    await manager.remove(req.params.instance);
    return { status: 'SUCCESS', error: false, response: { message: 'Instance deleted' } };
  }));

  app.post('/instance/setPresence/:instance', wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const presence = String(param(req, 'presence') ?? 'available');
    await inst.ensureOpen().sendPresenceUpdate(presence === 'unavailable' ? 'unavailable' : 'available');
    return { presence };
  }, 201));

  // Configurações de comportamento (rejectCall, groupsIgnore...)
  app.post('/settings/set/:instance', wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const b = req.body?.settings && typeof req.body.settings === 'object' ? req.body.settings : (req.body ?? {});
    return { settings: { instanceName: inst.name, settings: await manager.updateSettings(inst, b) } };
  }, 201));
  app.get('/settings/find/:instance', wrap(async (req) => manager.must(req.params.instance).settingsView()));
}

// ----------------------------------------------------------------------
// Webhook
// ----------------------------------------------------------------------

function webhookRoutes(app) {
  app.post('/webhook/set/:instance', wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const cfg = normalizeWebhookConfig(req.body ?? {}, inst.webhook);
    if (cfg.enabled && !/^https?:\/\//i.test(cfg.url)) throw new HttpError(400, 'url do webhook inválida');
    inst.webhook = cfg;
    await inst.saveMeta();
    return inst.webhookView();
  }, 201));

  app.get('/webhook/find/:instance', wrap(async (req) => manager.must(req.params.instance).webhookView()));
}

// ----------------------------------------------------------------------
// Mensagens
// ----------------------------------------------------------------------

/** Opções de envio nos formatos v2 (raiz) e v1 (options). */
function sendOpts(b) {
  const o = b.options && typeof b.options === 'object' ? b.options : {};
  return {
    delay: b.delay ?? o.delay,
    quoted: b.quoted ?? o.quoted,
    mentionsEveryOne: truthy(b.mentionsEveryOne ?? o.mentions?.everyOne ?? o.mentionsEveryOne),
    mentioned: b.mentioned ?? o.mentions?.mentioned ?? o.mentioned,
    presence: o.presence,
  };
}

const requireNumber = (b) => {
  if (!b.number) throw new HttpError(400, 'number is required');
  return b.number;
};

function messageRoutes(app) {
  app.post('/message/sendText/:instance', wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const b = req.body ?? {};
    const text = b.text ?? b.textMessage?.text ?? '';
    if (String(text).trim() === '') throw new HttpError(400, 'text is required');
    return inst.send(requireNumber(b), { text: String(text) }, sendOpts(b));
  }, 201));

  app.post('/message/sendMedia/:instance', wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const b0 = req.body ?? {};
    const b = b0.mediaMessage && typeof b0.mediaMessage === 'object' ? { ...b0, ...b0.mediaMessage } : b0;
    const type = String(b.mediatype ?? b.mediaType ?? 'document').toLowerCase();
    const media = mediaInput(b.media);
    const caption = b.caption ? String(b.caption) : undefined;
    let content;
    if (type === 'image') content = { image: media, caption, ...(b.mimetype ? { mimetype: b.mimetype } : {}) };
    else if (type === 'video') content = { video: media, caption, ...(b.mimetype ? { mimetype: b.mimetype } : {}) };
    else if (type === 'audio') content = { audio: media, mimetype: b.mimetype || 'audio/mpeg' };
    else {
      content = {
        document: media,
        mimetype: b.mimetype || 'application/octet-stream',
        fileName: b.fileName || 'documento',
        caption,
      };
    }
    return inst.send(requireNumber(b), content, sendOpts(b));
  }, 201));

  app.post('/message/sendWhatsAppAudio/:instance', wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const b0 = req.body ?? {};
    const b = b0.audioMessage && typeof b0.audioMessage === 'object' ? { ...b0, ...b0.audioMessage } : b0;
    const { audio, mimetype, ptt } = await prepareVoiceNote(mediaInput(b.audio), b.mimetype);
    return inst.send(requireNumber(b), { audio, ptt, mimetype }, { ...sendOpts(b), presence: 'recording' });
  }, 201));

  app.post('/message/sendSticker/:instance', wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const b = req.body ?? {};
    const sticker = mediaInput(b.sticker ?? b.stickerMessage?.image ?? b.image);
    return inst.send(requireNumber(b), { sticker }, sendOpts(b));
  }, 201));

  app.post('/message/sendLocation/:instance', wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const b0 = req.body ?? {};
    const b = b0.locationMessage && typeof b0.locationMessage === 'object' ? { ...b0, ...b0.locationMessage } : b0;
    const lat = Number(b.latitude ?? b.degreesLatitude);
    const lng = Number(b.longitude ?? b.degreesLongitude);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) throw new HttpError(400, 'latitude/longitude obrigatórios');
    return inst.send(requireNumber(b), {
      location: { degreesLatitude: lat, degreesLongitude: lng, name: b.name, address: b.address },
    }, sendOpts(b));
  }, 201));

  app.post('/message/sendContact/:instance', wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const b = req.body ?? {};
    let list = b.contact ?? b.contactMessage ?? [];
    if (!Array.isArray(list)) list = [list];
    if (!list.length) throw new HttpError(400, 'contact is required');
    const contacts = list.map((c) => {
      const phone = onlyDigits(c.phoneNumber ?? c.wuid ?? '');
      const wuid = onlyDigits(c.wuid ?? phone);
      const name = c.fullName ?? c.displayName ?? phone;
      const vcard = 'BEGIN:VCARD\nVERSION:3.0\n'
        + `FN:${name}\n`
        + (c.organization ? `ORG:${c.organization};\n` : '')
        + (c.email ? `EMAIL:${c.email}\n` : '')
        + (c.url ? `URL:${c.url}\n` : '')
        + `TEL;type=CELL;type=VOICE;waid=${wuid}:+${phone}\n`
        + 'END:VCARD';
      return { displayName: name, vcard };
    });
    return inst.send(requireNumber(b), {
      contacts: { displayName: contacts.length === 1 ? contacts[0].displayName : `${contacts.length} contatos`, contacts },
    }, sendOpts(b));
  }, 201));

  app.post('/message/sendReaction/:instance', wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const b0 = req.body ?? {};
    const b = b0.reactionMessage && typeof b0.reactionMessage === 'object' ? b0.reactionMessage : b0;
    const key = b.key ?? {};
    if (!key.remoteJid || !key.id) throw new HttpError(400, 'key.remoteJid e key.id obrigatórios');
    return inst.send(key.remoteJid, { react: { text: String(b.reaction ?? ''), key: { ...key, remoteJid: toJid(key.remoteJid) } } });
  }, 201));

  app.post('/message/sendPoll/:instance', wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const b0 = req.body ?? {};
    const b = b0.pollMessage && typeof b0.pollMessage === 'object' ? { ...b0, ...b0.pollMessage } : b0;
    const values = Array.isArray(b.values) ? b.values.map(String) : [];
    if (!b.name || values.length < 2) throw new HttpError(400, 'name e pelo menos 2 values obrigatórios');
    return inst.send(requireNumber(b), {
      poll: { name: String(b.name), values, selectableCount: Math.max(1, Number(b.selectableCount) || 1) },
    }, sendOpts(b));
  }, 201));

  app.post('/message/sendStatus/:instance', wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const b0 = req.body ?? {};
    const b = b0.statusMessage && typeof b0.statusMessage === 'object' ? b0.statusMessage : b0;
    const type = String(b.type ?? 'text');
    let jids = Array.isArray(b.statusJidList) ? b.statusJidList.map(toJid) : [];
    if (truthy(b.allContacts) || !jids.length) {
      const rows = await store.findContacts(inst.name);
      jids = rows.map((r) => r.phone_jid || r.jid).filter(isPn);
    }
    let content;
    if (type === 'text') content = { text: String(b.content ?? '') };
    else if (type === 'image') content = { image: mediaInput(b.content), caption: b.caption };
    else if (type === 'video') content = { video: mediaInput(b.content), caption: b.caption };
    else if (type === 'audio') content = { audio: mediaInput(b.content), ptt: true, mimetype: 'audio/ogg; codecs=opus' };
    else throw new HttpError(400, 'type inválido (text, image, video, audio)');
    return inst.send('status@broadcast', content, { statusJidList: jids });
  }, 201));

  const notSupported = wrap(async () => {
    throw new HttpError(400, 'Mensagens de lista/botões/modelo não são aceitas pelo WhatsApp em contas comuns. Envie como texto.');
  });
  app.post('/message/sendList/:instance', notSupported);
  app.post('/message/sendButtons/:instance', notSupported);
  app.post('/message/sendTemplate/:instance', notSupported);
}

// ----------------------------------------------------------------------
// Conversas
// ----------------------------------------------------------------------

function chatRoutes(app) {
  app.post('/chat/whatsappNumbers/:instance', wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const numbers = Array.isArray(req.body?.numbers) ? req.body.numbers : [];
    const out = [];
    for (const n of numbers) {
      const digits = onlyDigits(n);
      try {
        const jid = await inst.resolveJid(digits);
        out.push({ exists: true, jid, number: digits });
      } catch (e) {
        if (e instanceof HttpError && e.status === 400) out.push({ exists: false, jid: toJid(digits), number: digits });
        else throw e;
      }
    }
    return out;
  }));

  const markRead = wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const list = req.body?.readMessages ?? req.body?.read_messages ?? [];
    const keys = (Array.isArray(list) ? list : []).filter((k) => k?.id && k?.remoteJid)
      .map((k) => ({ remoteJid: toJid(k.remoteJid), id: k.id, fromMe: !!k.fromMe, participant: k.participant }));
    if (keys.length) await inst.ensureOpen().readMessages(keys);
    return { message: 'Read messages', read: 'success' };
  }, 201);
  app.post('/chat/markMessageAsRead/:instance', markRead);
  app.put('/chat/markMessageAsRead/:instance', markRead);

  const archive = wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const b = req.body ?? {};
    const key = b.lastMessage?.key ?? {};
    const jid = toJid(b.chat ?? key.remoteJid ?? '');
    if (!jid) throw new HttpError(400, 'chat/lastMessage.key.remoteJid obrigatório');
    const stored = key.id ? await store.getMessage(inst.name, key.id, jid) : null;
    const last = stored ?? { key: { remoteJid: jid, id: key.id, fromMe: !!key.fromMe }, messageTimestamp: Math.floor(Date.now() / 1000) };
    await inst.ensureOpen().chatModify({ archive: b.archive !== false, lastMessages: [last] }, jid);
    return { chatId: jid, archived: b.archive !== false };
  }, 201);
  app.post('/chat/archiveChat/:instance', archive);
  app.put('/chat/archiveChat/:instance', archive);

  app.delete('/chat/deleteMessageForEveryone/:instance', wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const b = req.body ?? {};
    if (!b.id || !b.remoteJid) throw new HttpError(400, 'id e remoteJid obrigatórios');
    const key = { remoteJid: toJid(b.remoteJid), id: b.id, fromMe: b.fromMe !== false, participant: b.participant };
    return inst.send(key.remoteJid, { delete: key });
  }, 201));

  const updateMessage = wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const b = req.body ?? {};
    if (!b.key?.id || !b.text) throw new HttpError(400, 'key.id e text obrigatórios');
    const jid = toJid(b.key.remoteJid ?? b.number);
    return inst.send(jid, { text: String(b.text), edit: { remoteJid: jid, id: b.key.id, fromMe: true } });
  }, 201);
  app.post('/chat/updateMessage/:instance', updateMessage);
  app.put('/chat/updateMessage/:instance', updateMessage);

  app.post('/chat/fetchProfilePictureUrl/:instance', wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const jid = toJid(param(req, 'number'));
    let url = null;
    try {
      url = await inst.ensureOpen().profilePictureUrl(jid, 'image');
    } catch {}
    return { wuid: jid, profilePictureUrl: url ?? null };
  }));

  const fetchProfile = wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const number = param(req, 'number');
    if (!number) throw new HttpError(400, 'number is required');
    const sock = inst.ensureOpen();
    let jid = toJid(number);
    let exists = true;
    if (isPn(jid)) {
      try {
        jid = await inst.resolveJid(jid);
      } catch (e) {
        if (e instanceof HttpError && e.status === 400) exists = false;
        else throw e;
      }
    }
    let picture = null;
    let status = null;
    let business = null;
    if (exists) {
      [picture, status, business] = await Promise.all([
        sock.profilePictureUrl(jid, 'image').catch(() => null),
        sock.fetchStatus(jid).catch(() => null),
        sock.getBusinessProfile(jid).catch(() => null),
      ]);
    }
    const st = Array.isArray(status) ? status[0]?.status : null;
    const contacts = await store.findContacts(inst.name, jid).catch(() => []);
    return {
      wuid: jid,
      name: contacts[0]?.notify ?? contacts[0]?.name ?? null,
      numberExists: exists,
      picture: picture ?? null,
      status: st ? { status: st.status ?? null, setAt: st.setAt ?? null } : null,
      isBusiness: !!business,
      email: business?.email ?? null,
      description: business?.description ?? null,
      website: business?.website?.[0] ?? null,
    };
  });
  app.post('/chat/fetchProfile/:instance', fetchProfile);
  app.get('/chat/fetchProfile/:instance', fetchProfile);

  const findContacts = wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const where = req.body?.where ?? {};
    const id = where.id ?? where.remoteJid ?? req.query.id ?? null;
    const rows = await store.findContacts(inst.name, id ? toJid(id) : null);
    return rows.map((r) => ({
      id: r.phone_jid || r.jid,
      remoteJid: r.phone_jid || r.jid,
      lid: r.lid,
      pushName: r.notify ?? r.name ?? null,
      name: r.name ?? null,
      profilePicUrl: r.img_url ?? null,
      updatedAt: r.updated_at,
      instanceId: inst.name,
    }));
  });
  app.post('/chat/findContacts/:instance', findContacts);
  app.get('/chat/findContacts/:instance', findContacts);

  const findChats = wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const rows = await store.findChats(inst.name, req.body?.limit ?? req.query.limit);
    const out = [];
    for (const r of rows) {
      const last = r.msg ? await toEvolutionMessage(r.msg, inst.name, null) : null;
      out.push({
        id: r.remote_jid,
        remoteJid: r.remote_jid,
        pushName: r.notify ?? r.name ?? (!r.from_me ? r.push_name : null) ?? null,
        name: r.name ?? r.notify ?? null,
        profilePicUrl: r.img_url ?? null,
        updatedAt: new Date(Number(r.msg_ts) * 1000).toISOString(),
        unreadCount: 0,
        isGroup: isGroupJid(r.remote_jid),
        lastMessage: last,
        instanceId: inst.name,
      });
    }
    return out;
  });
  app.post('/chat/findChats/:instance', findChats);
  app.get('/chat/findChats/:instance', findChats);

  const findMessages = wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const b = req.body ?? {};
    const k = b.where?.key ?? {};
    let remoteJids = [];
    if (k.remoteJid) {
      const jid = toJid(k.remoteJid);
      remoteJids = [jid];
      // A conversa pode estar guardada pelo LID e pelo número
      if (isPn(jid)) {
        const rows = await store.findContacts(inst.name, jid).catch(() => []);
        for (const r of rows) if (r.lid) remoteJids.push(r.lid);
      } else if (isLid(jid)) {
        const pn = await inst.lidToPn(jid);
        if (pn) remoteJids.push(pn);
      }
    }
    const limit = Number(b.offset ?? b.limit ?? 50) || 50;
    const { total, records } = await store.findMessages(inst.name, {
      remoteJids, id: k.id, fromMe: k.fromMe === undefined ? undefined : !!k.fromMe,
    }, b.page ?? 1, limit);
    const out = [];
    for (const m of records) out.push(await toEvolutionMessage(m, inst.name, (lid) => inst.lidToPn(lid), { id: m.key?.id }));
    return { messages: { total, pages: Math.ceil(total / limit), currentPage: Number(b.page ?? 1), records: out } };
  });
  app.post('/chat/findMessages/:instance', findMessages);

  app.post('/chat/findStatusMessage/:instance', wrap(async () => []));

  app.post('/chat/getBase64FromMediaMessage/:instance', wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const m = req.body?.message ?? {};
    const key = m.key ?? {};
    if (!key.id) throw new HttpError(400, 'message.key.id obrigatório');
    let msg = await store.getMessage(inst.name, key.id, key.remoteJid ? toJid(key.remoteJid) : null);
    if (!msg && m.message) msg = { key, message: m.message };
    if (!msg) throw new HttpError(404, 'Mensagem não encontrada');
    const media = await downloadMedia(inst.sock, msg);
    if (!media) throw new HttpError(400, 'A mensagem não tem mídia');
    return {
      mediaType: media.type.replace('Message', ''),
      fileName: media.fileName,
      caption: media.caption,
      size: media.size,
      mimetype: media.mimetype,
      base64: media.buffer.toString('base64'),
      buffer: null,
    };
  }, 201));

  app.post('/chat/sendPresence/:instance', wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const b = req.body ?? {};
    const jid = await inst.resolveJid(requireNumber(b));
    await inst.ensureOpen().sendPresenceUpdate(String(b.presence ?? 'composing'), jid);
    return { presence: b.presence ?? 'composing' };
  }, 201));
}

// ----------------------------------------------------------------------
// Grupos
// ----------------------------------------------------------------------

const groupJidOf = (req) => {
  const g = param(req, 'groupJid');
  if (!g) throw new HttpError(400, 'groupJid is required');
  const jid = toJid(g);
  if (!isGroupJid(jid)) throw new HttpError(400, 'groupJid inválido');
  return jid;
};

async function participantJids(inst, list) {
  return inst.resolveJids(list);
}

function groupRoutes(app) {
  const lidFn = (inst) => (lid) => inst.lidToPn(lid);

  app.post('/group/create/:instance', wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const b = req.body ?? {};
    if (!b.subject) throw new HttpError(400, 'subject is required');
    const sock = inst.ensureOpen();
    const participants = await participantJids(inst, b.participants ?? []);
    const meta = await sock.groupCreate(String(b.subject), participants);
    if (b.description) await sock.groupUpdateDescription(meta.id, String(b.description)).catch(() => {});
    inst.groupCache.set(meta.id, meta);
    return toEvolutionGroup(meta, true, lidFn(inst));
  }, 201));

  const updateSubject = wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const jid = groupJidOf(req);
    await inst.ensureOpen().groupUpdateSubject(jid, String(param(req, 'subject') ?? ''));
    inst.groupCache.del(jid);
    return { update: 'success' };
  }, 201);
  app.put('/group/updateGroupSubject/:instance', updateSubject);
  app.post('/group/updateGroupSubject/:instance', updateSubject);

  const updateDesc = wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const jid = groupJidOf(req);
    await inst.ensureOpen().groupUpdateDescription(jid, String(param(req, 'description') ?? ''));
    inst.groupCache.del(jid);
    return { update: 'success' };
  }, 201);
  app.put('/group/updateGroupDescription/:instance', updateDesc);
  app.post('/group/updateGroupDescription/:instance', updateDesc);

  const updatePicture = wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const jid = groupJidOf(req);
    await inst.ensureOpen().updateProfilePicture(jid, mediaInput(param(req, 'image')));
    return { update: 'success' };
  }, 201);
  app.put('/group/updateGroupPicture/:instance', updatePicture);
  app.post('/group/updateGroupPicture/:instance', updatePicture);

  app.get('/group/findGroupInfos/:instance', wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const jid = groupJidOf(req);
    const meta = await inst.groupMeta(jid, true);
    const pic = await inst.sock?.profilePictureUrl(jid, 'image').catch(() => null);
    return toEvolutionGroup(meta, true, lidFn(inst), pic ?? null);
  }));

  app.get('/group/fetchAllGroups/:instance', wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const withParts = truthy(req.query.getParticipants ?? req.query.getMembers);
    const all = await inst.ensureOpen().groupFetchAllParticipating();
    const out = [];
    for (const meta of Object.values(all ?? {})) {
      inst.groupCache.set(meta.id, meta);
      out.push(await toEvolutionGroup(meta, withParts, lidFn(inst)));
    }
    return out;
  }));

  app.get('/group/participants/:instance', wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const jid = groupJidOf(req);
    const meta = await inst.groupMeta(jid, true);
    const g = await toEvolutionGroup(meta, true, lidFn(inst));
    return { participants: g.participants };
  }));

  app.get('/group/inviteCode/:instance', wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const code = await inst.ensureOpen().groupInviteCode(groupJidOf(req));
    return { inviteUrl: `https://chat.whatsapp.com/${code}`, inviteCode: code };
  }));

  app.get('/group/inviteInfo/:instance', wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const code = String(param(req, 'inviteCode') ?? '').replace(/^https?:\/\/chat\.whatsapp\.com\//i, '');
    if (!code) throw new HttpError(400, 'inviteCode is required');
    const meta = await inst.ensureOpen().groupGetInviteInfo(code);
    return toEvolutionGroup(meta, true, lidFn(inst));
  }));

  app.get('/group/acceptInviteCode/:instance', wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const code = String(param(req, 'inviteCode') ?? '').replace(/^https?:\/\/chat\.whatsapp\.com\//i, '');
    if (!code) throw new HttpError(400, 'inviteCode is required');
    const groupJid = await inst.ensureOpen().groupAcceptInvite(code);
    return { accepted: true, groupJid };
  }));

  app.post('/group/sendInvite/:instance', wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const b = req.body ?? {};
    const jid = groupJidOf(req);
    const code = await inst.ensureOpen().groupInviteCode(jid);
    const inviteUrl = `https://chat.whatsapp.com/${code}`;
    const text = (b.description ? String(b.description) + '\n\n' : '') + inviteUrl;
    const numbers = Array.isArray(b.numbers) ? b.numbers : [];
    for (const n of numbers) await inst.send(n, { text });
    return { send: true, inviteUrl };
  }, 201));

  const revoke = wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const code = await inst.ensureOpen().groupRevokeInvite(groupJidOf(req));
    return { revoked: true, inviteCode: code };
  }, 201);
  app.put('/group/revokeInviteCode/:instance', revoke);
  app.post('/group/revokeInviteCode/:instance', revoke);

  // Aceita /group/updateParticipant/{inst} e /group/updateParticipant/{inst}/{ação}
  const updateParticipant = wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const jid = groupJidOf(req);
    const action = String(req.params.action ?? param(req, 'action') ?? '').toLowerCase();
    if (!['add', 'remove', 'promote', 'demote'].includes(action)) throw new HttpError(400, 'action inválida (add, remove, promote, demote)');
    const parts = await participantJids(inst, param(req, 'participants') ?? []);
    if (!parts.length) throw new HttpError(400, 'participants is required');
    const res = await inst.ensureOpen().groupParticipantsUpdate(jid, parts, action);
    inst.groupCache.del(jid);
    return { updateParticipants: (res ?? []).map((r) => ({ status: String(r.status), jid: r.jid, content: r.content ?? null })) };
  }, 201);
  for (const p of ['/group/updateParticipant/:instance', '/group/updateParticipant/:instance/:action']) {
    app.post(p, updateParticipant);
    app.put(p, updateParticipant);
  }

  const updateSetting = wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const jid = groupJidOf(req);
    const action = String(param(req, 'action') ?? '');
    if (!['announcement', 'not_announcement', 'locked', 'unlocked'].includes(action)) {
      throw new HttpError(400, 'action inválida (announcement, not_announcement, locked, unlocked)');
    }
    await inst.ensureOpen().groupSettingUpdate(jid, action);
    inst.groupCache.del(jid);
    return { updateSetting: true };
  }, 201);
  for (const p of ['/group/updateSetting/:instance', '/group/setting/:instance']) {
    app.post(p, updateSetting);
    app.put(p, updateSetting);
  }

  const ephemeral = wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    await inst.ensureOpen().groupToggleEphemeral(groupJidOf(req), Number(param(req, 'expiration')) || 0);
    return { success: true };
  }, 201);
  app.put('/group/toggleEphemeral/:instance', ephemeral);
  app.post('/group/toggleEphemeral/:instance', ephemeral);

  app.delete('/group/leaveGroup/:instance', wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const jid = groupJidOf(req);
    await inst.ensureOpen().groupLeave(jid);
    inst.groupCache.del(jid);
    return { groupJid: jid, leave: true };
  }));
}

// ----------------------------------------------------------------------
// Monitor de conexão real (mesmo contrato do evo-watchdog)
// ----------------------------------------------------------------------

function monitorRoutes(app) {
  app.get('/status', wrap(async () => {
    const list = manager.list().map((i) => i.statusInfo());
    const summary = {};
    for (const s of list) summary[s.verdict] = (summary[s.verdict] ?? 0) + 1;
    return {
      generatedAt: new Date().toISOString(),
      thresholds: { silentSec: config.monitor.silentSec, warmupSec: config.monitor.warmupSec },
      summary,
      instances: list,
    };
  }));

  app.get('/status/:instance', wrap(async (req) => {
    const inst = manager.get(req.params.instance);
    if (!inst) return { __status: 404, body: { name: req.params.instance, verdict: 'unknown', error: 'instância não encontrada' } };
    return inst.statusInfo();
  }));

  // Histórico para o gráfico do piloto (72 h por padrão)
  app.get('/status/:instance/history', wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const hours = Math.max(1, Math.min(24 * 30, Number(req.query.hours) || 72));
    const rows = await query(
      `SELECT logged_at, socket, verdict, inbound_silent_min, last_event_sec FROM baileys_status_log
       WHERE instance = ? AND logged_at > (UTC_TIMESTAMP() - INTERVAL ${hours} HOUR) ORDER BY logged_at ASC`,
      [inst.name],
    );
    return {
      instance: inst.name,
      hours,
      intervalMin: config.monitor.logIntervalMin,
      points: rows.map((r) => ({
        at: String(r.logged_at).replace(' ', 'T') + 'Z',
        socket: r.socket,
        verdict: r.verdict,
        inboundSilentMinutes: r.inbound_silent_min,
        lastEventSec: r.last_event_sec,
      })),
    };
  }));

  // Últimas linhas do log (para a tela do sistema; sem acesso ao servidor)
  app.get('/service/logs', wrap(async (req) => {
    const n = Math.max(1, Math.min(400, Number(req.query.lines) || 150));
    return { lines: recentLogs.slice(-n) };
  }));

  app.post('/heal/:instance', wrap(async (req) => {
    const inst = manager.get(req.params.instance);
    if (!inst) return { __status: 404, body: { ok: false, reason: 'instância não encontrada' } };
    const r = await inst.heal(truthy(req.query.force ?? req.body?.force));
    return { __status: r.status, body: r.body };
  }));

  // Sincronizar: reenvia ao webhook mensagens já recebidas (guardadas no banco)
  app.post('/redispatch/:instance', wrap(async (req) => {
    const inst = manager.must(req.params.instance);
    const b = req.body ?? {};
    const parseUtc = (s) => {
      const t = Date.parse(String(s ?? '').trim().replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(String(s ?? '')) ? '' : 'Z'));
      return Number.isFinite(t) ? Math.floor(t / 1000) : null;
    };
    const from = parseUtc(b.from);
    const to = parseUtc(b.to);
    if (from === null || to === null || from >= to) throw new HttpError(400, 'from/to inválidos (UTC "Y-m-d H:i:s")');
    if (to - from > 7 * 86400) throw new HttpError(400, 'janela máxima de 7 dias');
    const types = Array.isArray(b.types) ? b.types.map(String).filter(Boolean) : [];
    const limit = Math.max(1, Math.min(500, Number(b.limit) || 200));

    if (truthy(b.dryRun)) {
      const total = await countInRangeSafe(inst.name, from, to, types);
      const { rows, more } = await store.messagesInRange(inst.name, from, to, types, Math.min(limit, 200));
      return {
        wouldRedispatch: Math.min(total, limit),
        total,
        moreAvailable: total > limit || more,
        sample: rows.map((r) => ({ id: r.msg.key?.id, type: r.type, remoteJid: r.msg.key?.remoteJid, timestamp: r.ts, fromMe: !!r.msg.key?.fromMe })),
      };
    }

    const { rows, more } = await store.messagesInRange(inst.name, from, to, types, limit);
    let redispatched = 0;
    const failures = [];
    let i = 0;
    const worker = async () => {
      while (i < rows.length) {
        const r = rows[i++];
        const data = await toEvolutionMessage(r.msg, inst.name, (lid) => inst.lidToPn(lid));
        const res = await inst.dispatcher.deliverNow('messages.upsert', data, 2);
        if (res.ok) redispatched++;
        else failures.push({ id: r.msg.key?.id, status: res.error || 'HTTP ' + res.status });
      }
    };
    await Promise.all([worker(), worker(), worker(), worker()]);
    return { redispatched, failed: failures.length, failures, moreAvailable: more };
  }));
}

async function countInRangeSafe(instance, from, to, types) {
  try {
    return await store.countInRange(instance, from, to, types);
  } catch {
    return 0;
  }
}

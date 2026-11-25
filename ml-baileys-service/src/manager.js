// Registro das instâncias em memória + tabela baileys_instances.
import { exec, query } from './db.js';
import { logger } from './logger.js';
import { deleteSession, hasLogin } from './authState.js';
import { Instance } from './instance.js';
import * as store from './store.js';
import { normalizeWebhookConfig } from './webhook.js';
import { HttpError, notFoundInstance, randomToken, sleep } from './util.js';

/** @type {Map<string, Instance>} */
export const instances = new Map();

const NAME_RE = /^[A-Za-z0-9_.\-]{1,100}$/;

export function get(name) {
  return instances.get(String(name ?? '')) ?? null;
}

export function must(name) {
  const inst = get(name);
  if (!inst) throw notFoundInstance(name);
  return inst;
}

export const list = () => [...instances.values()];

/** Boot: carrega as instâncias e reconecta as que têm sessão salva. */
export async function loadAll() {
  const rows = await query('SELECT * FROM baileys_instances ORDER BY created_at ASC');
  for (const row of rows) instances.set(row.name, new Instance(row));
  logger.info({ total: rows.length }, 'instâncias carregadas');
  // Reconecta uma de cada vez (evita rajada de conexões do mesmo IP)
  (async () => {
    for (const inst of instances.values()) {
      if (await hasLogin(inst.name).catch(() => false)) {
        inst.start().catch((e) => inst.log.error({ err: e.message }, 'falha ao iniciar'));
        await sleep(1500);
      }
    }
  })();
}

/** Opções de comportamento aceitas na criação (mesmos nomes da Evolution). */
function settingsFrom(body, current = {}) {
  const s = { ...current };
  for (const k of ['rejectCall', 'groupsIgnore', 'alwaysOnline', 'readMessages', 'readStatus']) {
    if (body[k] !== undefined) s[k] = !!body[k];
  }
  if (body.msgCall !== undefined) s.msgCall = String(body.msgCall ?? '');
  return s;
}

/** POST /instance/create */
export async function create(body) {
  const name = String(body.instanceName ?? '').trim();
  if (!NAME_RE.test(name)) throw new HttpError(400, 'instanceName inválido (use letras, números, ponto, hífen ou sublinhado)');
  if (instances.has(name)) throw new HttpError(403, `This name "${name}" is already in use.`);
  const token = String(body.token ?? '').trim() || randomToken();

  let webhook = null;
  const wh = body.webhook && typeof body.webhook === 'object' ? body.webhook : null;
  const whUrl = wh?.url ?? body.webhookUrl ?? body.webhook_url ?? (typeof body.webhook === 'string' ? body.webhook : '');
  if (whUrl) {
    webhook = normalizeWebhookConfig({
      url: whUrl,
      enabled: wh?.enabled ?? true,
      byEvents: wh?.byEvents ?? body.webhook_by_events ?? body.webhookByEvents,
      base64: wh?.base64 ?? body.webhook_base64 ?? body.webhookBase64,
      events: wh?.events ?? body.events,
      headers: wh?.headers,
    });
  }
  const settings = settingsFrom(body);
  await deleteSession(name).catch(() => {});
  await exec('INSERT INTO baileys_instances (name, token, webhook, settings) VALUES (?, ?, ?, ?)', [
    name, token, webhook ? JSON.stringify(webhook) : null, JSON.stringify(settings),
  ]);
  const [row] = await query('SELECT * FROM baileys_instances WHERE name = ?', [name]);
  const inst = new Instance(row);
  instances.set(name, inst);
  logger.info({ instance: name }, 'instância criada');

  let qrcode = null;
  if (body.qrcode === true || body.qrcode === 'true' || body.number) {
    try {
      qrcode = await inst.connect(body.number ? String(body.number) : '');
    } catch (e) {
      inst.log.warn({ err: e.message }, 'falha ao gerar QR Code na criação');
    }
  }
  const out = {
    instance: {
      instanceName: name,
      instanceId: name,
      integration: 'WHATSAPP-BAILEYS',
      webhookWaBusiness: null,
      accessTokenWaBusiness: '',
      status: inst.state,
    },
    hash: token,
    webhook: inst.webhookView(),
    settings: inst.settingsView(),
  };
  // Só manda "qrcode" quando há QR/código (as telas testam isset(qrcode))
  if (qrcode && (qrcode.base64 || qrcode.code || qrcode.pairingCode)) out.qrcode = qrcode;
  return out;
}

export async function updateSettings(inst, body) {
  inst.settings = settingsFrom(body, inst.settings ?? {});
  await inst.saveMeta();
  return inst.settingsView();
}

/** DELETE /instance/delete */
export async function remove(name) {
  const inst = must(name);
  await inst.destroy();
  instances.delete(name);
  await deleteSession(name).catch(() => {});
  await store.purgeInstance(name).catch(() => {});
  await exec('DELETE FROM baileys_instances WHERE name = ?', [name]);
  logger.info({ instance: name }, 'instância apagada');
}

export async function shutdownAll() {
  await Promise.all(list().map((i) => i.shutdown().catch(() => {})));
}

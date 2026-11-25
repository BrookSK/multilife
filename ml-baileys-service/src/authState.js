// Sessão do WhatsApp num único JSON {creds, keys} por instância (padrão do cx-crm).
//
// - A sessão inteira é gravada de uma vez (UPDATE atômico da linha): não existe
//   gravação parcial por chave, que era a origem da corrupção vista na Evolution.
// - Sem Redis e sem cache externo: no boot lê o JSON do banco e reconecta.
// - creds.update grava na hora; mudanças de chaves (muito frequentes) são
//   agrupadas em no máximo 1 gravação a cada KEYS_FLUSH_MS.
import { BufferJSON, initAuthCreds, proto } from 'baileys';
import { exec, query } from './db.js';
import { logger } from './logger.js';

// Tipo de chave do Baileys -> nome do grupo no JSON (KEY_MAP do cx-crm).
const KEY_MAP = {
  'pre-key': 'preKeys',
  session: 'sessions',
  'sender-key': 'senderKeys',
  'sender-key-memory': 'senderKeyMemory',
  'app-state-sync-key': 'appStateSyncKeys',
  'app-state-sync-version': 'appStateVersions',
  'lid-mapping': 'lidMappings',
  'device-list': 'deviceList',
  tctoken: 'tcTokens',
};

const KEYS_FLUSH_MS = 1000;

export async function hasSession(instance) {
  const rows = await query('SELECT 1 FROM baileys_session WHERE instance = ? LIMIT 1', [instance]);
  return rows.length > 0;
}

/** Sessão com aparelho pareado (QR Code já lido)? Sem isso, reconectar só geraria QR. */
export async function hasLogin(instance) {
  const rows = await query('SELECT session FROM baileys_session WHERE instance = ? LIMIT 1', [instance]);
  if (!rows.length) return false;
  try {
    const parsed = JSON.parse(rows[0].session, BufferJSON.reviver);
    return !!parsed?.creds?.me?.id;
  } catch {
    return false;
  }
}

export async function deleteSession(instance) {
  await exec('DELETE FROM baileys_session WHERE instance = ?', [instance]);
}

/**
 * @returns {Promise<{state: import('baileys').AuthenticationState, saveCreds: () => Promise<void>, flush: () => Promise<void>, discard: () => void}>}
 */
export async function useMysqlAuthState(instance) {
  const log = logger.child({ instance, mod: 'auth' });
  const rows = await query('SELECT session FROM baileys_session WHERE instance = ? LIMIT 1', [instance]);

  let creds;
  let keys = {};
  if (rows.length) {
    try {
      const parsed = JSON.parse(rows[0].session, BufferJSON.reviver);
      creds = parsed.creds;
      keys = parsed.keys && typeof parsed.keys === 'object' ? parsed.keys : {};
    } catch (e) {
      log.error({ err: e.message }, 'sessão ilegível no banco: começando do zero (vai pedir QR Code)');
    }
  }
  if (!creds) creds = initAuthCreds();

  let discarded = false;
  let chain = Promise.resolve();
  let timer = null;

  const write = () => {
    chain = chain
      .then(async () => {
        if (discarded) return;
        const json = JSON.stringify({ creds, keys }, BufferJSON.replacer);
        await exec(
          'INSERT INTO baileys_session (instance, session, updated_at) VALUES (?, ?, NOW()) '
            + 'ON DUPLICATE KEY UPDATE session = VALUES(session), updated_at = NOW()',
          [instance, json],
        );
      })
      .catch((e) => log.error({ err: e.message }, 'falha ao gravar a sessão'));
    return chain;
  };

  const scheduleWrite = () => {
    if (timer) return;
    timer = setTimeout(() => {
      timer = null;
      write();
    }, KEYS_FLUSH_MS);
  };

  const flush = async () => {
    if (timer) {
      clearTimeout(timer);
      timer = null;
    }
    await write();
  };

  const state = {
    creds,
    keys: {
      get: async (type, ids) => {
        const bucket = keys[KEY_MAP[type] ?? type] ?? {};
        const out = {};
        for (const id of ids) {
          let value = bucket[id];
          if (value !== undefined && value !== null && type === 'app-state-sync-key') {
            value = proto.Message.AppStateSyncKeyData.fromObject(value);
          }
          if (value !== undefined && value !== null) out[id] = value;
        }
        return out;
      },
      set: async (data) => {
        for (const type of Object.keys(data)) {
          const name = KEY_MAP[type] ?? type;
          const bucket = keys[name] ?? (keys[name] = {});
          for (const id of Object.keys(data[type] ?? {})) {
            const value = data[type][id];
            if (value === null || value === undefined) delete bucket[id];
            else bucket[id] = value;
          }
        }
        scheduleWrite();
      },
      clear: async () => {
        keys = {};
        scheduleWrite();
      },
    },
  };

  return {
    state,
    // creds é o mesmo objeto que o Baileys altera: basta gravar.
    saveCreds: () => flush(),
    flush,
    /** Sessão apagada (logout): impede gravações pendentes de recriá-la. */
    discard: () => {
      discarded = true;
      if (timer) clearTimeout(timer);
      timer = null;
    },
  };
}

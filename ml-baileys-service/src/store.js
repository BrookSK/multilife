// Guarda de mensagens e contatos no MySQL (o Baileys não guarda nada sozinho).
//
// Usada para: findMessages/findChats/findContacts (mesmas rotas da Evolution),
// download de mídia depois (getBase64FromMediaMessage), reenvio de mensagens
// que o WhatsApp pede de novo (getMessage) e o "Sincronizar" (redispatch).
import { BufferJSON } from 'baileys';
import { exec, query } from './db.js';
import { logger } from './logger.js';
import { messageTypeOf, tsToNumber } from './util.js';

const log = logger.child({ mod: 'store' });

const cut = (s, n) => (s === undefined || s === null || s === '' ? null : String(s).slice(0, n));

/** Grava (ou atualiza) uma mensagem. Nunca lança erro. */
export async function saveMessage(instance, msg) {
  try {
    const key = msg?.key;
    if (!key?.id || !key.remoteJid) return;
    const data = JSON.stringify(msg, BufferJSON.replacer);
    if (data.length > 15 * 1024 * 1024) return; // limite do MEDIUMTEXT (folga)
    await exec(
      `INSERT INTO baileys_messages (instance, remote_jid, msg_id, from_me, participant, msg_type, msg_ts, push_name, data)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE data = VALUES(data), msg_type = VALUES(msg_type),
         push_name = COALESCE(VALUES(push_name), push_name), msg_ts = GREATEST(msg_ts, VALUES(msg_ts))`,
      [
        instance,
        cut(key.remoteJid, 150),
        cut(key.id, 150),
        key.fromMe ? 1 : 0,
        cut(key.participant, 150),
        cut(messageTypeOf(msg.message), 60),
        tsToNumber(msg.messageTimestamp) || Math.floor(Date.now() / 1000),
        cut(msg.pushName, 255),
        data,
      ],
    );
  } catch (e) {
    log.warn({ instance, err: e.message }, 'falha ao guardar mensagem');
  }
}

const parse = (row) => {
  try {
    return JSON.parse(row.data, BufferJSON.reviver);
  } catch {
    return null;
  }
};

/** Mensagem pelo id (e conversa, se conhecida). */
export async function getMessage(instance, id, remoteJid = null) {
  if (!id) return null;
  const rows = remoteJid
    ? await query('SELECT data FROM baileys_messages WHERE instance = ? AND remote_jid = ? AND msg_id = ? LIMIT 1', [instance, remoteJid, id])
    : await query('SELECT data FROM baileys_messages WHERE instance = ? AND msg_id = ? ORDER BY msg_ts DESC LIMIT 1', [instance, id]);
  if (!rows.length && remoteJid) return getMessage(instance, id, null);
  return rows.length ? parse(rows[0]) : null;
}

/**
 * findMessages: filtros por conversa/id/fromMe, mais nova primeiro.
 * @returns {Promise<{total:number, records:any[]}>}
 */
export async function findMessages(instance, { remoteJid, remoteJids, id, fromMe } = {}, page = 1, limit = 50) {
  const where = ['instance = ?'];
  const params = [instance];
  const jids = (remoteJids ?? (remoteJid ? [remoteJid] : [])).filter(Boolean);
  if (jids.length) {
    where.push(`remote_jid IN (${jids.map(() => '?').join(',')})`);
    params.push(...jids);
  }
  if (id) {
    where.push('msg_id = ?');
    params.push(id);
  }
  if (fromMe === true || fromMe === false) {
    where.push('from_me = ?');
    params.push(fromMe ? 1 : 0);
  }
  const w = where.join(' AND ');
  const [{ c }] = await query(`SELECT COUNT(*) AS c FROM baileys_messages WHERE ${w}`, params);
  const lim = Math.max(1, Math.min(500, Number(limit) || 50));
  const off = Math.max(0, (Math.max(1, Number(page) || 1) - 1) * lim);
  const rows = await query(`SELECT data FROM baileys_messages WHERE ${w} ORDER BY msg_ts DESC LIMIT ${lim} OFFSET ${off}`, params);
  return { total: Number(c) || 0, records: rows.map(parse).filter(Boolean) };
}

/** Mensagens de um período (segundos unix), mais antiga primeiro. */
export async function messagesInRange(instance, fromTs, toTs, types = [], limit = 200) {
  const params = [instance, fromTs, toTs];
  let typeSql = '';
  if (types.length) {
    typeSql = ` AND msg_type IN (${types.map(() => '?').join(',')})`;
    params.push(...types);
  }
  const lim = Math.max(1, Math.min(1000, Number(limit) || 200));
  const rows = await query(
    `SELECT msg_type, msg_ts, data FROM baileys_messages
     WHERE instance = ? AND msg_ts BETWEEN ? AND ?${typeSql} AND remote_jid <> 'status@broadcast'
     ORDER BY msg_ts ASC LIMIT ${lim + 1}`,
    params,
  );
  return {
    more: rows.length > lim,
    rows: rows.slice(0, lim).map((r) => ({ type: r.msg_type, ts: Number(r.msg_ts), msg: parse(r) })).filter((r) => r.msg),
  };
}

export async function countInRange(instance, fromTs, toTs, types = []) {
  const params = [instance, fromTs, toTs];
  let typeSql = '';
  if (types.length) {
    typeSql = ` AND msg_type IN (${types.map(() => '?').join(',')})`;
    params.push(...types);
  }
  const [{ c }] = await query(
    `SELECT COUNT(*) AS c FROM baileys_messages WHERE instance = ? AND msg_ts BETWEEN ? AND ?${typeSql} AND remote_jid <> 'status@broadcast'`,
    params,
  );
  return Number(c) || 0;
}

/** Conversas (última mensagem de cada uma). */
export async function findChats(instance, limit = 500) {
  const lim = Math.max(1, Math.min(2000, Number(limit) || 500));
  return query(
    `SELECT m.remote_jid, m.msg_ts, m.push_name, m.from_me, m.data, c.name, c.notify, c.img_url
     FROM baileys_messages m
     INNER JOIN (SELECT remote_jid, MAX(msg_ts) AS mts FROM baileys_messages WHERE instance = ? GROUP BY remote_jid) x
       ON x.remote_jid = m.remote_jid AND x.mts = m.msg_ts
     LEFT JOIN baileys_contacts c ON c.instance = m.instance AND c.jid = m.remote_jid
     WHERE m.instance = ? AND m.remote_jid <> 'status@broadcast'
     ORDER BY m.msg_ts DESC LIMIT ${lim}`,
    [instance, instance],
  ).then((rows) => {
    const seen = new Set();
    return rows.filter((r) => (seen.has(r.remote_jid) ? false : seen.add(r.remote_jid))).map((r) => ({ ...r, msg: parse(r) }));
  });
}

/** Grava contatos (contacts.upsert/update e pushName das mensagens). */
export async function saveContacts(instance, contacts) {
  for (const c of contacts) {
    const jid = c?.id;
    if (!jid) continue;
    try {
      await exec(
        `INSERT INTO baileys_contacts (instance, jid, lid, phone_jid, name, notify, img_url)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           lid = COALESCE(VALUES(lid), lid),
           phone_jid = COALESCE(VALUES(phone_jid), phone_jid),
           name = COALESCE(VALUES(name), name),
           notify = COALESCE(VALUES(notify), notify),
           img_url = COALESCE(VALUES(img_url), img_url)`,
        [
          instance,
          cut(jid, 150),
          cut(c.lid, 150),
          cut(c.phoneNumber, 150),
          cut(c.name, 255),
          cut(c.notify ?? c.verifiedName, 255),
          c.imgUrl && c.imgUrl !== 'changed' ? cut(c.imgUrl, 1000) : null,
        ],
      );
    } catch (e) {
      log.warn({ instance, err: e.message }, 'falha ao guardar contato');
    }
  }
}

export async function findContacts(instance, id = null) {
  if (id) {
    return query('SELECT * FROM baileys_contacts WHERE instance = ? AND (jid = ? OR phone_jid = ? OR lid = ?) LIMIT 50', [instance, id, id, id]);
  }
  return query('SELECT * FROM baileys_contacts WHERE instance = ? ORDER BY updated_at DESC LIMIT 5000', [instance]);
}

/** Número (PN) de um LID pelos contatos guardados. */
export async function pnForLid(instance, lid) {
  const rows = await query('SELECT phone_jid FROM baileys_contacts WHERE instance = ? AND (lid = ? OR jid = ?) AND phone_jid IS NOT NULL LIMIT 1', [instance, lid, lid]);
  return rows[0]?.phone_jid ?? null;
}

/** Apaga tudo de uma instância (DELETE /instance/delete). */
export async function purgeInstance(instance) {
  await exec('DELETE FROM baileys_messages WHERE instance = ?', [instance]);
  await exec('DELETE FROM baileys_contacts WHERE instance = ?', [instance]);
  await exec('DELETE FROM baileys_status_log WHERE instance = ?', [instance]);
}

/**
 * Limpeza periódica. 0 dias = não apaga nada (padrão das mensagens: guardar
 * para sempre; só apaga se o sistema configurar um prazo).
 */
export async function cleanup(messageDays, statusDays) {
  const md = Math.floor(Number(messageDays) || 0);
  if (md > 0) {
    // Em lotes, para não travar a tabela. Pela data da mensagem no WhatsApp.
    const cut = Math.floor(Date.now() / 1000) - md * 86400;
    for (let i = 0; i < 50; i++) {
      const r = await exec('DELETE FROM baileys_messages WHERE msg_ts < ? LIMIT 5000', [cut]);
      if (!r.affectedRows || r.affectedRows < 5000) break;
    }
  }
  const sd = Math.floor(Number(statusDays) || 0);
  if (sd > 0) {
    await exec(`DELETE FROM baileys_status_log WHERE logged_at < (UTC_TIMESTAMP() - INTERVAL ${sd} DAY)`);
  }
}

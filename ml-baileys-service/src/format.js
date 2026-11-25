// Conversão dos objetos do Baileys para o formato que a Evolution v2 entrega
// (webhook e respostas da API). O PHP do Multilife lê exatamente esses campos.
import { proto } from 'baileys';
import { isLid, isPn, messageTypeOf, toPlain, tsToNumber } from './util.js';

const STATUS_NAMES = ['ERROR', 'PENDING', 'SERVER_ACK', 'DELIVERY_ACK', 'READ', 'PLAYED'];

export const statusName = (s) => {
  if (typeof s === 'string') return s;
  return STATUS_NAMES[Number(s)] ?? 'PENDING';
};

/**
 * Chave da mensagem com os campos de número real que a Evolution 2.3 manda:
 * remoteJidAlt / senderPn / senderLid / participantPn / participantAlt.
 * $lidToPn: função async (lid) => pn|null para completar o que faltar.
 */
export async function enrichKey(key, lidToPn) {
  // Cópia simples: o Baileys põe remoteJidAlt/participantAlt direto no objeto
  // (campos fora do protobuf, que o toJSON descartaria).
  const k = {};
  for (const [name, v] of Object.entries(key ?? {})) {
    if (v !== undefined && v !== null && typeof v !== 'function') k[name] = typeof v === 'object' ? toPlain(v) : v;
  }
  const remote = k.remoteJid ?? '';
  let alt = k.remoteJidAlt ?? '';
  if (isLid(remote) && !isPn(alt) && lidToPn) {
    const pn = await lidToPn(remote).catch(() => null);
    if (pn) alt = k.remoteJidAlt = pn;
  }
  if (isLid(remote) && isPn(alt)) {
    k.senderPn = alt;
    k.senderLid = remote;
  } else if (isPn(remote) && isLid(alt)) {
    k.senderLid = alt;
    k.senderPn = remote;
  }
  if (k.participant) {
    let pAlt = k.participantAlt ?? '';
    if (isLid(k.participant) && !isPn(pAlt) && lidToPn) {
      const pn = await lidToPn(k.participant).catch(() => null);
      if (pn) pAlt = k.participantAlt = pn;
    }
    if (isLid(k.participant) && isPn(pAlt)) {
      k.participantPn = pAlt;
      k.participantLid = k.participant;
    } else if (isPn(k.participant)) {
      k.participantPn = k.participant;
      if (isLid(pAlt)) k.participantLid = pAlt;
    }
  }
  return k;
}

/** contextInfo da mensagem (a Evolution repete no nível de cima). */
function contextInfoOf(message) {
  if (!message) return null;
  for (const v of Object.values(message)) {
    if (v && typeof v === 'object' && v.contextInfo) return v.contextInfo;
  }
  return null;
}

/** WAMessage -> registro da Evolution (webhook messages.upsert e findMessages). */
export async function toEvolutionMessage(msg, instanceName, lidToPn, extra = {}) {
  const message = toPlain(msg.message ?? null);
  return {
    key: await enrichKey(msg.key ?? {}, lidToPn),
    pushName: msg.pushName ?? null,
    status: statusName(msg.status ?? (msg.key?.fromMe ? 2 : 3)),
    message,
    contextInfo: toPlain(contextInfoOf(msg.message)) ?? null,
    messageType: messageTypeOf(msg.message),
    messageTimestamp: tsToNumber(msg.messageTimestamp),
    instanceId: instanceName,
    source: 'baileys',
    ...extra,
  };
}

/** Participante de grupo -> {id, jid, lid, phoneNumber, admin} (id = número real quando conhecido). */
export async function toEvolutionParticipant(p, lidToPn) {
  let pn = isPn(p.phoneNumber) ? p.phoneNumber : (isPn(p.id) ? p.id : null);
  const lid = isLid(p.lid) ? p.lid : (isLid(p.id) ? p.id : null);
  if (!pn && lid && lidToPn) pn = await lidToPn(lid).catch(() => null);
  return {
    id: pn ?? p.id,
    jid: pn ?? p.id,
    lid: lid ?? null,
    phoneNumber: pn ?? null,
    admin: p.admin ?? (p.isSuperAdmin ? 'superadmin' : p.isAdmin ? 'admin' : null),
    name: p.name ?? p.notify ?? null,
    imgUrl: p.imgUrl && p.imgUrl !== 'changed' ? p.imgUrl : null,
  };
}

/** Metadados do grupo -> formato do fetchAllGroups/findGroupInfos. */
export async function toEvolutionGroup(g, withParticipants, lidToPn, pictureUrl = null) {
  const out = {
    id: g.id,
    subject: g.subject ?? '',
    subjectOwner: g.subjectOwnerPn ?? g.subjectOwner ?? null,
    subjectTime: g.subjectTime ?? null,
    pictureUrl,
    size: g.size ?? g.participants?.length ?? 0,
    creation: g.creation ?? null,
    owner: g.ownerPn ?? g.owner ?? null,
    desc: g.desc ?? null,
    descId: g.descId ?? null,
    restrict: !!g.restrict,
    announce: !!g.announce,
    isCommunity: !!g.isCommunity,
    isCommunityAnnounce: !!g.isCommunityAnnounce,
    linkedParent: g.linkedParent ?? null,
    addressingMode: g.addressingMode ?? null,
  };
  if (withParticipants) {
    out.participants = await Promise.all((g.participants ?? []).map((p) => toEvolutionParticipant(p, lidToPn)));
  }
  return out;
}

/** Converte o objeto vindo do PHP (mensagem citada) para proto. */
export function toProtoMessage(obj) {
  try {
    return proto.Message.fromObject(obj ?? {});
  } catch {
    return obj;
  }
}

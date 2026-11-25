// Utilidades: erros HTTP no formato da Evolution, JIDs, JSON e caches simples.
import crypto from 'node:crypto';
import { getContentType, normalizeMessageContent, jidNormalizedUser, isJidGroup, isLidUser } from 'baileys';

const STATUS_TEXT = {
  400: 'Bad Request', 401: 'Unauthorized', 403: 'Forbidden', 404: 'Not Found',
  409: 'Conflict', 413: 'Payload Too Large', 500: 'Internal Server Error', 503: 'Service Unavailable',
};

/** Erro com status HTTP. Resposta no formato da Evolution: {status, error, response:{message:[...]}}. */
export class HttpError extends Error {
  constructor(status, message) {
    super(typeof message === 'string' ? message : JSON.stringify(message));
    this.status = status;
    this.payload = message;
  }
  toJSON() {
    const msg = Array.isArray(this.payload) ? this.payload : [this.payload];
    return { status: this.status, error: STATUS_TEXT[this.status] ?? 'Error', response: { message: msg } };
  }
}

export const notFoundInstance = (name) => new HttpError(404, `The "${name}" instance does not exist`);

export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

export const randomToken = () => crypto.randomUUID().toUpperCase();

export function safeEqual(a, b) {
  const x = Buffer.from(String(a ?? ''));
  const y = Buffer.from(String(b ?? ''));
  return x.length === y.length && x.length > 0 && crypto.timingSafeEqual(x, y);
}

/** Long do protobuf -> number. */
function longToNumber(v) {
  // high é com sinal (Long com sinal) ou sem sinal; low sempre como sem sinal.
  return (v.unsigned ? v.high >>> 0 : v.high) * 4294967296 + (v.low >>> 0);
}

const isLong = (v) => v && typeof v === 'object' && typeof v.low === 'number' && typeof v.high === 'number' && 'unsigned' in v;

/**
 * Objeto do Baileys -> JSON puro (o que a Evolution entrega no webhook):
 * bytes em base64, Long em número, enums como texto (toJSON do protobuf).
 */
export function toPlain(obj) {
  if (obj === undefined || obj === null) return obj ?? null;
  return JSON.parse(JSON.stringify(obj, (_k, v) => {
    if (v === undefined || v === null) return v;
    if (isLong(v)) return longToNumber(v);
    if (v instanceof Uint8Array) return Buffer.from(v).toString('base64');
    if (typeof v === 'object' && v.type === 'Buffer' && Array.isArray(v.data)) return Buffer.from(v.data).toString('base64');
    if (typeof v === 'bigint') return Number(v);
    return v;
  }));
}

/** Timestamp (number | Long | string) -> segundos. */
export function tsToNumber(ts) {
  if (ts === undefined || ts === null) return 0;
  if (typeof ts === 'number') return ts;
  if (typeof ts === 'string') return parseInt(ts, 10) || 0;
  if (isLong(ts)) return longToNumber(ts);
  if (typeof ts.toNumber === 'function') return ts.toNumber();
  return Number(ts) || 0;
}

/** Tipo da mensagem como na Evolution (conversation, imageMessage, reactionMessage...). */
export function messageTypeOf(message) {
  if (!message) return 'unknown';
  try {
    return getContentType(normalizeMessageContent(message)) || 'unknown';
  } catch {
    return Object.keys(message)[0] || 'unknown';
  }
}

export const MEDIA_TYPES = new Set(['imageMessage', 'videoMessage', 'audioMessage', 'documentMessage', 'stickerMessage', 'ptvMessage']);

export const onlyDigits = (s) => String(s ?? '').replace(/\D+/g, '');

/**
 * Número/JID recebido da API -> JID (mesmas regras do createJid da Evolution).
 * Não consulta o WhatsApp; para conversas privadas use Instance.resolveJid().
 */
export function toJid(input) {
  let s = String(input ?? '').trim();
  if (s === '') return '';
  if (s.includes('@')) {
    if (s.endsWith('@c.us')) s = s.replace('@c.us', '@s.whatsapp.net');
    if (s.endsWith('@g.us') || s.endsWith('@broadcast') || s.endsWith('@newsletter')) return s;
    if (s.endsWith('@lid')) return s.replace(/:\d+@/, '@');
    return jidNormalizedUser(s);
  }
  // Grupo antigo "551199999999-1600000000"
  if (s.includes('-') && onlyDigits(s).length >= 18) return s.replace(/[^\d-]/g, '') + '@g.us';
  const d = onlyDigits(s);
  if (d.length >= 18) return d + '@g.us';
  return d + '@s.whatsapp.net';
}

export const isGroupJid = (j) => !!isJidGroup(j);
export const isLid = (j) => !!isLidUser(j);
export const isPn = (j) => typeof j === 'string' && j.endsWith('@s.whatsapp.net');

/**
 * Variantes de um número brasileiro (com e sem o 9 do celular): o WhatsApp
 * guarda contas antigas sem o 9.
 */
export function brVariants(digits) {
  const d = onlyDigits(digits);
  const out = [d];
  if (d.startsWith('55') && d.length === 13 && d[4] === '9') out.push(d.slice(0, 4) + d.slice(5));
  else if (d.startsWith('55') && d.length === 12 && /[6-9]/.test(d[4])) out.push(d.slice(0, 4) + '9' + d.slice(4));
  return out;
}

/** Cache em memória com validade (compatível com o CacheStore do Baileys). */
export class TtlCache {
  constructor(ttlMs, max = 5000) {
    this.ttl = ttlMs;
    this.max = max;
    this.map = new Map();
  }
  get(key) {
    const it = this.map.get(key);
    if (!it) return undefined;
    if (it.exp < Date.now()) {
      this.map.delete(key);
      return undefined;
    }
    return it.v;
  }
  set(key, v, ttlMs) {
    if (this.map.size >= this.max) {
      const first = this.map.keys().next().value;
      this.map.delete(first);
    }
    this.map.set(key, { v, exp: Date.now() + (ttlMs ?? this.ttl) });
    return true;
  }
  del(key) {
    this.map.delete(key);
    return true;
  }
  has(key) {
    return this.get(key) !== undefined;
  }
  flushAll() {
    this.map.clear();
  }
}

/** Mídia recebida pela API (URL, data URI ou base64 puro). */
export function mediaInput(media) {
  const s = String(media ?? '').trim();
  if (s === '') throw new HttpError(400, 'media is required');
  if (/^https?:\/\//i.test(s)) return { url: s };
  const m = s.match(/^data:([^;,]+)?(;base64)?,(.*)$/s);
  const b64 = m ? m[3] : s;
  const buf = Buffer.from(b64.replace(/\s+/g, ''), 'base64');
  if (!buf.length) throw new HttpError(400, 'media inválida: envie uma URL ou base64');
  return buf;
}

export const nowIso = () => new Date().toISOString();

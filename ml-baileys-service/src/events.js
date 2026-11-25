// Registro de eventos (o "log detalhado" do WhatsApp), na tabela baileys_event_log.
//
// Tudo o que acontece no serviço vira uma linha: conexão, QR Code, mensagens
// recebidas/enviadas, reações, falhas de descriptografia, entrega ao webhook,
// mídia, grupos, chamadas da API, erros internos do Baileys... O sistema PHP grava
// na MESMA tabela os eventos dele (origem "sistema"), então a tela
// /admin_whatsapp_eventos.php mostra a viagem completa de cada mensagem.
//
// Nunca atrapalha o WhatsApp: as linhas vão para um buffer em memória e são
// gravadas em lote (a cada 2 s ou 200 linhas). Banco fora: guarda até 10.000 e
// descarta as mais antigas (o descarte também é registrado).
import { config } from './config.js';

const MAX_BUFFER = 10000;
const BATCH = 200;
const LEVELS = new Set(['debug', 'info', 'warn', 'error']);

let execFn = null;
let buffer = [];
let dropped = 0;
let timer = null;
let flushing = false;
let ready = false;

export const SCHEMA_EVENT_LOG = `CREATE TABLE IF NOT EXISTS baileys_event_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at DATETIME(3) NOT NULL COMMENT 'UTC',
  source VARCHAR(10) NOT NULL COMMENT 'servico | sistema',
  level VARCHAR(8) NOT NULL COMMENT 'debug | info | warn | error',
  category VARCHAR(30) NOT NULL,
  event VARCHAR(80) NOT NULL,
  instance VARCHAR(100) NULL,
  remote_jid VARCHAR(150) NULL,
  message_id VARCHAR(150) NULL,
  status VARCHAR(40) NULL,
  http_status SMALLINT NULL,
  duration_ms INT NULL,
  summary VARCHAR(500) NULL,
  error TEXT NULL,
  details MEDIUMTEXT NULL,
  PRIMARY KEY (id),
  KEY idx_bel_time (created_at),
  KEY idx_bel_inst (instance, created_at),
  KEY idx_bel_cat (category, created_at),
  KEY idx_bel_level (level, created_at),
  KEY idx_bel_msg (message_id),
  KEY idx_bel_jid (remote_jid, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`;

const cut = (v, n) => {
  if (v === undefined || v === null || v === '') return null;
  const s = String(v);
  return s.length > n ? s.slice(0, n - 1) + '…' : s;
};

const utcNow = () => new Date().toISOString().replace('T', ' ').replace('Z', '');

function safeJson(obj) {
  if (obj === undefined || obj === null) return null;
  try {
    const s = JSON.stringify(obj, (_k, v) => {
      if (typeof v === 'bigint') return Number(v);
      if (v instanceof Uint8Array || Buffer.isBuffer(v)) return `[${v.length} bytes]`;
      if (typeof v === 'string' && v.length > 2000) return v.slice(0, 2000) + '…';
      return v;
    });
    return s.length > 60000 ? s.slice(0, 60000) + '…' : s;
  } catch {
    return null;
  }
}

/**
 * Registra um evento.
 * @param {'debug'|'info'|'warn'|'error'} level
 * @param {string} category conexao | recebida | enviada | reacao | webhook | midia | grupo | api | sistema | baileys | criptografia | seguranca | monitor
 * @param {string} event código curto (ex.: "conectado", "falha_entrega")
 * @param {object} f {instance, jid, messageId, status, http, ms, summary, error, details}
 */
export function ev(level, category, event, f = {}) {
  if (!LEVELS.has(level)) level = 'info';
  if (level === 'debug' && config.eventLogDebug === false) return;
  buffer.push([
    utcNow(), 'servico', level, cut(category, 30), cut(event, 80),
    cut(f.instance, 100), cut(f.jid, 150), cut(f.messageId, 150), cut(f.status, 40),
    Number.isFinite(f.http) ? f.http : null, Number.isFinite(f.ms) ? Math.round(f.ms) : null,
    cut(f.summary, 500), cut(f.error instanceof Error ? f.error.message : f.error, 4000), safeJson(f.details),
  ]);
  if (buffer.length > MAX_BUFFER) {
    const extra = buffer.length - MAX_BUFFER;
    buffer.splice(0, extra);
    dropped += extra;
  }
  if (buffer.length >= BATCH) flush();
  else if (!timer) timer = setTimeout(flush, 2000);
}

/** Liga a gravação (chamado depois que o banco ficou pronto). */
export function startEventLog(exec) {
  execFn = exec;
  ready = true;
  flush();
}

export async function flush() {
  clearTimeout(timer);
  timer = null;
  if (!ready || !execFn || flushing || !buffer.length) return;
  flushing = true;
  try {
    if (dropped > 0) {
      buffer.unshift([utcNow(), 'servico', 'warn', 'sistema', 'log_descartado', null, null, null, null, null, null,
        `${dropped} evento(s) do log descartados (banco indisponível por muito tempo)`, null, null]);
      dropped = 0;
    }
    while (buffer.length) {
      const rows = buffer.splice(0, BATCH);
      const ph = rows.map(() => '(?,?,?,?,?,?,?,?,?,?,?,?,?,?)').join(',');
      try {
        await execFn(
          `INSERT INTO baileys_event_log (created_at, source, level, category, event, instance, remote_jid, message_id, status,
             http_status, duration_ms, summary, error, details) VALUES ${ph}`,
          rows.flat(),
        );
      } catch (e) {
        // Banco fora: devolve para a fila e tenta depois
        buffer.unshift(...rows);
        if (buffer.length > MAX_BUFFER) {
          dropped += buffer.length - MAX_BUFFER;
          buffer.splice(MAX_BUFFER);
        }
        process.stderr.write(`[event-log] falha ao gravar: ${e.message}\n`);
        break;
      }
    }
  } finally {
    flushing = false;
    if (buffer.length && !timer) timer = setTimeout(flush, 5000);
  }
}

/** Limpeza: 0 dias = nunca apaga. */
export async function cleanupEvents(days) {
  const d = Math.floor(Number(days) || 0);
  if (d <= 0 || !execFn) return;
  for (let i = 0; i < 100; i++) {
    const r = await execFn(`DELETE FROM baileys_event_log WHERE created_at < (UTC_TIMESTAMP() - INTERVAL ${d} DAY) LIMIT 5000`);
    if (!r.affectedRows || r.affectedRows < 5000) break;
  }
}

/** Nome legível dos códigos de desconexão do WhatsApp. */
export function disconnectReasonName(code) {
  const map = {
    401: 'aparelho desconectado no celular (logout)',
    403: 'conta bloqueada/proibida pelo WhatsApp',
    408: 'conexão perdida/tempo esgotado',
    411: 'aparelho com sessão diferente (multidevice)',
    428: 'conexão fechada pelo servidor',
    440: 'outra sessão abriu com este número',
    500: 'sessão inválida/corrompida',
    503: 'WhatsApp indisponível',
    515: 'reinício exigido pelo WhatsApp (normal depois do QR)',
  };
  return map[Number(code)] ?? (code ? `código ${code}` : 'sem código');
}

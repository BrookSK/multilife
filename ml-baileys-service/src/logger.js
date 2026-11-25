import { Writable } from 'node:stream';
import pino from 'pino';
import { config } from './config.js';

/**
 * Últimas linhas do log em memória: o sistema mostra em Monitor do WhatsApp
 * (GET /service/logs), para não precisar de acesso ao servidor.
 */
const RING_MAX = 400;
export const recentLogs = [];

/** Destino extra de cada linha (o index.js liga no log detalhado). */
let sink = null;
export const setLogSink = (fn) => {
  sink = typeof fn === 'function' ? fn : null;
};

const out = new Writable({
  write(chunk, _enc, cb) {
    const s = chunk.toString();
    process.stdout.write(s);
    for (const line of s.split('\n')) {
      if (!line) continue;
      recentLogs.push(line);
      if (recentLogs.length > RING_MAX) recentLogs.shift();
      if (sink) {
        try {
          sink(line);
        } catch {}
      }
    }
    cb();
  },
});

/** Log do serviço (JSON, uma linha por evento). */
export const logger = pino({
  level: config.logLevel,
  base: { svc: 'ml-baileys' },
  timestamp: pino.stdTimeFunctions.isoTime,
}, out);

/** Log do Baileys: só avisos e erros (o nível "info" dele é muito verboso). */
export const baileysLogger = logger.child({ mod: 'baileys' }, { level: config.logLevel === 'debug' || config.logLevel === 'trace' ? config.logLevel : 'warn' });

import pino from 'pino';
import { config } from './config.js';

/** Log do serviço (JSON, uma linha por evento). */
export const logger = pino({
  level: config.logLevel,
  base: { svc: 'ml-baileys' },
  timestamp: pino.stdTimeFunctions.isoTime,
});

/** Log do Baileys: só avisos e erros (o nível "info" dele é muito verboso). */
export const baileysLogger = logger.child({ mod: 'baileys' }, { level: config.logLevel === 'debug' || config.logLevel === 'trace' ? config.logLevel : 'warn' });

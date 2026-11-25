// ml-baileys-service: conector WhatsApp enxuto do Multilife (Node + Baileys).
// Contrato de API e de webhook igual ao da Evolution v2: o PHP só troca a URL base.
import { assertConfig, config } from './config.js';
import { logger } from './logger.js';
import { ensureSchema, exec, pool } from './db.js';
import * as manager from './manager.js';
import * as store from './store.js';
import { buildApp } from './routes.js';
import { warmWaVersion } from './instance.js';

async function logStatuses() {
  for (const inst of manager.list()) {
    const s = inst.statusInfo();
    try {
      await exec(
        'INSERT INTO baileys_status_log (instance, logged_at, socket, verdict, inbound_silent_min, last_event_sec) VALUES (?, UTC_TIMESTAMP(), ?, ?, ?, ?)',
        [inst.name, s.socket, s.verdict, s.inboundSilentMinutes, s.lastEventSecondsAgo],
      );
    } catch (e) {
      logger.warn({ err: e.message }, 'falha ao registrar status');
    }
  }
}

async function main() {
  assertConfig();
  await ensureSchema();
  await warmWaVersion();
  await manager.loadAll();

  const app = buildApp();
  const server = app.listen(config.port, config.host, () => {
    logger.info({ host: config.host, port: config.port }, 'ml-baileys-service no ar');
  });
  server.requestTimeout = 300000;

  const statusTimer = setInterval(() => logStatuses().catch(() => {}), config.monitor.logIntervalMin * 60000);
  const cleanupTimer = setInterval(() => {
    store.cleanup(config.messageRetentionDays, config.monitor.logRetentionDays)
      .catch((e) => logger.warn({ err: e.message }, 'falha na limpeza'));
  }, 3600 * 1000);

  let closing = false;
  const shutdown = async (sig) => {
    if (closing) return;
    closing = true;
    logger.info({ sig }, 'encerrando (sessões continuam válidas)');
    clearInterval(statusTimer);
    clearInterval(cleanupTimer);
    server.close();
    await manager.shutdownAll();
    await pool.end().catch(() => {});
    process.exit(0);
  };
  process.on('SIGINT', () => shutdown('SIGINT'));
  process.on('SIGTERM', () => shutdown('SIGTERM'));
  process.on('unhandledRejection', (e) => logger.error({ err: e?.message ?? String(e) }, 'promessa rejeitada sem tratamento'));
  process.on('uncaughtException', (e) => logger.error({ err: e.message, stack: e.stack }, 'exceção não tratada'));
}

main().catch((e) => {
  logger.fatal({ err: e.message }, 'falha ao iniciar');
  process.exit(1);
});

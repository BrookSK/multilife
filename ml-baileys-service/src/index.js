// ml-baileys-service: conector WhatsApp enxuto do Multilife (Node + Baileys).
// Contrato de API e de webhook igual ao da Evolution v2: o PHP só troca a URL base.
//
// Pensado para subir sem ninguém rodar comando:
//  - o HTTP abre na hora (o painel/Passenger vê o app no ar) e as rotas
//    respondem "iniciando" até tudo ficar pronto;
//  - a configuração vem do runtime-config.php que o sistema PHP escreve
//    (ou do ambiente/.env, numa VPS);
//  - só um processo por banco conecta os números (trava de líder no MySQL);
//  - quando o arquivo do serviço muda (git pull), o processo encerra sozinho e o
//    painel/pm2 sobe a versão nova na próxima requisição;
//  - auto-ping na própria URL pública para o painel não desligar o app parado.
import fs from 'node:fs';
import { config, hasDbConfig, isConfigured, reloadConfig, setDbSettings } from './config.js';
import { logger } from './logger.js';
import { closePool, ensureSchema, exec, readSystemSettings, releaseLeader, tryAcquireLeader, watchLeader } from './db.js';
import * as manager from './manager.js';
import * as store from './store.js';
import { buildApp } from './routes.js';
import { warmWaVersion } from './instance.js';
import { state } from './state.js';
import { sleep } from './util.js';

/* global __filename */
const BUNDLE_FILE = typeof __filename !== 'undefined' && process.env.ML_BAILEYS_BUNDLE === '1' ? __filename : null;

const timers = [];
const every = (ms, fn) => {
  const t = setInterval(() => Promise.resolve().then(fn).catch((e) => logger.warn({ err: e?.message }, 'rotina falhou')), ms);
  t.unref?.();
  timers.push(t);
};

let closing = false;
async function shutdown(reason, code = 0) {
  if (closing) return;
  closing = true;
  logger.info({ reason }, 'encerrando (as sessões continuam válidas)');
  for (const t of timers) clearInterval(t);
  try {
    await manager.shutdownAll();
  } catch {}
  await releaseLeader();
  await closePool();
  setTimeout(() => process.exit(code), 300).unref?.();
}

async function logStatuses() {
  for (const inst of manager.list()) {
    const s = inst.statusInfo();
    await exec(
      'INSERT INTO baileys_status_log (instance, logged_at, socket, verdict, inbound_silent_min, last_event_sec) VALUES (?, UTC_TIMESTAMP(), ?, ?, ?, ?)',
      [inst.name, s.socket, s.verdict, s.inboundSilentMinutes, s.lastEventSecondsAgo],
    ).catch((e) => logger.warn({ err: e.message }, 'falha ao registrar status'));
  }
}

/** Auto-ping: requisição pela URL pública mantém o app "em uso" para o painel. */
async function selfPing() {
  if (!config.publicUrl) return;
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), 10000);
  try {
    await fetch(config.publicUrl + '/health', { signal: ctrl.signal, headers: { 'x-self-ping': '1' } }).then((r) => r.arrayBuffer());
  } catch {
    // sem problema: o próximo tenta de novo
  } finally {
    clearTimeout(t);
  }
}

/** Lê admin_settings do sistema e reaplica a configuração. */
async function refreshSystemSettings() {
  setDbSettings(await readSystemSettings());
  return reloadConfig();
}

async function start() {
  // 1) Dados do banco (runtime-config.php escrito pelo sistema, ou ambiente)
  while (!hasDbConfig()) {
    state.phase = 'aguardando configuração (abra Configurações → WhatsApp Conexão no sistema)';
    await sleep(10000);
    reloadConfig();
  }
  logger.info({ source: config.source, db: config.db.database }, 'configuração carregada');

  // 2) Banco
  for (let attempt = 1; ; attempt++) {
    try {
      state.phase = 'conectando no banco';
      await ensureSchema();
      break;
    } catch (e) {
      state.lastError = 'banco: ' + e.message;
      // "localhost" no PHP costuma ser o socket do MySQL: tenta o socket que o PHP usa
      if (!config.db.socketPath && config.db.socketHint && ['ECONNREFUSED', 'ENOTFOUND', 'ETIMEDOUT', 'EHOSTUNREACH'].includes(e.code)) {
        logger.warn({ socket: config.db.socketHint }, 'TCP do banco recusado: tentando pelo socket');
        config.db.socketPath = config.db.socketHint;
        await closePool();
        continue;
      }
      logger.error({ err: e.message, attempt }, 'falha no banco: nova tentativa em 15 s');
      await sleep(15000);
      reloadConfig();
      await closePool();
    }
  }

  // 2b) Chave, URLs e retenção: Configurações do sistema (mesmo banco)
  await refreshSystemSettings();
  while (!isConfigured()) {
    state.phase = 'aguardando a chave do serviço (abra Configurações → WhatsApp Conexão no sistema)';
    await sleep(10000);
    reloadConfig();
    await refreshSystemSettings();
  }

  // 3) Um processo só conecta os números
  state.phase = 'aguardando outro processo do serviço terminar';
  while (!(await tryAcquireLeader())) await sleep(3000);
  state.leader = true;
  watchLeader(() => shutdown('trava de líder perdida', 1));

  // 4) WhatsApp
  state.phase = 'conectando os números';
  await warmWaVersion();
  await manager.loadAll();
  state.ready = true;
  state.phase = 'pronto';
  state.lastError = null;
  logger.info('serviço pronto');

  every(config.monitor.logIntervalMin * 60000, logStatuses);
  every(3600 * 1000, () => store.cleanup(config.messageRetentionDays, config.monitor.logRetentionDays));
}

function watchers() {
  // Configuração escrita pelo PHP: chave, URL e retenção valem sem reiniciar
  // e as Configurações do sistema (admin_settings), relidas a cada 30 s
  every(30000, async () => {
    const r = state.ready ? await refreshSystemSettings() : reloadConfig();
    if (r.dbChanged && state.ready) shutdown('dados do banco mudaram');
    else if (r.changed) logger.info('configuração atualizada');
  });

  // Versão nova do serviço (git pull): encerra; o painel/pm2 sobe a nova
  if (BUNDLE_FILE) {
    let mtime = 0;
    try {
      mtime = fs.statSync(BUNDLE_FILE).mtimeMs;
    } catch {}
    every(60000, () => {
      let m = mtime;
      try {
        m = fs.statSync(BUNDLE_FILE).mtimeMs;
      } catch {}
      if (m !== mtime) shutdown('versão nova do serviço instalada');
    });
  }

  every(60000, selfPing);
}

function main() {
  const app = buildApp();
  const server = app.listen(config.port, config.host, () => {
    logger.info({ port: config.port }, 'ml-baileys-service no ar');
  });
  server.requestTimeout = 300000;
  server.on('error', (e) => {
    logger.fatal({ err: e.message }, 'não foi possível abrir a porta');
    shutdown('porta indisponível', 1);
  });

  watchers();
  start().catch((e) => {
    state.lastError = e.message;
    logger.fatal({ err: e.message, stack: e.stack }, 'falha ao iniciar');
    shutdown('falha ao iniciar', 1);
  });

  process.on('SIGINT', () => shutdown('SIGINT'));
  process.on('SIGTERM', () => shutdown('SIGTERM'));
  process.on('unhandledRejection', (e) => logger.error({ err: e?.message ?? String(e) }, 'promessa rejeitada sem tratamento'));
  process.on('uncaughtException', (e) => logger.error({ err: e.message, stack: e.stack }, 'exceção não tratada'));
}

main();

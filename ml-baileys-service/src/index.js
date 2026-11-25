// ml-baileys-service: conector WhatsApp enxuto do Multilife (Node + Baileys).
// Contrato de API e de webhook igual ao da Evolution v2: o PHP só troca a URL base.
//
// Pensado para subir sem ninguém rodar comando:
//  - o HTTP abre na hora (o painel/Passenger vê o app no ar) e as rotas
//    respondem "iniciando" até tudo ficar pronto;
//  - a configuração vem do runtime-config.php que o sistema PHP escreve
//    (ou do ambiente/.env, numa VPS) e das Configurações do sistema (banco);
//  - só um processo por banco conecta os números (trava de líder no MySQL);
//  - quando o arquivo do serviço muda (git pull), o processo encerra sozinho e o
//    painel/pm2 sobe a versão nova na próxima requisição;
//  - auto-ping na própria URL pública para o painel não desligar o app parado;
//  - tudo o que acontece vai para o log detalhado (baileys_event_log).
import fs from 'node:fs';
import { config, hasDbConfig, isConfigured, reloadConfig, setDbSettings } from './config.js';
import { logger, setLogSink } from './logger.js';
import { closePool, ensureSchema, exec, readSystemSettings, releaseLeader, tryAcquireLeader, watchLeader } from './db.js';
import * as manager from './manager.js';
import * as store from './store.js';
import { buildApp } from './routes.js';
import { warmWaVersion, currentWaVersion } from './instance.js';
import { state } from './state.js';
import { sleep } from './util.js';
import { cleanupEvents, ev, flush as flushEvents, startEventLog } from './events.js';

/* global __filename */
const BUNDLE_FILE = typeof __filename !== 'undefined' && process.env.ML_BAILEYS_BUNDLE === '1' ? __filename : null;

// Avisos e erros internos do Baileys (e erros graves de qualquer parte) também vão
// para o log detalhado: nada some no terminal da hospedagem.
setLogSink((line) => {
  if (line[0] !== '{') return;
  let j;
  try {
    j = JSON.parse(line);
  } catch {
    return;
  }
  const lvl = Number(j.level) || 30;
  const isBaileys = j.mod === 'baileys';
  if (!(isBaileys && lvl >= 40) && lvl < 50) return;
  const { level, time, svc, msg, mod, instance, err, ...rest } = j;
  ev(lvl >= 50 ? 'error' : 'warn', isBaileys ? 'baileys' : 'sistema', isBaileys ? 'aviso_interno' : 'erro_interno', {
    instance,
    summary: String(msg ?? '').slice(0, 480),
    error: typeof err === 'string' ? err : (err ? JSON.stringify(err).slice(0, 3000) : null),
    details: Object.keys(rest).length ? rest : null,
  });
});

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
  ev(code === 0 ? 'info' : 'error', 'sistema', 'servico_encerrando', { summary: 'Serviço encerrando: ' + reason, details: { code } });
  for (const t of timers) clearInterval(t);
  try {
    await manager.shutdownAll();
  } catch {}
  await flushEvents().catch(() => {});
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
    // Situação de cada número a cada 5 min: o que deixou de acontecer aparece aqui
    const lvl = s.verdict === 'silent' ? 'warn' : (s.verdict === 'dormant' && inst.registered ? 'warn' : 'debug');
    ev(lvl, 'monitor', 'situacao_' + s.verdict, {
      instance: inst.name,
      status: s.state,
      summary: `Situação: ${s.verdict} · socket ${s.state}`
        + (s.inboundSilentMinutes !== null ? ` · ${s.inboundSilentMinutes} min sem receber` : '')
        + (s.lastEventSecondsAgo !== null ? ` · último evento há ${s.lastEventSecondsAgo}s` : ''),
      details: s,
    });
  }
}

/** Auto-ping: requisição pela URL pública mantém o app "em uso" para o painel. */
let selfPingFails = 0;
async function selfPing() {
  if (!config.publicUrl) return;
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), 10000);
  const t0 = Date.now();
  try {
    const r = await fetch(config.publicUrl + '/health', { signal: ctrl.signal, headers: { 'x-self-ping': '1' } });
    await r.arrayBuffer();
    if (!r.ok) throw new Error('HTTP ' + r.status);
    if (selfPingFails > 0) ev('info', 'sistema', 'auto_ping_voltou', { summary: 'Endereço público do serviço respondeu de novo', ms: Date.now() - t0 });
    selfPingFails = 0;
  } catch (e) {
    selfPingFails++;
    // Avisa na 1ª falha e depois a cada 30 (meia hora)
    if (selfPingFails === 1 || selfPingFails % 30 === 0) {
      ev('warn', 'sistema', 'auto_ping_falhou', {
        summary: `O serviço não conseguiu abrir o próprio endereço público (${config.publicUrl}). O painel pode desligá-lo quando ficar parado.`,
        error: e.name === 'AbortError' ? 'tempo esgotado (10 s)' : e.message,
        details: { falhas_seguidas: selfPingFails },
      });
    }
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
  ev('info', 'sistema', 'servico_iniciando', {
    summary: `Serviço iniciando (Node ${process.version})`,
    details: { pid: process.pid, node: process.version, bundle: !!BUNDLE_FILE, memoriaMB: Math.round(process.memoryUsage().rss / 1048576) },
  });

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
  startEventLog(exec);
  ev('info', 'sistema', 'banco_conectado', { summary: `Banco conectado (${config.db.database}, origem da configuração: ${config.source})` });

  // 2b) Chave, URLs e retenção: Configurações do sistema (mesmo banco)
  await refreshSystemSettings();
  let waitedKey = false;
  while (!isConfigured()) {
    if (!waitedKey) {
      ev('warn', 'sistema', 'aguardando_chave', { summary: 'Sem chave da API: abra Configurações → WhatsApp Conexão no sistema para gerar' });
      waitedKey = true;
    }
    state.phase = 'aguardando a chave do serviço (abra Configurações → WhatsApp Conexão no sistema)';
    await sleep(10000);
    reloadConfig();
    await refreshSystemSettings();
  }

  // 3) Um processo só conecta os números
  state.phase = 'aguardando outro processo do serviço terminar';
  const t0 = Date.now();
  let waitedLeader = false;
  while (!(await tryAcquireLeader())) {
    if (!waitedLeader) {
      ev('warn', 'sistema', 'aguardando_lider', { summary: 'Outro processo do serviço está ligado: este aguarda a vez (só um conecta os números)' });
      waitedLeader = true;
    }
    await sleep(3000);
  }
  state.leader = true;
  ev('info', 'sistema', 'lider', { summary: 'Este processo assumiu os números', ms: Date.now() - t0, details: { pid: process.pid } });
  watchLeader(() => {
    ev('error', 'sistema', 'lider_perdido', { summary: 'Conexão da trava com o banco caiu: o processo vai reiniciar' });
    shutdown('trava de líder perdida', 1);
  });

  // 4) WhatsApp
  state.phase = 'conectando os números';
  await warmWaVersion();
  await manager.loadAll();
  state.ready = true;
  state.phase = 'pronto';
  state.lastError = null;
  logger.info('serviço pronto');
  ev('info', 'sistema', 'servico_pronto', {
    summary: `Serviço pronto: ${manager.list().length} número(s) cadastrados · WhatsApp Web ${currentWaVersion()?.join('.') ?? 'padrão'}`,
    details: {
      instancias: manager.list().map((i) => i.name),
      retencaoMensagensDias: config.messageRetentionDays,
      retencaoLogDias: config.eventLogRetentionDays,
      publicUrl: config.publicUrl,
      webhookPadrao: config.webhook.defaultUrl,
    },
  });

  every(config.monitor.logIntervalMin * 60000, logStatuses);
  every(30000, () => manager.checkPendingAcks());
  every(3600 * 1000, async () => {
    await store.cleanup(config.messageRetentionDays, config.monitor.logRetentionDays);
    await cleanupEvents(config.eventLogRetentionDays);
  });
  // Memória do processo a cada 15 min (pista de vazamento/limite da hospedagem)
  every(15 * 60000, () => {
    const m = process.memoryUsage();
    const rss = Math.round(m.rss / 1048576);
    ev(rss > 1500 ? 'warn' : 'debug', 'sistema', 'memoria', { summary: `Memória do serviço: ${rss} MB`, details: { rssMB: rss, heapMB: Math.round(m.heapUsed / 1048576) } });
  });
}

function watchers() {
  // Configuração escrita pelo PHP: chave, URL e retenção valem sem reiniciar,
  // e as Configurações do sistema (admin_settings), relidas a cada 30 s
  every(30000, async () => {
    const r = state.ready ? await refreshSystemSettings() : reloadConfig();
    if (r.dbChanged && state.ready) shutdown('dados do banco mudaram');
    else if (r.changed) {
      logger.info('configuração atualizada');
      ev('info', 'sistema', 'configuracao_atualizada', {
        summary: 'Configuração do serviço atualizada',
        details: { publicUrl: config.publicUrl, webhookPadrao: config.webhook.defaultUrl, retencaoMensagensDias: config.messageRetentionDays, retencaoLogDias: config.eventLogRetentionDays, logDebug: config.eventLogDebug },
      });
    }
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
  process.on('unhandledRejection', (e) => logger.error({ err: e?.message ?? String(e), stack: e?.stack }, 'promessa rejeitada sem tratamento'));
  process.on('uncaughtException', (e) => logger.error({ err: e.message, stack: e.stack }, 'exceção não tratada'));
}

main();

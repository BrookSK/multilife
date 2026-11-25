// Configuração do serviço.
//
// Ordem (o que vier primeiro vale):
//   1. variáveis de ambiente (ou arquivo .env na pasta do serviço) — instalação em VPS;
//   2. runtime-config.php — escrito SOZINHO pelo sistema PHP (Multilife) quando o
//      serviço roda na mesma hospedagem: banco, chave da API, URL pública e
//      retenção. Ninguém precisa editar nada;
//   3. valores padrão.
// O runtime-config.php é relido a cada 30 s (chave, retenção e URL mudam sem reiniciar).
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

/* global __dirname */
const here = typeof __dirname !== 'undefined' ? __dirname : path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '..');

export const RUNTIME_FILE = path.join(root, 'runtime-config.php');

/** Lê o .env (KEY=valor por linha) sem sobrescrever variáveis já definidas no ambiente. */
function loadDotEnv(file) {
  if (!fs.existsSync(file)) return;
  for (const raw of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
    const line = raw.trim();
    if (!line || line.startsWith('#')) continue;
    const eq = line.indexOf('=');
    if (eq <= 0) continue;
    const key = line.slice(0, eq).trim();
    let val = line.slice(eq + 1).trim();
    if ((val.startsWith('"') && val.endsWith('"')) || (val.startsWith("'") && val.endsWith("'"))) {
      val = val.slice(1, -1);
    }
    if (process.env[key] === undefined) process.env[key] = val;
  }
}

loadDotEnv(path.join(root, '.env'));

/**
 * runtime-config.php: 1ª linha "<?php ... exit; ?>" (se alguém abrir pelo
 * navegador, o PHP sai sem mostrar nada); 2ª linha em diante: JSON.
 */
export function readRuntimeFile() {
  try {
    const raw = fs.readFileSync(RUNTIME_FILE, 'utf8');
    const nl = raw.indexOf('\n');
    const json = nl >= 0 && raw.startsWith('<?php') ? raw.slice(nl + 1) : raw;
    const data = JSON.parse(json);
    return data && typeof data === 'object' ? data : null;
  } catch {
    return null;
  }
}

const env = (k) => {
  const v = process.env[k];
  return v === undefined ? undefined : String(v).trim();
};
const pick = (envKey, runtimeVal, def) => {
  const e = env(envKey);
  if (e !== undefined && e !== '') return e;
  if (runtimeVal !== undefined && runtimeVal !== null && String(runtimeVal).trim() !== '') return String(runtimeVal).trim();
  return def;
};
const num = (v, d) => {
  const n = parseInt(String(v ?? ''), 10);
  return Number.isFinite(n) ? n : d;
};

/** Primeiro valor preenchido. */
const first = (...vals) => {
  for (const v of vals) if (v !== undefined && v !== null && String(v).trim() !== '') return String(v).trim();
  return undefined;
};

/**
 * Configurações gravadas pelo sistema na tabela admin_settings (mesmo banco).
 * Valem mesmo sem o runtime-config.php: assim o serviço pode rodar em outra
 * pasta/assinatura só com os dados do banco nas variáveis de ambiente.
 */
let dbSettings = {};
export const setDbSettings = (s) => {
  dbSettings = s && typeof s === 'object' ? s : {};
};

function build(rt) {
  const r = rt ?? {};
  const db = r.db ?? {};
  const s = dbSettings;
  const sysUrl = first(s['app.public_base_url'])?.replace(/\/+$/, '');
  return {
    root,
    host: pick('HOST', null, '0.0.0.0'),
    // Em hospedagem com Passenger (Plesk/cPanel) a porta é definida por ele.
    port: num(pick('PORT', null, '8090'), 8090),
    // Ordem: ambiente > Configurações do sistema (banco) > runtime-config.php
    apiKey: pick('API_KEY', first(s['baileys.api_key'], r.api_key), ''),
    /** URL pública deste serviço (campo server_url do webhook e auto-ping). */
    publicUrl: pick('PUBLIC_URL', first(s['baileys.base_url'], s['baileys.detected_url'], r.public_url), '').replace(/\/+$/, ''),
    logLevel: pick('LOG_LEVEL', null, 'info'),

    db: {
      host: pick('DB_HOST', db.host, '127.0.0.1'),
      port: num(pick('DB_PORT', db.port, '3306'), 3306),
      user: pick('DB_USER', db.user, ''),
      password: pick('DB_PASSWORD', db.pass, ''),
      database: pick('DB_NAME', db.name, ''),
      socketPath: pick('DB_SOCKET', db.socket, ''),
      /** Socket que o PHP usa; tentado se a conexão TCP falhar. */
      socketHint: pick('DB_SOCKET_HINT', db.socket_hint, ''),
    },

    webhook: {
      /** Usado quando a instância não tem webhook próprio (POST /webhook/set). */
      defaultUrl: pick('DEFAULT_WEBHOOK_URL', first(s['baileys.webhook_url'], sysUrl ? sysUrl + '/chat_webhook.php' : undefined, r.default_webhook_url), ''),
      timeoutMs: num(pick('WEBHOOK_TIMEOUT_MS', null, '10000'), 10000),
      retries: num(pick('WEBHOOK_RETRIES', null, '5'), 5),
      concurrency: Math.max(1, num(pick('WEBHOOK_CONCURRENCY', null, '3'), 3)),
      queueMax: Math.max(100, num(pick('WEBHOOK_QUEUE_MAX', null, '5000'), 5000)),
    },

    /**
     * Dias que as mensagens ficam guardadas no serviço. 0 = PARA SEMPRE (padrão):
     * nada é apagado, a não ser que o sistema configure um prazo.
     */
    messageRetentionDays: Math.max(0, num(pick('MESSAGE_RETENTION_DAYS', first(s['baileys.message_retention_days'], r.message_retention_days), '0'), 0)),
    maxMediaBytes: Math.max(1, num(pick('MAX_MEDIA_MB', null, '64'), 64)) * 1024 * 1024,

    monitor: {
      /** Sem eventos há mais que isso com o socket aberto = "silent" (suspeita de queda). */
      silentSec: Math.max(60, num(pick('SILENT_THRESHOLD_SEC', null, '1800'), 1800)),
      /** Logo depois de conectar, sem dados ainda = "warming_up". */
      warmupSec: Math.max(30, num(pick('WARMUP_SEC', null, '300'), 300)),
      /** Intervalo do registro de status para o gráfico do piloto (minutos). */
      logIntervalMin: Math.max(1, num(pick('STATUS_LOG_INTERVAL_MIN', null, '5'), 5)),
      /** Dias do registro de status (0 = para sempre). Não são mensagens. */
      logRetentionDays: Math.max(0, num(pick('STATUS_LOG_RETENTION_DAYS', first(s['baileys.status_retention_days'], r.status_retention_days), '90'), 90)),
    },

    /** Nome do aparelho mostrado em "Aparelhos conectados" no celular. */
    browserName: pick('BROWSER_NAME', null, 'Multilife'),
    /** QR Codes gerados sem leitura antes de desistir (cada um vale ~20 s). */
    qrLimit: Math.max(1, num(pick('QRCODE_LIMIT', null, '6'), 6)),
    ffmpegPath: pick('FFMPEG_PATH', null, 'ffmpeg'),
    /** De onde veio a configuração (para a tela de status). */
    source: env('DB_NAME') ? 'ambiente' : (rt ? 'sistema' : 'nenhuma'),
  };
}

/** Configuração atual (o objeto é o mesmo; reloadConfig() atualiza os campos). */
export const config = build(readRuntimeFile());

// socketPath fica de fora: pode ter sido trocado pelo socketHint na conexão
const dbKey = (c) => JSON.stringify({ ...c.db, socketPath: undefined });

/**
 * Relê o runtime-config.php. Devolve {changed, dbChanged}.
 * Banco diferente exige reiniciar o processo (o chamador decide).
 */
export function reloadConfig() {
  const next = build(readRuntimeFile());
  const dbChanged = dbKey(next) !== dbKey(config);
  const before = JSON.stringify(config);
  for (const k of Object.keys(next)) {
    if (k === 'db' && !dbChanged) continue;
    config[k] = next[k];
  }
  return { changed: before !== JSON.stringify(config), dbChanged };
}

/** Sabe em qual banco entrar? (a chave pode vir depois, do próprio banco) */
export const hasDbConfig = () => config.db.user !== '' && config.db.database !== '';

/** Pronta para atender a API? */
export const isConfigured = () => config.apiKey !== '' && hasDbConfig();

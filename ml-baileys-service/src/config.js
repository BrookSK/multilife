// Configuração do serviço (variáveis de ambiente; arquivo .env opcional na raiz do serviço).
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

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

const env = (k, d = '') => (process.env[k] ?? d).toString().trim();
const int = (k, d) => {
  const n = parseInt(env(k, String(d)), 10);
  return Number.isFinite(n) ? n : d;
};

export const config = {
  root,
  host: env('HOST', '0.0.0.0'),
  port: int('PORT', 8090),
  apiKey: env('API_KEY'),
  /** URL pública deste serviço (vai no campo server_url do webhook). */
  publicUrl: env('PUBLIC_URL'),
  logLevel: env('LOG_LEVEL', 'info'),

  db: {
    host: env('DB_HOST', '127.0.0.1'),
    port: int('DB_PORT', 3306),
    user: env('DB_USER', 'root'),
    password: env('DB_PASSWORD'),
    database: env('DB_NAME', 'ml_baileys'),
  },

  webhook: {
    /** Usado quando a instância não tem webhook próprio (POST /webhook/set). */
    defaultUrl: env('DEFAULT_WEBHOOK_URL'),
    timeoutMs: int('WEBHOOK_TIMEOUT_MS', 10000),
    retries: int('WEBHOOK_RETRIES', 5),
    concurrency: Math.max(1, int('WEBHOOK_CONCURRENCY', 3)),
    queueMax: Math.max(100, int('WEBHOOK_QUEUE_MAX', 5000)),
  },

  /** Mensagens guardadas (findMessages, mídia, Sincronizar). */
  messageRetentionDays: Math.max(1, int('MESSAGE_RETENTION_DAYS', 15)),
  maxMediaBytes: Math.max(1, int('MAX_MEDIA_MB', 64)) * 1024 * 1024,

  monitor: {
    /** Sem eventos há mais que isso com o socket aberto = "silent" (suspeita de queda). */
    silentSec: Math.max(60, int('SILENT_THRESHOLD_SEC', 1800)),
    /** Logo depois de conectar, sem dados ainda = "warming_up". */
    warmupSec: Math.max(30, int('WARMUP_SEC', 300)),
    /** Intervalo do registro de status para o gráfico do piloto (minutos). */
    logIntervalMin: Math.max(1, int('STATUS_LOG_INTERVAL_MIN', 5)),
    logRetentionDays: Math.max(1, int('STATUS_LOG_RETENTION_DAYS', 30)),
  },

  /** Nome do aparelho mostrado em "Aparelhos conectados" no celular. */
  browserName: env('BROWSER_NAME', 'Multilife'),
  /** QR Codes gerados sem leitura antes de desistir (cada um vale ~20 s). */
  qrLimit: Math.max(1, int('QRCODE_LIMIT', 6)),
  ffmpegPath: env('FFMPEG_PATH', 'ffmpeg'),
};

export function assertConfig() {
  const missing = [];
  if (!config.apiKey) missing.push('API_KEY');
  if (!config.db.password && config.db.user !== 'root') missing.push('DB_PASSWORD');
  if (missing.length) {
    throw new Error('Configuração incompleta: defina ' + missing.join(', ') + ' (veja .env.example)');
  }
}

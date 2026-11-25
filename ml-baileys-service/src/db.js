// Banco do serviço (MySQL/MariaDB). As tabelas baileys_* são criadas no boot.
// Na hospedagem do Multilife é o MESMO banco do sistema (dados vindos do
// runtime-config.php escrito pelo PHP).
import mysql from 'mysql2/promise';
import { config } from './config.js';
import { logger } from './logger.js';
import { SCHEMA_EVENT_LOG } from './events.js';

let pool = null;

function connOptions() {
  const o = {
    user: config.db.user,
    password: config.db.password,
    database: config.db.database,
    charset: 'utf8mb4',
    supportBigNumbers: true,
    dateStrings: true,
  };
  if (config.db.socketPath) o.socketPath = config.db.socketPath;
  else {
    o.host = config.db.host === 'localhost' ? '127.0.0.1' : config.db.host;
    o.port = config.db.port;
  }
  return o;
}

export function getPool() {
  if (!pool) {
    pool = mysql.createPool({ ...connOptions(), connectionLimit: 8, waitForConnections: true, enableKeepAlive: true });
  }
  return pool;
}

/** SELECT: devolve as linhas. */
export async function query(sql, params = []) {
  const [rows] = await getPool().query(sql, params);
  return rows;
}

/** INSERT/UPDATE/DELETE: devolve o resultado (affectedRows etc.). */
export async function exec(sql, params = []) {
  const [res] = await getPool().execute(sql, params);
  return res;
}

const SETTING_KEYS = [
  'baileys.api_key', 'baileys.base_url', 'baileys.detected_url', 'baileys.webhook_url',
  'baileys.message_retention_days', 'baileys.status_retention_days', 'app.public_base_url',
  'baileys.event_log_retention_days', 'baileys.event_log_debug', 'baileys.system_local_ip',
];

/**
 * Configurações do sistema (tabela admin_settings), quando o serviço usa o
 * banco do Multilife. Banco só do serviço (VPS): devolve {} sem erro.
 */
export async function readSystemSettings() {
  try {
    const rows = await query(
      `SELECT setting_key, setting_value FROM admin_settings WHERE setting_key IN (${SETTING_KEYS.map(() => '?').join(',')})`,
      SETTING_KEYS,
    );
    const out = {};
    for (const r of rows) out[r.setting_key] = r.setting_value ?? '';
    return out;
  } catch (e) {
    if (e.code !== 'ER_NO_SUCH_TABLE') logger.warn({ err: e.message }, 'falha ao ler as configurações do sistema');
    return {};
  }
}

export async function closePool() {
  if (pool) await pool.end().catch(() => {});
  pool = null;
}

// ---------------------------------------------------------------------------
// Um processo só por banco ("líder").
//
// Em hospedagem com Passenger pode existir, por alguns segundos, mais de um
// processo do serviço (reinício, pico de acessos). Dois processos com o mesmo
// número conectado derrubam a sessão no WhatsApp (erro 440). Por isso só o
// processo que segura a trava do MySQL conecta os números; os outros só
// respondem "iniciando" até a trava ficar livre.
// ---------------------------------------------------------------------------

const LOCK_NAME = 'ml_baileys_leader';
let lockConn = null;
let lockTimer = null;

/** Tenta pegar a trava. true = este processo é o líder. */
export async function tryAcquireLeader() {
  if (lockConn) return true;
  let conn;
  try {
    conn = await mysql.createConnection(connOptions());
    const [rows] = await conn.query('SELECT GET_LOCK(?, 0) AS ok', [`${LOCK_NAME}_${config.db.database}`.slice(0, 64)]);
    if (Number(rows?.[0]?.ok) === 1) {
      lockConn = conn;
      return true;
    }
  } catch (e) {
    logger.warn({ err: e.message }, 'falha ao pegar a trava de líder');
  }
  if (conn) await conn.end().catch(() => {});
  return false;
}

/**
 * Confere a conexão da trava a cada 20 s. Se ela cair, a trava foi perdida:
 * outro processo pode virar líder, então este precisa parar (onLost).
 */
export function watchLeader(onLost) {
  clearInterval(lockTimer);
  lockTimer = setInterval(async () => {
    if (!lockConn) return;
    try {
      await lockConn.query('SELECT 1');
    } catch (e) {
      logger.error({ err: e.message }, 'conexão da trava de líder caiu');
      clearInterval(lockTimer);
      lockConn = null;
      onLost();
    }
  }, 20000);
  lockTimer.unref?.();
}

export async function releaseLeader() {
  clearInterval(lockTimer);
  if (lockConn) await lockConn.end().catch(() => {});
  lockConn = null;
}

const SCHEMA = [
  // Sessão do WhatsApp: UM JSON {creds, keys} por instância, gravado inteiro
  // (sem gravação parcial por chave).
  `CREATE TABLE IF NOT EXISTS baileys_session (
    instance VARCHAR(100) CHARACTER SET ascii NOT NULL,
    session LONGTEXT NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (instance)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,

  `CREATE TABLE IF NOT EXISTS baileys_instances (
    name VARCHAR(100) CHARACTER SET ascii NOT NULL,
    token VARCHAR(100) NOT NULL,
    owner_jid VARCHAR(150) NULL,
    profile_name VARCHAR(255) NULL,
    profile_pic_url VARCHAR(1000) NULL,
    webhook TEXT NULL,
    settings TEXT NULL,
    disconnect_code INT NULL,
    disconnect_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (name)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,

  // Mensagens recebidas e enviadas (findMessages, download de mídia, Sincronizar).
  // Guardadas para sempre, a não ser que o sistema configure um prazo.
  `CREATE TABLE IF NOT EXISTS baileys_messages (
    instance VARCHAR(100) CHARACTER SET ascii NOT NULL,
    remote_jid VARCHAR(150) CHARACTER SET ascii NOT NULL,
    msg_id VARCHAR(150) CHARACTER SET ascii NOT NULL,
    from_me TINYINT(1) NOT NULL DEFAULT 0,
    participant VARCHAR(150) CHARACTER SET ascii NULL,
    msg_type VARCHAR(60) CHARACTER SET ascii NULL,
    msg_ts INT UNSIGNED NOT NULL DEFAULT 0,
    push_name VARCHAR(255) NULL,
    data MEDIUMTEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (instance, remote_jid, msg_id),
    KEY idx_bm_ts (instance, msg_ts),
    KEY idx_bm_id (instance, msg_id),
    KEY idx_bm_jid_ts (instance, remote_jid, msg_ts),
    KEY idx_bm_created (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,

  `CREATE TABLE IF NOT EXISTS baileys_contacts (
    instance VARCHAR(100) CHARACTER SET ascii NOT NULL,
    jid VARCHAR(150) CHARACTER SET ascii NOT NULL,
    lid VARCHAR(150) CHARACTER SET ascii NULL,
    phone_jid VARCHAR(150) CHARACTER SET ascii NULL,
    name VARCHAR(255) NULL,
    notify VARCHAR(255) NULL,
    img_url VARCHAR(1000) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (instance, jid),
    KEY idx_bc_lid (instance, lid),
    KEY idx_bc_pn (instance, phone_jid)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,

  // Registro periódico do status (gráfico de 72 h do piloto).
  `CREATE TABLE IF NOT EXISTS baileys_status_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    instance VARCHAR(100) CHARACTER SET ascii NOT NULL,
    logged_at DATETIME NOT NULL,
    socket VARCHAR(20) NOT NULL,
    verdict VARCHAR(20) NOT NULL,
    inbound_silent_min INT NULL,
    last_event_sec INT NULL,
    PRIMARY KEY (id),
    KEY idx_bsl_inst (instance, logged_at),
    KEY idx_bsl_time (logged_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
];

export async function ensureSchema() {
  for (const sql of [...SCHEMA, SCHEMA_EVENT_LOG]) {
    await getPool().query(sql);
  }
  logger.info('banco pronto');
}

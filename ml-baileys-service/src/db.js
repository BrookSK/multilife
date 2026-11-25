// Banco do serviço (MySQL/MariaDB). As tabelas são criadas no boot.
import mysql from 'mysql2/promise';
import { config } from './config.js';
import { logger } from './logger.js';

export const pool = mysql.createPool({
  host: config.db.host,
  port: config.db.port,
  user: config.db.user,
  password: config.db.password,
  database: config.db.database,
  charset: 'utf8mb4',
  connectionLimit: 10,
  waitForConnections: true,
  supportBigNumbers: true,
  dateStrings: true,
  enableKeepAlive: true,
});

/** SELECT: devolve as linhas. */
export async function query(sql, params = []) {
  const [rows] = await pool.query(sql, params);
  return rows;
}

/** INSERT/UPDATE/DELETE: devolve o resultado (affectedRows etc.). */
export async function exec(sql, params = []) {
  const [res] = await pool.execute(sql, params);
  return res;
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
  for (const sql of SCHEMA) {
    await pool.query(sql);
  }
  logger.info('banco pronto');
}

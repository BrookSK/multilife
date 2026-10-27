-- =====================================================================
-- Disparo de captação em SEGUNDO PLANO (assíncrono) com popup de progresso
-- Data: 2026-10-27
--
-- O disparo da captação via WhatsApp (criação/busca de grupo, reconexão de
-- instâncias, sincronização de membros e envio da mensagem) era 100% síncrono
-- dentro do POST e estourava o timeout do PHP/Apache. Agora ele roda em
-- background (fila integration_jobs + worker cron) e a UI acompanha o progresso
-- consultando demand_dispatch_runs.
--
-- Esta migration amplia demand_dispatch_runs para representar TODO o disparo
-- (não só a adição de membros em lote), incluindo fase atual, contadores de
-- envio e mensagem final de erro/sucesso.
-- =====================================================================

-- Garante a tabela (fallback: também é criada em app/demand_dispatch_batch.php)
CREATE TABLE IF NOT EXISTS demand_dispatch_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  demand_id BIGINT UNSIGNED NOT NULL,
  group_jid VARCHAR(100) NOT NULL DEFAULT '',
  group_db_id BIGINT UNSIGNED NULL,
  total_targets INT UNSIGNED NOT NULL DEFAULT 0,
  processed_targets INT UNSIGNED NOT NULL DEFAULT 0,
  added_count INT UNSIGNED NOT NULL DEFAULT 0,
  error_count INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('queued','processing','completed','completed_with_errors') NOT NULL DEFAULT 'queued',
  created_by_user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ddr_demand (demand_id),
  KEY idx_ddr_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Novas colunas para o disparo completo em background.
-- sub_request_id: qual sub-solicitação (especialidade) está sendo captada.
-- phase: etapa legível para o popup (ex.: 'queued', 'resolving_instance',
--        'creating_group', 'syncing_members', 'sending', 'done').
-- phase_message: texto amigável da etapa atual (mostrado no popup).
-- sent_count: quantas mensagens de captação foram enviadas com sucesso.
-- result_message: mensagem final de sucesso ou erro.
ALTER TABLE demand_dispatch_runs
  ADD COLUMN IF NOT EXISTS sub_request_id BIGINT UNSIGNED NULL AFTER demand_id,
  ADD COLUMN IF NOT EXISTS phase VARCHAR(40) NOT NULL DEFAULT 'queued' AFTER status,
  ADD COLUMN IF NOT EXISTS phase_message VARCHAR(255) NULL AFTER phase,
  ADD COLUMN IF NOT EXISTS sent_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER added_count,
  ADD COLUMN IF NOT EXISTS result_message VARCHAR(500) NULL AFTER error_count;

-- Amplia o ENUM de status para cobrir o disparo completo (fila -> executando ->
-- concluído / falhou). Mantém os valores antigos para compatibilidade.
ALTER TABLE demand_dispatch_runs
  MODIFY COLUMN status ENUM(
    'queued',
    'processing',
    'completed',
    'completed_with_errors',
    'failed'
  ) NOT NULL DEFAULT 'queued';

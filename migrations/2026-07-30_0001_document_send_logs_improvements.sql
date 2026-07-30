-- Melhorias na tabela document_send_logs para suportar:
-- Status de envio, ação (envio/reenvio), vínculo com atendimento, usuário responsável

ALTER TABLE document_send_logs ADD COLUMN IF NOT EXISTS send_status VARCHAR(30) NOT NULL DEFAULT 'enviado' AFTER notes;
ALTER TABLE document_send_logs ADD COLUMN IF NOT EXISTS send_action VARCHAR(30) NOT NULL DEFAULT 'envio_inicial' AFTER send_status;
ALTER TABLE document_send_logs ADD COLUMN IF NOT EXISTS resent_from_log_id INT UNSIGNED NULL AFTER send_action;
ALTER TABLE document_send_logs ADD COLUMN IF NOT EXISTS recipient_name VARCHAR(255) NULL AFTER recipient_email;
ALTER TABLE document_send_logs ADD COLUMN IF NOT EXISTS patient_id INT UNSIGNED NULL AFTER assignment_id;
ALTER TABLE document_send_logs ADD COLUMN IF NOT EXISTS session_id INT UNSIGNED NULL AFTER patient_id;
ALTER TABLE document_send_logs ADD COLUMN IF NOT EXISTS captation_id INT UNSIGNED NULL AFTER session_id;

-- Índices para performance
CREATE INDEX IF NOT EXISTS idx_dsl_recipient ON document_send_logs (recipient_type, recipient_id);
CREATE INDEX IF NOT EXISTS idx_dsl_assignment ON document_send_logs (assignment_id);
CREATE INDEX IF NOT EXISTS idx_dsl_status ON document_send_logs (send_status);
CREATE INDEX IF NOT EXISTS idx_dsl_action ON document_send_logs (send_action);
CREATE INDEX IF NOT EXISTS idx_dsl_sent_by ON document_send_logs (sent_by_user_id);

-- Atualizar registros existentes para ter status padrão
UPDATE document_send_logs SET send_status = 'enviado' WHERE send_status = '' OR send_status IS NULL;
UPDATE document_send_logs SET send_action = 'envio_inicial' WHERE send_action = '' OR send_action IS NULL;

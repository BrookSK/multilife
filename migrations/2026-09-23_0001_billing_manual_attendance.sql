-- Suporte a atendimentos manuais (registrados fora do sistema) no Monitoramento
-- e rastreio do "operador" que criou o registro, para filtro no Fechamento Mensal.

-- Operador responsável pelo registro do atendimento (quem criou manualmente).
ALTER TABLE billing_document_requirements
    ADD COLUMN IF NOT EXISTS created_by_user_id INT UNSIGNED NULL COMMENT 'Operador que registrou o atendimento (manual)';

-- Marca se o atendimento foi criado manualmente na tela de Monitoramento.
ALTER TABLE billing_document_requirements
    ADD COLUMN IF NOT EXISTS is_manual TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = atendimento lançado manualmente';

ALTER TABLE billing_document_requirements
    ADD INDEX IF NOT EXISTS idx_bdr_created_by (created_by_user_id);

-- Adicionar campos de cancelamento e reativação na tabela demands
ALTER TABLE demands 
ADD COLUMN cancellation_reason TEXT NULL AFTER status,
ADD COLUMN cancelled_at TIMESTAMP NULL AFTER cancellation_reason,
ADD COLUMN cancelled_by_user_id INT NULL AFTER cancelled_at,
ADD COLUMN reactivation_reason TEXT NULL AFTER cancelled_by_user_id,
ADD COLUMN reactivated_at TIMESTAMP NULL AFTER reactivation_reason,
ADD COLUMN reactivated_by_user_id INT NULL AFTER reactivated_at,
ADD INDEX idx_cancelled_by (cancelled_by_user_id),
ADD INDEX idx_reactivated_by (reactivated_by_user_id);

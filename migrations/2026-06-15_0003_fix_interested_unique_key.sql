-- Migration: Permitir interesse na mesma demanda via grupos diferentes
-- Data: 2026-06-15
-- Problema: mesma demanda disparada em múltiplos grupos, profissional reage em 2 grupos
-- mas o UNIQUE(demand_id, phone) impede o segundo registro

-- Remover constraint antiga
ALTER TABLE demand_interested_professionals
DROP INDEX IF EXISTS idx_dip_unique;

-- Criar nova constraint: permite mesmo profissional na mesma demanda se veio de dispatch_log diferente
ALTER TABLE demand_interested_professionals
ADD UNIQUE INDEX idx_dip_unique_v2 (demand_id, phone, dispatch_log_id);

-- ============================================================================
-- Garante o {{link_cadastro}} no template do evento preadmission_approved (id 7)
-- e desativa os eventos duplicados. Usa texto direto (sem CONCAT/\n) para evitar
-- qualquer problema de interpretação.
--
-- SEGURO: mexe apenas na tabela whatsapp_events.
-- ============================================================================

-- 1) Grava o template no evento id 7 (o "Pré-admissão aprovada").
--    O texto abaixo usa quebras de linha REAIS (a string ocupa várias linhas).
UPDATE whatsapp_events
SET name = 'Pré-admissão aprovada',
    status = 'active',
    send_to_professional = 1,
    template_professional = 'Olá {{profissional_nome}}! 🎉

Seu atendimento foi aprovado na pré-admissão.

Para finalizarmos o seu cadastro (dados pessoais, bancários e PIX), complete suas informações neste link:

{{link_cadastro}}

É rápido e não precisa de senha. Obrigado!

Equipe MultiLife Care',
    updated_at = CURRENT_TIMESTAMP
WHERE id = 7;

-- 2) Desativa os outros eventos preadmission_approved (evita ambiguidade no disparo).
UPDATE whatsapp_events
SET status = 'inactive', updated_at = CURRENT_TIMESTAMP
WHERE system_event = 'preadmission_approved' AND id <> 7;

-- 3) Conferência: deve restar apenas o id 7 ativo, com tem_link_cadastro = 1.
SELECT id, name, status,
       (template_professional LIKE '%{{link_cadastro}}%') AS tem_link_cadastro
FROM whatsapp_events
WHERE system_event = 'preadmission_approved'
ORDER BY id ASC;

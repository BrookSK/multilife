-- =====================================================================
-- DIAGNÓSTICO: por que a mensagem de finalização não chegou no WhatsApp
-- =====================================================================
-- Rode as consultas abaixo e observe os resultados. Cada seção aponta uma
-- possível causa. Ao lado de cada uma, o que é ESPERADO.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1) Os EVENTOS existem e estão ATIVOS?
--    ESPERADO: 2 linhas, status='active', send_to_professional=1,
--    e template_professional preenchido.
--    Se vier VAZIO: o SQL de templates NÃO foi rodado ainda.
-- ---------------------------------------------------------------------
SELECT system_event, status, send_to_professional,
       LEFT(template_professional, 60) AS inicio_template
FROM whatsapp_events
WHERE system_event IN ('attendance_hospitalization', 'attendance_authorized_period_ended');


-- ---------------------------------------------------------------------
-- 2) Os MOTIVOS de encerramento têm o SLUG esperado?
--    ESPERADO: existir 'hospitalizacao' e 'termino_periodo_autorizado'.
--    ATENÇÃO: se você criou os motivos manualmente pela tela, o slug pode
--    ter um sufixo aleatório (ex.: 'hospitalizacao_a1b2') e NÃO vai casar
--    com o mapeamento do código.
-- ---------------------------------------------------------------------
SELECT id, name, slug, is_active FROM treatment_end_reasons ORDER BY id;


-- ---------------------------------------------------------------------
-- 3) Qual motivo foi realmente gravado no atendimento #211 ao finalizar?
--    Veja o slug em 'reason_slug'. Ele PRECISA ser exatamente
--    'hospitalizacao' ou 'termino_periodo_autorizado'.
-- ---------------------------------------------------------------------
SELECT pa.id, pa.status, pa.end_reason_id, ter.name AS reason_name, ter.slug AS reason_slug,
       u.name AS profissional, u.phone AS prof_phone, u.status AS prof_status
FROM patient_assignments pa
LEFT JOIN treatment_end_reasons ter ON ter.id = pa.end_reason_id
LEFT JOIN users u ON u.id = pa.professional_user_id
WHERE pa.id = 211;


-- ---------------------------------------------------------------------
-- 4) O envio tentou acontecer? Veja o LOG de eventos WhatsApp.
--    Se houver linha 'failed', a coluna error_message diz o porquê
--    (ex.: HTTP 4xx da Evolution). Se NÃO houver nenhuma linha, o
--    dispatch nem chegou a enviar (evento não encontrado / motivo não mapeado
--    / código antigo no servidor).
-- ---------------------------------------------------------------------
SELECT wel.id, we.system_event, wel.recipient_type, wel.recipient_phone,
       wel.status, wel.error_message, wel.sent_at
FROM whatsapp_event_logs wel
INNER JOIN whatsapp_events we ON we.id = wel.event_id
WHERE we.system_event IN ('attendance_hospitalization', 'attendance_authorized_period_ended')
ORDER BY wel.id DESC
LIMIT 20;


-- ---------------------------------------------------------------------
-- 5) Log de integração da Evolution (envio real do WhatsApp).
--    Mostra o que a API respondeu no último envio de texto.
-- ---------------------------------------------------------------------
SELECT id, action, status, http_status, LEFT(error_message, 120) AS erro, created_at
FROM integration_logs
WHERE provider = 'evolution' AND action LIKE '%sendText%'
ORDER BY id DESC
LIMIT 10;


-- =====================================================================
-- CORREÇÃO RÁPIDA (se o item 2/3 mostrou slug ERRADO):
-- Realinhe o slug do motivo que você usou para o valor esperado.
-- Troque <ID_DO_MOTIVO> pelo id visto no item 2/3.
-- =====================================================================
-- UPDATE treatment_end_reasons SET slug = 'hospitalizacao' WHERE id = <ID_DO_MOTIVO>;
-- UPDATE treatment_end_reasons SET slug = 'termino_periodo_autorizado' WHERE id = <ID_DO_MOTIVO>;

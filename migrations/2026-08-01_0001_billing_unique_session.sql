-- Impedir duplicatas de sessões em billing_document_requirements
-- Cada assignment pode ter no máximo UM registro por session_number

-- Remover duplicatas existentes mantendo o registro com melhor status
-- Prioridade: approved(4) > uploaded(3) > rejected(2) > pending(1)
-- Em caso de empate de status, manter o de maior ID (mais recente)
DELETE bdr FROM billing_document_requirements bdr
INNER JOIN (
    SELECT assignment_id, session_number, 
           MAX(
               CASE status 
                   WHEN 'approved' THEN 4000000000 
                   WHEN 'uploaded' THEN 3000000000 
                   WHEN 'rejected' THEN 2000000000 
                   ELSE 1000000000 
               END + id
           ) as keep_score
    FROM billing_document_requirements
    GROUP BY assignment_id, session_number
    HAVING COUNT(*) > 1
) dup ON bdr.assignment_id = dup.assignment_id AND bdr.session_number = dup.session_number
WHERE (CASE bdr.status 
           WHEN 'approved' THEN 4000000000 
           WHEN 'uploaded' THEN 3000000000 
           WHEN 'rejected' THEN 2000000000 
           ELSE 1000000000 
       END + bdr.id) < dup.keep_score;

-- Adicionar constraint UNIQUE para prevenir futuras duplicatas
ALTER TABLE billing_document_requirements 
ADD UNIQUE INDEX uk_assignment_session (assignment_id, session_number);

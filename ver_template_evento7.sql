-- Mostra o template COMPLETO do evento id 7 e checagens de conteúdo.
SELECT
    id,
    template_professional AS template_completo,
    LENGTH(template_professional) AS tamanho,
    (template_professional LIKE '%link_cadastro%') AS contem_texto_link_cadastro,
    (template_professional LIKE '%{{link_cadastro}}%') AS contem_com_chaves
FROM whatsapp_events
WHERE id = 7;

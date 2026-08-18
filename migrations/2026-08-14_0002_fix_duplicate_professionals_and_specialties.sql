-- ============================================
-- FIX: Remover profissionais duplicados e preencher especialidades
-- Data: 2026-08-14
-- ============================================

-- ============================================
-- PARTE 1: REMOVER DUPLICADOS
-- Estratégia: Para cada nome duplicado, manter o registro com MENOR ID (mais antigo)
-- e atualizar todas as referências (patient_assignments) para apontar para ele.
-- Depois deletar o duplicado.
-- ============================================

-- Atualizar patient_assignments para apontar para o user mais antigo (menor ID) de cada nome duplicado
UPDATE patient_assignments pa
JOIN users u_dup ON u_dup.id = pa.professional_user_id
JOIN (
    SELECT LOWER(name) AS name_lower, MIN(id) AS keep_id
    FROM users
    WHERE email LIKE '%@import%multilife%'
    GROUP BY LOWER(name)
    HAVING COUNT(*) > 1
) dups ON LOWER(u_dup.name) = dups.name_lower AND u_dup.id != dups.keep_id
SET pa.professional_user_id = dups.keep_id,
    pa.professional_remote_jid = CONCAT('import_', dups.keep_id, '@import.local')
WHERE pa.professional_user_id = u_dup.id;

-- Remover roles dos duplicados
DELETE ur FROM user_roles ur
JOIN users u ON u.id = ur.user_id
JOIN (
    SELECT LOWER(name) AS name_lower, MIN(id) AS keep_id
    FROM users
    WHERE email LIKE '%@import%multilife%'
    GROUP BY LOWER(name)
    HAVING COUNT(*) > 1
) dups ON LOWER(u.name) = dups.name_lower AND u.id != dups.keep_id
WHERE u.email LIKE '%@import%multilife%';

-- Deletar os duplicados (mantém o mais antigo)
DELETE u FROM users u
JOIN (
    SELECT LOWER(name) AS name_lower, MIN(id) AS keep_id
    FROM users
    WHERE email LIKE '%@import%multilife%'
    GROUP BY LOWER(name)
    HAVING COUNT(*) > 1
) dups ON LOWER(u.name) = dups.name_lower AND u.id != dups.keep_id
WHERE u.email LIKE '%@import%multilife%';

-- ============================================
-- PARTE 2: Remover duplicados que existiam ANTES da importação
-- (profissional já cadastrado com email real + novo com @import)
-- Manter o registro original (sem @import), atualizar referências
-- ============================================

-- Atualizar patient_assignments para apontar para o user original (sem @import)
UPDATE patient_assignments pa
JOIN users u_import ON u_import.id = pa.professional_user_id AND u_import.email LIKE '%@import%multilife%'
JOIN users u_original ON LOWER(u_original.name) = LOWER(u_import.name) AND u_original.email NOT LIKE '%@import%multilife%'
SET pa.professional_user_id = u_original.id,
    pa.professional_remote_jid = CONCAT('import_', u_original.id, '@import.local');

-- Remover roles dos importados que tinham original
DELETE ur FROM user_roles ur
JOIN users u_import ON u_import.id = ur.user_id AND u_import.email LIKE '%@import%multilife%'
JOIN users u_original ON LOWER(u_original.name) = LOWER(u_import.name) AND u_original.email NOT LIKE '%@import%multilife%';

-- Deletar os importados que tinham original
DELETE u_import FROM users u_import
JOIN users u_original ON LOWER(u_original.name) = LOWER(u_import.name) AND u_original.email NOT LIKE '%@import%multilife%'
WHERE u_import.email LIKE '%@import%multilife%';

-- ============================================
-- PARTE 3: PREENCHER ESPECIALIDADE DE CADA PROFISSIONAL
-- Atualiza o campo `specialty` da tabela users para cada profissional importado
-- ============================================

-- Fonoaudiologia
UPDATE users SET specialty = 'Fonoaudiologia' WHERE LOWER(name) = LOWER('Telma Mara Dos Santos Rodolpho') AND specialty IS NULL;
UPDATE users SET specialty = 'Fonoaudiologia' WHERE LOWER(name) = LOWER('Ana Paula Ferreira Opaso Alvarez Antonucci e Silva') AND specialty IS NULL;
UPDATE users SET specialty = 'Fonoaudiologia' WHERE LOWER(name) = LOWER('Soraia Fátima Marques Restivo') AND specialty IS NULL;
UPDATE users SET specialty = 'Fonoaudiologia' WHERE LOWER(name) = LOWER('Vanessa Alcalá Grilo') AND specialty IS NULL;
UPDATE users SET specialty = 'Fonoaudiologia' WHERE LOWER(name) = LOWER('Ana Alvarez') AND specialty IS NULL;
UPDATE users SET specialty = 'Fonoaudiologia' WHERE LOWER(name) = LOWER('Luciana Taddeo dos Santos Missaci') AND specialty IS NULL;
UPDATE users SET specialty = 'Fonoaudiologia' WHERE LOWER(name) = LOWER('Jussara Aparecida Miranda Franco') AND specialty IS NULL;
UPDATE users SET specialty = 'Fonoaudiologia' WHERE LOWER(name) = LOWER('Bianca Darzinia Fargiani Nishiyama') AND specialty IS NULL;
UPDATE users SET specialty = 'Fonoaudiologia' WHERE LOWER(name) = LOWER('Luana Akemi Yamashita Thomaz') AND specialty IS NULL;
UPDATE users SET specialty = 'Fonoaudiologia' WHERE LOWER(name) = LOWER('LILIANE GREEN FRREIRA') AND specialty IS NULL;
UPDATE users SET specialty = 'Fonoaudiologia' WHERE LOWER(name) = LOWER('Giovanna C. Vitali') AND specialty IS NULL;

-- Enfermagem
UPDATE users SET specialty = 'Enfermagem' WHERE LOWER(name) = LOWER('Ana Laura Giriolli') AND specialty IS NULL;
UPDATE users SET specialty = 'Enfermagem' WHERE LOWER(name) = LOWER('Karen Hikarai Matsubara de Souza') AND specialty IS NULL;
UPDATE users SET specialty = 'Enfermagem' WHERE LOWER(name) = LOWER('Jaqueline Rodrigues de Oliveira Bonani') AND specialty IS NULL;
UPDATE users SET specialty = 'Enfermagem' WHERE LOWER(name) = LOWER('Rafaela Aparecida Bernardes') AND specialty IS NULL;

-- Fisioterapia
UPDATE users SET specialty = 'Fisioterapia' WHERE LOWER(name) = LOWER('Cleonice Duarte de Araujo') AND specialty IS NULL;
UPDATE users SET specialty = 'Fisioterapia' WHERE LOWER(name) = LOWER('Katia Ferreira de Lima Silva') AND specialty IS NULL;
UPDATE users SET specialty = 'Fisioterapia' WHERE LOWER(name) = LOWER('Flavio Silva Pereira') AND specialty IS NULL;
UPDATE users SET specialty = 'Fisioterapia' WHERE LOWER(name) = LOWER('Ledlei Quagliato') AND specialty IS NULL;
UPDATE users SET specialty = 'Fisioterapia' WHERE LOWER(name) = LOWER('Cristina Pontes Diener Rosa') AND specialty IS NULL;
UPDATE users SET specialty = 'Fisioterapia' WHERE LOWER(name) = LOWER('Marcela Aparecida de Oliveira Ribeiro') AND specialty IS NULL;
UPDATE users SET specialty = 'Fisioterapia' WHERE LOWER(name) = LOWER('Willian Eduardo de Almeida') AND specialty IS NULL;
UPDATE users SET specialty = 'Fisioterapia' WHERE LOWER(name) = LOWER('Daniella Ruotolo Joaquim') AND specialty IS NULL;
UPDATE users SET specialty = 'Fisioterapia' WHERE LOWER(name) = LOWER('Francimara Aparecida Costa Pereira') AND specialty IS NULL;
UPDATE users SET specialty = 'Fisioterapia' WHERE LOWER(name) = LOWER('Jean Roberto Campeoto') AND specialty IS NULL;
UPDATE users SET specialty = 'Fisioterapia' WHERE LOWER(name) = LOWER('Katia Eliana Das Neves Soares') AND specialty IS NULL;
UPDATE users SET specialty = 'Fisioterapia' WHERE LOWER(name) = LOWER('Tatiana Cristine Rachid') AND specialty IS NULL;
UPDATE users SET specialty = 'Fisioterapia' WHERE LOWER(name) = LOWER('Anderson Codognoto') AND specialty IS NULL;
UPDATE users SET specialty = 'Fisioterapia' WHERE LOWER(name) = LOWER('Elton Augusto Graciano') AND specialty IS NULL;
UPDATE users SET specialty = 'Fisioterapia' WHERE LOWER(name) = LOWER('Cristina Pontes Silva') AND specialty IS NULL;
UPDATE users SET specialty = 'Fisioterapia' WHERE LOWER(name) = LOWER('Maria Cristina de Carvalho') AND specialty IS NULL;
UPDATE users SET specialty = 'Fisioterapia' WHERE LOWER(name) = LOWER('Mayara Kimberlin Alves') AND specialty IS NULL;
UPDATE users SET specialty = 'Fisioterapia' WHERE LOWER(name) = LOWER('Yasmin dos Santos') AND specialty IS NULL;
UPDATE users SET specialty = 'Fisioterapia' WHERE LOWER(name) = LOWER('Ellem Karoline Pavan') AND specialty IS NULL;
UPDATE users SET specialty = 'Fisioterapia' WHERE LOWER(name) = LOWER('Maria Ivani De Souza Pereira') AND specialty IS NULL;
UPDATE users SET specialty = 'Fisioterapia' WHERE LOWER(name) = LOWER('Cristina dos Reis Bozelli') AND specialty IS NULL;
UPDATE users SET specialty = 'Fisioterapia' WHERE LOWER(name) = LOWER('Camila Cibele Bezerra de Queiroz') AND specialty IS NULL;
UPDATE users SET specialty = 'Fisioterapia' WHERE LOWER(name) = LOWER('Adriana Gonçalves Mendes') AND specialty IS NULL;

-- Nutrição
UPDATE users SET specialty = 'Nutrição' WHERE LOWER(name) = LOWER('Aline Cristina Dias Carmo') AND specialty IS NULL;
UPDATE users SET specialty = 'Nutrição' WHERE LOWER(name) = LOWER('Lana Simone Wanzeller de Melo') AND specialty IS NULL;
UPDATE users SET specialty = 'Nutrição' WHERE LOWER(name) = LOWER('Ana Flávia de Freitas') AND specialty IS NULL;
UPDATE users SET specialty = 'Nutrição' WHERE LOWER(name) = LOWER('Aryane Cunha Louzada') AND specialty IS NULL;
UPDATE users SET specialty = 'Nutrição' WHERE LOWER(name) = LOWER('Elaine Cristina Teixeira Pinto') AND specialty IS NULL;
UPDATE users SET specialty = 'Nutrição' WHERE LOWER(name) = LOWER('Jéssica Geronymo Frias Marciano') AND specialty IS NULL;

-- Medico
UPDATE users SET specialty = 'Medico' WHERE LOWER(name) = LOWER('GUILHERME MENDES MOUCACHEN') AND specialty IS NULL;
UPDATE users SET specialty = 'Medico' WHERE LOWER(name) = LOWER('Hugo Jaime Rodriguez Alvarez') AND specialty IS NULL;
UPDATE users SET specialty = 'Medico' WHERE LOWER(name) = LOWER('DR. SANDRO SEITI') AND specialty IS NULL;
UPDATE users SET specialty = 'Medico' WHERE LOWER(name) = LOWER('Melina Destri Garcia') AND specialty IS NULL;

-- Psicologia
UPDATE users SET specialty = 'Psicologia' WHERE LOWER(name) = LOWER('Camilo Diego Benedetti') AND specialty IS NULL;
UPDATE users SET specialty = 'Psicologia' WHERE LOWER(name) = LOWER('Daniela da Conceição Perez Martim') AND specialty IS NULL;

-- Terapia Ocupacional
UPDATE users SET specialty = 'Terapia Ocupacional' WHERE LOWER(name) = LOWER('Roberta Cristina Santos Bravo') AND specialty IS NULL;
UPDATE users SET specialty = 'Terapia Ocupacional' WHERE LOWER(name) = LOWER('Aldicéia Ribeiro Ferrari Gomes') AND specialty IS NULL;
UPDATE users SET specialty = 'Terapia Ocupacional' WHERE LOWER(name) = LOWER('André Fortini Propheta') AND specialty IS NULL;
UPDATE users SET specialty = 'Terapia Ocupacional' WHERE LOWER(name) = LOWER('Ana Carolina Abrantes Martinelli') AND specialty IS NULL;

-- ============================================
-- PARTE 4: VERIFICAÇÃO
-- ============================================
SELECT 'Profissionais duplicados restantes:' AS verificacao,
       (SELECT COUNT(*) FROM (
           SELECT LOWER(name), COUNT(*) as cnt FROM users
           WHERE email LIKE '%@import%multilife%'
           GROUP BY LOWER(name) HAVING cnt > 1
       ) t) AS duplicados;

SELECT 'Profissionais sem especialidade:' AS verificacao,
       COUNT(*) AS sem_especialidade
FROM users u
JOIN user_roles ur ON ur.user_id = u.id
JOIN roles r ON r.id = ur.role_id
WHERE r.slug = 'profissional' AND (u.specialty IS NULL OR u.specialty = '');

SELECT 'Importação corrigida com sucesso!' AS resultado;

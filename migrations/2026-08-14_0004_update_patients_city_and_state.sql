-- ============================================
-- Atualizar cidade E estado dos pacientes importados
-- O formulário precisa do estado (UF) para carregar as cidades
-- Data: 2026-08-14
-- ============================================

-- FONOTERAPIA / FISIOTERAPIA / PSICOLOGIA / TO - VIP CARE (região Sorocaba = SP)
UPDATE patients SET address_city = 'Sorocaba', address_state = 'SP' WHERE LOWER(full_name) = LOWER('EIKO HIRATA SHIMABUKURO') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'Sorocaba', address_state = 'SP' WHERE LOWER(full_name) = LOWER('FERNANDA CRISTINA MEIKEN MONTEIRO') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'Votorantim', address_state = 'SP' WHERE LOWER(full_name) = LOWER('JOSE CLOVIS LORENA') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'Sorocaba', address_state = 'SP' WHERE LOWER(full_name) = LOWER('JOAO PEDRO CORREA GUIGUER') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'Porto Feliz', address_state = 'SP' WHERE LOWER(full_name) = LOWER('LUIZ CARLOS GONÇALVES ANJA') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'Sorocaba', address_state = 'SP' WHERE LOWER(full_name) = LOWER('LIVIA PACIFICO SILVA') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'Sorocaba', address_state = 'SP' WHERE LOWER(full_name) = LOWER('MARIA DE FATIMA COSSERMELLI SANTOS') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'Sorocaba', address_state = 'SP' WHERE LOWER(full_name) = LOWER('MARIA JOSE BAZZO CUCHERA') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'Porto Feliz', address_state = 'SP' WHERE LOWER(full_name) = LOWER('ROMEU ANTONIO SGARIBOLDI DA SILVA') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'Porto Feliz', address_state = 'SP' WHERE LOWER(full_name) = LOWER('TALITA NABAS DE CARVALHO') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'Sorocaba', address_state = 'SP' WHERE LOWER(full_name) = LOWER('DIRCEU DOMINGUES DE OLIVEIRA') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'Araçoiaba da Serra', address_state = 'SP' WHERE LOWER(full_name) = LOWER('DAVI LUCAS ZUCKER') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'Capela do Alto', address_state = 'SP' WHERE LOWER(full_name) = LOWER('DAVI LUIZ GOMES VIEIRA') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'Sorocaba', address_state = 'SP' WHERE LOWER(full_name) = LOWER('DANIEL SANCHES') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'Sorocaba', address_state = 'SP' WHERE LOWER(full_name) = LOWER('ELVIRA RAMOS VIEIRA') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'Sorocaba', address_state = 'SP' WHERE LOWER(full_name) = LOWER('EDGAR STEFFEN') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'Sorocaba', address_state = 'SP' WHERE LOWER(full_name) = LOWER('HERMELINDA ROSA ALBERTINI') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'Sorocaba', address_state = 'SP' WHERE LOWER(full_name) = LOWER('ISABELLA FERNANDA PASSARO') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'Sorocaba', address_state = 'SP' WHERE LOWER(full_name) = LOWER('LUIS ANTONIO MACHADO PIMENTEL') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'Sorocaba', address_state = 'SP' WHERE LOWER(full_name) = LOWER('MARIANA CRISTINA RODRIGUES') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'Araçoiaba da Serra', address_state = 'SP' WHERE LOWER(full_name) = LOWER('NELSON GUTIERREZ') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'Sorocaba', address_state = 'SP' WHERE LOWER(full_name) = LOWER('SEBASTIÃO SANTOS DA SILVA') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'Sorocaba', address_state = 'SP' WHERE LOWER(full_name) = LOWER('SUELI APARECIDA TRINDADE RODRIGUES GARCIA') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'Boituva', address_state = 'SP' WHERE LOWER(full_name) = LOWER('VIRGINIA VERCELINO PRIMO') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'Sorocaba', address_state = 'SP' WHERE LOWER(full_name) = LOWER('LUCAS HENRIQUE OLIVEIRO CAMARGO') AND deleted_at IS NULL;

-- ENFERMAGEM DAY HOME CARE
UPDATE patients SET address_city = 'Jundiaí', address_state = 'SP' WHERE LOWER(full_name) = LOWER('RONILDO PAULO PEROTTI') AND deleted_at IS NULL;

-- FISIOTERAPIA / ENFERMAGEM / NUTRIÇÃO / MÉDICO - GANEP LAR
UPDATE patients SET address_city = 'Itapetininga', address_state = 'SP' WHERE LOWER(full_name) = LOWER('ISAURA DE MORAES LEONEL FERREIRA') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'São Paulo', address_state = 'SP' WHERE LOWER(full_name) = LOWER('LUIZA CASERTA SCATENA') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'São Paulo', address_state = 'SP' WHERE LOWER(full_name) = LOWER('MARIA DO ROSARIO MARTINS HERMACULA') AND deleted_at IS NULL;

-- FONOTERAPIA GANEP LAR
UPDATE patients SET address_city = 'Mauá', address_state = 'SP' WHERE LOWER(full_name) = LOWER('FRANCISCO JOAQUIM DA SILVA') AND deleted_at IS NULL;

-- FISIOTERAPIA APAS
UPDATE patients SET address_city = 'Bauru', address_state = 'SP' WHERE LOWER(full_name) = LOWER('IRENE MACAGNAN GALVAO DE MOURA') AND deleted_at IS NULL;

-- FONOAUDIOLOGIA APAS
UPDATE patients SET address_city = 'Bauru', address_state = 'SP' WHERE LOWER(full_name) = LOWER('CLAUDIONOR RIBEIRO AGUIAR') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'Bauru', address_state = 'SP' WHERE LOWER(full_name) = LOWER('NIRDE ROSALIN BARBIERI') AND deleted_at IS NULL;

-- FISIOTERAPIA LIFE CARE
UPDATE patients SET address_city = 'Guarulhos', address_state = 'SP' WHERE LOWER(full_name) = LOWER('GILDA ROSANA LEONEL') AND deleted_at IS NULL;

-- FISIOTERAPIA ANERY
UPDATE patients SET address_city = 'Bauru', address_state = 'SP' WHERE LOWER(full_name) = LOWER('JOSE CARLOS PERES') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'Barretos', address_state = 'SP' WHERE LOWER(full_name) = LOWER('SYLVIA MARIA CANOAS MIZIARA') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'Campinas', address_state = 'SP' WHERE LOWER(full_name) = LOWER('THELMA LOPES ONOFRE DE FREITAS RIBEIRO') AND deleted_at IS NULL;

-- NUTRIÇÃO ANERY
UPDATE patients SET address_city = 'São Carlos', address_state = 'SP' WHERE LOWER(full_name) = LOWER('HELENA KLEIN ALMEIDA') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'Ipatinga', address_state = 'MG' WHERE LOWER(full_name) = LOWER('LIGIA DA PENHA CAMPOS') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'Jundiaí', address_state = 'SP' WHERE LOWER(full_name) = LOWER('OTTO FERREIRA') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'Suzano', address_state = 'SP' WHERE LOWER(full_name) = LOWER('SHIRLEI DA CUNHA OLIVEIRA') AND deleted_at IS NULL;

-- ENFERMAGEM LIFE CARE
UPDATE patients SET address_city = 'Rio Claro', address_state = 'SP' WHERE LOWER(full_name) = LOWER('ELCO APPARECIDO FORNAZALI') AND deleted_at IS NULL;

-- FONOTERAPIA LIFE CARE
UPDATE patients SET address_city = 'São Paulo', address_state = 'SP' WHERE LOWER(full_name) = LOWER('GRACI LUIZA DE GODOI FORTES') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'Campinas', address_state = 'SP' WHERE LOWER(full_name) = LOWER('NAELCIO FERREIRA') AND deleted_at IS NULL;

-- MÉDICO LIFE CARE
UPDATE patients SET address_city = 'Joanópolis', address_state = 'SP' WHERE LOWER(full_name) = LOWER('ANTONIO BUENO DE CAMARGO') AND deleted_at IS NULL;

-- TERAPIA OCUPACIONAL AUSTA
UPDATE patients SET address_city = 'Buritama', address_state = 'SP' WHERE LOWER(full_name) = LOWER('EDINAMARA APARECIDA BISPO DE SOUZA') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'José Bonifácio', address_state = 'SP' WHERE LOWER(full_name) = LOWER('THEREZA VICENTIM CHERONE') AND deleted_at IS NULL;

-- TERAPIA OCUPACIONAL ANERY
UPDATE patients SET address_city = 'Itaquaquecetuba', address_state = 'SP' WHERE LOWER(full_name) = LOWER('NATHALY ROCHA DE SOUZA') AND deleted_at IS NULL;

-- ============================================
SELECT 'Cidades e estados atualizados com sucesso!' AS resultado;

-- ============================================
-- Atualizar cidade dos pacientes importados
-- Dados extraídos das planilhas
-- Data: 2026-08-14
-- ============================================

-- FONOTERAPIA VIP CARE
UPDATE patients SET address_city = 'SOROCABA' WHERE LOWER(full_name) = LOWER('EIKO HIRATA SHIMABUKURO') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'SOROCABA' WHERE LOWER(full_name) = LOWER('FERNANDA CRISTINA MEIKEN MONTEIRO') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'VOTORANTIM' WHERE LOWER(full_name) = LOWER('JOSE CLOVIS LORENA') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'SOROCABA' WHERE LOWER(full_name) = LOWER('JOAO PEDRO CORREA GUIGUER') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'PORTO FELIZ' WHERE LOWER(full_name) = LOWER('LUIZ CARLOS GONÇALVES ANJA') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'SOROCABA' WHERE LOWER(full_name) = LOWER('LIVIA PACIFICO SILVA') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'SOROCABA' WHERE LOWER(full_name) = LOWER('MARIA DE FATIMA COSSERMELLI SANTOS') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'SOROCABA' WHERE LOWER(full_name) = LOWER('MARIA JOSE BAZZO CUCHERA') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'PORTO FELIZ' WHERE LOWER(full_name) = LOWER('ROMEU ANTONIO SGARIBOLDI DA SILVA') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'PORTO FELIZ' WHERE LOWER(full_name) = LOWER('TALITA NABAS DE CARVALHO') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;

-- ENFERMAGEM DAY HOME CARE
UPDATE patients SET address_city = 'JUNDIAI' WHERE LOWER(full_name) = LOWER('RONILDO PAULO PEROTTI') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;

-- FISIOTERAPIA GANEP LAR
UPDATE patients SET address_city = 'ITAPETININGA' WHERE LOWER(full_name) = LOWER('ISAURA DE MORAES LEONEL FERREIRA') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'SÃO PAULO' WHERE LOWER(full_name) = LOWER('LUIZA CASERTA SCATENA') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'SÃO PAULO' WHERE LOWER(full_name) = LOWER('MARIA DO ROSARIO MARTINS HERMACULA') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;

-- FISIOTERAPIA VIP CARE
UPDATE patients SET address_city = 'SOROCABA' WHERE LOWER(full_name) = LOWER('DIRCEU DOMINGUES DE OLIVEIRA') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'ARAÇOIABA DA SERRA' WHERE LOWER(full_name) = LOWER('DAVI LUCAS ZUCKER') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'CAPELA DO ALTO' WHERE LOWER(full_name) = LOWER('DAVI LUIZ GOMES VIEIRA') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'SOROCABA' WHERE LOWER(full_name) = LOWER('DANIEL SANCHES') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'SOROCABA' WHERE LOWER(full_name) = LOWER('ELVIRA RAMOS VIEIRA') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'SOROCABA' WHERE LOWER(full_name) = LOWER('EDGAR STEFFEN') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'SOROCABA' WHERE LOWER(full_name) = LOWER('HERMELINDA ROSA ALBERTINI') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'SOROCABA' WHERE LOWER(full_name) = LOWER('ISABELLA FERNANDA PASSARO') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'SOROCABA' WHERE LOWER(full_name) = LOWER('LUIS ANTONIO MACHADO PIMENTEL') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'SOROCABA' WHERE LOWER(full_name) = LOWER('MARIANA CRISTINA RODRIGUES') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'ARAÇOIABA DA SERRA' WHERE LOWER(full_name) = LOWER('NELSON GUTIERREZ') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'SOROCABA' WHERE LOWER(full_name) = LOWER('SEBASTIÃO SANTOS DA SILVA') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'SOROCABA' WHERE LOWER(full_name) = LOWER('SUELI APARECIDA TRINDADE RODRIGUES GARCIA') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'BOITUVA' WHERE LOWER(full_name) = LOWER('VIRGINIA VERCELINO PRIMO') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;

-- FISIOTERAPIA APAS
UPDATE patients SET address_city = 'BAURU' WHERE LOWER(full_name) = LOWER('IRENE MACAGNAN GALVAO DE MOURA') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;

-- FONOAUDIOLOGIA APAS
UPDATE patients SET address_city = 'BAURU' WHERE LOWER(full_name) = LOWER('CLAUDIONOR RIBEIRO AGUIAR') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'BAURU' WHERE LOWER(full_name) = LOWER('NIRDE ROSALIN BARBIERI') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;

-- FONOTERAPIA GANEP LAR
UPDATE patients SET address_city = 'MAUÁ' WHERE LOWER(full_name) = LOWER('FRANCISCO JOAQUIM DA SILVA') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;

-- FISIOTERAPIA LIFE CARE
UPDATE patients SET address_city = 'GUARULHOS' WHERE LOWER(full_name) = LOWER('GILDA ROSANA LEONEL') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;

-- FISIOTERAPIA ANERY
UPDATE patients SET address_city = 'BAURU' WHERE LOWER(full_name) = LOWER('JOSE CARLOS PERES') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'BARRETOS' WHERE LOWER(full_name) = LOWER('SYLVIA MARIA CANOAS MIZIARA') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'CAMPINAS' WHERE LOWER(full_name) = LOWER('THELMA LOPES ONOFRE DE FREITAS RIBEIRO') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;

-- NUTRIÇÃO ANERY
UPDATE patients SET address_city = 'SÃO CARLOS' WHERE LOWER(full_name) = LOWER('HELENA KLEIN ALMEIDA') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'IPATINGA' WHERE LOWER(full_name) = LOWER('LIGIA DA PENHA CAMPOS') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'JUNDIAI' WHERE LOWER(full_name) = LOWER('OTTO FERREIRA') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'SUZANO' WHERE LOWER(full_name) = LOWER('SHIRLEI DA CUNHA OLIVEIRA') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;

-- ENFERMAGEM LIFE CARE
UPDATE patients SET address_city = 'RIO CLARO' WHERE LOWER(full_name) = LOWER('ELCO APPARECIDO FORNAZALI') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;

-- FONOTERAPIA LIFE CARE
UPDATE patients SET address_city = 'SÃO PAULO' WHERE LOWER(full_name) = LOWER('GRACI LUIZA DE GODOI FORTES') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'CAMPINAS' WHERE LOWER(full_name) = LOWER('NAELCIO FERREIRA') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;

-- MÉDICO LIFE CARE
UPDATE patients SET address_city = 'JOANOPOLIS' WHERE LOWER(full_name) = LOWER('ANTONIO BUENO DE CAMARGO') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;

-- TERAPIA OCUPACIONAL VIP CARE
UPDATE patients SET address_city = 'SOROCABA' WHERE LOWER(full_name) = LOWER('LUCAS HENRIQUE OLIVEIRO CAMARGO') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;

-- TERAPIA OCUPACIONAL AUSTA
UPDATE patients SET address_city = 'BURITAMA' WHERE LOWER(full_name) = LOWER('EDINAMARA APARECIDA BISPO DE SOUZA') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;
UPDATE patients SET address_city = 'JOSE BONIFACIO' WHERE LOWER(full_name) = LOWER('THEREZA VICENTIM CHERONE') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;

-- TERAPIA OCUPACIONAL ANERY
UPDATE patients SET address_city = 'ITAQUAQUECETUBA' WHERE LOWER(full_name) = LOWER('NATHALY ROCHA DE SOUZA') AND (address_city IS NULL OR address_city = '') AND deleted_at IS NULL;

-- PSICOLOGIA VIP CARE (cidades já definidas acima via fono/fisio)

-- ============================================
SELECT 'Cidades dos pacientes atualizadas!' AS resultado;

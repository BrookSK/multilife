-- ============================================
-- IMPORTAÇÃO DE PLANILHAS - ESPECIALIDADES, PACIENTES, PROFISSIONAIS E MONITORAMENTO
-- Gerado automaticamente a partir dos CSVs de imports/
-- Data: 2026-08-14
-- ============================================

-- ============================================
-- 1. ESPECIALIDADES (garantir que existem)
-- ============================================
INSERT INTO specialties (name, status) VALUES ('Fisioterapia', 'active')
ON DUPLICATE KEY UPDATE name = name;

INSERT INTO specialties (name, status) VALUES ('Fonoaudiologia', 'active')
ON DUPLICATE KEY UPDATE name = name;

INSERT INTO specialties (name, status) VALUES ('Enfermagem', 'active')
ON DUPLICATE KEY UPDATE name = name;

INSERT INTO specialties (name, status) VALUES ('Medico', 'active')
ON DUPLICATE KEY UPDATE name = name;

INSERT INTO specialties (name, status) VALUES ('Nutrição', 'active')
ON DUPLICATE KEY UPDATE name = name;

INSERT INTO specialties (name, status) VALUES ('Terapia Ocupacional', 'active')
ON DUPLICATE KEY UPDATE name = name;

INSERT INTO specialties (name, status) VALUES ('Psicologia', 'active')
ON DUPLICATE KEY UPDATE name = name;

-- ============================================
-- 2. PACIENTES (somente nome, evita duplicatas)
-- ============================================
INSERT INTO patients (full_name) SELECT 'EIKO HIRATA SHIMABUKURO' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('EIKO HIRATA SHIMABUKURO') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'FERNANDA CRISTINA MEIKEN MONTEIRO' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('FERNANDA CRISTINA MEIKEN MONTEIRO') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'JOSE CLOVIS LORENA' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('JOSE CLOVIS LORENA') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'JOAO PEDRO CORREA GUIGUER' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('JOAO PEDRO CORREA GUIGUER') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'LUIZ CARLOS GONÇALVES ANJA' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('LUIZ CARLOS GONÇALVES ANJA') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'LIVIA PACIFICO SILVA' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('LIVIA PACIFICO SILVA') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'MARIA DE FATIMA COSSERMELLI SANTOS' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('MARIA DE FATIMA COSSERMELLI SANTOS') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'MARIA JOSE BAZZO CUCHERA' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('MARIA JOSE BAZZO CUCHERA') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'ROMEU ANTONIO SGARIBOLDI DA SILVA' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('ROMEU ANTONIO SGARIBOLDI DA SILVA') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'TALITA NABAS DE CARVALHO' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('TALITA NABAS DE CARVALHO') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'RONILDO PAULO PEROTTI' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('RONILDO PAULO PEROTTI') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'ISAURA DE MORAES LEONEL FERREIRA' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('ISAURA DE MORAES LEONEL FERREIRA') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'LUIZA CASERTA SCATENA' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('LUIZA CASERTA SCATENA') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'MARIA DO ROSARIO MARTINS HERMACULA' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('MARIA DO ROSARIO MARTINS HERMACULA') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'DIRCEU DOMINGUES DE OLIVEIRA' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('DIRCEU DOMINGUES DE OLIVEIRA') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'DAVI LUCAS ZUCKER' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('DAVI LUCAS ZUCKER') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'DAVI LUIZ GOMES VIEIRA' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('DAVI LUIZ GOMES VIEIRA') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'DANIEL SANCHES' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('DANIEL SANCHES') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'ELVIRA RAMOS VIEIRA' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('ELVIRA RAMOS VIEIRA') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'EDGAR STEFFEN' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('EDGAR STEFFEN') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'HERMELINDA ROSA ALBERTINI' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('HERMELINDA ROSA ALBERTINI') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'ISABELLA FERNANDA PASSARO' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('ISABELLA FERNANDA PASSARO') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'LUIS ANTONIO MACHADO PIMENTEL' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('LUIS ANTONIO MACHADO PIMENTEL') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'MARIANA CRISTINA RODRIGUES' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('MARIANA CRISTINA RODRIGUES') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'NELSON GUTIERREZ' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('NELSON GUTIERREZ') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'SEBASTIÃO SANTOS DA SILVA' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('SEBASTIÃO SANTOS DA SILVA') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'SUELI APARECIDA TRINDADE RODRIGUES GARCIA' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('SUELI APARECIDA TRINDADE RODRIGUES GARCIA') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'VIRGINIA VERCELINO PRIMO' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('VIRGINIA VERCELINO PRIMO') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'IRENE MACAGNAN GALVAO DE MOURA' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('IRENE MACAGNAN GALVAO DE MOURA') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'CLAUDIONOR RIBEIRO AGUIAR' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('CLAUDIONOR RIBEIRO AGUIAR') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'NIRDE ROSALIN BARBIERI' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('NIRDE ROSALIN BARBIERI') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'FRANCISCO JOAQUIM DA SILVA' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('FRANCISCO JOAQUIM DA SILVA') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'GILDA ROSANA LEONEL' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('GILDA ROSANA LEONEL') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'JOSE CARLOS PERES' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('JOSE CARLOS PERES') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'SYLVIA MARIA CANOAS MIZIARA' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('SYLVIA MARIA CANOAS MIZIARA') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'THELMA LOPES ONOFRE DE FREITAS RIBEIRO' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('THELMA LOPES ONOFRE DE FREITAS RIBEIRO') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'HELENA KLEIN ALMEIDA' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('HELENA KLEIN ALMEIDA') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'LIGIA DA PENHA CAMPOS' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('LIGIA DA PENHA CAMPOS') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'OTTO FERREIRA' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('OTTO FERREIRA') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'SHIRLEI DA CUNHA OLIVEIRA' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('SHIRLEI DA CUNHA OLIVEIRA') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'ELCO APPARECIDO FORNAZALI' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('ELCO APPARECIDO FORNAZALI') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'GRACI LUIZA DE GODOI FORTES' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('GRACI LUIZA DE GODOI FORTES') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'NAELCIO FERREIRA' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('NAELCIO FERREIRA') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'ANTONIO BUENO DE CAMARGO' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('ANTONIO BUENO DE CAMARGO') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'LUCAS HENRIQUE OLIVEIRO CAMARGO' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('LUCAS HENRIQUE OLIVEIRO CAMARGO') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'EDINAMARA APARECIDA BISPO DE SOUZA' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('EDINAMARA APARECIDA BISPO DE SOUZA') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'THEREZA VICENTIM CHERONE' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('THEREZA VICENTIM CHERONE') AND deleted_at IS NULL);
INSERT INTO patients (full_name) SELECT 'NATHALY ROCHA DE SOUZA' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE LOWER(full_name) = LOWER('NATHALY ROCHA DE SOUZA') AND deleted_at IS NULL);

-- ============================================
-- 3. PROFISSIONAIS (users com role 'profissional')
-- Cada profissional recebe email fictício @import.multilife.local
-- Senha aleatória (bcrypt de 'ImportTemp2026!')
-- ============================================
SET @prof_password_hash = '$2y$10$XKzV1U2v3W4x5Y6z7A8bCeD9fG0hI1jK2lM3nO4pQ5rS6tU7vW8x';

-- Profissionais da FONOTERAPIA VIP CARE
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Telma Mara Dos Santos Rodolpho', 'telma.mara.dos.santos.rodolpho@import.multilife.local', '15997347474', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Telma Mara Dos Santos Rodolpho'));
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Ana Paula Ferreira Opaso Alvarez Antonucci e Silva', 'ana.paula.ferreira.opaso.alvarez.antonucci.e.silva@import.multilife.local', '15981377940', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Ana Paula Ferreira Opaso Alvarez Antonucci e Silva'));
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Soraia Fátima Marques Restivo', 'soraia.fatima.marques.restivo@import.multilife.local', '15996031701', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Soraia Fátima Marques Restivo'));
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Vanessa Alcalá Grilo', 'vanessa.alcala.grilo@import.multilife.local', '15997019033', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Vanessa Alcalá Grilo'));
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Ana Alvarez', 'ana.alvarez@import.multilife.local', '15981377940', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Ana Alvarez'));
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Luciana Taddeo dos Santos Missaci', 'luciana.taddeo.dos.santos.missaci@import.multilife.local', '15998562727', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Luciana Taddeo dos Santos Missaci'));

-- Profissionais da ENFERMAGEM DAY
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Ana Laura Giriolli', 'ana.laura.giriolli@import.multilife.local', '11984018168', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Ana Laura Giriolli'));

-- Profissionais da FISIOTERAPIA GANEP LAR
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Cleonice Duarte de Araujo', 'cleonice.duarte.de.araujo@import.multilife.local', '15996432674', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Cleonice Duarte de Araujo'));
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Katia Ferreira de Lima Silva', 'katia.ferreira.de.lima.silva@import.multilife.local', '11953777388', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Katia Ferreira de Lima Silva'));
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Flavio Silva Pereira', 'flavio.silva.pereira@import.multilife.local', '11977517876', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Flavio Silva Pereira'));

-- Profissionais da FISIOTERAPIA VIP CARE
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Ledlei Quagliato', 'ledlei.quagliato@import.multilife.local', '15974050579', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Ledlei Quagliato'));
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Cristina Pontes Diener Rosa', 'cristina.pontes.diener.rosa@import.multilife.local', '15997922125', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Cristina Pontes Diener Rosa'));
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Marcela Aparecida de Oliveira Ribeiro', 'marcela.aparecida.de.oliveira.ribeiro@import.multilife.local', '15996226106', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Marcela Aparecida de Oliveira Ribeiro'));
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Willian Eduardo de Almeida', 'willian.eduardo.de.almeida@import.multilife.local', '15996738653', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Willian Eduardo de Almeida'));
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Daniella Ruotolo Joaquim', 'daniella.ruotolo.joaquim@import.multilife.local', '15996772780', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Daniella Ruotolo Joaquim'));
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Francimara Aparecida Costa Pereira', 'francimara.aparecida.costa.pereira@import.multilife.local', '15991457779', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Francimara Aparecida Costa Pereira'));
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Jean Roberto Campeoto', 'jean.roberto.campeoto@import.multilife.local', '15997991219', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Jean Roberto Campeoto'));
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Katia Eliana Das Neves Soares', 'katia.eliana.das.neves.soares@import.multilife.local', '15997959267', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Katia Eliana Das Neves Soares'));
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Tatiana Cristine Rachid', 'tatiana.cristine.rachid@import.multilife.local', '15997210127', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Tatiana Cristine Rachid'));
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Anderson Codognoto', 'anderson.codognoto@import.multilife.local', '15997030277', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Anderson Codognoto'));
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Elton Augusto Graciano', 'elton.augusto.graciano@import.multilife.local', '15988313642', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Elton Augusto Graciano'));
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Cristina Pontes Silva', 'cristina.pontes.silva@import.multilife.local', '15997922125', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Cristina Pontes Silva'));
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Maria Cristina de Carvalho', 'maria.cristina.de.carvalho@import.multilife.local', '15996820413', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Maria Cristina de Carvalho'));
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Mayara Kimberlin Alves', 'mayara.kimberlin.alves@import.multilife.local', '15998250325', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Mayara Kimberlin Alves'));
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Yasmin dos Santos', 'yasmin.dos.santos@import.multilife.local', '11966095536', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Yasmin dos Santos'));

-- Profissionais da FISIOTERAPIA APAS
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Ellem Karoline Pavan', 'ellem.karoline.pavan@import.multilife.local', '14997115761', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Ellem Karoline Pavan'));

-- Profissionais da FONOAUDIOLOGIA APAS
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Jussara Aparecida Miranda Franco', 'jussara.aparecida.miranda.franco@import.multilife.local', '14991514548', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Jussara Aparecida Miranda Franco'));

-- Profissionais da FONOTERAPIA GANEP LAR
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Bianca Darzinia Fargiani Nishiyama', 'bianca.darzinia.fargiani.nishiyama@import.multilife.local', '11956568191', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Bianca Darzinia Fargiani Nishiyama'));
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Luana Akemi Yamashita Thomaz', 'luana.akemi.yamashita.thomaz@import.multilife.local', '11976264736', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Luana Akemi Yamashita Thomaz'));

-- Profissionais da ENFERMAGEM GANEP LAR
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Karen Hikarai Matsubara de Souza', 'karen.hikarai.matsubara.de.souza@import.multilife.local', '15996628976', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Karen Hikarai Matsubara de Souza'));

-- Profissionais da NUTRIÇÃO GANEP LAR
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Aline Cristina Dias Carmo', 'aline.cristina.dias.carmo@import.multilife.local', '15991517691', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Aline Cristina Dias Carmo'));

-- Profissionais da MÉDICO GANEP LAR
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'GUILHERME MENDES MOUCACHEN', 'guilherme.mendes.moucachen@import.multilife.local', '15991758559', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('GUILHERME MENDES MOUCACHEN'));

-- Profissionais da FISIOTERAPIA LIFE CARE
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Maria Ivani De Souza Pereira', 'maria.ivani.de.souza.pereira@import.multilife.local', '11999418143', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Maria Ivani De Souza Pereira'));

-- Profissionais da ENFERMAGEM LIFE CARE
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Jaqueline Rodrigues de Oliveira Bonani', 'jaqueline.rodrigues.de.oliveira.bonani@import.multilife.local', '19997047838', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Jaqueline Rodrigues de Oliveira Bonani'));
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Rafaela Aparecida Bernardes', 'rafaela.aparecida.bernardes@import.multilife.local', '19987462968', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Rafaela Aparecida Bernardes'));

-- Profissionais da FONOTERAPIA LIFE CARE
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'LILIANE GREEN FRREIRA', 'liliane.green.frreira@import.multilife.local', '11982032204', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('LILIANE GREEN FRREIRA'));
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Giovanna C. Vitali', 'giovanna.c.vitali@import.multilife.local', '19982649777', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Giovanna C. Vitali'));

-- Profissionais da NUTRIÇÃO LIFE CARE
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Lana Simone Wanzeller de Melo', 'lana.simone.wanzeller.de.melo@import.multilife.local', '19995486744', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Lana Simone Wanzeller de Melo'));

-- Profissionais da MÉDICO LIFE CARE
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Hugo Jaime Rodriguez Alvarez', 'hugo.jaime.rodriguez.alvarez@import.multilife.local', '11933057934', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Hugo Jaime Rodriguez Alvarez'));

-- Profissionais da FISIOTERAPIA ANERY
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Cristina dos Reis Bozelli', 'cristina.dos.reis.bozelli@import.multilife.local', '14996947598', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Cristina dos Reis Bozelli'));
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Camila Cibele Bezerra de Queiroz', 'camila.cibele.bezerra.de.queiroz@import.multilife.local', '17982274318', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Camila Cibele Bezerra de Queiroz'));
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Adriana Gonçalves Mendes', 'adriana.goncalves.mendes@import.multilife.local', '11983486581', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Adriana Gonçalves Mendes'));

-- Profissionais da MÉDICO ANERY
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'DR. SANDRO SEITI', 'dr.sandro.seiti@import.multilife.local', '16992584608', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('DR. SANDRO SEITI'));
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Melina Destri Garcia', 'melina.destri.garcia@import.multilife.local', '17996022430', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Melina Destri Garcia'));

-- Profissionais da NUTRIÇÃO ANERY
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Ana Flávia de Freitas', 'ana.flavia.de.freitas@import.multilife.local', '17981181030', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Ana Flávia de Freitas'));
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Aryane Cunha Louzada', 'aryane.cunha.louzada@import.multilife.local', '3187748342', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Aryane Cunha Louzada'));
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Elaine Cristina Teixeira Pinto', 'elaine.cristina.teixeira.pinto@import.multilife.local', '11959705243', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Elaine Cristina Teixeira Pinto'));
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Jéssica Geronymo Frias Marciano', 'jessica.geronymo.frias.marciano@import.multilife.local', '11971831528', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Jéssica Geronymo Frias Marciano'));

-- Profissionais da PSICOLOGIA VIP CARE
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Camilo Diego Benedetti', 'camilo.diego.benedetti@import.multilife.local', '15996866517', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Camilo Diego Benedetti'));
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Daniela da Conceição Perez Martim', 'daniela.da.conceicao.perez.martim@import.multilife.local', '15991071478', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Daniela da Conceição Perez Martim'));

-- Profissionais da TERAPIA OCUPACIONAL VIP CARE
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Roberta Cristina Santos Bravo', 'roberta.cristina.santos.bravo@import.multilife.local', '15981142443', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Roberta Cristina Santos Bravo'));

-- Profissionais da TERAPIA OCUPACIONAL AUSTA
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Aldicéia Ribeiro Ferrari Gomes', 'aldiceia.ribeiro.ferrari.gomes@import.multilife.local', '18997197521', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Aldicéia Ribeiro Ferrari Gomes'));
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'André Fortini Propheta', 'andre.fortini.propheta@import.multilife.local', '14982329049', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('André Fortini Propheta'));

-- Profissionais da TERAPIA OCUPACIONAL ANERY
INSERT INTO users (name, email, phone, password_hash, status) SELECT 'Ana Carolina Abrantes Martinelli', 'ana.carolina.abrantes.martinelli@import.multilife.local', '12991378599', @prof_password_hash, 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE LOWER(name) = LOWER('Ana Carolina Abrantes Martinelli'));

-- ============================================
-- 4. ATRIBUIR ROLE 'profissional' A TODOS OS IMPORTADOS
-- ============================================
INSERT IGNORE INTO user_roles (user_id, role_id)
SELECT u.id, r.id
FROM users u
CROSS JOIN roles r
WHERE u.email LIKE '%@import.multilife.local'
  AND r.slug = 'profissional'
  AND NOT EXISTS (
    SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role_id = r.id
  );

-- ============================================
-- 5. DEMANDS (uma por arquivo/especialidade+empresa)
-- ============================================
INSERT INTO demands (title, specialty, status, description) VALUES ('Importação Fonoaudiologia - VIP CARE', 'Fonoaudiologia', 'admitido', 'Importado via planilha');
SET @demand_fono_vip = LAST_INSERT_ID();

INSERT INTO demands (title, specialty, status, description) VALUES ('Importação Enfermagem - DAY HOME CARE', 'Enfermagem', 'admitido', 'Importado via planilha');
SET @demand_enf_day = LAST_INSERT_ID();

INSERT INTO demands (title, specialty, status, description) VALUES ('Importação Fisioterapia - GANEP LAR', 'Fisioterapia', 'admitido', 'Importado via planilha');
SET @demand_fisio_ganep = LAST_INSERT_ID();

INSERT INTO demands (title, specialty, status, description) VALUES ('Importação Fisioterapia - VIP CARE', 'Fisioterapia', 'admitido', 'Importado via planilha');
SET @demand_fisio_vip = LAST_INSERT_ID();

INSERT INTO demands (title, specialty, status, description) VALUES ('Importação Fisioterapia - APAS', 'Fisioterapia', 'admitido', 'Importado via planilha');
SET @demand_fisio_apas = LAST_INSERT_ID();

INSERT INTO demands (title, specialty, status, description) VALUES ('Importação Fonoaudiologia - APAS', 'Fonoaudiologia', 'admitido', 'Importado via planilha');
SET @demand_fono_apas = LAST_INSERT_ID();

INSERT INTO demands (title, specialty, status, description) VALUES ('Importação Fonoaudiologia - GANEP LAR', 'Fonoaudiologia', 'admitido', 'Importado via planilha');
SET @demand_fono_ganep = LAST_INSERT_ID();

INSERT INTO demands (title, specialty, status, description) VALUES ('Importação Enfermagem - GANEP LAR', 'Enfermagem', 'admitido', 'Importado via planilha');
SET @demand_enf_ganep = LAST_INSERT_ID();

INSERT INTO demands (title, specialty, status, description) VALUES ('Importação Nutrição - GANEP LAR', 'Nutrição', 'admitido', 'Importado via planilha');
SET @demand_nutri_ganep = LAST_INSERT_ID();

INSERT INTO demands (title, specialty, status, description) VALUES ('Importação Medico - GANEP LAR', 'Medico', 'admitido', 'Importado via planilha');
SET @demand_med_ganep = LAST_INSERT_ID();

INSERT INTO demands (title, specialty, status, description) VALUES ('Importação Fisioterapia - LIFE CARE', 'Fisioterapia', 'admitido', 'Importado via planilha');
SET @demand_fisio_life = LAST_INSERT_ID();

INSERT INTO demands (title, specialty, status, description) VALUES ('Importação Enfermagem - LIFE CARE', 'Enfermagem', 'admitido', 'Importado via planilha');
SET @demand_enf_life = LAST_INSERT_ID();

INSERT INTO demands (title, specialty, status, description) VALUES ('Importação Fonoaudiologia - LIFE CARE', 'Fonoaudiologia', 'admitido', 'Importado via planilha');
SET @demand_fono_life = LAST_INSERT_ID();

INSERT INTO demands (title, specialty, status, description) VALUES ('Importação Nutrição - LIFE CARE', 'Nutrição', 'admitido', 'Importado via planilha');
SET @demand_nutri_life = LAST_INSERT_ID();

INSERT INTO demands (title, specialty, status, description) VALUES ('Importação Medico - LIFE CARE', 'Medico', 'admitido', 'Importado via planilha');
SET @demand_med_life = LAST_INSERT_ID();

INSERT INTO demands (title, specialty, status, description) VALUES ('Importação Fisioterapia - ANERY', 'Fisioterapia', 'admitido', 'Importado via planilha');
SET @demand_fisio_anery = LAST_INSERT_ID();

INSERT INTO demands (title, specialty, status, description) VALUES ('Importação Medico - ANERY', 'Medico', 'admitido', 'Importado via planilha');
SET @demand_med_anery = LAST_INSERT_ID();

INSERT INTO demands (title, specialty, status, description) VALUES ('Importação Nutrição - ANERY', 'Nutrição', 'admitido', 'Importado via planilha');
SET @demand_nutri_anery = LAST_INSERT_ID();

INSERT INTO demands (title, specialty, status, description) VALUES ('Importação Terapia Ocupacional - ANERY', 'Terapia Ocupacional', 'admitido', 'Importado via planilha');
SET @demand_to_anery = LAST_INSERT_ID();

INSERT INTO demands (title, specialty, status, description) VALUES ('Importação Psicologia - VIP CARE', 'Psicologia', 'admitido', 'Importado via planilha');
SET @demand_psico_vip = LAST_INSERT_ID();

INSERT INTO demands (title, specialty, status, description) VALUES ('Importação Terapia Ocupacional - VIP CARE', 'Terapia Ocupacional', 'admitido', 'Importado via planilha');
SET @demand_to_vip = LAST_INSERT_ID();

INSERT INTO demands (title, specialty, status, description) VALUES ('Importação Terapia Ocupacional - AUSTA', 'Terapia Ocupacional', 'admitido', 'Importado via planilha');
SET @demand_to_austa = LAST_INSERT_ID();

-- ============================================
-- 6. PATIENT_ASSIGNMENTS (monitoramento)
-- Vincula paciente, profissional, especialidade, frequência e quantidade
-- ============================================

-- Pegar o ID do admin para assigned_by
SET @admin_id = (SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.slug = 'admin' ORDER BY u.id ASC LIMIT 1);

-- === FONOTERAPIA VIP CARE ===
INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fono_vip, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fonoaudiologia', 9, '2x por semana', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('EIKO HIRATA SHIMABUKURO') AND LOWER(u.name) = LOWER('Telma Mara Dos Santos Rodolpho') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fono_vip, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fonoaudiologia', 13, '3x por semana', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('FERNANDA CRISTINA MEIKEN MONTEIRO') AND LOWER(u.name) = LOWER('Ana Paula Ferreira Opaso Alvarez Antonucci e Silva') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fono_vip, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fonoaudiologia', 9, '2x por semana', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('JOSE CLOVIS LORENA') AND LOWER(u.name) = LOWER('Ana Paula Ferreira Opaso Alvarez Antonucci e Silva') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fono_vip, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fonoaudiologia', 1, '2x por semana', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('JOAO PEDRO CORREA GUIGUER') AND LOWER(u.name) = LOWER('Soraia Fátima Marques Restivo') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fono_vip, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fonoaudiologia', 5, '1x por semana', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('LUIZ CARLOS GONÇALVES ANJA') AND LOWER(u.name) = LOWER('Vanessa Alcalá Grilo') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fono_vip, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fonoaudiologia', 9, '2x por semana', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('LIVIA PACIFICO SILVA') AND LOWER(u.name) = LOWER('Ana Alvarez') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fono_vip, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fonoaudiologia', 9, '2x por semana', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('MARIA DE FATIMA COSSERMELLI SANTOS') AND LOWER(u.name) = LOWER('Telma Mara Dos Santos Rodolpho') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fono_vip, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fonoaudiologia', 9, '2x por semana', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('MARIA JOSE BAZZO CUCHERA') AND LOWER(u.name) = LOWER('Telma Mara Dos Santos Rodolpho') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fono_vip, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fonoaudiologia', 21, '5x por semana', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('ROMEU ANTONIO SGARIBOLDI DA SILVA') AND LOWER(u.name) = LOWER('Vanessa Alcalá Grilo') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fono_vip, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fonoaudiologia', 6, '2x por semana', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('TALITA NABAS DE CARVALHO') AND LOWER(u.name) = LOWER('Luciana Taddeo dos Santos Missaci') AND p.deleted_at IS NULL LIMIT 1;

-- === ENFERMAGEM DAY HOME CARE ===
INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_enf_day, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Enfermagem', 1, '1x por dia', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('RONILDO PAULO PEROTTI') AND LOWER(u.name) = LOWER('Ana Laura Giriolli') AND p.deleted_at IS NULL LIMIT 1;

-- === FISIOTERAPIA GANEP LAR ===
INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fisio_ganep, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fisioterapia', 5, '2x por semana', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('ISAURA DE MORAES LEONEL FERREIRA') AND LOWER(u.name) = LOWER('Cleonice Duarte de Araujo') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fisio_ganep, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fisioterapia', 2, '5x por semana', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('LUIZA CASERTA SCATENA') AND LOWER(u.name) = LOWER('Katia Ferreira de Lima Silva') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fisio_ganep, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fisioterapia', 1, '3x por semana', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('MARIA DO ROSARIO MARTINS HERMACULA') AND LOWER(u.name) = LOWER('Flavio Silva Pereira') AND p.deleted_at IS NULL LIMIT 1;

-- === FISIOTERAPIA VIP CARE ===
INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fisio_vip, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fisioterapia', 9, '2x por semana', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('DIRCEU DOMINGUES DE OLIVEIRA') AND LOWER(u.name) = LOWER('Ledlei Quagliato') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fisio_vip, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fisioterapia', 9, '2x por semana', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('DAVI LUCAS ZUCKER') AND LOWER(u.name) = LOWER('Cristina Pontes Diener Rosa') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fisio_vip, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fisioterapia', 14, '3x por semana', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('DAVI LUCAS ZUCKER') AND LOWER(u.name) = LOWER('Marcela Aparecida de Oliveira Ribeiro') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fisio_vip, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fisioterapia', 31, '1x por dia', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('DAVI LUIZ GOMES VIEIRA') AND LOWER(u.name) = LOWER('Willian Eduardo de Almeida') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fisio_vip, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fisioterapia', 3, '3x por semana', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('DANIEL SANCHES') AND LOWER(u.name) = LOWER('Ledlei Quagliato') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fisio_vip, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fisioterapia', 25, '1x por dia', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('ELVIRA RAMOS VIEIRA') AND LOWER(u.name) = LOWER('Francimara Aparecida Costa Pereira') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fisio_vip, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fisioterapia', 4, '1x por semana', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('EDGAR STEFFEN') AND LOWER(u.name) = LOWER('Jean Roberto Campeoto') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fisio_vip, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fisioterapia', 31, '1x por dia', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('EIKO HIRATA SHIMABUKURO') AND LOWER(u.name) = LOWER('Katia Eliana Das Neves Soares') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fisio_vip, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fisioterapia', 14, '3x por semana', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('HERMELINDA ROSA ALBERTINI') AND LOWER(u.name) = LOWER('Tatiana Cristine Rachid') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fisio_vip, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fisioterapia', 31, '1x por dia', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('ISABELLA FERNANDA PASSARO') AND LOWER(u.name) = LOWER('Anderson Codognoto') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fisio_vip, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fisioterapia', 9, '2x por semana', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('LUIS ANTONIO MACHADO PIMENTEL') AND LOWER(u.name) = LOWER('Ledlei Quagliato') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fisio_vip, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fisioterapia', 13, '3x por semana', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('LIVIA PACIFICO SILVA') AND LOWER(u.name) = LOWER('Cristina Pontes Silva') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fisio_vip, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fisioterapia', 31, '1x por dia', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('MARIANA CRISTINA RODRIGUES') AND LOWER(u.name) = LOWER('Maria Cristina de Carvalho') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fisio_vip, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fisioterapia', 31, '1x por dia', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('MARIA DE FATIMA COSSERMELLI SANTOS') AND LOWER(u.name) = LOWER('Katia Eliana Das Neves Soares') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fisio_vip, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fisioterapia', 14, '3x por semana', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('MARIA JOSE BAZZO CUCHERA') AND LOWER(u.name) = LOWER('Ledlei Quagliato') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fisio_vip, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fisioterapia', 31, '1x por dia', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('NELSON GUTIERREZ') AND LOWER(u.name) = LOWER('Marcela Aparecida de Oliveira Ribeiro') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fisio_vip, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fisioterapia', 14, '3x por semana', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('SEBASTIÃO SANTOS DA SILVA') AND LOWER(u.name) = LOWER('Ledlei Quagliato') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fisio_vip, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fisioterapia', 9, '2x por semana', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('VIRGINIA VERCELINO PRIMO') AND LOWER(u.name) = LOWER('Yasmin dos Santos') AND p.deleted_at IS NULL LIMIT 1;

-- === FISIOTERAPIA APAS ===
INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fisio_apas, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fisioterapia', 1, 'Avaliação', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('IRENE MACAGNAN GALVAO DE MOURA') AND LOWER(u.name) = LOWER('Ellem Karoline Pavan') AND p.deleted_at IS NULL LIMIT 1;

-- === FONOAUDIOLOGIA APAS ===
INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fono_apas, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fonoaudiologia', 1, 'Avaliação', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('CLAUDIONOR RIBEIRO AGUIAR') AND LOWER(u.name) = LOWER('Jussara Aparecida Miranda Franco') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fono_apas, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fonoaudiologia', 1, 'Avaliação', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('NIRDE ROSALIN BARBIERI') AND LOWER(u.name) = LOWER('Jussara Aparecida Miranda Franco') AND p.deleted_at IS NULL LIMIT 1;

-- === FONOTERAPIA GANEP LAR ===
INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fono_ganep, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fonoaudiologia', 5, '1x por dia', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('FRANCISCO JOAQUIM DA SILVA') AND LOWER(u.name) = LOWER('Bianca Darzinia Fargiani Nishiyama') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fono_ganep, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fonoaudiologia', 1, 'Avaliação', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('MARIA DO ROSARIO MARTINS HERMACULA') AND LOWER(u.name) = LOWER('Luana Akemi Yamashita Thomaz') AND p.deleted_at IS NULL LIMIT 1;

-- === ENFERMAGEM GANEP LAR ===
INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_enf_ganep, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Enfermagem', 3, '1x por dia', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('ISAURA DE MORAES LEONEL FERREIRA') AND LOWER(u.name) = LOWER('Karen Hikarai Matsubara de Souza') AND p.deleted_at IS NULL LIMIT 1;

-- === NUTRIÇÃO GANEP LAR ===
INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_nutri_ganep, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Nutrição', 1, '1x por mês', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('ISAURA DE MORAES LEONEL FERREIRA') AND LOWER(u.name) = LOWER('Aline Cristina Dias Carmo') AND p.deleted_at IS NULL LIMIT 1;

-- === MÉDICO GANEP LAR ===
INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_med_ganep, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Medico', 1, '1x por mês', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('ISAURA DE MORAES LEONEL FERREIRA') AND LOWER(u.name) = LOWER('GUILHERME MENDES MOUCACHEN') AND p.deleted_at IS NULL LIMIT 1;

-- === FISIOTERAPIA LIFE CARE ===
INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fisio_life, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fisioterapia', 1, '1x por dia', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('GILDA ROSANA LEONEL') AND LOWER(u.name) = LOWER('Maria Ivani De Souza Pereira') AND p.deleted_at IS NULL LIMIT 1;

-- === ENFERMAGEM LIFE CARE ===
INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_enf_life, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Enfermagem', 1, '1x por mês', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('ELCO APPARECIDO FORNAZALI') AND LOWER(u.name) = LOWER('Jaqueline Rodrigues de Oliveira Bonani') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_enf_life, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Enfermagem', 1, '1x por mês', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('ELCO APPARECIDO FORNAZALI') AND LOWER(u.name) = LOWER('Rafaela Aparecida Bernardes') AND p.deleted_at IS NULL LIMIT 1;

-- === FONOTERAPIA LIFE CARE ===
INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fono_life, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fonoaudiologia', 1, 'Avaliação', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('GRACI LUIZA DE GODOI FORTES') AND LOWER(u.name) = LOWER('LILIANE GREEN FRREIRA') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fono_life, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fonoaudiologia', 1, '2x por semana', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('NAELCIO FERREIRA') AND LOWER(u.name) = LOWER('Giovanna C. Vitali') AND p.deleted_at IS NULL LIMIT 1;

-- === NUTRIÇÃO LIFE CARE ===
INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_nutri_life, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Nutrição', 1, '1x por mês', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('NAELCIO FERREIRA') AND LOWER(u.name) = LOWER('Lana Simone Wanzeller de Melo') AND p.deleted_at IS NULL LIMIT 1;

-- === MÉDICO LIFE CARE ===
INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_med_life, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Medico', 1, '1x por mês', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('ANTONIO BUENO DE CAMARGO') AND LOWER(u.name) = LOWER('Hugo Jaime Rodriguez Alvarez') AND p.deleted_at IS NULL LIMIT 1;

-- === FISIOTERAPIA ANERY ===
INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fisio_anery, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fisioterapia', 1, '3x por semana', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('JOSE CARLOS PERES') AND LOWER(u.name) = LOWER('Cristina dos Reis Bozelli') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fisio_anery, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fisioterapia', 1, '3x por semana', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('SYLVIA MARIA CANOAS MIZIARA') AND LOWER(u.name) = LOWER('Camila Cibele Bezerra de Queiroz') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_fisio_anery, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Fisioterapia', 1, '1x por dia', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('THELMA LOPES ONOFRE DE FREITAS RIBEIRO') AND LOWER(u.name) = LOWER('Adriana Gonçalves Mendes') AND p.deleted_at IS NULL LIMIT 1;

-- === MÉDICO ANERY ===
INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_med_anery, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Medico', 1, '1x por mês', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('JOSE CARLOS PERES') AND LOWER(u.name) = LOWER('DR. SANDRO SEITI') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_med_anery, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Medico', 1, '1x por mês', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('SYLVIA MARIA CANOAS MIZIARA') AND LOWER(u.name) = LOWER('Melina Destri Garcia') AND p.deleted_at IS NULL LIMIT 1;

-- === NUTRIÇÃO ANERY ===
INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_nutri_anery, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Nutrição', 1, '1x por mês', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('HELENA KLEIN ALMEIDA') AND LOWER(u.name) = LOWER('Ana Flávia de Freitas') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_nutri_anery, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Nutrição', 1, '2x por mês', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('HELENA KLEIN ALMEIDA') AND LOWER(u.name) = LOWER('Ana Flávia de Freitas') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_nutri_anery, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Nutrição', 1, '1x por mês', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('LIGIA DA PENHA CAMPOS') AND LOWER(u.name) = LOWER('Aryane Cunha Louzada') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_nutri_anery, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Nutrição', 1, '1x por mês', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('OTTO FERREIRA') AND LOWER(u.name) = LOWER('Elaine Cristina Teixeira Pinto') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_nutri_anery, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Nutrição', 1, '1x por mês', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('SHIRLEI DA CUNHA OLIVEIRA') AND LOWER(u.name) = LOWER('Jéssica Geronymo Frias Marciano') AND p.deleted_at IS NULL LIMIT 1;

-- === TERAPIA OCUPACIONAL ANERY ===
INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_to_anery, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Terapia Ocupacional', 1, '1x por semana', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('NATHALY ROCHA DE SOUZA') AND LOWER(u.name) = LOWER('Ana Carolina Abrantes Martinelli') AND p.deleted_at IS NULL LIMIT 1;

-- === PSICOLOGIA VIP CARE ===
INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_psico_vip, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Psicologia', 4, '1x por semana', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('LUIZ CARLOS GONÇALVES ANJA') AND LOWER(u.name) = LOWER('Camilo Diego Benedetti') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_psico_vip, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Psicologia', 4, '1x por semana', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('LIVIA PACIFICO SILVA') AND LOWER(u.name) = LOWER('Daniela da Conceição Perez Martim') AND p.deleted_at IS NULL LIMIT 1;

-- === TERAPIA OCUPACIONAL VIP CARE ===
INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_to_vip, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Terapia Ocupacional', 4, '1x por semana', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('LUCAS HENRIQUE OLIVEIRO CAMARGO') AND LOWER(u.name) = LOWER('Roberta Cristina Santos Bravo') AND p.deleted_at IS NULL LIMIT 1;

-- === TERAPIA OCUPACIONAL AUSTA ===
INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_to_austa, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Terapia Ocupacional', 1, '2x por semana', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('EDINAMARA APARECIDA BISPO DE SOUZA') AND LOWER(u.name) = LOWER('Aldicéia Ribeiro Ferrari Gomes') AND p.deleted_at IS NULL LIMIT 1;

INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, session_quantity, session_frequency, payment_value, status, confirmed_at)
SELECT @demand_to_austa, p.id, CONCAT('import_', u.id, '@import.local'), u.id, @admin_id, 'Terapia Ocupacional', 1, '1x por semana', 0.00, 'confirmed', NOW()
FROM patients p, users u WHERE LOWER(p.full_name) = LOWER('THEREZA VICENTIM CHERONE') AND LOWER(u.name) = LOWER('André Fortini Propheta') AND p.deleted_at IS NULL LIMIT 1;

-- ============================================
-- FIM DA IMPORTAÇÃO
-- ============================================
SELECT 'Importação concluída com sucesso!' AS resultado;

-- Migration: SEED das orientações por operadora (textos oficiais).
-- Data: 2026-10-03
--
-- Insere as variações de orientação (health_insurer_orientations) com os textos
-- oficiais de cada operadora. É IDEMPOTENTE: cada variação só é inserida se ainda
-- não existir uma com o mesmo (health_insurer_id, label).
--
-- A operadora é localizada pelo NOME (LIKE). Se a operadora NÃO estiver cadastrada,
-- a variação simplesmente não é inserida (sem erro) — cadastre a operadora em
-- Operadoras e rode esta migration novamente.
--
-- Placeholders usados nos textos:
--   {{login_externo}} / {{senha_externa}} -> campos de login/senha da pré-admissão
--   {{tabela_dias}} -> tabela de padronização dos dias (gerada pelo sistema)
--
-- Observação MySQL: \n dentro de string com aspas simples é interpretado como
-- quebra de linha real (comportamento padrão do MySQL).

-- =====================================================================
-- ANERY (4 variações: com/sem tablet x avaliação/gerencial)
-- =====================================================================
INSERT INTO health_insurer_orientations (health_insurer_id, label, body, requires_credentials, is_active, sort_order)
SELECT hi.id, 'Com tablet — Avaliação',
'*⚠️ ATENÇÃO – ORIENTAÇÕES INDISPENSÁVEIS ⚠️*\n\n*🔐 ACESSO AO TABLET*\n\nLogin: {{login_externo}}\n\nSenha: {{senha_externa}}\n\n*📱 TABLET + EVOLUÇÃO*: Realizar check-in e evolução no tablet da residência. A Ficha de Evolução não estará disponível na residência, portanto, o profissional deverá levar a ficha impressa e preenchê-la em todos os atendimentos, com data, assinatura e carimbo.\n\n*📋 PRODUTIVIDADE*: A ficha estará na residência. Preencher todos os campos em cada sessão, com assinatura do paciente/responsável e grau de parentesco.\n\n*📤 ENVIO DAS FICHAS: As fichas de Evolução e Produtividade preenchidas deverão ser enviadas para a Tania do Financeiro – WhatsApp: 17 99677-4770, até o último dia do mês. ❗Sem as fichas devidamente preenchidas e enviadas, não haverá pagamento.*\n\n*📄 AVALIAÇÃO*: Preencher o documento completo, incluindo as indicações finais e o PAD (Planejamento de Atendimento Domiciliar) com terapias e frequências. Enviar com MÁXIMA URGÊNCIA, pois é necessário para análise no solicitador e liberação do acompanhamento.\n\n*🏠 VISITA IMPRODUTIVA*: Fotografar a frente da residência e enviar imediatamente à equipe. O pagamento depende da comprovação da visita previamente agendada.\n\n*🏥 INTERCORRÊNCIAS/HOSPITALIZAÇÃO*: Comunicar imediatamente qualquer intercorrência, internação, óbito ou falta. Em caso de hospitalização, suspender os atendimentos, informar a quantidade realizada e aguardar nova autorização da Multilife.\n\n{{tabela_dias}}\n\n*⚠️ O cumprimento dessas orientações é indispensável para faturamento e pagamento.*',
1, 1, 10
FROM health_insurers hi
WHERE LOWER(hi.name) LIKE '%anery%'
  AND NOT EXISTS (SELECT 1 FROM health_insurer_orientations o WHERE o.health_insurer_id = hi.id AND o.label = 'Com tablet — Avaliação')
LIMIT 1;

INSERT INTO health_insurer_orientations (health_insurer_id, label, body, requires_credentials, is_active, sort_order)
SELECT hi.id, 'Com tablet — Relatório gerencial',
'*⚠️ ATENÇÃO – ORIENTAÇÕES INDISPENSÁVEIS ⚠️*\n\n*🔐 ACESSO AO TABLET*\n\nLogin: {{login_externo}}\n\nSenha: {{senha_externa}}\n\n*📱 TABLET + EVOLUÇÃO*: Realizar check-in e evolução no tablet da residência. A Ficha de Evolução não estará disponível na residência, portanto, o profissional deverá levar a ficha impressa e preenchê-la em todos os atendimentos, com data, assinatura e carimbo.\n\n*📋 PRODUTIVIDADE*: A ficha estará na residência. Preencher todos os campos em cada sessão, com assinatura do paciente/responsável e grau de parentesco.\n\n*📤 ENVIO DAS FICHAS: As fichas de Evolução e Produtividade preenchidas deverão ser enviadas para a Tania do Financeiro – WhatsApp: 17 99677-4770, até o último dia do mês. ❗Sem as fichas devidamente preenchidas e enviadas, não haverá pagamento.*\n\n*📊 RELATÓRIO GERENCIAL*: Realizar mensalmente, mantendo o quadro do paciente atualizado.\n\nEnviar IMEDIATAMENTE APÓS O PRIMEIRO ATENDIMENTO e sempre DIGITADO.\n\n*🏠 VISITA IMPRODUTIVA*: Fotografar a frente da residência e enviar imediatamente à equipe. O pagamento depende da comprovação da visita previamente agendada.\n\n*🏥 INTERCORRÊNCIAS/HOSPITALIZAÇÃO*: Comunicar imediatamente qualquer intercorrência, internação, óbito ou falta. Em caso de hospitalização, suspender os atendimentos, informar a quantidade realizada e aguardar nova autorização da Multilife.\n\n{{tabela_dias}}\n\n*⚠️ O cumprimento dessas orientações é indispensável para faturamento e pagamento.*',
1, 1, 20
FROM health_insurers hi
WHERE LOWER(hi.name) LIKE '%anery%'
  AND NOT EXISTS (SELECT 1 FROM health_insurer_orientations o WHERE o.health_insurer_id = hi.id AND o.label = 'Com tablet — Relatório gerencial')
LIMIT 1;

INSERT INTO health_insurer_orientations (health_insurer_id, label, body, requires_credentials, is_active, sort_order)
SELECT hi.id, 'Sem tablet — Avaliação',
'*⚠️ ATENÇÃO – ORIENTAÇÕES INDISPENSÁVEIS ⚠️*\n\n*📋 EVOLUÇÃO*: A Ficha de Evolução não estará disponível na residência, portanto, o profissional deverá levar a ficha impressa e preenchê-la em todos os atendimentos, com data, assinatura e carimbo.\n\n*📋 PRODUTIVIDADE*: A ficha estará na residência. Preencher todos os campos em cada sessão, com assinatura do paciente/responsável e grau de parentesco.\n\n*📤 ENVIO DAS FICHAS: As fichas de Evolução e Produtividade preenchidas deverão ser enviadas para a Tania do Financeiro – WhatsApp: 17 99677-4770, até o último dia do mês. ❗Sem as fichas devidamente preenchidas e enviadas, não haverá pagamento.*\n\n*📄 AVALIAÇÃO*: Preencher o documento completo, incluindo as indicações finais e o PAD (Planejamento de Atendimento Domiciliar) com terapias e frequências. Enviar com MÁXIMA URGÊNCIA, pois é necessário para análise no solicitador e liberação do acompanhamento.\n\n*🏠 VISITA IMPRODUTIVA*: Fotografar a frente da residência e enviar imediatamente à equipe. O pagamento depende da comprovação da visita previamente agendada.\n\n*🏥 INTERCORRÊNCIAS/HOSPITALIZAÇÃO*: Comunicar imediatamente qualquer intercorrência, internação, óbito ou falta. Em caso de hospitalização, suspender os atendimentos, informar a quantidade realizada e aguardar nova autorização da Multilife.\n\n{{tabela_dias}}\n\n*⚠️ O cumprimento dessas orientações é indispensável para faturamento e pagamento.*',
0, 1, 30
FROM health_insurers hi
WHERE LOWER(hi.name) LIKE '%anery%'
  AND NOT EXISTS (SELECT 1 FROM health_insurer_orientations o WHERE o.health_insurer_id = hi.id AND o.label = 'Sem tablet — Avaliação')
LIMIT 1;

INSERT INTO health_insurer_orientations (health_insurer_id, label, body, requires_credentials, is_active, sort_order)
SELECT hi.id, 'Sem tablet — Relatório gerencial',
'*⚠️ ATENÇÃO – ORIENTAÇÕES INDISPENSÁVEIS ⚠️*\n\n*📋 EVOLUÇÃO*: A Ficha de Evolução não estará disponível na residência, portanto, o profissional deverá levar a ficha impressa e preenchê-la em todos os atendimentos, com data, assinatura e carimbo.\n\n*📋 PRODUTIVIDADE*: A ficha estará na residência. Preencher todos os campos em cada sessão, com assinatura do paciente/responsável e grau de parentesco.\n\n*📤 ENVIO DAS FICHAS: As fichas de Evolução e Produtividade preenchidas deverão ser enviadas para a Tania do Financeiro – WhatsApp: 17 99677-4770, até o último dia do mês. ❗Sem as fichas devidamente preenchidas e enviadas, não haverá pagamento.*\n\n*📊 RELATÓRIO GERENCIAL*: Realizar mensalmente, mantendo o quadro do paciente atualizado.\n\nEnviar IMEDIATAMENTE APÓS O PRIMEIRO ATENDIMENTO e sempre DIGITADO.\n\n*🏠 VISITA IMPRODUTIVA*: Fotografar a frente da residência e enviar imediatamente à equipe. O pagamento depende da comprovação da visita previamente agendada.\n\n*🏥 INTERCORRÊNCIAS/HOSPITALIZAÇÃO*: Comunicar imediatamente qualquer intercorrência, internação, óbito ou falta. Em caso de hospitalização, suspender os atendimentos, informar a quantidade realizada e aguardar nova autorização da Multilife.\n\n{{tabela_dias}}\n\n*⚠️ O cumprimento dessas orientações é indispensável para faturamento e pagamento.*',
0, 1, 40
FROM health_insurers hi
WHERE LOWER(hi.name) LIKE '%anery%'
  AND NOT EXISTS (SELECT 1 FROM health_insurer_orientations o WHERE o.health_insurer_id = hi.id AND o.label = 'Sem tablet — Relatório gerencial')
LIMIT 1;

-- =====================================================================
-- APAS
-- =====================================================================
INSERT INTO health_insurer_orientations (health_insurer_id, label, body, requires_credentials, is_active, sort_order)
SELECT hi.id, 'Avaliação',
'*⚠️ ATENÇÃO – ORIENTAÇÕES INDISPENSÁVEIS ⚠️*\n\n*📋 EVOLUÇÃO*: A Ficha de Evolução não estará disponível na residência. Portanto, o profissional deverá levar a ficha impressa e preenchê-la em todos os atendimentos, com data, assinatura e carimbo.\n\n*📋 PRODUTIVIDADE*: A Ficha de Produtividade também não estará disponível na residência. O profissional deverá levar a ficha impressa e preencher todos os campos em cada sessão, com assinatura do paciente/responsável e grau de parentesco.\n\n*📑 GUIA DE SERVIÇO*: Por se tratar de um paciente do convênio APAS, a Guia de Serviço deverá ser preenchida obrigatoriamente, contendo data, horário, assinatura do profissional e assinatura do responsável/família.\n\n*📤 ENVIO DAS FICHAS: As fichas de Evolução e Produtividade preenchidas deverão ser enviadas para a Tania do Financeiro – WhatsApp: 17 99677-4770, até o último dia do mês. ❗Sem as fichas devidamente preenchidas e enviadas, não haverá pagamento.*\n\n*📄 AVALIAÇÃO*: Preencher o documento completo, incluindo as indicações finais e o PAD (Planejamento de Atendimento Domiciliar) com terapias e frequências. Enviar com MÁXIMA URGÊNCIA, pois é necessário para análise no solicitador e liberação do acompanhamento.\n\n*🏠 VISITA IMPRODUTIVA*: Fotografar a frente da residência e enviar imediatamente à equipe. O pagamento depende da comprovação da visita previamente agendada.\n\n*🏥 INTERCORRÊNCIAS/HOSPITALIZAÇÃO*: Comunicar imediatamente qualquer intercorrência, internação, óbito ou falta. Em caso de hospitalização, suspender os atendimentos, informar a quantidade realizada e aguardar nova autorização da Multilife.\n\n{{tabela_dias}}\n\n*⚠️ O cumprimento dessas orientações é indispensável para faturamento e pagamento.*',
0, 1, 10
FROM health_insurers hi
WHERE LOWER(hi.name) LIKE '%apas%'
  AND NOT EXISTS (SELECT 1 FROM health_insurer_orientations o WHERE o.health_insurer_id = hi.id AND o.label = 'Avaliação')
LIMIT 1;

INSERT INTO health_insurer_orientations (health_insurer_id, label, body, requires_credentials, is_active, sort_order)
SELECT hi.id, 'Relatório gerencial',
'*⚠️ ATENÇÃO – ORIENTAÇÕES INDISPENSÁVEIS ⚠️*\n\n*📋 EVOLUÇÃO*: A Ficha de Evolução não estará disponível na residência. Portanto, o profissional deverá levar a ficha impressa e preenchê-la em todos os atendimentos, com data, assinatura e carimbo.\n\n*📋 PRODUTIVIDADE*: A Ficha de Produtividade também não estará disponível na residência. O profissional deverá levar a ficha impressa e preencher todos os campos em cada sessão, com assinatura do paciente/responsável e grau de parentesco.\n\n*📑 GUIA DE SERVIÇO*: Por se tratar de um paciente do convênio APAS, a Guia de Serviço deverá ser preenchida obrigatoriamente a cada atendimento, contendo data, horário, assinatura do profissional e assinatura do responsável/família.\n\n*📤 ENVIO DAS FICHAS: As fichas de Evolução e Produtividade preenchidas deverão ser enviadas para a Tania do Financeiro – WhatsApp: 17 99677-4770, até o último dia do mês. ❗Sem as fichas devidamente preenchidas e enviadas, não haverá pagamento.*\n\n*📊 RELATÓRIO GERENCIAL*: Realizar mensalmente, mantendo o quadro do paciente atualizado.\n\nEnviar IMEDIATAMENTE APÓS O PRIMEIRO ATENDIMENTO e sempre DIGITADO.\n\n*🏠 VISITA IMPRODUTIVA*: Fotografar a frente da residência e enviar imediatamente à equipe. O pagamento depende da comprovação da visita previamente agendada.\n\n*🏥 INTERCORRÊNCIAS/HOSPITALIZAÇÃO*: Comunicar imediatamente qualquer intercorrência, internação, óbito ou falta. Em caso de hospitalização, suspender os atendimentos, informar a quantidade realizada e aguardar nova autorização da Multilife.\n\n{{tabela_dias}}\n\n*⚠️ O cumprimento dessas orientações é indispensável para faturamento e pagamento.*',
0, 1, 20
FROM health_insurers hi
WHERE LOWER(hi.name) LIKE '%apas%'
  AND NOT EXISTS (SELECT 1 FROM health_insurer_orientations o WHERE o.health_insurer_id = hi.id AND o.label = 'Relatório gerencial')
LIMIT 1;

-- =====================================================================
-- AUSTA
-- =====================================================================
INSERT INTO health_insurer_orientations (health_insurer_id, label, body, requires_credentials, is_active, sort_order)
SELECT hi.id, 'Avaliação',
'*⚠️ ATENÇÃO – ORIENTAÇÕES INDISPENSÁVEIS ⚠️*\n\n*📋 EVOLUÇÃO*: A Ficha de Evolução não estará disponível na residência. Portanto, o profissional deverá levar a ficha impressa e preenchê-la em todos os atendimentos, com data, assinatura e carimbo.\n\n*📋 PRODUTIVIDADE*: A Ficha de Produtividade também não estará disponível na residência. O profissional deverá levar a ficha impressa e preencher todos os campos em cada sessão, com assinatura do paciente/responsável e grau de parentesco.\n\n*📤 ENVIO DAS FICHAS: As fichas de Evolução e Produtividade preenchidas deverão ser enviadas para a Tania do Financeiro – WhatsApp: 17 99677-4770, até o último dia do mês. ❗Sem as fichas devidamente preenchidas e enviadas, não haverá pagamento.*\n\n*📄 AVALIAÇÃO*: Preencher o documento completo, incluindo as indicações finais e o PAD (Planejamento de Atendimento Domiciliar) com terapias e frequências. Enviar com MÁXIMA URGÊNCIA, pois é necessário para análise no solicitador e liberação do acompanhamento.\n\n*🏠 VISITA IMPRODUTIVA*: Fotografar a frente da residência e enviar imediatamente à equipe. O pagamento depende da comprovação da visita previamente agendada.\n\n*🏥 INTERCORRÊNCIAS/HOSPITALIZAÇÃO*: Comunicar imediatamente qualquer intercorrência, internação, óbito ou falta. Em caso de hospitalização, suspender os atendimentos, informar a quantidade realizada e aguardar nova autorização da Multilife.\n\n{{tabela_dias}}\n\n*⚠️ O cumprimento dessas orientações é indispensável para faturamento e pagamento.*',
0, 1, 10
FROM health_insurers hi
WHERE LOWER(hi.name) LIKE '%austa%'
  AND NOT EXISTS (SELECT 1 FROM health_insurer_orientations o WHERE o.health_insurer_id = hi.id AND o.label = 'Avaliação')
LIMIT 1;

INSERT INTO health_insurer_orientations (health_insurer_id, label, body, requires_credentials, is_active, sort_order)
SELECT hi.id, 'Relatório gerencial',
'*⚠️ ATENÇÃO – ORIENTAÇÕES INDISPENSÁVEIS ⚠️*\n\n*📋 EVOLUÇÃO*: A Ficha de Evolução não estará disponível na residência. Portanto, o profissional deverá levar a ficha impressa e preenchê-la em todos os atendimentos, com data, assinatura e carimbo.\n\n*📋 PRODUTIVIDADE*: A Ficha de Produtividade também não estará disponível na residência. O profissional deverá levar a ficha impressa e preencher todos os campos em cada sessão, com assinatura do paciente/responsável e grau de parentesco.\n\n*📤 ENVIO DAS FICHAS: As fichas de Evolução e Produtividade preenchidas deverão ser enviadas para a Tania do Financeiro – WhatsApp: 17 99677-4770, até o último dia do mês. ❗Sem as fichas devidamente preenchidas e enviadas, não haverá pagamento.*\n\n*📊 RELATÓRIO GERENCIAL*: Realizar mensalmente, mantendo o quadro do paciente atualizado.\n\nEnviar IMEDIATAMENTE APÓS O PRIMEIRO ATENDIMENTO e sempre DIGITADO.\n\n*🏠 VISITA IMPRODUTIVA*: Fotografar a frente da residência e enviar imediatamente à equipe. O pagamento depende da comprovação da visita previamente agendada.\n\n*🏥 INTERCORRÊNCIAS/HOSPITALIZAÇÃO*: Comunicar imediatamente qualquer intercorrência, internação, óbito ou falta. Em caso de hospitalização, suspender os atendimentos, informar a quantidade realizada e aguardar nova autorização da Multilife.\n\n{{tabela_dias}}\n\n*⚠️ O cumprimento dessas orientações é indispensável para faturamento e pagamento.*',
0, 1, 20
FROM health_insurers hi
WHERE LOWER(hi.name) LIKE '%austa%'
  AND NOT EXISTS (SELECT 1 FROM health_insurer_orientations o WHERE o.health_insurer_id = hi.id AND o.label = 'Relatório gerencial')
LIMIT 1;

-- =====================================================================
-- CENEMED (SALVUS)
-- =====================================================================
INSERT INTO health_insurer_orientations (health_insurer_id, label, body, requires_credentials, is_active, sort_order)
SELECT hi.id, 'Avaliação (SALVUS)',
'*⚠️ ATENÇÃO – ORIENTAÇÕES INDISPENSÁVEIS ⚠️*\n\n*📋 EVOLUÇÃO*: A Ficha de Evolução estará disponível na residência e deverá ser preenchida em todos os atendimentos, com data, assinatura e carimbo.\n\n*📋 PRODUTIVIDADE*: A Ficha de Produtividade estará disponível na residência. Preencher todos os campos em cada sessão, com assinatura do paciente/responsável e grau de parentesco.\n\n*🗒️ SALVUS*: Todos os atendimentos deverão ser registrados na plataforma SALVUS, utilizada para evolução e faturamento.\n\n🔐 Login: {{login_externo}}\n\n🔑 Senha: {{senha_externa}}\n\nÉ obrigatório:\n\n• Registrar a evolução com a data do atendimento;\n\n• Inserir a foto da Ficha de Produtividade;\n\n• Realizar a assinatura no aplicativo e colher a assinatura do responsável presente.\n\n*❗Os registros e assinaturas no SALVUS são obrigatórios para fins de remuneração dos atendimentos realizados.*\n\n*📤 ENVIO DAS FICHAS: As fichas de Evolução e Produtividade preenchidas deverão ser enviadas para a Tania do Financeiro – WhatsApp: 17 99677-4770, até o último dia do mês. ❗Sem as fichas devidamente preenchidas e enviadas, não haverá pagamento.*\n\n*📄 AVALIAÇÃO*: Preencher o documento completo, incluindo as indicações finais e o PAD (Planejamento de Atendimento Domiciliar) com terapias e frequências. Enviar com MÁXIMA URGÊNCIA, pois é necessário para análise no solicitador e liberação do acompanhamento.\n\n*🏠 VISITA IMPRODUTIVA*: Fotografar a frente da residência e enviar imediatamente à equipe. O pagamento depende da comprovação da visita previamente agendada.\n\n*🏥 INTERCORRÊNCIAS/HOSPITALIZAÇÃO*: Comunicar imediatamente qualquer intercorrência, internação, óbito ou falta. Em caso de hospitalização, suspender os atendimentos, informar a quantidade realizada e aguardar nova autorização da Multilife.\n\n{{tabela_dias}}\n\n*⚠️ O cumprimento dessas orientações é indispensável para faturamento e pagamento.*',
1, 1, 10
FROM health_insurers hi
WHERE LOWER(hi.name) LIKE '%cenemed%'
  AND NOT EXISTS (SELECT 1 FROM health_insurer_orientations o WHERE o.health_insurer_id = hi.id AND o.label = 'Avaliação (SALVUS)')
LIMIT 1;

INSERT INTO health_insurer_orientations (health_insurer_id, label, body, requires_credentials, is_active, sort_order)
SELECT hi.id, 'Relatório gerencial (SALVUS)',
'*⚠️ ATENÇÃO – ORIENTAÇÕES INDISPENSÁVEIS ⚠️*\n\n*📋 EVOLUÇÃO*: A Ficha de Evolução estará disponível na residência e deverá ser preenchida em todos os atendimentos, com data, assinatura e carimbo.\n\n*📋 PRODUTIVIDADE*: A Ficha de Produtividade estará disponível na residência. Preencher todos os campos em cada sessão, com assinatura do paciente/responsável e grau de parentesco.\n\n*🗒️ SALVUS*: Todos os atendimentos deverão ser registrados na plataforma SALVUS, utilizada para evolução e faturamento.\n\n🔐 Login: {{login_externo}}\n\n🔑 Senha: {{senha_externa}}\n\nÉ obrigatório:\n\n• Registrar a evolução com a data do atendimento;\n\n• Inserir a foto da Ficha de Produtividade;\n\n• Realizar a assinatura no aplicativo e colher a assinatura do responsável presente.\n\n*❗Os registros e assinaturas no SALVUS são obrigatórios para fins de remuneração dos atendimentos realizados.*\n\n*📤 ENVIO DAS FICHAS: As fichas de Evolução e Produtividade preenchidas deverão ser enviadas para a Tania do Financeiro – WhatsApp: 17 99677-4770, até o último dia do mês. ❗Sem as fichas devidamente preenchidas e enviadas, não haverá pagamento.*\n\n*📊 RELATÓRIO GERENCIAL*: Realizar mensalmente, mantendo o quadro do paciente atualizado.\n\nEnviar IMEDIATAMENTE APÓS O PRIMEIRO ATENDIMENTO e sempre DIGITADO\n\n*🏠 VISITA IMPRODUTIVA*: Fotografar a frente da residência e enviar imediatamente à equipe. O pagamento depende da comprovação da visita previamente agendada.\n\n*🏥 INTERCORRÊNCIAS/HOSPITALIZAÇÃO*: Comunicar imediatamente qualquer intercorrência, internação, óbito ou falta. Em caso de hospitalização, suspender os atendimentos, informar a quantidade realizada e aguardar nova autorização da Multilife.\n\n{{tabela_dias}}\n\n*⚠️ O cumprimento dessas orientações é indispensável para faturamento e pagamento.*',
1, 1, 20
FROM health_insurers hi
WHERE LOWER(hi.name) LIKE '%cenemed%'
  AND NOT EXISTS (SELECT 1 FROM health_insurer_orientations o WHERE o.health_insurer_id = hi.id AND o.label = 'Relatório gerencial (SALVUS)')
LIMIT 1;

-- =====================================================================
-- DAY HOME CARE
-- =====================================================================
INSERT INTO health_insurer_orientations (health_insurer_id, label, body, requires_credentials, is_active, sort_order)
SELECT hi.id, 'Avaliação',
'*⚠️ ATENÇÃO – ORIENTAÇÕES INDISPENSÁVEIS ⚠️*\n\n*📋 EVOLUÇÃO*: A Ficha de Evolução estará disponível na residência e deverá ser preenchida em todos os atendimentos, com data, assinatura e carimbo.\n\n*📋 PRODUTIVIDADE*: A Ficha de Produtividade estará disponível na residência. Preencher todos os campos em cada sessão, com assinatura do paciente/responsável e grau de parentesco.\n\n*📤 ENVIO DAS FICHAS: As fichas de Evolução e Produtividade preenchidas deverão ser enviadas para a Tania do Financeiro – WhatsApp: 17 99677-4770, até o último dia do mês. ❗Sem as fichas devidamente preenchidas e enviadas, não haverá pagamento.*\n\n*📄 AVALIAÇÃO*: Preencher o documento completo, incluindo as indicações finais e o PAD (Planejamento de Atendimento Domiciliar) com terapias e frequências. Enviar com MÁXIMA URGÊNCIA, pois é necessário para análise no solicitador e liberação do acompanhamento.\n\n*🏠 VISITA IMPRODUTIVA*: Fotografar a frente da residência e enviar imediatamente à equipe. O pagamento depende da comprovação da visita previamente agendada.\n\n*🏥 INTERCORRÊNCIAS/HOSPITALIZAÇÃO*: Comunicar imediatamente qualquer intercorrência, internação, óbito ou falta. Em caso de hospitalização, suspender os atendimentos, informar a quantidade realizada e aguardar nova autorização da Multilife.\n\n{{tabela_dias}}\n\n*⚠️ O cumprimento dessas orientações é indispensável para faturamento e pagamento.*',
0, 1, 10
FROM health_insurers hi
WHERE LOWER(hi.name) LIKE '%day%'
  AND NOT EXISTS (SELECT 1 FROM health_insurer_orientations o WHERE o.health_insurer_id = hi.id AND o.label = 'Avaliação')
LIMIT 1;

INSERT INTO health_insurer_orientations (health_insurer_id, label, body, requires_credentials, is_active, sort_order)
SELECT hi.id, 'Relatório gerencial',
'*⚠️ ATENÇÃO – ORIENTAÇÕES INDISPENSÁVEIS ⚠️*\n\n*📋 EVOLUÇÃO*: A Ficha de Evolução estará disponível na residência e deverá ser preenchida em todos os atendimentos, com data, assinatura e carimbo.\n\n*📋 PRODUTIVIDADE*: A Ficha de Produtividade estará disponível na residência. Preencher todos os campos em cada sessão, com assinatura do paciente/responsável e grau de parentesco.\n\n*📤 ENVIO DAS FICHAS: As fichas de Evolução e Produtividade preenchidas deverão ser enviadas para a Tania do Financeiro – WhatsApp: 17 99677-4770, até o último dia do mês. ❗Sem as fichas devidamente preenchidas e enviadas, não haverá pagamento.*\n\n*📊 RELATÓRIO GERENCIAL*: Realizar mensalmente, mantendo o quadro do paciente atualizado.\n\nEnviar IMEDIATAMENTE APÓS O PRIMEIRO ATENDIMENTO e sempre DIGITADO\n\n*🏠 VISITA IMPRODUTIVA*: Fotografar a frente da residência e enviar imediatamente à equipe. O pagamento depende da comprovação da visita previamente agendada.\n\n*🏥 INTERCORRÊNCIAS/HOSPITALIZAÇÃO*: Comunicar imediatamente qualquer intercorrência, internação, óbito ou falta. Em caso de hospitalização, suspender os atendimentos, informar a quantidade realizada e aguardar nova autorização da Multilife.\n\n{{tabela_dias}}\n\n*⚠️ O cumprimento dessas orientações é indispensável para faturamento e pagamento.*',
0, 1, 20
FROM health_insurers hi
WHERE LOWER(hi.name) LIKE '%day%'
  AND NOT EXISTS (SELECT 1 FROM health_insurer_orientations o WHERE o.health_insurer_id = hi.id AND o.label = 'Relatório gerencial')
LIMIT 1;

-- =====================================================================
-- GANEP LAR
-- =====================================================================
INSERT INTO health_insurer_orientations (health_insurer_id, label, body, requires_credentials, is_active, sort_order)
SELECT hi.id, 'Avaliação',
'*⚠️ ATENÇÃO – ORIENTAÇÕES INDISPENSÁVEIS ⚠️*\n\n*📋 EVOLUÇÃO*: A Ficha de Evolução estará disponível na residência e deverá ser preenchida em todos os atendimentos, com data, assinatura e carimbo.\n\n*📋 PRODUTIVIDADE*: A Ficha de Produtividade não estará disponível na residência. O profissional deverá levar a ficha impressa e preencher todos os campos em cada sessão, com assinatura do paciente/responsável e grau de parentesco.\n\n*📤 ENVIO DAS FICHAS: As fichas de Evolução e Produtividade preenchidas deverão ser enviadas para a Tania do Financeiro – WhatsApp: 17 99677-4770, até o último dia do mês. ❗Sem as fichas devidamente preenchidas e enviadas, não haverá pagamento.*\n\n*📄 AVALIAÇÃO*: Preencher o documento completo, incluindo as indicações finais e o PAD (Planejamento de Atendimento Domiciliar) com terapias e frequências. Enviar com MÁXIMA URGÊNCIA, pois é necessário para análise no solicitador e liberação do acompanhamento.\n\n*🏠 VISITA IMPRODUTIVA*: Fotografar a frente da residência e enviar imediatamente à equipe. O pagamento depende da comprovação da visita previamente agendada.\n\n*🏥 INTERCORRÊNCIAS/HOSPITALIZAÇÃO*: Comunicar imediatamente qualquer intercorrência, internação, óbito ou falta. Em caso de hospitalização, suspender os atendimentos, informar a quantidade realizada e aguardar nova autorização da Multilife.\n\n{{tabela_dias}}\n\n*⚠️ O cumprimento dessas orientações é indispensável para faturamento e pagamento.*',
0, 1, 10
FROM health_insurers hi
WHERE LOWER(hi.name) LIKE '%ganep%'
  AND NOT EXISTS (SELECT 1 FROM health_insurer_orientations o WHERE o.health_insurer_id = hi.id AND o.label = 'Avaliação')
LIMIT 1;

INSERT INTO health_insurer_orientations (health_insurer_id, label, body, requires_credentials, is_active, sort_order)
SELECT hi.id, 'Relatório gerencial',
'*⚠️ ATENÇÃO – ORIENTAÇÕES INDISPENSÁVEIS ⚠️*\n\n*📋 EVOLUÇÃO*: A Ficha de Evolução estará disponível na residência e deverá ser preenchida em todos os atendimentos, com data, assinatura e carimbo.\n\n*📋 PRODUTIVIDADE*: A Ficha de Produtividade não estará disponível na residência. O profissional deverá levar a ficha impressa e preencher todos os campos em cada sessão, com assinatura do paciente/responsável e grau de parentesco.\n\n*📤 ENVIO DAS FICHAS: As fichas de Evolução e Produtividade preenchidas deverão ser enviadas para a Tania do Financeiro – WhatsApp: 17 99677-4770, até o último dia do mês. ❗Sem as fichas devidamente preenchidas e enviadas, não haverá pagamento.*\n\n*📊 RELATÓRIO GERENCIAL*: Realizar mensalmente, mantendo o quadro do paciente atualizado.\n\nEnviar IMEDIATAMENTE APÓS O PRIMEIRO ATENDIMENTO e sempre DIGITADO\n\n*🏠 VISITA IMPRODUTIVA*: Fotografar a frente da residência e enviar imediatamente à equipe. O pagamento depende da comprovação da visita previamente agendada.\n\n*🏥 INTERCORRÊNCIAS/HOSPITALIZAÇÃO*: Comunicar imediatamente qualquer intercorrência, internação, óbito ou falta. Em caso de hospitalização, suspender os atendimentos, informar a quantidade realizada e aguardar nova autorização da Multilife.\n\n{{tabela_dias}}\n\n*⚠️ O cumprimento dessas orientações é indispensável para faturamento e pagamento.*',
0, 1, 20
FROM health_insurers hi
WHERE LOWER(hi.name) LIKE '%ganep%'
  AND NOT EXISTS (SELECT 1 FROM health_insurer_orientations o WHERE o.health_insurer_id = hi.id AND o.label = 'Relatório gerencial')
LIMIT 1;

-- =====================================================================
-- GLOBAL (SP/Outcare, Nordeste e Avaliação de enfermagem)
-- =====================================================================
INSERT INTO health_insurer_orientations (health_insurer_id, label, body, requires_credentials, is_active, sort_order)
SELECT hi.id, 'Rio Preto/SP/Prudente/Campinas — Gerencial (Outcare)',
'*⚠️ ATENÇÃO – ORIENTAÇÕES INDISPENSÁVEIS ⚠️*\n\n*🗒️ SISTEMA OUTCARE*: Todos os atendimentos deverão ser registrados no aplicativo Outcare Profissionais (IOS) ou Outcare Prestadores (Android), seguindo o fluxo: check-in → evolução no aplicativo → foto das fichas de Evolução e Produtividade → check-out.\n\n*⚠️ O registro dos atendimentos e os evolutivos na plataforma são obrigatórios para fins de remuneração.*\n\nLogin: {{login_externo}}\n\nSenha: {{senha_externa}}\n\nEm caso de dúvidas ou dificuldades, entrar em contato pelo WhatsApp: 17 99748-3924 (Auditoria).\n\n*📋 EVOLUÇÃO*: A Ficha de Evolução estará disponível na residência e deverá ser preenchida em todos os atendimentos, com data, assinatura e carimbo.\n\n*📋 PRODUTIVIDADE*: A Ficha de Produtividade estará disponível na residência e deverá ser preenchida em todos os atendimentos, com todos os campos devidamente preenchidos, incluindo a assinatura do paciente/responsável e o grau de parentesco.\n\n*📤 ENVIO DAS FICHAS: As fichas de Evolução e Produtividade preenchidas deverão ser enviadas para a Tania do Financeiro – WhatsApp: 17 99677-4770, até o último dia do mês. ❗Sem as fichas devidamente preenchidas e enviadas, não haverá pagamento.*\n\n*📊 RELATÓRIO GERENCIAL*: Realizar mensalmente, mantendo o quadro do paciente atualizado.\n\nEnviar IMEDIATAMENTE APÓS O PRIMEIRO ATENDIMENTO e sempre DIGITADO\n\n*🏠 VISITA IMPRODUTIVA*: Fotografar a frente da residência e enviar imediatamente à equipe. O pagamento depende da comprovação da visita previamente agendada.\n\n*🏥 INTERCORRÊNCIAS/HOSPITALIZAÇÃO*: Comunicar imediatamente qualquer intercorrência, internação, óbito ou falta. Em caso de hospitalização, suspender os atendimentos, informar a quantidade realizada e aguardar nova autorização da Multilife.\n\n{{tabela_dias}}\n\n*⚠️ O cumprimento de todas essas orientações é indispensável para o faturamento e pagamento dos atendimentos.*',
1, 1, 10
FROM health_insurers hi
WHERE LOWER(hi.name) LIKE '%global%'
  AND NOT EXISTS (SELECT 1 FROM health_insurer_orientations o WHERE o.health_insurer_id = hi.id AND o.label = 'Rio Preto/SP/Prudente/Campinas — Gerencial (Outcare)')
LIMIT 1;

INSERT INTO health_insurer_orientations (health_insurer_id, label, body, requires_credentials, is_active, sort_order)
SELECT hi.id, 'Rio Preto/SP/Prudente/Campinas — Avaliação (Outcare)',
'*⚠️ ATENÇÃO – ORIENTAÇÕES INDISPENSÁVEIS ⚠️*\n\n*🗒️ SISTEMA OUTCARE*: Todos os atendimentos deverão ser registrados no aplicativo Outcare Profissionais (IOS) ou Outcare Prestadores (Android), seguindo o fluxo: check-in → evolução no aplicativo → foto das fichas de Evolução e Produtividade → check-out.\n\n*⚠️ O registro dos atendimentos e os evolutivos na plataforma são obrigatórios para fins de remuneração.*\n\nLogin: {{login_externo}}\n\nSenha: {{senha_externa}}\n\nEm caso de dúvidas ou dificuldades, entrar em contato pelo WhatsApp: 17 99748-3924 (Auditoria).\n\n*📋 EVOLUÇÃO*: A Ficha de Evolução estará disponível na residência e deverá ser preenchida em todos os atendimentos, com data, assinatura e carimbo.\n\n*📋 PRODUTIVIDADE*: A Ficha de Produtividade estará disponível na residência e deverá ser preenchida em todos os atendimentos, com todos os campos devidamente preenchidos, incluindo a assinatura do paciente/responsável e o grau de parentesco.\n\n*📤 ENVIO DAS FICHAS: As fichas de Evolução e Produtividade preenchidas deverão ser enviadas para a Tania do Financeiro – WhatsApp: 17 99677-4770, até o último dia do mês. ❗Sem as fichas devidamente preenchidas e enviadas, não haverá pagamento.*\n\n*📄 AVALIAÇÃO*: Preencher o documento completo, incluindo as indicações finais e o PAD (Planejamento de Atendimento Domiciliar), com as terapias e respectivas frequências. Enviar com MÁXIMA URGÊNCIA, pois é necessário para análise no solicitador e liberação do acompanhamento.\n\n*🏠 VISITA IMPRODUTIVA*: Fotografar a frente da residência e enviar imediatamente à equipe. O pagamento depende da comprovação da visita previamente agendada.\n\n*🏥 INTERCORRÊNCIAS/HOSPITALIZAÇÃO*: Comunicar imediatamente qualquer intercorrência, internação, óbito ou falta. Em caso de hospitalização, suspender os atendimentos, informar a quantidade realizada e aguardar nova autorização da Multilife.\n\n{{tabela_dias}}\n\n*⚠️ O cumprimento de todas essas orientações é indispensável para o faturamento e pagamento dos atendimentos.*',
1, 1, 20
FROM health_insurers hi
WHERE LOWER(hi.name) LIKE '%global%'
  AND NOT EXISTS (SELECT 1 FROM health_insurer_orientations o WHERE o.health_insurer_id = hi.id AND o.label = 'Rio Preto/SP/Prudente/Campinas — Avaliação (Outcare)')
LIMIT 1;

INSERT INTO health_insurer_orientations (health_insurer_id, label, body, requires_credentials, is_active, sort_order)
SELECT hi.id, 'Salvador/Recife — Gerencial',
'*⚠️ ATENÇÃO – ORIENTAÇÕES INDISPENSÁVEIS ⚠️*\n\n*📋 EVOLUÇÃO*: A Ficha de Evolução estará disponível na residência e deverá ser preenchida em todos os atendimentos, com data, assinatura e carimbo.\n\n*📋 PRODUTIVIDADE*: A Ficha de Produtividade estará disponível na residência e deverá ser preenchida em todos os atendimentos, com todos os campos devidamente preenchidos, incluindo a assinatura do paciente/responsável e o grau de parentesco.\n\n*📤 ENVIO DAS FICHAS: As fichas de Evolução e Produtividade preenchidas deverão ser enviadas para a Tania do Financeiro – WhatsApp: 17 99677-4770, até o último dia do mês. ❗Sem as fichas devidamente preenchidas e enviadas, não haverá pagamento.*\n\n*📊 RELATÓRIO GERENCIAL*: Realizar mensalmente, mantendo o quadro do paciente atualizado.\n\nEnviar IMEDIATAMENTE APÓS O PRIMEIRO ATENDIMENTO e sempre DIGITADO\n\n*🏠 VISITA IMPRODUTIVA*: Fotografar a frente da residência e enviar imediatamente à equipe. O pagamento depende da comprovação da visita previamente agendada.\n\n*🏥 INTERCORRÊNCIAS/HOSPITALIZAÇÃO*: Comunicar imediatamente qualquer intercorrência, internação, óbito ou falta. Em caso de hospitalização, suspender os atendimentos, informar a quantidade realizada e aguardar nova autorização da Multilife.\n\n{{tabela_dias}}\n\n*⚠️ O cumprimento de todas essas orientações é indispensável para o faturamento e pagamento dos atendimentos.*',
0, 1, 30
FROM health_insurers hi
WHERE LOWER(hi.name) LIKE '%global%'
  AND NOT EXISTS (SELECT 1 FROM health_insurer_orientations o WHERE o.health_insurer_id = hi.id AND o.label = 'Salvador/Recife — Gerencial')
LIMIT 1;

INSERT INTO health_insurer_orientations (health_insurer_id, label, body, requires_credentials, is_active, sort_order)
SELECT hi.id, 'Salvador/Recife — Avaliação',
'*⚠️ ATENÇÃO – ORIENTAÇÕES INDISPENSÁVEIS ⚠️*\n\n*📋 EVOLUÇÃO*: A Ficha de Evolução estará disponível na residência e deverá ser preenchida em todos os atendimentos, com data, assinatura e carimbo.\n\n*📋 PRODUTIVIDADE*: A Ficha de Produtividade estará disponível na residência e deverá ser preenchida em todos os atendimentos, com todos os campos devidamente preenchidos, incluindo a assinatura do paciente/responsável e o grau de parentesco.\n\n*📤 ENVIO DAS FICHAS: As fichas de Evolução e Produtividade preenchidas deverão ser enviadas para a Tania do Financeiro – WhatsApp: 17 99677-4770, até o último dia do mês. ❗Sem as fichas devidamente preenchidas e enviadas, não haverá pagamento.*\n\n*📄 AVALIAÇÃO*: Preencher o documento completo, incluindo as indicações finais e o PAD (Planejamento de Atendimento Domiciliar), com as terapias e respectivas frequências. Enviar com MÁXIMA URGÊNCIA, pois é necessário para análise no solicitador e liberação do acompanhamento.\n\n*🏠 VISITA IMPRODUTIVA*: Fotografar a frente da residência e enviar imediatamente à equipe. O pagamento depende da comprovação da visita previamente agendada.\n\n*🏥 INTERCORRÊNCIAS/HOSPITALIZAÇÃO*: Comunicar imediatamente qualquer intercorrência, internação, óbito ou falta. Em caso de hospitalização, suspender os atendimentos, informar a quantidade realizada e aguardar nova autorização da Multilife.\n\n{{tabela_dias}}\n\n*⚠️ O cumprimento de todas essas orientações é indispensável para o faturamento e pagamento dos atendimentos.*',
0, 1, 40
FROM health_insurers hi
WHERE LOWER(hi.name) LIKE '%global%'
  AND NOT EXISTS (SELECT 1 FROM health_insurer_orientations o WHERE o.health_insurer_id = hi.id AND o.label = 'Salvador/Recife — Avaliação')
LIMIT 1;

INSERT INTO health_insurer_orientations (health_insurer_id, label, body, requires_credentials, is_active, sort_order)
SELECT hi.id, 'Avaliação de enfermagem',
'*⚠️ ATENÇÃO – ORIENTAÇÕES INDISPENSÁVEIS ⚠️*\n\n*📋 EVOLUÇÃO*: A ficha estará disponível e deverá ser preenchida em todos os atendimentos, com data, assinatura e carimbo.\n\n*📋 PRODUTIVIDADE*: A ficha estará disponível e deverá ser preenchida em todos os atendimentos, com todos os campos preenchidos, incluindo assinatura do paciente/responsável e grau de parentesco.\n\n*📤 ENVIO: As fichas de Evolução e Produtividade deverão ser enviadas à Tania do Financeiro – 17 99677-4770, até o último dia do mês. ❗Sem as fichas devidamente preenchidas e enviadas, não haverá pagamento.*\n\n*📄 AVALIAÇÃO*: Preencher todos os campos aplicáveis, sinalizando “não se aplica” quando necessário. Informar dados completos do paciente, diagnóstico, histórico clínico, condições funcionais, sinais vitais, dispositivos, lesões, dieta, eliminações, condições respiratórias, cuidador e contexto familiar.\n\n*📎 DOCUMENTOS*: Sempre que disponíveis e autorizados, anexar RG/CPF, carteirinha do plano, prescrições médicas e nutricionais, medicamentos, terapias e demais documentos pertinentes, sempre com imagens legíveis.\n\n*📞 CONTATOS*: Informar os telefones do paciente, familiares e responsáveis, sempre identificando o nome e o vínculo com o paciente.\n\n*💊 MEDICAÇÕES*: Quando houver uso contínuo, informar a prescrição vigente com medicamento, apresentação, dose, via e horários. Preferencialmente, anexar foto da prescrição.\n\n*🩹 LESÕES/CURATIVOS*: Descrever localização, grau, dimensões, aspecto, exsudato, pele ao redor e cobertura utilizada. Quando autorizado, anexar foto da lesão.\n\n*🔹 DISPOSITIVOS/OSTOMIAS*: Informar tipo, localização, calibre, data de implantação/troca, condições do local e previsão de permanência. Para GTT, SVD, PICC, CVC, Port-a-Cath e demais dispositivos, registrar todas as informações disponíveis e anexar foto quando autorizada.\n\n*🥣 DIETA*: Informar via, nome da dieta, apresentação, volume, frequência/horários, forma de administração e necessidade de bomba de infusão. Anexar prescrição nutricional, quando disponível.\n\n*📋 PAD*: O PAD deverá estar coerente com os achados da avaliação, contendo as terapias indicadas, frequências e necessidades de equipamentos, materiais, medicamentos e insumos.\n\n*⏰ PRAZO*: A avaliação e toda a documentação deverão ser enviadas imediatamente após a avaliação, para que haja tempo hábil para conferência, orçamento e liberação do atendimento.\n\n*🏠 VISITA IMPRODUTIVA*: Fotografar a frente da residência e enviar imediatamente à equipe.\n\n{{tabela_dias}}\n\n*⚠️ O preenchimento completo e correto da documentação é indispensável para a continuidade da assistência, faturamento e pagamento.*',
0, 1, 50
FROM health_insurers hi
WHERE LOWER(hi.name) LIKE '%global%'
  AND NOT EXISTS (SELECT 1 FROM health_insurer_orientations o WHERE o.health_insurer_id = hi.id AND o.label = 'Avaliação de enfermagem')
LIMIT 1;

-- =====================================================================
-- LIFE CARE
-- =====================================================================
INSERT INTO health_insurer_orientations (health_insurer_id, label, body, requires_credentials, is_active, sort_order)
SELECT hi.id, 'Avaliação',
'*⚠️ ATENÇÃO – ORIENTAÇÕES INDISPENSÁVEIS ⚠️*\n\n*📋 EVOLUÇÃO*: A Ficha de Evolução estará disponível na residência e deverá ser preenchida em todos os atendimentos, com data, assinatura e carimbo.\n\n*📋 PRODUTIVIDADE*: A Ficha de Produtividade estará disponível na residência e deverá ser preenchida em todos os atendimentos, com todos os campos devidamente preenchidos, incluindo a assinatura do paciente/responsável e o grau de parentesco.\n\n*📤 ENVIO DAS FICHAS: As fichas de Evolução e Produtividade preenchidas deverão ser enviadas para a Tania do Financeiro – WhatsApp: 17 99677-4770, até o último dia do mês. ❗Sem as fichas devidamente preenchidas e enviadas, não haverá pagamento.*\n\n*📄 AVALIAÇÃO*: Preencher o documento completo, incluindo as indicações finais e o PAD (Planejamento de Atendimento Domiciliar), com as terapias e respectivas frequências. Enviar com MÁXIMA URGÊNCIA, pois é necessário para análise no solicitador e liberação do acompanhamento.\n\n*🏠 VISITA IMPRODUTIVA*: Fotografar a frente da residência e enviar imediatamente à equipe. O pagamento depende da comprovação da visita previamente agendada.\n\n*🏥 INTERCORRÊNCIAS/HOSPITALIZAÇÃO*: Comunicar imediatamente qualquer intercorrência, internação, óbito ou falta. Em caso de hospitalização, suspender os atendimentos, informar a quantidade realizada e aguardar nova autorização da Multilife.\n\n{{tabela_dias}}\n\n*⚠️ O cumprimento de todas essas orientações é indispensável para o faturamento e pagamento dos atendimentos.*',
0, 1, 10
FROM health_insurers hi
WHERE (LOWER(hi.name) LIKE '%life care%' OR LOWER(hi.name) LIKE '%lifecare%')
  AND NOT EXISTS (SELECT 1 FROM health_insurer_orientations o WHERE o.health_insurer_id = hi.id AND o.label = 'Avaliação')
LIMIT 1;

INSERT INTO health_insurer_orientations (health_insurer_id, label, body, requires_credentials, is_active, sort_order)
SELECT hi.id, 'Relatório gerencial',
'*⚠️ ATENÇÃO – ORIENTAÇÕES INDISPENSÁVEIS ⚠️*\n\n*📋 EVOLUÇÃO*: A Ficha de Evolução estará disponível na residência e deverá ser preenchida em todos os atendimentos, com data, assinatura e carimbo.\n\n*📋 PRODUTIVIDADE*: A Ficha de Produtividade estará disponível na residência e deverá ser preenchida em todos os atendimentos, com todos os campos devidamente preenchidos, incluindo a assinatura do paciente/responsável e o grau de parentesco.\n\n*📤 ENVIO DAS FICHAS: As fichas de Evolução e Produtividade preenchidas deverão ser enviadas para a Tania do Financeiro – WhatsApp: 17 99677-4770, até o último dia do mês. ❗Sem as fichas devidamente preenchidas e enviadas, não haverá pagamento.*\n\n*📊 RELATÓRIO GERENCIAL*: Realizar mensalmente, mantendo o quadro do paciente atualizado.\n\nEnviar IMEDIATAMENTE APÓS O PRIMEIRO ATENDIMENTO e sempre DIGITADO\n\n*🏠 VISITA IMPRODUTIVA*: Fotografar a frente da residência e enviar imediatamente à equipe. O pagamento depende da comprovação da visita previamente agendada.\n\n*🏥 INTERCORRÊNCIAS/HOSPITALIZAÇÃO*: Comunicar imediatamente qualquer intercorrência, internação, óbito ou falta. Em caso de hospitalização, suspender os atendimentos, informar a quantidade realizada e aguardar nova autorização da Multilife.\n\n{{tabela_dias}}\n\n*⚠️ O cumprimento de todas essas orientações é indispensável para o faturamento e pagamento dos atendimentos.*',
0, 1, 20
FROM health_insurers hi
WHERE (LOWER(hi.name) LIKE '%life care%' OR LOWER(hi.name) LIKE '%lifecare%')
  AND NOT EXISTS (SELECT 1 FROM health_insurer_orientations o WHERE o.health_insurer_id = hi.id AND o.label = 'Relatório gerencial')
LIMIT 1;

-- =====================================================================
-- PRONEP
-- =====================================================================
INSERT INTO health_insurer_orientations (health_insurer_id, label, body, requires_credentials, is_active, sort_order)
SELECT hi.id, 'Avaliação',
'*⚠️ ATENÇÃO – ORIENTAÇÕES INDISPENSÁVEIS ⚠️*\n\n*📋 EVOLUÇÃO*: A Ficha de Evolução estará disponível na residência e deverá ser preenchida em todos os atendimentos, com data, assinatura e carimbo.\n\n*📋 PRODUTIVIDADE*: A Ficha de Produtividade estará disponível na residência e deverá ser preenchida em todos os atendimentos, com todos os campos devidamente preenchidos, incluindo a assinatura do paciente/responsável e o grau de parentesco.\n\n*📤 ENVIO DAS FICHAS: As fichas de Evolução e Produtividade preenchidas deverão ser enviadas para a Tania do Financeiro – WhatsApp: 17 99677-4770, até o último dia do mês. ❗Sem as fichas devidamente preenchidas e enviadas, não haverá pagamento.*\n\n*📄 AVALIAÇÃO*: Preencher o documento completo, incluindo as indicações finais e o PAD (Planejamento de Atendimento Domiciliar), com as terapias e respectivas frequências. Enviar com MÁXIMA URGÊNCIA, pois é necessário para análise no solicitador e liberação do acompanhamento.\n\n*🏠 VISITA IMPRODUTIVA*: Fotografar a frente da residência e enviar imediatamente à equipe. O pagamento depende da comprovação da visita previamente agendada.\n\n*🏥 INTERCORRÊNCIAS/HOSPITALIZAÇÃO*: Comunicar imediatamente qualquer intercorrência, internação, óbito ou falta. Em caso de hospitalização, suspender os atendimentos, informar a quantidade realizada e aguardar nova autorização da Multilife.\n\n{{tabela_dias}}\n\n*⚠️ O cumprimento de todas essas orientações é indispensável para o faturamento e pagamento dos atendimentos.*',
0, 1, 10
FROM health_insurers hi
WHERE LOWER(hi.name) LIKE '%pronep%'
  AND NOT EXISTS (SELECT 1 FROM health_insurer_orientations o WHERE o.health_insurer_id = hi.id AND o.label = 'Avaliação')
LIMIT 1;

INSERT INTO health_insurer_orientations (health_insurer_id, label, body, requires_credentials, is_active, sort_order)
SELECT hi.id, 'Relatório gerencial',
'*⚠️ ATENÇÃO – ORIENTAÇÕES INDISPENSÁVEIS ⚠️*\n\n*📋 EVOLUÇÃO*: A Ficha de Evolução estará disponível na residência e deverá ser preenchida em todos os atendimentos, com data, assinatura e carimbo.\n\n*📋 PRODUTIVIDADE*: A Ficha de Produtividade estará disponível na residência e deverá ser preenchida em todos os atendimentos, com todos os campos devidamente preenchidos, incluindo a assinatura do paciente/responsável e o grau de parentesco.\n\n*📤 ENVIO DAS FICHAS: As fichas de Evolução e Produtividade preenchidas deverão ser enviadas para a Tania do Financeiro – WhatsApp: 17 99677-4770, até o último dia do mês. ❗Sem as fichas devidamente preenchidas e enviadas, não haverá pagamento.*\n\n*📊 RELATÓRIO GERENCIAL*: Realizar mensalmente, mantendo o quadro do paciente atualizado.\n\nEnviar IMEDIATAMENTE APÓS O PRIMEIRO ATENDIMENTO e sempre DIGITADO\n\n*🏠 VISITA IMPRODUTIVA*: Fotografar a frente da residência e enviar imediatamente à equipe. O pagamento depende da comprovação da visita previamente agendada.\n\n*🏥 INTERCORRÊNCIAS/HOSPITALIZAÇÃO*: Comunicar imediatamente qualquer intercorrência, internação, óbito ou falta. Em caso de hospitalização, suspender os atendimentos, informar a quantidade realizada e aguardar nova autorização da Multilife.\n\n{{tabela_dias}}\n\n*⚠️ O cumprimento de todas essas orientações é indispensável para o faturamento e pagamento dos atendimentos.*',
0, 1, 20
FROM health_insurers hi
WHERE LOWER(hi.name) LIKE '%pronep%'
  AND NOT EXISTS (SELECT 1 FROM health_insurer_orientations o WHERE o.health_insurer_id = hi.id AND o.label = 'Relatório gerencial')
LIMIT 1;

-- =====================================================================
-- RESIDENCIAL GERIÁTRICO VÔ CUIDAR
-- =====================================================================
INSERT INTO health_insurer_orientations (health_insurer_id, label, body, requires_credentials, is_active, sort_order)
SELECT hi.id, 'Avaliação',
'*⚠️ ATENÇÃO – ORIENTAÇÕES INDISPENSÁVEIS ⚠️*\n\n*📋 EVOLUÇÃO*: A Ficha de Evolução não estará disponível na residência. Portanto, o profissional deverá levar a ficha impressa e preenchê-la em todos os atendimentos, com data, assinatura e carimbo.\n\n*📋 PRODUTIVIDADE*: A Ficha de Produtividade também não estará disponível na residência. O profissional deverá levar a ficha impressa e preencher todos os campos em cada sessão, com assinatura do paciente/responsável e grau de parentesco.\n\n*📤 ENVIO DAS FICHAS: As fichas de Evolução e Produtividade preenchidas deverão ser enviadas para a Tania do Financeiro – WhatsApp: 17 99677-4770, até o último dia do mês. ❗Sem as fichas devidamente preenchidas e enviadas, não haverá pagamento.*\n\n*📄 AVALIAÇÃO*: Preencher o documento completo, incluindo as indicações finais e o PAD (Planejamento de Atendimento Domiciliar) com terapias e frequências. Enviar com MÁXIMA URGÊNCIA, pois é necessário para análise no solicitador e liberação do acompanhamento.\n\n*🏠 VISITA IMPRODUTIVA*: Fotografar a frente da residência e enviar imediatamente à equipe. O pagamento depende da comprovação da visita previamente agendada.\n\n*🏥 INTERCORRÊNCIAS/HOSPITALIZAÇÃO*: Comunicar imediatamente qualquer intercorrência, internação, óbito ou falta. Em caso de hospitalização, suspender os atendimentos, informar a quantidade realizada e aguardar nova autorização da Multilife.\n\n{{tabela_dias}}\n\n*⚠️ O cumprimento dessas orientações é indispensável para faturamento e pagamento.*',
0, 1, 10
FROM health_insurers hi
WHERE LOWER(hi.name) LIKE '%cuidar%'
  AND NOT EXISTS (SELECT 1 FROM health_insurer_orientations o WHERE o.health_insurer_id = hi.id AND o.label = 'Avaliação')
LIMIT 1;

INSERT INTO health_insurer_orientations (health_insurer_id, label, body, requires_credentials, is_active, sort_order)
SELECT hi.id, 'Relatório gerencial',
'*⚠️ ATENÇÃO – ORIENTAÇÕES INDISPENSÁVEIS ⚠️*\n\n*📋 EVOLUÇÃO*: A Ficha de Evolução não estará disponível na residência. Portanto, o profissional deverá levar a ficha impressa e preenchê-la em todos os atendimentos, com data, assinatura e carimbo.\n\n*📋 PRODUTIVIDADE*: A Ficha de Produtividade também não estará disponível na residência. O profissional deverá levar a ficha impressa e preencher todos os campos em cada sessão, com assinatura do paciente/responsável e grau de parentesco.\n\n*📤 ENVIO DAS FICHAS: As fichas de Evolução e Produtividade preenchidas deverão ser enviadas para a Tania do Financeiro – WhatsApp: 17 99677-4770, até o último dia do mês. ❗Sem as fichas devidamente preenchidas e enviadas, não haverá pagamento.*\n\n*📊 RELATÓRIO GERENCIAL*: Realizar mensalmente, mantendo o quadro do paciente atualizado.\n\nEnviar IMEDIATAMENTE APÓS O PRIMEIRO ATENDIMENTO e sempre DIGITADO.\n\n*🏠 VISITA IMPRODUTIVA*: Fotografar a frente da residência e enviar imediatamente à equipe. O pagamento depende da comprovação da visita previamente agendada.\n\n*🏥 INTERCORRÊNCIAS/HOSPITALIZAÇÃO*: Comunicar imediatamente qualquer intercorrência, internação, óbito ou falta. Em caso de hospitalização, suspender os atendimentos, informar a quantidade realizada e aguardar nova autorização da Multilife.\n\n{{tabela_dias}}\n\n*⚠️ O cumprimento dessas orientações é indispensável para faturamento e pagamento.*',
0, 1, 20
FROM health_insurers hi
WHERE LOWER(hi.name) LIKE '%cuidar%'
  AND NOT EXISTS (SELECT 1 FROM health_insurer_orientations o WHERE o.health_insurer_id = hi.id AND o.label = 'Relatório gerencial')
LIMIT 1;

-- =====================================================================
-- SENIOR MAIS
-- =====================================================================
INSERT INTO health_insurer_orientations (health_insurer_id, label, body, requires_credentials, is_active, sort_order)
SELECT hi.id, 'Avaliação',
'*⚠️ ATENÇÃO – ORIENTAÇÕES INDISPENSÁVEIS ⚠️*\n\n*📋 EVOLUÇÃO*: A Ficha de Evolução estará disponível na residência e deverá ser preenchida em todos os atendimentos, com data, assinatura e carimbo.\n\n*📋 PRODUTIVIDADE*: A Ficha de Produtividade estará disponível na residência e deverá ser preenchida em todos os atendimentos, com todos os campos devidamente preenchidos, incluindo a assinatura do paciente/responsável e o grau de parentesco.\n\n*📤 ENVIO DAS FICHAS: As fichas de Evolução e Produtividade preenchidas deverão ser enviadas para a Tania do Financeiro – WhatsApp: 17 99677-4770, até o último dia do mês. ❗Sem as fichas devidamente preenchidas e enviadas, não haverá pagamento.*\n\n*📄 AVALIAÇÃO*: Preencher o documento completo, incluindo as indicações finais e o PAD (Planejamento de Atendimento Domiciliar), com as terapias e respectivas frequências. Enviar com MÁXIMA URGÊNCIA, pois é necessário para análise no solicitador e liberação do acompanhamento.\n\n*🏠 VISITA IMPRODUTIVA*: Fotografar a frente da residência e enviar imediatamente à equipe. O pagamento depende da comprovação da visita previamente agendada.\n\n*🏥 INTERCORRÊNCIAS/HOSPITALIZAÇÃO*: Comunicar imediatamente qualquer intercorrência, internação, óbito ou falta. Em caso de hospitalização, suspender os atendimentos, informar a quantidade realizada e aguardar nova autorização da Multilife.\n\n{{tabela_dias}}\n\n*⚠️ O cumprimento de todas essas orientações é indispensável para o faturamento e pagamento dos atendimentos.*',
0, 1, 10
FROM health_insurers hi
WHERE LOWER(hi.name) LIKE '%senior mais%' -- antes '%senior%' casava com "Prevent Senior"
  AND NOT EXISTS (SELECT 1 FROM health_insurer_orientations o WHERE o.health_insurer_id = hi.id AND o.label = 'Avaliação')
LIMIT 1;

INSERT INTO health_insurer_orientations (health_insurer_id, label, body, requires_credentials, is_active, sort_order)
SELECT hi.id, 'Relatório gerencial',
'*⚠️ ATENÇÃO – ORIENTAÇÕES INDISPENSÁVEIS ⚠️*\n\n*📋 EVOLUÇÃO*: A Ficha de Evolução estará disponível na residência e deverá ser preenchida em todos os atendimentos, com data, assinatura e carimbo.\n\n*📋 PRODUTIVIDADE*: A Ficha de Produtividade estará disponível na residência e deverá ser preenchida em todos os atendimentos, com todos os campos devidamente preenchidos, incluindo a assinatura do paciente/responsável e o grau de parentesco.\n\n*📤 ENVIO DAS FICHAS: As fichas de Evolução e Produtividade preenchidas deverão ser enviadas para a Tania do Financeiro – WhatsApp: 17 99677-4770, até o último dia do mês. ❗Sem as fichas devidamente preenchidas e enviadas, não haverá pagamento.*\n\n*📊 RELATÓRIO GERENCIAL*: Realizar mensalmente, mantendo o quadro do paciente atualizado.\n\nEnviar IMEDIATAMENTE APÓS O PRIMEIRO ATENDIMENTO e sempre DIGITADO\n\n*🏠 VISITA IMPRODUTIVA*: Fotografar a frente da residência e enviar imediatamente à equipe. O pagamento depende da comprovação da visita previamente agendada.\n\n*🏥 INTERCORRÊNCIAS/HOSPITALIZAÇÃO*: Comunicar imediatamente qualquer intercorrência, internação, óbito ou falta. Em caso de hospitalização, suspender os atendimentos, informar a quantidade realizada e aguardar nova autorização da Multilife.\n\n{{tabela_dias}}\n\n*⚠️ O cumprimento de todas essas orientações é indispensável para o faturamento e pagamento dos atendimentos.*',
0, 1, 20
FROM health_insurers hi
WHERE LOWER(hi.name) LIKE '%senior mais%' -- antes '%senior%' casava com "Prevent Senior"
  AND NOT EXISTS (SELECT 1 FROM health_insurer_orientations o WHERE o.health_insurer_id = hi.id AND o.label = 'Relatório gerencial')
LIMIT 1;

-- =====================================================================
-- UNIMED (Andradina/Bebedouro/Birigui/Londrina)
-- =====================================================================
INSERT INTO health_insurer_orientations (health_insurer_id, label, body, requires_credentials, is_active, sort_order)
SELECT hi.id, 'Avaliação',
'*⚠️ ATENÇÃO – ORIENTAÇÕES INDISPENSÁVEIS ⚠️*\n\n*📋 EVOLUÇÃO*: A Ficha de Evolução não estará disponível na residência. Portanto, o profissional deverá levar a ficha impressa e preenchê-la em todos os atendimentos, com data, assinatura e carimbo.\n\n*📋 PRODUTIVIDADE*: A Ficha de Produtividade também não estará disponível na residência. O profissional deverá levar a ficha impressa e preencher todos os campos em cada sessão, com assinatura do paciente/responsável e grau de parentesco.\n\n*📤 ENVIO DAS FICHAS: As fichas de Evolução e Produtividade preenchidas deverão ser enviadas para a Tania do Financeiro – WhatsApp: 17 99677-4770, até o último dia do mês. ❗Sem as fichas devidamente preenchidas e enviadas, não haverá pagamento.*\n\n*📄 AVALIAÇÃO*: Preencher o documento completo, incluindo as indicações finais e o PAD (Planejamento de Atendimento Domiciliar) com terapias e frequências. Enviar com MÁXIMA URGÊNCIA, pois é necessário para análise no solicitador e liberação do acompanhamento.\n\n*🏠 VISITA IMPRODUTIVA*: Fotografar a frente da residência e enviar imediatamente à equipe. O pagamento depende da comprovação da visita previamente agendada.\n\n*🏥 INTERCORRÊNCIAS/HOSPITALIZAÇÃO*: Comunicar imediatamente qualquer intercorrência, internação, óbito ou falta. Em caso de hospitalização, suspender os atendimentos, informar a quantidade realizada e aguardar nova autorização da Multilife.\n\n{{tabela_dias}}\n\n*⚠️ O cumprimento dessas orientações é indispensável para faturamento e pagamento.*',
0, 1, 10
FROM health_insurers hi
WHERE LOWER(hi.name) LIKE '%unimed%'
  AND NOT EXISTS (SELECT 1 FROM health_insurer_orientations o WHERE o.health_insurer_id = hi.id AND o.label = 'Avaliação')
LIMIT 1;

INSERT INTO health_insurer_orientations (health_insurer_id, label, body, requires_credentials, is_active, sort_order)
SELECT hi.id, 'Relatório gerencial',
'*⚠️ ATENÇÃO – ORIENTAÇÕES INDISPENSÁVEIS ⚠️*\n\n*📋 EVOLUÇÃO*: A Ficha de Evolução não estará disponível na residência. Portanto, o profissional deverá levar a ficha impressa e preenchê-la em todos os atendimentos, com data, assinatura e carimbo.\n\n*📋 PRODUTIVIDADE*: A Ficha de Produtividade também não estará disponível na residência. O profissional deverá levar a ficha impressa e preencher todos os campos em cada sessão, com assinatura do paciente/responsável e grau de parentesco.\n\n*📤 ENVIO DAS FICHAS: As fichas de Evolução e Produtividade preenchidas deverão ser enviadas para a Tania do Financeiro – WhatsApp: 17 99677-4770, até o último dia do mês. ❗Sem as fichas devidamente preenchidas e enviadas, não haverá pagamento.*\n\n*📊 RELATÓRIO GERENCIAL*: Realizar mensalmente, mantendo o quadro do paciente atualizado.\n\nEnviar IMEDIATAMENTE APÓS O PRIMEIRO ATENDIMENTO e sempre DIGITADO.\n\n*🏠 VISITA IMPRODUTIVA*: Fotografar a frente da residência e enviar imediatamente à equipe. O pagamento depende da comprovação da visita previamente agendada.\n\n*🏥 INTERCORRÊNCIAS/HOSPITALIZAÇÃO*: Comunicar imediatamente qualquer intercorrência, internação, óbito ou falta. Em caso de hospitalização, suspender os atendimentos, informar a quantidade realizada e aguardar nova autorização da Multilife.\n\n{{tabela_dias}}\n\n*⚠️ O cumprimento dessas orientações é indispensável para faturamento e pagamento.*',
0, 1, 20
FROM health_insurers hi
WHERE LOWER(hi.name) LIKE '%unimed%'
  AND NOT EXISTS (SELECT 1 FROM health_insurer_orientations o WHERE o.health_insurer_id = hi.id AND o.label = 'Relatório gerencial')
LIMIT 1;

-- =====================================================================
-- VIP CARE
-- =====================================================================
INSERT INTO health_insurer_orientations (health_insurer_id, label, body, requires_credentials, is_active, sort_order)
SELECT hi.id, 'Avaliação',
'*⚠️ ATENÇÃO – ORIENTAÇÕES INDISPENSÁVEIS ⚠️*\n\n*📋 EVOLUÇÃO*: A Ficha de Evolução estará disponível na residência e deverá ser preenchida em todos os atendimentos, com data, assinatura e carimbo.\n\n*📋 PRODUTIVIDADE*: A Ficha de Produtividade estará disponível na residência e deverá ser preenchida em todos os atendimentos, com todos os campos devidamente preenchidos, incluindo a assinatura do paciente/responsável e o grau de parentesco.\n\n*📤 ENVIO DAS FICHAS: As fichas de Evolução e Produtividade preenchidas deverão ser enviadas para a Tania do Financeiro – WhatsApp: 17 99677-4770, até o último dia do mês. ❗Sem as fichas devidamente preenchidas e enviadas, não haverá pagamento.*\n\n*📄 AVALIAÇÃO*: Preencher o documento completo, incluindo as indicações finais e o PAD (Planejamento de Atendimento Domiciliar), com as terapias e respectivas frequências. Enviar com MÁXIMA URGÊNCIA, pois é necessário para análise no solicitador e liberação do acompanhamento.\n\n*🏠 VISITA IMPRODUTIVA*: Fotografar a frente da residência e enviar imediatamente à equipe. O pagamento depende da comprovação da visita previamente agendada.\n\n*🏥 INTERCORRÊNCIAS/HOSPITALIZAÇÃO*: Comunicar imediatamente qualquer intercorrência, internação, óbito ou falta. Em caso de hospitalização, suspender os atendimentos, informar a quantidade realizada e aguardar nova autorização da Multilife.\n\n{{tabela_dias}}\n\n*⚠️ O cumprimento de todas essas orientações é indispensável para o faturamento e pagamento dos atendimentos.*',
0, 1, 10
FROM health_insurers hi
WHERE (LOWER(hi.name) LIKE '%vip care%' OR LOWER(hi.name) LIKE '%vipcare%')
  AND NOT EXISTS (SELECT 1 FROM health_insurer_orientations o WHERE o.health_insurer_id = hi.id AND o.label = 'Avaliação')
LIMIT 1;

INSERT INTO health_insurer_orientations (health_insurer_id, label, body, requires_credentials, is_active, sort_order)
SELECT hi.id, 'Relatório gerencial',
'*⚠️ ATENÇÃO – ORIENTAÇÕES INDISPENSÁVEIS ⚠️*\n\n*📋 EVOLUÇÃO*: A Ficha de Evolução estará disponível na residência e deverá ser preenchida em todos os atendimentos, com data, assinatura e carimbo.\n\n*📋 PRODUTIVIDADE*: A Ficha de Produtividade estará disponível na residência e deverá ser preenchida em todos os atendimentos, com todos os campos devidamente preenchidos, incluindo a assinatura do paciente/responsável e o grau de parentesco.\n\n*📤 ENVIO DAS FICHAS: As fichas de Evolução e Produtividade preenchidas deverão ser enviadas para a Tania do Financeiro – WhatsApp: 17 99677-4770, até o último dia do mês. ❗Sem as fichas devidamente preenchidas e enviadas, não haverá pagamento.*\n\n*📊 RELATÓRIO GERENCIAL*: Realizar mensalmente, mantendo o quadro do paciente atualizado.\n\nEnviar IMEDIATAMENTE APÓS O PRIMEIRO ATENDIMENTO e sempre DIGITADO\n\n*🏠 VISITA IMPRODUTIVA*: Fotografar a frente da residência e enviar imediatamente à equipe. O pagamento depende da comprovação da visita previamente agendada.\n\n*🏥 INTERCORRÊNCIAS/HOSPITALIZAÇÃO*: Comunicar imediatamente qualquer intercorrência, internação, óbito ou falta. Em caso de hospitalização, suspender os atendimentos, informar a quantidade realizada e aguardar nova autorização da Multilife.\n\n{{tabela_dias}}\n\n*⚠️ O cumprimento de todas essas orientações é indispensável para o faturamento e pagamento dos atendimentos.*',
0, 1, 20
FROM health_insurers hi
WHERE (LOWER(hi.name) LIKE '%vip care%' OR LOWER(hi.name) LIKE '%vipcare%')
  AND NOT EXISTS (SELECT 1 FROM health_insurer_orientations o WHERE o.health_insurer_id = hi.id AND o.label = 'Relatório gerencial')
LIMIT 1;

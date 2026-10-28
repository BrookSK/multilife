-- Migration: orientações por operadora que FALTAVAM.
-- Data: 2026-10-27
--
-- Conferência do banco (2026-10-27): havia 20 orientações; faltavam CENEMED,
-- GLOBAL (5 variações), PRONEP, RESIDENCIAL GERIÁTRICO VÔ CUIDAR e SENIOR MAIS.
-- Motivo: o seed 2026-10-03_0009 só insere quando a operadora existe, e essas
-- operadoras não estavam cadastradas. Além disso o seed procurava '%senior%' e
-- colocou o texto da SENIOR MAIS na "Prevent Senior" (operadora errada).
--
-- Esta migration (idempotente):
--   1) cadastra as operadoras que faltam (se ainda não existirem);
--   2) insere as orientações delas com os textos oficiais;
--   3) DESATIVA (não apaga) as 2 orientações gravadas por engano na Prevent Senior;
--   4) copia as orientações da Unimed para TODAS as Unimeds cadastradas
--      (Andradina/Bebedouro/Birigui/Londrina usam o mesmo texto).
--
-- Placeholders: {{login_externo}} / {{senha_externa}} (login/senha da pré-admissão)
-- e {{tabela_dias}} (tabela de padronização dos dias, gerada pelo sistema).

-- 1) Operadoras que faltam ------------------------------------------------
INSERT INTO health_insurers (name, is_active)
SELECT 'CENEMED', 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM health_insurers WHERE LOWER(name) LIKE '%cenemed%');
INSERT INTO health_insurers (name, is_active)
SELECT 'GLOBAL', 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM health_insurers WHERE LOWER(name) LIKE '%global%');
INSERT INTO health_insurers (name, is_active)
SELECT 'PRONEP', 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM health_insurers WHERE LOWER(name) LIKE '%pronep%');
INSERT INTO health_insurers (name, is_active)
SELECT 'RESIDENCIAL GERIÁTRICO VÔ CUIDAR', 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM health_insurers WHERE LOWER(name) LIKE '%cuidar%');
INSERT INTO health_insurers (name, is_active)
SELECT 'SENIOR MAIS', 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM health_insurers WHERE LOWER(name) LIKE '%senior mais%');

-- 2) Orientações (textos oficiais) ----------------------------------------
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

INSERT INTO health_insurer_orientations (health_insurer_id, label, body, requires_credentials, is_active, sort_order)
SELECT hi.id, 'Avaliação',
'*⚠️ ATENÇÃO – ORIENTAÇÕES INDISPENSÁVEIS ⚠️*\n\n*📋 EVOLUÇÃO*: A Ficha de Evolução estará disponível na residência e deverá ser preenchida em todos os atendimentos, com data, assinatura e carimbo.\n\n*📋 PRODUTIVIDADE*: A Ficha de Produtividade estará disponível na residência e deverá ser preenchida em todos os atendimentos, com todos os campos devidamente preenchidos, incluindo a assinatura do paciente/responsável e o grau de parentesco.\n\n*📤 ENVIO DAS FICHAS: As fichas de Evolução e Produtividade preenchidas deverão ser enviadas para a Tania do Financeiro – WhatsApp: 17 99677-4770, até o último dia do mês. ❗Sem as fichas devidamente preenchidas e enviadas, não haverá pagamento.*\n\n*📄 AVALIAÇÃO*: Preencher o documento completo, incluindo as indicações finais e o PAD (Planejamento de Atendimento Domiciliar), com as terapias e respectivas frequências. Enviar com MÁXIMA URGÊNCIA, pois é necessário para análise no solicitador e liberação do acompanhamento.\n\n*🏠 VISITA IMPRODUTIVA*: Fotografar a frente da residência e enviar imediatamente à equipe. O pagamento depende da comprovação da visita previamente agendada.\n\n*🏥 INTERCORRÊNCIAS/HOSPITALIZAÇÃO*: Comunicar imediatamente qualquer intercorrência, internação, óbito ou falta. Em caso de hospitalização, suspender os atendimentos, informar a quantidade realizada e aguardar nova autorização da Multilife.\n\n{{tabela_dias}}\n\n*⚠️ O cumprimento de todas essas orientações é indispensável para o faturamento e pagamento dos atendimentos.*',
0, 1, 10
FROM health_insurers hi
WHERE LOWER(hi.name) LIKE '%senior mais%'
  AND NOT EXISTS (SELECT 1 FROM health_insurer_orientations o WHERE o.health_insurer_id = hi.id AND o.label = 'Avaliação')
LIMIT 1;

INSERT INTO health_insurer_orientations (health_insurer_id, label, body, requires_credentials, is_active, sort_order)
SELECT hi.id, 'Relatório gerencial',
'*⚠️ ATENÇÃO – ORIENTAÇÕES INDISPENSÁVEIS ⚠️*\n\n*📋 EVOLUÇÃO*: A Ficha de Evolução estará disponível na residência e deverá ser preenchida em todos os atendimentos, com data, assinatura e carimbo.\n\n*📋 PRODUTIVIDADE*: A Ficha de Produtividade estará disponível na residência e deverá ser preenchida em todos os atendimentos, com todos os campos devidamente preenchidos, incluindo a assinatura do paciente/responsável e o grau de parentesco.\n\n*📤 ENVIO DAS FICHAS: As fichas de Evolução e Produtividade preenchidas deverão ser enviadas para a Tania do Financeiro – WhatsApp: 17 99677-4770, até o último dia do mês. ❗Sem as fichas devidamente preenchidas e enviadas, não haverá pagamento.*\n\n*📊 RELATÓRIO GERENCIAL*: Realizar mensalmente, mantendo o quadro do paciente atualizado.\n\nEnviar IMEDIATAMENTE APÓS O PRIMEIRO ATENDIMENTO e sempre DIGITADO\n\n*🏠 VISITA IMPRODUTIVA*: Fotografar a frente da residência e enviar imediatamente à equipe. O pagamento depende da comprovação da visita previamente agendada.\n\n*🏥 INTERCORRÊNCIAS/HOSPITALIZAÇÃO*: Comunicar imediatamente qualquer intercorrência, internação, óbito ou falta. Em caso de hospitalização, suspender os atendimentos, informar a quantidade realizada e aguardar nova autorização da Multilife.\n\n{{tabela_dias}}\n\n*⚠️ O cumprimento de todas essas orientações é indispensável para o faturamento e pagamento dos atendimentos.*',
0, 1, 20
FROM health_insurers hi
WHERE LOWER(hi.name) LIKE '%senior mais%'
  AND NOT EXISTS (SELECT 1 FROM health_insurer_orientations o WHERE o.health_insurer_id = hi.id AND o.label = 'Relatório gerencial')
LIMIT 1;

-- 3) Prevent Senior recebeu por engano o texto da SENIOR MAIS: desativa (reversível).
UPDATE health_insurer_orientations o
JOIN health_insurers hi ON hi.id = o.health_insurer_id
SET o.is_active = 0
WHERE LOWER(hi.name) LIKE '%prevent%'
  AND o.label IN ('Avaliação', 'Relatório gerencial');

-- 4) Mesmo texto para todas as Unimeds (Andradina, Bebedouro, Birigui, Londrina...).
INSERT INTO health_insurer_orientations (health_insurer_id, label, body, requires_credentials, is_active, sort_order)
SELECT hi.id, src.label, src.body, src.requires_credentials, 1, src.sort_order
FROM health_insurers hi
JOIN (
    SELECT o.label, o.body, o.requires_credentials, o.sort_order
    FROM health_insurer_orientations o
    WHERE o.is_active = 1 AND o.health_insurer_id = (
        SELECT MIN(o2.health_insurer_id) FROM health_insurer_orientations o2
        JOIN health_insurers h2 ON h2.id = o2.health_insurer_id
        WHERE LOWER(h2.name) LIKE '%unimed%'
    )
) src
WHERE LOWER(hi.name) LIKE '%unimed%'
  AND NOT EXISTS (SELECT 1 FROM health_insurer_orientations x WHERE x.health_insurer_id = hi.id AND x.label = src.label);

-- Conferência:
-- SELECT hi.name AS operadora, o.label, o.is_active FROM health_insurer_orientations o
-- JOIN health_insurers hi ON hi.id = o.health_insurer_id ORDER BY hi.name, o.sort_order;

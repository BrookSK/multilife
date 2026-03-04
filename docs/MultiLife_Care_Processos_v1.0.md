# MULTILIFE CARE
## Sistema de Gestão de Saúde Domiciliar
### Documento de Processos e Requisitos Funcionais — Versão 1.0

---

## SUMÁRIO

| Módulo / Seção | |
|---|---|
| 1. Visão Geral do Sistema | |
| 2. Perfis de Acesso e Permissões | |
| 3. Fluxo de Captação de Demandas | |
| 4. Módulo de Comunicação (Chat Interno) | |
| 5. Processo de Candidatura de Profissionais | |
| 6. Fluxo do Profissional — Atendimento e Faturamento | |
| 7. Módulo de Pacientes — Cadastro e Prontuário | |
| 8. Módulo de Agendamentos | |
| 9. Módulo Financeiro | |
| 10. Gestão Documental | |
| 11. Módulo Administrativo e RH | |
| 12. Integrações Externas | |
| 13. Logs, Auditoria e Backups | |
| 14. Conformidade LGPD | |

---

## 1. VISÃO GERAL DO SISTEMA

O MultiLife Care é uma plataforma web de gestão de saúde domiciliar (home care). O sistema centraliza os processos de captação de demandas, comunicação com profissionais de saúde, agendamento de atendimentos, faturamento, gestão documental e controle administrativo em um único ambiente integrado.

### 1.1 Objetivo

Automatizar e rastrear o ciclo completo de um atendimento domiciliar: desde o recebimento da solicitação via e-mail, passando pela captação e confirmação de um profissional habilitado, até o registro do atendimento realizado, faturamento e consolidação documental — garantindo rastreabilidade, conformidade com a LGPD e eficiência operacional.

### 1.2 Tecnologias e Integrações

| Tecnologia / API | Finalidade |
|---|---|
| PHP + JavaScript | Base da plataforma |
| API OpenAI (ChatGPT) | Extração inteligente de dados de e-mails recebidos |
| Evolution API (WhatsApp) | Disparo de mensagens em grupos, notificações e webhooks |
| ZapSign | Assinatura digital de documentos e contratos |
| SMTP Interno | Recebimento e envio de e-mails transacionais |
| Webhooks HTTP | Integração com sistemas externos e automações |

### 1.3 Ciclo Completo de um Atendimento

```
① E-mail recebido
② IA extrai dados
③ Card criado
④ Captador assume
⑤ Disparo em grupos WhatsApp
⑥ Profissional responde
⑦ Confirmação via chat
⑧ Agendamento gerado
⑨ Notificação ao paciente
⑩ Profissional realiza atendimento
⑪ Preenche formulário de documentação
⑫ Dados registrados no prontuário
⑬ Faturamento verificado
⑭ Repasse financeiro processado
⑮ Documentos consolidados
```

---

## 2. PERFIS DE ACESSO E PERMISSÕES

O sistema adota um modelo de controle de acesso baseado em perfis (RBAC — Role-Based Access Control). Cada usuário é associado a um perfil que determina quais módulos, ações e dados ele pode acessar.

| Perfil | Principais Permissões |
|---|---|
| **Admin** | Acesso total ao sistema. Configurações globais, aprovações, logs, financeiro, RH, backups e todos os módulos. |
| **Financeiro** | Visualização e gestão de contas a pagar/receber, faturamento, repasses, indicadores financeiros e relatórios. |
| **Captadores / Admissão** | Gestão de cards de demanda, comunicação via chat, cadastro de pacientes, vinculação paciente-profissional e agendamentos. |
| **TI** | Acesso a logs técnicos, configurações de integração, monitoramento de webhooks e backups. |
| **Profissionais** | Acesso restrito ao próprio perfil, lista de pacientes vinculados, formulário de documentação e histórico de atendimentos. |

**Regras adicionais:**

- Profissionais não visualizam dados de outros profissionais.
- O perfil Financeiro não tem acesso a prontuários ou dados clínicos.
- Todas as ações de criação, edição e exclusão são registradas no log de auditoria com identificação do usuário.
- Senhas devem seguir política mínima de segurança (8 caracteres, letras e números).
- Sessões expiram após período configurável pelo Admin.

---

## 3. FLUXO DE CAPTAÇÃO DE DEMANDAS

Este módulo representa o ponto de entrada das solicitações de atendimento. O fluxo é iniciado por um e-mail externo e percorre etapas automatizadas até a confirmação do profissional e geração do agendamento.

### 3.1 Recebimento de E-mail

O sistema disponibiliza um endereço de e-mail gerado internamente (ex: `demandas@multilife.sistema`), operando via servidor SMTP próprio. Qualquer solicitação enviada para este endereço é capturada automaticamente.

- O endereço de e-mail é configurado no painel do Admin.
- O sistema monitora a caixa de entrada em intervalo configurável (ex: a cada 5 minutos).
- E-mails processados são marcados como lidos e arquivados.

### 3.2 Extração de Dados com Inteligência Artificial

Ao receber o e-mail, o sistema envia o conteúdo para a API do ChatGPT (OpenAI) com um prompt estruturado para extração dos dados relevantes. O modelo identifica e formata as informações no padrão do card interno:

| Campo | Descrição |
|---|---|
| **Título** | Descrição resumida da demanda |
| **Localização** | Cidade e Estado identificados no e-mail |
| **Especialidade** | Tipo de profissional necessário (ex: Fisioterapia) |
| **Descrição** | Detalhamento clínico ou operacional do atendimento |
| **Origem** | E-mail remetente / empresa solicitante |

> ⚠️ **Exceção — Dados Insuficientes:** Se a IA não conseguir extrair os dados mínimos (localização ou especialidade), o card é criado com status **"Tratamento Manual"** e um alerta é exibido no painel do Captador indicando quais campos necessitam de preenchimento manual.

### 3.3 Criação do Card de Pendência

Após a extração, o sistema cria automaticamente um card de pendência no painel dos Captadores com status **"Aguardando Captação"**.

- Cada card recebe um ID único gerado pelo sistema.
- Data e hora de criação são registradas automaticamente.
- O card fica visível para todos os Captadores disponíveis.

### 3.4 Assumindo a Demanda

Um Captador acessa o card e clica em **"Assumir Demanda"**. A partir desse momento, o card fica bloqueado para outros captadores e exibe o nome do responsável.

- O sistema registra o captador responsável e o horário em que assumiu.
- Se o captador não der andamento em X horas (configurável pelo Admin), o card retorna ao pool geral com alerta.

### 3.5 Disparo nos Grupos de WhatsApp

Dentro do card, o Captador clica em **"Realizar Captação"**. O sistema identifica automaticamente os grupos de WhatsApp cadastrados com correspondência de especialidade, cidade e estado, e dispara a mensagem via Evolution API.

- Os grupos são previamente cadastrados pelo Admin com os atributos: nome, especialidade, cidade, estado.
- O conteúdo da mensagem é configurável no painel do Admin.
- O sistema registra data, hora e grupos para os quais o disparo foi realizado.

> ⚠️ **Exceção — Nenhum Grupo Compatível:** Se nenhum grupo cadastrado corresponder à especialidade + localização do card, o sistema exibe alerta ao captador: *"Nenhum grupo compatível encontrado."* O captador pode ajustar os filtros manualmente ou escalar para o Admin.

### 3.6 Monitoramento das Respostas

Após o disparo, profissionais interessados entram em contato via WhatsApp diretamente com o número da empresa (pvt). Estas conversas aparecem no Módulo de Comunicação (Seção 4), onde o Captador prossegue a tratativa.

### 3.7 Confirmação do Profissional e Geração do Agendamento

Ao confirmar o interesse e aptidão do profissional via chat, o Captador preenche os dados do agendamento:

- Profissional vinculado
- Paciente vinculado
- Data e horário do primeiro atendimento
- Frequência (único / X vezes por semana por Z dias)
- Valor do atendimento (verificado contra o mínimo configurado)

Após salvar, o sistema executa automaticamente:

1. Cria o agendamento no Módulo de Agendamentos (Seção 8).
2. Gera pendência ao profissional para preenchimento do formulário pós-atendimento.
3. Dispara webhook de notificação ao paciente com dados do profissional.
4. Envia e-mail de confirmação ao e-mail de origem e ao paciente (quando disponível).
5. Atualiza o status do card para **"Admitido"**.

---

## 4. MÓDULO DE COMUNICAÇÃO — CHAT INTERNO

O módulo de comunicação centraliza as conversas privadas de WhatsApp recebidas nos números da empresa, apresentando uma interface inspirada no WhatsApp Web diretamente na plataforma.

### 4.1 Regras Fundamentais

- Conversas são sempre iniciadas pelo cliente/profissional externo. O sistema não pode disparar o primeiro contato em conversas pvt.
- Uma vez iniciada, a conversa fica livre para troca de mensagens entre o atendente e o contato.
- Cada conversa exibe o perfil do contato identificado pelo número de telefone, buscando no banco de dados a qual usuário (profissional ou paciente) o número pertence.

### 4.2 Layout do Chat

| Bloco | Conteúdo |
|---|---|
| **Esquerda — Lista de Conversas** | Lista de chats ativos com nome, número e prévia da última mensagem. Indicador visual de mensagens não lidas. |
| **Centro — Área de Mensagens** | Histórico completo da conversa selecionada. Campo de texto para resposta. Suporte a texto, emojis e arquivos. |
| **Direita — Painel do Contato** | Dados do profissional ou paciente identificado: nome, especialidade, documentos, atendimentos vinculados e botão de ação (ex: Confirmar Admissão). |

### 4.3 Identificação de Contatos

Ao receber uma mensagem, o sistema consulta o banco de dados pelo número de telefone do remetente. Se encontrado, exibe os dados completos do profissional ou paciente vinculado. Se não encontrado, exibe **"Contato não identificado"** com opção de cadastro manual.

### 4.4 Transferência de Conversa

O atendente pode transferir uma conversa ativa para outro atendente através do botão **"Transferir"**. O histórico completo da conversa é mantido e visível para o novo atendente.

### 4.5 Conversas Não Respondidas

O sistema monitora o tempo sem resposta. Conversas que ultrapassem o tempo limite configurado pelo Admin geram uma **pendência automática** com alerta visual no painel do captador responsável.

### 4.6 Encerramento de Conversa

O atendente pode encerrar uma conversa pelo botão **"Finalizar"**. Conversas finalizadas são movidas para a aba **"Histórico"**, onde permanecem acessíveis para consulta futura com filtros por data, atendente e contato.

---

## 5. PROCESSO DE CANDIDATURA DE PROFISSIONAIS

O sistema disponibiliza um fluxo público de candidatura para novos profissionais, com avaliação administrativa e onboarding automatizado.

### 5.1 Link de Candidatura

Um link público é gerado pelo sistema e pode ser compartilhado em canais de recrutamento. O candidato preenche a ficha cadastral completa:

| Grupo de Dados | Campos Principais |
|---|---|
| **Identificação** | Nome completo, cidades de atuação, estado civil, sexo, religião, naturalidade, nacionalidade, escolaridade |
| **Endereço** | Logradouro, bairro, cidade, CEP, número, UF |
| **Contato** | Telefone, e-mail |
| **Documentos** | RG, sigla do conselho profissional, número do conselho, UF do conselho |
| **Dados Bancários** | Banco, agência, conta, tipo, titular, CPF, PIX, titular do PIX |
| **Informações Técnicas** | Experiências em home care, tempo de atuação, especializações/pós-graduação |

### 5.2 Avaliação Administrativa

Após o envio da ficha, o Admin recebe notificação de nova candidatura pendente e pode:

- **Aprovar** — libera acesso ao sistema e inicia o onboarding.
- **Reprovar** — arquiva o cadastro com justificativa registrada.
- **Solicitar complemento** — envia notificação ao candidato pedindo dados faltantes.

### 5.3 Onboarding Automático

Ao aprovar a candidatura, o sistema executa automaticamente:

1. Criação da conta de acesso ao sistema com perfil "Profissional", gerenciada internamente pela plataforma.
2. Envio de mensagem via WhatsApp (webhook Evolution API) com credenciais de acesso e instruções de uso da plataforma.
3. Envio de e-mail de boas-vindas com o link de acesso ao sistema.
4. Criação da ficha do profissional com todos os dados cadastrais.
5. Geração de pasta documental individual no módulo de gestão de documentos.

### 5.4 Validade de Documentos do Profissional

O sistema monitora documentos com prazo de validade (COREN, CRM, certidões):

- **30 dias antes do vencimento** — notificação ao profissional e ao Admin.
- **Na data de vencimento** — pendência criada no painel Admin com bloqueio de novos agendamentos para o profissional.

---

## 6. FLUXO DO PROFISSIONAL — ATENDIMENTO E FATURAMENTO

Após a realização de um atendimento, o profissional é responsável pelo registro e documentação das informações na plataforma dentro do prazo estabelecido. **O profissional não define valores financeiros** — essa responsabilidade é exclusiva do Captador no momento da criação do agendamento.

### 6.1 Prazo para Registro

O profissional tem até **48 horas** após a finalização do último atendimento de um ciclo para submeter o formulário. Para atendimentos recorrentes, o prazo também se aplica ao fechamento mensal.

- Webhook via WhatsApp (Evolution API) com X dias de antecedência — configurável pelo Admin.
- Se o formulário não for enviado: reenvio diário por até **7 dias consecutivos**.
- Após 7 dias sem ação: criação de **pendência de revisão** no painel do Admin.

### 6.2 Formulário de Documentação

O profissional acessa o sistema, seleciona o paciente vinculado e preenche o formulário com:

| Campo | Descrição |
|---|---|
| **Identificação do Paciente** | Seleção do paciente da lista vinculada ao profissional |
| **Quantidade de atendimentos** | Número de sessões realizadas no período |
| **Documentos de Faturamento** | Upload de imagens/arquivos da categoria Faturamento |
| **Documentos de Produtividade** | Upload de imagens/arquivos da categoria Produtividade |
| **Observações** | Campo texto livre para informações adicionais |

### 6.3 Controle de Valor Mínimo

O valor do atendimento é definido pelo Captador no momento da criação do agendamento e registrado como custo operacional. O profissional não define nem altera valores — sua responsabilidade é exclusivamente a documentação do atendimento realizado.

O sistema valida o valor inserido pelo Captador contra o mínimo configurado por tipo de especialidade. Se o valor for inferior ao mínimo:

1. O sistema bloqueia o fechamento do agendamento.
2. Exibe mensagem explicativa informando o valor mínimo.
3. Disponibiliza o botão **"Solicitar Autorização aos Admins"**.
4. O Admin recebe um alerta no painel com os detalhes da solicitação.
5. O Admin pode **Autorizar** (libera com o valor solicitado) ou **Rejeitar** (notifica o captador com justificativa).

### 6.4 Registro Pós-Envio

Após o envio válido do formulário, o sistema executa automaticamente:

1. Registra os dados do atendimento no prontuário do paciente.
2. Registra o atendimento na ficha do profissional.
3. Dispara webhook de confirmação via WhatsApp ao profissional.
4. Atualiza o status da pendência para **"Concluído"**.
5. Alimenta os indicadores financeiros e operacionais do dashboard.

---

## 7. MÓDULO DE PACIENTES — CADASTRO E PRONTUÁRIO

O cadastro de pacientes é realizado pelos Captadores e pelo time de Admissão. Os dados são organizados em abas de navegação para coerência visual e facilidade de acesso.

### 7.1 Abas de Navegação do Cadastro

| Aba | Conteúdo Principal |
|---|---|
| **Identificação** | Dados pessoais completos, foto, CPF, RG, estado civil, profissão, escolaridade |
| **Contato** | Telefones, WhatsApp, e-mail, preferência de contato |
| **Endereço** | CEP, logradouro, complemento, bairro, cidade, estado, país |
| **Emergência** | Nome, parentesco e contatos do responsável de emergência |
| **Convênio** | Dados do plano de saúde, carteirinha (frente e verso), validade |
| **Saúde** | Dados biométricos, tipo sanguíneo, alergias, doenças crônicas, medicamentos |
| **Histórico Médico** | Cirurgias, internações, histórico familiar, hábitos de vida |
| **Documentos** | Arquivos organizados por mês/ano (exames, receitas, relatórios) |
| **Financeiro** | Forma de pagamento, histórico de faturas, pendências |
| **LGPD** | Termos de consentimento assinados digitalmente |
| **Responsável** | Dados do responsável legal (menores ou incapazes) |
| **Prontuário** | Registros de todos os atendimentos realizados por data |
| **Administrativo** | Status, unidade, médico responsável, metadados do sistema |

### 7.2 Prontuário Digital

O prontuário reúne o histórico completo de atendimentos do paciente. Cada entrada é criada automaticamente quando o profissional submete o formulário, contendo:

- Data e hora do atendimento
- Nome do profissional e especialidade
- Quantidade de sessões realizadas
- Documentos anexados (faturamento e produtividade)
- Observações clínicas do profissional

O prontuário é **somente leitura** para o profissional. Correções só podem ser realizadas pelo Admin com registro de alteração no log de auditoria.

### 7.3 Vinculação Paciente-Profissional

A vinculação é realizada pelo Captador no momento da admissão. Um paciente pode ser vinculado a múltiplos profissionais (especialidades diferentes). A vinculação determina:

- Quais pacientes o profissional visualiza em seu formulário.
- Qual profissional é notificado nas comunicações do paciente.
- Qual histórico de atendimentos é exibido no prontuário.

### 7.4 Cancelamento pelo Paciente

Ao confirmar um agendamento, o sistema gera um **link único por atendimento**, enviado ao paciente via WhatsApp. Através deste link, o paciente pode confirmar presença ou informar ausência/cancelamento do profissional. Em caso de cancelamento, o sistema cria um alerta para o Captador responsável para reagendamento ou captação de novo profissional.

---

## 8. MÓDULO DE AGENDAMENTOS

O módulo de agendamentos centraliza todos os atendimentos programados, com controle de recorrência, status e comunicações automáticas.

### 8.1 Criação do Agendamento

O agendamento é criado pelo Captador no momento da confirmação do profissional. Campos obrigatórios:

- Paciente vinculado
- Profissional vinculado
- Data e horário do primeiro atendimento
- Modalidade de recorrência
- Valor do atendimento por sessão

### 8.2 Modalidades de Recorrência

| Modalidade | Descrição |
|---|---|
| **Atendimento Único** | Ocorre somente uma vez na data agendada. |
| **Recorrente Semanal** | Repete X vezes por semana durante Z dias (ex: 3x/semana por 30 dias). |
| **Recorrente Mensal** | Define ciclos mensais com fechamento no último dia do mês. |
| **Personalizado** | Configuração flexível de dias específicos da semana e duração total. |

### 8.3 Ciclo de Vida do Agendamento

| Status | Descrição |
|---|---|
| **Agendado** | Criado, aguardando realização. |
| **Realizado** | Profissional submeteu o formulário com sucesso. |
| **Pendente de Formulário** | Prazo de 48h ativo, aguardando envio do profissional. |
| **Atrasado** | Prazo expirado, webhook de cobrança em andamento. |
| **Cancelado** | Cancelado por paciente, profissional ou admin com registro de motivo. |
| **Revisão Admin** | Sem ação do profissional após 7 dias — requer intervenção manual. |

### 8.4 Renovação e Encerramento de Ciclos

Ao término de um ciclo recorrente, o sistema cria automaticamente uma pendência para o Captador para decidir sobre renovação. O Captador pode:

- Renovar o ciclo com os mesmos parâmetros.
- Ajustar frequência, valor ou profissional e renovar.
- Encerrar o atendimento do paciente.

---

## 9. MÓDULO FINANCEIRO

O módulo financeiro consolida o controle de receitas, custos, repasses a profissionais e indicadores de saúde financeira da operação.

### 9.1 Contas a Receber

Geradas automaticamente ao confirmar um agendamento. Cada conta está vinculada a um paciente, profissional, especialidade e data de atendimento.

- Valores são registrados pelo Captador na criação do agendamento.
- Status: **Pendente → Recebido → Inadimplente**.
- Baixa pode ser manual (financeiro) ou automática por integração.

### 9.2 Contas a Pagar — Repasse a Profissionais

O repasse ao profissional é calculado com base nos atendimentos realizados e confirmados. O ciclo de repasse é configurável pelo Admin (ex: quinzenal, mensal).

1. Profissional submete formulário de documentação do atendimento.
2. Admin ou Financeiro valida os documentos enviados.
3. Sistema calcula o valor de repasse: valor por atendimento × quantidade de sessões.
4. Repasse é registrado em Contas a Pagar com data prevista de pagamento.
5. Após confirmação de pagamento, status atualizado e profissional notificado.

### 9.3 Controle de Valor Mínimo por Especialidade

O Admin configura valores mínimos por tipo de atendimento no painel de configurações. O sistema valida qualquer lançamento feito pelo Captador contra esses limites (detalhe completo na Seção 6.3).

### 9.4 Indicadores Financeiros

| Indicador | Descrição |
|---|---|
| **Faturamento Total** | Soma de todas as contas a receber confirmadas no período. |
| **Custo de Atendimentos** | Soma de todos os repasses realizados a profissionais no período. |
| **Margem Operacional** | Faturamento menos custos de atendimento. |
| **Contas a Receber** | Total de valores pendentes de recebimento. |
| **Contas a Pagar** | Total de repasses pendentes de pagamento. |
| **Inadimplência** | Valor total de contas vencidas não recebidas. |

Todos os indicadores são filtráveis por período (dia, semana, mês, ano), profissional, especialidade e cidade.

---

## 10. GESTÃO DOCUMENTAL

O sistema organiza todos os documentos em uma estrutura hierárquica de pastas, segregada por tipo de entidade (paciente, profissional, empresa) e por período (mês/ano).

### 10.1 Estrutura de Pastas

```
📁 Pacientes
   └── 📁 [Nome do Paciente — ID]
        └── 📁 [Ano] / 📁 [Mês]
             └── Exames, Receitas, Relatórios, Consentimentos

📁 Profissionais
   └── 📁 [Nome do Profissional — ID]
        └── 📁 [Ano] / 📁 [Mês]
             └── Faturamento, Produtividade, Documentos pessoais

📁 Empresa
   └── 📁 Contratos
   └── 📁 RH
   └── 📁 Financeiro
   └── 📁 Técnico
```

### 10.2 Nomenclatura Padrão de Arquivos

Arquivos enviados são renomeados automaticamente:

```
[TIPO]_[ID-ENTIDADE]_[AAAA-MM-DD]_[SEQUENCIAL].[extensão]

Exemplos:
  FATURAMENTO_PROF00123_2025-06-15_001.pdf
  EXAME_PAC00456_2025-06-20_001.jpg
  CONTRATO_EMP_2025-01-10_001.pdf
```

### 10.3 Controle de Validade Documental

Documentos com prazo de validade (COREN, CRM, certidões, contratos) possuem campo de data de vencimento:

- **30 dias antes:** notificação ao profissional e ao Admin.
- **Vencido:** pendência criada no Admin + bloqueio de novos agendamentos.

### 10.4 Controle de Versão

Documentos atualizados não substituem a versão anterior. O histórico de versões é mantido com data de upload, usuário responsável e versão numerada (v1, v2, v3...).

---

## 11. MÓDULO ADMINISTRATIVO E RECURSOS HUMANOS

### 11.1 Dashboard Operacional

| Indicador | Descrição |
|---|---|
| **Atendimentos Recebidos** | Total de demandas/cards criados no período. |
| **Atendimentos Realizados** | Total de formulários confirmados no período. |
| **Taxa de Conversão** | Percentual de demandas que resultaram em atendimento confirmado. |
| **Profissionais Ativos** | Quantidade de profissionais com agendamentos no período. |
| **Pendências em Aberto** | Cards sem ação, formulários atrasados, documentos vencidos. |

### 11.2 Painel de Configurações do Admin

- Tempo de antecedência para disparo do lembrete de formulário ao profissional.
- Ciclo de repasse financeiro a profissionais (dias entre repasses).
- Valores mínimos por tipo de especialidade/atendimento.
- Tempo limite para captadores assumirem um card.
- Tempo limite para resposta em chats (antes de gerar pendência).
- Configurações de SMTP para e-mails.
- Frequência do monitoramento de caixa de entrada.
- Configurações das APIs OpenAI e Evolution.

### 11.3 Recursos Humanos

O módulo de RH cobre o ciclo completo do colaborador interno:

1. Cadastro de funcionário com dados pessoais e função.
2. Criação digital de contrato e documentos via templates pré-configurados.
3. Envio automático para assinatura via ZapSign (webhook).
4. Monitoramento do status de assinatura (pendente / assinado / vencido).
5. Arquivamento do documento assinado na pasta documental da empresa.

> ⚠️ **Exceção — Documento Não Assinado:** Se o documento não for assinado dentro do prazo configurado, o sistema cria uma pendência no painel Admin e envia um lembrete ao colaborador via e-mail.

### 11.4 Gestão de Grupos WhatsApp

O Admin cria e gerencia grupos de WhatsApp diretamente pela plataforma via Evolution API. Cada grupo possui:

- Nome do grupo
- Especialidade associada
- Cidade e Estado de atuação
- Número de contatos (atualizado automaticamente)
- Status (ativo/inativo)

---

## 12. INTEGRAÇÕES EXTERNAS

| Integração | Gatilho | Payload Principal |
|---|---|---|
| **Evolution API — Grupos** | Captador clica em "Realizar Captação" | Mensagem de captação + dados do card |
| **Evolution API — Profissional** | Aprovação de candidatura / onboarding | Credenciais + instruções de acesso |
| **Evolution API — Paciente** | Confirmação de agendamento | Dados do profissional + data/hora + link de suporte |
| **Evolution API — Lembrete** | X dias antes do prazo do formulário | Nome do paciente + prazo + link do formulário |
| **Evolution API — Cobrança** | Formulário não enviado após prazo | Alerta de pendência + link direto |
| **OpenAI API** | E-mail recebido na caixa do sistema | Conteúdo do e-mail → card estruturado |
| **ZapSign** | Criação de contrato RH ou documento admin | Documento PDF + dados do signatário |
| **SMTP Saída** | Confirmação de agendamento ou admissão | E-mail ao paciente e/ou origem |
| **SMTP Entrada** | Contínuo (polling configurável) | Leitura da caixa de demandas |
| **Webhook — Link Paciente** | Cancelamento informado pelo paciente | Dados do atendimento + ação do paciente |

### 12.1 Tratamento de Falhas de Integração

- Todas as chamadas de webhook/API são registradas no log técnico com status (sucesso/erro) e payload.
- Falhas são automaticamente reprocessadas até 3 vezes com intervalo exponencial.
- Após 3 tentativas sem sucesso, uma pendência de revisão técnica é criada no painel do perfil TI.

---

## 13. LOGS, AUDITORIA E BACKUPS

### 13.1 Log de Auditoria de Usuário

Todas as ações realizadas por usuários autenticados são registradas com:

- Usuário (ID + nome + perfil)
- Ação realizada (criar, editar, excluir, aprovar, rejeitar)
- Módulo e registro afetado
- Valor anterior e novo valor (para edições)
- Data, hora e IP de origem

Filtros disponíveis: por usuário, paciente, profissional, módulo, tipo de ação e período.

### 13.2 Log Técnico (TI)

Registra eventos de sistema, erros de integração, falhas de webhook, chamadas de API e exceções de código. Visível apenas para perfil TI e Admin.

### 13.3 Backups Automáticos

| Parâmetro | Configuração |
|---|---|
| **Frequência** | Configurável pelo Admin (padrão: diário às 02h00) |
| **Destino** | Armazenamento em nuvem (configurável: S3, Google Drive, FTP) |
| **Retenção** | Configurável: padrão 30 dias de histórico de backups |
| **Escopo** | Banco de dados + arquivos de mídia (documentos, imagens) |
| **Notificação** | E-mail ao Admin em caso de falha no backup |
| **Restore** | Procedimento documentado no painel TI com backup selecionável por data |

---

## 14. CONFORMIDADE LGPD

Por se tratar de software de saúde, o sistema opera sob as diretrizes da Lei Geral de Proteção de Dados (Lei 13.709/2018) e das regulamentações do CFM/CFN para prontuários eletrônicos.

### 14.1 Consentimento

- Todos os pacientes assinam digitalmente o Termo de Consentimento e a Política de Privacidade no ato do cadastro.
- O consentimento é versionado — se a política for atualizada, nova coleta de consentimento é solicitada.
- Profissionais assinam Termo de Uso e Confidencialidade no onboarding.

### 14.2 Dados Sensíveis

- Dados de saúde são classificados como sensíveis e têm acesso restrito por perfil.
- Profissionais acessam apenas dados clínicos dos pacientes vinculados a eles.
- O perfil Financeiro não tem acesso a dados clínicos — apenas dados financeiros.

### 14.3 Retenção e Exclusão

- Dados de pacientes inativos são retidos por período mínimo de 20 anos conforme resolução CFM 2314/2022.
- Solicitações de exclusão de dados são tratadas pelo Admin com registro no log de auditoria.
- A exclusão lógica mantém os dados no banco com flag "excluído" para fins de auditoria.

### 14.4 Segurança de Dados

- Comunicação via HTTPS com certificado SSL.
- Senhas armazenadas com hash bcrypt.
- Dados sensíveis em banco de dados criptografados (AES-256).
- Sessões com token JWT de curta duração + refresh token.
- Logs de acesso a prontuários registrados individualmente.

---

*MultiLife Care — Documento de Processos e Requisitos Funcionais — Versão 1.0 — Confidencial*

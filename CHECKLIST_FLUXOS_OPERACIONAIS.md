# ✅ MultiLife Care — Checklist Completo de Fluxos Operacionais

**Documento para apresentação à cliente**  
**Versão:** 1.0 | **Data:** Junho/2026  

---

## 📌 Como Usar Este Checklist

- ☐ = Item a ser demonstrado / verificado
- Use este roteiro na ordem para apresentar o sistema tela a tela
- As **⚠️ Ressalvas** destacam regras de negócio importantes (bloqueios, restrições)

---

## FLUXO 1 — Acesso ao Sistema (Login)

| # | Etapa | Verificar |
|---|-------|-----------|
| ☐ | 1.1 | Usuário acessa URL do sistema |
| ☐ | 1.2 | Se já está logado → redireciona automaticamente para o Dashboard |
| ☐ | 1.3 | Se NÃO logado → exibe Tela de Login (e-mail + senha) |
| ☐ | 1.4 | Conta ativa + senha correta → sessão iniciada → Dashboard |
| ☐ | 1.5 | Conta inativa OU senha incorreta → mensagem de erro |
| ☐ | 1.6 | Sessão expira automaticamente conforme tempo configurado pelo Admin |
| ☐ | 1.7 | Após expiração, redireciona para login novamente |

**⚠️ Ressalva:** O tempo de expiração é configurável em Admin > Configurações.

---

## FLUXO 2 — Candidatura de Profissional

| # | Etapa | Verificar |
|---|-------|-----------|
| ☐ | 2.1 | Profissional acessa formulário público (link aberto, sem login) |
| ☐ | 2.2 | Preenche dados: Nome, E-mail, Telefone, Cidades, Especializações |
| ☐ | 2.3 | Dados bancários, documentos, experiência profissional |
| ☐ | 2.4 | E-mail já existe no sistema? → **Bloqueado (duplicata)** |
| ☐ | 2.5 | E-mail novo → Candidatura registrada com Status: **Pendente** |
| ☐ | 2.6 | Admin visualiza candidaturas no painel |
| ☐ | 2.7 | Admin pode: **Aprovar** → cria conta + senha provisória |
| ☐ | 2.8 | Admin pode: **Rejeitar** → candidatura recusada |
| ☐ | 2.9 | Admin pode: **Solicitar mais informações** → volta ao candidato |
| ☐ | 2.10 | Ao aprovar: envio automático de credenciais via WhatsApp e E-mail |
| ☐ | 2.11 | Validação automática do registro profissional no conselho (CRM, COREN, CRP, etc.) |

**⚠️ Ressalvas:**
- Conselhos validados automaticamente: CRM, COREN, CRP, CRN, CREFITO, CRO, CREA, OAB
- Sistema usa multi-provedor com fallback (Consultar.IO → Infosimples → Portal Direto)
- Resultado da validação fica registrado com cache de 24h

---

## FLUXO 3 — Captação de Demandas (Manual)

| # | Etapa | Verificar |
|---|-------|-----------|
| ☐ | 3.1 | Operador cria nova demanda: Título, Cidade, Especialidade, Descrição |
| ☐ | 3.2 | Campos opcionais: Urgência, Frequência, Sub-solicitações |
| ☐ | 3.3 | Status inicial: **Aguardando Captação** |
| ☐ | 3.4 | Operador assume a demanda → Status: **Em Captação** |
| ☐ | 3.5 | Disparo WhatsApp para grupos filtrados por especialidade + cidade + estado |
| ☐ | 3.6 | Profissional responde → fluxo de Seleção (Fluxo 6) |
| ☐ | 3.7 | Profissional não responde → **Tratamento Manual** |

**Ciclo de Status da Demanda:**
```
Aguardando Captação → Em Captação → Admitido → Concluído
                                  ↘ Cancelado
                                  ↘ Autorização Negada
                                  ↘ Tratamento Manual
```

**⚠️ Ressalvas:**
- **Timeout automático:** Se o operador assume mas não dá andamento em X horas (padrão: 4h), o sistema libera a demanda automaticamente de volta ao pool
- Cancelamento é obrigatório informar motivo
- Demanda cancelada pode ser **reativada** (exige justificativa)
- Reativação muda status para "Em Captação"

---

## FLUXO 4 — Captação via E-mail (Automática com IA)

| # | Etapa | Verificar |
|---|-------|-----------|
| ☐ | 4.1 | E-mails chegam na caixa configurada (IMAP) |
| ☐ | 4.2 | Sistema lê e-mails periodicamente (CRON configurável) |
| ☐ | 4.3 | IA (OpenAI) analisa conteúdo do e-mail |
| ☐ | 4.4 | É solicitação de atendimento? **SIM** → Demanda criada automaticamente |
| ☐ | 4.5 | Dados extraídos pela IA: título, localização, especialidade, descrição, origem |
| ☐ | 4.6 | **NÃO** é solicitação → E-mail arquivado sem ação |
| ☐ | 4.7 | Demanda criada entra no painel de captação (fluxo normal) |
| ☐ | 4.8 | Console de teste da IA disponível no Admin (admin_openai_console.php) |

**⚠️ Ressalvas:**
- Modelo da IA configurável (GPT-4, GPT-3.5, etc.)
- Prompt de extração é editável pelo Admin
- E-mails já processados são marcados e não reprocessados

---

## FLUXO 5 — Atendimento via Chat / WhatsApp

| # | Etapa | Verificar |
|---|-------|-----------|
| ☐ | 5.1 | Mensagem recebida via WhatsApp (integração Evolution API) |
| ☐ | 5.2 | Sistema registra e identifica o contato automaticamente |
| ☐ | 5.3 | Aparece na lista de conversas com indicador de não lida |
| ☐ | 5.4 | Operador aceita a conversa |
| ☐ | 5.5 | Contato já cadastrado? **SIM** → vincula ao paciente existente |
| ☐ | 5.6 | Contato novo? → opção de iniciar cadastro de paciente |
| ☐ | 5.7 | Ações: Responder mensagens (texto, mídia, documentos) |
| ☐ | 5.8 | Ações: Transcrever áudios recebidos |
| ☐ | 5.9 | Ações: Transferir conversa para outro operador |
| ☐ | 5.10 | Ações: Vincular conversa a uma demanda |
| ☐ | 5.11 | Ações: Selecionar profissional para atendimento |
| ☐ | 5.12 | Ações: Confirmar admissão |
| ☐ | 5.13 | Ações: Finalizar atendimento |

**Ciclo de Status do Chat:**
```
Novo → Em atendimento ⇄ Transferido → Finalizado
```

**⚠️ Ressalvas:**
- **SLA de chat:** conversa sem resposta gera pendência automática (timeout configurável)
- Suporte a múltiplas instâncias de WhatsApp simultâneas
- Histórico completo da conversa fica registrado no sistema

---

## FLUXO 6 — Seleção de Profissional e Proposta

| # | Etapa | Verificar |
|---|-------|-----------|
| ☐ | 6.1 | Operador inicia seleção no Chat |
| ☐ | 6.2 | Seleciona: demanda, paciente, profissional e especialidade |
| ☐ | 6.3 | Define detalhes: Data início, Frequência, Qtd. sessões, Duração, Valores |
| ☐ | 6.4 | Sistema verifica valor mínimo por especialidade |
| ☐ | 6.5 | Valor OK → cria solicitação de autorização junto à operadora |
| ☐ | 6.6 | E-mail automático enviado à operadora com a proposta |

**⚠️ Ressalvas:**
- **Valor abaixo do mínimo:** cria solicitação de autorização para Admin aprovar
- Valores mínimos por especialidade são configuráveis
- O campo "valor acordado" (profissional) e "valor autorizado" (operadora) são independentes
- Lucro = valor autorizado – valor acordado

---

## FLUXO 7 — Autorização da Operadora

| # | Etapa | Verificar |
|---|-------|-----------|
| ☐ | 7.1 | Proposta enviada → Status: **Aguardando Autorização** |
| ☐ | 7.2 | Sistema monitora e-mail (CRON periódico) |
| ☐ | 7.3 | Resposta recebida dentro do prazo? |
| ☐ | 7.4 | **SIM** → IA analisa conteúdo da resposta |
| ☐ | 7.5 | **Prazo esgotado** → IA tenta extrair decisão dos e-mails recentes |
| ☐ | 7.6 | Resultado: **Aprovada** → Vínculo paciente-profissional criado |
| ☐ | 7.7 | Resultado: **Aprovada** → Demanda status → **Admitido** |
| ☐ | 7.8 | Resultado: **Negada** → Demanda → **Autorização Negada** |

**⚠️ Ressalvas:**
- A IA interpreta automaticamente se a resposta é positiva ou negativa
- Modelo e prompt configuráveis pelo Admin

---

## FLUXO 8 — Pré-Admissão e Admissão do Paciente

| # | Etapa | Verificar |
|---|-------|-----------|
| ☐ | 8.1 | Autorização aprovada pela operadora |
| ☐ | 8.2 | Sistema cria vínculo paciente ↔ profissional |
| ☐ | 8.3 | Dados do vínculo: Especialidade, Serviço, Valor/sessão, Qtd. sessões, Frequência |
| ☐ | 8.4 | Tela de pré-admissão: revisão dos dados antes de confirmar |
| ☐ | 8.5 | Administrador aprova a admissão |
| ☐ | 8.6 | Status: **Admitido** → Pronto para faturamento |

**⚠️ Ressalvas:**
- Notificação automática de admissão (WhatsApp e e-mail)
- **⛔ NÃO envia notificação se o paciente tem status:** Óbito, Falecido(a), Alta, Alta Definitiva, Internado(a), Internação, Inativo(a), Deceased
- **⛔ NÃO envia notificação se o paciente foi excluído** (soft delete)
- **⛔ NÃO envia notificação se o paciente não tem profissional atribuído ativo**
- **⛔ NÃO envia notificação se o profissional está inativo** (status ≠ 'active')

---

## FLUXO 9 — Agendamentos e Ciclo de Sessões

| # | Etapa | Verificar |
|---|-------|-----------|
| ☐ | 9.1 | Agendamento criado: Paciente, Profissional, Data/hora, Recorrência, Valor |
| ☐ | 9.2 | Modalidades: Único, Semanal, Mensal, Personalizado |
| ☐ | 9.3 | Valor acima do mínimo da especialidade? |
| ☐ | 9.4 | **SIM** → Agendamento criado direto com status **Pendente Formulário** |
| ☐ | 9.5 | **NÃO** (abaixo do mínimo) → Solicitação de autorização de valor (Admin aprova) |
| ☐ | 9.6 | Admin aprova → agendamento criado |
| ☐ | 9.7 | Ao criar agendamento: Conta a Receber gerada automaticamente (vencimento: 30 dias) |
| ☐ | 9.8 | Ao criar agendamento: Pendência de formulário criada para o profissional (prazo 48h) |
| ☐ | 9.9 | Demanda vinculada atualiza para status "Admitido" |

**Ciclo de Status do Agendamento:**
```
Pendente Formulário → Realizado → Ciclo Encerrado → Renovação de Ciclo
                    ↘ Atrasado (48h sem formulário) → Revisão Admin (7 dias)
                    ↘ Cancelado
```

**⚠️ Ressalvas:**
- **⛔ BLOQUEIO:** Profissional com documento obrigatório vencido NÃO pode ter novos agendamentos criados
- Transição automática (CRON): `pendente_formulario` → `atrasado` após 48h
- Transição automática (CRON): `atrasado` → `revisao_admin` após 7 dias sem ação
- Na revisão admin, cria pendência automática para intervenção administrativa

---

## FLUXO 10 — Documentação do Profissional (Relatórios/Formulário)

| # | Etapa | Verificar |
|---|-------|-----------|
| ☐ | 10.1 | Profissional cria documentação → Status: **Rascunho** |
| ☐ | 10.2 | Preenche: Paciente, Qtd. sessões, Anotações, Documentos anexos |
| ☐ | 10.3 | Profissional envia para revisão → Status: **Enviado** |
| ☐ | 10.4 | Pendência criada para equipe administrativa |
| ☐ | 10.5 | Revisor analisa |
| ☐ | 10.6 | **Aprovar** → Documentação aprovada, registros no prontuário |
| ☐ | 10.7 | **Rejeitar** → Devolvido ao profissional com observações para correção |

**⚠️ Ressalvas — SLA de Documentação:**
- Prazo padrão: **48 horas** para envio
- **Antes do prazo:** lembrete configurável (padrão: 1 dia antes) via WhatsApp + E-mail
- **Após o prazo:** cobrança diária via WhatsApp + E-mail por até **7 dias consecutivos**
- Máximo de **1 cobrança por dia** (controle por data do último envio)
- **Após 7 dias sem ação:** pendência de revisão admin criada automaticamente
- O lembrete/cobrança é configurável: `professional.docs_reminder_days_before_due`

---

## FLUXO 11 — Faturamento e Aprovação Financeira

| # | Etapa | Verificar |
|---|-------|-----------|
| ☐ | 11.1 | Atendimento com status **"Admitido"** e pronto para faturar |
| ☐ | 11.2 | **ETAPA 1:** Aguardando Documentos — profissional envia docs obrigatórios |
| ☐ | 11.3 | Todos os documentos enviados e revisados? |
| ☐ | 11.4 | **NÃO** → permanece aguardando documentos |
| ☐ | 11.5 | **SIM** → **ETAPA 2:** Aguardando Aprovação Financeira |
| ☐ | 11.6 | Financeiro analisa: Receita, Despesa, Margem |
| ☐ | 11.7 | **ETAPA 3:** Aprovado → Fatura criada (billing_invoices) |
| ☐ | 11.8 | Lançamentos gerados: Receita (income) + Despesa (expense) |
| ☐ | 11.9 | Registro automático no prontuário com valores |
| ☐ | 11.10 | **ETAPA 4:** Concluído → Pagamento registrado → Atendimento encerrado |

**⚠️ Ressalvas — Cálculo Financeiro:**
- **Receita total** = valor autorizado × quantidade de sessões
- **Custo total** = valor acordado × quantidade de sessões
- **Lucro** = Receita − Custo
- Status do assignment passa para 'approved' após aprovação financeira

---

## FLUXO 12 — Financeiro: Contas a Pagar e Receber

| # | Etapa | Verificar |
|---|-------|-----------|
| ☐ | 12.1 | **A Receber** (da operadora): Status Pendente |
| ☐ | 12.2 | A Receber: Pendente → Recebido (paid) |
| ☐ | 12.3 | A Receber: Pode marcar como Inadimplente (cancelled) |
| ☐ | 12.4 | **A Pagar** (ao profissional): Status Pendente |
| ☐ | 12.5 | A Pagar: Pendente → Pago |
| ☐ | 12.6 | Data de pagamento registrada automaticamente ao marcar como "Pago" |
| ☐ | 12.7 | Dashboard Financeiro: total a receber e total a pagar em tempo real |
| ☐ | 12.8 | Dashboard: Margem e lucro por período |
| ☐ | 12.9 | Dashboard: Filtros por período, profissional, especialidade |
| ☐ | 12.10 | Centro de custos configurável |

---

## FLUXO 13 — Gestão de Pacientes

| # | Etapa | Verificar |
|---|-------|-----------|
| ☐ | 13.1 | Cadastro completo em 12 abas |
| ☐ | 13.2 | Aba Identificação: nome, CPF, data nascimento, sexo |
| ☐ | 13.3 | Aba Contato: telefones, WhatsApp, e-mail |
| ☐ | 13.4 | Aba Endereço: completo com CEP |
| ☐ | 13.5 | Aba Emergência: contato de emergência |
| ☐ | 13.6 | Aba Convênio: operadora, número, validade |
| ☐ | 13.7 | Aba Saúde: dados clínicos, alergias, doenças crônicas, medicamentos |
| ☐ | 13.8 | Aba Histórico Médico |
| ☐ | 13.9 | Aba Documentos: uploads com versionamento |
| ☐ | 13.10 | Aba Financeiro |
| ☐ | 13.11 | Aba LGPD: consentimento, logs de acesso |
| ☐ | 13.12 | Aba Responsável Legal |
| ☐ | 13.13 | Aba Prontuário: histórico completo de atendimentos |
| ☐ | 13.14 | Vinculação paciente ↔ profissional(is) |
| ☐ | 13.15 | **Aba Administrativo:** campo Status (Ativo, Inativo, Óbito, Alta, Internado, etc.) |

**⚠️ Ressalvas — Pacientes:**
- **⛔ Paciente com status ÓBITO / FALECIDO / ALTA / INTERNADO / INATIVO:**
  - NÃO recebe notificações WhatsApp
  - NÃO recebe notificações por e-mail
  - NÃO recebe lembretes de agendamento
  - Jobs da fila de integração são marcados como "bloqueados" (não retentam)
- **⛔ Paciente sem profissional atribuído ativo:** notificações bloqueadas
- **⛔ Paciente excluído (soft delete):** notificações bloqueadas
- Profissional só visualiza dados de pacientes vinculados a ele
- Link único para cancelamento/confirmação pelo paciente
- Conformidade LGPD: logs de acesso ao prontuário, anonimização sob solicitação

---

## FLUXO 14 — Recursos Humanos (RH)

| # | Etapa | Verificar |
|---|-------|-----------|
| ☐ | 14.1 | Cadastro de funcionário: Dados pessoais, Cargo, Contrato |
| ☐ | 14.2 | Benefícios e Dependentes |
| ☐ | 14.3 | Documentos do funcionário |
| ☐ | 14.4 | Funcionário ativo → acesso ao sistema |
| ☐ | 14.5 | Contrato digital via ZapSign |
| ☐ | 14.6 | Folha de pagamento |
| ☐ | 14.7 | Gestão de benefícios |
| ☐ | 14.8 | Histórico funcional |
| ☐ | 14.9 | Conta de usuário vinculada |
| ☐ | 14.10 | Conta pode ser suspensa ou reativada |

---

## FLUXO 15 — Contratos Digitais (ZapSign)

| # | Etapa | Verificar |
|---|-------|-----------|
| ☐ | 15.1 | RH seleciona funcionário e modelo de contrato |
| ☐ | 15.2 | Sistema gera documento via ZapSign (variáveis preenchidas automaticamente) |
| ☐ | 15.3 | Contrato enviado ao signatário via E-mail ou WhatsApp |
| ☐ | 15.4 | Signatário assina digitalmente |
| ☐ | 15.5 | Webhook notifica o sistema → Status atualizado automaticamente |
| ☐ | 15.6 | **Assinado** → Contrato ativo |
| ☐ | 15.7 | **Recusado** → Contrato recusado |

---

## FLUXO 16 — Documentos e Validade (Profissional)

| # | Etapa | Verificar |
|---|-------|-----------|
| ☐ | 16.1 | Documento enviado pelo profissional: CRM, Alvará, Certidões, etc. |
| ☐ | 16.2 | Possui data de validade? |
| ☐ | 16.3 | **SIM** → monitoramento automático diário (CRON) |
| ☐ | 16.4 | **NÃO** → documento arquivado (sem monitoramento) |
| ☐ | 16.5 | 30 dias antes do vencimento → notificação WhatsApp + E-mail ao profissional |
| ☐ | 16.6 | 30 dias antes → pendência criada para Admin |
| ☐ | 16.7 | Documento vencido → pendência "DOCUMENTO VENCIDO" para Admin |
| ☐ | 16.8 | Documento vencido → **⛔ Profissional BLOQUEADO para novos agendamentos** |

**⚠️ Ressalvas:**
- Categorias de documentos obrigatórios são configuráveis: `professional.required_doc_categories`
- Prazo de aviso antecipado configurável: `professional.docs_expiry_notice_days` (padrão: 30 dias)
- Notificação de vencimento só é reenviada a cada 7 dias (evita spam)
- Versionamento: documentos atualizados não sobrescrevem, criam nova versão (v1, v2, v3...)
- Nomenclatura automática padronizada nos uploads

---

## FLUXO 17 — Notificações e Eventos Automáticos

| # | Etapa | Verificar |
|---|-------|-----------|
| ☐ | 17.1 | **Gatilhos de notificação configurados:** |
| ☐ | 17.2 | → Demanda muda de status |
| ☐ | 17.3 | → Agendamento criado ou alterado |
| ☐ | 17.4 | → Documento enviado ou aprovado |
| ☐ | 17.5 | → Faturamento aprovado |
| ☐ | 17.6 | → Chat sem resposta (SLA) |
| ☐ | 17.7 | → Demanda não assumida (timeout) |
| ☐ | 17.8 | → Documento perto de vencer |
| ☐ | 17.9 | → Contrato assinado ou recusado |
| ☐ | 17.10 | **Canais de envio:** 🔔 Sino interno, 📧 E-mail, 💬 WhatsApp |
| ☐ | 17.11 | Templates de WhatsApp configuráveis com variáveis dinâmicas |
| ☐ | 17.12 | Templates de E-mail configuráveis com variáveis dinâmicas |
| ☐ | 17.13 | Suporte a anexos nos templates |
| ☐ | 17.14 | Log detalhado de todos os envios |

**⚠️ Ressalvas — Regras de Bloqueio de Notificação:**

| Condição do Paciente | Resultado |
|---------------------|-----------|
| Status: Óbito / Falecido(a) | ⛔ **TODAS** as notificações bloqueadas |
| Status: Alta / Alta Definitiva | ⛔ **TODAS** as notificações bloqueadas |
| Status: Internado(a) / Internação | ⛔ **TODAS** as notificações bloqueadas |
| Status: Inativo(a) | ⛔ **TODAS** as notificações bloqueadas |
| Paciente excluído (soft delete) | ⛔ **TODAS** as notificações bloqueadas |
| Sem profissional atribuído ativo | ⛔ **TODAS** as notificações bloqueadas |
| Profissional inativo (status ≠ active) | ⛔ Notificações para o profissional bloqueadas |

**Comportamento:** Jobs na fila de integração que atingem bloqueio são marcados como "success" (não ficam retentando).

---

## FLUXO 18 — Administração e Permissões

| # | Etapa | Verificar |
|---|-------|-----------|
| ☐ | 18.1 | **Papéis (Roles):** Admin, Profissional, Operador, Financeiro |
| ☐ | 18.2 | Permissões granulares por funcionalidade |
| ☐ | 18.3 | admin.dashboard, demands.manage, appointments.manage |
| ☐ | 18.4 | finance.manage, patients.manage, hr.manage |
| ☐ | 18.5 | professional_docs.submit, professional_docs.review |
| ☐ | 18.6 | Usuário sem permissão vê tela de "Acesso Negado" |
| ☐ | 18.7 | **Gestão de Usuários:** criar, editar, excluir |
| ☐ | 18.8 | Suspender e reativar contas |
| ☐ | 18.9 | Login como outro usuário (suporte) |
| ☐ | 18.10 | **Configurações do Sistema:** |
| ☐ | 18.11 | → Integrações: WhatsApp (Evolution API), SMTP, OpenAI, ZapSign |
| ☐ | 18.12 | → Templates de e-mail e WhatsApp |
| ☐ | 18.13 | → Especialidades e tipos de serviço |
| ☐ | 18.14 | → Operadoras de saúde |
| ☐ | 18.15 | → Centros de custo |
| ☐ | 18.16 | → Logotipo e tempo de sessão |
| ☐ | 18.17 | → Valores mínimos por especialidade |
| ☐ | 18.18 | → Categorias de documentos obrigatórios |

**Processos Automáticos (CRON):**

| # | Processo | Frequência |
|---|----------|-----------|
| ☐ | Leitura de e-mails (IMAP) | Periódico |
| ☐ | Extração de demandas por IA | Periódico |
| ☐ | Processamento de autorizações | Periódico |
| ☐ | Transição de status de agendamentos (48h/7 dias) | Periódico |
| ☐ | Timeout de demandas não assumidas (4h padrão) | Periódico |
| ☐ | Renovação de ciclos de agendamento | Periódico |
| ☐ | SLA de chat sem resposta | Periódico |
| ☐ | SLA de documentação profissional (cobranças) | Diário |
| ☐ | Monitoramento de validade documental | Diário |
| ☐ | Fila de integrações (envio WhatsApp/E-mail) | Periódico |

---

## 📊 RESUMO — Fluxo Completo de Ponta a Ponta

```
📧 E-mail chega                           💬 WhatsApp (mensagem)
     │                                          │
     ▼                                          ▼
🤖 IA extrai dados                     💬 Chat centralizado
     │                                          │
     ▼                                          │
📋 Card de Demanda criado  ◄────────────────────┘
     │
     ▼
🎯 Captador assume (timeout 4h)
     │
     ▼
💬 Disparo WhatsApp (grupos por especialidade + cidade)
     │
     ▼
👤 Profissional responde
     │
     ▼
📋 Seleção: define valores, frequência, sessões
     │
     ▼
📧 Proposta enviada à Operadora
     │
     ▼
✅ Autorização aprovada (IA interpreta resposta)
     │
     ▼
🏥 Admissão: vínculo paciente ↔ profissional
     │
     ▼
📅 Agendamento criado (status: Pendente Formulário)
     │
     ├──→ ⏱ Profissional tem 48h para enviar formulário
     │         │
     │         ├── Enviou → Revisão Admin → Aprovado → Prontuário
     │         └── Não enviou → Cobranças (7 dias) → Revisão Admin
     │
     ▼
💰 Faturamento
     │
     ├── Documentos OK → Aprovação Financeira
     │         │
     │         ▼
     │    💵 Fatura + Lançamentos (Receita + Despesa)
     │         │
     │         ▼
     │    💳 Pagamento registrado
     │
     ▼
✔ Concluído
```

---

## 🔒 REGRAS DE SEGURANÇA E COMPLIANCE

| # | Regra | Verificar |
|---|-------|-----------|
| ☐ | Toda ação gera registro em audit_logs (valor anterior + novo) |
| ☐ | Profissionais só veem pacientes vinculados a eles |
| ☐ | Financeiro não acessa prontuários / dados clínicos |
| ☐ | LGPD: consentimento digital versionado |
| ☐ | LGPD: logs de acesso ao prontuário (quem, quando, IP) |
| ☐ | LGPD: anonimização de dados sob solicitação |
| ☐ | Senhas com hash bcrypt |
| ☐ | Sessões com expiração configurável |
| ☐ | Soft delete (exclusão lógica) para manter rastreabilidade |
| ☐ | Retenção mínima de 20 anos (CFM 2314/2022) |
| ☐ | Documentos com versionamento (sem sobrescrita) |

---

## ⚙️ PARÂMETROS CONFIGURÁVEIS PELO ADMIN

| Parâmetro | Onde Configurar | Padrão |
|-----------|----------------|--------|
| Tempo de expiração da sessão | Admin > Configurações | Configurável |
| Timeout de demanda não assumida | Admin > Configurações | 4 horas |
| SLA de documentação profissional | Admin > Configurações | 48 horas |
| Dias de aviso antes do vencimento documental | Admin > Configurações | 30 dias |
| Lembrete antes do prazo de formulário | Admin > Configurações | 1 dia |
| Categorias de docs obrigatórios | Admin > Configurações | Configurável |
| Valores mínimos por especialidade | Admin > Configurações | Por especialidade |
| Modelo de IA (OpenAI) | Admin > Configurações | GPT-4 |
| Prompt de extração de e-mail | Admin > Configurações | Editável |
| Templates WhatsApp | Admin > Templates | Editáveis |
| Templates E-mail | Admin > Templates | Editáveis |
| Instâncias WhatsApp | Admin > Integrações | Múltiplas |

---

*Checklist gerado em Junho/2026 — Para uso na apresentação à cliente dona do software.*

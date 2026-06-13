# MultiLife Care — Documentação Completa do Software

## 📋 Índice

1. [Visão Geral](#1-visão-geral)
2. [Problema que Resolve](#2-problema-que-resolve)
3. [Público-Alvo](#3-público-alvo)
4. [Módulos e Funcionalidades](#4-módulos-e-funcionalidades)
5. [Integrações](#5-integrações)
6. [Arquitetura Técnica](#6-arquitetura-técnica)
7. [Modelo de Acesso (RBAC)](#7-modelo-de-acesso-rbac)
8. [Diferenciais Competitivos](#8-diferenciais-competitivos)
9. [Concorrentes](#9-concorrentes)
10. [Conformidade e Segurança](#10-conformidade-e-segurança)
11. [Roadmap Tecnológico](#11-roadmap-tecnológico)

---

## 1. Visão Geral

**MultiLife Care** é uma plataforma web de **gestão de saúde domiciliar (home care)** desenvolvida para centralizar, automatizar e rastrear todo o ciclo operacional de uma empresa de atendimento domiciliar — desde o recebimento da demanda até o faturamento e repasse financeiro ao profissional.

O sistema foi projetado para o mercado brasileiro, respeitando regulamentações do CFM, CFN, COREN e a Lei Geral de Proteção de Dados (LGPD).

### Resumo do Ciclo Operacional

```
E-mail recebido → IA extrai dados → Card de demanda criado →
Captador assume → Disparo em grupos WhatsApp por especialidade/cidade →
Profissional responde → Confirmação via chat → Agendamento gerado →
Paciente notificado → Atendimento realizado → Formulário documentado →
Prontuário atualizado → Faturamento verificado → Repasse processado
```

---

## 2. Problema que Resolve

Empresas de home care enfrentam desafios operacionais significativos:

| Problema | Como o MultiLife Care resolve |
|----------|-------------------------------|
| Demandas recebidas por e-mail sem padronização | IA (OpenAI) extrai e estrutura dados automaticamente |
| Captação manual de profissionais em dezenas de grupos WhatsApp | Disparo automatizado por especialidade, cidade e estado via Evolution API |
| Comunicação fragmentada entre WhatsApp pessoal e empresa | Chat centralizado na plataforma com histórico completo |
| Falta de controle sobre prazos de documentação | SLA automatizado com cobranças via WhatsApp e e-mail |
| Profissionais com registros vencidos atendendo pacientes | Validação automática em conselhos (CRM, COREN, CRP, etc.) com bloqueio |
| Faturamento manual e propenso a erros | Controle financeiro integrado com contas a receber/pagar |
| Documentos espalhados em drives e pastas locais | Gestão documental centralizada com versionamento e nomenclatura padrão |
| Falta de rastreabilidade e auditoria | Log completo de todas as ações com valor anterior/novo |
| Conformidade LGPD não implementada | Termos digitais, acesso segmentado, criptografia e retenção legal |

---

## 3. Público-Alvo

### 3.1 Público Primário — Empresas de Home Care

- Empresas de atendimento domiciliar (enfermagem, fisioterapia, nutrição, psicologia, fonoaudiologia, terapia ocupacional)
- Cooperativas de profissionais de saúde
- Empresas de cuidadores e técnicos de enfermagem
- Agências de recrutamento e alocação de profissionais de saúde

### 3.2 Público Secundário — Operadoras e Convênios

- Operadoras de planos de saúde que terceirizam serviços domiciliares
- Hospitais com programas de desospitalização
- Clínicas com braço de atendimento domiciliar

### 3.3 Perfil Geográfico

- **Brasil** — O sistema é totalmente em português brasileiro
- Validação de conselhos brasileiros (CRM, COREN, CRP, CRN, CREFITO, CRO, CREA, OAB)
- Integração com WhatsApp (predominante no mercado brasileiro)
- Conformidade com LGPD e resoluções do CFM

### 3.4 Porte das Empresas

| Porte | Características |
|-------|----------------|
| Pequeno (5-20 profissionais) | Precisa de organização básica de demandas e agendamentos |
| Médio (20-100 profissionais) | Necessita automação de captação e controle financeiro |
| Grande (100+ profissionais) | Requer escalabilidade, múltiplos captadores, relatórios avançados |

### 3.5 Usuários Finais

| Perfil | Quantidade típica | Uso principal |
|--------|-------------------|---------------|
| Admin / Gestores | 1-5 | Configuração, aprovações, visão geral |
| Captadores / Admissão | 2-10 | Gestão diária de demandas e admissões |
| Financeiro | 1-3 | Faturamento e repasses |
| TI | 1-2 | Monitoramento técnico |
| Profissionais de Saúde | 20-500+ | Autoatendimento (documentação, perfil, pacientes) |

---

## 4. Módulos e Funcionalidades

### 4.1 Captação de Demandas (Módulo 3)

- Recebimento automático de e-mails via IMAP
- Extração inteligente com OpenAI (título, localização, especialidade, descrição, origem)
- Cards com status: Aguardando Captação → Em Captação → Admitido → Concluído
- Timeout configurável para captadores assumirem demandas
- Disparo em grupos WhatsApp filtrados por especialidade + cidade + estado
- Suporte a sub-solicitações (desmame, substituição)
- Campos de urgência e frequência de atendimento

### 4.2 Chat Integrado (Módulo 4)

- Interface inspirada no WhatsApp Web
- Lista de conversas com indicador de não lidas
- Identificação automática de contatos (profissional ou paciente)
- Transferência de conversas entre atendentes
- Timeout para conversas sem resposta (gera pendência)
- Suporte a mídia (imagens, documentos, áudio)
- Gerenciamento de status: Atendendo, Aguardando, Finalizado
- Integração bidirecional com Evolution API

### 4.3 Candidatura de Profissionais (Módulo 5)

- Link público de candidatura
- Ficha completa: identificação, endereço, contato, documentos, dados bancários, experiência
- Workflow de aprovação: Pendente → Aprovado / Reprovado / Complemento
- Onboarding automático (criação de conta, envio de credenciais via WhatsApp e e-mail)
- Validação de registros profissionais em conselhos (CRM, COREN, CRP, CRN, CREFITO, CRO, CREA, OAB)
- Monitoramento de validade documental com notificação 30 dias antes

### 4.4 Documentação do Profissional (Módulo 6)

- Formulário pós-atendimento com SLA de 48h
- Upload de documentos de faturamento e produtividade
- Cobranças automáticas via WhatsApp (até 7 dias consecutivos)
- Criação de pendência para Admin após 7 dias sem ação
- Registro automático no prontuário do paciente

### 4.5 Pacientes e Prontuário (Módulo 7)

- Cadastro completo em 12 abas (identificação, contato, endereço, emergência, convênio, saúde, histórico médico, documentos, financeiro, LGPD, responsável, prontuário)
- Prontuário digital com histórico completo de atendimentos
- Vinculação paciente-profissional (múltiplos profissionais por paciente)
- Link único para cancelamento/confirmação pelo paciente
- Dados biométricos, alergias, doenças crônicas, medicamentos
- Segmentação de acesso (profissional vê apenas pacientes vinculados)

### 4.6 Agendamentos (Módulo 8)

- Modalidades: Único, Recorrente Semanal, Recorrente Mensal, Personalizado
- Ciclo de vida: Agendado → Pendente Formulário → Realizado / Atrasado / Cancelado / Revisão Admin
- Renovação e encerramento de ciclos recorrentes
- Valor por sessão definido pelo captador (com validação de mínimo por especialidade)
- Sistema de autorização para valores abaixo do mínimo
- Agendamento por dias da semana

### 4.7 Financeiro (Módulo 9)

- Contas a Receber (pacientes/convênios)
- Contas a Pagar (repasses a profissionais)
- Ciclo de repasse configurável (quinzenal, mensal)
- Valores mínimos por especialidade
- Sistema de autorizações para exceções
- Controle de parcelas
- Indicadores: Faturamento, Custo, Margem Operacional, Inadimplência
- Centro de custos configurável

### 4.8 Gestão Documental (Módulo 10)

- Estrutura hierárquica (Pacientes / Profissionais / Empresa)
- Nomenclatura automática padronizada
- Versionamento (v1, v2, v3...)
- Controle de validade com alertas automáticos
- Categorização (Faturamento, Produtividade, Exames, Contratos, etc.)

### 4.9 Administrativo e RH (Módulo 11)

- Dashboard operacional com KPIs
- Gestão de funcionários internos
- Contratos digitais com assinatura via ZapSign
- Gestão de grupos WhatsApp por especialidade/cidade
- Painel de configurações com 100+ parâmetros
- Templates de mensagens WhatsApp e e-mail

### 4.10 Automações (Eventos)

- Sistema de eventos WhatsApp (disparo automático por condição)
- Sistema de eventos E-mail (disparo automático por condição)
- Templates com placeholders dinâmicos
- Suporte a anexos nos templates
- Log detalhado de envios

### 4.11 Validação de Conselhos Profissionais

- Validação automática de registros em conselhos brasileiros
- Arquitetura multi-provedor com fallback automático (Consultar.IO → Infosimples → Portal Direto)
- Cache de 24h para reduzir custos
- Logs detalhados de cada consulta
- Dashboard de estatísticas por provedor e por conselho
- 8 conselhos suportados: CRM, COREN, CRP, CRN, CREFITO, CRO, CREA, OAB

---

## 5. Integrações

### 5.1 Evolution API (WhatsApp)

| Caso de Uso | Descrição |
|-------------|-----------|
| Captação em grupos | Disparo automático por especialidade + cidade + estado |
| Chat privado | Interface completa de atendimento via WhatsApp |
| Onboarding | Envio de credenciais ao profissional aprovado |
| Notificação ao paciente | Dados do profissional + horário do atendimento |
| Lembrete de formulário | Cobrança antes e depois do prazo |
| Cancelamento | Notificação quando paciente cancela via link |
| Múltiplas instâncias | Suporte a várias instâncias de WhatsApp simultâneas |

### 5.2 OpenAI (ChatGPT)

- Extração estruturada de dados de e-mails de demanda
- Prompt configurável pelo Admin
- Modelo configurável (GPT-4, GPT-3.5, etc.)
- Console de teste integrado ao painel admin

### 5.3 ZapSign

- Criação de documentos para assinatura digital
- Envio automático de contratos RH
- Monitoramento de status de assinatura
- Webhooks para atualização em tempo real

### 5.4 SMTP/IMAP

- Recebimento de e-mails (polling IMAP configurável)
- Envio de e-mails transacionais
- Templates personalizáveis com placeholders
- Arquivamento automático de e-mails processados

### 5.5 APIs de Conselhos Profissionais

- Consultar.IO (CRM, CRO — API paga, ~R$ 0,20/consulta)
- Infosimples (CRM, CRP, CRO, COREN — API paga)
- Portal Direto (scraping como fallback)

---

## 6. Arquitetura Técnica

### Stack Tecnológica

| Camada | Tecnologia |
|--------|-----------|
| Backend | PHP 8.x (sem framework, MVC simples) |
| Frontend | JavaScript vanilla + CSS custom (design system próprio) |
| Banco de Dados | MySQL 8.x (InnoDB, utf8mb4) |
| Autenticação | Sessões PHP + bcrypt + RBAC |
| APIs | REST (consumo e exposição) |
| Fila de Jobs | Tabela MySQL + CRON |
| Timezone | America/Sao_Paulo |

### Estrutura de Diretórios

```
multilife/
├── app/                    # Core da aplicação (bootstrap, auth, services)
│   ├── council_providers/  # Provedores de validação de conselhos
│   ├── bootstrap.php       # Inicialização e includes
│   ├── auth.php            # Autenticação
│   ├── rbac.php            # Controle de acesso
│   ├── db.php              # Conexão com banco
│   ├── evolution_api_v1.php # Integração WhatsApp
│   ├── openai_api.php      # Integração OpenAI
│   ├── zapsign_api.php     # Integração ZapSign
│   └── smtp_client.php     # Envio de e-mails
├── api/                    # Endpoints AJAX/REST
├── config/                 # Configuração do sistema
├── docs/                   # Documentação técnica
├── migrations/             # Migrations SQL (90+ arquivos)
├── uploads/                # Arquivos enviados
├── admin_*.php             # Páginas administrativas
├── chat_*.php              # Páginas do chat
├── demands_*.php           # Páginas de demandas
├── patients_*.php          # Páginas de pacientes
├── appointments_*.php      # Páginas de agendamentos
├── finance_*.php           # Páginas financeiras
└── professional_*.php      # Páginas de profissionais
```

### Banco de Dados

- **90+ migrations** cobrindo todos os módulos
- Tabelas principais: `users`, `demands`, `patients`, `appointments`, `professional_applications`, `finance_accounts_receivable`, `finance_accounts_payable`, `chat_messages`, `audit_logs`
- Controle de acesso granular via `roles`, `permissions`, `role_permissions`
- Log de auditoria com valores antigos e novos (JSON)

---

## 7. Modelo de Acesso (RBAC)

| Perfil | Módulos Acessíveis | Restrições |
|--------|-------------------|------------|
| **Admin** | Todos | Nenhuma |
| **Financeiro** | Financeiro, Relatórios | Sem acesso a dados clínicos |
| **Captador / Admissão** | Demandas, Chat, Pacientes, Agendamentos | Sem acesso a financeiro completo |
| **TI** | Logs técnicos, Integrações, Monitoramento | Sem acesso a dados clínicos/financeiros |
| **Profissional** | Próprio perfil, Pacientes vinculados, Formulário | Sem acesso a outros profissionais |

### Princípios de Segurança

- Profissionais não visualizam dados de outros profissionais
- Financeiro não acessa prontuários ou dados clínicos
- Toda ação gera registro em `audit_logs`
- Sessões expiram após período configurável
- Senhas com hash bcrypt
- Tokens de sessão com controle de last_activity

---

## 8. Diferenciais Competitivos

| Diferencial | Descrição |
|-------------|-----------|
| **IA na captação** | Único no mercado de home care com extração automática de demandas via ChatGPT |
| **WhatsApp nativo** | Chat integrado + disparo em grupos + automações via Evolution API |
| **Validação de conselhos** | Verificação automática de registros profissionais com multi-provedor e fallback |
| **Ciclo completo** | Da demanda ao faturamento em uma única plataforma |
| **Automação de SLA** | Cobranças automáticas com escalonamento progressivo |
| **LGPD nativo** | Consentimento digital, anonimização, acesso segmentado, logs de acesso ao prontuário |
| **Sem dependência de framework pesado** | PHP puro permite customização rápida e deploy simples |
| **Multi-instância WhatsApp** | Suporte a múltiplas linhas de WhatsApp simultâneas |
| **Custo operacional baixo** | Stack leve (PHP + MySQL) sem licenciamento de terceiros obrigatório |

---

## 9. Concorrentes

### 9.1 Concorrentes Diretos — Softwares de Gestão Home Care (Brasil)

| Concorrente | Descrição | Pontos Fortes | Limitações vs MultiLife |
|-------------|-----------|---------------|------------------------|
| **Nível Saúde** | Plataforma de gestão para home care e ILPI | Interface moderna, aplicativo mobile, agendamento | Sem IA para captação, sem WhatsApp integrado |
| **HomeCare.AI** | Software de gestão para empresas de atenção domiciliar | Foco em BI e relatórios, mobile | Sem captação automatizada via grupos WhatsApp |
| **SisHOSP** | Sistema de gestão hospitalar com módulo home care | Amplo, estabelecido no mercado | Generalista, interface legada, sem IA |
| **SGHC (MV)** | Módulo home care da MV Informática | Grande escala, integração hospitalar | Caro, complexo, sem WhatsApp nativo |
| **Carefy** | Plataforma de auditoria e gestão de internação domiciliar | Foco em operadoras, auditoria | Voltado para operadoras, não para prestadores |
| **SpinCare** | Gestão de home care com foco em enfermagem | Específico para enfermagem, simples | Escopo limitado a uma especialidade |
| **Previva** | Plataforma de coordenação de cuidados | Foco em atenção primária e crônicos | Não é específico para home care operacional |
| **Prontmed** | Prontuário eletrônico com módulo domiciliar | Prontuário robusto, certificação SBIS | Foco clínico, sem gestão operacional/financeira |
| **iClinic / Feegow** | Prontuário e gestão de clínicas | Boa UX, marketplace, mobile | Voltados para clínicas, não para operação home care |

### 9.2 Concorrentes Indiretos — Ferramentas de Produtividade Adaptadas

| Concorrente | Uso no Home Care | Limitação |
|-------------|-----------------|-----------|
| **Trello / Asana / Monday** | Kanban de demandas | Zero integração com saúde, sem prontuário, sem compliance |
| **HubSpot / Pipedrive** | CRM para gestão de leads | Sem fluxo clínico, sem validação de conselhos |
| **Google Workspace** | Planilhas + Drive + Gmail | Manual, sem automação, sem rastreabilidade |
| **WhatsApp Business** | Comunicação direta | Sem integração com sistema, sem histórico centralizado |
| **Conta Azul / Bling** | Financeiro/ERP | Sem fluxo de saúde, sem compliance LGPD saúde |

### 9.3 Concorrentes Internacionais (Referência)

| Concorrente | País | Descrição |
|-------------|------|-----------|
| **AlayaCare** | Canadá | Plataforma completa de home care, IA, mobile, billing |
| **Axxess** | EUA | Software de home health com scheduling e billing |
| **WellSky (Kinnser)** | EUA | EHR + gestão operacional para home health |
| **CareSmartz360** | EUA | Scheduling, billing, EVV, caregiver app |
| **Homecare Homebase** | EUA | EMR + point-of-care para enfermagem domiciliar |
| **Netsmart** | EUA | EHR para post-acute care e home health |

> **Nota:** Os concorrentes internacionais não atuam diretamente no Brasil e não possuem integração com WhatsApp, conselhos brasileiros ou conformidade LGPD.

### 9.4 Posicionamento do MultiLife Care

```
                    ┌─────────────────────────────────────────┐
                    │         ALTA AUTOMAÇÃO / IA              │
                    │                                         │
                    │         ★ MultiLife Care                 │
                    │                                         │
    OPERACIONAL ────┼─────────────────────────────────────────┼──── CLÍNICO
    (Captação,      │                                         │     (Prontuário,
     Financeiro,    │     AlayaCare    Nível Saúde            │      Prescrição,
     RH)            │                                         │      Evolução)
                    │  SisHOSP        Prontmed                │
                    │                                         │
                    │  Trello/CRM     iClinic/Feegow          │
                    │                                         │
                    │         BAIXA AUTOMAÇÃO / MANUAL         │
                    └─────────────────────────────────────────┘
```

O MultiLife Care se posiciona no **quadrante de alta automação com foco operacional**, sendo o único no mercado brasileiro que combina:
- IA para extração de demandas
- WhatsApp como canal principal de operação
- Validação automática de conselhos
- Ciclo financeiro integrado

---

## 10. Conformidade e Segurança

### LGPD (Lei 13.709/2018)

- Consentimento digital versionado com assinatura
- Classificação de dados sensíveis (saúde)
- Acesso segmentado por perfil (profissionais não acessam dados de outros)
- Log de acesso a prontuários individualizado
- Exclusão lógica (soft delete) para auditoria
- Retenção mínima de 20 anos conforme CFM 2314/2022
- Direito a anonimização implementado

### Segurança Técnica

- HTTPS obrigatório com certificado SSL
- Senhas com hash bcrypt (cost factor adequado)
- Sessões com expiração configurável
- Proteção contra session fixation
- Logs de auditoria com IP de origem
- Backups automáticos configuráveis (S3, Google Drive, FTP)
- Retenção configurável de backups (padrão: 30 dias)

### Regulamentações de Saúde

- Conformidade com resoluções do CFM para prontuários eletrônicos
- Registro de acesso a dados clínicos (quem, quando, IP)
- Imutabilidade do prontuário (alterações somente por Admin com log)
- Versionamento de documentos (não há sobrescrita)

---

## 11. Roadmap Tecnológico

Com base na análise do código e migrations, funcionalidades em evolução incluem:

| Feature | Status | Evidência |
|---------|--------|-----------|
| Sub-solicitações de demanda | Implementado | `demand_sub_requests` |
| Desmame e substituição de profissional | Implementado | `desmame_substituicao` |
| Agendamento por dias da semana | Implementado | `weekdays_scheduling` |
| Validação multi-provedor de conselhos | Implementado | `council_validation_providers` |
| Templates WhatsApp com anexos | Implementado | `whatsapp_template_attachments` |
| Templates de e-mail | Implementado | `email_templates` |
| Múltiplas instâncias WhatsApp | Implementado | `whatsapp_instances_tracking` |
| Sistema de autorizações de valor | Implementado | `authorization_requests` |
| Rastreamento de message_id | Implementado | `add_message_id_tracking` |

### Possíveis Evoluções Futuras

- Aplicativo mobile para profissionais (PWA ou nativo)
- Integração com operadoras de saúde (TISS/TUSS)
- Business Intelligence com dashboards avançados
- Telemonitoramento e IoT
- Integração com plataformas de telemedicina
- App para pacientes/familiares
- Integração com ERPs de convênios

---

## Resumo Executivo

O **MultiLife Care** é uma solução vertical completa para empresas de saúde domiciliar no Brasil. Combina automação por IA, comunicação via WhatsApp, gestão financeira, prontuário digital e conformidade regulatória em uma plataforma única e integrada. Seu principal diferencial é a automação do ciclo de captação (e-mail → IA → grupos WhatsApp → confirmação → agendamento) — um fluxo que concorrentes tratam de forma manual ou fragmentada entre múltiplas ferramentas.

---

*Documento gerado em Junho/2026 — Versão 1.0*

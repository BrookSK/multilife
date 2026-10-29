# MultiLife Care — Documentação técnica de entrega

Versão: 2026-10-27 · Público: equipe técnica e coordenação da operação.

Este documento descreve como o sistema funciona por dentro: os fluxos da operação, as regras automáticas, as rotinas em segundo plano, as configurações e o que verificar quando algo dá errado.

---

## 1. Visão geral

O MultiLife Care organiza o atendimento domiciliar do começo ao fim:

| Etapa | Tela | O que acontece |
|---|---|---|
| 1. Captação | Captação (`demands_list.php`) | O pedido chega por e-mail ou é cadastrado manualmente e vira um **card**. A captação envia o card para os grupos de WhatsApp dos profissionais. |
| 2. Chat ao vivo | Chat ao Vivo (`chat_web.php`) | Os profissionais que reagiram à mensagem aparecem na lista de interessados. O captador conversa, escolhe o profissional e envia a proposta. |
| 3. Autorização | Autorização (`authorization_list.php`) | A operadora/cliente aprova ou nega a proposta. |
| 4. Pré-admissão | Pré-admissão (`pre_admissao.php`) | Passagem de plantão: agenda, sessões, orientações da operadora, login de sistema externo. Ao aprovar, o atendimento começa. |
| 5. Monitoramento | Monitoramento (`monitoramento.php`) | Acompanhamento do atendimento: desmame, substituição de profissional, encerramento. |
| 6. Sessões e faturamento | Sessões / Faturamento / Fechamento Mensal | Conferência das sessões e fechamento por operadora e por profissional. |
| 7. Financeiro | Financeiro | Lançamentos, contas a pagar e a receber, notas fiscais e fechamento financeiro. |

---

## 2. Tecnologia e estrutura

- **Linguagem:** PHP 8 (sem framework), MySQL/MariaDB (PDO, `utf8mb4`).
- **WhatsApp:** Evolution API (`app/evolution_api_v1.php`), várias instâncias (números) em paralelo.
- **IA:** OpenAI (`app/openai_api.php`) para ler e-mails e preencher cards.
- **Hospedagem:** Plesk/Apache, rotas sem `.php` via `.htaccess`.

| Pasta / arquivo | Conteúdo |
|---|---|
| `*.php` (raiz) | Telas e ações (`*_post.php` são os envios de formulário). |
| `app/` | Regras de negócio compartilhadas. Tudo é carregado por `app/bootstrap.php`. |
| `cron/` | Rotinas em segundo plano (protegidas pelo token `cron.token`). |
| `migrations/` | Scripts SQL de banco, em ordem de data. |
| `config/config.php` | Banco de dados, sessão e chave de criptografia. |
| `docs/` | Documentação (este arquivo). |
| `storage/documents/` | Arquivos da Gestão Documental. |

Principais arquivos de `app/`:

| Arquivo | Responsabilidade |
|---|---|
| `auth.php`, `rbac.php` | Login, perfis e permissões. |
| `view.php` | Layout, menu lateral (filtrado por permissão), avisos globais. |
| `demand_form.php` | Formulário do card (novo e editar) e regra do "o que falta". |
| `location_zones.php` | Cidade/UF/bairro/zona, separação de endereço, compatibilidade de localização, especialidade canônica. |
| `dispatch_targets.php` | Quem entra em cada grupo de captação e qual grupo usar. |
| `demand_dispatch_batch.php` | Fila e acompanhamento do disparo da captação. |
| `dispatch_tracker_widget.php` | Quadro de progresso da captação (canto inferior direito). |
| `whatsapp_health.php` | Detecção de WhatsApp em loop/instável. |
| `whatsapp_instances.php` | Instâncias do WhatsApp e vínculo com usuários. |
| `insurer_orientations_helper.php` | Orientações por operadora e tabela de dias. |
| `secure_store.php` | Criptografia de dados sensíveis. |
| `zoho_billing.php` | Avisos de faturamento vindos do Zoho. |

---

## 3. Perfis e permissões

As permissões são verificadas em cada tela (`rbac_require_permission`). O menu lateral só mostra o que o usuário pode acessar.

| Permissão | Libera |
|---|---|
| `demands.manage` | Captação, Autorização, Pré-admissão, Monitoramento. |
| `chat.manage` | Chat ao Vivo e Contatos. |
| `cadastros.manage` | Especialidades, Tipos de Serviço, Operadoras, Clientes, Orientações. |
| `users.manage` | Usuários e Profissionais. |
| `roles.manage` | Criar/editar perfis e atribuir qualquer perfil. |
| `patients.manage` | Pacientes. |
| `appointments.manage` | Sessões e seleção de profissional no chat. |
| `documents.manage` | Gestão Documental. |
| `finance.manage` | Financeiro. |
| `admin.settings.manage` | Configurações e integrações. |

**Captador** (`captador`): tem captação, chat, cadastros, usuários, pacientes, sessões e grupos de WhatsApp.

**Trava de segurança:** quem não tem `roles.manage` (o captador) só cria, edita ou exclui usuários com perfil **Profissional** ou **Paciente**. Não consegue criar administradores nem alterar a equipe.

---

## 4. Captação

### 4.1 Entrada de e-mails

1. `cron/smtp_poll.php` (e o botão de sincronizar na Captação) baixa os e-mails da caixa para a tabela `inbound_emails`. A busca disparada pela tela roda no máximo **uma vez por minuto**.
2. `cron/openai_extract_email_to_demand.php` lê cada e-mail com a IA e cria um card por paciente (e sub-solicitações quando há várias especialidades).
3. Respostas de autorização (mesma conversa do e-mail de proposta) são reconhecidas e vão para a Autorização, não viram card.

A caixa de captação recebe **somente pedidos de captação**, por isso a triagem "é/não é captação" está **desligada** (`demands.captation_filter_enabled = 0`). Para religar numa caixa mista, grave `1`.

### 4.2 O card

Criação e edição usam o mesmo formulário (`app/demand_form.php`):

- **Paciente e contratante:** nome do paciente, cliente, operadora, e-mail de origem. Os botões **"+"** cadastram cliente, operadora ou especialidade na hora, cada um com seus próprios campos, e já deixam selecionado.
- **Atendimento:** especialidade, frequência, urgência, valor do procedimento (não vai para o grupo).
- **Local:** campo **"Endereço completo"** — ao colar o endereço em uma linha, o sistema separa em CEP, rua, número, complemento, bairro, cidade, UF e zona. Os campos separados ficam abaixo para conferência. O CEP também completa o endereço (ViaCEP).
- **Mensagem:** quadro clínico (vai no grupo, sem nome do paciente), observação para os profissionais, observações internas.
- **Preencher com IA:** cole o texto do e-mail e a IA preenche os campos para conferência. Nada é salvo sem clicar em Salvar.

**O que bloqueia a captação** (card fica em *Tratamento Manual*): especialidade, cidade, UF e, em São Paulo e Rio de Janeiro, a **zona**.
**Recomendado** (a mensagem sai incompleta): paciente, rua, bairro, frequência, quadro clínico.

O que falta aparece no kanban ("⚠️ Falta: …"), na tela do card e na edição (vermelho = bloqueia, laranja = recomendado). Ao salvar com o obrigatório preenchido, o card sai sozinho do Tratamento Manual.

### 4.3 Grupos por cidade e zona

- O grupo de WhatsApp é por **especialidade + cidade + UF**. Ex.: `Fisioterapia - Mirassol/SP - 1`.
- **São Paulo e Rio de Janeiro** também são separados por **zona** (Centro, Norte, Sul, Leste, Oeste). Ex.: `Fonoaudiologia - São Paulo/SP - Zona Sul - 1`.
- A zona sai do **bairro** (tabela de distritos e bairros em `app/location_zones.php`) ou é escolhida manualmente.
- Um card **nunca** usa grupo de outra cidade ou zona. Se não existe grupo, o sistema cria.

### 4.4 Quem entra no grupo

Calculado em `dispatch_collect_professionals()`:

1. **Profissionais cadastrados** (perfil Profissional, ativos, com telefone) da especialidade do card. A localização vem do cadastro (cidade, UF, bairro, zona em *Usuários → Editar*) ou, se faltar, do formulário de candidatura.
2. **Contatos da agenda** (Contatos, categoria Profissional) ainda não pré-cadastrados, com especialidade deduzida do nome (ex.: "Fono Ana") e **cidade informada** igual à do card.
3. **Equipe MultiLife:** números das instâncias conectadas, quem disparou e quem gerencia captação.

Regra de localização do profissional:

| Profissional tem | Entra se |
|---|---|
| Cidade | mesma cidade (e mesma UF); em SP/RJ com zona conhecida, mesma zona |
| Só UF (cadastro ou DDD do telefone) | mesma UF do card |
| Nada | entra (para não esvaziar o grupo) |

**Admins do grupo:** só os números da MultiLife e a equipe. Profissionais nunca viram admin; se algum estiver como admin, é rebaixado na próxima captação do grupo.

**Modo de teste** (`feature.captacao_test_mode = 1`): só entram profissionais marcados como teste; a equipe não entra.

### 4.5 Ordem do disparo

Quando o captador clica em **Realizar Captação**:

1. A tela valida o card (o que falta) e enfileira o trabalho (`integration_jobs`, ação `demand_dispatch_run`). O processamento é acionado na hora; o cron é a garantia.
2. O worker (`cron/integration_jobs_run.php` → `demands_dispatch_whatsapp_post.php` em modo worker):
   1. procura o grupo da cidade/zona; se não houver, **cria** (com a equipe e até 20 profissionais), configura "somente admins enviam" e promove a equipe;
   2. **adiciona ao grupo quem ainda não está** (lotes de 50) — **antes** de enviar;
   3. **envia a mensagem** com o token `#CAP<id>-XXXX`;
   4. muda o card para *Em Captação*.
3. O WhatsApp confirma o envio pelo webhook (`chat_webhook.php`); o acompanhamento fecha mesmo que a Evolution demore a responder.

**Acompanhamento:** o quadro "Captação em andamento" aparece em todas as telas, volta depois de recarregar a página e some sozinho ao terminar. Se a captação ficar parada (3 min na fila ou 5 min sem avanço), é encerrada com o motivo e pode ser disparada de novo.

### 4.6 Reações e chat

Quem reage à mensagem no grupo é registrado em `demand_interested_professionals` (via webhook). No Chat ao Vivo, o captador abre a conversa, atribui o card, seleciona o profissional, paciente, especialidade, serviço, e-mail da operadora e o **valor acordado por sessão**, e envia a proposta.

---

## 5. Autorização → Pré-admissão → Monitoramento

| Etapa | Regra |
|---|---|
| Proposta (`chat_select_professional_post.php`) | Cria `authorization_requests` com status `aguardando_autorizacao`. O valor acordado é **por sessão**. |
| Aprovação (`authorization_mark_responded_post.php`) | Exige o **valor total autorizado**. Cria o atendimento (`patient_assignments`, status `confirmed`) e os lançamentos financeiros (receita e despesa). Vai para a Pré-admissão. |
| Negativa | Status `autorizacao_negada`; o card vai para a coluna *Negativas*. |
| Pré-admissão (`pre_admissao_approve.php`) | Define agenda, frequência, total de sessões (ou tempo indefinido), orientação da operadora e login/senha de sistema externo. Recalcula o valor autorizado por sessão e a despesa com o número real de sessões. Gera as sessões (`billing_document_requirements`), envia a orientação ao profissional e o card vai para *Admitido*. |
| Monitoramento | Atendimentos `admitted`, `awaiting_documents`, `awaiting_financial_approval`: desmame, substituição (recaptação), confirmação e encerramento. |

### Orientações por operadora

Tabela `health_insurer_orientations`. Cada operadora tem variações (ex.: *Avaliação* e *Relatório gerencial*; ANERY com e sem tablet; GLOBAL por região e Avaliação de enfermagem). A variação é escolhida na pré-admissão pelo pessoal da captação.

Placeholders: `{{login_externo}}`, `{{senha_externa}}`, `{{tabela_dias}}` (tabela de padronização dos dias por frequência).

### Senha de sistema externo

Gravada **criptografada** (AES-256-GCM, `app/secure_store.php`). A chave fica em `config/config.php` → `app.secret_key`, versionada com o código. **Não altere esse valor** — as senhas gravadas deixariam de abrir. Se uma senha não abrir, o campo vem vazio e basta digitá-la de novo.

---

## 6. WhatsApp: saúde das instâncias

O sistema registra quedas, reconexões, QR Codes e resultado dos envios (`whatsapp_instance_events`) e avalia os últimos 15 minutos:

| Situação | Critério |
|---|---|
| Conexão em loop | 3+ quedas e reconexões ou 6+ trocas de estado |
| QR Code em loop | 5+ QR Codes sem conectar |
| Conectada, mas envios falhando | 3 envios seguidos com erro |
| Conflito de sessão | outra sessão usando o mesmo número |
| Desconectada do celular | logout feito pelo WhatsApp |

Quando acontece: aviso no topo das telas com o passo a passo, alerta na lista de instâncias (Configurações → WhatsApp) e a captação deixa de usar a instância. Some sozinho após 5 minutos estável.

**Passo a passo de recuperação:** no celular, *Aparelhos conectados → desconectar todos*; no sistema, *Desconectar (logout)* e *Conectar*; ler o QR e aguardar 1–2 minutos sem reconectar de novo; se persistir, reiniciar ou recriar a instância.

As instâncias precisam enviar os eventos `CONNECTION_UPDATE` e `QRCODE_UPDATED` ao webhook (botão "configurar webhook").

---

## 7. Zoho — avisos de faturamento

Tela: Configurações → Zoho Faturamento (`admin_zoho_billing.php`). Webhook: `zoho_billing_webhook.php`.

- **Instância de envio:** escolhida na lista; os avisos saem **somente** por ela.
- **Destino:** com o modo teste **desligado**, o telefone que chega no webhook; com o modo teste **ligado**, sempre o telefone de teste. O botão "Testar Webhook" sempre usa o telefone de teste.
- Webhook sem telefone válido: não envia e registra o motivo.

---

## 8. Rotinas em segundo plano (cron)

Todas exigem `?token=<cron.token>`.

| Script | Função |
|---|---|
| `cron/integration_jobs_run.php` | Fila de integrações: captação, adição de membros, notificações. Aceita `?job_id=N`. |
| `cron/smtp_poll.php` | Baixa e-mails da caixa de captação. |
| `cron/openai_extract_email_to_demand.php` | Transforma e-mails em cards. |
| `cron/process_authorization_responses.php` | Lê respostas de autorização das operadoras. |
| `cron/demands_auto_archive.php` / `demands_auto_reactivate.php` | Arquiva cards sem retorno / reativa com nova reação. |
| `cron/demand_followup_dispatch.php` | Lembretes de captação. |
| `cron/chat_unanswered_sla.php` | Alerta de conversas sem resposta. |
| `cron/documents_validity_monitor.php` | Validade de documentos. |

Configuração útil: `demands.dispatch_kick_worker = 0` faz a captação depender só do cron (sem acionamento imediato).

---

## 9. Configurações (tabela `admin_settings`)

| Chave | Uso |
|---|---|
| `cron.token` | Token das rotinas cron. |
| `evolution.base_url`, `evolution.api_key`, `evolution.instance` | Evolution API. |
| `openai.api_key`, `openai.model` | IA. |
| `demands.whatsapp_template` | Modelo da mensagem de captação (placeholders `{title}`, `{street_neighborhood_city}`, `{specialty}`, `{frequency}`, `{ai_summary_block}`, `{captation_note_block}`...). |
| `demands.captation_filter_enabled` | Triagem de e-mails (padrão `0`). |
| `demands.dispatch_include_contacts` | Incluir contatos da agenda na captação (padrão `1`). |
| `demands.dispatch_kick_worker` | Acionamento imediato da fila (padrão `1`). |
| `feature.captacao_test_mode` | Modo de teste da captação. |
| `feature.auto_send_authorization` | Envio automático do e-mail de proposta. |
| `zoho_billing.*` | Zoho (modo teste, telefone de teste, instância, modelo). |

---

## 10. Banco de dados — migrations desta entrega

Rodar em ordem:

1. `migrations/2026-10-27_0001_demand_dispatch_runs_async.sql`
2. `migrations/2026-10-27_0002_captacao_fluxo_revisado.sql` — permissão `cadastros.manage`, permissões do captador, zona/CEP, localização do profissional, frequência da autorização, senha criptografada, histórico de conexões.
3. `migrations/2026-10-27_0003_orientacoes_operadoras_faltantes.sql` — CENEMED, GLOBAL, PRONEP, Vô Cuidar, Senior Mais; correção da Prevent Senior; Unimeds.

O código também cria as colunas que faltarem sozinho, mas as **permissões e orientações** dependem das migrations.

Cards de teste: `testes/seed_cards_cenarios_captacao.sql` (6 cenários; limpeza no fim do arquivo).

---

## 11. Desempenho

- Chamadas em segundo plano (busca de e-mails, polling do chat, notificações, acompanhamento) **liberam a sessão** (`session_release()`) e não travam as outras telas do usuário.
- Perfis, permissões e usuário logado têm cache por requisição.
- Chamadas à Evolution têm tempo limite curto (estado: 10 s; grupos: 15–25 s) e o estado das instâncias é reaproveitado durante o disparo.

---

## 12. Diagnóstico rápido

| Sintoma | Onde olhar |
|---|---|
| Card em Tratamento Manual | Aviso "Falta: …" no card; *Editar* completa. Registro em `demand_status_logs.note`. |
| Captação parada | Mensagem do quadro de progresso; `SELECT id,status,attempts,last_error,last_run_at FROM integration_jobs WHERE action='demand_dispatch_run' ORDER BY id DESC LIMIT 5;` |
| Captação "na fila" sem começar | Cron `integration_jobs_run` e `cron.token`. |
| WhatsApp conectado mas não envia | Aviso de saúde no topo; Configurações → WhatsApp. |
| Profissional não entrou no grupo | Especialidade, cidade/UF/bairro do cadastro, telefone e (no modo teste) a marcação de teste. |
| Grupo de outra cidade | Não deve ocorrer: conferir cidade/UF/zona do card e do grupo (`whatsapp_groups`). |
| Logs | Log de erros do PHP (prefixos `[DISPATCH]`, `[EVOLUTION]`, `[EMAIL_EXTRACT]`, `[WHATSAPP_HEALTH]`); chamadas às integrações em `integration_jobs_list.php` e `tech_logs_list.php`. |

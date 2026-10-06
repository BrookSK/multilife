# Ajustes Operacionais Globais — MultiLife Care

Documento de planejamento e execução dos ajustes solicitados. Cada item traz: objetivo, causa/estado atual, arquivos afetados, abordagem técnica e critério de pronto. Os itens são implementados **um a um**, marcando o progresso.

> Arquitetura base: PHP procedural, MySQL via `db()` (PDO), roteamento por arquivos `.php` na raiz, views por `app/view.php` (`view_header`/`view_footer`, `h()` escapa HTML), RBAC por `rbac_require_permission`/`rbac_user_can`, settings por `admin_setting_get`. Migrations em `migrations/*.sql`.

---

## Ajuste 1 — Remover o texto "substituição de profissional" das notificações

**Objetivo:** Nem o profissional substituído nem o novo profissional podem perceber que se trata de uma substituição. As notificações (WhatsApp e e-mail) não devem conter termos como "substituição", "você foi substituído", "novo profissional designado em substituição a...".

**Estado atual:**
- As mensagens saem via `WhatsAppEventDispatcher::dispatch()` (`app/whatsapp_event_dispatcher.php`), que também espelha o mesmo texto no e-mail (`sendEmailNotification`).
- Os textos vêm dos **templates** na tabela `whatsapp_events` para os eventos `professional_substituted` (aviso ao NOVO profissional) e `professional_removed` (aviso ao profissional ANTERIOR).
- `monitoramento_substituicao_post.php` cria automaticamente o evento `professional_removed` com um template hardcoded contendo `🔄 *Substituição de Profissional*` e "você foi substituído".

**Abordagem:**
1. Reescrever o template default hardcoded de `professional_removed` em `monitoramento_substituicao_post.php` para uma mensagem neutra (ex.: encerramento/conclusão do atendimento, agradecimento), sem termo "substituição".
2. Reescrever o template default do evento `professional_substituted` (quando criado) para um aviso neutro de designação de atendimento ("Você foi designado para um novo atendimento"), sem citar que substitui alguém.
3. Migration `UPDATE` nos registros já existentes de `whatsapp_events` desses dois eventos, trocando o texto por versões neutras (apenas quando o texto ainda for o default conhecido, para não sobrescrever customizações da equipe). Mesmo tratamento para templates de e-mail equivalentes se existirem em `email_events`.
4. Garantir que o `reason` (motivo da substituição) **não** seja injetado em nenhum template enviado ao profissional.

**Arquivos:** `monitoramento_substituicao_post.php`, nova migration `migrations/XXXX_neutralize_substitution_templates.sql`.

**Pronto quando:** As mensagens recebidas por ambos os profissionais não contêm nenhuma referência a substituição; a equipe interna continua vendo o motivo apenas no painel.

---

## Ajuste 2 — Importador de contatos (cliente / profissional / outros)

**Objetivo:** Importar contatos exportados do celular (nome, telefone, categoria) para que fiquem pré-cadastrados e disponíveis para a equipe de captação.

**Estado atual:** Contatos de chat ficam em `chat_contacts` (`remote_jid` UNIQUE, `contact_name`, `is_group`, ...). Não há importador de contatos "de agenda".

**Abordagem:**
1. Nova tabela `imported_contacts` (`id`, `name`, `phone` normalizado, `phone_raw`, `category` ENUM('cliente','profissional','outros'), `source`, `created_by_user_id`, `created_at`, UNIQUE(`phone`,`category`)).
2. Tela web `contacts_import.php` (upload CSV/arquivo exportado) + `contacts_import_post.php` (parse + preview + confirmação). Detecção de categoria pela marcação no arquivo (prefixo "cliente"/"profissional"/outro no nome, conforme formato real do arquivo do usuário).
3. Tela `imported_contacts_list.php` para a captação consultar/buscar os contatos.
4. **Parser depende do formato real do arquivo** — estruturar genérico (CSV com colunas nome/telefone/categoria) e ajustar quando o arquivo for enviado.

**Arquivos:** `contacts_import.php`, `contacts_import_post.php`, `imported_contacts_list.php`, `app/imported_contacts_helper.php`, migration `imported_contacts`, botão "Contatos" em `demands_list.php`.

**Implementado:** upload CSV com detecção automática de colunas (nome/telefone/categoria), normalização de telefone (DDI 55), detecção de categoria por marcador/nome, dedup por (telefone,categoria), lista com filtro/busca. Parser flexível.

> **Pendência de ajuste fino:** com o arquivo real de exportação, confirmo o separador e os nomes das colunas para 100% de precisão.

---

## Ajuste 3 — Importador das captações de setembro + zerar os "em andamento" antigos

**Objetivo:** Importar a planilha de captações de setembro (fechados etc.) e zerar/arquivar os cards que estavam "em andamento" de antes.

**Estado atual:** Existe `imports/import_spreadsheets.php` (CLI, lê CSV de monitoramento, cria demands+assignments). Cards ficam em `demands` com `status` ENUM.

**Abordagem:**
1. Tela web `captation_import.php` + `captation_import_post.php` (upload planilha, preview, confirmação) reaproveitando a lógica de `imports/import_spreadsheets.php`.
2. Opção explícita "zerar os em andamento anteriores": antes de importar, mover para `cancelado`/arquivado os `demands` em status de andamento (`em_captacao`, `tratamento_manual`, `aguardando_captacao`) criados antes de uma data de corte. Operação **destrutiva/irreversível em massa** → exige confirmação explícita na tela e registro em log.
3. **Parser depende do formato real da planilha** — estruturar e ajustar com o arquivo.

**Arquivos:** `captation_import.php`, `captation_import_post.php`, botão "Importar planilha" em `demands_list.php`.

**Implementado:** upload CSV com detecção de colunas (paciente/profissional/especialidade/cidade/UF/frequência/status/telefone), cria cards (demands) com status mapeado do status da planilha (fechado→admitido, etc.), pré-cadastra paciente e profissional. Opção destrutiva "zerar em andamento anteriores" (move para cancelado) com dupla confirmação explícita. Parser flexível.

> **Pendência de ajuste fino:** com a planilha real de setembro, confirmo os nomes das colunas e o mapeamento de status.

---

## Ajuste 4 — Campos úteis no card manual + endereço único

**Objetivo:** O "Novo card" manual (`demands_create.php`) tem campos que a equipe não usa. Trocar pelos campos que fazem sentido no fluxo; o endereço deve ser **um campo único** (a IA separa depois).

**Estado atual:** Form com `title`, cidade, UF, rua, número, bairro, complemento (separados), especialidade, origem (e-mail), frequência, descrição, status.

**Abordagem:**
1. Endereço: substituir os campos separados por **um textarea/input único** (`location_raw`). Adicionar coluna `location_raw` em `demands` (migration). O post grava o texto completo nesse campo e nos campos separados deixa nulo (IA separa depois).
2. Campos úteis alinhados ao fluxo: `patient_name` (paciente), `specialty`, `frequency`, `urgency`, `procedure_value`, `client_id`/`health_insurer_id` (contratante/operadora), `location_raw`, `description`/observações. Remover campos crus desnecessários (UF/rua/número/bairro/complemento separados do form manual).
3. Atualizar `demands_create_post.php` para o novo conjunto.

**Arquivos:** `demands_create.php`, `demands_create_post.php`, migration `location_raw`.

**Pronto quando:** Card manual tem só campos úteis, endereço é um campo único e grava corretamente.

---

## Ajuste 5 — Exibição de múltiplos arquivos no chat (vários enviados, só um aparece)

**Objetivo:** Quando vários arquivos são enviados de uma vez pelo WhatsApp, todos devem aparecer no chat (hoje só um aparece).

**Causa raiz (confirmada):** O polling incremental do chat usa `WHERE message_timestamp > :since` (comparação estrita) e avança `lastTimestamp` para o segundo já consumido. Como múltiplas mídias chegam no mesmo segundo, as demais nunca mais satisfazem `> since` e somem do chat em tempo real. Secundário: dedup de `fromMe` por texto+janela de ±10s descarta arquivos sem legenda (placeholders iguais).

**Abordagem:**
1. Mudar a paginação do polling para usar o **id** como cursor (ou timestamp+id), em `chat_poll_messages.php` e `chat_get_messages.php`: retornar `last_id` e filtrar `(message_timestamp > since) OR (message_timestamp = since AND id > last_id)`, ou simplesmente paginar por `id > last_id`.
2. Ajustar `chat_web_functions.js` para enviar/rastrear `last_id` e manter a dedup por `external_message_id`/`id` no DOM.
3. Afrouxar a dedup de `fromMe` para considerar `external_message_id` (quando houver) em vez de só texto+janela, evitando descartar múltiplos arquivos sem legenda.

**Arquivos:** `chat_poll_messages.php`, `chat_get_messages.php`, `chat_web_functions.js`, `chat_webhook.php` (dedup fromMe).

**Pronto quando:** Enviar N arquivos juntos exibe os N arquivos no chat sem recarregar a página.

---

## Ajuste 6 — Filtrar/sseparar cards de captação dos demais e-mails

**Objetivo:** O e-mail conectado recebe de tudo (captação, auditoria, etc.). Hoje todo e-mail vira card. Só deve virar card se o e-mail for de captação; os demais ficam separados.

**Estado atual:** `cron/openai_extract_email_to_demand.php` converte todo e-mail (que não seja resposta de autorização) em `demand`. Não há classificação de "é captação?".

**Abordagem:**
1. Adicionar etapa de **classificação** antes de criar o card: regra determinística (remetente/domínio em allowlist de operadoras/clientes conhecidos; palavras-chave de assunto) combinada com a IA (classifica "captação" vs "outros").
2. Se não for captação: marcar `inbound_emails.status = 'not_captation'` (ou `ignored`) e **não criar** `demand`. Registrar motivo.
3. Separar na UI: na inbox (`inbound_emails_list.php`) distinguir captação x outros; no Kanban (`demands_list.php`) nada muda (só nascem cards de captação). Possível aba/filtro "Outros e-mails".

**Arquivos:** `cron/openai_extract_email_to_demand.php`, `inbound_emails_list.php`.

**Implementado:** função `classify_email_is_captation()` (regras negativas/positivas + IA) roda antes da CHAMADA 1. Não-captação → `inbound_emails.status='not_captation'`, sem criar card. Inbox com abas Captação/Não-captação/Erros e badge colorido. Setting `demands.captation_filter_enabled` ('0' desliga; default ligado). Fallback conservador: se a IA falhar, mantém como captação para não perder solicitação real.

**Pronto quando:** E-mails não-captação não geram card e ficam visíveis à parte; captação continua gerando card normalmente.

---

## Ajuste 7 — Substituição por tempo determinado (cobertura)

**Objetivo:** Permitir substituir um profissional **temporariamente** (cobertura). Ao fim do período, o profissional original volta automaticamente.

**Estado atual:** `monitoramento_substituicao.php`/`_post.php` fazem substituição definitiva (troca `professional_user_id` do assignment).

**Abordagem:**
1. Adicionar no form a opção "Cobertura (tempo determinado)" com data de início e **data de término** da cobertura.
2. Nova tabela `assignment_coverages` (`assignment_id`, `original_professional_user_id`, `cover_professional_user_id`, `start_date`, `end_date`, `status`, dados do atendimento de cobertura, `created_by`). Guardar o profissional original para retorno.
3. Durante a cobertura, o assignment aponta para o profissional de cobertura; sessões no período vão para ele.
4. Cron (`cron/`) que, ao passar `end_date`, restaura o `professional_user_id` original no assignment e marca a cobertura como `finished`. Notificações neutras (ver Ajuste 1).

**Arquivos:** `monitoramento_substituicao.php`, `monitoramento_substituicao_post.php`, migration `assignment_coverages`, novo `cron/restore_coverages.php`.

**Pronto quando:** É possível registrar cobertura com término; no fim do período o original volta sozinho.

---

## Ajuste 8 — Recaptação sigilosa (profissional atual não pode saber)

**Objetivo:** Para substituir um profissional, refazer todo o fluxo de captação para o mesmo card/cliente **sem** que o profissional atual saiba — portanto **não** disparar no grupo onde ele está.

**Estado atual:** Disparo de captação vai para `whatsapp_groups` compatíveis (`demands_dispatch_whatsapp_post.php`). O profissional atual pode estar nesses grupos.

**Abordagem:**
1. Fluxo "Recaptar para substituição (sigiloso)" a partir do atendimento: reabre/duplica a demanda em modo captação, marcando-a como sigilosa.
2. No disparo, **excluir** os grupos que contêm o profissional atual (checar membros do grupo via Evolution/`whatsapp_groups` membros) ou enviar apenas via canais individuais (DM) aos interessados, nunca no grupo com o profissional atual.
3. Alternativa de segurança: enviar a captação sigilosa somente por mensagem direta a profissionais elegíveis (não em grupo). Decidir conforme viabilidade de saber a composição dos grupos.
4. Ao concluir, aplicar a substituição (Ajuste 1 garante notificação neutra).

**Arquivos:** `monitoramento_substituicao.php`/novo fluxo, `demands_dispatch_whatsapp_post.php` (ou novo `_post` sigiloso), `app/demand_captation_handler.php`, migration (flag `is_confidential` na demanda/dispatch).

**Pronto quando:** É possível recaptar para troca sem nenhuma mensagem no grupo do profissional atual.

---

## Ajuste 9 — Tela de Notas Fiscais no Financeiro

**Objetivo:** No financeiro, uma tela para subir notas fiscais correlacionadas ao que foi pago/recebido, mantendo controle de arquivo e valores.

**Estado atual:** `finance_entry_create.php` tem campo texto `document_number` mas sem upload. `financial_entries` guarda os lançamentos. Padrão de upload do sistema em `uploads/` (ver `documents_upload_post.php`, `faturamento_upload_doc.php`).

**Abordagem:**
1. Nova tabela `finance_invoices` (`id`, `type` income/expense, `financial_entry_id` NULL, `patient_id`/`professional_user_id`/`client_id` NULL, `invoice_number`, `issue_date`, `amount`, `file_path`, `file_name`, `file_type`, `notes`, `created_by_user_id`, timestamps).
2. Telas: `finance_invoices_list.php` (lista + filtros), `finance_invoice_upload.php` + `finance_invoice_upload_post.php` (upload arquivo + metadados + vínculo a lançamento/pagamento/recebimento), `finance_invoice_download.php`.
3. Permissão `finance.manage`. Link no menu financeiro (`app/view.php`).

**Arquivos:** novos arquivos acima, migration `finance_invoices`, `app/view.php`.

**Pronto quando:** É possível subir uma NF, vinculá-la a um lançamento (pago/recebido) com valor, listar, baixar e manter o controle.

---

## Ordem de execução

1. Ajuste 1 (templates neutros) — rápido, alto impacto.
2. Ajuste 4 (card manual + endereço único).
3. Ajuste 5 (múltiplos arquivos no chat).
4. Ajuste 6 (filtro de captação).
5. Ajuste 7 (cobertura).
6. Ajuste 8 (recaptação sigilosa).
7. Ajuste 9 (notas fiscais).
8. Ajustes 2 e 3 (importadores) — ao receber os arquivos do usuário.

> Itens 2 e 3 ficam com a estrutura pronta (telas + tabelas) e o parser é finalizado quando os arquivos forem enviados.

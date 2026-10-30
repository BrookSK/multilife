# MultiLife Care — Manual de uso por equipe

Este manual explica, passo a passo, o que cada equipe faz no sistema e onde clicar. Os nomes entre aspas são os textos que aparecem na tela.

---

## Como o trabalho anda pelo sistema

Um atendimento passa por estas etapas, nesta ordem:

| # | Etapa | Equipe | Menu |
|---|---|---|---|
| 1 | O pedido chega e vira um **card** | Captação | **Captação** |
| 2 | O card é enviado aos grupos de WhatsApp dos profissionais | Captação | **Captação** |
| 3 | Conversa com quem se interessou e envio da proposta | Captação | **Chat ao Vivo** |
| 4 | A operadora aprova ou nega | Captação | **Autorização** |
| 5 | Passagem de plantão e início do atendimento | Captação / Admissão | **Pré-admissão** |
| 6 | Acompanhamento do atendimento | Monitoramento | **Monitoramento** |
| 7 | Conferência das sessões e fechamento do mês | Faturamento | **Sessões**, **Faturamento**, **Fechamento Mensal** |
| 8 | Pagamentos, recebimentos e notas fiscais | Financeiro | **Financeiro** e submenus |

Cada pessoa só vê no menu lateral as telas da sua função.

**Avisos que podem aparecer em qualquer tela:**
- **Quadro "Captação em andamento"** (canto inferior direito): mostra o andamento de uma captação. Pode continuar trabalhando; ele some sozinho quando termina.
- **Faixa "⚠️ WhatsApp ...: Conexão em loop"** (topo): o WhatsApp daquele número está com problema. Siga os passos escritos na própria faixa ou avise o responsável.
- **Sino** (topo): notificações do sistema.
- **Relógio** (topo): histórico do que foi feito naquela tela e por quem.

---

## 1. Equipe de Captação

### 1.1 A tela de Captação

Menu **Captação**. Os cards ficam em colunas, conforme a situação:

| Coluna | O que significa |
|---|---|
| **Recebimento de E-mail** | Card completo, pronto para captar. |
| **Tratamento Manual** | Falta alguma informação. O card mostra **"⚠️ Falta: ..."**. |
| **Em Captação** | Já foi enviado aos grupos. Aguardando profissionais. |
| **Negativas** | A operadora negou a proposta. |
| **Admitido** | Atendimento iniciado. |
| **Concluídos** / **Cancelado** | Encerrados (últimos 30 dias). |

Botões do topo: **"Novo card"**, **"Inbox (E-mails)"**, **"Contatos"**, **"Importar planilha"**, **"Grupos WhatsApp"**. Use os filtros (status, especialidade, cidade, cliente, captador, datas, busca por número do card) para encontrar um card.

Um card com **borda vermelha** está esperando há mais de 10 minutos sem ninguém assumir.

### 1.2 Quando chega um e-mail

Os e-mails da caixa de captação viram cards **sozinhos**. A inteligência artificial lê o e-mail e preenche paciente, especialidade, endereço, frequência e quadro clínico.

1. Abra o card novo na coluna **Recebimento de E-mail** (ou **Tratamento Manual**, se faltou algo).
2. Confira os dados. O texto original do e-mail fica no próprio card.
3. Se estiver em **Tratamento Manual**, veja a faixa **"Captação bloqueada — falta: ..."**, clique em **"Completar dados do card"**, preencha o que falta e salve. O card sai do Tratamento Manual sozinho.

### 1.3 Criar um card manualmente

1. Clique em **"Novo card"** (ou no **+** no canto da tela).
2. **Atalho:** abra **"Preencher a partir do texto do e-mail"**, cole o texto do pedido e clique em **"Preencher com IA"**. Os campos são preenchidos; confira antes de salvar.
3. **Paciente e contratante:** nome do paciente, cliente, operadora e e-mail de origem.
   - Se o cliente, a operadora ou a especialidade não existir, clique no **+** ao lado do campo, preencha e clique em **"Cadastrar e selecionar"**. Não precisa sair da tela.
4. **Atendimento:** especialidade, frequência, urgência e valor. O valor **não** aparece para os profissionais.
5. **Local do atendimento:** cole o endereço inteiro em **"Endereço completo (cole em uma linha)"**. O sistema separa rua, número, bairro, cidade, UF e CEP sozinho. Confira os campos abaixo.
   - Em **São Paulo** e **Rio de Janeiro**, a **zona** (Norte, Sul, Leste, Oeste, Centro) é preenchida pelo bairro. Se aparecer "Bairro não reconhecido", escolha a zona na lista.
6. **Informações para a mensagem:**
   - **Quadro clínico:** vai na mensagem do grupo. Não coloque o nome do paciente.
   - **Observação para os profissionais:** também vai na mensagem (ex.: "atendimento pela manhã").
   - **Observações internas:** ficam só no sistema.
7. Clique em **"Salvar card"**.

Campos em **vermelho** impedem a captação. Campos em **laranja** são recomendados (a mensagem sai incompleta sem eles).

### 1.4 Realizar a captação (enviar aos grupos)

1. Abra o card e clique em **"Realizar Captação"**.
   - Se o pedido tem várias especialidades, escolha qual captar e clique em **"Captar esta"**.
2. O quadro **"Captação em andamento"** mostra cada etapa. Pode continuar usando o sistema.
3. O sistema faz sozinho:
   - procura o grupo de WhatsApp da especialidade **na cidade do paciente** (e na zona, em SP/RJ); se não existir, **cria o grupo**;
   - **coloca no grupo** os profissionais daquela especialidade e cidade que ainda não estão lá;
   - **envia a mensagem** com local, especialidade, frequência e quadro clínico;
   - move o card para **Em Captação**.
4. Quando terminar, o quadro fica verde ("Captação enviada ✓") e a tela do card atualiza.

Se aparecer "Complete o card antes de captar", volte ao passo 1.2. Se aparecer um aviso do WhatsApp, avise o responsável pelos números.

**Botões do card:** **"Editar"**, **"Assumir Demanda"** (fica responsável pelo card), **"Devolver"** (libera para outro captador), **"Cancelar Card"**, e a lista de status com **"Atualizar status"**.

### 1.5 Conversar com quem se interessou (Chat ao Vivo)

Os profissionais demonstram interesse **reagindo** (com qualquer emoji) à mensagem no grupo.

1. Menu **Chat ao Vivo** → ícone **"Lista de Espera"** (relógio).
2. Os interessados aparecem separados por **grupo** e por **captação**, com **"Reagiu em dd/mm hh:mm"**.
3. Clique no profissional para abrir a conversa particular com ele e combine os detalhes.

Outros ícones do Chat ao Vivo: **"Em Captação"** (conversas em andamento), **"Concluídos"**, **"Grupos"**, **"Todos"** e **"Contatos"** (agenda importada). Botões do topo: **"Sincronizar"**, **"Nova Conversa"**, **"Criar Grupo"**, **"Gerenciar Grupos"**, **"QR Code"**.

### 1.6 Enviar a proposta

Com a conversa do profissional aberta, no painel da direita:

1. Em **"Atribuir Paciente"**, escolha o card em **"Selecione um card de captação..."**. Aparecem só os cards que **você assumiu**.
2. Clique em **"Selecionar Profissional"**.
3. Preencha **Paciente**, **Profissional**, **Especialidade**, **E-mail da Operadora / Cliente**, **Serviço** e **Valor Acordado (profissional)** — valor **por sessão** que o profissional vai receber.
4. Clique em **"Enviar Proposta"**. O card vai para a **Autorização**.

Se o paciente ou o profissional ainda não tem cadastro, use **"Cadastrar Contato"** → **"Profissional"** ou **"Paciente"** no painel da direita (cadastro rápido, completa-se depois).

### 1.7 Autorização (resposta da operadora)

Menu **Autorização**. As propostas ficam em **"⏳ Aguardando Resposta"** e **"❌ Negativas"**.

1. Clique na proposta.
2. Quando a operadora responder:
   - **Aprovou:** digite o **valor total autorizado** e clique em **"✅ Marcar como aprovada"**. O atendimento vai para a **Pré-admissão**.
   - **Negou:** escreva o motivo (opcional) e clique em **"❌ Marcar como recusada"**. O card vai para **Negativas**.
3. Outras opções: **"🔄 Reenviar com Novo Valor"**, **"⛔ Finalizar Solicitação"** e, na lista, **"📧 Reenviar E-mail"**.

### 1.8 Pré-admissão (passagem de plantão)

Menu **Pré-admissão**. Clique no paciente para abrir a ficha. Ela tem 5 abas; preencha todas antes de aprovar.

| Aba | O que fazer |
|---|---|
| **Faturamento** | Confirme a **Operadora / Cliente** (obrigatório). Os demais dados vêm da proposta. |
| **Agendamento** | **Data de Início**, **Hora Início**, **Hora Fim**, **Frequência** e **Duração (semanas)**. O **Total de Sessões** é calculado. Marque **"Tempo indefinido"** se não houver data para acabar. |
| **WhatsApp** | Confira os telefones do paciente e do profissional. |
| **Prontuário** | Confira a descrição do atendimento. |
| **Profissional** | Se a operadora usa sistema próprio (tablet, Salvus, Outcare...), preencha **"Login (sistema externo)"** e **"Senha (sistema externo)"**. Em **"Variação de orientação"**, escolha o texto certo da operadora (ex.: ANERY "Com tablet — Avaliação"). |

Clique em **"✅ Aprovar Atendimento"**. O sistema:
- cria as sessões no calendário;
- envia ao profissional a orientação da operadora, os documentos, a tabela de dias e o login/senha;
- move o card para **Admitido** e o atendimento para o **Monitoramento**.

**ANERY com ou sem tablet:** a operadora informa na autorização se a casa do paciente tem tablet. Escolha a variação correspondente.

### 1.9 Cadastros que a captação pode fazer

Pelo menu lateral: **Especialidades**, **Tipos de Serviço**, **Operadoras**, **Clientes**, **Pacientes**, **Profissionais**, **Contatos**.

- **Profissionais:** em *Profissionais → Editar*, preencha **cidade, UF e bairro de atendimento**. É isso que define em quais grupos o profissional entra.
- **Contatos:** agenda importada. Na categoria Profissionais, o botão **"Pré-cadastrar"** transforma o contato em profissional do sistema.
- **Cadastros Pendentes:** profissionais pré-cadastrados. **"Enviar link"** manda ao profissional o link para ele completar os próprios dados.
- **Pacientes Pendentes:** pacientes que vieram dos cards e ainda não têm cadastro. Use **"Pré-cadastrar"** ou **"Completar cadastro"**.

---

## 2. Equipe de Monitoramento

### 2.1 A tela de Monitoramento

Menu **Monitoramento**. Mostra um **calendário** com todas as sessões em andamento.

- **Cores:** amarelo = pendente, azul = documento enviado, verde = aprovado, vermelho = rejeitado, vermelho escuro = sem operadora.
- **Filtros:** busca, **Pacientes**, **Profissionais**, **Operadoras** (inclui "⚠️ Sem operadora"), **Especialidades**, e **"Limpar"**.
- Se aparecer "⚠️ N atendimento(s) sem operadora", abra um deles e defina a operadora.

Clique em uma sessão para abrir **"📋 Detalhes do Atendimento"**: dados gerais, paciente, profissional, valores e sessões.

### 2.2 O que fazer em cada situação

| Situação | Botão no detalhe | O que acontece |
|---|---|---|
| Lembrar o profissional do atendimento | **"✅ Confirmar Profissional"** | Envia um lembrete pelo WhatsApp. |
| Diminuir ou mudar a frequência | **"📉 Desmame (Alterar Frequência)"** | Escolha a nova frequência, a data de início e o motivo; clique em **"✓ Confirmar alteração"**. |
| Trocar o profissional | **"🔄 Substituir Profissional"** | Veja abaixo. |
| Encerrar o atendimento | **"🏁 Finalizar Atendimento"** | Escolha o **motivo**, data, hora e observações; clique em **"Finalizar"**. |
| Faltou a operadora | **"Definir operadora"** → **"Salvar"** | Preenche a operadora do atendimento. |
| Atendimento feito fora do sistema | **"➕ Novo atendimento"** (topo) | Registra a sessão; ela conta no fechamento do mês. |

Atendimentos encerrados ficam em **"📁 Finalizados"**.

### 2.3 Substituir o profissional

1. Clique em **"🔄 Substituir Profissional"**.
2. **Precisa de alguém novo sem o atual saber?** Clique em **"🔒 Recaptar sigiloso (não avisa o atual)"**. A vaga é enviada por mensagem particular aos profissionais disponíveis, sem usar o grupo.
3. Quem demonstrar interesse aparece em **"💬 Profissionais que demonstraram interesse"**. Clique em **"Selecionar"**.
4. Preencha o **motivo**, a **data de início**, os **horários**, a **frequência** e o **valor acordado** com o novo profissional.
5. **Troca temporária** (férias, por exemplo)? Marque **"Cobertura (tempo determinado)"** e informe o último dia. O profissional atual volta sozinho depois.
6. Escolha quem será avisado (paciente, profissional atual, novo profissional).
7. Clique em **"Confirmar Substituição"**.

---

## 3. Equipe de Faturamento (Sessões e Fechamento)

### 3.1 Sessões

Menu **Sessoes**. Três formas de ver: **"👨‍⚕️ Por Profissional"**, **"🩺 Por Especialidade"**, **"🏥 Por Operadora / Cliente"**.

1. Encontre o profissional e clique em **"Acessar"**.
2. Veja os pacientes e as sessões, com a situação de cada documento.
3. Em cada sessão:
   - **"Ver documento"**: abre a ficha enviada pelo profissional;
   - **"Aprovar"**: aprova a sessão;
   - **"Enviar Ficha"**: envia o documento no lugar do profissional.

### 3.2 Faturamento

Menu **Faturamento**. Abas: **"Aguardando Documentos"**, **"Aguardando Aprovação"**, **"Aprovados"**, **"Concluídos"**. Use **"Ver detalhes"** para conferir cada atendimento.

### 3.3 Fechamento Mensal

Menu **Fechamento Mensal**.

1. Escolha a **Competência** (mês) e, se quiser, filtre por cliente, operadora, profissional ou paciente. Clique em **"Ver"**.
2. Confira os atendimentos de cada operadora e clique em **"✔ Fechar operadora"**. (Errou? **"↩ Reabrir"**.)
3. Com todas as operadoras de um cliente fechadas, clique em **"🔒 Consolidar cliente"**.
4. Clique em **"Ir para o Financeiro →"**.

---

## 4. Equipe Financeira

### 4.1 Fechamento Financeiro

Menu **Fechamento Financeiro**.

1. Escolha a **Competência** e clique em **"Ver"**.
2. Para cada cliente consolidado, confira **A receber** e **A pagar** e clique em **"✅ OK — gerar contas"**.
3. O sistema cria as contas a receber (da operadora/cliente) e as contas a pagar (dos profissionais).

### 4.2 Contas a Receber e Contas a Pagar

- **Contas a Receber:** abas **"Pendentes"** e **"Histórico"**. Quando o dinheiro entrar, mude o status e clique em **"Salvar"**. Para lançar algo avulso, **"+ Nova Receita"**.
- **Contas a Pagar:** abas **"Pendentes"** e **"Histórico"**. Depois de pagar, clique em **"Marcar como pago"**. Para lançar algo avulso, **"+ Nova Despesa"**.

### 4.3 Lançamentos

Menu **Lançamentos**: tudo que entrou e saiu, com filtros por tipo, status, ano, mês e centro de custo.

Para criar um lançamento avulso:
1. Em **Contas a Receber** clique em **"+ Nova Receita"**, ou em **Contas a Pagar** clique em **"+ Nova Despesa"**. Abre a tela **"Novo Lançamento Financeiro"**.
2. Escolha **Receita** ou **Despesa**, categoria, forma de pagamento e se é **único, parcelado, recorrente ou contínuo**.
3. Preencha valor, datas, centro de custo e descrição.
4. Clique em **"Criar Lançamento"**.

### 4.4 Notas Fiscais

Menu **Notas Fiscais** → **"+ Nova Nota Fiscal"**.

1. Escolha se a nota foi **recebida** (receita) ou **paga** (despesa).
2. Preencha valor, número, data de emissão e fornecedor/cliente.
3. Se quiser, vincule a um lançamento, profissional ou paciente.
4. Anexe o arquivo (PDF, imagem ou XML) e clique em **"Salvar Nota Fiscal"**.

A nota fica no histórico, com o arquivo disponível para baixar.

### 4.5 Painel e pendências

- **Financeiro** (painel): gráfico de receitas e despesas, com filtros de período, profissional, especialidade e cidade.
- **Pendências**: contas atrasadas, documentos não recebidos e pré-admissões paradas.

---

## 5. Profissionais (área do profissional)

O profissional entra no sistema com o próprio login e vê:

- **Meus Registros:** seus pacientes, as sessões e os **documentos pendentes**. Clique em **"Enviar Documento"** para mandar a ficha de cada sessão.
- **Documentos da Operadora:** fichas e orientações que ele precisa usar.
- **WhatsApp** e **Minha Conta**.

---

## 6. Gestão Documental (todas as equipes com acesso)

Menu **Documentos**. Abas **Pacientes**, **Profissionais**, **Funcionários**, **Empresa** e **Enviados**.

- **Empresa:** documentos institucionais da MultiLife e a documentação do sistema (este manual e a documentação técnica). Use **"Upload"** para guardar contratos, alvarás e certidões.
- **Enviados:** tudo que foi mandado a profissionais e pacientes, com histórico.

---

## 7. Dúvidas comuns

| Pergunta | Resposta |
|---|---|
| O card ficou em Tratamento Manual. | Veja o "Falta: ..." no card, clique em **"Completar dados do card"** e salve. |
| O card de São Paulo não capta. | Falta a **zona**. Confira o bairro ou escolha a zona na edição. |
| O quadro de captação ficou parado. | Depois de alguns minutos ele mostra o motivo. Se for o WhatsApp, siga a faixa de aviso do topo e clique em **"Realizar Captação"** de novo. |
| Um profissional não entrou no grupo. | Confira no cadastro dele a **especialidade**, a **cidade/UF/bairro** e o **telefone**. |
| Não aparece meu card em "Atribuir Paciente" no chat. | Abra o card e clique em **"Assumir Demanda"**. Só aparecem os cards assumidos por você. |
| A operadora tem login/senha próprio. | Preencha na aba **Profissional** da Pré-admissão. O sistema envia ao profissional. |
| Não sei qual orientação escolher. | Use a variação que corresponde ao tipo de documento da operadora (Avaliação ou Relatório gerencial; com ou sem tablet). |

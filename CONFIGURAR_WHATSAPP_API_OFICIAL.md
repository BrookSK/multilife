# Como ligar a API oficial do WhatsApp (Meta) no MultiLife Care

O sistema agora trabalha com três integrações de WhatsApp. A escolha fica em
**Configurações → WhatsApp Conexão → Integração do WhatsApp**:

| Integração | Como o número conecta | Quando usar |
| --- | --- | --- |
| Baileys (serviço próprio) | QR Code | Padrão de hoje, roda na própria hospedagem |
| Evolution API | QR Code | Se tiver um servidor Evolution v2 |
| **API oficial (Meta)** | Painel da Meta, sem QR Code | Número oficial da empresa, sem risco de bloqueio por "aparelho conectado" |

Nada precisa ser instalado na hospedagem: tudo é feito por cliques no painel da Meta
e na tela **Configurações → API oficial** (`/admin_whatsapp_cloud.php`).

---

## 1. No painel da Meta (uma vez só)

1. Em [business.facebook.com](https://business.facebook.com) tenha um **Gerenciador de Negócios** verificado.
2. Em [developers.facebook.com/apps](https://developers.facebook.com/apps) crie um app do tipo **Empresa** e adicione o produto **WhatsApp**.
3. Em **WhatsApp → Configuração da API**, adicione o número da empresa (ele não pode estar em uso no WhatsApp do celular comum, a não ser pela "coexistência" do WhatsApp Business).
4. Anote o **ID da conta do WhatsApp Business (WABA)** que aparece nessa tela.
5. Em **Configurações do negócio → Usuários do sistema**, crie um usuário do sistema **Administrador**, dê acesso ao app e à conta do WhatsApp e gere um **token permanente** com as permissões `whatsapp_business_messaging` e `whatsapp_business_management`.
6. Em **Configurações do app → Básico**, copie a **Chave secreta do app**.

> Grupos pela API oficial só funcionam para contas **Oficiais (selo verde / OBA)**. Sem o selo, tudo o resto funciona; a captação vai direto para cada profissional (veja o item 5).

## 2. No sistema: Configurações → API oficial

Siga a tela de cima para baixo:

1. **Credenciais**: cole o ID da conta (WABA), o token permanente e a chave secreta do app. Clique em **Salvar credenciais** — a tela mostra o nome da conta se a Meta aceitou.
2. **Webhook**: copie o **endereço** e o **token de verificação** mostrados na tela. No app da Meta, em **WhatsApp → Configuração → Webhook → Editar**, cole os dois e clique em **Verificar e salvar**. Depois, em **Campos do webhook**, assine:
   `messages`, `smb_message_echoes`, `message_template_status_update`, `group_lifecycle_update`, `group_participants_update`, `group_settings_update`, `group_status_update`, `account_update`, `phone_number_quality_update`.
   Volte ao sistema e clique em **Inscrever o app na conta**. Quando chegar a primeira mensagem, a tela mostra "Último evento recebido da Meta".
3. **Números**: cada número da conta aparece na lista. Clique em **Usar no sistema** (dá para trocar o nome interno). O primeiro vira o **WhatsApp padrão** das mensagens automáticas. Para vincular aos usuários e dar apelido, use Configurações → WhatsApp Conexão → Instâncias, como antes.
4. **Modelos**: abra "Criar os modelos padrão do sistema na Meta" e envie os três (**Aviso**, **Captação** e **Convite para grupo**) para aprovação. Leva de minutos a 24 h. Quando aprovarem, clique em **Atualizar modelos da Meta** — eles já ficam escolhidos em cada uso.
5. **Captação**: deixe em **Direto para cada profissional** (recomendado).
6. **Teste de envio**: mande uma mensagem para o seu celular.
7. No topo da tela clique em **Usar a API oficial agora** (ou escolha em Configurações → WhatsApp Conexão → Integração).

Se o app Node.js do Baileys continuar ligado no painel da hospedagem, ele segue atendendo
os números de QR Code dele. Para usar só a API oficial, pare esse app no painel (Node.js →
Parar) ou tire os números de lá.

---

## 3. O que muda no dia a dia (regras da Meta)

**Janela de 24 horas.** Mensagem livre só pode ser enviada até 24 h depois da última mensagem
que a pessoa mandou para aquele número. Fora desse prazo o sistema envia sozinho pelo modelo
aprovado de **Aviso** (o texto vai numa linha só, dentro do modelo). No chat aparece a faixa:
- 🟢 "Conversa aberta pela API oficial até dd/mm hh:mm"
- 🟠 "Fora da janela de 24 h": texto sai pelo modelo; arquivo e áudio só depois que a pessoa responder.

**Captação.** Grupo da API oficial aceita no máximo **8 participantes** e ninguém é adicionado
sem aceitar o convite. Por isso a captação passou a ir **direto para cada profissional** da
especialidade/região (a mesma lista que entrava no grupo):
1. o captador clica em "Realizar Captação", como sempre;
2. cada profissional recebe a mensagem com o botão **Tenho interesse** (fora das 24 h, o modelo "Captação" com o mesmo botão);
3. tocar no botão **ou** reagir à mensagem coloca o profissional na **Lista de Espera**, pelo mesmo WhatsApp que enviou;
4. o histórico do card mostra "Envio direto a N profissionais: X enviadas, Y entregues, Z lidas, W falhas";
5. a cópia de cada envio aparece na conversa do profissional no Chat ao Vivo.

**Grupos.** Criar grupo continua pelo sistema; ele nasce com um **link de convite**.
"Adicionar participante" envia esse link para a pessoa (texto ou modelo "Convite para grupo"),
e ela entra ao tocar. Quem já recebeu convite daquele grupo nos últimos 30 dias não recebe de
novo automaticamente. Não existem: tornar administrador, "somente administradores enviam",
sair do grupo (use excluir), mensagens temporárias.

**Não existe na API oficial:** QR Code, desconectar o número pelo sistema, histórico antigo do
celular ("Sincronizar"), foto de perfil dos contatos, apagar ou editar mensagem já enviada,
enquete e status. As telas avisam quando alguém tenta usar.

**Mensagem não entregue.** Quando a Meta avisa que não entregou (número sem WhatsApp, fora das
24 h, bloqueio...), a mensagem fica marcada no chat com "⚠️ Não entregue: motivo". Se o motivo
for a janela de 24 h, o sistema reenvia sozinho pelo modelo de Aviso.

---

## 4. Problemas comuns

| O que aparece | O que fazer |
| --- | --- |
| "A Meta recusou: O token de acesso expirou ou é inválido" | Gere um token **permanente** de Usuário do Sistema (passo 1.5) e cole de novo. |
| Webhook não verifica no painel da Meta | Confira se o endereço começa com `https://` e se o token de verificação foi copiado inteiro. O endereço vem de "App - Base URL pública" (Configurações → Operacional). |
| Nenhuma mensagem chega no chat | Tela da API oficial → "Último evento recebido". Se "nenhum ainda": assine o campo `messages` no webhook e clique em "Inscrever o app na conta". |
| "Fora da janela de 24 h e sem modelo configurado" | Crie/escolha o modelo de Aviso (passo 2.4) e espere a aprovação. |
| "O modelo ainda não está aprovado" | Aguarde a Meta (até 24 h) e clique em "Atualizar modelos da Meta". |
| Captação "NÃO enviada" com motivo de modelo | O modelo "Captação" precisa estar aprovado para quem não falou com o número nas últimas 24 h. |
| "Webhook com assinatura inválida" em Eventos do WhatsApp | A chave secreta do app está errada: copie de novo em Configurações do app → Básico. |
| Grupo: "A Meta não criou o grupo" | A conta precisa ser Oficial (selo verde) para usar grupos. |

Todos os eventos (enviado por modelo, não entregue, convites, grupo criado, avisos da conta)
ficam em **Eventos do WhatsApp** (`/admin_whatsapp_eventos.php`).

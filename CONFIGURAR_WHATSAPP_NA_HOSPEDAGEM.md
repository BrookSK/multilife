# Como deixar o WhatsApp (Baileys) funcionando na hospedagem

Guia para quem só tem acesso ao **painel da hospedagem** (Plesk) e ao sistema.
Não precisa rodar comando, instalar pacote nem editar arquivo. São ajustes feitos
**uma vez** no painel; depois disso é só entrar no sistema e conectar o WhatsApp.

## Como funciona (para entender o que vai ser feito)

O WhatsApp agora roda num programa **Node.js** que fica ligado o tempo todo. Ele
já vem dentro do código do sistema, na pasta **`ml-baileys-service`**. O PHP
sozinho não consegue manter a conexão do WhatsApp aberta, então esse programa
precisa ser ligado pelo painel.

O Node.js do Plesk sempre ocupa um **endereço inteiro** (um domínio ou
subdomínio). Ele não pode ficar no mesmo endereço do sistema, porque tomaria o
lugar do PHP. Por isso é preciso **um endereço só para ele**. Esse endereço:

- **não recebe código novo**: ele só aponta para a pasta `ml-baileys-service`
  que já existe dentro do sistema. Quando o sistema é atualizado, o WhatsApp é
  atualizado junto;
- **pode ter qualquer nome**: é configurável no sistema (Passo 5). Serve um
  subdomínio do domínio temporário do Plesk agora e outro nome depois, na produção.

> O Composer não é usado aqui. O serviço já vem pronto num arquivo só
> (`ml-baileys-service/dist/server.cjs`), sem "npm install".

## Antes de começar

- O painel precisa ter a opção **Node.js** (versão 20 ou mais nova). No Plesk é
  o ícone **Node.js** dentro do domínio. Se não aparecer, peça à hospedagem:
  *"Ativar a extensão Node.js do Plesk (versão 20 ou mais nova) na minha assinatura."*
- Descubra a **pasta do sistema** no Plesk: em **Sites e Domínios**, no domínio
  do sistema, veja a "Raiz do documento" (normalmente `httpdocs`, ou algo como
  `/sistema.seudominio.com.br` quando o sistema é um subdomínio). A pasta do
  WhatsApp é essa + `/ml-baileys-service`.

---

## Passo 1 — Atualizar o sistema

Atualize o sistema como você já faz (a branch `feature/baileys-migracao`, ou a
principal depois do merge). A pasta `ml-baileys-service` vem junto.

## Passo 2 — Criar o endereço do WhatsApp

No Plesk, **na mesma assinatura do sistema** (o mesmo cliente/assinatura onde o
sistema está): **Sites e Domínios → Adicionar subdomínio**.

| Campo | O que colocar |
|---|---|
| Nome do subdomínio | o que quiser. Sugestão: `whatsapp` |
| Domínio pai | qualquer domínio dessa assinatura. Hoje: o domínio temporário do Plesk. Depois: o de produção. |
| **Raiz do documento** | a pasta do sistema + `/ml-baileys-service/public`. Ex.: `/httpdocs/ml-baileys-service/public` (pode deixar a que o Plesk sugere agora e trocar no Passo 3). |

Não precisa colocar código nem fazer deploy nesse subdomínio: a pasta
`ml-baileys-service/public` já vem no código do sistema.

Depois, no subdomínio: **SSL/TLS → Let's Encrypt → Proteger** (certificado grátis,
um clique). Se o domínio temporário do Plesk não aceitar o certificado, pode
seguir sem: o sistema também funciona com `http://`.

Anote o endereço completo criado, por exemplo
`https://whatsapp.seu-dominio-temporario.plesk.page`.

## Passo 3 — Ligar o Node.js nesse endereço

No subdomínio criado, clique em **Node.js** e preencha:

| Campo | Valor |
|---|---|
| Versão do Node.js | **20** ou mais nova (22 se tiver) |
| Modo do aplicativo (Application Mode) | `production` |
| **Raiz do aplicativo** (Application Root) | a pasta do sistema + `/ml-baileys-service`. Ex.: `/httpdocs/ml-baileys-service` |
| **Raiz do documento** (Document Root) | a raiz do aplicativo + `/public`. Ex.: `/httpdocs/ml-baileys-service/public`. **Precisa** ficar dentro da raiz do aplicativo, senão o Plesk avisa "o documento raiz está fora do diretório raiz do aplicativo" e o app não sobe. |
| **Arquivo de inicialização** (Application Startup File) | `app.js` |

Clique em **Ativar Node.js** (Enable Node.js). Se já estava ativado, clique em
**Reiniciar aplicativo** depois de corrigir a raiz do documento.

- **Não** precisa clicar em "NPM install" (se clicar por engano, não estraga nada).
- **Não** precisa preencher variáveis de ambiente.

> Se o Plesk não deixar escolher a pasta do sistema como "Raiz do aplicativo",
> o subdomínio foi criado em outra assinatura. Veja "Node.js em outra assinatura"
> no fim do guia.

## Passo 4 — Conferir o endereço do sistema

No sistema: **Configurações → Operacional**, campo **"App - Base URL pública"**.
Precisa ser o endereço pelo qual o sistema é acessado **hoje** (o temporário,
enquanto estiver nele). Ex.: `https://seu-dominio-temporario.plesk.page`.

É para esse endereço (+ `/chat_webhook.php`) que o WhatsApp manda as mensagens
recebidas.

## Passo 5 — Informar o endereço do WhatsApp no sistema

No sistema: **Configurações → WhatsApp Conexão → Credenciais**.

| Campo | O que colocar |
|---|---|
| **Endereço do serviço** | o endereço do Passo 2, completo. Ex.: `https://whatsapp.seu-dominio-temporario.plesk.page`. (Vazio só funciona se o nome for exatamente `whatsapp.` + o endereço do sistema.) |
| Token (API Key) | deixe como está (o sistema gera sozinho) |
| Endereço que recebe as mensagens (webhook) | deixe vazio (usa o do Passo 4). Só preencha se precisar de outro. |
| Nome da Instância | o WhatsApp padrão das mensagens automáticas |
| Guardar o histórico das mensagens por (dias) | `0` = para sempre (padrão) |

Clique em **Salvar**. Ao salvar e ao abrir essa tela, o sistema:
1. gera a chave de acesso do serviço;
2. entrega ao serviço os dados do banco, a chave, o endereço do webhook e o prazo
   do histórico (arquivo `ml-baileys-service/runtime-config.php`, que ninguém
   precisa abrir; ele não aparece pelo navegador e não vai para o git);
3. liga o serviço e confere se ele responde.

O quadro no topo da tela mostra a situação:

| Quadro | Significado |
|---|---|
| ✅ **Serviço WhatsApp no ar** | Pronto. Siga para o Passo 6. |
| ⏳ **Serviço WhatsApp iniciando** | Ligando (até 1 minuto na primeira vez). Recarregue a página. |
| ⚠️ **Serviço WhatsApp sem resposta** | Veja "Se algo não funcionar" abaixo. |

## Passo 6 — Conectar os números

Em **Configurações → WhatsApp Conexão → Instâncias** (ou **Meu WhatsApp**):
**Reconectar** → ler o QR Code com o celular do número.

- Cada número precisa ser conectado de novo **uma vez** (a conexão da Evolution
  não serve no Baileys). Antes, no celular, apague o aparelho antigo em
  **Aparelhos conectados**.
- Sem câmera por perto: **Webhook Debug → "Reconectar WhatsApp travado"**,
  informe o número e use o **código de pareamento** (8 letras).

Pronto. Mensagens, captação, reações e avisos funcionam como antes.

---

## Quando mudar para o domínio de produção

Nada é fixo no código. Na troca:

1. Crie o subdomínio do WhatsApp no domínio de produção e ligue o Node.js nele
   (Passos 2 e 3, com a mesma pasta `ml-baileys-service` e a raiz do documento
   `ml-baileys-service/public`). Antes, desative o Node.js do endereço antigo
   (**Node.js → Desabilitar Node.js**): o Plesk não deixa duas raízes do documento
   iguais, e dois serviços ligados ao mesmo tempo não são necessários.
2. No sistema: **"App - Base URL pública"** com o endereço novo do sistema e
   **Endereço do serviço** com o endereço novo do WhatsApp. Salve.

Ao salvar, o sistema grava o endereço novo do webhook em todos os números
conectados. Os números **não** precisam ler o QR Code de novo: a sessão fica no
banco, e o serviço novo reconecta sozinho.

---

## O que o sistema faz sozinho daqui para frente

- **Mantém o serviço ligado.** A cada minuto (cron da fila e telas abertas) o
  sistema acessa o serviço. Se o servidor da hospedagem reiniciar, o primeiro
  acesso liga o serviço de novo e ele reconecta todos os números sem QR Code.
- **Atualização.** Quando uma versão nova do sistema chega, o serviço percebe em
  até 1 minuto, desliga e volta já na versão nova. Ninguém precisa clicar em
  "Reiniciar".
- **Um processo só.** Se o painel abrir dois processos do serviço ao mesmo
  tempo, só um conecta os números (dois conectados derrubariam a sessão).
- **Histórico das mensagens: guardado para sempre** (padrão). Nada é apagado.
  Para usar um prazo, preencha os dias em Credenciais. Isso vale para a cópia que
  o serviço guarda (mídia antiga e botão **Sincronizar**); as conversas do chat
  do sistema não são afetadas.

## Onde acompanhar (tudo pelo navegador)

| Tela | Para quê |
|---|---|
| Configurações → WhatsApp Conexão → Credenciais | Situação do serviço |
| Configurações → WhatsApp Conexão → Instâncias | Situação de cada número (Conectado / Suspeita de queda / Aguardando QR) |
| **`/admin_whatsapp_eventos.php`** | **Eventos do WhatsApp**: log detalhado de tudo (ver abaixo) |
| `/admin_whatsapp_monitor.php` | Gráfico de 72 h de cada número e log do serviço |
| `/admin_webhook_debug.php` | Webhook de cada número e o que chegou |

### Eventos do WhatsApp (`/admin_whatsapp_eventos.php`)

Uma linha para cada coisa que acontece, gravada pelo **serviço** (WhatsApp) e pelo
**sistema** (o que ele fez com cada evento):

- **Agora, por número** (topo): o que está parado e o motivo. Desconectado (com o
  código), aguardando QR Code, conexão "muda", sem receber há muito tempo,
  mensagens que chegam no serviço mas não no sistema, entregas ao sistema que
  falharam, envios sem confirmação do WhatsApp, mensagens que não puderam ser
  lidas (criptografia), mídias perdidas e quedas nas últimas 24 h.
- **Filtros**: período, número, nível (erros, avisos...), categoria (conexão,
  recebidas, enviadas, reações, captação, entrega ao sistema, mídia, grupos...),
  origem, busca por texto e **id da mensagem ou conversa**.
- **Viagem da mensagem**: clique no `msg ...` de uma linha para ver, do mais antigo
  ao mais novo, tudo o que aconteceu com ela. Exemplo: recebida no serviço →
  entregue ao sistema → colocada na fila → gravada no chat. Para uma reação, a
  viagem mostra também se virou interesse na captação ou por que não virou.
- **O que mais deu problema no período**: os erros que mais se repetem.
- **Baixar planilha**: os eventos filtrados num arquivo CSV (para mandar ao suporte).
- **Atualizar sozinho**: recarrega a cada 15 s (não recarrega com um detalhe aberto).

Em **Configurações → WhatsApp Conexão → Credenciais**:
- **Guardar o log de eventos por (dias)**: padrão 90. 0 = para sempre. As
  mensagens não são apagadas por isso.
- **Gravar também os eventos de detalhe**: ligado por padrão. Registra cada
  entrega, confirmação, gravação no chat e mídia baixada. Desligue só se o banco
  crescer demais.

---

## Se algo não funcionar

| O que aparece | O que fazer |
|---|---|
| "sem resposta" com **Could not resolve host** | O endereço digitado em **Endereço do serviço** não existe. Confira o nome do subdomínio (Passo 2). |
| "sem resposta" com **HTTP 404 / 403** ou página do Plesk | O Node.js não está ativado nesse subdomínio, ou a raiz do aplicativo está errada (Passo 3). |
| Plesk: "documento raiz está fora do diretório raiz do aplicativo" e "Cannot find module .../app.js" | A raiz do documento precisa ser `.../ml-baileys-service/public` (Passo 3). Corrija e clique em **Reiniciar aplicativo**. |
| "sem resposta" com **HTTP 502 / 503** | O app não conseguiu iniciar. No painel: **Node.js → versão 20+** e arquivo `app.js`. O log do painel (Node.js → Logs) mostra o motivo. |
| "o endereço não é o serviço WhatsApp" | O endereço abre outro site. Confira o **Endereço do serviço** e a raiz do aplicativo. |
| "iniciando: aguardando configuração" por mais de 2 min | Abra de novo **Credenciais** e salve. Se continuar, a pasta `ml-baileys-service` não tem permissão de escrita: peça à hospedagem para dar permissão de escrita ao usuário do site nessa pasta. |
| "Último erro: banco: ..." | O serviço não conseguiu entrar no banco do sistema. Peça à hospedagem: *"liberar conexão MySQL por 127.0.0.1 para o usuário do banco do site"*. |
| Mensagens não chegam no chat | **"App - Base URL pública"** precisa ser o endereço atual do sistema. Depois, Webhook Debug → **Aplicar padrão em todos**. |
| Número em "Suspeita de queda" | Use **Reconectar (sem QR)**. Se não resolver, ler o QR Code de novo. |

---

## Node.js em outra assinatura (ou outro servidor)

Se o endereço do WhatsApp precisar ficar numa assinatura diferente da do sistema,
o Plesk não deixa apontar para a pasta do sistema. Nesse caso:

1. Nessa outra assinatura, publique **o mesmo repositório e a mesma branch**
   (Git do Plesk), só para ter a pasta `ml-baileys-service`.
2. No Node.js, a raiz do aplicativo é a `ml-baileys-service` dessa cópia e o
   arquivo é `app.js`.
3. Em **Node.js → Variáveis de ambiente personalizadas**, crie as variáveis do
   banco do sistema. A tela **Credenciais** mostra os valores prontos
   (seção "O Node.js está em outra assinatura ou servidor?"). Só a senha você
   digita: é a mesma do banco do sistema.
4. Siga os Passos 4 a 6. A chave, os endereços e o prazo do histórico o serviço
   lê sozinho do banco do sistema.

Se a hospedagem não tiver Node.js de jeito nenhum, o serviço precisa rodar em
outro servidor (por exemplo a VPS onde a Evolution roda hoje), instalado por quem
tem acesso a ele, seguindo `ml-baileys-service/README.md`.

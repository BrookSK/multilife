# Como deixar o WhatsApp (Baileys) funcionando na hospedagem

Guia para quem só tem acesso ao **painel da hospedagem** (Plesk) e ao sistema.
Não precisa rodar comando, instalar pacote nem editar arquivo. São ajustes feitos
**uma vez** no painel; depois disso é só entrar no sistema e conectar o WhatsApp.

## Antes de começar: o que a hospedagem precisa ter

O WhatsApp agora roda num programa **Node.js** que fica ligado o tempo todo
(pasta `ml-baileys-service` do sistema). O PHP sozinho não consegue manter a
conexão do WhatsApp aberta. Então o painel precisa ter a opção **Node.js**:

- **Plesk:** no domínio, procure o ícone **Node.js**. Se não aparecer, peça à
  hospedagem: *"Ativar a extensão Node.js do Plesk (versão 20 ou mais nova) na
  minha assinatura."*
- **cPanel:** o nome da opção é **Setup Node.js App**.

Se a hospedagem não tiver Node.js de jeito nenhum, veja o fim deste guia.

> O Composer não é usado aqui. O serviço já vem pronto num arquivo só
> (`ml-baileys-service/dist/server.cjs`), sem "npm install".

---

## Passo 1 — Atualizar o sistema

Atualize o sistema como você já faz (puxar a branch `feature/baileys-migracao`,
ou a branch principal depois do merge). A pasta `ml-baileys-service` vem junto.

## Passo 2 — Criar o subdomínio `whatsapp`

O sistema procura o serviço sozinho em **`whatsapp.` + o endereço do sistema**.

| Endereço do sistema | Subdomínio a criar |
|---|---|
| `https://multilifecare.com.br` | `whatsapp.multilifecare.com.br` |
| `https://sistema.multilifecare.com.br` | `whatsapp.sistema.multilifecare.com.br` |

No Plesk: **Sites e Domínios → Adicionar subdomínio**.
- Nome: `whatsapp` (ou `whatsapp.sistema`, conforme a tabela).
- **Raiz do documento:** deixe a pasta que o Plesk sugere (algo como
  `/whatsapp.multilifecare.com.br`). **Não** use a pasta do sistema (`httpdocs`).

Depois, ainda no subdomínio: **SSL/TLS → Let's Encrypt → Proteger** (certificado
grátis, um clique). Sem certificado também funciona (o sistema tenta `http`
sozinho), mas com ele a comunicação fica protegida.

## Passo 3 — Ligar o Node.js no subdomínio

No subdomínio `whatsapp`, clique em **Node.js** e preencha:

| Campo | Valor |
|---|---|
| Versão do Node.js | **20** ou mais nova (22 se tiver) |
| Modo do aplicativo (Application Mode) | `production` |
| **Raiz do aplicativo** (Application Root) | a pasta do sistema + `/ml-baileys-service`. Ex.: `/httpdocs/ml-baileys-service` |
| Raiz do documento (Document Root) | deixe a do subdomínio (a do Passo 2) |
| **Arquivo de inicialização** (Application Startup File) | `app.js` |

Clique em **Ativar Node.js** (Enable Node.js).

**Não** clique em "NPM install": não é necessário (e se clicar por engano, não estraga nada).
Não precisa preencher variáveis de ambiente.

> cPanel ("Setup Node.js App"): *Application root* = `public_html/ml-baileys-service`
> (ou a pasta do sistema), *Application URL* = o subdomínio `whatsapp`,
> *Application startup file* = `app.js`, versão 20+. Clique em **Create**.

## Passo 4 — Conferir o endereço do sistema

No sistema: **Configurações → Operacional**, campo **"App - Base URL pública"**
(`app.public_base_url`), por exemplo `https://multilifecare.com.br`.
É dele que o sistema tira o endereço do WhatsApp e do webhook. Normalmente já está
preenchido.

## Passo 5 — Abrir a tela do WhatsApp no sistema

No sistema: **Configurações → WhatsApp Conexão → Credenciais**.

Ao abrir essa tela, o sistema faz sozinho:
1. gera a chave de acesso do serviço;
2. entrega ao serviço os dados do banco, a chave, o endereço do webhook e o prazo
   de histórico (arquivo `ml-baileys-service/runtime-config.php`, que ninguém
   precisa abrir; ele não aparece pelo navegador e não vai para o git);
3. liga o serviço e confere se ele responde.

O quadro no topo mostra a situação:

| Quadro | Significado |
|---|---|
| ✅ **Serviço WhatsApp no ar** | Pronto. Siga para o Passo 6. |
| ⏳ **Serviço WhatsApp iniciando** | Ligando (até 1 minuto na primeira vez). Recarregue a página. |
| ⚠️ **Serviço WhatsApp sem resposta** | Veja "Se algo não funcionar" abaixo. |

Os campos **Endereço do serviço** e **Token (API Key)** podem ficar como estão:
vazio = automático.

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

## O que o sistema faz sozinho daqui para frente

- **Mantém o serviço ligado.** A cada minuto (cron da fila e telas abertas) o
  sistema acessa o serviço. Se o servidor da hospedagem reiniciar, o primeiro
  acesso liga o serviço de novo e ele reconecta todos os números sem QR Code.
- **Atualização.** Quando uma versão nova do sistema chega (git pull), o serviço
  percebe em até 1 minuto, desliga e volta já na versão nova. Ninguém precisa
  clicar em "Reiniciar".
- **Um processo só.** Se o painel abrir dois processos do serviço ao mesmo
  tempo, só um conecta os números (dois conectados derrubariam a sessão).
- **Histórico das mensagens: guardado para sempre** (padrão). Nada é apagado.

### Prazo do histórico (opcional)

**Configurações → WhatsApp Conexão → Credenciais → "Guardar o histórico das
mensagens no serviço por (dias)"**.
- `0` = **para sempre** (padrão).
- Um número (ex.: `365`) = o serviço apaga as mensagens mais antigas que isso.

Isso vale para a cópia que o serviço guarda, usada para baixar mídia antiga e
para o botão **Sincronizar**. As conversas do chat do sistema não são afetadas.

---

## Onde acompanhar (tudo pelo navegador)

| Tela | Para quê |
|---|---|
| Configurações → WhatsApp Conexão → Credenciais | Situação do serviço |
| Configurações → WhatsApp Conexão → Instâncias | Situação de cada número (Conectado / Suspeita de queda / Aguardando QR) |
| `/admin_whatsapp_monitor.php` | Gráfico de 72 h de cada número e **log do serviço** |
| `/admin_webhook_debug.php` | Webhook de cada número e o que chegou |

---

## Se algo não funcionar

| O que aparece | O que fazer |
|---|---|
| "sem resposta" com **Could not resolve host** | O subdomínio `whatsapp` não existe ou o nome está diferente (Passo 2). Se usou outro nome, escreva o endereço completo em **Endereço do serviço**. |
| "sem resposta" com **HTTP 404 / 403** ou página do Plesk | O Node.js não está ativado no subdomínio, ou a raiz do aplicativo está errada (Passo 3). |
| "sem resposta" com **HTTP 502 / 503** | O app não conseguiu iniciar. No painel, **Node.js → versão 20+** e arquivo `app.js`. O log do painel (Node.js → Logs) mostra o motivo. |
| "o endereço não é o serviço WhatsApp" | O endereço abre outro site. Confira a raiz do aplicativo (Passo 3). |
| "iniciando: aguardando configuração" por mais de 2 min | Abra de novo **Configurações → WhatsApp Conexão → Credenciais** (é essa tela que entrega a configuração). Se continuar, a pasta `ml-baileys-service` não tem permissão de escrita: peça à hospedagem para dar permissão de escrita ao usuário do site nessa pasta. |
| "Último erro: banco: ..." | O serviço não conseguiu entrar no banco do sistema. Peça à hospedagem: *"liberar conexão MySQL por 127.0.0.1 para o usuário do banco do site"*. |
| Número em "Suspeita de queda" | Use **Reconectar (sem QR)**. Se não resolver, ler o QR Code de novo. |

---

## Se a hospedagem não tiver Node.js

Aí o serviço precisa rodar em outro servidor (por exemplo a VPS onde a Evolution
roda hoje). Quem tem acesso a essa VPS instala uma vez, seguindo
`ml-baileys-service/README.md` (sem `npm install`: basta a pasta e o pm2).
Depois, no sistema, em **Credenciais**, preencha **Endereço do serviço** e
**Token (API Key)** com os dados que essa pessoa passar. O resto (Passos 5 e 6)
é igual.

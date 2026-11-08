# Roteiro de testes antes da apresentação

Siga na ordem. Marque cada item com ✅ ou ❌. Se der ❌, anote o horário, o card e o
usuário e veja a seção "Se algo falhar" no fim.

Tempo estimado: 1h30 com duas pessoas, uma no computador e outra com o celular de
um profissional de teste.

---

## 0. Antes de começar (5 min)

1. Entre como **admin** e abra no navegador:
   `https://SEU-DOMINIO/testes/stress/diagnostico.php?wa_rounds=10`
   Espere terminar (1 a 2 minutos). No fim aparece **RESUMO**.
   - ✅ Esperado: "Nenhum problema bloqueante encontrado". Avisos podem aparecer; leia cada um.
   - Se aparecer "oscilou entre estados", o WhatsApp está em loop. Resolva isso antes de tudo (seção 1).
2. Confira em **Configurações > Funcionalidades** se o **modo de teste da captação** está LIGADO.
   Durante os testes ele deve ficar ligado, para não mandar mensagem a profissionais de verdade.
3. Tenha à mão: o celular de pelo menos 1 profissional de teste e os logins da Grace,
   da Tânia e do admin.

## 1. WhatsApp: conectar, desconectar e reconectar (15 min)

⚠️ **Não faça isso muitas vezes seguidas.** Conectar e desconectar o mesmo número
várias vezes em poucos minutos pode fazer o WhatsApp bloquear o número. Faça no
máximo **3 ciclos por número**, com pelo menos **2 minutos** entre eles. Use de
preferência o número de teste, não o do Financeiro.

| # | Ação | Resultado esperado | ✅/❌ |
|---|------|--------------------|------|
| 1.1 | Em Gerenciar WhatsApp, desconecte o número de teste | Status muda para desconectado em até 1 min. Aparece o aviso (faixa) de WhatsApp com problema no topo | |
| 1.2 | Com ele desconectado, tente mandar uma mensagem pelo Chat ao Vivo com o usuário dele | Aparece **erro na tela**. A mensagem **não** sai por outro número | |
| 1.3 | Gere o QR Code e conecte de novo | Conecta. A faixa de aviso some em até 1 a 2 min | |
| 1.4 | Mande uma mensagem e receba uma resposta do celular | As duas aparecem no chat, no WhatsApp certo | |
| 1.5 | Repita 1.1 a 1.3 mais uma vez, 2 min depois | Mesmo resultado, sem ficar em "conectando" para sempre | |
| 1.6 | Rode o diagnóstico de novo (passo 0.1) | Nenhum WhatsApp "oscilou entre estados" | |

## 2. Captação em modo de teste (25 min)

Use os cards do arquivo `testes/seed_cards_cenarios_captacao.sql` ou crie cards novos.

| # | Cenário | Como fazer | Resultado esperado | ✅/❌ |
|---|---------|-----------|--------------------|------|
| 2.1 | Card **com** profissional de teste na cidade | Abra o card e clique em **Realizar Captação** | O acompanhamento aparece embaixo, passa pelas fases e termina em "Captação enviada". O grupo é criado ou reutilizado e a mensagem chega no celular de teste | |
| 2.2 | Acompanhamento sobrevive à navegação | Durante o 2.1, mude de página e depois recarregue (F5) | O acompanhamento continua aparecendo até terminar | |
| 2.3 | Card **sem** profissional na cidade, **com** no estado (ex.: Campinas/SP) | Realizar Captação | Abre a janela **"Captação não enviada"** com o botão **"Ampliar para o estado e captar (N)"**. Nada foi enviado | |
| 2.4 | Mesmo card do 2.3 | Clique em **Cancelar** | Nada é enviado e nenhum grupo é criado | |
| 2.5 | Mesmo card do 2.3 | Clique em **Ampliar para o estado e captar** | Envia. A mensagem final diz "Ampliada para os profissionais do estado SP" | |
| 2.6 | Card **sem ninguém** no estado (ex.: uma cidade de outra UF) | Realizar Captação | A janela avisa que não há ninguém e só oferece **Fechar** | |
| 2.7 | Duplo clique | Clique 2 vezes rápido em Realizar Captação num card válido | Só **uma** captação é feita (a segunda diz "já está em andamento") | |
| 2.8 | Várias seguidas | Dispare 3 cards diferentes com 10 s de intervalo | As 3 terminam. Nenhuma fica presa em "Enviando" por mais de 5 min | |
| 2.9 | Card com dados faltando | Abra um card sem cidade ou especialidade | Mostra o que falta e o botão "Completar dados do card". Depois de completar, a captação funciona | |
| 2.10 | Profissional não vira admin | No celular de teste, abra o grupo | O profissional de teste **não** é admin do grupo. Só a equipe MultiLife é | |

## 3. Reações, Lista de Espera e Chat (20 min)

| # | Ação | Resultado esperado | ✅/❌ |
|---|------|--------------------|------|
| 3.1 | No celular de teste, reaja (👍) à mensagem da captação no grupo | Em até 1 min o profissional aparece na **Lista de Espera** (ícone de relógio no Chat ao Vivo) | |
| 3.2 | Logado como **Grace**, clique no profissional na Lista de Espera | Abre a conversa **no WhatsApp da Grace**. Não aparecem mensagens do Financeiro | |
| 3.3 | Grace manda uma mensagem de texto | Chega no celular **vinda do número da Grace** | |
| 3.4 | Grace manda **áudio** (microfone), **imagem** e **documento PDF** | Os 3 chegam pelo número da Grace e aparecem no chat | |
| 3.5 | O profissional responde pelo celular | A resposta aparece para a Grace em poucos segundos, sem recarregar | |
| 3.6 | Logado como **Tânia**, mande mensagem para o mesmo profissional pelo Financeiro | Chega vinda do número do Financeiro | |
| 3.7 | Logada como Tânia, abra a conversa | Ela vê **só** o histórico do Financeiro. O profissional aparece na lista dela | |
| 3.8 | Logada como Grace de novo | O profissional continua na lista da Grace, com o histórico dela. Não aparece a mensagem da Tânia | |
| 3.9 | Logado como **Maria** (usa o mesmo WhatsApp da Grace) | Vê as mesmas conversas da Grace | |
| 3.10 | Logado como **admin**, com "Todos WhatsApp" | Vê o profissional com as mensagens dos dois WhatsApps. Ao filtrar por um WhatsApp, vê só aquele | |
| 3.11 | Não lidas | Profissional manda mensagem para os dois números. A Grace abre a conversa dela | O contador da Tânia para esse contato **continua** com as não lidas dela | |
| 3.12 | Selecionar profissional | No chat, em "Atribuir paciente", escolha o card e clique em **Selecionar Profissional** | O card avança para a próxima etapa | |

## 4. Autorização → Pré-admissão → Monitoramento → Financeiro (20 min)

| # | Ação | Resultado esperado | ✅/❌ |
|---|------|--------------------|------|
| 4.1 | Abra a Autorização do card do passo 3.12 e registre a resposta da operadora (aprovada, com valor) | O card fica "autorização aprovada" e o valor aparece | |
| 4.2 | Faça uma autorização **negada** em outro card | O card fica "autorização negada" | |
| 4.3 | Pré-admissão: preencha frequência, valor combinado por sessão e datas | Salva sem erro. O valor total é recalculado | |
| 4.4 | Confirme a admissão | O card vai para "admitido" e o paciente entra no Monitoramento | |
| 4.5 | Monitoramento: registre um atendimento ou evolução | Aparece no histórico do paciente | |
| 4.6 | Financeiro: veja o fechamento do profissional | O valor bate com sessões × valor combinado | |
| 4.7 | Envie a mensagem automática de faturamento (pelo Financeiro) para o profissional de teste | Chega **pelo número do Financeiro** e aparece só no chat da Tânia | |
| 4.8 | Zoho: abra a integração do Financeiro | A instância "Financeiro" (ML-Tânia) aparece selecionada e não some ao salvar | |

## 5. E-mail (10 min)

| # | Ação | Resultado esperado | ✅/❌ |
|---|------|--------------------|------|
| 5.1 | No diagnóstico (passo 0.1), seção 6 | "[ok] login em ..." | |
| 5.2 | Mande um e-mail de teste para a caixa de demandas | Em até 2 a 3 min ele é lido pelo sistema (veja os e-mails recebidos ou os cards novos) | |
| 5.3 | Em Modelos de e-mail, abra, edite e salve um modelo | Salva sem erro | |
| 5.4 | Envie um documento por e-mail (Documentos > Enviar) para um e-mail seu | O e-mail chega. Ele aparece em "Documentos enviados" e o botão "Reenviar" funciona | |

## 6. Perfis de acesso (10 min)

| # | Login | Verificar | ✅/❌ |
|---|-------|-----------|------|
| 6.1 | Captador (Grace) | Vê os cadastros, cria card, capta. **Não** vê Configurações, Usuários/Perfis nem o WhatsApp do Financeiro | |
| 6.2 | Captador | No "+" de cadastro rápido, só aparece Profissional e Paciente | |
| 6.3 | Profissional de teste (se o portal estiver ativo) | **Não** tem acesso de admin nem ao Chat | |
| 6.4 | Admin | Vê tudo, inclusive todas as conversas | |
| 6.5 | Sessão expirada | Deixe uma aba parada além do tempo de sessão e clique em algo | Volta ao login com "Sessão expirada", sem tela branca | |

## 7. Carga: vários usuários ao mesmo tempo (10 min, fora do horário de uso)

Num computador com PHP, rode:

```
set ML_EMAIL=email_do_admin
set ML_PASS=senha_do_admin
php testes/stress/http_load.php https://SEU-DOMINIO --users=5 --rounds=3
php testes/stress/http_load.php https://SEU-DOMINIO --users=15 --rounds=3
```

- ✅ Esperado: "OK: nenhuma falha". Tempo médio abaixo de 3 s e p95 abaixo de 6 s.
- Se a coluna de erros mostrar "sessão caiu" ou "HTTP 500", anote a página e me envie o arquivo de `testes/stress/_resultados/`.
- Ao mesmo tempo, abra o sistema no navegador e veja se continua usável.

## Se algo falhar

1. **Tire print** da tela e anote: horário, usuário logado, número do card.
2. **WhatsApp em loop ou "conectando" sem parar:** desconecte, espere 2 min, apague a
   sessão no celular (Aparelhos conectados) e gere um QR novo. Não fique tentando várias vezes seguidas.
3. **Captação presa em "Enviando" há mais de 5 min:** o acompanhamento marca como falha
   sozinho. Veja a seção 5 do diagnóstico ("Captações paradas"). Se houver jobs esperando
   há mais de 15 min, o cron do worker no servidor parou.
4. **Tela branca ou erro 500:** é erro de PHP. Peça o log de erros do servidor (error_log)
   do horário anotado.
5. **No dia da apresentação:** deixe o modo de teste **ligado** se for demonstrar a captação, para
   não disparar para profissionais de verdade na frente do cliente.

# Relatório dos testes (27/10/2026)

## O que foi executado aqui, no computador de desenvolvimento

| Teste | Resultado |
|-------|-----------|
| Sintaxe PHP (`php -l`) nos 679 arquivos `.php` | 1 erro encontrado e corrigido |
| Varredura de consultas SQL com placeholder repetido (`static_scan.php`) | 3 encontradas e corrigidas; nova varredura: 0 |
| Banco local montado só com as migrações, para testar o diagnóstico | O banco gerado ficou incompleto (480 comandos das migrações antigas falham num banco vazio, e várias colunas são criadas pelo próprio sistema em uso). Por isso ele **não serve** como cópia do banco real. O diagnóstico rodou nele, e isso serviu para deixar o script resistente a colunas faltando. O banco local de teste foi apagado depois |
| Banco real, WhatsApp real, e-mail real | **Não testados daqui.** O banco de produção não aceita conexão deste computador. Esses testes ficam com `diagnostico.php` no servidor e com o roteiro manual |

## Bugs encontrados e corrigidos

1. **`admin_documents_sent.php` linha 349: erro de sintaxe.** A página "Documentos enviados"
   não abria (tela branca). A string `\\'` fechava a string PHP antes da hora. Corrigido para `\'`.
2. **`chat_save_capture_info.php` linha 63:** o `:uid` repetido no INSERT fazia
   **falhar ao salvar as informações de captação pela primeira vez** num contato
   ("Salvar Informações" no painel do chat). Com `ATTR_EMULATE_PREPARES` desligado
   (`app/db.php`), o PDO não aceita o mesmo placeholder duas vezes. Trocado por `:uid` e `:uid2`.
3. **`admin_whatsapp_instance_link_post.php` linha 32:** o mesmo problema, ao vincular
   um WhatsApp que ainda não estava cadastrado no banco.
4. **`app/notification_guard.php` linha 133:** o mesmo problema (`:p` três vezes). A checagem
   "paciente bloqueado para notificação" pelo telefone sempre dava erro.
5. **`login_post.php` (segurança):** o login `contato@multilifecare.com.br` / `admin123`
   **redefinia a senha dessa conta e entrava como admin a qualquer momento**, mesmo que a
   senha tivesse sido trocada. Agora esse atalho só funciona numa instalação nova, sem
   nenhum admin ativo. Quem já usa essa conta com a senha atual continua entrando normalmente.

## Ferramentas criadas (`testes/stress/`)

| Arquivo | Para quê | Onde rodar |
|---------|----------|------------|
| `diagnostico.php` | Só leitura. Confere banco, estado dos WhatsApps (N consultas seguidas, detecta loop e lentidão), o que cada usuário vê no chat (roda a mesma consulta da lista), profissionais por card aberto (cidade/estado), captações presas, jobs atrasados e login no IMAP | No servidor: navegador logado como admin, ou `php testes/stress/diagnostico.php --wa-rounds=10` |
| `http_load.php` | Carga: N usuários simultâneos abrindo as telas principais. Mede média, p95 e erros (500, erro PHP, sessão caindo). Só faz GET | Em qualquer PC com PHP, apontando para o domínio |
| `static_scan.php` | Procura consultas com placeholder repetido (o tipo de bug que causou o "Enviando" travado antes) | Aqui, antes de subir código |
| `ROTEIRO_TESTES.md` | Checklist manual completo: WhatsApp, captação, chat por WhatsApp, autorização até o financeiro, e-mail, perfis, carga | Equipe, no sistema |

## O que ficou para teste manual (e por quê)

- **Conectar e desconectar WhatsApp várias vezes:** automatizar isso pode fazer o WhatsApp
  bloquear o número. O roteiro limita a 3 ciclos com 2 min de intervalo.
- **Envios reais (WhatsApp e e-mail), reações no grupo, fluxo até o financeiro:** precisam
  do servidor e do celular de teste. Estão na ordem no `ROTEIRO_TESTES.md`.

## Limitações conhecidas (não corrigidas agora)

- O status da conversa (Em captação, Concluído), o atendente e o nome personalizado do
  contato são **um por contato**, não um por WhatsApp. Se a Grace concluir a conversa,
  ela aparece concluída para a Tânia também.
- A varredura de placeholders só analisa SQL escrito direto no `prepare(...)`. Consultas
  montadas em variáveis antes não são cobertas.

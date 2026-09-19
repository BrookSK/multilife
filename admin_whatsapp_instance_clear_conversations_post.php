<?php

declare(strict_types=1);

/**
 * Limpa as conversas/mensagens vinculadas EXCLUSIVAMENTE a uma instância WhatsApp.
 *
 * Contexto (reunião 15/09): ao limpar uma instância desconectada, a limpeza antiga
 * (TRUNCATE global) apagava conversas de TODAS as instâncias. Aqui o escopo é
 * ISOLADO: o filtro é sempre `WHERE instance_name = :instance`, então as demais
 * instâncias e suas conversas permanecem intactas.
 *
 * Segurança: só permite limpar instância DESCONECTADA/INATIVA (critério oficial do
 * projeto: connection_status = 'disconnected' OU status = 'inactive'). Isso evita
 * apagar por engano o histórico de uma instância que ainda está conectada.
 *
 * POST: instance (nome da instância)
 */

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp.manage');

// A tela de Instâncias (admin_settings.php) chama este endpoint via fetch (AJAX)
// e espera JSON. O acesso direto por formulário usa flash + redirect.
$isAjax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

$instance = trim((string)($_POST['instance'] ?? ''));
$backToView = '/admin_whatsapp_instance_view.php?instance=' . urlencode($instance);

/**
 * Encerra a requisição respondendo em JSON (AJAX) ou via flash + redirect.
 */
$respond = static function (bool $ok, string $message, string $redirect) use ($isAjax): void {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => $ok, ($ok ? 'message' : 'error') => $message]);
        exit;
    }
    flash_set($ok ? 'success' : 'error', $message);
    header('Location: ' . $redirect);
    exit;
};

if ($instance === '') {
    $respond(false, 'Instância inválida.', '/admin_whatsapp_instances.php');
}

$db = db();

// Verificar o estado da instância no banco de rastreamento.
// Só liberamos a limpeza se ela estiver EXPLICITAMENTE desconectada/inativa.
$connStatus = null;
$recStatus = null;
$known = false;
try {
    $stmt = $db->prepare('SELECT status, connection_status FROM whatsapp_instances WHERE instance_name = :i LIMIT 1');
    $stmt->execute(['i' => $instance]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $known = true;
        $recStatus = (string)($row['status'] ?? '');
        $connStatus = (string)($row['connection_status'] ?? '');
    }
} catch (Throwable $e) {
    // Se a tabela de rastreamento não existir, seguimos com a checagem best-effort abaixo.
    $known = false;
}

// Regra de desconexão (mesma do CLEANUP oficial): disconnected OU inactive.
// Se a instância é conhecida e NÃO está desconectada, bloqueia por segurança.
if ($known) {
    $isDisconnected = ($connStatus === 'disconnected') || ($recStatus === 'inactive');
    if (!$isDisconnected) {
        $respond(false, 'A instância "' . $instance . '" não está desconectada (status: '
            . ($connStatus !== '' ? $connStatus : 'desconhecido')
            . '). A limpeza só é permitida para instâncias desconectadas.', $backToView);
    }
}

try {
    $db->beginTransaction();

    // 1) Mensagens da instância (escopo isolado).
    $mStmt = $db->prepare('DELETE FROM chat_messages WHERE instance_name = :i');
    $mStmt->execute(['i' => $instance]);
    $delMessages = $mStmt->rowCount();

    // 2) Contatos/conversas da instância (escopo isolado).
    $cStmt = $db->prepare('DELETE FROM chat_contacts WHERE instance_name = :i');
    $cStmt->execute(['i' => $instance]);
    $delContacts = $cStmt->rowCount();

    // 3) Satélites SEM instance_name: só removemos os que ficaram ÓRFÃOS
    //    (sem nenhuma mensagem nem contato remanescente com aquele jid).
    //    Isso é seguro: se outra instância ainda tem o mesmo jid, os registros ficam.
    $db->exec("DELETE cr FROM chat_reactions cr
               WHERE NOT EXISTS (SELECT 1 FROM chat_messages cm WHERE cm.remote_jid = cr.remote_jid)
                 AND NOT EXISTS (SELECT 1 FROM chat_contacts cc WHERE cc.remote_jid = cr.remote_jid)");

    try {
        $db->exec("DELETE cg FROM chat_groups cg
                   WHERE NOT EXISTS (SELECT 1 FROM chat_messages cm WHERE cm.remote_jid = cg.group_jid)
                     AND NOT EXISTS (SELECT 1 FROM chat_contacts cc WHERE cc.remote_jid = cg.group_jid)");
    } catch (Throwable $e) { /* chat_group_participants cai em cascata; ignora se schema diferir */ }

    try {
        $db->exec("DELETE cci FROM chat_capture_info cci
                   WHERE NOT EXISTS (SELECT 1 FROM chat_messages cm WHERE cm.remote_jid = cci.chat_id)
                     AND NOT EXISTS (SELECT 1 FROM chat_contacts cc WHERE cc.remote_jid = cci.chat_id)");
    } catch (Throwable $e) { /* tabela pode não existir em alguns ambientes */ }

    audit_log('clear_conversations', 'whatsapp_instances', $instance, null, [
        'instance' => $instance,
        'deleted_messages' => $delMessages,
        'deleted_contacts' => $delContacts,
        'connection_status' => $connStatus,
        'status' => $recStatus,
    ]);

    $db->commit();

    $respond(true, 'Conversas da instância "' . $instance . '" limpas com escopo isolado ('
        . $delMessages . ' mensagem(ns) e ' . $delContacts . ' conversa(s) removidas). As demais instâncias não foram afetadas.', $backToView);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[WHATSAPP_CLEAR_CONVERSATIONS] ' . $e->getMessage());
    $respond(false, 'Falha ao limpar conversas: ' . $e->getMessage(), $backToView);
}

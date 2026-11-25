<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp.manage');

$method = (string)($_POST['method'] ?? '');
$payloadJson = (string)($_POST['payload_json'] ?? '');

$data = json_decode($payloadJson, true);
if (!is_array($data)) {
    flash_set('error', 'JSON inválido.');
    header('Location: /admin_whatsapp_console.php');
    exit;
}

$instance = isset($data['instance']) ? trim((string)$data['instance']) : '';
$args = $data['args'] ?? [];
if (!is_array($args)) {
    $args = [];
}

$waApi = new WhatsAppApi(null, null, $instance !== '' ? $instance : null);

$resp = null;

try {
    switch ($method) {
        // Instance
        case 'fetchInstances':
            $resp = $waApi->fetchInstances(isset($args['instanceName']) ? (string)$args['instanceName'] : null);
            break;
        case 'createInstanceBasic':
            $resp = $waApi->createInstanceBasic($args);
            break;
        case 'connectInstance':
            $resp = $waApi->connectInstance($instance !== '' ? $instance : null, isset($args['number']) ? (string)$args['number'] : null);
            break;
        case 'connectionState':
            $resp = $waApi->connectionState($instance !== '' ? $instance : null);
            break;
        case 'restartInstance':
            $resp = $waApi->restartInstance($instance !== '' ? $instance : null);
            break;
        case 'logoutInstance':
            $resp = $waApi->logoutInstance($instance !== '' ? $instance : null);
            break;
        case 'deleteInstance':
            $resp = $waApi->deleteInstance($instance !== '' ? $instance : null);
            break;

        // Chat
        case 'findChats':
            $resp = $waApi->findChats();
            break;
        case 'findMessages':
            $resp = $waApi->findMessages((string)($args['remoteJid'] ?? ''));
            break;
        case 'archiveChat':
            $resp = $waApi->archiveChat((array)($args['lastMessageKey'] ?? []), (bool)($args['archive'] ?? true));
            break;
        case 'checkIsWhatsapp':
            $resp = $waApi->checkIsWhatsapp((array)($args['numbers'] ?? []));
            break;
        case 'markMessageAsRead':
            $resp = $waApi->markMessageAsRead((array)($args['read_messages'] ?? []));
            break;
        case 'deleteMessageForEveryone':
            $resp = $waApi->deleteMessageForEveryone((array)($args['key'] ?? $args));
            break;
        case 'updateMessage':
            $resp = $waApi->updateMessage((int)($args['number'] ?? 0), (string)($args['text'] ?? ''), (array)($args['key'] ?? []));
            break;
        case 'findContacts':
            $resp = $waApi->findContacts(isset($args['id']) ? (string)$args['id'] : null);
            break;
        case 'findStatusMessage':
            $resp = $waApi->findStatusMessage((array)($args['where'] ?? []), isset($args['limit']) ? (int)$args['limit'] : null);
            break;

        // Message
        case 'sendText':
            $resp = $waApi->sendText((string)($args['number'] ?? ''), (string)($args['text'] ?? ''), (array)($args['options'] ?? []));
            break;
        case 'sendMedia':
            $resp = $waApi->sendMedia(
                (string)($args['number'] ?? ''),
                (string)($args['mediaType'] ?? ''),
                (string)($args['fileName'] ?? ''),
                (string)($args['media'] ?? ''),
                isset($args['caption']) ? (string)$args['caption'] : null,
                (array)($args['options'] ?? [])
            );
            break;
        case 'sendContact':
            $resp = $waApi->sendContact((string)($args['number'] ?? ''), (array)($args['contactMessage'] ?? []), (array)($args['options'] ?? []));
            break;
        case 'sendWhatsAppAudio':
            $resp = $waApi->sendWhatsAppAudio((string)($args['number'] ?? ''), (string)($args['audio'] ?? ''), (array)($args['options'] ?? []));
            break;
        case 'sendTemplate':
            $resp = $waApi->sendTemplate((string)($args['number'] ?? ''), (array)($args['templateMessage'] ?? []));
            break;
        case 'sendStatus':
            $resp = $waApi->sendStatus((array)($args['statusMessage'] ?? $args));
            break;
        case 'sendLocation':
            $resp = $waApi->sendLocation((string)($args['number'] ?? ''), (array)($args['locationMessage'] ?? []), (array)($args['options'] ?? []));
            break;
        case 'sendReaction':
            $resp = $waApi->sendReaction((array)($args['reactionMessage'] ?? $args));
            break;
        case 'sendSticker':
            $resp = $waApi->sendSticker((string)($args['number'] ?? ''), (string)($args['image'] ?? ''), (array)($args['options'] ?? []));
            break;
        case 'sendPoll':
            $resp = $waApi->sendPoll((string)($args['number'] ?? ''), (array)($args['pollMessage'] ?? []), (array)($args['options'] ?? []));
            break;
        case 'sendList':
            $resp = $waApi->sendList((string)($args['number'] ?? ''), (array)($args['listMessage'] ?? []), (array)($args['options'] ?? []));
            break;

        // Group
        case 'fetchAllGroups':
            $resp = $waApi->fetchAllGroups((bool)($args['getMembers'] ?? false));
            break;
        case 'findGroupByJid':
            $resp = $waApi->findGroupByJid((string)($args['groupJid'] ?? ''));
            break;
        case 'findGroupMembers':
            $resp = $waApi->findGroupMembers((string)($args['groupJid'] ?? ''));
            break;
        case 'createGroup':
            $resp = $waApi->createGroup((string)($args['subject'] ?? ''), (array)($args['participants'] ?? []), isset($args['description']) ? (string)$args['description'] : null);
            break;
        case 'updateGroupMembers':
            $resp = $waApi->updateGroupMembers((string)($args['groupJid'] ?? ''), (string)($args['action'] ?? ''), (array)($args['participants'] ?? []));
            break;
        case 'updateGroupSetting':
            $resp = $waApi->updateGroupSetting((string)($args['groupJid'] ?? ''), (string)($args['action'] ?? ''));
            break;
        case 'updateGroupSubject':
            $resp = $waApi->updateGroupSubject((string)($args['groupJid'] ?? ''), (string)($args['subject'] ?? ''));
            break;
        case 'updateGroupDescription':
            $resp = $waApi->updateGroupDescription((string)($args['groupJid'] ?? ''), (string)($args['description'] ?? ''));
            break;
        case 'updateGroupPicture':
            $resp = $waApi->updateGroupPicture((string)($args['groupJid'] ?? ''), (string)($args['imageUrl'] ?? ''));
            break;
        case 'fetchInviteCode':
            $resp = $waApi->fetchInviteCode((string)($args['groupJid'] ?? ''));
            break;
        case 'acceptInviteCode':
            $resp = $waApi->acceptInviteCode((string)($args['inviteCode'] ?? ''));
            break;
        case 'revokeInviteCode':
            $resp = $waApi->revokeInviteCode((string)($args['groupJid'] ?? ''));
            break;
        case 'sendGroupInvite':
            $resp = $waApi->sendGroupInvite((string)($args['groupJid'] ?? ''), (array)($args['numbers'] ?? []), (string)($args['description'] ?? ''));
            break;
        case 'findGroupByInviteCode':
            $resp = $waApi->findGroupByInviteCode((string)($args['inviteCode'] ?? ''));
            break;
        case 'leaveGroup':
            $resp = $waApi->leaveGroup((string)($args['groupJid'] ?? ''));
            break;
        case 'toggleEphemeral':
            $resp = $waApi->toggleEphemeral((string)($args['groupJid'] ?? ''), (int)($args['expiration'] ?? 0));
            break;

        default:
            flash_set('error', 'Método não suportado.');
            header('Location: /admin_whatsapp_console.php?instance=' . urlencode($instance));
            exit;
    }
} catch (Throwable $e) {
    flash_set('error', 'Erro ao executar: ' . $e->getMessage());
    header('Location: /admin_whatsapp_console.php?instance=' . urlencode($instance));
    exit;
}

view_header('Resultado');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="font-size:22px;font-weight:800">Resultado</div>';
echo '<div style="margin-top:8px;color:rgba(234,240,255,.72);font-size:14px;line-height:1.6">';
echo '<strong>Método:</strong> ' . h($method) . ' &nbsp; <strong>HTTP:</strong> ' . h((string)($resp['status'] ?? ''));
echo '</div>';

echo '<div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/admin_whatsapp_console.php?instance=' . urlencode($instance) . '">Voltar console</a>';
echo '<a class="btn" href="/tech_logs_list.php?provider=whatsapp">Ver Logs TI</a>';
if ($instance !== '') {
    echo '<a class="btn" href="/admin_whatsapp_instance_view.php?instance=' . urlencode($instance) . '">Voltar instância</a>';
}
echo '</div>';

echo '</section>';

echo '<section class="card col12">';
echo '<div style="font-weight:800;margin-bottom:8px">Resposta (raw)</div>';
echo '<pre style="white-space:pre-wrap;background:rgba(10,14,28,.35);border:1px solid rgba(255,255,255,.12);border-radius:14px;padding:12px;overflow:auto">' . h((string)($resp['body_raw'] ?? '')) . '</pre>';
echo '</section>';

echo '</div>';

view_footer();

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

$evo = new EvolutionApiV1(null, null, $instance !== '' ? $instance : null);

$resp = null;

try {
    switch ($method) {
        // Instance
        case 'fetchInstances':
            $resp = $evo->fetchInstances(isset($args['instanceName']) ? (string)$args['instanceName'] : null);
            break;
        case 'createInstanceBasic':
            $resp = $evo->createInstanceBasic($args);
            break;
        case 'connectInstance':
            $resp = $evo->connectInstance($instance !== '' ? $instance : null, isset($args['number']) ? (string)$args['number'] : null);
            break;
        case 'connectionState':
            $resp = $evo->connectionState($instance !== '' ? $instance : null);
            break;
        case 'restartInstance':
            $resp = $evo->restartInstance($instance !== '' ? $instance : null);
            break;
        case 'logoutInstance':
            $resp = $evo->logoutInstance($instance !== '' ? $instance : null);
            break;
        case 'deleteInstance':
            $resp = $evo->deleteInstance($instance !== '' ? $instance : null);
            break;

        // Chat
        case 'findChats':
            $resp = $evo->findChats();
            break;
        case 'findMessages':
            $resp = $evo->findMessages((string)($args['remoteJid'] ?? ''));
            break;
        case 'archiveChat':
            $resp = $evo->archiveChat((array)($args['lastMessageKey'] ?? []), (bool)($args['archive'] ?? true));
            break;
        case 'checkIsWhatsapp':
            $resp = $evo->checkIsWhatsapp((array)($args['numbers'] ?? []));
            break;
        case 'markMessageAsRead':
            $resp = $evo->markMessageAsRead((array)($args['read_messages'] ?? []));
            break;
        case 'deleteMessageForEveryone':
            $resp = $evo->deleteMessageForEveryone((array)($args['key'] ?? $args));
            break;
        case 'updateMessage':
            $resp = $evo->updateMessage((int)($args['number'] ?? 0), (string)($args['text'] ?? ''), (array)($args['key'] ?? []));
            break;
        case 'findContacts':
            $resp = $evo->findContacts(isset($args['id']) ? (string)$args['id'] : null);
            break;
        case 'findStatusMessage':
            $resp = $evo->findStatusMessage((array)($args['where'] ?? []), isset($args['limit']) ? (int)$args['limit'] : null);
            break;

        // Message
        case 'sendText':
            $resp = $evo->sendText((string)($args['number'] ?? ''), (string)($args['text'] ?? ''), (array)($args['options'] ?? []));
            break;
        case 'sendMedia':
            $resp = $evo->sendMedia(
                (string)($args['number'] ?? ''),
                (string)($args['mediaType'] ?? ''),
                (string)($args['fileName'] ?? ''),
                (string)($args['media'] ?? ''),
                isset($args['caption']) ? (string)$args['caption'] : null,
                (array)($args['options'] ?? [])
            );
            break;
        case 'sendContact':
            $resp = $evo->sendContact((string)($args['number'] ?? ''), (array)($args['contactMessage'] ?? []), (array)($args['options'] ?? []));
            break;
        case 'sendWhatsAppAudio':
            $resp = $evo->sendWhatsAppAudio((string)($args['number'] ?? ''), (string)($args['audio'] ?? ''), (array)($args['options'] ?? []));
            break;
        case 'sendTemplate':
            $resp = $evo->sendTemplate((string)($args['number'] ?? ''), (array)($args['templateMessage'] ?? []));
            break;
        case 'sendStatus':
            $resp = $evo->sendStatus((array)($args['statusMessage'] ?? $args));
            break;
        case 'sendLocation':
            $resp = $evo->sendLocation((string)($args['number'] ?? ''), (array)($args['locationMessage'] ?? []), (array)($args['options'] ?? []));
            break;
        case 'sendReaction':
            $resp = $evo->sendReaction((array)($args['reactionMessage'] ?? $args));
            break;
        case 'sendSticker':
            $resp = $evo->sendSticker((string)($args['number'] ?? ''), (string)($args['image'] ?? ''), (array)($args['options'] ?? []));
            break;
        case 'sendPoll':
            $resp = $evo->sendPoll((string)($args['number'] ?? ''), (array)($args['pollMessage'] ?? []), (array)($args['options'] ?? []));
            break;
        case 'sendList':
            $resp = $evo->sendList((string)($args['number'] ?? ''), (array)($args['listMessage'] ?? []), (array)($args['options'] ?? []));
            break;

        // Group
        case 'fetchAllGroups':
            $resp = $evo->fetchAllGroups((bool)($args['getMembers'] ?? false));
            break;
        case 'findGroupByJid':
            $resp = $evo->findGroupByJid((string)($args['groupJid'] ?? ''));
            break;
        case 'findGroupMembers':
            $resp = $evo->findGroupMembers((string)($args['groupJid'] ?? ''));
            break;
        case 'createGroup':
            $resp = $evo->createGroup((string)($args['subject'] ?? ''), (array)($args['participants'] ?? []), isset($args['description']) ? (string)$args['description'] : null);
            break;
        case 'updateGroupMembers':
            $resp = $evo->updateGroupMembers((string)($args['groupJid'] ?? ''), (string)($args['action'] ?? ''), (array)($args['participants'] ?? []));
            break;
        case 'updateGroupSetting':
            $resp = $evo->updateGroupSetting((string)($args['groupJid'] ?? ''), (string)($args['action'] ?? ''));
            break;
        case 'updateGroupSubject':
            $resp = $evo->updateGroupSubject((string)($args['groupJid'] ?? ''), (string)($args['subject'] ?? ''));
            break;
        case 'updateGroupDescription':
            $resp = $evo->updateGroupDescription((string)($args['groupJid'] ?? ''), (string)($args['description'] ?? ''));
            break;
        case 'updateGroupPicture':
            $resp = $evo->updateGroupPicture((string)($args['groupJid'] ?? ''), (string)($args['imageUrl'] ?? ''));
            break;
        case 'fetchInviteCode':
            $resp = $evo->fetchInviteCode((string)($args['groupJid'] ?? ''));
            break;
        case 'acceptInviteCode':
            $resp = $evo->acceptInviteCode((string)($args['inviteCode'] ?? ''));
            break;
        case 'revokeInviteCode':
            $resp = $evo->revokeInviteCode((string)($args['groupJid'] ?? ''));
            break;
        case 'sendGroupInvite':
            $resp = $evo->sendGroupInvite((string)($args['groupJid'] ?? ''), (array)($args['numbers'] ?? []), (string)($args['description'] ?? ''));
            break;
        case 'findGroupByInviteCode':
            $resp = $evo->findGroupByInviteCode((string)($args['inviteCode'] ?? ''));
            break;
        case 'leaveGroup':
            $resp = $evo->leaveGroup((string)($args['groupJid'] ?? ''));
            break;
        case 'toggleEphemeral':
            $resp = $evo->toggleEphemeral((string)($args['groupJid'] ?? ''), (int)($args['expiration'] ?? 0));
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
echo '<a class="btn" href="/tech_logs_list.php?provider=evolution">Ver Logs TI</a>';
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

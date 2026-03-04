<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp.manage');

$instance = isset($_GET['instance']) ? trim((string)$_GET['instance']) : '';

view_header('WhatsApp Console');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:800">Console Evolution API v1</div>';
echo '<div style="margin-top:6px;color:rgba(234,240,255,.72);font-size:14px;line-height:1.6">Executa qualquer método do SDK. Todas as chamadas geram log em Logs TI (provider=evolution).</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/tech_logs_list.php?provider=evolution">Logs TI</a>';
echo '<a class="btn" href="/admin_whatsapp_instances.php">Instâncias</a>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

$methods = [
    // Instance
    'fetchInstances' => 'instance.fetchInstances',
    'createInstanceBasic' => 'instance.createInstanceBasic',
    'connectInstance' => 'instance.connectInstance',
    'connectionState' => 'instance.connectionState',
    'restartInstance' => 'instance.restartInstance',
    'logoutInstance' => 'instance.logoutInstance',
    'deleteInstance' => 'instance.deleteInstance',

    // Chat
    'findChats' => 'chat.findChats',
    'findMessages' => 'chat.findMessages',
    'archiveChat' => 'chat.archiveChat',
    'checkIsWhatsapp' => 'chat.checkIsWhatsapp',
    'markMessageAsRead' => 'chat.markMessageAsRead',
    'deleteMessageForEveryone' => 'chat.deleteMessageForEveryone',
    'updateMessage' => 'chat.updateMessage',
    'findContacts' => 'chat.findContacts',
    'findStatusMessage' => 'chat.findStatusMessage',

    // Message
    'sendText' => 'message.sendText',
    'sendMedia' => 'message.sendMedia',
    'sendContact' => 'message.sendContact',
    'sendWhatsAppAudio' => 'message.sendWhatsAppAudio',
    'sendTemplate' => 'message.sendTemplate',
    'sendStatus' => 'message.sendStatus',
    'sendLocation' => 'message.sendLocation',
    'sendReaction' => 'message.sendReaction',
    'sendSticker' => 'message.sendSticker',
    'sendPoll' => 'message.sendPoll',
    'sendList' => 'message.sendList',

    // Group
    'fetchAllGroups' => 'group.fetchAllGroups',
    'findGroupByJid' => 'group.findGroupByJid',
    'findGroupMembers' => 'group.findGroupMembers',
    'createGroup' => 'group.createGroup',
    'updateGroupMembers' => 'group.updateGroupMembers',
    'updateGroupSetting' => 'group.updateGroupSetting',
    'updateGroupSubject' => 'group.updateGroupSubject',
    'updateGroupDescription' => 'group.updateGroupDescription',
    'updateGroupPicture' => 'group.updateGroupPicture',
    'fetchInviteCode' => 'group.fetchInviteCode',
    'acceptInviteCode' => 'group.acceptInviteCode',
    'revokeInviteCode' => 'group.revokeInviteCode',
    'sendGroupInvite' => 'group.sendGroupInvite',
    'findGroupByInviteCode' => 'group.findGroupByInviteCode',
    'leaveGroup' => 'group.leaveGroup',
    'toggleEphemeral' => 'group.toggleEphemeral',
];

$defaultPayload = "{\n  \"instance\": \"\",\n  \"args\": {}\n}\n";

$payloadExample = [
    'fetchInstances' => [
        'instance' => '',
        'args' => ['instanceName' => ''],
    ],
    'connectInstance' => [
        'instance' => 'teste-docs',
        'args' => ['number' => '559999999999'],
    ],
    'sendText' => [
        'instance' => 'teste-docs',
        'args' => ['number' => '5511999999999', 'text' => 'Olá'],
    ],
    'sendMedia' => [
        'instance' => 'teste-docs',
        'args' => ['number' => '5511999999999', 'mediaType' => 'image', 'fileName' => 'img.png', 'media' => 'https://... ou base64', 'caption' => 'caption'],
    ],
    'createGroup' => [
        'instance' => 'teste-docs',
        'args' => ['subject' => 'Grupo X', 'description' => 'desc', 'participants' => ['5511999999999']],
    ],
];

$exampleJson = json_encode($payloadExample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

$prefill = [
    'instance' => $instance,
    'args' => (object)[],
];
$prefillJson = json_encode($prefill, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);


echo '<section class="card col12">';
echo '<form method="post" action="/admin_whatsapp_console_post.php" style="display:grid;gap:12px;max-width:980px">';

echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Método<select name="method" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px">';
foreach ($methods as $k => $label) {
    echo '<option value="' . h($k) . '">' . h($label) . '</option>';
}
echo '</select></label>';

echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Payload JSON<textarea name="payload_json" rows="14" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:13px">' . h($prefillJson !== false ? $prefillJson : $defaultPayload) . '</textarea></label>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<button class="btn btnPrimary" type="submit">Executar</button>';
echo '<a class="btn" href="/admin_whatsapp_instance_view.php?instance=' . urlencode($instance) . '">Voltar instância</a>';
echo '</div>';

echo '</form>';

echo '<div style="margin-top:14px" class="pill">';
echo '<div style="font-weight:800;margin-bottom:8px">Exemplos (copie/cole)</div>';
echo '<pre style="white-space:pre-wrap">' . h($exampleJson !== false ? $exampleJson : '') . '</pre>';
echo '</div>';

echo '</section>';

echo '</div>';

view_footer();

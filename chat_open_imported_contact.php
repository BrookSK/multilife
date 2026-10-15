<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('chat.manage');

require_once __DIR__ . '/app/imported_contacts_helper.php';
imported_contacts_ensure_schema();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    flash_set('error', 'Contato inválido.');
    header('Location: /chat_web.php?type=contatos');
    exit;
}

$stmt = db()->prepare('SELECT id, name, phone FROM imported_contacts WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $id]);
$contact = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$contact || trim((string)($contact['phone'] ?? '')) === '') {
    flash_set('error', 'Contato sem telefone.');
    header('Location: /chat_web.php?type=contatos');
    exit;
}

$jid = imported_contacts_whatsapp_jid((string)$contact['phone']);
$name = mb_substr((string)$contact['name'], 0, 120);

$currentUserId = (int)(auth_user_id() ?? 0);
$userInstance = whatsapp_get_user_instance($currentUserId);
$instanceName = is_array($userInstance) ? (string)($userInstance['instance_name'] ?? '') : '';
if ($instanceName === '') {
    $instanceName = (string)admin_setting_get('evolution.instance', '');
}

try {
    $save = db()->prepare(
        "INSERT INTO chat_contacts (remote_jid, instance_name, contact_name, custom_name, is_group)
         VALUES (:jid, :inst, :name, :custom, 0)
         ON DUPLICATE KEY UPDATE
            custom_name = COALESCE(NULLIF(custom_name, ''), VALUES(custom_name)),
            contact_name = COALESCE(NULLIF(contact_name, ''), VALUES(contact_name)),
            instance_name = COALESCE(NULLIF(instance_name, ''), VALUES(instance_name))"
    );
    $save->execute([
        'jid' => $jid,
        'inst' => $instanceName !== '' ? $instanceName : null,
        'name' => $name,
        'custom' => $name,
    ]);
} catch (Throwable $e) {
    try {
        $save = db()->prepare(
            "INSERT INTO chat_contacts (remote_jid, instance_name, contact_name, is_group)
             VALUES (:jid, :inst, :name, 0)
             ON DUPLICATE KEY UPDATE
                contact_name = COALESCE(NULLIF(contact_name, ''), VALUES(contact_name))"
        );
        $save->execute([
            'jid' => $jid,
            'inst' => $instanceName !== '' ? $instanceName : null,
            'name' => $name,
        ]);
    } catch (Throwable $e2) {
        error_log('[IMPORTED_CONTACTS] open chat: ' . $e2->getMessage());
    }
}

header('Location: /chat_web.php?chat=' . urlencode($jid) . '&type=todos');
exit;

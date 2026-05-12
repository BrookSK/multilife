<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

// Buscar grupos do chat_groups que não estão no whatsapp_groups
$stmt = db()->query('SELECT group_jid, group_name, specialty, region FROM chat_groups WHERE group_jid LIKE "%@g.us"');
$groups = $stmt->fetchAll();

$count = 0;
foreach ($groups as $g) {
    $ins = db()->prepare(
        'INSERT INTO whatsapp_groups (name, evolution_group_jid, specialty, state, status) 
         VALUES (:n, :jid, :sp, :st, "active") 
         ON DUPLICATE KEY UPDATE name = VALUES(name), specialty = COALESCE(VALUES(specialty), specialty), state = COALESCE(VALUES(state), state)'
    );
    $ins->execute([
        'n' => $g['group_name'],
        'jid' => $g['group_jid'],
        'sp' => $g['specialty'] ?? null,
        'st' => $g['region'] ?? null,
    ]);
    $count++;
}

flash_set('success', "Sincronizados $count grupos de chat_groups para whatsapp_groups.");
header('Location: /admin_settings.php');
exit;

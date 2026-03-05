<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp_groups.manage');

$api = null;
try {
    $api = new EvolutionApiV1();
} catch (Throwable $e) {
    flash_set('error', 'Evolution API não configurada: ' . mb_strimwidth($e->getMessage(), 0, 220, ''));
    header('Location: /whatsapp_groups_list.php');
    exit;
}

$res = $api->fetchAllGroups(false);

$json = $res['json'] ?? null;
$data = null;

if (is_array($json)) {
    $data = $json['data'] ?? $json;
}

if (!is_array($data)) {
    flash_set('error', 'Resposta inválida da Evolution ao listar grupos.');
    header('Location: /whatsapp_groups_list.php');
    exit;
}

$db = db();

$sel = $db->prepare('SELECT * FROM whatsapp_groups WHERE evolution_group_jid = :jid LIMIT 1');
$ins = $db->prepare(
    'INSERT INTO whatsapp_groups (name, evolution_group_jid, contacts_count, specialty, city, state, status) VALUES (:n,:jid,:cc,NULL,NULL,NULL,\'active\')'
);
$upd = $db->prepare('UPDATE whatsapp_groups SET name = :n, contacts_count = :cc WHERE id = :id');

$created = 0;
$updated = 0;
$skipped = 0;

foreach ($data as $g) {
    if (!is_array($g)) {
        $skipped++;
        continue;
    }

    $jid = (string)($g['id'] ?? ($g['jid'] ?? ($g['groupJid'] ?? '')));
    $name = (string)($g['subject'] ?? ($g['name'] ?? ''));

    if ($jid === '') {
        $skipped++;
        continue;
    }

    if ($name === '') {
        $name = $jid;
    }

    $contactsCount = null;
    if (isset($g['participants']) && is_array($g['participants'])) {
        $contactsCount = count($g['participants']);
    } elseif (isset($g['size'])) {
        $contactsCount = (int)$g['size'];
    }

    $sel->execute(['jid' => $jid]);
    $row = $sel->fetch();
    if ($row) {
        $upd->execute([
            'n' => $name,
            'cc' => $contactsCount,
            'id' => (int)$row['id'],
        ]);
        $updated++;
    } else {
        $ins->execute([
            'n' => $name,
            'jid' => $jid,
            'cc' => $contactsCount,
        ]);
        $newId = (string)$db->lastInsertId();
        audit_log('create', 'whatsapp_groups_sync_evolution', $newId, null, ['name' => $name, 'evolution_group_jid' => $jid]);
        $created++;
    }
}

flash_set('success', 'Sincronização concluída: criados=' . $created . ' atualizados=' . $updated . ' ignorados=' . $skipped);
header('Location: /whatsapp_groups_list.php');
exit;

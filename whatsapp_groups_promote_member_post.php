<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp_groups.manage');

$groupId = isset($_POST['group_id']) ? (int)$_POST['group_id'] : 0;
$memberId = trim((string)($_POST['member_id'] ?? ''));

if ($groupId === 0 || empty($memberId)) {
    flash_set('error', 'Dados inválidos.');
    header('Location: /whatsapp_groups_list.php');
    exit;
}

$stmt = db()->prepare('SELECT evolution_group_jid FROM whatsapp_groups WHERE id = :id');
$stmt->execute(['id' => $groupId]);
$groupJid = $stmt->fetchColumn();

if (!$groupJid) {
    flash_set('error', 'Grupo não encontrado.');
    header('Location: /whatsapp_groups_list.php');
    exit;
}

try {
    $api = new EvolutionApiV1();
    $api->updateGroupParticipants($groupJid, 'promote', [$memberId]);
    
    flash_set('success', 'Membro promovido a administrador!');
} catch (Exception $e) {
    flash_set('error', 'Erro ao promover membro: ' . $e->getMessage());
}

header('Location: /whatsapp_groups_members_manage.php?id=' . $groupId);
exit;

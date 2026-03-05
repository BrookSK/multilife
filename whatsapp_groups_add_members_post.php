<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp_groups.manage');

$groupId = isset($_POST['group_id']) ? (int)$_POST['group_id'] : 0;
$userIds = $_POST['user_ids'] ?? [];

if ($groupId === 0) {
    flash_set('error', 'Grupo não especificado.');
    header('Location: /whatsapp_groups_list.php');
    exit;
}

if (!is_array($userIds) || empty($userIds)) {
    flash_set('error', 'Selecione ao menos um usuário.');
    header('Location: /whatsapp_groups_members_manage.php?id=' . $groupId);
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

// Buscar telefones dos usuários
$placeholders = implode(',', array_fill(0, count($userIds), '?'));
$stmt = db()->prepare("SELECT phone FROM users WHERE id IN ($placeholders) AND status = 'active'");
$stmt->execute($userIds);
$phones = $stmt->fetchAll(PDO::FETCH_COLUMN);

$participants = [];
foreach ($phones as $phone) {
    $clean = preg_replace('/\D+/', '', (string)$phone);
    if (!empty($clean)) {
        $participants[] = $clean;
    }
}

if (empty($participants)) {
    flash_set('error', 'Nenhum telefone válido encontrado.');
    header('Location: /whatsapp_groups_members_manage.php?id=' . $groupId);
    exit;
}

try {
    $api = new EvolutionApiV1();
    $api->updateGroupParticipants($groupJid, 'add', $participants);
    
    flash_set('success', count($participants) . ' membro(s) adicionado(s) com sucesso!');
} catch (Exception $e) {
    flash_set('error', 'Erro ao adicionar membros: ' . $e->getMessage());
}

header('Location: /whatsapp_groups_members_manage.php?id=' . $groupId);
exit;

<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp_groups.manage');

$id = (int)($_POST['id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
$professionalUserIds = $_POST['professional_user_ids'] ?? [];

if (!in_array($action, ['add', 'remove'], true)) {
    flash_set('error', 'Ação inválida.');
    header('Location: /whatsapp_groups_list.php');
    exit;
}

$stmt = db()->prepare('SELECT * FROM whatsapp_groups WHERE id = :id');
$stmt->execute(['id' => $id]);
$g = $stmt->fetch();

if (!$g) {
    flash_set('error', 'Grupo não encontrado.');
    header('Location: /whatsapp_groups_list.php');
    exit;
}

$jid = trim((string)($g['evolution_group_jid'] ?? ''));
if ($jid === '') {
    flash_set('error', 'Grupo sem Evolution Group JID configurado.');
    header('Location: /whatsapp_groups_edit.php?id=' . $id);
    exit;
}

if (!is_array($professionalUserIds) || count($professionalUserIds) === 0) {
    flash_set('error', 'Selecione ao menos um profissional.');
    header('Location: /whatsapp_groups_members_evolution.php?id=' . $id);
    exit;
}

$ids = [];
foreach ($professionalUserIds as $x) {
    $x = (string)$x;
    if ($x === '' || !ctype_digit($x)) {
        continue;
    }
    $ids[] = (int)$x;
}
$ids = array_values(array_unique($ids));
if (count($ids) === 0) {
    flash_set('error', 'Seleção inválida.');
    header('Location: /whatsapp_groups_members_evolution.php?id=' . $id);
    exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = db()->prepare(
    "SELECT u.id, u.phone\n"
    . "FROM users u\n"
    . "INNER JOIN user_roles ur ON ur.user_id = u.id\n"
    . "INNER JOIN roles r ON r.id = ur.role_id\n"
    . "WHERE u.status = 'active' AND r.slug = 'profissional' AND u.id IN ($placeholders)"
);
$stmt->execute($ids);
$rows = $stmt->fetchAll();

$numbers = [];
$missingPhone = [];
foreach ($rows as $r) {
    $uid = (int)$r['id'];
    $phone = preg_replace('/\D+/', '', (string)($r['phone'] ?? ''));
    if ($phone === '') {
        $missingPhone[] = $uid;
        continue;
    }
    $numbers[] = $phone;
}

if (count($missingPhone) > 0) {
    flash_set('error', 'Profissionais sem telefone cadastrado: ' . implode(', ', $missingPhone));
    header('Location: /whatsapp_groups_members_evolution.php?id=' . $id);
    exit;
}

if (count($numbers) === 0) {
    flash_set('error', 'Nenhum telefone válido encontrado para os profissionais selecionados.');
    header('Location: /whatsapp_groups_members_evolution.php?id=' . $id);
    exit;
}

$api = null;
try {
    $api = new EvolutionApiV1();
} catch (Throwable $e) {
    flash_set('error', 'Evolution API não configurada: ' . mb_strimwidth($e->getMessage(), 0, 220, ''));
    header('Location: /whatsapp_groups_members_evolution.php?id=' . $id);
    exit;
}

$res = $api->updateGroupMembers($jid, $action, $numbers);
$ok = (int)($res['status'] ?? 0) >= 200 && (int)($res['status'] ?? 0) < 300;
if (!$ok) {
    flash_set('error', 'Falha ao atualizar membros no grupo (ver logs de integração).');
    header('Location: /whatsapp_groups_members_evolution.php?id=' . $id);
    exit;
}

// Atualiza contacts_count com melhor esforço
try {
    $r2 = $api->findGroupMembers($jid);
    $j2 = $r2['json'] ?? null;
    $data = null;
    if (is_array($j2)) {
        $data = $j2['participants'] ?? ($j2['data'] ?? $j2);
    }
    $count = null;
    if (is_array($data)) {
        $count = count($data);
    }
    if ($count !== null) {
        $stmt = db()->prepare('UPDATE whatsapp_groups SET contacts_count = :cc WHERE id = :id');
        $stmt->execute(['cc' => $count, 'id' => $id]);
    }
} catch (Throwable $e) {
    // ignore
}

audit_log('update', 'whatsapp_groups_members_evolution', (string)$id, null, ['action' => $action, 'numbers_count' => count($numbers)]);

flash_set('success', $action === 'add' ? 'Profissionais adicionados ao grupo.' : 'Profissionais removidos do grupo.');
header('Location: /whatsapp_groups_members_evolution.php?id=' . $id);
exit;

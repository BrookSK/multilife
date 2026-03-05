<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp_groups.manage');

$subject = trim((string)($_POST['subject'] ?? ''));
$professionalUserIds = $_POST['professional_user_ids'] ?? [];
$description = trim((string)($_POST['description'] ?? ''));

$specialty = trim((string)($_POST['specialty'] ?? ''));
$city = trim((string)($_POST['city'] ?? ''));
$state = strtoupper(trim((string)($_POST['state'] ?? '')));
$status = (string)($_POST['status'] ?? 'active');

if ($subject === '') {
    flash_set('error', 'Informe o nome do grupo.');
    header('Location: /whatsapp_groups_create_evolution.php');
    exit;
}

if (!is_array($professionalUserIds) || count($professionalUserIds) === 0) {
    flash_set('error', 'Selecione ao menos um profissional participante.');
    header('Location: /whatsapp_groups_create_evolution.php');
    exit;
}

if ($state !== '' && !preg_match('/^[A-Z]{2}$/', $state)) {
    flash_set('error', 'UF inválida.');
    header('Location: /whatsapp_groups_create_evolution.php');
    exit;
}

if (!in_array($status, ['active', 'inactive'], true)) {
    $status = 'active';
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
    flash_set('error', 'Seleção de profissionais inválida.');
    header('Location: /whatsapp_groups_create_evolution.php');
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

$participants = [];
$missingPhone = [];
foreach ($rows as $r) {
    $uid = (int)$r['id'];
    $phone = preg_replace('/\D+/', '', (string)($r['phone'] ?? ''));
    if ($phone === '') {
        $missingPhone[] = $uid;
        continue;
    }
    $participants[] = $phone;
}

if (count($missingPhone) > 0) {
    flash_set('error', 'Profissionais sem telefone cadastrado: ' . implode(', ', $missingPhone));
    header('Location: /whatsapp_groups_create_evolution.php');
    exit;
}

if (count($participants) === 0) {
    flash_set('error', 'Nenhum telefone válido encontrado para os profissionais selecionados.');
    header('Location: /whatsapp_groups_create_evolution.php');
    exit;
}

$api = null;
try {
    $api = new EvolutionApiV1();
} catch (Throwable $e) {
    flash_set('error', 'Evolution API não configurada: ' . mb_strimwidth($e->getMessage(), 0, 220, ''));
    header('Location: /whatsapp_groups_create_evolution.php');
    exit;
}

$res = $api->createGroup($subject, $participants, $description !== '' ? $description : null);
$json = $res['json'] ?? null;

$jid = '';
if (is_array($json)) {
    $jid = (string)($json['groupJid'] ?? ($json['jid'] ?? ($json['id'] ?? '')));
    if ($jid === '' && isset($json['data']) && is_array($json['data'])) {
        $jid = (string)($json['data']['groupJid'] ?? ($json['data']['jid'] ?? ($json['data']['id'] ?? '')));
    }
}

if ($jid === '') {
    flash_set('error', 'Evolution não retornou groupJid/jid. Verifique logs de integração.');
    header('Location: /whatsapp_groups_create_evolution.php');
    exit;
}

$stmt = db()->prepare('INSERT INTO whatsapp_groups (name, evolution_group_jid, contacts_count, specialty, city, state, status) VALUES (:n,:jid,:cc,:sp,:c,:s,:st)');
$stmt->execute([
    'n' => $subject,
    'jid' => $jid,
    'cc' => count($participants),
    'sp' => $specialty !== '' ? $specialty : null,
    'c' => $city !== '' ? $city : null,
    's' => $state !== '' ? $state : null,
    'st' => $status,
]);

$id = (string)db()->lastInsertId();
audit_log('create', 'whatsapp_groups_create_evolution', $id, null, ['name' => $subject, 'evolution_group_jid' => $jid, 'contacts_count' => count($participants)]);

flash_set('success', 'Grupo criado na Evolution e salvo.');
header('Location: /whatsapp_groups_list.php');
exit;

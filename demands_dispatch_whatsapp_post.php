<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM demands WHERE id = :id');
$stmt->execute(['id' => $id]);
$d = $stmt->fetch();

if (!$d) {
    flash_set('error', 'Demanda não encontrada.');
    header('Location: /demands_list.php');
    exit;
}

$city = (string)($d['location_city'] ?? '');
$state = (string)($d['location_state'] ?? '');
$specialty = (string)($d['specialty'] ?? '');

// Seleção automática conforme doc: especialidade + cidade/estado
$sql = 'SELECT id, name FROM whatsapp_groups WHERE status = \'active\'';
$where = [];
$params = [];

if (trim($specialty) !== '') {
    $where[] = '(specialty IS NULL OR specialty = \'\' OR specialty = :sp)';
    $params['sp'] = $specialty;
}

if (trim($city) !== '') {
    $where[] = '(city IS NULL OR city = \'\' OR city = :city)';
    $params['city'] = $city;
}

if (trim($state) !== '') {
    $where[] = '(state IS NULL OR state = \'\' OR state = :st)';
    $params['st'] = $state;
}

if (count($where) > 0) {
    $sql .= ' AND ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY id DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$groups = $stmt->fetchAll();

if (count($groups) === 0) {
    flash_set('error', 'Nenhum grupo compatível encontrado.');
    header('Location: /demands_view.php?id=' . $id);
    exit;
}

// Mensagem configurável será implementada no módulo Admin. Por agora, gera um template.
$msg = "[CAPTAÇÃO]\n" . (string)$d['title'] . "\n";
if ($city !== '' || $state !== '') {
    $msg .= "Local: " . trim($city . ' ' . $state) . "\n";
}
if ($specialty !== '') {
    $msg .= "Especialidade: " . $specialty . "\n";
}

$db = db();
$db->beginTransaction();
try {
    $ins = $db->prepare('INSERT INTO demand_dispatch_logs (demand_id, group_id, dispatched_by_user_id, message, dispatch_status) VALUES (:did, :gid, :uid, :msg, :st)');
    foreach ($groups as $g) {
        $ins->execute([
            'did' => $id,
            'gid' => (int)$g['id'],
            'uid' => auth_user_id(),
            'msg' => $msg,
            'st' => 'sent',
        ]);
    }

    // Atualiza status para em_captacao se ainda estava aguardando
    if ((string)$d['status'] === 'aguardando_captacao') {
        $upd = $db->prepare('UPDATE demands SET status = \'em_captacao\' WHERE id = :id');
        $upd->execute(['id' => $id]);

        $log = $db->prepare('INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (:did, :os, :ns, :uid, :note)');
        $log->execute([
            'did' => $id,
            'os' => 'aguardando_captacao',
            'ns' => 'em_captacao',
            'uid' => auth_user_id(),
            'note' => 'realizar captacao',
        ]);
    }

    audit_log('create', 'demand_dispatch', (string)$id, null, ['groups' => array_map(fn($x) => (int)$x['id'], $groups)]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

flash_set('success', 'Captação registrada nos logs (envio via Evolution será integrado depois).');
header('Location: /demands_view.php?id=' . $id);
exit;

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
$sql = 'SELECT id, name, evolution_group_jid FROM whatsapp_groups WHERE status = \'active\' AND evolution_group_jid IS NOT NULL AND evolution_group_jid <> \'\'';
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

$tpl = (string)admin_setting_get(
    'demands.whatsapp_template',
    "[CAPTAÇÃO #{id}]\n{title}\nLocal: {city}/{state}\nEspecialidade: {specialty}\n\n{description}\nOrigem: {origin}"
);

$repl = [
    '{id}' => (string)$d['id'],
    '{title}' => (string)$d['title'],
    '{city}' => $city !== '' ? $city : '-',
    '{state}' => $state !== '' ? $state : '-',
    '{specialty}' => $specialty !== '' ? $specialty : '-',
    '{description}' => (string)($d['description'] ?? ''),
    '{origin}' => (string)($d['origin_email'] ?? ''),
];

$msg = strtr($tpl, $repl);

$db = db();
$db->beginTransaction();
try {
    $ins = $db->prepare('INSERT INTO demand_dispatch_logs (demand_id, group_id, dispatched_by_user_id, message, capture_token, dispatch_status) VALUES (:did, :gid, :uid, :msg, :token, :st)');
    foreach ($groups as $g) {
        $token = '#CAP' . (string)$id . '-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
        $msgWithToken = $msg . "\n\n" . $token;
        $ins->execute([
            'did' => $id,
            'gid' => (int)$g['id'],
            'uid' => auth_user_id(),
            'msg' => $msgWithToken,
            'token' => $token,
            'st' => 'queued',
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

// Envio via Evolution (fora da transação)
$api = null;
try {
    $api = new EvolutionApiV1();
} catch (Throwable $e) {
    // registra erro em todos
    $upd = db()->prepare('UPDATE demand_dispatch_logs SET dispatch_status = \'error\', error_message = :err WHERE demand_id = :did AND dispatch_status = \'queued\'');
    $upd->execute(['err' => 'Evolution API não configurada: ' . mb_strimwidth($e->getMessage(), 0, 220, ''), 'did' => $id]);
    flash_set('error', 'Falha ao enviar: Evolution API não configurada.');
    header('Location: /demands_view.php?id=' . $id);
    exit;
}

$selLogs = db()->prepare(
    'SELECT dl.id, dl.message, g.evolution_group_jid FROM demand_dispatch_logs dl LEFT JOIN whatsapp_groups g ON g.id = dl.group_id WHERE dl.demand_id = :did AND dl.dispatch_status = \'queued\''
);
$selLogs->execute(['did' => $id]);
$toSend = $selLogs->fetchAll();

$updOne = db()->prepare('UPDATE demand_dispatch_logs SET dispatch_status = :st, error_message = :err WHERE id = :id');

$sent = 0;
$errCount = 0;
foreach ($toSend as $row) {
    $logId = (int)$row['id'];
    $jid = (string)($row['evolution_group_jid'] ?? '');
    $msgRow = (string)($row['message'] ?? $msg);
    if ($jid === '') {
        $updOne->execute(['st' => 'error', 'err' => 'Grupo sem evolution_group_jid configurado.', 'id' => $logId]);
        $errCount++;
        continue;
    }

    try {
        $res = $api->sendText($jid, $msgRow);
        $ok = isset($res['status']) && (int)$res['status'] >= 200 && (int)$res['status'] < 300;
        if ($ok) {
            $updOne->execute(['st' => 'sent', 'err' => null, 'id' => $logId]);
            $sent++;
        } else {
            $updOne->execute(['st' => 'error', 'err' => 'HTTP ' . (string)($res['status'] ?? ''), 'id' => $logId]);
            $errCount++;
        }
    } catch (Throwable $e) {
        $updOne->execute(['st' => 'error', 'err' => mb_strimwidth($e->getMessage(), 0, 255, ''), 'id' => $logId]);
        $errCount++;
        continue;
    }
}

if ($sent > 0 && $errCount === 0) {
    flash_set('success', 'Captação enviada via WhatsApp.');
} elseif ($sent > 0) {
    flash_set('error', 'Captação enviada para alguns grupos, mas houve erros em outros.');
} else {
    flash_set('error', 'Falha ao enviar captação via WhatsApp. Verifique os logs.');
}
header('Location: /demands_view.php?id=' . $id);
exit;

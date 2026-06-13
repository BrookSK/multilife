<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$id = (int)($_POST['id'] ?? 0);
$subRequestId = (int)($_POST['sub_request_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM demands WHERE id = :id');
$stmt->execute(['id' => $id]);
$d = $stmt->fetch();

if (!$d) {
    flash_set('error', 'Demanda não encontrada.');
    header('Location: /demands_list.php');
    exit;
}

// Se foi selecionada uma sub-solicitação, usar os dados dela
$subRequest = null;
if ($subRequestId > 0) {
    $stmtSub = db()->prepare('SELECT * FROM demand_sub_requests WHERE id = :id AND demand_id = :did');
    $stmtSub->execute(['id' => $subRequestId, 'did' => $id]);
    $subRequest = $stmtSub->fetch();
    if (!$subRequest) {
        flash_set('error', 'Sub-solicitação não encontrada.');
        header('Location: /demands_view.php?id=' . $id);
        exit;
    }
    if ($subRequest['status'] === 'em_captacao' || $subRequest['status'] === 'concluido') {
        flash_set('error', 'Esta sub-solicitação já está em captação ou concluída.');
        header('Location: /demands_view.php?id=' . $id);
        exit;
    }
}

// Usar dados da sub-solicitação se disponível, senão usar dados da demanda
$city = (string)($subRequest ? ($subRequest['location_city'] ?? $d['location_city'] ?? '') : ($d['location_city'] ?? ''));
$state = (string)($subRequest ? ($subRequest['location_state'] ?? $d['location_state'] ?? '') : ($d['location_state'] ?? ''));
$specialty = (string)($subRequest ? ($subRequest['specialty'] ?? '') : ($d['specialty'] ?? ''));

// Buscar grupos compatíveis - tentar match progressivo
$groups = [];
$jidFilter = ' AND evolution_group_jid LIKE \'%@g.us\'';

// Tentativa 1: Match exato por especialidade + estado + cidade
if (trim($specialty) !== '' && count($groups) === 0) {
    $sql = 'SELECT id, name, evolution_group_jid FROM whatsapp_groups WHERE status = \'active\' AND evolution_group_jid IS NOT NULL AND evolution_group_jid <> \'\'' . $jidFilter;
    $conditions = [];
    $params = [];
    
    $conditions[] = '(specialty = :sp)';
    $params['sp'] = $specialty;
    
    if (trim($state) !== '') {
        $conditions[] = '(state IS NULL OR state = \'\' OR state = :st)';
        $params['st'] = $state;
    }
    
    if (trim($city) !== '') {
        $conditions[] = '(city IS NULL OR city = \'\' OR city = :city)';
        $params['city'] = $city;
    }
    
    $sql .= ' AND ' . implode(' AND ', $conditions) . ' ORDER BY id DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $groups = $stmt->fetchAll();
}

// Tentativa 2: Só por especialidade (ignorar localização)
if (count($groups) === 0 && trim($specialty) !== '') {
    $sql2 = 'SELECT id, name, evolution_group_jid FROM whatsapp_groups WHERE status = \'active\' AND evolution_group_jid IS NOT NULL AND evolution_group_jid <> \'\'' . $jidFilter . ' AND specialty = :sp ORDER BY id DESC';
    $stmt2 = db()->prepare($sql2);
    $stmt2->execute(['sp' => $specialty]);
    $groups = $stmt2->fetchAll();
}

// Tentativa 3: Especialidade com LIKE (caso tenha diferença de acentuação/case)
if (count($groups) === 0 && trim($specialty) !== '') {
    $sql3 = 'SELECT id, name, evolution_group_jid FROM whatsapp_groups WHERE status = \'active\' AND evolution_group_jid IS NOT NULL AND evolution_group_jid <> \'\'' . $jidFilter . ' AND (specialty LIKE :sp_like OR name LIKE :name_like) ORDER BY id DESC';
    $stmt3 = db()->prepare($sql3);
    $stmt3->execute(['sp_like' => '%' . $specialty . '%', 'name_like' => '%' . $specialty . '%']);
    $groups = $stmt3->fetchAll();
}

if (count($groups) === 0) {
    // Mostrar debug com dados do banco para facilitar diagnóstico
    $allGroups = db()->query('SELECT id, name, specialty, city, state, status, evolution_group_jid FROM whatsapp_groups ORDER BY id DESC LIMIT 10')->fetchAll();
    $groupsDebug = [];
    foreach ($allGroups as $g) {
        $groupsDebug[] = '#' . $g['id'] . ' "' . $g['name'] . '" spec="' . ($g['specialty'] ?? 'NULL') . '" state="' . ($g['state'] ?? 'NULL') . '" status="' . $g['status'] . '" jid="' . ($g['evolution_group_jid'] ? 'SIM' : 'NÃO') . '"';
    }
    $debugInfo = "Buscando: Especialidade=\"$specialty\", Cidade=\"$city\", Estado=\"$state\". Grupos no banco: " . implode(' | ', $groupsDebug);
    flash_set('error', 'Nenhum grupo compatível encontrado. ' . $debugInfo);
    header('Location: /demands_view.php?id=' . $id);
    exit;
}

$tpl = trim((string)admin_setting_get(
    'demands.whatsapp_template',
    ''
));

// Se template vazio ou não configurado, usar padrão
if ($tpl === '') {
    $tpl = "[CAPTAÇÃO #{id}]\n{title}\nLocal: {city}/{state}\nEspecialidade: {specialty}\nFrequência: {frequency}\n\n{description}\nOrigem: {origin}";
}

$repl = [
    '{id}' => (string)$d['id'],
    '{title}' => (string)$d['title'],
    '{city}' => $city !== '' ? $city : '-',
    '{state}' => $state !== '' ? $state : '-',
    '{specialty}' => $specialty !== '' ? $specialty : '-',
    '{frequency}' => $subRequest && trim((string)($subRequest['frequency'] ?? '')) !== '' ? (string)$subRequest['frequency'] : (trim((string)($d['frequency'] ?? '')) !== '' ? (string)$d['frequency'] : '-'),
    '{description}' => $subRequest ? (string)($subRequest['description'] ?? $d['description'] ?? '') : (string)($d['description'] ?? ''),
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
            // Extrair o message_id retornado pela API (para vincular reações futuras)
            $externalMsgId = null;
            $jsonRes = $res['json'] ?? null;
            if (is_array($jsonRes) && isset($jsonRes['key']['id'])) {
                $externalMsgId = (string)$jsonRes['key']['id'];
            }
            
            $updOne->execute(['st' => 'sent', 'err' => null, 'id' => $logId]);
            
            // Salvar external_message_id para vincular reações
            if ($externalMsgId) {
                $stmtExtId = db()->prepare('UPDATE demand_dispatch_logs SET external_message_id = :eid WHERE id = :id');
                $stmtExtId->execute(['eid' => $externalMsgId, 'id' => $logId]);
            }
            
            $sent++;
            
            // Salvar na chat_messages para aparecer no chat
            try {
                $stmtChat = db()->prepare(
                    'INSERT INTO chat_messages (remote_jid, message_text, from_me, message_timestamp, external_message_id) VALUES (?, ?, 1, ?, ?)'
                );
                $stmtChat->execute([$jid, $msgRow, time(), $externalMsgId]);
            } catch (Throwable $chatErr) {
                error_log('[DISPATCH] Erro ao salvar em chat_messages: ' . $chatErr->getMessage());
            }
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

// Atualizar status da sub-solicitação somente se pelo menos um envio foi bem-sucedido
if ($subRequest !== null && $sent > 0) {
    $updSub = db()->prepare('UPDATE demand_sub_requests SET status = \'em_captacao\', dispatched_at = NOW() WHERE id = :id');
    $updSub->execute(['id' => $subRequestId]);
}

header('Location: /demands_view.php?id=' . $id);
exit;
